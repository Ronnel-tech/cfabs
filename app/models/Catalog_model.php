<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Catalog_model extends Model {
    private $connection;
    private const TABLES = ['departments', 'facility_categories', 'asset_categories', 'facilities', 'assets', 'asset_instances'];

    public function __construct()
    {
        parent::__construct();
        $lava = lava_instance();
        if (!isset($lava->properties['db'])) $lava->call->database();
        $this->connection = $lava->db;
    }

    private function table(string $table): string
    {
        if (!in_array($table, self::TABLES, TRUE)) throw new InvalidArgumentException('Unknown catalog.');
        return $table;
    }

    public function find_record(string $table, int $id, bool $lock = FALSE): ?array
    {
        $table = $this->table($table);
        return $this->connection->raw("SELECT * FROM {$table} WHERE id = ?" . ($lock ? ' FOR UPDATE' : ''), [$id])->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function list_records(string $table, int $limit, int $offset, bool $include_inactive = FALSE, ?int $asset = null): array
    {
        $table = $this->table($table);
        $conditions = $include_inactive ? [] : ["status = 'active'"];
        $values = [];
        if ($asset !== null) { $conditions[] = 'asset_id = ?'; $values[] = $asset; }
        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
        return $this->connection->raw("SELECT * FROM {$table}{$where} ORDER BY id LIMIT {$limit} OFFSET {$offset}", $values)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function duplicate(string $table, string $column, string $value, int $except = 0): bool
    {
        $allowed = ['department_code', 'category_name', 'facility_code', 'asset_code', 'asset_tag', 'serial_number'];
        if (!in_array($column, $allowed, TRUE)) throw new InvalidArgumentException('Unknown identity field.');
        $table = $this->table($table);
        return (bool) $this->connection->raw("SELECT id FROM {$table} WHERE {$column} = ? AND id <> ? LIMIT 1", [$value, $except])->fetchColumn();
    }

    public function insert_record(string $table, array $data): int
    {
        $table = $this->table($table);
        // Only Catalog_rules output and server-owned metadata reach this method.
        $columns = implode(', ', array_keys($data));
        $marks = implode(', ', array_fill(0, count($data), '?'));
        $this->connection->raw("INSERT INTO {$table} ({$columns}) VALUES ({$marks})", array_values($data));
        return (int) $this->connection->raw('SELECT LAST_INSERT_ID()')->fetchColumn();
    }

    public function update_record(string $table, int $id, array $data): void
    {
        $table = $this->table($table);
        $assignments = implode(', ', array_map(fn($column) => $column . ' = ?', array_keys($data)));
        $this->connection->raw("UPDATE {$table} SET {$assignments}, updated_at = UTC_TIMESTAMP() WHERE id = ?", array_merge(array_values($data), [$id]));
    }

    public function has_bookings(string $table, int $id): bool
    {
        if ($table === 'facilities') {
            $sql = "SELECT id FROM facility_bookings WHERE facility_id = ? AND status IN ('PENDING','APPROVED') AND end_datetime > UTC_TIMESTAMP() LIMIT 1";
        } elseif ($table === 'assets') {
            $sql = "SELECT id FROM asset_bookings WHERE asset_id = ? AND
                ((status IN ('PENDING','APPROVED','READY_FOR_RELEASE') AND expected_return > UTC_TIMESTAMP()) OR status IN ('RELEASED','RETURNED')) LIMIT 1";
        } elseif ($table === 'asset_instances') {
            $sql = "SELECT b.id FROM asset_booking_instances x JOIN asset_bookings b ON b.id = x.asset_booking_id
                WHERE x.asset_instance_id = ? AND ((b.status IN ('PENDING','APPROVED','READY_FOR_RELEASE') AND b.expected_return > UTC_TIMESTAMP())
                    OR b.status IN ('RELEASED','RETURNED')) LIMIT 1";
        } else {
            throw new InvalidArgumentException('Unknown booking resource.');
        }
        return (bool) $this->connection->raw($sql, [$id])->fetchColumn();
    }

    public function maximum_participants(int $facility): int
    {
        return (int) $this->connection->raw("SELECT COALESCE(MAX(participants_count), 0) FROM facility_bookings
            WHERE facility_id = ? AND status IN ('PENDING','APPROVED') AND end_datetime > UTC_TIMESTAMP()", [$facility])->fetchColumn();
    }

    public function active_children(string $table, int $id): bool
    {
        if ($table === 'facility_categories' || $table === 'asset_categories') {
            $child = $table === 'facility_categories' ? 'facilities' : 'assets';
            return (bool) $this->connection->raw("SELECT id FROM {$child} WHERE category_id = ? AND status = 'active' LIMIT 1", [$id])->fetchColumn();
        }
        if ($table === 'departments') {
            return (bool) $this->connection->raw("SELECT id FROM users WHERE department_id = ? AND is_active = 1
                UNION ALL SELECT id FROM assets WHERE owning_department_id = ? AND status = 'active' LIMIT 1", [$id, $id])->fetchColumn();
        }
        return FALSE;
    }

    public function audit(string $table, int $id, int $actor, ?array $before, array $after): void
    {
        $entities = ['departments' => 'department', 'facility_categories' => 'facility_category', 'asset_categories' => 'asset_category',
            'facilities' => 'facility', 'assets' => 'asset', 'asset_instances' => 'asset_instance'];
        $entity = $entities[$table];
        $this->connection->raw('INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, old_values_json, new_values_json, created_at)
            VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())', [$actor, $entity . ($before === null ? '.created' : '.updated'), $entity, $id,
                $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR), json_encode($after, JSON_THROW_ON_ERROR)]);
    }

    public function acquire_write_lock(): bool
    {
        // Serialize identity checks across all catalog API writes, including new rows.
        return (int) $this->connection->raw("SELECT GET_LOCK(CONCAT('cfabs_catalog_', LEFT(SHA2(DATABASE(), 256), 40)), 10)")->fetchColumn() === 1;
    }

    public function release_write_lock(): void
    {
        $this->connection->raw("SELECT RELEASE_LOCK(CONCAT('cfabs_catalog_', LEFT(SHA2(DATABASE(), 256), 40)))");
    }
}
