<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Reminder_dispatch extends Controller {
    public function __construct()
    {
        if (!defined('IS_CLI') || !IS_CLI) { http_response_code(404); exit; }
        parent::__construct();
    }
    public function run()
    {
        $raw_hours = getenv('CFABS_REMINDER_HOURS');
        $hours = filter_var($raw_hours === FALSE || $raw_hours === '' ? 24 : $raw_hours, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 168]]);
        if ($hours === FALSE) { fwrite(STDERR, "Invalid reminder horizon.\n"); exit(1); }
        $recipient = getenv('CFABS_REMINDER_RECIPIENT');
        $recipient = $recipient === FALSE || $recipient === '' ? null : filter_var($recipient, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($recipient === FALSE) { fwrite(STDERR, "Invalid recipient.\n"); exit(1); }
        $this->call->library('reminder_service');
        try { echo json_encode($this->reminder_service->dispatch(getenv('CFABS_REMINDER_SEND') === '1', $hours, $recipient), JSON_THROW_ON_ERROR) . PHP_EOL; }
        catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . PHP_EOL); exit(1); }
    }
}
