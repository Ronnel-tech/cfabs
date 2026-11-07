<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Catalog_service {
    private $lava;
    private const IDENTITIES = ['departments' => ['department_code'], 'facility_categories' => ['category_name'],
        'asset_categories' => ['category_name'], 'facilities' => ['facility_code'], 'assets' => ['asset_code'],
        'asset_instances' => ['asset_tag', 'serial_number']];

    public function __construct()
    {
        $this->lava = lava_instance();
        $this->lava->call->model('Catalog_model');
        $this->lava->call->library('catalog_rules');
    }

    public function save(string $table, ?int $id, array $body, array $actor, ?int $parent = null, bool $status_only = FALSE): array
    {
        $data = $status_only ? ['status' => $this->lava->catalog_rules->status($table, $body)]
            : $this->lava->catalog_rules->fields($table, $body, $id === null);
        if (!$this->lava->Catalog_model->acquire_write_lock()) throw new DomainException('Catalog is busy; try again.', 409);
        try {
            $this->lava->db->raw('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $this->lava->db->transaction();
            try {
                // Match booking lock order: parent asset, then its instance.
                $asset = $parent === null ? null : $this->lava->Catalog_model->find_record('assets', $parent, TRUE);
                if ($parent !== null && (!$asset || $asset['tracking_mode'] !== 'individual')) throw new DomainException('An individually tracked parent asset is required.', 422);
                $before = $id === null ? null : $this->lava->Catalog_model->find_record($table, $id, TRUE);
                if ($id !== null && (!$before || ($parent !== null && (int) $before['asset_id'] !== $parent))) throw new DomainException('Catalog record not found.', 404);
                if ($id === null) {
                    $data['status'] = 'active';
                    if ($table === 'assets' || $table === 'facilities') $data['created_by'] = (int) $actor['id'];
                    if ($table === 'asset_instances') {
                        if ($parent === null || $asset['status'] !== 'active') throw new DomainException('An active parent asset is required.', 422);
                        $data['asset_id'] = $parent;
                    }
                }
                $after = array_replace($before ?? [], $data);
                $this->validate($table, $id, $before, $after, $data);
                foreach (self::IDENTITIES[$table] as $column) {
                    if (isset($after[$column]) && $this->lava->Catalog_model->duplicate($table, $column, $after[$column], $id ?? 0)) {
                        throw new DomainException("{$column} already exists.", 409);
                    }
                }
                if ($id === null) $id = $this->lava->Catalog_model->insert_record($table, $data);
                else $this->lava->Catalog_model->update_record($table, $id, $data);
                if ($before === null && ($table === 'asset_instances' || ($table === 'assets' && $after['tracking_mode'] === 'quantity'))) {
                    $this->lava->call->model('Qr_model');
                    $kind = ['assets' => 'asset', 'asset_instances' => 'asset-instance'][$table];
                    $this->lava->Qr_model->create($kind, $id);
                }
                $result = $this->lava->Catalog_model->find_record($table, $id);
                $this->lava->Catalog_model->audit($table, $id, (int) $actor['id'], $before, $result);
                $this->lava->db->commit();
                return $result;
            } catch (Throwable $error) {
                $this->lava->db->roll_back();
                throw $error;
            }
        } finally {
            $this->lava->Catalog_model->release_write_lock();
        }
    }

    private function validate(string $table, ?int $id, ?array $before, array $after, array $changes): void
    {
        $model = $this->lava->Catalog_model;
        if ($table === 'facilities' || $table === 'assets') {
            $category_table = $table === 'facilities' ? 'facility_categories' : 'asset_categories';
            $category = $model->find_record($category_table, (int) $after['category_id']);
            if (!$category || ($after['status'] === 'active' && $category['status'] !== 'active')) throw new DomainException('An active category is required for an active resource.', 422);
        }
        if ($table === 'assets') {
            if ($before && $before['tracking_mode'] !== $after['tracking_mode']) throw new DomainException('Tracking mode cannot be changed; register a separate asset.', 409);
            $department = $model->find_record('departments', (int) $after['owning_department_id']);
            if (!$department || ($after['status'] === 'active' && $department['status'] !== 'active')) throw new DomainException('An active owning department is required.', 422);
            $total = (int) ($after['total_quantity'] ?? 0);
            $usable = (int) ($after['usable_quantity'] ?? 0);
            if ($usable > $total) throw new DomainException('Usable stock cannot exceed total stock.', 422);
            if ($after['tracking_mode'] === 'individual' && ($total !== 0 || $usable !== 0)) throw new DomainException('Individual stock is counted from item instances, not pool quantities.', 422);
            if (!in_array($after['condition_status'] ?? 'good', ['good', 'fair'], TRUE) && $usable > 0) throw new DomainException('Unusable pools must have zero usable stock.', 422);
            if (isset($after['custodian_id'])) {
                $custodian = $this->lava->Account_model->by_id((int) $after['custodian_id']);
                if (!$custodian || (int) $custodian['is_active'] !== 1
                    || !in_array('asset_booking.release', $this->lava->Account_model->permissions((int) $custodian['id']), TRUE)) {
                    throw new DomainException('Assign an active user with asset release permission as custodian.', 422);
                }
            }
            if ($before && ($total < (int) $before['total_quantity'] || $usable < (int) $before['usable_quantity']) && $model->has_bookings($table, $id)) {
                throw new DomainException('Resolve outstanding asset requests before reducing inventory.', 409);
            }
        }
        if ($before && $table === 'facilities' && isset($changes['capacity']) && $after['capacity'] < $model->maximum_participants($id)) {
            throw new DomainException('Capacity cannot be below an outstanding request participant count.', 409);
        }
        $unavailable = $after['status'] !== 'active' || ($table === 'asset_instances'
            && !in_array($after['condition_status'] ?? 'good', ['good', 'fair'], TRUE));
        if ($before && $unavailable && in_array($table, ['facilities', 'assets', 'asset_instances'], TRUE) && $model->has_bookings($table, $id)) {
            throw new DomainException('Resolve outstanding bookings before making this resource unavailable.', 409);
        }
        if ($before && $after['status'] !== 'active' && $model->active_children($table, $id)) {
            throw new DomainException('Reassign or deactivate active dependent records first.', 409);
        }
    }
}
