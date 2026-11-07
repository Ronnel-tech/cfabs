<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Create_asset_handovers {
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        $forge = $this->_lava->dbforge;
        if ($forge->table_exists('asset_handovers') || $forge->table_exists('asset_handover_items') || $forge->column_exists('asset_bookings', 'quantity_lost')) {
            throw new RuntimeException('Handover schema already exists; inspect before retrying.');
        }
        $this->_lava->db->raw('SET SESSION default_storage_engine = InnoDB');
        $forge->add_column('asset_bookings', ['quantity_lost' => ['type' => 'INT', 'unsigned' => TRUE, 'null' => FALSE, 'default' => 0]]);
        $id = ['type' => 'BIGINT', 'unsigned' => TRUE];
        $forge->add_field([
            'id' => $id + ['auto_increment' => TRUE], 'asset_booking_id' => $id,
            'actor_id' => ['type' => 'INT', 'unsigned' => TRUE],
            'direction' => ['type' => 'ENUM', 'constraint' => "'release','return','loss','inspection'"],
            'quantity' => ['type' => 'INT', 'unsigned' => TRUE],
            'condition_status' => ['type' => 'VARCHAR', 'constraint' => 25],
            'remarks' => ['type' => 'TEXT', 'null' => TRUE],
            'source_handover_id' => $id + ['null' => TRUE],
            'idempotency_key' => ['type' => 'VARCHAR', 'constraint' => 80],
            'request_hash' => ['type' => 'CHAR', 'constraint' => 64],
            'created_at' => ['type' => 'DATETIME', 'default' => 'CURRENT_TIMESTAMP'],
        ])->add_key('id', primary: TRUE)->add_key(['asset_booking_id', 'idempotency_key'], unique: TRUE)
            ->add_key(['asset_booking_id', 'direction', 'created_at'])
            ->add_foreign_key('asset_booking_id', 'asset_bookings', 'id', 'RESTRICT', 'RESTRICT')
            ->add_foreign_key('actor_id', 'users', 'id', 'RESTRICT', 'RESTRICT')
            ->add_foreign_key('source_handover_id', 'asset_handovers', 'id', 'RESTRICT', 'RESTRICT')
            ->create_table('asset_handovers', FALSE);
        $forge->add_field(['id' => $id + ['auto_increment' => TRUE], 'handover_id' => $id, 'asset_instance_id' => $id])
            ->add_key('id', primary: TRUE)->add_key(['handover_id', 'asset_instance_id'], unique: TRUE)
            ->add_foreign_key('handover_id', 'asset_handovers', 'id', 'RESTRICT', 'RESTRICT')
            ->add_foreign_key('asset_instance_id', 'asset_instances', 'id', 'RESTRICT', 'RESTRICT')
            ->create_table('asset_handover_items', FALSE);
        $this->_lava->db->raw('ALTER TABLE asset_handovers ADD CONSTRAINT cfabs_handover_quantity CHECK (quantity > 0)');
        $this->_lava->db->raw('ALTER TABLE asset_bookings ADD CONSTRAINT cfabs_settled_quantity CHECK (COALESCE(quantity_returned, 0) + quantity_lost <= COALESCE(quantity_released, 0))');
    }

    public function down()
    {
        $this->_lava->dbforge->drop_table('asset_handover_items');
        $this->_lava->dbforge->drop_table('asset_handovers');
        $this->_lava->db->raw('ALTER TABLE asset_bookings DROP CHECK cfabs_settled_quantity');
        $this->_lava->dbforge->drop_column('asset_bookings', 'quantity_lost');
    }
}
