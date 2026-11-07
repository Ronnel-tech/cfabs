<?php
// Platform environment only. Never print secret values or load a local .env.
$errors=[];
foreach(['APP_KEY','JWT_SECRET','REFRESH_TOKEN_KEY','DB_HOST','DB_NAME','DB_USER','DB_PASSWORD','APP_URL','CORS_ALLOWED_ORIGINS','MAIL_APP_URL'] as $key)if(trim(getenv($key)?:'')==='')$errors[]=$key.' is required';
foreach(['APP_KEY','JWT_SECRET','REFRESH_TOKEN_KEY'] as $key)if(strlen(getenv($key)?:'')<32)$errors[]=$key.' must contain at least 32 characters';
if(getenv('JWT_SECRET')===getenv('REFRESH_TOKEN_KEY'))$errors[]='JWT_SECRET and REFRESH_TOKEN_KEY must differ';
if(getenv('APP_ENV')!=='production')$errors[]='APP_ENV must be production';
if((getenv('DB_DRIVER')?:'mysql')!=='mysql')$errors[]='DB_DRIVER must be mysql';
if(getenv('DB_PREFIX'))$errors[]='DB_PREFIX must be empty for existing queries';
$origin=static function($value):bool {
    $url=parse_url($value);
    return filter_var($value,FILTER_VALIDATE_URL)!==FALSE && is_array($url) && ($url['scheme']??'')==='https' && !empty($url['host']) && !str_contains($url['host'],'*')
        && !isset($url['user']) && !isset($url['pass']) && !isset($url['query']) && !isset($url['fragment']) && in_array($url['path']??'',['','/'],TRUE);
};
foreach(['APP_URL','MAIL_APP_URL'] as $key)if(!$origin(getenv($key)?:''))$errors[]=$key.' must be an HTTPS origin without a path';
foreach(explode(',',getenv('CORS_ALLOWED_ORIGINS')?:'') as $value)if(!$origin(trim($value)))$errors[]='CORS_ALLOWED_ORIGINS requires exact HTTPS origins, without wildcards';
if($errors){fwrite(STDERR,implode(PHP_EOL,array_unique($errors)).PHP_EOL);exit(1);}
echo "Production environment configuration validated.\n";
