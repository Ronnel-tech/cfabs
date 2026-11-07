<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Access_model extends Model {
    private $connection;

    public function __construct()
    {
        parent::__construct();
        $lava = lava_instance();
        if (!isset($lava->properties['db'])) $lava->call->database();
        $this->connection = $lava->db;
    }

    public function role(int $id, bool $lock = FALSE): ?array
    {
        return $this->connection->raw('SELECT * FROM roles WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''), [$id])->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function role_name_exists(string $name, int $except = 0): bool
    {
        return (bool) $this->connection->raw('SELECT id FROM roles WHERE role_name = ? AND id <> ? LIMIT 1', [$name, $except])->fetchColumn();
    }

    public function save_role(?int $id, array $data): int
    {
        if ($id === null) {
            $this->connection->raw('INSERT INTO roles (role_name, description, is_system_role, created_at, updated_at)
                VALUES (?, ?, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())', [$data['role_name'], $data['description'] ?? null]);
            return (int) $this->connection->raw('SELECT LAST_INSERT_ID()')->fetchColumn();
        }
        $sets = implode(', ', array_map(fn($field) => $field . ' = ?', array_keys($data)));
        $this->connection->raw("UPDATE roles SET {$sets}, updated_at = UTC_TIMESTAMP() WHERE id = ?", [...array_values($data), $id]);
        return $id;
    }

    public function grants(int $role): array
    {
        return $this->connection->raw('SELECT p.permission_key FROM role_permissions rp
            JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ? ORDER BY p.permission_key', [$role])->fetchAll(PDO::FETCH_COLUMN);
    }

    public function replace_grants(int $role, array $keys): void
    {
        $this->connection->raw('DELETE FROM role_permissions WHERE role_id = ?', [$role]);
        foreach ($keys as $key) {
            $this->connection->raw('INSERT INTO role_permissions (role_id, permission_id)
                SELECT ?, id FROM permissions WHERE permission_key = ?', [$role, $key]);
        }
    }

    public function change_account(int $id, array $fields): void
    {
        if (!$fields || array_diff(array_keys($fields), ['role_id', 'department_id', 'first_name', 'last_name'])) {
            throw new InvalidArgumentException('Unsupported account fields.');
        }
        $sets = implode(', ', array_map(fn($field) => $field . ' = ?', array_keys($fields)));
        $this->connection->raw("UPDATE users SET {$sets}, updated_at = UTC_TIMESTAMP() WHERE id = ?", [...array_values($fields), $id]);
        if (array_key_exists('role_id', $fields)) {
            $this->connection->raw('DELETE FROM refresh_tokens WHERE user_id = ?', [$id]);
        }
    }

    public function administrator_count(): int
    {
        return (int) $this->connection->raw("SELECT COUNT(*) FROM (
            SELECT u.id FROM users u JOIN role_permissions rp ON rp.role_id = u.role_id
            JOIN permissions p ON p.id = rp.permission_id WHERE u.is_active = 1
            AND p.permission_key IN ('user.manage','role.manage','permission.manage')
            GROUP BY u.id HAVING COUNT(DISTINCT p.permission_key) = 3
        ) administrators")->fetchColumn();
    }

    public function audit(string $entity, int $id, int $actor, string $action, $before, $after): void
    {
        $this->connection->raw('INSERT INTO audit_logs
            (actor_user_id, action, entity_type, entity_id, old_values_json, new_values_json, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())', [$actor, $action, $entity, $id,
                $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR), json_encode($after, JSON_THROW_ON_ERROR),
                $_SERVER['REMOTE_ADDR'] ?? null, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);
    }

    public function acquire_lock(): bool
    {
        return (int) $this->connection->raw("SELECT GET_LOCK(CONCAT('cfabs_access_', LEFT(SHA2(DATABASE(), 256), 40)), 10)")->fetchColumn() === 1;
    }

    public function release_lock(): void
    {
        $this->connection->raw("SELECT RELEASE_LOCK(CONCAT('cfabs_access_', LEFT(SHA2(DATABASE(), 256), 40)))");
    }
}
