<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Accounts extends Controller {
    public function __construct()
    {
        parent::__construct();
        $this->call->library('account_access');
        header('Cache-Control: no-store');
    }

    public function index()
    {
        $this->account_access->require_user('user.manage');
        $params = $this->api->get_query_params();
        $page = filter_var($params['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
        $limit = filter_var($params['per_page'] ?? 20, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($page === FALSE || $limit === FALSE) {
            $this->api->respond_error('Invalid pagination', 422);
        }
        $offset = ($page - 1) * $limit;
        $rows = $this->Account_model->list_accounts($limit, $offset);
        $total = $this->Account_model->account_total();
        $this->api->respond(['success' => TRUE, 'data' => $rows,
            'pagination' => ['page' => $page, 'per_page' => $limit, 'total' => $total]]);
    }

    public function show($id)
    {
        $actor = $this->account_access->require_user();
        $id = $this->positive_id($id);
        if ((int) $actor['id'] !== $id && !in_array('user.manage', $actor['permissions'], TRUE)) {
            $this->api->respond_error('Forbidden', 403);
        }
        $user = $this->Account_model->by_id($id);
        if (!$user) {
            $this->api->respond_error('User not found', 404);
        }
        $this->api->respond(['success' => TRUE, 'data' => $this->Account_model->public_profile($user)]);
    }

    public function create()
    {
        $actor = $this->account_access->require_user('user.manage');
        $body = $this->account_access->json_body();
        $this->call->library('account_rules');
        try {
            $data = $this->account_rules->new_account($body);
        } catch (InvalidArgumentException $error) {
            $this->api->respond_error($error->getMessage(), 422);
        }
        $default_role = $this->Account_model->role_by_name('faculty_employee');
        $data['role_id'] = $this->positive_id($body['role_id'] ?? $default_role['id']);
        if ($data['role_id'] !== (int) $default_role['id'] && !in_array('role.manage', $actor['permissions'], TRUE)) {
            $this->api->respond_error('Assigning another role requires role.manage', 403);
        }
        if (!$this->Account_model->role_exists($data['role_id'])) {
            $this->api->respond_error('Role not found', 422);
        }
        $data['department_id'] = $this->positive_id($body['department_id'] ?? null);
        if (!$this->Account_model->active_department_exists($data['department_id'])) {
            $this->api->respond_error('An active department is required', 422);
        }
        if ($this->Account_model->identity_exists($data['username'], $data['email'])) {
            $this->api->respond_error('Username or email already exists', 409);
        }
        $this->db->transaction();
        // Serialize new assignments with department deactivation.
        if (!$this->Account_model->active_department_exists($data['department_id'], TRUE)) {
            $this->db->roll_back();
            $this->api->respond_error('An active department is required', 422);
        }
        $id = $this->Account_model->create_account($data);
        $this->Account_model->audit((int) $actor['id'], 'user.created', $id, [
            'role_id' => $data['role_id'], 'department_id' => $data['department_id'],
        ]);
        $this->db->commit();
        $this->api->respond(['success' => TRUE, 'data' => $this->Account_model->public_profile($this->Account_model->by_id($id))], 201);
    }

    public function roles()
    {
        $this->account_access->require_user();
        $this->api->respond(['success' => TRUE, 'data' => $this->Account_model->list_roles()]);
    }

    public function permissions()
    {
        $this->account_access->require_user('permission.manage');
        $this->api->respond(['success' => TRUE, 'data' => $this->Account_model->list_permissions()]);
    }

    private function positive_id($value): int
    {
        if (!is_int($value) && !is_string($value)) {
            $this->api->respond_error('A positive integer ID is required', 422);
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === FALSE) {
            $this->api->respond_error('A positive integer ID is required', 422);
        }
        return $id;
    }
}

