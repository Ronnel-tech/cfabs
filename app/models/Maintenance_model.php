<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Maintenance_model extends Model {
    private $connection;

    public function __construct()
    {
        parent::__construct();
        $lava = lava_instance();
        if (!isset($lava->properties['db'])) $lava->call->database();
        $this->connection = $lava->db;
    }

    public function table(string $kind): string
    {
        if (!in_array($kind, ['facility','asset'], TRUE)) throw new InvalidArgumentException('Unknown maintenance kind.');
        return $kind . '_maintenance';
    }

    public function find_record(string $kind, int $id, bool $lock = FALSE): ?array
    {
        $table = $this->table($kind);
        return $this->connection->raw("SELECT * FROM {$table} WHERE id = ?" . ($lock ? ' FOR UPDATE' : ''), [$id])->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function records(string $kind, int $limit, int $offset, ?int $resource): array
    {
        $table = $this->table($kind);
        $where = $resource === null ? '' : " WHERE m.{$kind}_id = ?";
        $label = $kind === 'asset' ? ', i.asset_tag' : '';
        $join = $kind === 'asset' ? ' LEFT JOIN asset_instances i ON i.id = m.asset_instance_id' : '';
        return $this->connection->raw("SELECT m.*{$label} FROM {$table} m{$join}{$where} ORDER BY m.id DESC LIMIT {$limit} OFFSET {$offset}", $resource === null ? [] : [$resource])->fetchAll(PDO::FETCH_ASSOC);
    }

    public function insert_record(string $kind, array $fields): int
    {
        $table = $this->table($kind);
        $columns = implode(',', array_keys($fields));
        $marks = implode(',', array_fill(0, count($fields), '?'));
        $this->connection->raw("INSERT INTO {$table} ({$columns}) VALUES ({$marks})", array_values($fields));
        return (int) $this->connection->last_id();
    }

    public function conflicts(string $kind, int $resource, string $start, string $end, ?int $instance): array
    {
        $s = lava_instance()->Booking_model->schema($kind);
        $extra = $instance === null ? '' : ' AND EXISTS (SELECT 1 FROM asset_booking_instances x WHERE x.asset_booking_id = b.id AND x.asset_instance_id = ?)';
        $values = [$resource, $end, $start];
        if ($instance !== null) $values[] = $instance;
        return $this->connection->raw("SELECT b.* FROM {$s['table']} b WHERE b.{$s['key']} = ?
            AND b.status IN ('APPROVED','READY_FOR_RELEASE','RELEASED','RETURNED')
            AND b.{$s['start']} < ? AND (b.{$s['end']} > ? OR b.status IN ('RELEASED','RETURNED')){$extra}", $values)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function overlapping(string $kind, int $resource, string $start, string $end, ?int $instance): bool
    {
        $table = $this->table($kind);
        $finish = $kind === 'facility' ? 'end_datetime' : 'expected_completion';
        $extra = $kind === 'asset' && $instance !== null ? ' AND (asset_instance_id = ? OR asset_instance_id IS NULL)' : '';
        $values = [$resource, $end, $start];
        if ($extra !== '') $values[] = $instance;
        return (bool) $this->connection->raw("SELECT id FROM {$table} WHERE {$kind}_id = ? AND status IN ('scheduled','in_progress')
            AND start_datetime < ? AND ({$finish} IS NULL OR {$finish} > ? OR {$finish} <= UTC_TIMESTAMP()){$extra} LIMIT 1", $values)->fetchColumn();
    }

    public function close_record(string $kind, int $id, int $actor, string $status): void
    {
        $table = $this->table($kind);
        if ($status === 'cancelled') {
            $this->connection->raw("UPDATE {$table} SET status = 'cancelled', updated_at = UTC_TIMESTAMP() WHERE id = ?", [$id]);
            return;
        }
        $time = $kind === 'facility' ? 'completed_at' : 'actual_completion';
        $this->connection->raw("UPDATE {$table} SET status = ?, completed_by = ?, {$time} = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?", [$status, $actor, $id]);
    }

    public function audit(string $kind, int $id, int $actor, string $action, array $details): void
    {
        $this->connection->raw('INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, new_values_json, created_at)
            VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())', [$actor, $kind . '_maintenance.' . $action, $kind . '_maintenance', $id, json_encode($details, JSON_THROW_ON_ERROR)]);
        if (in_array($action, ['scheduled','completed','cancelled'], TRUE)) {
            $lava = lava_instance(); $lava->call->model('Notification_model');
            $lava->Notification_model->maintenance($kind, $id, $this->find_record($kind, $id), $action);
        }
    }
}
