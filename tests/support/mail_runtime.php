<?php
/** Process-local LavaLust loader boundary for real mail models/transactions. */
define('PREVENT_DIRECT_ACCESS',TRUE);
define('SYSTEM_DIR',$root.'/scheme/');
class Model { public function __construct() {} }
class Mail_test_lava {
    public array $properties=[];
    public function __get($name) { return $this->properties[$name]; }
}
class Mail_test_loader {
    public function __construct(private Mail_test_lava $lava) {}
    public function model($names): void
    {
        global $root;
        foreach((array)$names as $name) {
            if(isset($this->lava->properties[$name]))continue;
            require_once $root.'/app/models/'.$name.'.php';
            $this->lava->properties[$name]=new $name();
        }
    }
    public function library($names,$params=null): void
    {
        global $root;
        foreach((array)$names as $name) {
            if(isset($this->lava->properties[$name]))continue;
            $class=ucfirst($name);
            require_once $root.'/app/libraries/'.$class.'.php';
            $this->lava->properties[$name]=$params===null?new $class():new $class($params);
        }
    }
}
$mail_test_lava=new Mail_test_lava();
$mail_test_lava->properties['db']=new class($db) {
    public function __construct(private PDO $connection) {}
    public function raw($sql,$values=[]) { return query($this->connection,$sql,$values); }
    public function transaction() { $this->connection->beginTransaction(); }
    public function commit() { $this->connection->commit(); }
    public function roll_back() { if($this->connection->inTransaction())$this->connection->rollBack(); }
};
$mail_test_lava->properties['call']=new Mail_test_loader($mail_test_lava);
function lava_instance() { global $mail_test_lava; return $mail_test_lava; }
function config_item($name) { global $environment; return $name==='encryption_key'?$environment['APP_KEY']:null; }
$mail_test_lava->call->library('mail_service');
$mail_test_lava->call->library('resend_transport');
class Mail_test_transport extends Resend_transport {
    public array $calls=[];
    public string $outcome='accepted';
    public function send(array $payload,string $key): array
    {
        $this->calls[]=['payload'=>$payload,'key'=>$key];
        if($this->outcome==='throw')throw new RuntimeException('Simulated unavailable provider');
        return $this->outcome==='accepted'
            ? ['accepted'=>TRUE,'provider_id'=>'49a3999c-0ce1-4ea6-ab68-afcd6dc2e794','retryable'=>FALSE,'code'=>null,'retry_after'=>0]
            : ['accepted'=>FALSE,'provider_id'=>null,'retryable'=>$this->outcome==='retry','code'=>'provider_http_'.($this->outcome==='retry'?'429':'422'),'retry_after'=>300];
    }
}
