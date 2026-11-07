<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Asset_operations {
    private $lava;

    public function __construct()
    {
        $this->lava = lava_instance();
        $this->lava->call->library('booking_service');
        $this->lava->call->model('Handover_model');
    }

    public function handover(int $booking, string $direction, int $actor, array $body, ?int $source = null): array
    {
        if (!in_array($direction, ['release', 'return', 'inspection'], TRUE)) throw new InvalidArgumentException('Invalid handover.');
        $qr = $body['qr_identifiers'] ?? null;
        if (array_key_exists('qr_identifiers', $body)) {
            if ($direction === 'inspection') throw new DomainException('QR scanning is supported for release and return only.', 422);
            $this->lava->call->library('qr_service');
            if (!is_array($qr) || !array_is_list($qr) || count($qr) < 1 || count($qr) > 1000) throw new DomainException('qr_identifiers must contain 1–1000 identifiers.', 422);
            $qr = array_map([$this->lava->qr_service, 'identifier'], $qr);
            if (count(array_unique($qr)) !== count($qr)) throw new DomainException('Duplicate QR identifiers are not allowed.', 422);
            sort($qr);
            unset($body['qr_identifiers']);
        }
        $data = $this->input($direction, $body);
        if ($direction === 'return' && $data['condition_status'] === 'lost') $direction = 'loss';
        $permission = $direction === 'release' ? 'asset_booking.release' : ($direction === 'inspection' ? 'asset.condition.manage' : 'asset_booking.return');
        $hash_input = [$actor, $direction, $source, $data];
        if ($qr !== null) $hash_input[] = $qr;
        $data['request_hash'] = hash('sha256', json_encode($hash_input, JSON_THROW_ON_ERROR));
        return $this->lava->booking_service->with_locked_asset_booking($booking, function ($asset, $request) use ($booking, $direction, $actor, $data, $source, $permission, $qr) {
            $this->require_actor($actor, $permission);
            if (in_array($data['condition_status'], ['damaged', 'lost'], TRUE)) $this->require_actor($actor, 'asset.condition.manage');
            $model = $this->lava->Handover_model;
            $existing = $model->by_key($booking, $data['idempotency_key']);
            if ($existing) {
                if (!hash_equals($existing['request_hash'], $data['request_hash'])) throw new DomainException('Idempotency key was already used for another handover.', 409);
                return $this->receipt($existing, TRUE);
            }
            if ($qr !== null) $this->lava->qr_service->verify_handover($qr, $asset, $data['instance_ids']);
            $totals = $this->verified_totals($request);
            $items = $data['instance_ids'];
            if ($asset['tracking_mode'] === 'individual') {
                if (count($items) !== $data['quantity']) throw new DomainException('Select exactly one item per handover unit.', 422);
            } elseif ($items) throw new DomainException('Quantity pools do not accept individual item IDs.', 422);
            if (!in_array($request['status'], ['APPROVED', 'READY_FOR_RELEASE', 'RELEASED', 'RETURNED'], TRUE)) throw new DomainException('Booking is not eligible for this handover.', 409);
            if ($direction === 'release') {
                if ($asset['status'] !== 'active' || time() < strtotime($request['usage_start'] . ' UTC') || time() >= strtotime($request['expected_return'] . ' UTC')) {
                    throw new DomainException('Release is allowed only during the approved booking period for an active asset.', 409);
                }
                if ($totals['released'] + $data['quantity'] > (int) $request['quantity_requested']) throw new DomainException('Release exceeds the approved quantity.', 409);
                if ($asset['tracking_mode'] === 'individual') {
                    $selected = $this->lava->Booking_model->selected_instances($booking);
                    $released = $model->event_items($booking, ['release']);
                    $now = gmdate('Y-m-d H:i:s');
                    $free = array_map('intval', array_column($model->available_instances((int) $asset['id'], $now, gmdate('Y-m-d H:i:s', time() + 1), $booking), 'id'));
                    if (array_diff($items, $selected) || array_intersect($items, $released) || array_diff($items, $free)) throw new DomainException('Selected items are not eligible for release.', 409);
                } elseif ($data['quantity'] > $model->physical_free((int) $asset['id'], (int) $asset['usable_quantity'])) {
                    throw new DomainException('Insufficient physical stock for release.', 409);
                }
            } elseif ($direction === 'inspection') {
                $origin = $source === null ? null : $model->find_handover($source);
                if (!$origin || (int) $origin['asset_booking_id'] !== $booking || $origin['direction'] !== 'return' || $origin['condition_status'] !== 'inspection') {
                    throw new DomainException('Inspection must reference a return awaiting inspection.', 422);
                }
                if ($data['quantity'] > (int) $origin['quantity'] - $model->inspected_quantity($source)) throw new DomainException('Inspection exceeds the remaining held quantity.', 409);
                if ($items && array_diff($items, array_diff($model->items($source), $model->inspected_items($source)))) throw new DomainException('Selected items are not awaiting this inspection.', 409);
            } else {
                if ($data['quantity'] > $totals['released'] - $totals['returned'] - $totals['lost']) throw new DomainException('Return or loss exceeds outstanding released quantity.', 409);
                if ($items && array_diff($items, array_diff($model->event_items($booking, ['release']), $model->event_items($booking, ['return', 'loss'])))) {
                    throw new DomainException('Selected items are not outstanding on this booking.', 409);
                }
            }
            if (($direction === 'return' || $direction === 'loss' || $direction === 'inspection') && in_array($data['condition_status'], ['damaged', 'lost'], TRUE)
                && $asset['tracking_mode'] === 'quantity') {
                if ($data['quantity'] > (int) $asset['usable_quantity']) throw new DomainException('Stock has already been adjusted; reconcile the inventory before recording this condition.', 409);
                $model->reduce_usable((int) $asset['id'], $data['quantity']);
            }
            if ($direction !== 'release' && $items) $model->condition_items((int) $asset['id'], $items, $data['condition_status']);
            $event = $model->insert_event([
                'asset_booking_id' => $booking, 'actor_id' => $actor, 'direction' => $direction, 'quantity' => $data['quantity'],
                'condition_status' => $data['condition_status'], 'remarks' => $data['remarks'], 'source_handover_id' => $source,
                'idempotency_key' => $data['idempotency_key'], 'request_hash' => $data['request_hash'],
            ], $items);
            $totals = $model->totals($booking);
            $settled = $totals['returned'] + $totals['lost'] === $totals['released'];
            $status = $totals['released'] === (int) $request['quantity_requested'] && $settled
                ? ($totals['inspection'] === 0 ? 'COMPLETED' : 'RETURNED') : 'RELEASED';
            $model->update_summary($booking, $totals, $status, $actor, $event);
            if (in_array($data['condition_status'], ['damaged', 'lost', 'inspection'], TRUE)) {
                $usable = (int) $asset['usable_quantity'] - (in_array($data['condition_status'], ['damaged', 'lost'], TRUE) ? $data['quantity'] : 0);
                foreach ($model->future_reservations((int) $asset['id']) as $future) {
                    $conflict = $asset['tracking_mode'] === 'quantity'
                        ? $this->lava->booking_service->asset_reserved_quantity((int) $asset['id'], $future['usage_start'], $future['expected_return']) > $usable
                        : (bool) array_intersect($items, $this->lava->Booking_model->selected_instances((int) $future['id']));
                    if (!$conflict) continue;
                    $this->lava->Booking_model->notify('asset', $future, 'inventory_changed', $future['booking_reference'] . ': changed inventory availability no longer covers existing reservations. Contact the custodian to resolve this booking.');
                    $this->lava->Booking_model->audit('asset', (int) $future['id'], $actor, 'inventory_conflict', ['handover_id' => (int) $event['id']]);
                }
            }
            $this->lava->Booking_model->audit('asset', $booking, $actor, 'handover.' . $direction, ['handover_id' => (int) $event['id'], 'quantity' => $data['quantity'], 'condition' => $data['condition_status']]);
            if ($qr !== null) $this->lava->Booking_model->audit('asset', $booking, $actor, 'qr.verified', ['handover_id' => (int) $event['id'], 'label_fingerprints' => array_map(fn($value) => hash('sha256', $value), $qr)]);
            $this->lava->Booking_model->notify('asset', $request, $direction, $request['booking_reference'] . ': ' . $data['quantity'] . ' unit(s), ' . $direction . ' recorded.');
            return $this->receipt($event, FALSE);
        });
    }

    public function custody(int $id): array
    {
        return $this->lava->booking_service->with_locked_asset_booking($id, function ($asset, $booking) use ($id) {
            $model = $this->lava->Handover_model;
            $totals = $model->totals($id);
            $released = $model->event_items($id, ['release']);
            $settled = $model->event_items($id, ['return', 'loss']);
            return [
                'booking' => $booking, 'tracking_mode' => $asset['tracking_mode'], 'asset_status' => $asset['status'], 'totals' => $totals,
                'reconciled' => $totals['released'] === (int) ($booking['quantity_released'] ?? 0)
                    && $totals['returned'] === (int) ($booking['quantity_returned'] ?? 0) && $totals['lost'] === (int) $booking['quantity_lost'],
                'unreleased_instance_ids' => array_values(array_diff($this->lava->Booking_model->selected_instances($id), $released)),
                'outstanding_instance_ids' => array_values(array_diff($released, $settled)),
                'inspection_holds' => $model->open_inspections($id),
            ];
        });
    }

    public function complete(int $id, int $actor, string $reason): array
    {
        return $this->lava->booking_service->with_locked_asset_booking($id, function ($asset, $request) use ($id, $actor, $reason) {
            $this->require_actor($actor, 'asset_booking.return');
            if ($request['status'] === 'COMPLETED') return $request;
            $totals = $this->verified_totals($request);
            if (!in_array($request['status'], ['RELEASED','RETURNED'], TRUE) || $totals['released'] < 1
                || $totals['returned'] + $totals['lost'] !== $totals['released'] || $totals['inspection'] !== 0) {
                throw new DomainException('Settle all released units and inspections before completing the booking.', 409);
            }
            $this->lava->Handover_model->complete_booking($id);
            $this->lava->Booking_model->audit('asset', $id, $actor, 'completed', ['reason' => $reason, 'unused_quantity' => (int) $request['quantity_requested'] - $totals['released']]);
            $this->lava->Booking_model->notify('asset', $request, 'completed', $request['booking_reference'] . ': ' . $reason);
            return $this->lava->Booking_model->booking('asset', $id);
        });
    }

    private function verified_totals(array $booking): array
    {
        $totals = $this->lava->Handover_model->totals((int) $booking['id']);
        if ($totals['released'] !== (int) ($booking['quantity_released'] ?? 0) || $totals['returned'] !== (int) ($booking['quantity_returned'] ?? 0)
            || $totals['lost'] !== (int) $booking['quantity_lost']) throw new DomainException('Existing handover totals need reconciliation; do not invent missing transaction history.', 409);
        return $totals;
    }

    private function require_actor(int $actor, string $permission): void
    {
        $user = $this->lava->Account_model->by_id($actor);
        if (!$user || (int) $user['is_active'] !== 1) throw new DomainException('Unauthorized', 401);
        if (!in_array($permission, $this->lava->Account_model->permissions($actor), TRUE)) throw new DomainException('Forbidden', 403);
    }

    private function receipt(array $event, bool $replayed): array
    {
        unset($event['request_hash'], $event['idempotency_key']);
        $event['instance_ids'] = $this->lava->Handover_model->items((int) $event['id']);
        return ['handover' => $event, 'booking' => $this->lava->Booking_model->booking('asset', (int) $event['asset_booking_id']), 'replayed' => $replayed];
    }

    private function input(string $direction, array $body): array
    {
        if (array_diff(array_keys($body), ['quantity','condition_status','instance_ids','remarks','idempotency_key'])) throw new DomainException('Unsupported handover fields.', 422);
        $quantity = $body['quantity'] ?? null;
        if (!is_int($quantity) || $quantity < 1 || $quantity > 4294967295) throw new DomainException('A positive whole-number quantity is required.', 422);
        $condition = $body['condition_status'] ?? null;
        $choices = $direction === 'release' ? ['good','fair'] : ($direction === 'inspection' ? ['good','fair','damaged'] : ['good','fair','damaged','lost','inspection']);
        if (!in_array($condition, $choices, TRUE)) throw new DomainException('Choose a valid condition for this operation.', 422);
        $key = $body['idempotency_key'] ?? null;
        if (!is_string($key) || !preg_match('/\A[A-Za-z0-9_-]{16,80}\z/', $key)) throw new DomainException('Provide a unique 16–80 character idempotency_key.', 422);
        $items = $body['instance_ids'] ?? [];
        if (!is_array($items) || !array_is_list($items) || count($items) > 1000) throw new DomainException('instance_ids must be a list of at most 1000 IDs.', 422);
        $items = array_map([$this->lava->booking_rules, 'positive_id'], $items);
        if (count(array_unique($items)) !== count($items)) throw new DomainException('Duplicate instance IDs are not allowed.', 422);
        sort($items);
        $remarks = $body['remarks'] ?? null;
        if ($remarks !== null) $remarks = $this->lava->booking_rules->text($remarks, 4000, 'remarks');
        if (in_array($condition, ['damaged','lost','inspection'], TRUE) && $remarks === null) throw new DomainException('Remarks are required for damage, loss or inspection.', 422);
        return ['quantity' => $quantity, 'condition_status' => $condition, 'instance_ids' => $items, 'remarks' => $remarks, 'idempotency_key' => $key];
    }
}
