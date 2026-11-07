<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
class Mail_dispatch extends Controller {
    public function __construct()
    {
        if (!defined('IS_CLI') || !IS_CLI) { http_response_code(404); exit; }
        parent::__construct();
    }
    public function run()
    {
        $limit=filter_var(getenv('CFABS_MAIL_LIMIT')?:25,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100]]);
        $raw=getenv('CFABS_MAIL_RECIPIENT');
        $recipient=$raw===FALSE||$raw===''?null:filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if ($limit===FALSE || $recipient===FALSE) { fwrite(STDERR,"Invalid email dispatch limit or recipient.\n"); exit(1); }
        $this->call->library('mail_service');
        try { echo json_encode($this->mail_service->dispatch(getenv('CFABS_MAIL_MODE')?:'preview',$limit,$recipient),JSON_THROW_ON_ERROR).PHP_EOL; }
        catch(Throwable $error) { fwrite(STDERR,'Email dispatch could not run. Check configuration and database availability.'.PHP_EOL); exit(1); }
    }
}
