<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Create_booking_series {
    private $_lava;
    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }
    public function up()
    {
        $forge = $this->_lava->dbforge;
        if ($forge->table_exists('booking_series')) throw new RuntimeException('Series table exists; inspect before retrying.');
        foreach (['facility_bookings','asset_bookings'] as $table) {
            if ($forge->column_exists($table, 'series_id')) throw new RuntimeException('Series column exists; inspect before retrying.');
        }
        $this->_lava->db->raw('SET SESSION default_storage_engine = InnoDB');
        $forge->add_field([
            'id' => ['type' => 'BIGINT', 'unsigned' => TRUE, 'auto_increment' => TRUE],
            'requester_id' => ['type' => 'INT', 'unsigned' => TRUE],
            'resource_kind' => ['type' => 'ENUM', 'constraint' => "'facility','asset'"],
            'frequency' => ['type' => 'ENUM', 'constraint' => "'daily','weekly'"],
            'interval_step' => ['type' => 'TINYINT', 'unsigned' => TRUE],
            'occurrence_count' => ['type' => 'TINYINT', 'unsigned' => TRUE],
            'timezone' => ['type' => 'VARCHAR', 'constraint' => 40],
            'idempotency_key' => ['type' => 'VARCHAR', 'constraint' => 80],
            'request_hash' => ['type' => 'CHAR', 'constraint' => 64],
            'created_at' => ['type' => 'DATETIME', 'default' => 'CURRENT_TIMESTAMP'],
        ])->add_key('id', primary: TRUE)->add_key(['requester_id','idempotency_key'], unique: TRUE)
            ->add_foreign_key('requester_id', 'users', 'id', 'RESTRICT', 'RESTRICT')->create_table('booking_series', FALSE);
        foreach (['facility','asset'] as $kind) {
            $forge->add_column($kind . '_bookings', ['series_id' => ['type' => 'BIGINT', 'unsigned' => TRUE, 'null' => TRUE]]);
            $this->_lava->db->raw("ALTER TABLE {$kind}_bookings ADD CONSTRAINT cfabs_{$kind}_series FOREIGN KEY (series_id) REFERENCES booking_series(id) ON DELETE RESTRICT");
        }
        $this->_lava->db->raw('ALTER TABLE booking_series ADD CONSTRAINT cfabs_series_count CHECK (occurrence_count BETWEEN 2 AND 30), ADD CONSTRAINT cfabs_series_interval CHECK (interval_step BETWEEN 1 AND 4)');
    }
    public function down()
    {
        foreach (['facility','asset'] as $kind) {
            $this->_lava->db->raw("ALTER TABLE {$kind}_bookings DROP FOREIGN KEY cfabs_{$kind}_series");
            $this->_lava->dbforge->drop_column($kind . '_bookings', 'series_id');
        }
        $this->_lava->dbforge->drop_table('booking_series');
    }
}
