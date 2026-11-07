<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Create_email_outbox {
    private $lava;
    public function __construct() { $this->lava = lava_instance(); $this->lava->call->dbforge(); }
    public function up()
    {
        $forge = $this->lava->dbforge;
        foreach (['email_deliveries','password_reset_tokens','password_reset_requests'] as $table) {
            if ($forge->table_exists($table)) throw new RuntimeException('Email schema exists; inspect before retrying.');
        }
        if ($forge->column_exists('notifications','email_eligible')) throw new RuntimeException('Email eligibility column exists; inspect before retrying.');
        if ($forge->column_exists('users','auth_version')) throw new RuntimeException('Session version column exists; inspect before retrying.');
        $this->lava->db->raw('SET SESSION default_storage_engine = InnoDB');
        $forge->add_field([
            'id'=>['type'=>'BIGINT','unsigned'=>TRUE,'auto_increment'=>TRUE],
            'email_encrypted'=>['type'=>'TEXT','null'=>TRUE],
            'processed_at'=>['type'=>'DATETIME','null'=>TRUE],
            'created_at'=>['type'=>'DATETIME','default'=>'CURRENT_TIMESTAMP'],
        ])->add_key('id',primary:TRUE)->add_key(['processed_at','id'])->create_table('password_reset_requests',FALSE);
        $forge->add_field([
            'id' => ['type'=>'BIGINT','unsigned'=>TRUE,'auto_increment'=>TRUE],
            'user_id' => ['type'=>'INT','unsigned'=>TRUE],
            'token_hash' => ['type'=>'CHAR','constraint'=>64],
            'email' => ['type'=>'VARCHAR','constraint'=>255],
            'expires_at' => ['type'=>'DATETIME'],
            'used_at' => ['type'=>'DATETIME','null'=>TRUE],
            'created_at' => ['type'=>'DATETIME','default'=>'CURRENT_TIMESTAMP'],
        ])->add_key('id',primary:TRUE)->add_key('token_hash',unique:TRUE)->add_key(['user_id','expires_at'])
            ->add_foreign_key('user_id','users','id','CASCADE','RESTRICT')->create_table('password_reset_tokens',FALSE);
        $forge->add_field([
            'id' => ['type'=>'BIGINT','unsigned'=>TRUE,'auto_increment'=>TRUE],
            'notification_id' => ['type'=>'BIGINT','unsigned'=>TRUE,'null'=>TRUE],
            'password_reset_id' => ['type'=>'BIGINT','unsigned'=>TRUE,'null'=>TRUE],
            'recipient_id' => ['type'=>'INT','unsigned'=>TRUE],
            'delivery_key' => ['type'=>'CHAR','constraint'=>64],
            'payload_encrypted' => ['type'=>'MEDIUMTEXT','null'=>TRUE],
            'status' => ['type'=>'VARCHAR','constraint'=>20,'default'=>'pending'],
            'attempts' => ['type'=>'TINYINT','unsigned'=>TRUE,'default'=>0],
            'next_attempt_at' => ['type'=>'DATETIME','default'=>'CURRENT_TIMESTAMP'],
            'first_attempt_at' => ['type'=>'DATETIME','null'=>TRUE],
            'provider_id' => ['type'=>'VARCHAR','constraint'=>80,'null'=>TRUE],
            'last_error_code' => ['type'=>'VARCHAR','constraint'=>80,'null'=>TRUE],
            'accepted_at' => ['type'=>'DATETIME','null'=>TRUE],
            'created_at' => ['type'=>'DATETIME','default'=>'CURRENT_TIMESTAMP'],
            'updated_at' => ['type'=>'DATETIME','default'=>'CURRENT_TIMESTAMP'],
        ])->add_key('id',primary:TRUE)->add_key('notification_id',unique:TRUE)->add_key('password_reset_id',unique:TRUE)
            ->add_key('delivery_key',unique:TRUE)->add_key(['status','next_attempt_at'])
            ->add_foreign_key('notification_id','notifications','id','CASCADE','RESTRICT')
            ->add_foreign_key('password_reset_id','password_reset_tokens','id','CASCADE','RESTRICT')
            ->add_foreign_key('recipient_id','users','id','RESTRICT','RESTRICT')->create_table('email_deliveries',FALSE);
        $this->lava->db->raw('ALTER TABLE email_deliveries ADD CONSTRAINT cfabs_mail_source CHECK ((notification_id IS NULL) <> (password_reset_id IS NULL))');
        // Historical notifications are not retroactively emailed. Only inserts
        // after this migration become eligible, including while sending is off.
        $forge->add_column('notifications',['email_eligible'=>['type'=>'TINYINT','unsigned'=>TRUE,'default'=>0]]);
        $this->lava->db->raw('ALTER TABLE notifications ALTER COLUMN email_eligible SET DEFAULT 1');
        $forge->add_column('users',['auth_version'=>['type'=>'INT','unsigned'=>TRUE,'default'=>0]]);
    }
    public function down()
    {
        $this->lava->dbforge->drop_table('email_deliveries');
        $this->lava->dbforge->drop_table('password_reset_tokens');
        $this->lava->dbforge->drop_table('password_reset_requests');
        $this->lava->dbforge->drop_column('notifications','email_eligible');
        $this->lava->dbforge->drop_column('users','auth_version');
    }
}
