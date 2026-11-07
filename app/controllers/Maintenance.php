<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Maintenance extends Controller {
    public function __construct()
    {
        parent::__construct();
        $this->call->library(['account_access','maintenance_service']);
        header('Cache-Control: no-store');
    }
    public function facilities() { $this->index('facility'); }
    public function assets() { $this->index('asset'); }
    public function facility_create() { $this->create('facility'); }
    public function asset_create() { $this->create('asset'); }
    public function facility_complete($id) { $this->close('facility', $id, 'completed'); }
    public function asset_complete($id) { $this->close('asset', $id, 'completed'); }
    public function facility_cancel($id) { $this->close('facility', $id, 'cancelled'); }
    public function asset_cancel($id) { $this->close('asset', $id, 'cancelled'); }

    private function index(string $kind)
    {
        $this->account_access->require_user($kind . '.maintenance.manage');
        $this->respond(function () use ($kind) {
            $query = $this->api->get_query_params();
            if (array_diff(array_keys($query), ['page','per_page','resource_id'])) throw new DomainException('Unsupported maintenance filter.', 422);
            $page = $this->booking_rules->positive_id($query['page'] ?? 1);
            $limit = $this->booking_rules->positive_id($query['per_page'] ?? 20);
            if ($page > 1000000 || $limit > 100) throw new DomainException('Pagination limit exceeded.', 422);
            return $this->Maintenance_model->records($kind, $limit, ($page - 1) * $limit,
                isset($query['resource_id']) ? $this->booking_rules->positive_id($query['resource_id']) : null);
        });
    }
    private function create(string $kind)
    {
        $actor = $this->account_access->require_user($kind . '.maintenance.manage');
        $body = $this->account_access->json_body();
        $this->respond(fn() => $this->maintenance_service->create($kind, $body, (int) $actor['id']), 201);
    }
    private function close(string $kind, $id, string $status)
    {
        $actor = $this->account_access->require_user($kind . '.maintenance.manage');
        $this->respond(fn() => $this->maintenance_service->close($kind, $this->booking_rules->positive_id($id), (int) $actor['id'], $status));
    }
    private function respond(callable $action, int $status = 200)
    {
        try { $data = $action(); }
        catch (DomainException $error) { $this->api->respond_error($error->getMessage(), $error->getCode() ?: 422); }
        $this->api->respond(['success' => TRUE, 'data' => $data], $status);
    }
}
