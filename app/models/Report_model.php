<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Report_model extends Model {
    private $connection;

    public function __construct()
    {
        parent::__construct();
        $lava = lava_instance();
        if (!isset($lava->properties['db'])) $lava->call->database();
        $this->connection = $lava->db;
    }

    public function dashboard_catalog(array $permissions): array
    {
        $totals = [];
        foreach (['users' => 'user.manage', 'departments' => 'department.manage', 'facilities' => 'facility.view', 'assets' => 'asset.view'] as $table => $permission) {
            if (in_array($permission, $permissions, TRUE)) $totals[$table] = (int) $this->connection->raw("SELECT COUNT(*) FROM {$table}")->fetchColumn();
        }
        return $totals;
    }

    public function bookings(string $kind, string $start, string $end, ?int $owner = null, array $filters = []): array
    {
        $schema = lava_instance()->Booking_model->schema($kind);
        $where = "b.{$schema['start']} < ? AND b.{$schema['end']} > ?";
        $values = [$end, $start];
        if ($owner !== null) { $where .= ' AND b.requester_id = ?'; $values[] = $owner; }
        [$extra, $args] = $this->booking_filters($kind, $filters);
        return $this->connection->raw("SELECT b.status, COUNT(*) AS booking_count FROM {$schema['table']} b
            JOIN {$schema['resource']} r ON r.id = b.{$schema['key']}
            WHERE {$where}{$extra} GROUP BY b.status ORDER BY b.status", [...$values, ...$args])->fetchAll(PDO::FETCH_ASSOC);
    }

    public function resources(string $kind, string $start, string $end, array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $s = lava_instance()->Booking_model->schema($kind);
        $name = $kind === 'facility' ? 'facility_name' : 'asset_name';
        [$extra, $args] = $this->booking_filters($kind, $filters);
        // Clip booked duration to the reporting interval; this is not an occupancy percentage.
        $rows = $this->connection->raw("SELECT r.id AS resource_id, r.{$name} AS resource_name,
            COUNT(*) AS booking_count,
            SUM(CASE WHEN b.status IN ('APPROVED','READY_FOR_RELEASE','RELEASED','RETURNED','COMPLETED') THEN
                TIMESTAMPDIFF(SECOND, GREATEST(b.{$s['start']}, ?), LEAST(b.{$s['end']}, ?)) ELSE 0 END) AS reserved_seconds
            FROM {$s['table']} b JOIN {$s['resource']} r ON r.id = b.{$s['key']}
            WHERE b.{$s['start']} < ? AND b.{$s['end']} > ?{$extra} GROUP BY r.id, r.{$name} ORDER BY booking_count DESC, r.id LIMIT {$limit} OFFSET {$offset}",
            [$start, $end, $end, $start, ...$args])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['booking_count'] = (int) $row['booking_count'];
            $row['reserved_seconds'] = (int) $row['reserved_seconds'];
        }
        return $rows;
    }

    private function booking_filters(string $kind, array $filters): array
    {
        $key = lava_instance()->Booking_model->schema($kind)['key'];
        $columns = ['department_id' => 'b.department_id', 'category_id' => 'r.category_id', 'resource_id' => 'b.' . $key,
            'requester_id' => 'b.requester_id', 'status' => 'b.status'];
        $where = ''; $values = [];
        foreach ($filters as $field => $value) {
            if (!isset($columns[$field])) throw new InvalidArgumentException('Unknown report filter.');
            $where .= ' AND ' . $columns[$field] . ' = ?'; $values[] = $value;
        }
        return [$where, $values];
    }

    public function inventory(array $filters, int $limit, int $offset): array
    {
        $columns = ['department_id' => 'a.owning_department_id', 'category_id' => 'a.category_id', 'resource_id' => 'a.id',
            'status' => 'a.status', 'tracking_mode' => 'a.tracking_mode'];
        $where = []; $values = [];
        foreach ($filters as $field => $value) {
            if (!isset($columns[$field])) throw new InvalidArgumentException('Unknown inventory filter.');
            $where[] = $columns[$field] . ' = ?'; $values[] = $value;
        }
        $condition = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        return $this->connection->raw("SELECT a.id AS resource_id, a.asset_code, a.asset_name, a.tracking_mode,
            a.category_id, a.owning_department_id, a.status,
            CASE WHEN a.tracking_mode = 'quantity' THEN a.total_quantity ELSE (SELECT COUNT(*) FROM asset_instances i WHERE i.asset_id = a.id) END AS registered_quantity,
            CASE WHEN a.tracking_mode = 'quantity' THEN a.usable_quantity ELSE (SELECT COUNT(*) FROM asset_instances i WHERE i.asset_id = a.id AND i.status = 'active' AND i.condition_status IN ('good','fair')) END AS usable_quantity
            FROM assets a{$condition} ORDER BY a.id LIMIT {$limit} OFFSET {$offset}", $values)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function overdue(?int $owner, array $filters = []): array
    {
        $where = $owner === null ? '' : ' AND b.requester_id = ?';
        [$extra, $args] = $this->booking_filters('asset', $filters);
        $rows = $this->connection->raw("SELECT b.id, b.booking_reference, b.asset_id, b.requester_id, b.expected_return,
            GREATEST(COALESCE(b.quantity_released, b.quantity_requested) - COALESCE(b.quantity_returned, 0) - b.quantity_lost, 0) AS outstanding_quantity
            FROM asset_bookings b JOIN assets r ON r.id = b.asset_id WHERE b.status = 'RELEASED' AND b.expected_return <= UTC_TIMESTAMP(){$where}{$extra}
            HAVING outstanding_quantity > 0 ORDER BY b.expected_return, b.id LIMIT 100", [...($owner === null ? [] : [$owner]), ...$args])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) $row['outstanding_quantity'] = (int) $row['outstanding_quantity'];
        return $rows;
    }

    public function audit(string $start, string $end, array $filters, int $limit, int $offset): array
    {
        $where = ['created_at >= ?', 'created_at < ?'];
        $values = [$start, $end];
        foreach ($filters as $column => $value) {
            if (!in_array($column, ['actor_user_id', 'entity_type', 'action'], TRUE)) throw new InvalidArgumentException('Unknown audit filter.');
            $where[] = $column . ' = ?'; $values[] = $value;
        }
        $condition = implode(' AND ', $where);
        $total = (int) $this->connection->raw('SELECT COUNT(*) FROM audit_logs WHERE ' . $condition, $values)->fetchColumn();
        $rows = $this->connection->raw("SELECT id, actor_user_id, action, entity_type, entity_id,
            old_values_json, new_values_json, ip_address, user_agent, created_at FROM audit_logs
            WHERE {$condition} ORDER BY created_at DESC, id DESC LIMIT {$limit} OFFSET {$offset}", $values)->fetchAll(PDO::FETCH_ASSOC);
        return ['records' => $rows, 'total' => $total];
    }

    public function record_export(int $actor, string $kind, string $format, int $count, array $filters, ?array $period): void
    {
        $this->connection->raw('INSERT INTO audit_logs (actor_user_id, action, entity_type, new_values_json, created_at)
            VALUES (?, ?, ?, ?, UTC_TIMESTAMP())', [$actor, 'report.exported', 'report', json_encode([
                'report' => $kind, 'format' => $format, 'resource_count' => $count, 'filters' => $filters, 'period_utc' => $period,
            ], JSON_THROW_ON_ERROR)]);
    }
}
