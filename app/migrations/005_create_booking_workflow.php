<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Create_booking_workflow {
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        foreach ($this->tables() as $table) {
            if ($this->_lava->dbforge->table_exists($table)) {
                throw new RuntimeException("Table {$table} already exists; inspect before retrying.");
            }
        }
        $this->_lava->db->raw('SET SESSION default_storage_engine = InnoDB');
        $common = [
            'booking_reference' => $this->text(30), 'requester_id' => $this->user(),
            'department_id' => $this->id(), 'purpose' => ['type' => 'TEXT'],
            'status' => $this->text(30, 'PENDING'), 'approver_id' => $this->user(TRUE),
            'approval_remarks' => ['type' => 'TEXT', 'null' => TRUE],
            'rejection_reason' => ['type' => 'TEXT', 'null' => TRUE],
            'rejection_source' => ['type' => 'ENUM', 'constraint' => "'manual','automatic'", 'null' => TRUE],
            'submitted_at' => $this->time(), 'approved_at' => $this->time(TRUE),
            'rejected_at' => $this->time(TRUE), 'cancelled_at' => $this->time(TRUE),
            'completed_at' => $this->time(TRUE), 'cancellation_reason' => ['type' => 'TEXT', 'null' => TRUE],
        ];
        $references = ['requester_id' => 'users', 'department_id' => 'departments', 'approver_id' => 'users'];
        $this->create('facility_bookings', $common + [
            'facility_id' => $this->id(), 'start_datetime' => $this->time(), 'end_datetime' => $this->time(),
            'activity_name' => $this->text(180), 'participants_count' => ['type' => 'INT', 'unsigned' => TRUE],
        ], $references + ['facility_id' => 'facilities'], [
            ['facility_id', 'start_datetime', 'end_datetime', 'status'], ['requester_id', 'status', 'submitted_at'],
            ['department_id', 'submitted_at'], ['status', 'start_datetime'],
        ], ['booking_reference']);
        $this->create('asset_bookings', $common + [
            'asset_id' => $this->id(), 'usage_start' => $this->time(), 'expected_return' => $this->time(),
            'quantity_requested' => ['type' => 'INT', 'unsigned' => TRUE],
            'released_by' => $this->user(TRUE), 'released_at' => $this->time(TRUE),
            'quantity_released' => ['type' => 'INT', 'unsigned' => TRUE, 'null' => TRUE],
            'received_by' => $this->user(TRUE), 'returned_at' => $this->time(TRUE),
            'quantity_returned' => ['type' => 'INT', 'unsigned' => TRUE, 'null' => TRUE],
            'release_condition' => $this->text(25, null, TRUE), 'return_condition' => $this->text(25, null, TRUE),
            'return_remarks' => ['type' => 'TEXT', 'null' => TRUE],
        ], $references + ['asset_id' => 'assets', 'released_by' => 'users', 'received_by' => 'users'], [
            ['asset_id', 'usage_start', 'expected_return', 'status'], ['requester_id', 'status', 'submitted_at'],
            ['status', 'expected_return'],
        ], ['booking_reference']);
        $this->create('asset_booking_instances', [
            'asset_booking_id' => $this->id(), 'asset_instance_id' => $this->id(),
        ], ['asset_booking_id' => 'asset_bookings', 'asset_instance_id' => 'asset_instances'], [], [
            ['asset_booking_id', 'asset_instance_id'],
        ]);
        $this->create('facility_maintenance', [
            'facility_id' => $this->id(), 'start_datetime' => $this->time(), 'end_datetime' => $this->time(),
            'reason' => ['type' => 'TEXT'], 'status' => $this->text(20, 'scheduled'),
            'created_by' => $this->user(), 'completed_by' => $this->user(TRUE), 'completed_at' => $this->time(TRUE),
        ], ['facility_id' => 'facilities', 'created_by' => 'users', 'completed_by' => 'users'], [
            ['facility_id', 'start_datetime', 'end_datetime', 'status'],
        ]);
        $this->create('asset_maintenance', [
            'asset_id' => $this->id(), 'asset_instance_id' => array_replace($this->id(), ['null' => TRUE]),
            'quantity_affected' => ['type' => 'INT', 'unsigned' => TRUE],
            'start_datetime' => $this->time(), 'expected_completion' => $this->time(TRUE),
            'actual_completion' => $this->time(TRUE), 'reason' => ['type' => 'TEXT'],
            'status' => $this->text(20, 'scheduled'), 'created_by' => $this->user(), 'completed_by' => $this->user(TRUE),
        ], ['asset_id' => 'assets', 'asset_instance_id' => 'asset_instances', 'created_by' => 'users', 'completed_by' => 'users'], [
            ['asset_id', 'status', 'start_datetime'],
        ]);
        $this->create('notifications', [
            'recipient_id' => $this->user(), 'title' => $this->text(180), 'message' => ['type' => 'TEXT'],
            'notification_type' => $this->text(40), 'related_entity_type' => $this->text(40, null, TRUE),
            'related_entity_id' => array_replace($this->id(), ['null' => TRUE]),
            'is_read' => ['type' => 'BOOLEAN', 'default' => FALSE], 'read_at' => $this->time(TRUE),
        ], ['recipient_id' => 'users'], [['recipient_id', 'is_read', 'created_at']]);
        foreach ([
            'ALTER TABLE facility_bookings ADD CONSTRAINT cfabs_facility_interval CHECK (start_datetime < end_datetime), ADD CONSTRAINT cfabs_participants CHECK (participants_count > 0)',
            'ALTER TABLE asset_bookings ADD CONSTRAINT cfabs_asset_interval CHECK (usage_start < expected_return), ADD CONSTRAINT cfabs_requested_quantity CHECK (quantity_requested > 0), ADD CONSTRAINT cfabs_release_quantity CHECK (quantity_released <= quantity_requested), ADD CONSTRAINT cfabs_return_quantity CHECK (quantity_returned <= quantity_released)',
            'ALTER TABLE facility_maintenance ADD CONSTRAINT cfabs_facility_maintenance_interval CHECK (start_datetime < end_datetime)',
            'ALTER TABLE asset_maintenance ADD CONSTRAINT cfabs_maintenance_quantity CHECK (quantity_affected > 0), ADD CONSTRAINT cfabs_asset_maintenance_interval CHECK (expected_completion IS NULL OR start_datetime < expected_completion)',
        ] as $sql) {
            $this->_lava->db->raw($sql);
        }
    }

    public function down()
    {
        foreach (array_reverse($this->tables()) as $table) {
            $this->_lava->dbforge->drop_table($table);
        }
    }

    private function tables(): array
    {
        return ['facility_bookings', 'asset_bookings', 'asset_booking_instances', 'facility_maintenance', 'asset_maintenance', 'notifications'];
    }

    private function create(string $table, array $fields, array $references, array $indexes = [], array $unique = []): void
    {
        $forge = $this->_lava->dbforge;
        $forge->add_field(['id' => $this->id() + ['auto_increment' => TRUE]] + $fields + [
            'created_at' => $this->time(), 'updated_at' => $this->time(),
        ])->add_key('id', primary: TRUE);
        foreach ($indexes as $columns) $forge->add_key($columns);
        foreach ($unique as $columns) $forge->add_key($columns, unique: TRUE);
        foreach ($references as $field => $reference) $forge->add_foreign_key($field, $reference, 'id', 'RESTRICT', 'RESTRICT');
        $forge->create_table($table, FALSE);
    }

    private function id(): array { return ['type' => 'BIGINT', 'unsigned' => TRUE]; }
    private function user(bool $nullable = FALSE): array { return ['type' => 'INT', 'unsigned' => TRUE, 'null' => $nullable]; }
    private function time(bool $nullable = FALSE): array
    {
        return $nullable ? ['type' => 'DATETIME', 'null' => TRUE] : ['type' => 'DATETIME', 'default' => 'CURRENT_TIMESTAMP'];
    }
    private function text(int $length, ?string $default = null, bool $nullable = FALSE): array
    {
        $field = ['type' => 'VARCHAR', 'constraint' => $length, 'null' => $nullable];
        if ($default !== null) $field['default'] = $default;
        return $field;
    }
}
