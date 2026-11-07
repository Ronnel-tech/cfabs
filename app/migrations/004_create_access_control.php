<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Create_access_control {
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        $forge = $this->_lava->dbforge;
        foreach (['roles', 'permissions', 'role_permissions', 'audit_logs'] as $table) {
            if ($forge->table_exists($table)) {
                throw new RuntimeException("Table {$table} already exists; inspect before retrying.");
            }
        }
        foreach (['role_id', 'department_id', 'first_name', 'last_name', 'last_login_at'] as $column) {
            if ($forge->column_exists('users', $column)) {
                throw new RuntimeException("Column users.{$column} already exists; inspect before retrying.");
            }
        }
        $this->_lava->db->raw('SET SESSION default_storage_engine = InnoDB');
        $id = ['type' => 'BIGINT', 'unsigned' => TRUE, 'null' => FALSE];
        $timestamp = ['type' => 'DATETIME', 'null' => FALSE, 'default' => 'CURRENT_TIMESTAMP'];
        $forge->add_field([
            'id' => $id + ['auto_increment' => TRUE],
            'role_name' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => FALSE],
            'description' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => TRUE],
            'is_system_role' => ['type' => 'BOOLEAN', 'default' => TRUE],
            'created_at' => $timestamp, 'updated_at' => $timestamp,
        ])->add_key('id', primary: TRUE)->add_key('role_name', unique: TRUE)->create_table('roles', FALSE);
        $forge->add_field([
            'id' => $id + ['auto_increment' => TRUE],
            'permission_key' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => FALSE],
            'description' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => TRUE],
        ])->add_key('id', primary: TRUE)->add_key('permission_key', unique: TRUE)->create_table('permissions', FALSE);
        $forge->add_field([
            'id' => $id + ['auto_increment' => TRUE], 'role_id' => $id, 'permission_id' => $id,
        ])->add_key('id', primary: TRUE)->add_key(['role_id', 'permission_id'], unique: TRUE)
            ->add_foreign_key('role_id', 'roles', 'id', 'CASCADE', 'RESTRICT')
            ->add_foreign_key('permission_id', 'permissions', 'id', 'CASCADE', 'RESTRICT')
            ->create_table('role_permissions', FALSE);

        $forge->add_column('users', [
            'role_id' => array_replace($id, ['null' => TRUE]),
            'department_id' => array_replace($id, ['null' => TRUE]),
            'first_name' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => TRUE],
            'last_name' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => TRUE],
            'last_login_at' => ['type' => 'DATETIME', 'null' => TRUE],
        ]);
        // Preserve password, role and is_active for the native API library.
        $this->_lava->db->raw('ALTER TABLE users
            ADD CONSTRAINT cfabs_users_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT,
            ADD CONSTRAINT cfabs_users_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE RESTRICT');
        $forge->add_field([
            'id' => $id + ['auto_increment' => TRUE],
            'actor_user_id' => ['type' => 'INT', 'unsigned' => TRUE, 'null' => TRUE],
            'action' => ['type' => 'VARCHAR', 'constraint' => 100],
            'entity_type' => ['type' => 'VARCHAR', 'constraint' => 80],
            'entity_id' => array_replace($id, ['null' => TRUE]),
            'old_values_json' => ['type' => 'JSON', 'null' => TRUE],
            'new_values_json' => ['type' => 'JSON', 'null' => TRUE],
            'ip_address' => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => TRUE],
            'user_agent' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => TRUE],
            'created_at' => $timestamp,
        ])->add_key('id', primary: TRUE)->add_key(['actor_user_id', 'created_at'])
            ->add_key(['entity_type', 'entity_id', 'created_at'])->add_key(['action', 'created_at'])
            ->add_foreign_key('actor_user_id', 'users', 'id', 'RESTRICT', 'RESTRICT')
            ->create_table('audit_logs', FALSE);

        $shared = ['facility.view', 'facility_booking.create', 'asset.view', 'asset_booking.create'];
        $facility = ['facility.create', 'facility.update', 'facility.deactivate', 'facility.maintenance.manage',
            'facility_booking.view_all', 'facility_booking.approve', 'facility_booking.reject',
            'facility_booking.cancel_any', 'facility.category.manage', 'facility.policy.manage', 'report.facility.view'];
        $asset = ['asset.create', 'asset.update', 'asset.deactivate', 'asset.maintenance.manage',
            'asset_booking.view_all', 'asset_booking.approve', 'asset_booking.reject', 'asset_booking.cancel_any',
            'asset_booking.release', 'asset_booking.return', 'asset.condition.manage', 'asset.category.manage',
            'asset.policy.manage', 'report.asset.view'];
        // System administrators have management permissions, not business approval by implication.
        $admin = ['user.manage', 'role.manage', 'permission.manage', 'department.manage', 'audit.view',
            'facility.create', 'facility.update', 'facility.deactivate', 'facility.maintenance.manage',
            'facility.category.manage', 'facility.policy.manage', 'report.facility.view',
            'asset.create', 'asset.update', 'asset.deactivate', 'asset.maintenance.manage',
            'asset.category.manage', 'asset.policy.manage', 'report.asset.view'];
        $grants = [
            'faculty_employee' => $shared,
            'booking_admin' => array_merge($shared, $facility),
            'asset_custodian' => array_merge($shared, $asset),
            'system_admin' => array_merge($shared, $admin),
        ];
        foreach (array_unique(array_merge(...array_values($grants))) as $permission) {
            $this->_lava->db->raw('INSERT INTO permissions (permission_key) VALUES (?)', [$permission]);
        }
        foreach ($grants as $role => $permissions) {
            $this->_lava->db->raw('INSERT INTO roles (role_name, is_system_role) VALUES (?, 1)', [$role]);
            foreach ($permissions as $permission) {
                $this->_lava->db->raw('INSERT INTO role_permissions (role_id, permission_id)
                    SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.role_name = ? AND p.permission_key = ?',
                    [$role, $permission]);
            }
        }
    }

    public function down()
    {
        // Destructive; requires explicit approval before invoking a rollback.
        $this->_lava->dbforge->drop_table('audit_logs');
        $this->_lava->db->raw('ALTER TABLE users DROP FOREIGN KEY cfabs_users_role, DROP FOREIGN KEY cfabs_users_department');
        foreach (['last_login_at', 'last_name', 'first_name', 'department_id', 'role_id'] as $column) {
            $this->_lava->dbforge->drop_column('users', $column);
        }
        foreach (['role_permissions', 'permissions', 'roles'] as $table) {
            $this->_lava->dbforge->drop_table($table);
        }
    }
}
