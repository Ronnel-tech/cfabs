<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Booking_model extends Model {
    private $connection;
    private const SCHEMAS = [
        'facility' => ['resource' => 'facilities', 'table' => 'facility_bookings', 'key' => 'facility_id', 'start' => 'start_datetime', 'end' => 'end_datetime'],
        'asset' => ['resource' => 'assets', 'table' => 'asset_bookings', 'key' => 'asset_id', 'start' => 'usage_start', 'end' => 'expected_return'],
    ];

    public function __construct()
    {
        parent::__construct();
        $lava = lava_instance();
        if (!isset($lava->properties['db'])) $lava->call->database();
        $this->connection = $lava->db;
    }

    public function schema(string $kind): array
    {
        if (!isset(self::SCHEMAS[$kind])) throw new InvalidArgumentException('Unknown resource kind.');
        return self::SCHEMAS[$kind];
    }

    public function resource(string $kind, int $id, bool $lock = FALSE): ?array
    {
        $table = $this->schema($kind)['resource'];
        return $this->connection->raw("SELECT * FROM {$table} WHERE id = ?" . ($lock ? ' FOR UPDATE' : ''), [$id])->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function resources(string $kind, int $limit, int $offset): array
    {
        $table = $this->schema($kind)['resource'];
        return $this->connection->raw("SELECT * FROM {$table} WHERE status = 'active' ORDER BY id LIMIT {$limit} OFFSET {$offset}")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function booking(string $kind, int $id, bool $lock = FALSE): ?array
    {
        $table = $this->schema($kind)['table'];
        return $this->connection->raw("SELECT * FROM {$table} WHERE id = ?" . ($lock ? ' FOR UPDATE' : ''), [$id])->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function bookings(string $kind, int $user, bool $all, int $limit, int $offset): array
    {
        $table = $this->schema($kind)['table'];
        $where = $all ? '' : ' WHERE requester_id = ?';
        return $this->connection->raw("SELECT * FROM {$table}{$where} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}", $all ? [] : [$user])->fetchAll(PDO::FETCH_ASSOC);
    }

    public function blocks(string $kind, int $id, string $start, string $end): array
    {
        if ($kind === 'facility') {
            return $this->connection->raw("SELECT start_datetime AS `start`, end_datetime AS `end`, 1 AS quantity
                FROM facility_bookings WHERE facility_id = ? AND status = 'APPROVED' AND start_datetime < ? AND end_datetime > ?
                UNION ALL SELECT start_datetime, CASE WHEN end_datetime <= UTC_TIMESTAMP() THEN '9999-12-31 23:59:59' ELSE end_datetime END, 1 FROM facility_maintenance
                WHERE facility_id = ? AND status IN ('scheduled','in_progress') AND start_datetime < ? AND (end_datetime > ? OR end_datetime <= UTC_TIMESTAMP())",
                [$id, $end, $start, $id, $end, $start])->fetchAll(PDO::FETCH_ASSOC);
        }
        $model = lava_instance()->call->model('Handover_model');
        return $model->quantity_blocks($id, $start, $end);
    }

    public function available_instances(int $asset, string $start, string $end): array
    {
        $model = lava_instance()->call->model('Handover_model');
        return $model->available_instances($asset, $start, $end);
    }

    public function selected_instances(int $booking): array
    {
        return array_map('intval', $this->connection->raw('SELECT asset_instance_id FROM asset_booking_instances
            WHERE asset_booking_id = ? ORDER BY asset_instance_id', [$booking])->fetchAll(PDO::FETCH_COLUMN));
    }

    public function selected_instance_details(int $booking): array
    {
        return $this->connection->raw('SELECT i.id, i.asset_tag, i.serial_number FROM asset_booking_instances x
            JOIN asset_instances i ON i.id = x.asset_instance_id
            WHERE x.asset_booking_id = ? ORDER BY i.id', [$booking])->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create_booking(string $kind, array $data, array $instances): int
    {
        $table = $this->schema($kind)['table'];
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $this->connection->raw("INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})", array_values($data));
        $id = (int) $this->connection->raw('SELECT LAST_INSERT_ID()')->fetchColumn();
        foreach ($instances as $instance) {
            $this->connection->raw('INSERT INTO asset_booking_instances (asset_booking_id, asset_instance_id) VALUES (?, ?)', [$id, $instance]);
        }
        return $id;
    }

    public function approve(string $kind, int $id, int $actor): void
    {
        $table = $this->schema($kind)['table'];
        $this->connection->raw("UPDATE {$table} SET status = 'APPROVED', approver_id = ?, approved_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?", [$actor, $id]);
    }

    public function reject(string $kind, int $id, string $reason, string $source): void
    {
        $table = $this->schema($kind)['table'];
        $this->connection->raw("UPDATE {$table} SET status = 'REJECTED', rejection_reason = ?, rejection_source = ?, rejected_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?", [$reason, $source, $id]);
    }

    public function cancel(string $kind, int $id, string $reason): void
    {
        $table = $this->schema($kind)['table'];
        $this->connection->raw("UPDATE {$table} SET status = 'CANCELLED', cancellation_reason = ?, cancelled_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?", [$reason, $id]);
    }

    public function complete_facility(int $id): void
    {
        $this->connection->raw("UPDATE facility_bookings SET status = 'COMPLETED', completed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?", [$id]);
    }

    public function overlapping_pending(string $kind, int $resource, string $start, string $end): array
    {
        $s = $this->schema($kind);
        return $this->connection->raw("SELECT * FROM {$s['table']} WHERE {$s['key']} = ? AND status = 'PENDING'
            AND {$s['start']} < ? AND {$s['end']} > ? ORDER BY id FOR UPDATE", [$resource, $end, $start])->fetchAll(PDO::FETCH_ASSOC);
    }

    public function notify(string $kind, array $booking, string $event, string $message): void
    {
        $this->connection->raw('INSERT INTO notifications
            (recipient_id, title, message, notification_type, related_entity_type, related_entity_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())', [$booking['requester_id'], 'Booking ' . $event, $message,
                $kind . '_booking.' . $event, $kind . '_booking', $booking['id']]);
    }

    public function audit(string $kind, int $id, ?int $actor, string $action, array $details = []): void
    {
        $this->connection->raw('INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, new_values_json, created_at)
            VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())', [$actor, $kind . '_booking.' . $action, $kind . '_booking', $id, json_encode($details, JSON_THROW_ON_ERROR)]);
    }

    public function notifications(int $user, int $limit, int $offset): array
    {
        return $this->connection->raw("SELECT id, recipient_id, title, message, notification_type, related_entity_type,
            related_entity_id, is_read, read_at, created_at, updated_at FROM notifications WHERE recipient_id = ? ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}", [$user])->fetchAll(PDO::FETCH_ASSOC);
    }

    public function read_notification(int $user, int $id): bool
    {
        $found = $this->connection->raw('SELECT id FROM notifications WHERE id = ? AND recipient_id = ?', [$id, $user])->fetchColumn();
        if (!$found) return FALSE;
        $this->connection->raw('UPDATE notifications SET is_read = 1, read_at = COALESCE(read_at, UTC_TIMESTAMP()), updated_at = UTC_TIMESTAMP() WHERE id = ? AND recipient_id = ?', [$id, $user]);
        return TRUE;
    }
}

