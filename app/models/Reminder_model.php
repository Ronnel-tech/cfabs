<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Reminder_model extends Model {
    private $connection;
    public function __construct()
    {
        parent::__construct();
        $lava = lava_instance();
        if (!isset($lava->properties['db'])) $lava->call->database();
        $this->connection = $lava->db;
    }
    public function candidates(int $hours): iterable
    {
        $horizon = gmdate('Y-m-d H:i:s', time() + $hours * 3600);
        foreach (['facility','asset'] as $kind) {
            $table = $kind . '_bookings';
            $where = $kind === 'facility'
                ? "status = 'APPROVED' AND start_datetime > UTC_TIMESTAMP() AND start_datetime <= ?"
                : "((status IN ('APPROVED','READY_FOR_RELEASE') AND usage_start > UTC_TIMESTAMP() AND usage_start <= ?)
                    OR (status = 'RELEASED' AND COALESCE(quantity_released, quantity_requested) - COALESCE(quantity_returned, 0) - quantity_lost > 0 AND expected_return <= ?))";
            $statement = $this->connection->raw("SELECT id FROM {$table} WHERE {$where} ORDER BY id", $kind === 'facility' ? [$horizon] : [$horizon, $horizon]);
            while ($id = $statement->fetchColumn()) yield ['kind' => $kind, 'id' => (int) $id, 'maintenance' => FALSE];
            $finish = $kind === 'facility' ? 'end_datetime' : 'expected_completion';
            $statement = $this->connection->raw("SELECT id FROM {$kind}_maintenance WHERE status IN ('scheduled','in_progress') AND {$finish} <= UTC_TIMESTAMP() ORDER BY id");
            while ($id = $statement->fetchColumn()) yield ['kind' => $kind, 'id' => (int) $id, 'maintenance' => TRUE];
        }
    }
    public function record(array $source, bool $lock = FALSE): ?array
    {
        $table = $source['kind'] . ($source['maintenance'] ? '_maintenance' : '_bookings');
        return $this->connection->raw("SELECT * FROM {$table} WHERE id = ?" . ($lock ? ' FOR UPDATE' : ''), [$source['id']])->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    public function targets(array $source, array $record, string $event): array
    {
        $owner = $source['maintenance'] ? 0 : (int) $record['requester_id'];
        $permission = $source['maintenance'] ? $source['kind'] . '.maintenance.manage' : ($event === 'asset_overdue' ? 'asset_booking.return' : null);
        if ($permission === null) return array_map('intval', $this->connection->raw('SELECT id FROM users WHERE id = ? AND is_active = 1', [$owner])->fetchAll(PDO::FETCH_COLUMN));
        return array_map('intval', $this->connection->raw('SELECT DISTINCT u.id FROM users u
            LEFT JOIN role_permissions rp ON rp.role_id = u.role_id LEFT JOIN permissions p ON p.id = rp.permission_id
            WHERE u.is_active = 1 AND (u.id = ? OR p.permission_key = ?) ORDER BY u.id', [$owner, $permission])->fetchAll(PDO::FETCH_COLUMN));
    }
    public function exists(string $key): bool
    {
        return (bool) $this->connection->raw('SELECT id FROM notifications WHERE dispatch_key = ?', [$key])->fetchColumn();
    }
    public function notify(int $recipient, string $event, string $entity, int $id, string $due, string $key, string $label): bool
    {
        $titles = ['facility_upcoming' => 'Upcoming facility booking', 'asset_upcoming' => 'Upcoming asset booking',
            'asset_return_due' => 'Asset return due', 'asset_overdue' => 'Overdue asset return',
            'facility_maintenance_overdue' => 'Facility maintenance overdue', 'asset_maintenance_overdue' => 'Asset maintenance overdue'];
        $title = $titles[$event];
        $local = (new DateTimeImmutable($due, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'))->format('M j, Y g:i A');
        return $this->connection->raw('INSERT INTO notifications
            (recipient_id, title, message, notification_type, related_entity_type, related_entity_id, dispatch_key, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE id = id', [$recipient,
                $title, $label . ': ' . $title . '. Scheduled time: ' . $local . ' (Asia/Manila).',
                $event, $entity, $id, $key])->rowCount() === 1;
    }
    public function acquire_lock(): bool
    {
        return (int) $this->connection->raw("SELECT GET_LOCK(CONCAT('cfabs_reminders_', LEFT(SHA2(DATABASE(), 256), 40)), 0)")->fetchColumn() === 1;
    }
    public function release_lock(): void
    {
        $this->connection->raw("SELECT RELEASE_LOCK(CONCAT('cfabs_reminders_', LEFT(SHA2(DATABASE(), 256), 40)))");
    }
}
