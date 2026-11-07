<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Account_model extends Model {
    protected $table = 'users';
    private $connection;

    public function __construct()
    {
        parent::__construct();
        $lava = lava_instance();
        if (!isset($lava->properties['db'])) $lava->call->database();
        $this->connection = $lava->db;
    }

    public function by_id(int $id, bool $lock = FALSE): ?array
    {
        $row = $this->connection->raw('SELECT u.*, r.role_name FROM users u
            LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = ?' . ($lock ? ' FOR UPDATE' : ''), [$id])->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function by_email(string $email): ?array
    {
        $row = $this->connection->raw('SELECT id, password FROM users WHERE email = ? LIMIT 1', [$email])->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function permissions(int $user_id): array
    {
        return $this->connection->raw('SELECT p.permission_key FROM users u
            JOIN roles r ON r.id = u.role_id
            JOIN role_permissions rp ON rp.role_id = u.role_id JOIN permissions p ON p.id = rp.permission_id
            WHERE u.id = ? AND u.is_active = 1
            AND NOT (r.role_name IN (\'system_admin\',\'booking_admin\',\'asset_custodian\')
                AND p.permission_key IN (\'facility_booking.create\',\'asset_booking.create\'))
            ORDER BY p.permission_key', [$user_id])->fetchAll(PDO::FETCH_COLUMN);
    }

    public function public_profile(array $user): array
    {
        return array_intersect_key($user, array_flip([
            'id', 'username', 'email', 'first_name', 'last_name', 'department_id', 'role_id', 'role_name', 'is_active', 'last_login_at',
        ]));
    }

    public function refresh_exists(string $token, int $user_id): bool
    {
        $hash = hash_hmac('sha256', $token, config_item('refresh_token_key'));
        return (bool) $this->connection->raw('SELECT id FROM refresh_tokens
            WHERE token = ? AND user_id = ? AND expires_at > NOW() FOR UPDATE', [$hash, $user_id])->fetchColumn();
    }

    public function record_login(int $user_id): void
    {
        $this->connection->raw('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?', [$user_id]);
    }

    public function audit(?int $actor, string $action, int $entity_id, ?array $details = null): void
    {
        $this->connection->raw('INSERT INTO audit_logs
            (actor_user_id, action, entity_type, entity_id, new_values_json, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())', [
                $actor, $action, 'user', $entity_id, $details === null ? null : json_encode($details, JSON_THROW_ON_ERROR),
                $_SERVER['REMOTE_ADDR'] ?? null, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        if (in_array($action, ['user.created','user.bootstrap_admin'], TRUE)) {
            $lava = lava_instance(); $lava->call->model('Notification_model');
            $lava->Notification_model->account($entity_id, 'created');
        }
    }

    public function create_account(array $data): int
    {
        $this->connection->raw('INSERT INTO users
            (username, email, password, role, is_active, role_id, department_id, first_name, last_name, created_at)
            VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, UTC_TIMESTAMP())', [
                $data['username'], $data['email'], password_hash($data['password'], PASSWORD_DEFAULT), 'user',
                $data['role_id'], $data['department_id'] ?? null, $data['first_name'] ?? null, $data['last_name'] ?? null,
            ]);
        return (int) $this->connection->raw('SELECT LAST_INSERT_ID()')->fetchColumn();
    }

    public function identity_exists(string $username, string $email): bool
    {
        return (bool) $this->connection->raw('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1',
            [$username, $email])->fetchColumn();
    }

    public function role_by_name(string $name): ?array
    {
        return $this->connection->raw('SELECT id, role_name FROM roles WHERE role_name = ?', [$name])->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function list_accounts(int $limit, int $offset): array
    {
        return $this->connection->raw("SELECT u.id, u.username, u.email, u.first_name, u.last_name,
            u.department_id, u.role_id, r.role_name, u.is_active, u.last_login_at
            FROM users u LEFT JOIN roles r ON r.id = u.role_id ORDER BY u.id LIMIT {$limit} OFFSET {$offset}")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function account_total(): int
    {
        return (int) $this->connection->raw('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    public function role_exists(int $id): bool
    {
        return (bool) $this->connection->raw('SELECT id FROM roles WHERE id = ?', [$id])->fetchColumn();
    }

    public function active_department_exists(int $id, bool $lock = FALSE): bool
    {
        return (bool) $this->connection->raw("SELECT id FROM departments WHERE id = ? AND status = 'active'" . ($lock ? ' FOR UPDATE' : ''), [$id])->fetchColumn();
    }

    public function update_status(int $id, bool $active): void
    {
        $this->connection->raw('UPDATE users SET is_active = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [(int) $active, $id]);
        if (!$active) {
            $this->connection->raw('DELETE FROM refresh_tokens WHERE user_id = ?', [$id]);
        }
    }

    public function list_roles(): array
    {
        return $this->connection->raw('SELECT id, role_name, description FROM roles ORDER BY role_name')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function list_permissions(): array
    {
        return $this->connection->raw('SELECT id, permission_key, description FROM permissions ORDER BY permission_key')->fetchAll(PDO::FETCH_ASSOC);
    }
}
