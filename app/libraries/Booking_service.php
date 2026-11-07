<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Booking_service {
    private $lava;
    private int $transaction_depth = 0;

    public function __construct()
    {
        $this->lava = lava_instance();
        $this->lava->call->model('Booking_model');
        $this->lava->call->library(['booking_rules', 'booking_availability', 'facility_hours']);
    }

    public function availability(string $kind, int $id, string $start, string $end, ?array $resource = null): array
    {
        $resource = $resource ?? $this->lava->Booking_model->resource($kind, $id);
        if (!$resource) throw new DomainException('Resource not found.', 404);
        $instances = [];
        $intervals = [];
        if ($resource['status'] !== 'active') {
            $quantity = 0;
        } elseif ($kind === 'asset' && $resource['tracking_mode'] === 'individual') {
            $instances = $this->lava->Booking_model->available_instances($id, $start, $end);
            $quantity = count($instances);
        } else {
            $blocks = [];
            $utc = new DateTimeZone('UTC');
            foreach ($this->lava->Booking_model->blocks($kind, $id, $start, $end) as $row) {
                if ((int) $row['quantity'] === 0) continue;
                $blocks[] = ['start' => (new DateTimeImmutable($row['start'], $utc))->getTimestamp(),
                    'end' => (new DateTimeImmutable($row['end'], $utc))->getTimestamp(), 'quantity' => (int) $row['quantity']];
                if ($kind === 'facility') $intervals[] = ['start_utc' => $row['start'], 'end_utc' => $row['end']];
            }
            $quantity = $this->lava->booking_availability->available_quantity(
                $kind === 'facility' ? 1 : (int) $resource['usable_quantity'],
                (new DateTimeImmutable($start, $utc))->getTimestamp(), (new DateTimeImmutable($end, $utc))->getTimestamp(), $blocks);
        }
        $hours = [];
        if ($kind === 'facility') {
            $within = TRUE;
            try { $this->lava->facility_hours->validate($start, $end); }
            catch (DomainException $outside) { $within = FALSE; $quantity = 0; }
            $hours = ['operating_hours' => $this->lava->facility_hours->schedule(), 'within_operating_hours' => $within];
            $hours['requires_schedule_review'] = $resource['operating_hours_json'] !== null;
            if ($hours['requires_schedule_review']) $quantity = 0;
        }
        $advance = $this->lava->booking_rules->advance_window($start);
        if (!$advance['within_limit']) $quantity = 0;
        return ['resource_id' => $id, 'start_utc' => $start, 'end_utc' => $end, 'advance_booking_window' => $advance,
            'available' => $quantity > 0, 'available_quantity' => $quantity, 'available_instances' => $instances,
            'unavailable_intervals' => $intervals] + $hours;
    }

    public function create(string $kind, array $actor, array $body, ?int $series_id = null): array
    {
        if (array_key_exists('recurrence', $body) || array_key_exists('series_id', $body)) throw new DomainException('Use the booking-series endpoint for recurring requests.', 422);
        $rules = $this->lava->booking_rules;
        $s = $this->lava->Booking_model->schema($kind);
        $id = $rules->positive_id($body[$s['key']] ?? null);
        [$start, $end] = $rules->interval($body[$s['start']] ?? null, $body[$s['end']] ?? null, TRUE);
        $rules->require_advance_window($start);
        $purpose = $rules->text($body['purpose'] ?? null, 4000, 'purpose');
        $instances = [];
        if ($kind === 'facility') {
            $extra = ['activity_name' => $rules->text($body['activity_name'] ?? null, 180, 'activity_name'),
                'participants_count' => $rules->positive_id($body['participants_count'] ?? null)];
        } else {
            $extra = ['quantity_requested' => $rules->positive_id($body['quantity_requested'] ?? null)];
            if (isset($body['instance_ids'])) {
                if (!is_array($body['instance_ids']) || !array_is_list($body['instance_ids']) || count($body['instance_ids']) > 1000) {
                    throw new DomainException('instance_ids must be a list of at most 1000 IDs.', 422);
                }
                $instances = array_map([$rules, 'positive_id'], $body['instance_ids']);
                if (count(array_unique($instances)) !== count($instances)) throw new DomainException('Duplicate item selections are not allowed.', 422);
            }
        }
        return $this->atomic(function () use ($kind, $actor, $s, $id, $start, $end, $purpose, $extra, $instances, $series_id) {
            $resource = $this->lava->Booking_model->resource($kind, $id, TRUE);
            if (!$resource || $resource['status'] !== 'active') throw new DomainException('Resource is not bookable.', 422);
            if (!$actor['department_id'] || !$this->lava->Account_model->active_department_exists((int) $actor['department_id'])) {
                throw new DomainException('An active department must be assigned to your account.', 422);
            }
            if ($kind === 'facility') {
                $this->lava->facility_hours->validate($start, $end);
                if ($extra['participants_count'] > (int) $resource['capacity']) throw new DomainException('Participant count exceeds facility capacity.', 422);
                // Custom per-facility schedules remain unsupported; global hours apply.
                if ($resource['operating_hours_json'] !== null) throw new DomainException('This facility has a schedule requiring coordinator review.', 409);
            } elseif ($resource['tracking_mode'] === 'individual') {
                if (count($instances) !== $extra['quantity_requested']) throw new DomainException('Select one specific item for every requested unit.', 422);
            } elseif ($instances) {
                throw new DomainException('Quantity-tracked assets do not accept item selections.', 422);
            }
            $snapshot = $this->availability($kind, $id, $start, $end, $resource);
            $requested = $kind === 'facility' ? 1 : $extra['quantity_requested'];
            if (!$this->fits($snapshot, $requested, $instances)) throw new DomainException('The resource is unavailable for the selected interval.', 409);
            $data = [$s['key'] => $id, $s['start'] => $start, $s['end'] => $end,
                'booking_reference' => ($kind === 'facility' ? 'FAC-' : 'AST-') . strtoupper(bin2hex(random_bytes(8))),
                'requester_id' => (int) $actor['id'], 'department_id' => (int) $actor['department_id'],
                'purpose' => $purpose, 'status' => 'PENDING'] + $extra;
            if ($series_id !== null) $data['series_id'] = $series_id;
            $booking_id = $this->lava->Booking_model->create_booking($kind, $data, $instances);
            $booking = $this->lava->Booking_model->booking($kind, $booking_id);
            $this->lava->Booking_model->notify($kind, $booking, 'submitted', $booking['booking_reference'] . ' is awaiting review.');
            $this->lava->Booking_model->audit($kind, $booking_id, (int) $actor['id'], 'submitted');
            return $booking;
        });
    }

    public function approve(string $kind, int $id, array $actor): array
    {
        return $this->atomic(function () use ($kind, $id, $actor) {
            [$s, $resource, $booking] = $this->locked_booking($kind, $id);
            if ($booking['status'] !== 'PENDING') throw new DomainException('Only pending requests can be approved.', 409);
            $this->lava->booking_rules->require_advance_window($booking[$s['start']]);
            if ((int) $booking['requester_id'] === (int) $actor['id']) throw new DomainException('You cannot approve your own request.', 403);
            if ($resource['status'] !== 'active' || strtotime($booking[$s['start']] . ' UTC') <= time()) throw new DomainException('Resource or booking is no longer eligible.', 409);
            if ($kind === 'facility' && ($booking['participants_count'] > $resource['capacity'] || $resource['operating_hours_json'] !== null)) {
                throw new DomainException('Facility capacity or schedule no longer permits this booking.', 409);
            }
            if ($kind === 'facility') $this->lava->facility_hours->validate($booking[$s['start']], $booking[$s['end']]);
            $selected = $kind === 'asset' ? $this->lava->Booking_model->selected_instances($id) : [];
            $snapshot = $this->availability($kind, (int) $resource['id'], $booking[$s['start']], $booking[$s['end']], $resource);
            $requested = $kind === 'facility' ? 1 : (int) $booking['quantity_requested'];
            if (!$this->fits($snapshot, $requested, $selected)
                || ($kind === 'asset' && $resource['tracking_mode'] === 'individual' && count($selected) !== $requested)) {
                throw new DomainException('The resource is no longer available.', 409);
            }
            $this->lava->Booking_model->approve($kind, $id, (int) $actor['id']);
            $this->lava->Booking_model->notify($kind, $booking, 'approved', $booking['booking_reference'] . ' has been approved.');
            $this->lava->Booking_model->audit($kind, $id, (int) $actor['id'], 'approved');
            $rejected = [];
            foreach ($this->lava->Booking_model->overlapping_pending($kind, (int) $resource['id'], $booking[$s['start']], $booking[$s['end']]) as $pending) {
                $snapshot = $this->availability($kind, (int) $resource['id'], $pending[$s['start']], $pending[$s['end']], $resource);
                $selected = $kind === 'asset' ? $this->lava->Booking_model->selected_instances((int) $pending['id']) : [];
                if ($this->fits($snapshot, $kind === 'facility' ? 1 : (int) $pending['quantity_requested'], $selected)) continue;
                $reason = 'Automatically rejected: an approved booking reserves the facility, selected items, or required quantity during your requested interval.';
                $this->lava->Booking_model->reject($kind, (int) $pending['id'], $reason, 'automatic');
                $this->lava->Booking_model->notify($kind, $pending, 'rejected', $pending['booking_reference'] . ': ' . $reason);
                $this->lava->Booking_model->audit($kind, (int) $pending['id'], null, 'automatically_rejected', ['trigger_booking_id' => $id, 'reason' => $reason]);
                $rejected[] = (int) $pending['id'];
            }
            return ['booking' => $this->lava->Booking_model->booking($kind, $id), 'automatically_rejected_ids' => $rejected];
        });
    }

    public function reject(string $kind, int $id, array $actor, string $reason): array
    {
        return $this->atomic(function () use ($kind, $id, $actor, $reason) {
            [, , $booking] = $this->locked_booking($kind, $id);
            if ($booking['status'] !== 'PENDING') throw new DomainException('Only pending requests can be rejected.', 409);
            $this->lava->Booking_model->reject($kind, $id, $reason, 'manual');
            $this->lava->Booking_model->notify($kind, $booking, 'rejected', $booking['booking_reference'] . ': ' . $reason);
            $this->lava->Booking_model->audit($kind, $id, (int) $actor['id'], 'rejected', ['reason' => $reason]);
            return $this->lava->Booking_model->booking($kind, $id);
        });
    }

    private function fits(array $snapshot, int $quantity, array $selected): bool
    {
        if ($snapshot['available_quantity'] < $quantity) return FALSE;
        $available = array_map('intval', array_column($snapshot['available_instances'], 'id'));
        return !$selected || !array_diff($selected, $available);
    }

    public function cancel(string $kind, int $id, array $actor, string $reason): array
    {
        return $this->atomic(function () use ($kind, $id, $actor, $reason) {
            [$schema, , $booking] = $this->locked_booking($kind, $id);
            $current = $this->lava->Account_model->by_id((int) $actor['id']);
            if (!$current || (int) $current['is_active'] !== 1) throw new DomainException('Unauthorized', 401);
            if ((int) $booking['requester_id'] !== (int) $actor['id']
                && !in_array($kind . '_booking.cancel_any', $this->lava->Account_model->permissions((int) $actor['id']), TRUE)) {
                throw new DomainException('Forbidden', 403);
            }
            if (!in_array($booking['status'], ['PENDING', 'APPROVED', 'READY_FOR_RELEASE'], TRUE)) {
                throw new DomainException('This booking can no longer be cancelled.', 409);
            }
            if ($kind === 'asset' && ($booking['released_at'] !== null || (int) ($booking['quantity_released'] ?? 0) > 0)) {
                throw new DomainException('Released assets must follow the return process.', 409);
            }
            if ($booking['status'] !== 'PENDING' && strtotime($booking[$schema['start']] . ' UTC') <= time()) {
                throw new DomainException('Approved bookings may only be cancelled before their scheduled start.', 409);
            }
            $this->lava->Booking_model->cancel($kind, $id, $reason);
            $this->lava->Booking_model->notify($kind, $booking, 'cancelled', $booking['booking_reference'] . ': ' . $reason);
            $this->lava->Booking_model->audit($kind, $id, (int) $actor['id'], 'cancelled', ['previous_status' => $booking['status'], 'reason' => $reason]);
            return $this->lava->Booking_model->booking($kind, $id);
        });
    }

    private function locked_booking(string $kind, int $id): array
    {
        $s = $this->lava->Booking_model->schema($kind);
        $booking = $this->lava->Booking_model->booking($kind, $id);
        if (!$booking) throw new DomainException('Booking not found.', 404);
        // Always lock resource before booking: all operations share this lock order.
        $resource = $this->lava->Booking_model->resource($kind, (int) $booking[$s['key']], TRUE);
        return [$s, $resource, $this->lava->Booking_model->booking($kind, $id, TRUE)];
    }

    private function atomic(callable $operation): array
    {
        // After waiting for a resource lock, read the preceding approver's commit.
        if ($this->transaction_depth === 0) $this->lava->db->raw('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $this->lava->db->transaction();
        $this->transaction_depth++;
        try {
            $result = $operation();
            $this->lava->db->commit();
            return $result;
        } catch (Throwable $error) {
            $this->lava->db->roll_back();
            throw $error;
        } finally { $this->transaction_depth--; }
    }

    public function create_series(string $kind, array $actor, array $body): array
    {
        if (array_diff(array_keys($body), ['booking','recurrence','idempotency_key']) || !is_object($body['booking'] ?? null) || !is_object($body['recurrence'] ?? null)) throw new DomainException('Provide booking and recurrence objects and an idempotency_key.', 422);
        $key = $body['idempotency_key'] ?? null;
        if (!is_string($key) || !preg_match('/\A[A-Za-z0-9_-]{16,80}\z/', $key)) throw new DomainException('Provide a unique 16–80 character idempotency_key.', 422);
        // Match the database's case-insensitive key identity before taking its lock.
        $key = strtolower($key);
        $this->lava->call->library('recurrence_rules');
        $model = $this->lava->call->model('Series_model');
        $rule = $this->lava->recurrence_rules->rule((array) $body['recurrence']);
        $common = (array) $body['booking'];
        $s = $this->lava->Booking_model->schema($kind);
        $allowed = [$s['key'],$s['start'],$s['end'],'purpose'];
        array_push($allowed, ...($kind === 'facility' ? ['activity_name','participants_count'] : ['quantity_requested','instance_ids']));
        if (array_diff(array_keys($common), $allowed)) throw new DomainException('Unsupported common booking fields.', 422);
        ksort($common);
        $hash = hash('sha256', json_encode([$kind,$common,$rule], JSON_THROW_ON_ERROR));
        $owner = (int) $actor['id'];
        if (!$model->lock_key($owner, $key)) throw new DomainException('This recurring request is still processing; retry with the same key.', 409);
        try {
            return $this->atomic(function () use ($model,$kind,$owner,$key,$hash,$common,$rule,$s) {
                $actor = $this->lava->Account_model->by_id($owner);
                if (!$actor || (int) $actor['is_active'] !== 1) throw new DomainException('Unauthorized', 401);
                if (!in_array($kind . '_booking.create', $this->lava->Account_model->permissions($owner), TRUE)) throw new DomainException('Forbidden', 403);
                $existing = $model->by_key($owner, $key);
                if ($existing) {
                    if (!hash_equals($existing['request_hash'], $hash)) throw new DomainException('This key belongs to a different recurring request.', 409);
                    return $this->series_result($existing, TRUE);
                }
                [$start,$end] = $this->lava->booking_rules->interval($common[$s['start']] ?? null, $common[$s['end']] ?? null, TRUE);
                $dates = $this->lava->recurrence_rules->dates($start, $end, $rule);
                $id = $model->insert_series($kind, $owner, $rule, $key, $hash);
                foreach ($dates as $index => [$left,$right]) {
                    $occurrence = array_replace($common, [$s['start'] => $left, $s['end'] => $right]);
                    try { $this->create($kind, $actor, $occurrence, $id); }
                    catch (DomainException $error) {
                        $label = (new DateTimeImmutable($left))->format('M j, Y g:i A');
                        throw new DomainException('Occurrence ' . ($index + 1) . ' (' . $label . ' Asia/Manila): ' . $error->getMessage(), $error->getCode());
                    }
                }
                return $this->series_result($model->find_series($id), FALSE);
            });
        } finally { $model->lock_key($owner, $key, TRUE); }
    }

    public function series_result(array $series, bool $replayed = FALSE): array
    {
        $bookings = $this->lava->Series_model->members($series);
        unset($series['request_hash'], $series['idempotency_key']);
        return ['series' => $series, 'bookings' => $bookings, 'replayed' => $replayed];
    }

    public function with_locked_asset_booking(int $id, callable $operation): array
    {
        return $this->atomic(function () use ($id, $operation) {
            [, $resource, $booking] = $this->locked_booking('asset', $id);
            return $operation($resource, $booking);
        });
    }

    public function complete_facility(int $id, int $actor): array
    {
        return $this->atomic(function () use ($id, $actor) {
            [, , $booking] = $this->locked_booking('facility', $id);
            if (!in_array('facility_booking.approve', $this->lava->Account_model->permissions($actor), TRUE)) throw new DomainException('Forbidden', 403);
            if ($booking['status'] === 'COMPLETED') return $booking;
            if ($booking['status'] !== 'APPROVED' || strtotime($booking['end_datetime'] . ' UTC') > time()) throw new DomainException('Only approved facility bookings whose scheduled end has passed may be completed.', 409);
            $this->lava->Booking_model->complete_facility($id);
            $this->lava->Booking_model->audit('facility', $id, $actor, 'completed');
            $this->lava->Booking_model->notify('facility', $booking, 'completed', $booking['booking_reference'] . ' has been completed.');
            return $this->lava->Booking_model->booking('facility', $id);
        });
    }

    public function asset_reserved_quantity(int $id, string $start, string $end): int
    {
        $utc = new DateTimeZone('UTC');
        $blocks = [];
        foreach ($this->lava->Booking_model->blocks('asset', $id, $start, $end) as $row) {
            if ((int) $row['quantity'] === 0) continue;
            $blocks[] = ['start' => (new DateTimeImmutable($row['start'], $utc))->getTimestamp(),
                'end' => (new DateTimeImmutable($row['end'], $utc))->getTimestamp(), 'quantity' => (int) $row['quantity']];
        }
        return $this->lava->booking_availability->reserved_quantity((new DateTimeImmutable($start, $utc))->getTimestamp(), (new DateTimeImmutable($end, $utc))->getTimestamp(), $blocks);
    }
}
