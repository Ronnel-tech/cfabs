<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Qr_service {
    private $lava;
    private const TABLES = ['asset' => 'assets', 'asset-instance' => 'asset_instances'];

    public function __construct()
    {
        $this->lava = lava_instance();
        $this->lava->call->model(['Qr_model', 'Catalog_model']);
        $this->lava->call->library('booking_rules');
    }

    public function identifier($value): string
    {
        if (!is_string($value) || !preg_match('/\A[a-f0-9]{48}\z/', $value)) throw new DomainException('Invalid QR identifier.', 422);
        return $value;
    }

    private function resource(string $kind, int $id, array $actor, bool $lock = FALSE): array
    {
        $table = self::TABLES[$kind] ?? null;
        if (!$table) throw new DomainException('Unknown QR resource type.', 422);
        if (!in_array('asset.view', $actor['permissions'], TRUE)) throw new DomainException('Forbidden', 403);
        // Keep the same parent-before-instance lock order used by custody/catalog writes.
        $record = $this->lava->Catalog_model->find_record($table, $id);
        if (!$record) throw new DomainException('Resource not found.', 404);
        if ($kind === 'asset-instance') {
            $parent = $this->lava->Catalog_model->find_record('assets', (int) $record['asset_id'], $lock);
            if (!$parent || $parent['tracking_mode'] !== 'individual') throw new DomainException('Resource not found.', 404);
        }
        if ($lock) $record = $this->lava->Catalog_model->find_record($table, $id, TRUE);
        if (!$record || ($kind === 'asset' && $record['tracking_mode'] !== 'quantity')) throw new DomainException('Use the QR labels of the individually tracked items.', 422);
        $active = $record['status'] === 'active' && ($kind !== 'asset-instance' || $parent['status'] === 'active');
        if (!$active && !in_array('asset.update', $actor['permissions'], TRUE)) throw new DomainException('Resource not found.', 404);
        return ['kind' => $kind, 'id' => $id, 'asset_id' => $kind === 'asset-instance' ? (int) $record['asset_id'] : $id,
            'name' => $kind === 'asset' ? $record['asset_name'] : $parent['asset_name'],
            'code' => $kind === 'asset' ? $record['asset_code'] : $record['asset_tag'],
            'status' => $record['status'], 'active' => $active,
            'condition_status' => $record['condition_status'] ?? null];
    }

    public function label(string $kind, int $id, array $actor): array
    {
        $resource = $this->resource($kind, $id, $actor);
        $label = $this->lava->Qr_model->current($kind, $id);
        if (!$label) throw new DomainException('QR label not found.', 404);
        return $this->present($label, $resource);
    }

    public function resolve(string $identifier, array $actor): array
    {
        $label = $this->lava->Qr_model->find($this->identifier($identifier));
        if (!$label) throw new DomainException('QR code is invalid or revoked.', 404);
        [$kind, $id] = $this->target($label);
        $resource = $this->resource($kind, $id, $actor);
        if (!$resource['active']) throw new DomainException('Resource is inactive.', 404);
        return $this->present($label, $resource);
    }

    public function change(string $kind, int $id, array $body, array $actor): array
    {
        if (array_diff(array_keys($body), ['action', 'expected_identifier']) || !in_array($body['action'] ?? null, ['revoke', 'regenerate'], TRUE)) throw new DomainException('Provide action (revoke or regenerate) and expected_identifier only.', 422);
        $expected = $this->identifier($body['expected_identifier'] ?? null);
        if (!in_array('asset.update', $actor['permissions'], TRUE)) throw new DomainException('Forbidden', 403);
        $this->lava->db->transaction();
        try {
            $resource = $this->resource($kind, $id, $actor, TRUE);
            $before = $this->lava->Qr_model->current($kind, $id, TRUE);
            if (!$before) throw new DomainException('QR label not found.', 404);
            if (!hash_equals($before['identifier'], $expected)) throw new DomainException('QR label changed; refresh before trying again.', 409);
            if ($body['action'] !== 'revoke' || $before['revoked_at'] === null) {
                $this->lava->Qr_model->change((int) $before['id'], $body['action']);
                $after = $this->lava->Qr_model->current($kind, $id);
                $this->lava->Qr_model->audit((int) $actor['id'], $before, $after, $body['action']);
            } else $after = $before;
            $this->lava->db->commit();
            return $this->present($after, $resource);
        } catch (Throwable $error) {
            $this->lava->db->roll_back();
            throw $error;
        }
    }

    /** Runs inside the existing asset/booking transaction before a new ledger entry. */
    public function verify_handover(array $identifiers, array $asset, array $items): void
    {
        $resolved = [];
        foreach ($identifiers as $identifier) {
            $label = $this->lava->Qr_model->find($identifier, TRUE);
            if (!$label) throw new DomainException('QR code is invalid or revoked.', 409);
            if ($asset['tracking_mode'] === 'quantity') {
                if ((int) $label['asset_id'] !== (int) $asset['id']) throw new DomainException('QR code does not match this asset pool.', 422);
            } else {
                if ($label['asset_instance_id'] === null) throw new DomainException('Scan the individual item labels.', 422);
                $item = $this->lava->Catalog_model->find_record('asset_instances', (int) $label['asset_instance_id']);
                if (!$item || (int) $item['asset_id'] !== (int) $asset['id']) throw new DomainException('QR code does not match this asset.', 422);
                $resolved[] = (int) $item['id'];
            }
        }
        sort($resolved);
        if (($asset['tracking_mode'] === 'quantity' && count($identifiers) !== 1)
            || ($asset['tracking_mode'] === 'individual' && $resolved !== $items)) throw new DomainException('Scan exactly the items selected for this handover.', 422);
    }

    private function target(array $label): array
    {
        if ($label['asset_id'] !== null) return ['asset', (int) $label['asset_id']];
        return ['asset-instance', (int) $label['asset_instance_id']];
    }

    private function present(array $label, array $resource): array
    {
        return ['identifier' => $label['identifier'], 'revoked' => $label['revoked_at'] !== null,
            'payload' => 'cfabs:qr:' . $label['identifier'], 'resource' => $resource];
    }
}
