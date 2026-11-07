<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Resend_transport {
    public function send(array $payload, string $key): array
    {
        $config=new Mail_configuration(); $config->validate(TRUE);
        $body=''; $retry_after=0;
        $curl=curl_init('https://api.resend.com/emails');
        if (PHP_OS_FAMILY==='Windows' && defined('CURLSSLOPT_NATIVE_CA')) curl_setopt($curl,CURLOPT_SSL_OPTIONS,CURLSSLOPT_NATIVE_CA);
        curl_setopt_array($curl,[
            CURLOPT_POST=>TRUE,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.getenv('MAIL_API_KEY'),'Idempotency-Key: cfabs-'.$key],
            CURLOPT_FOLLOWLOCATION=>FALSE,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>10,
            CURLOPT_SSL_VERIFYPEER=>TRUE,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HEADERFUNCTION=>function($handle,$line)use(&$retry_after){
                if(preg_match('/^Retry-After:\s*(\d+)\s*$/i',$line,$match)) $retry_after=min(86400,(int)$match[1]);
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION=>function($handle,$chunk)use(&$body){ if(strlen($body)+strlen($chunk)>65536)return 0; $body.=$chunk; return strlen($chunk); },
        ]);
        $ok=curl_exec($curl); $status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE); $errno=curl_errno($curl); curl_close($curl);
        return self::result($status,$body,$ok!==FALSE?$retry_after:0,$errno);
    }
    public static function result(int $status,string $body,int $retry_after=0,int $errno=0): array
    {
        $data=json_decode($body,TRUE);
        if ($status>=200 && $status<300 && is_string($data['id']??null) && preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$data['id'])) {
            return ['accepted'=>TRUE,'provider_id'=>$data['id'],'retryable'=>FALSE,'code'=>null,'retry_after'=>0];
        }
        $category=$data['name']??'';
        $retryable=$errno!==0 || $status===0 || $status===408 || $status===429 || $status>=500
            || ($status===409 && in_array($category,['concurrent_idempotent_requests','resource_locked'],TRUE))
            || ($status>=200 && $status<300);
        // Never persist provider bodies, recipient addresses or request headers.
        $code=$errno!==0?'network_'.$errno:'provider_http_'.$status;
        return ['accepted'=>FALSE,'provider_id'=>null,'retryable'=>$retryable,'code'=>$code,'retry_after'=>max(0,min(86400,$retry_after))];
    }
}
