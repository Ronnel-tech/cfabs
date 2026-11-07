<?php
require __DIR__.'/support/http_database.php';
$id=null;
try {
    $config=expect_status(request_api('GET','/api/auth/password-reset/config'),200,'reset configuration')['data'];
    check(array_keys($config)===['enabled'],'configuration exposes no provider credentials');
    check(request_api('GET','/email-dispatch')[0]===404,'email worker is CLI-only');
    expect_status(request_api('POST','/api/auth/password-reset/request',null,null,'{'),400,'reset request requires valid JSON');
    expect_status(request_api('POST','/api/auth/password-reset/request',['email'=>[]]),422,'reset request validates address');
    if(!$config['enabled']) expect_status(request_api('POST','/api/auth/password-reset/request',['email'=>$prefix.'@example.invalid']),503,'disabled sender explains unavailable reset service');
    $role=query($db,"SELECT id FROM roles WHERE role_name='faculty_employee'")->fetchColumn();
    $email=$prefix.'@example.invalid';
    query($db,'INSERT INTO users (username,email,password,role,is_active,role_id) VALUES (?,?,?,?,1,?)',[$prefix,$email,password_hash($password,PASSWORD_DEFAULT),'user',$role]);
    $id=(int)$db->lastInsertId();
    $old=expect_status(request_api('POST','/api/auth/login',['email'=>$email,'password'=>$password]),200,'original password login')['data'];
    $legacy=signed_claims(['sub'=>$id,'iat'=>time(),'exp'=>time()+300,'iss'=>'cfabs','aud'=>'cfabs-clients','type'=>'access','role'=>'user','scopes'=>['read']],$environment['JWT_SECRET']);
    expect_status(request_api('GET','/api/auth/me',null,$legacy),200,'pre-migration version-zero token remains compatible');
    $insert=function(string $token,?string $expires=null)use($db,$id,$email){
        query($db,'INSERT INTO password_reset_tokens (user_id,email,token_hash,expires_at) VALUES (?,?,?,?)',[$id,$email,hash('sha256',$token),$expires??gmdate('Y-m-d H:i:s',time()+1800)]);
        return (int)$db->lastInsertId();
    };
    $token=bin2hex(random_bytes(32));$reset=$insert($token);
    $new='  New '.bin2hex(random_bytes(12)).'  ';
    $body=['token'=>$token,'password'=>$new,'confirmation'=>$new];
    expect_status(request_api('POST','/api/auth/password-reset/confirm',array_replace($body,['token'=>'bad'])),422,'malformed token rejected');
    expect_status(request_api('POST','/api/auth/password-reset/confirm',array_replace($body,['token'=>str_repeat('f',64)])),422,'unknown token rejected');
    expect_status(request_api('POST','/api/auth/password-reset/confirm',array_replace($body,['password'=>'short','confirmation'=>'short'])),422,'same password policy as account creation');
    expect_status(request_api('POST','/api/auth/password-reset/confirm',array_replace($body,['confirmation'=>'different password'])),422,'confirmation required');
    check(query($db,'SELECT used_at FROM password_reset_tokens WHERE id=?',[$reset])->fetchColumn()===null,'invalid input does not consume valid reset token');
    expect_status(request_api('POST','/api/auth/password-reset/confirm',$body),200,'valid single-use password reset');
    $user=query($db,'SELECT password,auth_version FROM users WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);
    check(password_verify($new,$user['password']) && (int)$user['auth_version']===1,'password spaces preserved and session version incremented');
    expect_status(request_api('GET','/api/auth/me',null,$old['access_token']),401,'old access token invalidated immediately');
    expect_status(request_api('GET','/api/auth/me',null,$legacy),401,'legacy access invalidated after reset');
    expect_status(request_api('POST','/api/auth/refresh',['refresh_token'=>$old['refresh_token']]),401,'old refresh token revoked');
    expect_status(request_api('POST','/api/auth/login',['email'=>$email,'password'=>$password]),401,'old password cannot log in');
    $fresh=expect_status(request_api('POST','/api/auth/login',['email'=>$email,'password'=>$new]),200,'new password login')['data'];
    expect_status(request_api('GET','/api/auth/me',null,$fresh['access_token']),200,'new access token has current session version');
    expect_status(request_api('POST','/api/auth/password-reset/confirm',$body),422,'reset replay rejected');
    $expired=bin2hex(random_bytes(32));$insert($expired,gmdate('Y-m-d H:i:s',time()-1));
    expect_status(request_api('POST','/api/auth/password-reset/confirm',array_replace($body,['token'=>$expired])),422,'expired token rejected');
    $changed=bin2hex(random_bytes(32));$insert($changed);
    query($db,'UPDATE users SET email=? WHERE id=?',[$prefix.'.changed@example.invalid',$id]);
    expect_status(request_api('POST','/api/auth/password-reset/confirm',array_replace($body,['token'=>$changed])),422,'address changed after issuance invalidates reset');
    query($db,'UPDATE users SET email=?,is_active=0 WHERE id=?',[$email,$id]);
    expect_status(request_api('POST','/api/auth/password-reset/confirm',array_replace($body,['token'=>$changed])),422,'deactivated account cannot reset');
    query($db,'UPDATE users SET is_active=1 WHERE id=?',[$id]);
    expect_status(request_api('POST','/api/auth/password-reset/confirm',array_replace($body,['token'=>$changed])),200,'subsequent valid reset');
    check(query($db,'SELECT COUNT(*) FROM password_reset_tokens WHERE user_id=? AND used_at IS NULL',[$id])->fetchColumn()==0,'successful reset invalidates all outstanding links');
    expect_status(request_api('GET','/api/auth/me',null,$fresh['access_token']),401,'second reset invalidates most recent session');
    $notice=query($db,"SELECT message FROM notifications WHERE recipient_id=? AND notification_type='account.password_changed' LIMIT 1",[$id])->fetchColumn();
    check(is_string($notice) && !str_contains($notice,$new),'security confirmation contains no password');
    check(query($db,'SELECT COUNT(*) FROM users WHERE id=?',[$id])->fetchColumn()==1,'reset does not create accounts or change roles');
    echo "Passed {$checks} password reset HTTP and session-revocation checks.\n";
} finally {
    if($id!==null) {
        query($db,'DELETE FROM notifications WHERE recipient_id=?',[$id]);
        query($db,'DELETE FROM password_reset_tokens WHERE user_id=?',[$id]);
        query($db,'DELETE FROM refresh_tokens WHERE user_id=?',[$id]);
        query($db,'DELETE FROM audit_logs WHERE actor_user_id=?',[$id]);
        query($db,'DELETE FROM users WHERE id=? AND username=?',[$id,$prefix]);
    }
}
