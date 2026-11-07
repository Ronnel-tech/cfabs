<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Create_resource_catalog {
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        // MySQL DDL is not transactional: reject collisions before creating anything.
        foreach ($this->tables() as $table) {
            if ($this->_lava->dbforge->table_exists($table)) {
                throw new RuntimeException("Table {$table} already exists; inspect the schema before retrying.");
            }
        }
        // DBForge does not expose a table-engine option.
        $this->_lava->db->raw('SET SESSION default_storage_engine = InnoDB');
        $this->create('departments', [
            'department_code' => $this->text(30),
            'department_name' => $this->text(150),
            'description' => ['type' => 'TEXT', 'null' => TRUE],
            'status' => $this->text(20, 'active'),
        ], [['department_code', TRUE]]);

        foreach (['facility_categories', 'asset_categories'] as $table) {
            $this->create($table, [
                'category_name' => $this->text(100),
                'description' => ['type' => 'TEXT', 'null' => TRUE],
                'status' => $this->text(20, 'active'),
            ], [['category_name', TRUE]]);
        }

        $this->create('facilities', [
            'facility_code' => $this->text(50),
            'facility_name' => $this->text(150),
            'category_id' => $this->id(),
            'building' => $this->text(120, null, TRUE),
            'location_details' => $this->text(255, null, TRUE),
            'description' => ['type' => 'TEXT', 'null' => TRUE],
            'capacity' => ['type' => 'INT', 'unsigned' => TRUE, 'null' => FALSE],
            'operating_hours_json' => ['type' => 'JSON', 'null' => TRUE],
            'status' => $this->text(25, 'active'),
            'image_path' => $this->text(255, null, TRUE),
            // Existing users.id is INT UNSIGNED; preserve its type for foreign keys.
            'created_by' => $this->user_id(),
        ], [['facility_code', TRUE], [['category_id', 'status'], FALSE]], [
            'category_id' => 'facility_categories', 'created_by' => 'users',
        ]);

        $this->create('assets', [
            'asset_code' => $this->text(60),
            'asset_name' => $this->text(150),
            'category_id' => $this->id(),
            'owning_department_id' => $this->id(),
            // Tracking is per asset record, allowing both modes within one category.
            'tracking_mode' => ['type' => 'ENUM', 'constraint' => "'quantity','individual'", 'null' => FALSE],
            'description' => ['type' => 'TEXT', 'null' => TRUE],
            // Pools use these counts; individual availability comes from instances.
            'total_quantity' => ['type' => 'INT', 'unsigned' => TRUE, 'null' => FALSE, 'default' => 0],
            'usable_quantity' => ['type' => 'INT', 'unsigned' => TRUE, 'null' => FALSE, 'default' => 0],
            'condition_status' => $this->text(25, 'good'),
            'location' => $this->text(180, null, TRUE),
            'custodian_id' => $this->user_id(TRUE),
            'status' => $this->text(25, 'active'),
            'created_by' => $this->user_id(),
        ], [['asset_code', TRUE], [['category_id', 'status'], FALSE], [['owning_department_id', 'status'], FALSE]], [
            'category_id' => 'asset_categories', 'owning_department_id' => 'departments',
            'custodian_id' => 'users', 'created_by' => 'users',
        ]);

        $this->create('asset_instances', [
            'asset_id' => $this->id(),
            'asset_tag' => $this->text(60),
            'serial_number' => $this->text(120, null, TRUE),
            'condition_status' => $this->text(25, 'good'),
            'status' => $this->text(25, 'active'),
            'location' => $this->text(180, null, TRUE),
        ], [['asset_tag', TRUE], ['serial_number', TRUE], [['asset_id', 'status'], FALSE]], [
            'asset_id' => 'assets',
        ]);

        // DBForge has no CHECK builder; keep this DDL inside the migration.
        $this->_lava->db->raw('ALTER TABLE facilities ADD CONSTRAINT cfabs_facility_capacity CHECK (capacity > 0)');
        $this->_lava->db->raw('ALTER TABLE assets ADD CONSTRAINT cfabs_asset_quantities CHECK (usable_quantity <= total_quantity)');
    }

    public function down()
    {
        // Destructive: only invoke an approved rollback against the intended database.
        foreach (array_reverse($this->tables()) as $table) {
            $this->_lava->dbforge->drop_table($table);
        }
    }

    private function tables(): array
    {
        return ['departments', 'facility_categories', 'asset_categories', 'facilities', 'assets', 'asset_instances'];
    }

    private function create(string $table, array $fields, array $indexes = [], array $references = []): void
    {
        // Fail on existing tables rather than silently accepting a different schema.
        $forge = $this->_lava->dbforge;
        $forge->add_field(['id' => $this->id() + ['auto_increment' => TRUE]] + $fields + [
            'created_at' => ['type' => 'DATETIME', 'null' => FALSE, 'default' => 'CURRENT_TIMESTAMP'],
            'updated_at' => ['type' => 'DATETIME', 'null' => FALSE, 'default' => 'CURRENT_TIMESTAMP'],
        ])->add_key('id', primary: TRUE);
        foreach ($indexes as [$columns, $unique]) {
            $forge->add_key($columns, unique: $unique);
        }
        foreach ($references as $column => $reference) {
            $forge->add_foreign_key($column, $reference, 'id', 'RESTRICT', 'RESTRICT');
        }
        $forge->create_table($table, FALSE);
    }

    private function id(): array
    {
        return ['type' => 'BIGINT', 'unsigned' => TRUE, 'null' => FALSE];
    }

    private function user_id(bool $nullable = FALSE): array
    {
        return ['type' => 'INT', 'unsigned' => TRUE, 'null' => $nullable];
    }

    private function text(int $length, ?string $default = null, bool $nullable = FALSE): array
    {
        $field = ['type' => 'VARCHAR', 'constraint' => $length, 'null' => $nullable];
        if ($default !== null) {
            $field['default'] = $default;
        }
        return $field;
    }
}
