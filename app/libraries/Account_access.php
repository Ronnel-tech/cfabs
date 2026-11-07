<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** Application authorization uses current database grants, never legacy JWT scopes. */
class Account_access {
    private $lava;

    public function __construct()
    {
        $this->lava = lava_instance();
        if (!isset($this->lava->properties['db'])) $this->lava->call->database();
        // Native token storage uses date() and NOW(); keep both clocks in UTC.
        date_default_timezone_set('UTC');
        $this->lava->db->raw("SET SESSION time_zone = '+00:00'");
        $this->lava->call->library('api');
        $this->lava->call->model('Account_model');
    }

    public function require_user(?string $permission = null): array
    {
        $claims = $this->lava->api->require_jwt();
        if (filter_var($claims['sub'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === FALSE) {
            $this->lava->api->respond_error('Unauthorized', 401);
        }
        $user = $this->lava->Account_model->by_id((int) $claims['sub']);
        if (!$user || (int) $user['is_active'] !== 1 || ($claims['auth_version'] ?? 0) !== (int) ($user['auth_version'] ?? 0)) {
            $this->lava->api->respond_error('Unauthorized', 401);
        }
        $user['permissions'] = $this->lava->Account_model->permissions((int) $user['id']);
        if ($permission !== null && !in_array($permission, $user['permissions'], TRUE)) {
            $this->lava->api->respond_error('Forbidden', 403);
        }
        return $user;
    }

    /** Preserve native refresh storage/rotation; version access tokens for resets. */
    public function issue_tokens(array $user): array
    {
        $tokens = $this->lava->api->issue_tokens($user);
        $claims = $this->lava->api->validate_jwt($tokens['access_token']);
        if (!$claims) throw new RuntimeException('Could not issue campus session.');
        $claims['auth_version'] = (int) ($user['auth_version'] ?? 0);
        $tokens['access_token'] = $this->lava->api->encode_jwt($claims);
        return $tokens;
    }

    /** Preserve password bytes: the native Api::body() trims every string. */
    public function json_body(): array
    {
        if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
            $this->lava->api->respond_error('Use application/json', 415);
        }
        try {
            $value = json_decode(file_get_contents('php://input'), FALSE, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            $this->lava->api->respond_error('Invalid JSON body', 400);
        }
        if (!is_object($value)) {
            $this->lava->api->respond_error('JSON body must be an object', 400);
        }
        return (array) $value;
    }

    public function token_field(array $body): string
    {
        $token = $body['refresh_token'] ?? null;
        if (!is_string($token) || $token === '' || strlen($token) > 4096) {
            $this->lava->api->respond_error('A refresh_token is required', 422);
        }
        return $token;
    }
}
