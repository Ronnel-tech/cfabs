<?php
require __DIR__.'/support/http_database.php';
require __DIR__.'/support/mail_runtime.php';
$lava=lava_instance(); $users=[]; $reset_requests=[];
$vars=['MAIL_PROVIDER','MAIL_ENABLED','MAIL_API_KEY','MAIL_FROM_ADDRESS','MAIL_FROM_NAME','MAIL_APP_URL'];
$before=[];foreach($vars as $var)$before[$var]=getenv($var);
putenv('MAIL_PROVIDER=resend');putenv('MAIL_ENABLED=1');putenv('MAIL_API_KEY=test-no-network');putenv('MAIL_FROM_ADDRESS=campus@example.invalid');putenv('MAIL_FROM_NAME=CFABS');putenv('MAIL_APP_URL=http://127.0.0.1:5173');
$facility=null; $category=null;
try {
    query($db,'INSERT INTO departments (department_code,department_name) VALUES (?,?)',[$prefix,'Mail test department']);
    $department=(int)$db->lastInsertId();
    $faculty=query($db,"SELECT id FROM roles WHERE role_name='faculty_employee'")->fetchColumn();
    foreach(['owner','other'] as $name) {
        query($db,'INSERT INTO users (username,email,password,role,is_active,role_id,department_id) VALUES (?,?,?,?,1,?,?)',[$prefix.'.'.$name,$prefix.'.'.$name.'@example.invalid',password_hash($password,PASSWORD_DEFAULT),'user',$faculty,$department]);
        $users[$name]=(int)$db->lastInsertId();
    }
    query($db,'INSERT INTO facility_categories (category_name) VALUES (?)',[$prefix]);$category=(int)$db->lastInsertId();
    query($db,'INSERT INTO facilities (facility_code,facility_name,category_id,capacity,created_by) VALUES (?,?,?,10,?)',[$prefix,'Mail test hall',$category,$users['owner']]);$facility=(int)$db->lastInsertId();
    $start=gmdate('Y-m-d H:i:s',time()+86400);$end=gmdate('Y-m-d H:i:s',time()+90000);
    query($db,'INSERT INTO facility_bookings (booking_reference,requester_id,department_id,purpose,status,facility_id,start_datetime,end_datetime,activity_name,participants_count) VALUES (?,?,?,?,?,?,?,?,?,1)',[$prefix,$users['owner'],$department,'Do not email this private purpose','APPROVED',$facility,$start,$end,'Private event name']);
    $booking=(int)$db->lastInsertId();
    $notify=function($type='facility_booking.approved',$who=null)use($lava,$users,$booking,$db){
        $lava->call->model('Notification_model');
        $lava->Notification_model->record($who??$users['owner'],$type,'facility_booking',$booking,'Fixture title','Do not forward raw fixture message');
        return (int)$db->lastInsertId();
    };
    $transport=new Mail_test_transport();
    $id=$notify();
    $preview=$lava->mail_service->dispatch('preview',25,$users['owner'],$transport);
    check($preview['candidates']===1 && $preview['queued']===0 && count($transport->calls)===0,'preview has no network or queue side effects');
    check(query($db,'SELECT COUNT(*) FROM email_deliveries WHERE notification_id=?',[$id])->fetchColumn()==0,'preview writes no delivery');
    $queued=$lava->mail_service->dispatch('queue',25,$users['owner'],$transport);
    check($queued['queued']===1 && count($transport->calls)===0,'queue persists without network');
    $row=query($db,'SELECT * FROM email_deliveries WHERE notification_id=?',[$id])->fetch(PDO::FETCH_ASSOC);
    check($row['status']==='pending' && !str_contains($row['payload_encrypted'],'example.invalid'),'payload stored encrypted');
    $payload=$lava->mail_cipher->open($row['payload_encrypted']);
    check(str_contains($payload['text'],'Mail test hall') && str_contains($payload['text'],$prefix),'email includes real resource and reference');
    check(!str_contains(json_encode($payload),'private purpose') && !str_contains(json_encode($payload),'raw fixture message') && !str_contains(json_encode($payload),$password),'mail omits free-text private details and credentials');
    check(str_contains($payload['text'],'UTC+8') && str_contains($payload['text'],'Current status'),'email includes campus times and current status');
    $again=$lava->mail_service->dispatch('queue',25,$users['owner'],$transport);
    check($again['queued']===0,'notification queued once');
    putenv('MAIL_ENABLED=0');
    try { $lava->mail_service->dispatch('send',25,$users['owner'],$transport); throw new LogicException('Disabled transport reached'); }
    catch(RuntimeException $error) { check(count($transport->calls)===0,'disabled sending rejected before transport'); }
    putenv('MAIL_ENABLED=1');
    $sent=$lava->mail_service->dispatch('send',25,$users['owner'],$transport);
    check($sent['accepted']===1 && count($transport->calls)===1,'worker uses explicitly injected fake provider');
    $done=query($db,'SELECT * FROM email_deliveries WHERE id=?',[$row['id']])->fetch(PDO::FETCH_ASSOC);
    check($done['status']==='accepted' && $done['payload_encrypted']===null && $done['provider_id']!==null,'acceptance recorded and content purged');
    $lava->mail_service->dispatch('send',25,$users['owner'],$transport);
    check(count($transport->calls)===1,'accepted delivery never resent');
    $id=$notify();$transport->outcome='retry';
    $result=$lava->mail_service->dispatch('send',25,$users['owner'],$transport);
    check($result['retry']===1,'transient failure schedules retry');
    $retry=query($db,'SELECT * FROM email_deliveries WHERE notification_id=?',[$id])->fetch(PDO::FETCH_ASSOC);
    $first_call=end($transport->calls);
    check($retry['status']==='retry' && (int)$retry['attempts']===1 && $retry['payload_encrypted']!==null,'retry retains frozen payload');
    check(query($db,'SELECT status FROM facility_bookings WHERE id=?',[$booking])->fetchColumn()==='APPROVED','provider failure does not roll back booking');
    $lava->mail_service->dispatch('send',25,$users['owner'],$transport);
    check(count($transport->calls)===2,'backoff prevents immediate retry');
    query($db,'UPDATE email_deliveries SET next_attempt_at=UTC_TIMESTAMP() WHERE id=?',[$retry['id']]);
    $transport->outcome='accepted';$lava->mail_service->dispatch('send',25,$users['owner'],$transport);
    check(end($transport->calls)===$first_call,'retry uses identical payload and provider idempotency key');
    $id=$notify();$lava->mail_service->dispatch('queue',25,$users['owner'],$transport);
    query($db,"UPDATE email_deliveries SET status='sending',attempts=1,first_attempt_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR) WHERE notification_id=?",[$id]);
    $calls=count($transport->calls);$result=$lava->mail_service->dispatch('send',25,$users['owner'],$transport);
    check($result['failed']===1 && count($transport->calls)===$calls,'crash recovery never retries after provider deduplication window');
    $id=$notify();$lava->mail_service->dispatch('queue',25,$users['owner'],$transport);
    query($db,'UPDATE users SET email=? WHERE id=?',[$prefix.'.changed@example.invalid',$users['owner']]);
    $result=$lava->mail_service->dispatch('send',25,$users['owner'],$transport);
    check($result['skipped']===1 && count($transport->calls)===$calls,'changed recipient address blocks frozen payload');
    query($db,'UPDATE users SET email=? WHERE id=?',[$prefix.'.owner@example.invalid',$users['owner']]);
    $id=$notify('unsupported.event');$result=$lava->mail_service->dispatch('queue',25,$users['owner'],$transport);
    check($result['suppressed']===1,'unsupported events suppressed');
    $id=$notify('facility_booking.approved',$users['other']);
    $result=$lava->mail_service->dispatch('queue',25,$users['other'],$transport);
    check($result['suppressed']===1,'private booking email not sent to another requester');
    $id=$notify();query($db,'UPDATE notifications SET created_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 25 HOUR) WHERE id=?',[$id]);
    check($lava->mail_service->dispatch('queue',25,$users['owner'],$transport)['suppressed']===1,'stale notifications expire');
    $historical=$notify();query($db,'UPDATE notifications SET email_eligible=0 WHERE id=?',[$historical]);
    check($lava->mail_service->dispatch('queue',25,$users['owner'],$transport)['queued']===0,'historical notifications are not retroactively emailed');
    $reminder_key=hash('sha256',json_encode(['facility_booking',$booking,'facility_upcoming',$start,$users['owner']],JSON_THROW_ON_ERROR));
    $lava->Reminder_model->notify($users['owner'],'facility_upcoming','facility_booking',$booking,$start,$reminder_key,$prefix);
    $lava->mail_service->dispatch('queue',25,$users['owner'],$transport);
    query($db,"UPDATE facility_bookings SET status='CANCELLED' WHERE id=?",[$booking]);
    $calls=count($transport->calls);$result=$lava->mail_service->dispatch('send',25,$users['owner'],$transport);
    check($result['skipped']===1 && count($transport->calls)===$calls,'cancelled booking cannot emit queued upcoming reminder');
    query($db,"UPDATE facility_bookings SET status='APPROVED' WHERE id=?",[$booking]);
    $id=$notify();$lava->mail_service->dispatch('queue',25,$users['owner'],$transport);
    query($db,'UPDATE users SET is_active=0 WHERE id=?',[$users['owner']]);
    check($lava->mail_service->dispatch('send',25,$users['owner'],$transport)['skipped']===1,'inactive recipient cannot receive private booking email');
    $lava->Notification_model->account($users['owner'],'deactivated');
    check($lava->mail_service->dispatch('send',25,$users['owner'],$transport)['accepted']===1,'deactivation notice may reach the affected inactive account');
    query($db,'UPDATE users SET is_active=1 WHERE id=?',[$users['owner']]);
    $id=$notify();$transport->outcome='permanent';
    check($lava->mail_service->dispatch('send',25,$users['owner'],$transport)['failed']===1,'permanent provider error is terminal');
    $calls=count($transport->calls);$transport->outcome='accepted';
    $lava->mail_service->dispatch('send',25,$users['owner'],$transport);
    check(count($transport->calls)===$calls,'permanent failure is not automatically resent');
    $id=$notify();$lava->mail_service->dispatch('queue',25,$users['owner'],$transport);
    $crashed=query($db,'SELECT * FROM email_deliveries WHERE notification_id=?',[$id])->fetch(PDO::FETCH_ASSOC);
    query($db,"UPDATE email_deliveries SET status='sending',attempts=1,first_attempt_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE id=?",[$crashed['id']]);
    check($lava->mail_service->dispatch('send',25,$users['owner'],$transport)['accepted']===1,'in-flight crash recovered inside idempotency window');
    check(end($transport->calls)['key']===$crashed['delivery_key'],'crash recovery preserves provider key');
    $lava->call->library('password_reset_service');
    $lava->password_reset_service->request($prefix.'.owner@example.invalid');
    $request_id=(int)$db->lastInsertId();$reset_requests[]=$request_id;
    $request_row=query($db,'SELECT * FROM password_reset_requests WHERE id=?',[$request_id])->fetch(PDO::FETCH_ASSOC);
    check(!str_contains($request_row['email_encrypted'],'example.invalid'),'request address encrypted before asynchronous lookup');
    check(query($db,'SELECT COUNT(*) FROM password_reset_tokens WHERE user_id=?',[$users['owner']])->fetchColumn()==0,'request does not synchronously discover account or issue a token');
    $result=$lava->mail_service->dispatch('queue',25,$users['owner'],$transport);
    check($result['reset_queued']===1,'worker prepares reset email without sending');
    $reset_mail=query($db,'SELECT d.* FROM email_deliveries d JOIN password_reset_tokens t ON t.id=d.password_reset_id WHERE t.user_id=? ORDER BY d.id DESC LIMIT 1',[$users['owner']])->fetch(PDO::FETCH_ASSOC);
    $reset_payload=$lava->mail_cipher->open($reset_mail['payload_encrypted']);
    preg_match('/#token=([a-f0-9]{64})/',$reset_payload['text'],$match);$token=$match[1]??'';
    check(strlen($token)===64,'reset email carries high entropy fragment credential');
    $stored=query($db,'SELECT token_hash FROM password_reset_tokens WHERE id=?',[$reset_mail['password_reset_id']])->fetchColumn();
    check(hash_equals($stored,hash('sha256',$token)) && $stored!==$token,'only reset hash stored in token table');
    $new_password='  Changed '.bin2hex(random_bytes(12)).'  ';
    $old_token=$token;
    $lava->password_reset_service->request($prefix.'.owner@example.invalid');$reset_requests[]=(int)$db->lastInsertId();
    $lava->mail_service->dispatch('queue',25,$users['owner'],$transport);
    $reset_mail=query($db,'SELECT d.* FROM email_deliveries d JOIN password_reset_tokens t ON t.id=d.password_reset_id WHERE t.user_id=? ORDER BY d.id DESC LIMIT 1',[$users['owner']])->fetch(PDO::FETCH_ASSOC);
    $reset_payload=$lava->mail_cipher->open($reset_mail['payload_encrypted']);
    preg_match('/#token=([a-f0-9]{64})/',$reset_payload['text'],$match);$token=$match[1]??'';
    check($token!==$old_token,'subsequent request issues a distinct reset credential');
    try{$lava->password_reset_service->confirm($old_token,$new_password,$new_password);throw new LogicException('Superseded reset accepted');}
    catch(DomainException $error){check($error->getCode()===422,'superseded reset credential is invalid');}
    $lava->password_reset_service->confirm($token,$new_password,$new_password);
    $user=query($db,'SELECT password,auth_version FROM users WHERE id=?',[$users['owner']])->fetch(PDO::FETCH_ASSOC);
    check(password_verify($new_password,$user['password']) && (int)$user['auth_version']===1,'reset hashes exact password and increments session version');
    check(query($db,'SELECT used_at FROM password_reset_tokens WHERE id=?',[$reset_mail['password_reset_id']])->fetchColumn()!==null,'reset token consumed');
    try{$lava->password_reset_service->confirm($token,$new_password,$new_password);throw new LogicException('Reset replay accepted');}
    catch(DomainException $error){check($error->getCode()===422,'reset replay rejected');}
    $types=query($db,'SELECT notification_type FROM notifications WHERE recipient_id=?',[$users['owner']])->fetchAll(PDO::FETCH_COLUMN);
    check(in_array('account.password_changed',$types,TRUE),'password change creates security confirmation notification');
    echo "Passed {$checks} persistent email outbox and reset transaction checks (fake provider only).\n";
} finally {
    if($db->inTransaction())$db->rollBack();
    foreach($reset_requests as $id)query($db,'DELETE FROM password_reset_requests WHERE id=?',[$id]);
    foreach($users as $id) {
        query($db,'DELETE FROM notifications WHERE recipient_id=?',[$id]);
        query($db,'DELETE FROM password_reset_tokens WHERE user_id=?',[$id]);
        query($db,'DELETE FROM facility_bookings WHERE requester_id=?',[$id]);
        query($db,'DELETE FROM refresh_tokens WHERE user_id=?',[$id]);
        query($db,'DELETE FROM audit_logs WHERE actor_user_id=?',[$id]);
    }
    if($facility!==null)query($db,'DELETE FROM facilities WHERE id=?',[$facility]);
    foreach($users as $id)query($db,'DELETE FROM users WHERE id=?',[$id]);
    if($category!==null)query($db,'DELETE FROM facility_categories WHERE id=?',[$category]);
    if($department!==null)query($db,'DELETE FROM departments WHERE id=?',[$department]);
    foreach($before as $var=>$value)putenv($value===FALSE?$var:$var.'='.$value);
}
