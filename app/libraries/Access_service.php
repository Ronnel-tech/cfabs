<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Access_service {
    private $lava;

    public function __construct()
    {
        $this->lava = lava_instance();
        $this->lava->call->model('Access_model');
        $this->lava->call->library('booking_rules');
    }

    public function save_role(?int $id, array $input, int $actor): array
    {
        if (!$input || array_diff(array_keys($input), ['role_name', 'description']) || ($id === null && !isset($input['role_name']))) {
            throw new DomainException('Provide role_name and optional description only.', 422);
        }
        $data = [];
        if (array_key_exists('role_name', $input)) {
            if (!is_string($input['role_name']) || !preg_match('/\A[a-z][a-z0-9_]{2,79}\z/', $input['role_name'])) {
                throw new DomainException('Role names must use 3–80 lowercase letters, digits or underscores, starting with a letter.', 422);
            }
            $data['role_name'] = $input['role_name'];
        }
        if (array_key_exists('description', $input)) {
            $data['description'] = $input['description'] === null ? null : $this->lava->booking_rules->text($input['description'], 255, 'description');
        }
        return $this->mutate($actor, ['role.manage'], function () use ($id, $data, $actor) {
            $before = $id === null ? null : $this->required_role($id);
            if ($before && $before['is_system_role'] && isset($data['role_name']) && $data['role_name'] !== $before['role_name']) {
                throw new DomainException('System role names cannot be changed.', 409);
            }
            if (isset($data['role_name']) && $this->lava->Access_model->role_name_exists($data['role_name'], $id ?? 0)) throw new DomainException('Role name already exists.', 409);
            $saved_id = $this->lava->Access_model->save_role($id, $data);
            $after = $this->lava->Access_model->role($saved_id);
            $this->lava->Access_model->audit('role', $saved_id, $actor, $id === null ? 'role.created' : 'role.updated', $before, $after);
            return $after;
        });
    }

    public function grants(int $id, array $body, int $actor): array
    {
        $keys = $body['permission_keys'] ?? null;
        if (array_keys($body) !== ['permission_keys'] || !is_array($keys) || !array_is_list($keys) || count($keys) > 200) {
            throw new DomainException('Provide a permission_keys list of at most 200 registered permissions.', 422);
        }
        foreach ($keys as $key) {
            if (!is_string($key) || strlen($key) > 120) throw new DomainException('Permission keys must be strings.', 422);
        }
        if (count(array_unique($keys)) !== count($keys)) throw new DomainException('Duplicate permission keys are not allowed.', 422);
        sort($keys);
        return $this->mutate($actor, ['role.manage', 'permission.manage'], function () use ($id, $keys, $actor) {
            $this->required_role($id);
            $registered = array_column($this->lava->Account_model->list_permissions(), 'permission_key');
            if (array_diff($keys, $registered)) throw new DomainException('Unknown permission key.', 422);
            $before = $this->lava->Access_model->grants($id);
            $this->lava->Access_model->replace_grants($id, $keys);
            $this->require_administrator();
            $this->lava->Access_model->audit('role', $id, $actor, 'role.permissions_changed', $before, $keys);
            return ['role_id' => $id, 'permission_keys' => $keys];
        });
    }

    public function account(int $id, string $operation, array $body, int $actor): array
    {
        $required = $operation === 'role' ? ['user.manage', 'role.manage'] : ($operation === 'profile' ? [] : ['user.manage']);
        $data = $this->account_fields($operation, $body);
        return $this->mutate($actor, $required, function () use ($id, $operation, $data, $actor) {
            $user = $this->lava->Account_model->by_id($id, TRUE);
            if (!$user) throw new DomainException('User not found.', 404);
            if ($operation === 'profile' && $id !== $actor && !in_array('user.manage', $this->lava->Account_model->permissions($actor), TRUE)) {
                throw new DomainException('Forbidden', 403);
            }
            if ($operation === 'role') $this->required_role($data['role_id']);
            if ($operation === 'department' && !$this->lava->Account_model->active_department_exists($data['department_id'], TRUE)) {
                throw new DomainException('An active department is required.', 422);
            }
            if ($operation === 'status') {
                if ($id === $actor) throw new DomainException('Use another administrator to change your account status.', 422);
                if ($data['is_active'] && $user['department_id'] !== null
                    && !$this->lava->Account_model->active_department_exists((int) $user['department_id'], TRUE)) {
                    throw new DomainException('Assign an active department before reactivating this account.', 422);
                }
                $this->lava->Account_model->update_status($id, $data['is_active']);
            } else {
                $this->lava->Access_model->change_account($id, $data);
            }
            if ($operation === 'role' || ($operation === 'status' && !$data['is_active'])) $this->require_administrator();
            $after = $this->lava->Account_model->by_id($id);
            $before_profile = $this->lava->Account_model->public_profile($user);
            $after_profile = $this->lava->Account_model->public_profile($after);
            $this->lava->Access_model->audit('user', $id, $actor, 'user.' . $operation . '_changed', $before_profile, $after_profile);
            if ($operation === 'status' && (int) $user['is_active'] !== (int) $after['is_active']) {
                $this->lava->call->model('Notification_model');
                $this->lava->Notification_model->account($id, (int) $after['is_active'] === 1 ? 'activated' : 'deactivated');
            }
            return $after_profile;
        });
    }

    private function account_fields(string $operation, array $body): array
    {
        if ($operation === 'profile') {
            if (!$body || array_diff(array_keys($body), ['first_name', 'last_name'])) throw new DomainException('Only first_name and last_name can be edited here.', 422);
            $result = [];
            foreach ($body as $field => $value) $result[$field] = $this->lava->booking_rules->text($value, 100, $field);
            return $result;
        }
        $field = ['role' => 'role_id', 'department' => 'department_id', 'status' => 'is_active'][$operation] ?? null;
        if ($field === null || array_keys($body) !== [$field]) throw new DomainException('Provide only the field for this account operation.', 422);
        if ($operation === 'status') {
            if (!is_bool($body[$field])) throw new DomainException('is_active must be a boolean.', 422);
            return [$field => $body[$field]];
        }
        return [$field => $this->lava->booking_rules->positive_id($body[$field])];
    }

    private function required_role(int $id): array
    {
        $role = $this->lava->Access_model->role($id, TRUE);
        if (!$role) throw new DomainException('Role not found.', 404);
        return $role;
    }

    private function require_administrator(): void
    {
        if ($this->lava->Access_model->administrator_count() < 1) {
            throw new DomainException('Keep at least one active account with user.manage, role.manage and permission.manage.', 409);
        }
    }

    private function mutate(int $actor, array $required, callable $action): array
    {
        if (!$this->lava->Access_model->acquire_lock()) throw new DomainException('Access administration is busy; try again.', 409);
        try {
            $this->lava->db->raw('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $this->lava->db->transaction();
            try {
                // Recheck after the lock so a waiting, demoted administrator cannot write.
                $current = $this->lava->Account_model->by_id($actor);
                if (!$current || (int) $current['is_active'] !== 1) throw new DomainException('Unauthorized', 401);
                if (array_diff($required, $this->lava->Account_model->permissions($actor))) throw new DomainException('Forbidden', 403);
                $result = $action();
                $this->lava->db->commit();
                return $result;
            } catch (Throwable $error) {
                $this->lava->db->roll_back();
                throw $error;
            }
        } finally {
            $this->lava->Access_model->release_lock();
        }
    }
}
