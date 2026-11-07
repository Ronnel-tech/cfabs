<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
class Password_reset extends Controller {
    public function __construct()
    {
        parent::__construct(); $this->call->library(['account_access','password_reset_service']);
        header('Cache-Control: no-store');
    }
    public function configuration()
    {
        $this->api->respond(['success'=>TRUE,'data'=>['enabled'=>$this->mail_configuration->ready()]]);
    }
    public function request()
    {
        $this->api->rate_limit('reset-request:'.($_SERVER['REMOTE_ADDR']??'unknown'),10,3600);
        $body=$this->account_access->json_body(); $email=$body['email']??null;
        if(!is_string($email) || !Mail_configuration::address(trim($email))) $this->api->respond_error('A valid email is required.',422);
        if(!$this->mail_configuration->ready()) $this->api->respond_error('Password reset email is not configured. Contact campus administration.',503);
        $email=trim($email);
        $this->api->rate_limit('reset-address:'.hash('sha256',strtolower($email)),3,3600);
        try { $this->password_reset_service->request($email); }
        catch(Throwable $error) { $this->api->respond_error('Could not process the reset request. Try again later.',503); }
        $this->api->respond(['success'=>TRUE,'message'=>'If an active campus account matches this address, a reset email will be sent. Check your inbox and spam folder.']);
    }
    public function confirm()
    {
        $this->api->rate_limit('reset-confirm:'.($_SERVER['REMOTE_ADDR']??'unknown'),20,3600);
        $body=$this->account_access->json_body();
        try { $this->password_reset_service->confirm($body['token']??null,$body['password']??null,$body['confirmation']??null); }
        catch(DomainException $error) { $this->api->respond_error($error->getMessage(),$error->getCode()?:422); }
        catch(Throwable $error) { $this->api->respond_error('Could not reset the password. Try again later.',503); }
        $this->api->respond(['success'=>TRUE,'message'=>'Password changed. Sign in with your new password. Previous sessions have been invalidated.']);
    }
}
