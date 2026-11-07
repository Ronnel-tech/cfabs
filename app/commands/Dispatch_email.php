<?php
class Dispatch_email {
    public static $command='email:dispatch';
    public static $description='Preview email outbox; --queue stores mail, --send submits to configured Resend';
    public static $arguments=['--queue'=>'Queue without contacting provider','--send'=>'Queue and send (requires MAIL_ENABLED=1)','--limit=<n>'=>'1–100 records, default 25','--recipient=<id>'=>'Limit to one recipient'];
    public function handle($unused=null,array $flags=[],$extra=null)
    {
        if ($unused!==null || $extra!==null || array_diff(array_keys($flags),['queue','send','limit','recipient'])
            || (isset($flags['queue'],$flags['send'])) || (isset($flags['queue']) && $flags['queue']!==TRUE)
            || (isset($flags['send']) && $flags['send']!==TRUE)
            || (isset($flags['limit']) && !is_string($flags['limit'])) || (isset($flags['recipient']) && !is_string($flags['recipient']))) {
            fwrite(STDERR,"Use --queue or --send, with optional --limit=<1–100> and --recipient=<id>.\n"); exit(1);
        }
        putenv('CFABS_MAIL_MODE='.(isset($flags['send'])?'send':(isset($flags['queue'])?'queue':'preview')));
        putenv('CFABS_MAIL_LIMIT='.($flags['limit']??'25'));
        putenv('CFABS_MAIL_RECIPIENT='.($flags['recipient']??''));
        passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(PUBLIC_DIR.'index.php').' email-dispatch',$status);
        exit($status);
    }
}
