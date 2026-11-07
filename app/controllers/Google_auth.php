<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Google_auth extends Controller {
    public function __construct()
    {
        parent::__construct();
        $this->call->library('account_access');
        $this->call->library('google_identity');
        $this->call->model('Google_identity_model');
        header('Cache-Control: no-store');
    }
    public function configuration()
    {
        $id = $this->google_identity->client_id();
        $this->api->respond(['success' => TRUE, 'data' => ['enabled' => $id !== '', 'client_id' => $id]]);
    }
    public function status()
    {
        $user = $this->account_access->require_user();
        $this->api->respond(['success' => TRUE, 'data' => $this->Google_identity_model->for_user((int) $user['id'])]);
    }
    public function challenge()
    {
        $this->limit('challenge', 30);
        $this->configured();
        $body = $this->account_access->json_body();
        $intent = $body['intent'] ?? null;
        if (!in_array($intent, ['login', 'link'], TRUE)) $this->api->respond_error('Choose login or link', 422);
        $user = $intent === 'link' ? $this->account_access->require_user() : null;
        $data = $this->Google_identity_model->challenge($intent, $user ? (int) $user['id'] : null);
        $this->api->respond(['success' => TRUE, 'data' => $data]);
    }
    public function login()
    {
        $this->complete(FALSE);
    }
    public function link()
    {
        $this->complete(TRUE);
    }
    private function complete(bool $link): void
    {
        $this->limit($link ? 'link' : 'login', 10);
        $this->configured();
        $actor = $link ? $this->account_access->require_user() : null;
        $body = $this->account_access->json_body();
        if (!is_string($body['credential'] ?? null) || strlen($body['credential']) > 16384
            || !is_string($body['challenge_token'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $body['challenge_token'])) {
            $this->api->respond_error('Google credential and challenge are required', 422);
        }
        try {
            $claims = $this->google_identity->verify($body['credential']);
        } catch (RuntimeException $error) {
            if ($error->getCode() === 503) $this->api->respond_error('Google verification is temporarily unavailable. Use your campus password or try again.', 503);
            $this->api->respond_error('Google credential is invalid or expired. Try Google Sign-In again.', $link ? 422 : 401);
        } catch (Throwable $error) {
            $this->api->respond_error('Google credential is invalid or expired. Try Google Sign-In again.', $link ? 422 : 401);
        }
        $id = $link ? (int) $actor['id'] : $this->Google_identity_model->owner($claims['sub']);
        if (!$id) $this->api->respond_error('This Google account is not linked. Sign in with your campus password, then link Google in My account.', 401);
        $this->db->transaction();
        try {
            $user = $this->Account_model->by_id($id, TRUE);
            if (!$user || (int) $user['is_active'] !== 1) throw new DomainException('Campus account is unavailable', 401);
            // Recheck under the campus user lock: unlink/deactivation cannot race login.
            if (!$link && $this->Google_identity_model->owner($claims['sub']) !== $id) throw new DomainException('Google account is no longer linked', 401);
            if ($link) {
                $this->check_password($body, $user);
                if ($this->Google_identity_model->for_user($id) || $this->Google_identity_model->owner($claims['sub'])) {
                    throw new DomainException('A Google account is already linked to one of these accounts. Unlink it first.', 409);
                }
            }
            if (!$this->Google_identity_model->consume($body['challenge_token'], $claims['nonce'], $link ? 'link' : 'login', $link ? $id : null)) {
                throw new DomainException('Google sign-in challenge expired or was already used. Try again.', $link ? 422 : 401);
            }
            if ($link) {
                $this->Google_identity_model->link($id, $claims);
                $data = $this->Google_identity_model->for_user($id);
            } else {
                $data = $this->account_access->issue_tokens($user);
                $this->Account_model->record_login($id);
            }
            $this->Account_model->audit($id, $link ? 'auth.google_link' : 'auth.google_login', $id);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            if ($error instanceof DomainException) $this->api->respond_error($error->getMessage(), $error->getCode());
            if ($error instanceof PDOException && (string) $error->getCode() === '23000') $this->api->respond_error('This Google account is already linked', 409);
            $this->api->respond_error('Could not complete Google sign-in', 500);
        }
        $this->api->respond(['success' => TRUE, 'data' => $data]);
    }
    public function unlink()
    {
        $this->limit('unlink', 10);
        $actor = $this->account_access->require_user();
        $body = $this->account_access->json_body();
        $this->db->transaction();
        try {
            $user = $this->Account_model->by_id((int) $actor['id'], TRUE);
            if (!$user || (int) $user['is_active'] !== 1) throw new DomainException('Campus account is unavailable', 401);
            $this->check_password($body, $user);
            $this->Google_identity_model->unlink((int) $user['id']);
            $this->Account_model->audit((int) $user['id'], 'auth.google_unlink', (int) $user['id']);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            if ($error instanceof DomainException) $this->api->respond_error($error->getMessage(), $error->getCode());
            $this->api->respond_error('Could not unlink Google', 500);
        }
        $this->api->respond(['success' => TRUE, 'message' => 'Google account unlinked']);
    }
    private function check_password(array $body, array $user): void
    {
        $password = $body['password'] ?? null;
        if (!is_string($password) || $password === '' || strlen($password) > 72 || str_contains($password, "\0") || !password_verify($password, $user['password'])) {
            throw new DomainException('Your campus password is incorrect', 422);
        }
    }
    private function configured(): void
    {
        if ($this->google_identity->client_id() === '') $this->api->respond_error('Google Sign-In is not configured', 503);
    }
    private function limit(string $action, int $maximum): void
    {
        $this->api->rate_limit('google-' . $action . ':' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), $maximum, 60);
    }
}
