<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Admin_bootstrap extends Controller {
    public function __construct()
    {
        if (!defined('IS_CLI') || !IS_CLI) {
            http_response_code(404);
            exit;
        }
        parent::__construct();
    }

    public function create()
    {
        $this->call->model('Account_model');
        $this->call->library('account_rules');
        $password = fgets(STDIN);
        try {
            $data = $this->account_rules->new_account([
                'username' => getenv('CFABS_ADMIN_USERNAME'), 'email' => getenv('CFABS_ADMIN_EMAIL'),
                'password' => $password === FALSE ? '' : rtrim($password, "\r\n"),
            ], FALSE);
        } catch (InvalidArgumentException $error) {
            fwrite(STDERR, $error->getMessage() . PHP_EOL);
            exit(1);
        }
        if ($this->Account_model->identity_exists($data['username'], $data['email'])) {
            fwrite(STDERR, "Username or email already exists; no account changed.\n");
            exit(1);
        }
        $role = $this->Account_model->role_by_name('system_admin');
        if (!$role) {
            fwrite(STDERR, "Run the access-control migration first.\n");
            exit(1);
        }
        $data['role_id'] = (int) $role['id'];
        $this->db->transaction();
        $id = $this->Account_model->create_account($data);
        $this->Account_model->audit(null, 'user.bootstrap_admin', $id);
        $this->db->commit();
        echo "System administrator created. No business approval permissions were granted.\n";
    }
}
