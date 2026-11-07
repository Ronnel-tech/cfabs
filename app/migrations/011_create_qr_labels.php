<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Create_qr_labels {
    private $lava;

    public function __construct()
    {
        $this->lava = lava_instance();
        $this->lava->call->dbforge();
    }

    public function up()
    {
        $forge = $this->lava->dbforge;
        if ($forge->table_exists('qr_labels')) throw new RuntimeException('QR labels table exists; inspect before retrying.');
        $this->lava->db->raw('SET SESSION default_storage_engine = InnoDB');
        $forge->add_field([
            'id' => ['type' => 'BIGINT', 'unsigned' => TRUE, 'auto_increment' => TRUE],
            'identifier' => ['type' => 'CHAR', 'constraint' => 48],
            'asset_id' => ['type' => 'BIGINT', 'unsigned' => TRUE, 'null' => TRUE],
            'asset_instance_id' => ['type' => 'BIGINT', 'unsigned' => TRUE, 'null' => TRUE],
            'revoked_at' => ['type' => 'DATETIME', 'null' => TRUE],
            'created_at' => ['type' => 'DATETIME', 'default' => 'CURRENT_TIMESTAMP'],
            'updated_at' => ['type' => 'DATETIME', 'default' => 'CURRENT_TIMESTAMP'],
        ])->add_key('id', primary: TRUE)->add_key('identifier', unique: TRUE);
        foreach (['asset_id' => 'assets', 'asset_instance_id' => 'asset_instances'] as $column => $table) {
            $forge->add_key($column, unique: TRUE)->add_foreign_key($column, $table, 'id', 'CASCADE', 'RESTRICT');
        }
        $forge->create_table('qr_labels', FALSE);
        $this->lava->db->raw('ALTER TABLE qr_labels ADD CONSTRAINT chk_qr_one_resource CHECK
            ((asset_id IS NOT NULL) + (asset_instance_id IS NOT NULL) = 1)');
        $this->lava->db->raw('ALTER TABLE qr_labels MODIFY identifier CHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL');
        foreach (['asset_id' => 'assets', 'asset_instance_id' => 'asset_instances'] as $column => $table) {
            $where = $table === 'assets' ? " WHERE tracking_mode = 'quantity'" : '';
            $rows = $this->lava->db->raw("SELECT id FROM {$table}{$where}")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($rows as $id) $this->lava->db->raw("INSERT INTO qr_labels (identifier, {$column}) VALUES (?, ?)", [bin2hex(random_bytes(24)), $id]);
        }
    }

    public function down()
    {
        $this->lava->dbforge->drop_table('qr_labels');
    }
}
