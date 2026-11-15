<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Scheduled_mail extends Controller {
    public function run()
    {
        $expected=getenv('MAIL_DISPATCH_TOKEN') ?: '';
        $provided=$_SERVER['HTTP_X_CFABS_DISPATCH_TOKEN'] ?? '';
        if (strlen($expected)<32 || !is_string($provided) || !hash_equals($expected,$provided)) {
            http_response_code(404);
            return;
        }
        $this->call->library(['reminder_service','mail_service']);
        try {
            $this->reminder_service->dispatch(TRUE,24,null);
            $this->mail_service->dispatch('send',25,null);
            header('Content-Type: application/json');
            echo '{"ok":true}';
        } catch (Throwable $error) {
            error_log('CFABS scheduled email dispatch failed.');
            http_response_code(503);
            echo '{"ok":false}';
        }
    }
}
