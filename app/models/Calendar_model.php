<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** Read-only projection of existing records; never an availability authority. */
class Calendar_model extends Model {
    private $connection;
    private $lava;
    public function __construct()
    {
        parent::__construct();
        $this->lava = lava_instance();
        if (!isset($this->lava->properties['db'])) $this->lava->call->database();
        $this->connection = $this->lava->db;
        $this->lava->call->model('Booking_model');
    }

    public function events(string $kind, bool $maintenance, array $actor, string $start, string $end, array $filters): array
    {
        $schema = $this->lava->Booking_model->schema($kind);
        $other = $kind === 'facility' ? 'asset' : 'facility';
        if ((isset($filters[$other . '_id']) || isset($filters[$other . '_category_id']))
            && !isset($filters[$kind . '_id']) && !isset($filters[$kind . '_category_id'])) return [];
        if ($maintenance && (isset($filters['status']) || isset($filters['department_id']))) return [];
        $table = $maintenance ? $kind . '_maintenance' : $schema['table'];
        $left = $maintenance ? 'start_datetime' : $schema['start'];
        $right = $maintenance ? ($kind === 'facility' ? 'end_datetime' : 'expected_completion') : $schema['end'];
        $name = $kind . '_name';
        $params = [];
        // Open/overdue maintenance blocks until explicitly closed, matching the
        // existing availability service. Clip that display to the loaded range.
        if ($maintenance) {
            $finish = "CASE WHEN b.{$right} IS NULL OR b.{$right} <= UTC_TIMESTAMP() THEN ? ELSE b.{$right} END";
            $params[] = $end;
            $columns = "b.reason, (b.{$right} IS NULL OR b.{$right} <= UTC_TIMESTAMP()) AS open_ended";
        } else {
            $finish = "b.{$right}";
            $columns = 'b.requester_id, b.department_id, b.booking_reference, b.purpose';
            $columns .= $kind === 'facility' ? ', b.activity_name' : ', b.quantity_requested';
        }
        $where = ["b.{$left} < ?"];
        $params[] = $end;
        if ($maintenance) {
            $where[] = "(b.{$right} IS NULL OR b.{$right} > ? OR b.{$right} <= UTC_TIMESTAMP())";
            $where[] = "b.status IN ('scheduled','in_progress')";
        } else {
            $where[] = "b.{$right} > ?";
        }
        $params[] = $start;
        $can_all = in_array($kind . '_booking.view_all', $actor['permissions'], TRUE);
        $manage = in_array($kind . '.maintenance.manage', $actor['permissions'], TRUE);
        if (!$maintenance && !$can_all) {
            $where[] = "(b.requester_id = ? OR b.status IN ('APPROVED','READY_FOR_RELEASE','RELEASED','RETURNED'))";
            $params[] = (int) $actor['id'];
        }
        foreach ([$kind . '_id' => 'r.id', $kind . '_category_id' => 'r.category_id'] as $filter => $column) {
            if (isset($filters[$filter])) { $where[] = $column . ' = ?'; $params[] = $filters[$filter]; }
        }
        if (!$maintenance) {
            foreach (['status','department_id'] as $filter) {
                if (isset($filters[$filter])) { $where[] = "b.{$filter} = ?"; $params[] = $filters[$filter]; }
            }
        }
        $rows = $this->connection->raw("SELECT b.id, b.status, b.{$schema['key']} AS resource_id,
            r.{$name} AS resource_name, r.status AS resource_status, b.{$left} AS starts, {$finish} AS ends, {$columns}
            FROM {$table} b JOIN {$schema['resource']} r ON r.id = b.{$schema['key']}
            WHERE " . implode(' AND ', $where) . ' ORDER BY starts, b.id LIMIT 1001', $params)->fetchAll(PDO::FETCH_ASSOC);
        $events = [];
        $type = $kind . ($maintenance ? '_maintenance' : '_booking');
        foreach ($rows as $row) {
            $details = $maintenance ? $manage : ($can_all || (int) $row['requester_id'] === (int) $actor['id']);
            $identity = $type . '-' . $row['id'];
            $event = [
                'id' => $details ? $identity : 'busy-' . hash_hmac('sha256', $identity, config_item('jwt_secret')),
                'title' => $row['resource_name'] . ' · ' . ($maintenance ? 'Maintenance' : 'Booking'),
                'type' => $type, 'kind' => $kind, 'resource_id' => (int) $row['resource_id'],
                'resource_name' => $row['resource_name'], 'resource_status' => $row['resource_status'], 'status' => $row['status'],
                'start' => str_replace(' ', 'T', max($start, $row['starts'])) . 'Z',
                'end' => str_replace(' ', 'T', min($end, $row['ends'])) . 'Z',
                'scheduled_start' => str_replace(' ', 'T', $row['starts']) . 'Z',
                'scheduled_end' => $maintenance && (int) $row['open_ended'] === 1 ? null : str_replace(' ', 'T', $row['ends']) . 'Z',
                'open_ended' => $maintenance && (int) $row['open_ended'] === 1,
                'can_view_details' => $details,
            ];
            if ($details) {
                if ($maintenance) {
                    $event['maintenance_id'] = (int) $row['id'];
                    $event['description'] = $row['reason'];
                } else {
                    $event['booking_id'] = (int) $row['id'];
                    $event['booking_reference'] = $row['booking_reference'];
                    $event['department_id'] = (int) $row['department_id'];
                    $event['description'] = $row['purpose'];
                    if ($kind === 'facility') $event['activity_name'] = $row['activity_name'];
                    else $event['quantity_requested'] = (int) $row['quantity_requested'];
                }
            }
            $events[] = $event;
        }
        return $events;
    }
}
