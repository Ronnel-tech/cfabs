<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Handover_model extends Model {
    private $connection;

    public function __construct()
    {
        parent::__construct();
        $lava = lava_instance();
        if (!isset($lava->properties['db'])) $lava->call->database();
        $this->connection = $lava->db;
    }

    public function find_handover(int $id): ?array
    {
        return $this->connection->raw('SELECT * FROM asset_handovers WHERE id = ?', [$id])->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function by_key(int $booking, string $key): ?array
    {
        return $this->connection->raw('SELECT * FROM asset_handovers WHERE asset_booking_id = ? AND idempotency_key = ?', [$booking, $key])->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function items(int $handover): array
    {
        return array_map('intval', $this->connection->raw('SELECT asset_instance_id FROM asset_handover_items WHERE handover_id = ? ORDER BY asset_instance_id', [$handover])->fetchAll(PDO::FETCH_COLUMN));
    }

    public function event_items(int $booking, array $directions): array
    {
        if (array_diff($directions, ['release', 'return', 'loss', 'inspection']) || !$directions) throw new InvalidArgumentException('Invalid handover directions.');
        $marks = implode(',', array_fill(0, count($directions), '?'));
        return array_map('intval', $this->connection->raw("SELECT DISTINCT i.asset_instance_id FROM asset_handover_items i
            JOIN asset_handovers h ON h.id = i.handover_id WHERE h.asset_booking_id = ? AND h.direction IN ({$marks})", [$booking, ...$directions])->fetchAll(PDO::FETCH_COLUMN));
    }

    public function inspected_items(int $source): array
    {
        return array_map('intval', $this->connection->raw("SELECT i.asset_instance_id FROM asset_handover_items i JOIN asset_handovers h ON h.id = i.handover_id
            WHERE h.source_handover_id = ? AND h.direction = 'inspection'", [$source])->fetchAll(PDO::FETCH_COLUMN));
    }

    public function inspected_quantity(int $source): int
    {
        return (int) $this->connection->raw("SELECT COALESCE(SUM(quantity), 0) FROM asset_handovers WHERE source_handover_id = ? AND direction = 'inspection'", [$source])->fetchColumn();
    }

    public function open_inspections(int $booking): array
    {
        $rows = $this->connection->raw("SELECT r.id, r.created_at, r.remarks, r.quantity - COALESCE(SUM(i.quantity), 0) AS remaining_quantity
            FROM asset_handovers r LEFT JOIN asset_handovers i ON i.source_handover_id = r.id AND i.direction = 'inspection'
            WHERE r.asset_booking_id = ? AND r.direction = 'return' AND r.condition_status = 'inspection'
            GROUP BY r.id, r.created_at, r.remarks, r.quantity HAVING remaining_quantity > 0 ORDER BY r.id", [$booking])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) $row['instance_ids'] = array_values(array_diff($this->items((int) $row['id']), $this->inspected_items((int) $row['id'])));
        return $rows;
    }

    public function totals(int $booking): array
    {
        $row = $this->connection->raw("SELECT
            COALESCE(SUM(CASE WHEN direction = 'release' THEN quantity ELSE 0 END), 0) AS released,
            COALESCE(SUM(CASE WHEN direction = 'return' THEN quantity ELSE 0 END), 0) AS returned,
            COALESCE(SUM(CASE WHEN direction = 'loss' THEN quantity ELSE 0 END), 0) AS lost,
            COALESCE(SUM(CASE WHEN direction = 'return' AND condition_status = 'inspection' THEN quantity
                WHEN direction = 'inspection' THEN -quantity ELSE 0 END), 0) AS inspection
            FROM asset_handovers WHERE asset_booking_id = ?", [$booking])->fetch(PDO::FETCH_ASSOC);
        return array_map('intval', $row);
    }

    public function insert_event(array $data, array $items): array
    {
        $this->connection->raw('INSERT INTO asset_handovers (asset_booking_id, actor_id, direction, quantity, condition_status, remarks,
            source_handover_id, idempotency_key, request_hash, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())', [
                $data['asset_booking_id'], $data['actor_id'], $data['direction'], $data['quantity'], $data['condition_status'],
                $data['remarks'], $data['source_handover_id'], $data['idempotency_key'], $data['request_hash']]);
        $id = (int) $this->connection->last_id();
        if ($items) {
            $values = [];
            foreach ($items as $item) { $values[] = $id; $values[] = $item; }
            $this->connection->raw('INSERT INTO asset_handover_items (handover_id, asset_instance_id) VALUES ' . implode(',', array_fill(0, count($items), '(?, ?)')), $values);
        }
        return $this->find_handover($id);
    }

    public function update_summary(int $booking, array $totals, string $status, int $actor, array $event): void
    {
        $direction = $event['direction'];
        $sets = '';
        $values = [$totals['released'], $totals['returned'], $totals['lost'], $status];
        if ($direction === 'release') {
            $sets = ', released_by = ?, released_at = COALESCE(released_at, UTC_TIMESTAMP()), release_condition = ?';
            array_push($values, $actor, $event['condition_status']);
        } elseif ($direction === 'return') {
            $sets = ', received_by = ?, returned_at = UTC_TIMESTAMP(), return_condition = ?, return_remarks = ?';
            array_push($values, $actor, $event['condition_status'], $event['remarks']);
        }
        if ($status === 'COMPLETED') $sets .= ', completed_at = UTC_TIMESTAMP()';
        $values[] = $booking;
        $this->connection->raw("UPDATE asset_bookings SET quantity_released = ?, quantity_returned = ?, quantity_lost = ?, status = ?, updated_at = UTC_TIMESTAMP(){$sets} WHERE id = ?", $values);
    }

    public function complete_booking(int $id): void
    {
        $this->connection->raw("UPDATE asset_bookings SET status = 'COMPLETED', completed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?", [$id]);
    }

    public function reduce_usable(int $asset, int $quantity): void
    {
        $this->connection->raw('UPDATE assets SET usable_quantity = usable_quantity - ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$quantity, $asset]);
    }

    public function condition_items(int $asset, array $items, string $condition): void
    {
        if (!$items) return;
        $marks = implode(',', array_fill(0, count($items), '?'));
        $status = in_array($condition, ['good', 'fair'], TRUE) ? 'active' : 'inactive';
        $this->connection->raw("UPDATE asset_instances SET condition_status = ?, status = ?, updated_at = UTC_TIMESTAMP()
            WHERE asset_id = ? AND id IN ({$marks})", [$condition, $status, $asset, ...$items]);
    }

    public function physical_free(int $asset, int $usable): int
    {
        $outstanding = (int) $this->connection->raw("SELECT COALESCE(SUM(GREATEST(COALESCE(quantity_released, CASE WHEN status = 'RELEASED' THEN quantity_requested ELSE 0 END) - COALESCE(quantity_returned, 0) - quantity_lost, 0)), 0)
            FROM asset_bookings WHERE asset_id = ?", [$asset])->fetchColumn();
        $inspection = (int) $this->connection->raw("SELECT
            COALESCE(SUM(CASE WHEN h.direction = 'return' AND h.condition_status = 'inspection' THEN h.quantity WHEN h.direction = 'inspection' THEN -h.quantity ELSE 0 END), 0)
            FROM asset_handovers h JOIN asset_bookings b ON b.id = h.asset_booking_id WHERE b.asset_id = ?", [$asset])->fetchColumn();
        $maintenance = (int) $this->connection->raw("SELECT COALESCE(SUM(quantity_affected), 0) FROM asset_maintenance WHERE asset_id = ?
            AND status IN ('scheduled','in_progress') AND start_datetime <= UTC_TIMESTAMP()", [$asset])->fetchColumn();
        $legacy_hold = (int) $this->connection->raw("SELECT COALESCE(SUM(COALESCE(b.quantity_returned, b.quantity_released, b.quantity_requested)), 0)
            FROM asset_bookings b WHERE b.asset_id = ? AND b.status = 'RETURNED'
            AND NOT EXISTS (SELECT 1 FROM asset_handovers h WHERE h.asset_booking_id = b.id)", [$asset])->fetchColumn();
        return max(0, $usable - $outstanding - $inspection - $maintenance - $legacy_hold);
    }

    public function quantity_blocks(int $asset, string $start, string $end): array
    {
        return $this->connection->raw("SELECT b.usage_start AS `start`,
            CASE WHEN (b.status = 'RETURNED' AND NOT EXISTS (SELECT 1 FROM asset_handovers h WHERE h.asset_booking_id = b.id))
                OR (b.status = 'RELEASED' AND b.expected_return <= UTC_TIMESTAMP()) THEN '9999-12-31 23:59:59' ELSE b.expected_return END AS `end`,
            CASE WHEN b.status = 'RETURNED' AND NOT EXISTS (SELECT 1 FROM asset_handovers h WHERE h.asset_booking_id = b.id)
                THEN COALESCE(b.quantity_returned, b.quantity_released, b.quantity_requested)
                WHEN b.status = 'RELEASED' AND b.expected_return <= UTC_TIMESTAMP()
                THEN GREATEST(COALESCE(b.quantity_released, b.quantity_requested) - COALESCE(b.quantity_returned, 0) - b.quantity_lost, 0)
                ELSE GREATEST(b.quantity_requested - COALESCE(b.quantity_returned, 0) - b.quantity_lost, 0) END AS quantity
            FROM asset_bookings b WHERE b.asset_id = ? AND b.status IN ('APPROVED','READY_FOR_RELEASE','RELEASED','RETURNED')
            AND b.usage_start < ? AND (b.expected_return > ? OR b.status = 'RETURNED' OR (b.status = 'RELEASED' AND b.expected_return <= UTC_TIMESTAMP()))
            UNION ALL SELECT start_datetime, CASE WHEN expected_completion <= UTC_TIMESTAMP() THEN '9999-12-31 23:59:59' ELSE COALESCE(expected_completion, '9999-12-31 23:59:59') END, quantity_affected
            FROM asset_maintenance WHERE asset_id = ? AND status IN ('scheduled','in_progress')
            AND start_datetime < ? AND (expected_completion IS NULL OR expected_completion > ? OR expected_completion <= UTC_TIMESTAMP())
            UNION ALL SELECT r.created_at, '9999-12-31 23:59:59', r.quantity - COALESCE(SUM(i.quantity), 0)
            FROM asset_handovers r JOIN asset_bookings b ON b.id = r.asset_booking_id
            LEFT JOIN asset_handovers i ON i.source_handover_id = r.id AND i.direction = 'inspection'
            WHERE b.asset_id = ? AND r.direction = 'return' AND r.condition_status = 'inspection' AND r.created_at < ?
            GROUP BY r.id, r.created_at, r.quantity HAVING r.quantity - COALESCE(SUM(i.quantity), 0) > 0", [$asset, $end, $start, $asset, $end, $start, $asset, $end])->fetchAll(PDO::FETCH_ASSOC);
    }

    public function available_instances(int $asset, string $start, string $end, int $exclude_booking = 0): array
    {
        return $this->connection->raw("SELECT i.id, i.asset_tag, i.serial_number FROM asset_instances i
            WHERE i.asset_id = ? AND i.status = 'active' AND i.condition_status IN ('good','fair')
            AND NOT EXISTS (SELECT 1 FROM asset_booking_instances x JOIN asset_bookings b ON b.id = x.asset_booking_id
                WHERE x.asset_instance_id = i.id AND b.id <> ? AND b.status IN ('APPROVED','READY_FOR_RELEASE','RELEASED','RETURNED')
                AND NOT EXISTS (SELECT 1 FROM asset_handovers t JOIN asset_handover_items ti ON ti.handover_id = t.id
                    WHERE t.asset_booking_id = b.id AND ti.asset_instance_id = i.id AND t.direction IN ('return','loss'))
                AND b.usage_start < ? AND (b.expected_return > ? OR b.status = 'RETURNED'
                    OR (b.status = 'RELEASED' AND b.expected_return <= UTC_TIMESTAMP() AND (
                        NOT EXISTS (SELECT 1 FROM asset_handovers h WHERE h.asset_booking_id = b.id)
                        OR EXISTS (SELECT 1 FROM asset_handovers h JOIN asset_handover_items hi ON hi.handover_id = h.id
                            WHERE h.asset_booking_id = b.id AND h.direction = 'release' AND hi.asset_instance_id = i.id)))))
            AND NOT EXISTS (SELECT 1 FROM asset_handovers r JOIN asset_handover_items ri ON ri.handover_id = r.id
                WHERE ri.asset_instance_id = i.id AND r.direction = 'return' AND r.condition_status = 'inspection'
                AND NOT EXISTS (SELECT 1 FROM asset_handovers s JOIN asset_handover_items si ON si.handover_id = s.id
                    WHERE s.source_handover_id = r.id AND s.direction = 'inspection' AND si.asset_instance_id = i.id))
            AND NOT EXISTS (SELECT 1 FROM asset_maintenance m WHERE m.asset_id = i.asset_id
                AND (m.asset_instance_id = i.id OR m.asset_instance_id IS NULL) AND m.status IN ('scheduled','in_progress')
                AND m.start_datetime < ? AND (m.expected_completion IS NULL OR m.expected_completion > ? OR m.expected_completion <= UTC_TIMESTAMP()))
            ORDER BY i.id", [$asset, $exclude_booking, $end, $start, $end, $start])->fetchAll(PDO::FETCH_ASSOC);
    }

    public function history(int $booking, int $limit, int $offset): array
    {
        // Internal idempotency hashes are not part of the API response.
        return $this->connection->raw("SELECT id, asset_booking_id, actor_id, direction, quantity, condition_status, remarks,
            source_handover_id, created_at FROM asset_handovers WHERE asset_booking_id = ? ORDER BY id LIMIT {$limit} OFFSET {$offset}", [$booking])->fetchAll(PDO::FETCH_ASSOC);
    }

    public function future_reservations(int $asset): array
    {
        return $this->connection->raw("SELECT * FROM asset_bookings WHERE asset_id = ?
            AND status IN ('APPROVED','READY_FOR_RELEASE') AND expected_return > UTC_TIMESTAMP()", [$asset])->fetchAll(PDO::FETCH_ASSOC);
    }
}
