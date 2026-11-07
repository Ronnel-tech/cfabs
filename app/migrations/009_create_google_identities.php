<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Create_google_identities {
    private $lava;
    public function __construct()
    {
        $this->lava = lava_instance();
        $this->lava->call->dbforge();
    }
    public function up()
    {
        $forge = $this->lava->dbforge;
        foreach (['google_identities', 'google_challenges'] as $table) {
            if ($forge->table_exists($table)) throw new RuntimeException('Google table exists; inspect before retrying.');
        }
        $this->lava->db->raw('SET SESSION default_storage_engine = InnoDB');
        $forge->add_field([
            'user_id' => ['type' => 'INT', 'unsigned' => TRUE],
            'google_sub' => ['type' => 'VARCHAR', 'constraint' => 255],
            'google_email' => ['type' => 'VARCHAR', 'constraint' => 255],
            'linked_at' => ['type' => 'DATETIME', 'default' => 'CURRENT_TIMESTAMP'],
        ])->add_key('user_id', primary: TRUE)->add_key('google_sub', unique: TRUE)
            ->add_foreign_key('user_id', 'users', 'id', 'RESTRICT', 'RESTRICT')->create_table('google_identities', FALSE);
        $forge->add_field([
            'token_hash' => ['type' => 'CHAR', 'constraint' => 64],
            'nonce_hash' => ['type' => 'CHAR', 'constraint' => 64],
            'intent' => ['type' => 'ENUM', 'constraint' => "'login','link'"],
            'user_id' => ['type' => 'INT', 'unsigned' => TRUE, 'null' => TRUE],
            'expires_at' => ['type' => 'DATETIME'],
        ])->add_key('token_hash', primary: TRUE)->add_key('expires_at')
            ->add_foreign_key('user_id', 'users', 'id', 'RESTRICT', 'RESTRICT')->create_table('google_challenges', FALSE);
        // Google subjects are opaque, case-sensitive identifiers.
        $this->lava->db->raw('ALTER TABLE google_identities MODIFY google_sub VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL');
    }
    public function down()
    {
        $this->lava->dbforge->drop_table('google_challenges');
        $this->lava->dbforge->drop_table('google_identities');
    }
}
