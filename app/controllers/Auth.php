<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Auth extends Controller {
    public function __construct()
    {
        parent::__construct();
        $this->call->library('account_access');
        header('Cache-Control: no-store');
    }

    public function login()
    {
        $this->api->rate_limit('login:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 10, 60);
        $body = $this->account_access->json_body();
        $email = $body['email'] ?? null;
        $password = $body['password'] ?? null;
        if (!is_string($email) || strlen($email) > 255 || !filter_var(trim($email), FILTER_VALIDATE_EMAIL)
            || !is_string($password) || $password === '' || strlen($password) > 72 || str_contains($password, "\0")) {
            $this->api->respond_error('Valid email and password are required', 422);
        }
        $candidate = $this->Account_model->by_email(trim($email));
        // A fixed valid bcrypt hash avoids a cheap user-existence timing shortcut.
        $hash = $candidate['password'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
        if (!password_verify($password, $hash) || !$candidate) {
            $this->api->respond_error('Invalid email or password', 401);
        }
        $this->db->transaction();
        $user = $this->Account_model->by_id((int) $candidate['id'], TRUE);
        if (!$user || (int) $user['is_active'] !== 1 || !hash_equals($hash, $user['password'])) {
            $this->db->roll_back();
            $this->api->respond_error('Invalid email or password', 401);
        }
        $tokens = $this->account_access->issue_tokens($user);
        $this->Account_model->record_login((int) $user['id']);
        $this->Account_model->audit((int) $user['id'], 'auth.login', (int) $user['id']);
        $this->db->commit();
        $this->api->respond(['success' => TRUE, 'data' => $tokens]);
    }

    public function refresh()
    {
        $this->api->rate_limit('refresh:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 30, 60);
        $token = $this->account_access->token_field($this->account_access->json_body());
        $claims = $this->api->validate_jwt($token, 'refresh');
        if (!$claims || filter_var($claims['sub'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === FALSE) {
            $this->api->respond_error('Invalid refresh token', 401);
        }
        $this->db->transaction();
        $user = $this->Account_model->by_id((int) $claims['sub'], TRUE);
        if (!$user || (int) $user['is_active'] !== 1
            || !$this->Account_model->refresh_exists($token, (int) $user['id'])) {
            $this->db->roll_back();
            $this->api->respond_error('Refresh token expired or revoked', 401);
        }
        $this->api->revoke_refresh_token($token);
        $tokens = $this->account_access->issue_tokens($user);
        $this->db->commit();
        $this->api->respond(['success' => TRUE, 'data' => $tokens]);
    }

    public function logout()
    {
        $user = $this->account_access->require_user();
        $token = $this->account_access->token_field($this->account_access->json_body());
        $claims = $this->api->validate_jwt($token, 'refresh');
        if (!$claims || (string) $claims['sub'] !== (string) $user['id']) {
            $this->api->respond_error('Invalid refresh token', 401);
        }
        $this->db->transaction();
        $this->Account_model->by_id((int) $user['id'], TRUE);
        $this->api->revoke_refresh_token($token);
        $this->Account_model->audit((int) $user['id'], 'auth.logout', (int) $user['id']);
        $this->db->commit();
        $this->api->respond(['success' => TRUE, 'message' => 'Logged out']);
    }

    public function me()
    {
        $user = $this->account_access->require_user();
        $profile = $this->Account_model->public_profile($user);
        $profile['permissions'] = $user['permissions'];
        $this->api->respond(['success' => TRUE, 'data' => $profile]);
    }
}
