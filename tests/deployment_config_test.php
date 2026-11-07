<?php
// Process-local placeholders only: no database or deployed secret is read.
$checks=0;$root=dirname(__DIR__);
function deployment_check(bool $ok,string $label):void { global $checks;if(!$ok)throw new RuntimeException('Failed: '.$label);$checks++; }
$vars=['APP_ENV'=>'production','APP_KEY'=>str_repeat('a',40),'JWT_SECRET'=>str_repeat('b',40),'REFRESH_TOKEN_KEY'=>str_repeat('c',40),
    'DB_DRIVER'=>'mysql','DB_HOST'=>'mysql.example.invalid','DB_NAME'=>'campus','DB_USER'=>'campus','DB_PASSWORD'=>'private-test-placeholder','DB_PREFIX'=>'',
    'APP_URL'=>'https://cfabs.onrender.com','MAIL_APP_URL'=>'https://cfabs-frontend.vercel.app','CORS_ALLOWED_ORIGINS'=>'https://cfabs-frontend.vercel.app'];
foreach($vars as $key=>$value)putenv($key.'='.$value);
$run=static function():array { global $root;$lines=[];$code=0;exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/deployment/check-env.php').' 2>&1',$lines,$code);return [$code,implode("\n",$lines)]; };
deployment_check($run()[0]===0,'valid platform settings');
foreach(['APP_KEY'=>'short','JWT_SECRET'=>str_repeat('c',40),'APP_ENV'=>'development','DB_PREFIX'=>'prefix_',
    'APP_URL'=>'http://cfabs.onrender.com','MAIL_APP_URL'=>'https://front.example/path','CORS_ALLOWED_ORIGINS'=>'https://*.vercel.app','DB_PASSWORD'=>''] as $key=>$value) {
    putenv($key.'='.$value);[$code,$message]=$run();deployment_check($code!==0,$key.' invalid configuration rejected');
    deployment_check(!str_contains($message,'private-test-placeholder'),'secrets never printed');putenv($key.'='.$vars[$key]);
}
define('PREVENT_DIRECT_ACCESS',TRUE);define('ROOT_DIR',$root.'/');
require $root.'/app/config/config.php';
deployment_check($config['base_url']==='https://cfabs.onrender.com','explicit backend base URL');
deployment_check($config['security_headers']['cors']['allowed_origins']===['https://cfabs-frontend.vercel.app'],'exact frontend CORS origin');
deployment_check($config['security_headers']['api_max_body']>=2800000,'private photo JSON fits deployment limit');
deployment_check($config['security_headers']['cors']['credentials']===FALSE,'bearer API does not enable cross-site cookies');
echo "Passed {$checks} deployment configuration checks.\n";
