<?php
define('PREVENT_DIRECT_ACCESS',TRUE);
define('SYSTEM_DIR',dirname(__DIR__).'/scheme/');
$test_key=str_repeat('k',64);
function config_item($key) { global $test_key; return $key==='encryption_key'?$test_key:null; }
foreach(['Mail_configuration','Mail_cipher','Mail_template','Resend_transport','Account_rules'] as $class) require dirname(__DIR__).'/app/libraries/'.$class.'.php';
$checks=0;
function verify_mail($value,$name) { global $checks; if(!$value) throw new RuntimeException('Failed: '.$name); $checks++; }
function reject_mail($callback,$name) { try { $callback(); } catch(Throwable $error) { verify_mail(TRUE,$name); return; } throw new RuntimeException('Failed: '.$name); }
$vars=['MAIL_PROVIDER','MAIL_ENABLED','MAIL_API_KEY','MAIL_FROM_ADDRESS','MAIL_FROM_NAME','MAIL_APP_URL'];
$before=[]; foreach($vars as $var)$before[$var]=getenv($var);
try {
    putenv('MAIL_PROVIDER=resend'); putenv('MAIL_ENABLED=1'); putenv('MAIL_API_KEY=test-no-network');
    putenv('MAIL_FROM_ADDRESS=campus@example.invalid'); putenv('MAIL_FROM_NAME=CFABS'); putenv('MAIL_APP_URL=http://127.0.0.1:5173');
    $config=new Mail_configuration(); verify_mail($config->ready(),'valid local configuration');
    foreach(["bad\r\nBcc: other@example.invalid",'bad','a@example.invalid'."\0"] as $bad) verify_mail(!Mail_configuration::address($bad),'invalid recipient rejected');
    foreach(['http://campus.example','https://user:pass@campus.example','https://campus.example?token=secret','https://campus.example#token=x','javascript:alert(1)'] as $bad) {
        putenv('MAIL_APP_URL='.$bad); reject_mail(fn()=>$config->app_url(),'untrusted app URL rejected');
    }
    putenv('MAIL_APP_URL=https://campus.example/portal/'); verify_mail($config->app_url()==='https://campus.example/portal','trusted app base path');
    putenv("MAIL_FROM_NAME=CFABS\r\nBcc: other"); reject_mail(fn()=>$config->sender(),'sender header injection rejected');
    putenv('MAIL_FROM_NAME=CFABS'); putenv('MAIL_ENABLED=0'); verify_mail(!$config->ready(),'sending off by default');
    $cipher=new Mail_cipher(); $payload=['to'=>['student@example.invalid'],'token'=>str_repeat('f',64)];
    $sealed=$cipher->seal($payload); verify_mail($cipher->open($sealed)===$payload,'authenticated envelope round trip');
    verify_mail(!str_contains($sealed,'student@example.invalid') && !str_contains($sealed,$payload['token']),'envelope does not expose reset credential');
    reject_mail(fn()=>$cipher->open('broken'),'malformed envelope rejected');
    $raw=base64_decode($sealed); $raw[15]=chr(ord($raw[15])^1); reject_mail(fn()=>$cipher->open(base64_encode($raw)),'tampered envelope rejected');
    $test_key=str_repeat('z',64); $wrong=new Mail_cipher(); reject_mail(fn()=>$wrong->open($sealed),'wrong key fails closed');
    $test_key=str_repeat('k',64);
    $template=new Mail_template();
    $mail=$template->render('facility_booking.approved','<script>alert(1)</script>',['Resource'=>'<img src=x onerror=bad>','Current status'=>'APPROVED'],'https://campus.example/requests/facility/1');
    verify_mail(!str_contains($mail['html'],'<script>') && !str_contains($mail['html'],'<img src=x'),'email HTML escapes names and fields');
    verify_mail(str_contains($mail['html'],'&lt;script&gt;') && str_contains($mail['text'],'<script>'),'text alternative preserved without HTML execution');
    foreach(['account.created','account.activated','account.deactivated','account.password_changed','asset_booking.release','asset_booking.return','asset_booking.inspection','asset_return_due','asset_overdue','facility_upcoming','asset_upcoming','facility_maintenance.scheduled','asset_maintenance.completed','password_reset'] as $event) verify_mail($template->event($event)!==null,'supported event '.$event);
    verify_mail($template->event('untrusted.unknown')===null,'unknown event suppressed');
    $accepted=Resend_transport::result(200,'{"id":"49a3999c-0ce1-4ea6-ab68-afcd6dc2e794"}'); verify_mail($accepted['accepted'],'provider acceptance recognized');
    foreach([0,408,429,500,503] as $status) verify_mail(Resend_transport::result($status,'{}')['retryable'],'transient status retried');
    foreach([400,401,403,404,422] as $status) verify_mail(!Resend_transport::result($status,'{"message":"private body"}')['retryable'],'permanent status not retried');
    verify_mail(Resend_transport::result(409,'{"name":"concurrent_idempotent_requests"}')['retryable'],'same-key in-flight conflict retried');
    verify_mail(!Resend_transport::result(409,'{"name":"invalid_idempotent_request"}')['retryable'],'changed-body conflict not retried');
    verify_mail(Resend_transport::result(200,'{}')['retryable'],'ambiguous acceptance retains idempotent retry');
    verify_mail(Resend_transport::result(0,'',0,60)['code']==='network_60','TLS failures recorded without weakening verification');
    verify_mail(!str_contains(json_encode(Resend_transport::result(422,'{"message":"private body"}')),'private body'),'provider response body never forwarded');
    $rules=new Account_rules(); verify_mail($rules->password('  safe password  ')==='  safe password  ','password bytes preserved');
    foreach(['short',str_repeat('x',73),"password with\0nul"] as $bad) reject_mail(fn()=>$rules->password($bad),'invalid reset password rejected');
    echo "Passed {$checks} email transport, template and encryption checks.\n";
} finally { foreach($before as $var=>$value)putenv($value===FALSE?$var:$var.'='.$value); }
