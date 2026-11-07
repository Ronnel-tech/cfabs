<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Add_notification_dispatch_key {
    private $_lava;
    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }
    public function up()
    {
        if ($this->_lava->dbforge->column_exists('notifications', 'dispatch_key')) throw new RuntimeException('Dispatch key already exists; inspect before retrying.');
        $this->_lava->dbforge->add_column('notifications', ['dispatch_key' => ['type' => 'CHAR', 'constraint' => 64, 'null' => TRUE, 'unique' => TRUE]]);
    }
    public function down()
    {
        $this->_lava->dbforge->drop_column('notifications', 'dispatch_key');
    }
}
