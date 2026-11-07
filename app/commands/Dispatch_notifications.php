<?php

class Dispatch_notifications {
    public static $command = 'notifications:dispatch';
    public static $description = 'Preview scheduled in-app reminders; use --send to write notifications';
    public static $arguments = ['--send' => 'Dispatch instead of dry run', '--hours=<n>' => 'Upcoming horizon: 1–168 hours, default 24', '--recipient=<id>' => 'Limit dispatch to one recipient'];
    public function handle($unused = null, array $flags = [], $extra = null)
    {
        if ($unused !== null || $extra !== null || array_diff(array_keys($flags), ['send','hours','recipient'])
            || (isset($flags['send']) && $flags['send'] !== TRUE)
            || (isset($flags['hours']) && !is_string($flags['hours']))
            || (isset($flags['recipient']) && !is_string($flags['recipient']))) { fwrite(STDERR, "Use --send and optional --hours=<1–168> and --recipient=<id>.\n"); exit(1); }
        putenv('CFABS_REMINDER_SEND=' . (isset($flags['send']) ? '1' : '0'));
        putenv('CFABS_REMINDER_HOURS=' . ($flags['hours'] ?? '24'));
        putenv('CFABS_REMINDER_RECIPIENT=' . ($flags['recipient'] ?? ''));
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(PUBLIC_DIR . 'index.php') . ' reminder-dispatch', $status);
        exit($status);
    }
}
