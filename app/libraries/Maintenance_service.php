<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Maintenance_service {
    private $lava;

    public function __construct()
    {
        $this->lava = lava_instance();
        $this->lava->call->library('booking_service');
        $this->lava->call->model('Maintenance_model');
    }

    public function create(string $kind, array $body, int $actor, ?callable $after_create = null): array
    {
        $rules = $this->lava->booking_rules;
        $key = $kind . '_id';
        $finish = $kind === 'facility' ? 'end_datetime' : 'expected_completion';
        $allowed = [$key, 'start_datetime', $finish, 'reason'];
        if ($kind === 'asset') array_push($allowed, 'quantity_affected', 'asset_instance_id');
        if (array_diff(array_keys($body), $allowed)) throw new DomainException('Unsupported maintenance fields.', 422);
        $resource = $rules->positive_id($body[$key] ?? null);
        [$start, $end] = $rules->interval($body['start_datetime'] ?? null, $body[$finish] ?? ($kind === 'asset' ? '9999-12-31T23:59:59Z' : null));
        if (strtotime($end . ' UTC') <= time()) throw new DomainException('Maintenance must end in the future.', 422);
        $reason = $rules->text($body['reason'] ?? null, 4000, 'reason');
        $instance = isset($body['asset_instance_id']) ? $rules->positive_id($body['asset_instance_id']) : null;
        $quantity = $kind === 'facility' ? 1 : $rules->positive_id($body['quantity_affected'] ?? null);
        return $this->atomic($kind, $resource, $actor, function ($record) use ($kind, $key, $finish, $body, $resource, $start, $end, $reason, $instance, $quantity, $actor, $after_create) {
            $model = $this->lava->Maintenance_model;
            if ($kind === 'asset') {
                if ($record['tracking_mode'] === 'individual') {
                    if ($instance === null || $quantity !== 1) throw new DomainException('Individual maintenance requires one specific item and quantity_affected = 1.', 422);
                    $items = $this->lava->call->model('Catalog_model');
                    $item = $items->find_record('asset_instances', $instance, TRUE);
                    if (!$item || (int) $item['asset_id'] !== $resource) throw new DomainException('Item does not belong to this asset.', 422);
                } elseif ($instance !== null || $quantity > (int) $record['usable_quantity']) throw new DomainException('Invalid pool maintenance quantity or item selection.', 422);
            }
            if (($kind === 'facility' || $instance !== null) && $model->overlapping($kind, $resource, $start, $end, $instance)) {
                throw new DomainException('Overlapping maintenance already exists.', 409);
            }
            $fields = [$key => $resource, 'start_datetime' => $start, $finish => isset($body[$finish]) ? $end : ($kind === 'asset' ? null : $end),
                'reason' => $reason, 'created_by' => $actor, 'status' => 'scheduled'];
            if ($kind === 'asset') $fields += ['quantity_affected' => $quantity, 'asset_instance_id' => $instance];
            $id = $model->insert_record($kind, $fields);
            if ($kind === 'asset' && $instance === null && $this->lava->booking_service->asset_reserved_quantity($resource, $start, $end) > (int) $record['usable_quantity']) {
                throw new DomainException('Maintenance would exceed available stock or conflict with committed bookings; resolve them first.', 409);
            }
            if ($kind === 'facility' || $instance !== null) {
                $conflicts = $model->conflicts($kind, $resource, $start, $end, $instance);
                if ($conflicts) throw new DomainException('Resolve committed booking conflicts before scheduling maintenance: ' . implode(', ', array_column($conflicts, 'id')), 409);
            }
            $model->audit($kind, $id, $actor, 'scheduled', $fields);
            // Pending requests remain pending; approval always rechecks maintenance.
            $pending = $this->lava->Booking_model->overlapping_pending($kind, $resource, $start, $end);
            foreach ($pending as $booking) {
                if ($instance !== null && !in_array($instance, $this->lava->Booking_model->selected_instances((int) $booking['id']), TRUE)) continue;
                $this->lava->Booking_model->notify($kind, $booking, 'maintenance', $booking['booking_reference'] . ': maintenance affects the requested interval; availability will be rechecked during review.');
            }
            $result = $model->find_record($kind, $id);
            if ($after_create !== null) $after_create($result);
            return $result;
        });
    }

    public function close(string $kind, int $id, int $actor, string $status, ?callable $before_close = null): array
    {
        $model = $this->lava->Maintenance_model;
        $initial = $model->find_record($kind, $id);
        if (!$initial) throw new DomainException('Maintenance not found.', 404);
        return $this->atomic($kind, (int) $initial[$kind . '_id'], $actor, function () use ($model, $kind, $id, $actor, $status, $before_close) {
            $record = $model->find_record($kind, $id, TRUE);
            if ($record['status'] === $status) return $record;
            if (!in_array($record['status'], ['scheduled','in_progress'], TRUE)) throw new DomainException('Maintenance is already closed.', 409);
            if ($status === 'completed' && strtotime($record['start_datetime'] . ' UTC') > time()) throw new DomainException('Future maintenance cannot be marked complete; cancel it instead.', 409);
            if ($before_close !== null) $before_close();
            if ($kind === 'asset') {
                $this->lava->call->library('problem_report_service');
                $this->lava->problem_report_service->maintenance_close($id, $actor, $status);
            }
            $model->close_record($kind, $id, $actor, $status);
            $model->audit($kind, $id, $actor, $status, ['previous_status' => $record['status']]);
            return $model->find_record($kind, $id);
        });
    }

    private function atomic(string $kind, int $resource, int $actor, callable $action): array
    {
        $this->lava->db->raw('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $this->lava->db->transaction();
        try {
            $record = $this->lava->Booking_model->resource($kind, $resource, TRUE);
            if (!$record) throw new DomainException('Resource not found.', 404);
            if (!in_array($kind . '.maintenance.manage', $this->lava->Account_model->permissions($actor), TRUE)) throw new DomainException('Forbidden', 403);
            $result = $action($record);
            $this->lava->db->commit();
            return $result;
        } catch (Throwable $error) { $this->lava->db->roll_back(); throw $error; }
    }
}
