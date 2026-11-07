<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Access extends Controller {
    public function __construct()
    {
        parent::__construct();
        $this->call->library(['account_access', 'access_service']);
        header('Cache-Control: no-store');
    }

    public function role_create() { $this->save_role(null); }
    public function role_update($id) { $this->save_role($id); }
    public function account_role($id) { $this->account($id, 'role', 'user.manage'); }
    public function account_department($id) { $this->account($id, 'department', 'user.manage'); }
    public function account_status($id) { $this->account($id, 'status', 'user.manage'); }
    public function account_profile($id) { $this->account($id, 'profile', null); }

    public function role_permissions($id)
    {
        $this->account_access->require_user('permission.manage');
        $this->respond(function () use ($id) {
            $id = $this->booking_rules->positive_id($id);
            if (!$this->Access_model->role($id)) throw new DomainException('Role not found.', 404);
            return ['role_id' => $id, 'permission_keys' => $this->Access_model->grants($id)];
        });
    }

    public function replace_permissions($id)
    {
        $actor = $this->account_access->require_user('permission.manage');
        $body = $this->account_access->json_body();
        $this->respond(fn() => $this->access_service->grants($this->booking_rules->positive_id($id), $body, (int) $actor['id']));
    }

    private function save_role($id)
    {
        $actor = $this->account_access->require_user('role.manage');
        $body = $this->account_access->json_body();
        $this->respond(fn() => $this->access_service->save_role($id === null ? null : $this->booking_rules->positive_id($id), $body, (int) $actor['id']), $id === null ? 201 : 200);
    }

    private function account($id, string $operation, ?string $permission)
    {
        $actor = $this->account_access->require_user($permission);
        $body = $this->account_access->json_body();
        $this->respond(fn() => $this->access_service->account($this->booking_rules->positive_id($id), $operation, $body, (int) $actor['id']));
    }

    private function respond(callable $action, int $status = 200)
    {
        try { $result = $action(); }
        catch (DomainException $error) { $this->api->respond_error($error->getMessage(), $error->getCode() ?: 422); }
        $this->api->respond(['success' => TRUE, 'data' => $result], $status);
    }
}
