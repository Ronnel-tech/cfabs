<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Catalog extends Controller {
    private const ACCESS = [
        'departments' => [null, 'department.manage'],
        'facility_categories' => [null, 'facility.category.manage'],
        'asset_categories' => [null, 'asset.category.manage'],
        'facilities' => ['facility.view', 'facility.update'],
        'assets' => ['asset.view', 'asset.update'],
        'asset_instances' => ['asset.view', 'asset.update'],
    ];

    public function __construct()
    {
        parent::__construct();
        $this->call->library(['account_access', 'catalog_service']);
        header('Cache-Control: no-store');
    }

    public function departments() { $this->index('departments'); }
    public function department_create() { $this->write('departments', null, 'department.manage'); }
    public function department_update($id) { $this->write('departments', $id, 'department.manage'); }
    public function department_status($id) { $this->write('departments', $id, 'department.manage', null, TRUE); }
    public function facility_categories() { $this->index('facility_categories'); }
    public function facility_category_create() { $this->write('facility_categories', null, 'facility.category.manage'); }
    public function facility_category_update($id) { $this->write('facility_categories', $id, 'facility.category.manage'); }
    public function facility_category_status($id) { $this->write('facility_categories', $id, 'facility.category.manage', null, TRUE); }
    public function asset_categories() { $this->index('asset_categories'); }
    public function asset_category_create() { $this->write('asset_categories', null, 'asset.category.manage'); }
    public function asset_category_update($id) { $this->write('asset_categories', $id, 'asset.category.manage'); }
    public function asset_category_status($id) { $this->write('asset_categories', $id, 'asset.category.manage', null, TRUE); }
    public function facilities() { $this->index('facilities'); }
    public function facility_show($id) { $this->show('facilities', $id); }
    public function facility_create() { $this->write('facilities', null, 'facility.create'); }
    public function facility_update($id) { $this->write('facilities', $id, 'facility.update'); }
    public function facility_status($id) { $this->write('facilities', $id, 'facility.deactivate', null, TRUE); }
    public function assets() { $this->index('assets'); }
    public function asset_show($id) { $this->show('assets', $id); }
    public function asset_create() { $this->write('assets', null, 'asset.create'); }
    public function asset_update($id) { $this->write('assets', $id, 'asset.update'); }
    public function asset_status($id) { $this->write('assets', $id, 'asset.deactivate', null, TRUE); }
    public function instances($id) { $this->index('asset_instances', $id); }
    public function instance_create($id) { $this->write('asset_instances', null, 'asset.create', $id); }
    public function instance_update($id, $instance_id) { $this->write('asset_instances', $instance_id, 'asset.update', $id); }
    public function instance_status($id, $instance_id) { $this->write('asset_instances', $instance_id, 'asset.deactivate', $id, TRUE); }

    private function index(string $table, $parent = null)
    {
        $actor = $this->account_access->require_user(self::ACCESS[$table][0]);
        $this->respond(function () use ($table, $actor, $parent) {
            $query = $this->api->get_query_params();
            $page = $this->booking_rules->positive_id($query['page'] ?? 1);
            $limit = $this->booking_rules->positive_id($query['per_page'] ?? 20);
            if ($page > 1000000 || $limit > 100) throw new DomainException('Pagination limit exceeded.', 422);
            $include_inactive = $query['include_inactive'] ?? '0';
            if (!in_array($include_inactive, ['0', '1'], TRUE)) throw new DomainException('include_inactive must be 0 or 1.', 422);
            if ($include_inactive === '1' && !in_array(self::ACCESS[$table][1], $actor['permissions'], TRUE)) throw new DomainException('Forbidden', 403);
            $asset_id = $parent === null ? null : $this->booking_rules->positive_id($parent);
            if ($asset_id !== null) $this->visible_record('assets', $asset_id, $actor);
            return $this->Catalog_model->list_records($table, $limit, ($page - 1) * $limit, $include_inactive === '1', $asset_id);
        });
    }

    private function show(string $table, $id)
    {
        $actor = $this->account_access->require_user(self::ACCESS[$table][0]);
        $this->respond(fn() => $this->visible_record($table, $this->booking_rules->positive_id($id), $actor));
    }

    private function visible_record(string $table, int $id, array $actor): array
    {
        $record = $this->Catalog_model->find_record($table, $id);
        if (!$record || ($record['status'] !== 'active' && !in_array(self::ACCESS[$table][1], $actor['permissions'], TRUE))) {
            throw new DomainException('Catalog record not found.', 404);
        }
        return $record;
    }

    private function write(string $table, $id, string $permission, $parent = null, bool $status = FALSE)
    {
        $actor = $this->account_access->require_user($permission);
        $body = $this->account_access->json_body();
        $this->respond(fn() => $this->catalog_service->save($table,
            $id === null ? null : $this->booking_rules->positive_id($id), $body, $actor,
            $parent === null ? null : $this->booking_rules->positive_id($parent), $status), $id === null ? 201 : 200);
    }

    private function respond(callable $action, int $status = 200)
    {
        try { $data = $action(); }
        catch (DomainException $error) { $this->api->respond_error($error->getMessage(), $error->getCode() ?: 422); }
        $this->api->respond(['success' => TRUE, 'data' => $data], $status);
    }
}
