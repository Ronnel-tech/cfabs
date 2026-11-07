<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
class Password_reset_service {
    private $lava;
    public function __construct()
    {
        $this->lava=lava_instance();
        $this->lava->call->model(['Password_reset_model','Account_model','Mail_model','Notification_model']);
        $this->lava->call->library(['account_rules','mail_configuration','mail_template']);
        date_default_timezone_set('UTC'); $this->lava->db->raw("SET SESSION time_zone = '+00:00'");
    }
    /** Same asynchronous path for every address; HTTP never queries account existence. */
    public function request(string $email): void
    {
        $this->lava->mail_configuration->validate(TRUE);
        $this->lava->call->library('mail_cipher');
        $this->lava->Password_reset_model->request($this->lava->mail_cipher->seal(['email'=>$email]));
    }
    /** Called only by the locked email worker, not an HTTP request. */
    public function prepare(int $limit,?int $recipient=null): int
    {
        $this->lava->call->library('mail_cipher');
        $model=$this->lava->Password_reset_model; $queued=0;
        foreach($model->requests($limit) as $request) {
            try { $address=$this->lava->mail_cipher->open($request['email_encrypted'])['email']??null; }
            catch(Throwable $error) { $address=null; }
            $candidate=is_string($address) && Mail_configuration::address($address)?$this->lava->Account_model->by_email($address):null;
            if ($recipient!==null && (!$candidate || (int)$candidate['id']!==$recipient)) continue;
            $this->lava->db->transaction();
            try {
                $user=$candidate?$this->lava->Account_model->by_id((int)$candidate['id'],TRUE):null;
                if($user && (int)$user['is_active']===1 && strcasecmp($user['email'],$address)===0 && strtotime($request['created_at'].' UTC')>time()-3600) {
                    $token=bin2hex(random_bytes(32));
                    $model->revoke((int)$user['id']);
                    $id=$model->create((int)$user['id'],$user['email'],hash('sha256',$token));
                    $name=trim(($user['first_name']??'').' '.($user['last_name']??''))?:$user['username'];
                    $url=$this->lava->mail_configuration->app_url().'/reset-password#token='.$token;
                    $payload=['from'=>$this->lava->mail_configuration->sender(),'to'=>[$user['email']]]
                        +$this->lava->mail_template->render('password_reset',$name,[],$url);
                    $this->lava->Mail_model->queue(null,$id,(int)$user['id'],$this->lava->mail_cipher->seal($payload));
                    $queued++;
                }
                $model->processed((int)$request['id']);
                $this->lava->db->commit();
            } catch(Throwable $error) { $this->lava->db->roll_back(); throw $error; }
        }
        return $queued;
    }
    public function confirm($token,$password,$confirmation): void
    {
        if(!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D',$token)) throw new DomainException('Reset link is invalid or expired.',422);
        try { $password=$this->lava->account_rules->password($password); }
        catch(InvalidArgumentException $error) { throw new DomainException($error->getMessage(),422); }
        if(!is_string($confirmation) || !hash_equals($password,$confirmation)) throw new DomainException('Passwords must match.',422);
        $hash=hash('sha256',$token); $model=$this->lava->Password_reset_model;
        $candidate=$model->token($hash);
        if(!$candidate) throw new DomainException('Reset link is invalid or expired.',422);
        $new_hash=password_hash($password,PASSWORD_DEFAULT);
        $this->lava->db->transaction();
        try {
            $user=$this->lava->Account_model->by_id((int)$candidate['user_id'],TRUE);
            $reset=$model->token($hash,TRUE);
            if(!$user || !$reset || (int)$user['is_active']!==1 || $user['email']!==$reset['email']) throw new DomainException('Reset link is invalid or expired.',422);
            $model->change_password((int)$user['id'],$new_hash);
            $this->lava->Account_model->audit((int)$user['id'],'auth.password_reset',(int)$user['id']);
            $this->lava->Notification_model->record((int)$user['id'],'account.password_changed','user',(int)$user['id'],'Campus password changed','Your campus password was changed. Previous sessions were invalidated.');
            $this->lava->db->commit();
        } catch(Throwable $error) { $this->lava->db->roll_back(); throw $error; }
    }
}
