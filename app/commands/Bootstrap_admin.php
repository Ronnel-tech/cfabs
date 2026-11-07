<?php

class Bootstrap_admin {
    public static $command = 'admin:create';
    public static $description = 'Create an explicit system administrator; read password from stdin';
    public static $arguments = ['username' => 'Unique username', '--email=<address>' => 'Unique email address'];

    public function handle($username = null, array $flags = [], $unused = null)
    {
        if (!$username || empty($flags['email']) || !is_string($flags['email'])) {
            fwrite(STDERR, "Usage: php lava admin:create username --email=admin@example.edu (password on stdin)\n");
            exit(1);
        }
        putenv('CFABS_ADMIN_USERNAME=' . $username);
        putenv('CFABS_ADMIN_EMAIL=' . $flags['email']);
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(PUBLIC_DIR . 'index.php') . ' admin-bootstrap', $status);
        exit($status);
    }
}
