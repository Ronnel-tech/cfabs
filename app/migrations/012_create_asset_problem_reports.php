<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Create_asset_problem_reports {
    private $lava;
    public function __construct() { $this->lava = lava_instance(); $this->lava->call->dbforge(); }
    public function up()
    {
        $forge = $this->lava->dbforge;
        foreach (['asset_problem_reports','asset_problem_photos'] as $table) if ($forge->table_exists($table)) throw new RuntimeException('Problem report schema exists; inspect before retrying.');
        $this->lava->db->raw('SET SESSION default_storage_engine = InnoDB');
        $big = ['type'=>'BIGINT','unsigned'=>TRUE]; $user = ['type'=>'INT','unsigned'=>TRUE];
        $forge->add_field([
            'id'=>$big+['auto_increment'=>TRUE], 'asset_id'=>$big, 'asset_instance_id'=>$big+['null'=>TRUE],
            'reporter_id'=>$user, 'assigned_to'=>$user+['null'=>TRUE], 'maintenance_id'=>$big+['null'=>TRUE],
            'description'=>['type'=>'TEXT'], 'quantity_affected'=>['type'=>'INT','unsigned'=>TRUE],
            'status'=>['type'=>'ENUM','constraint'=>"'submitted','assigned','in_progress','resolved','rejected'",'default'=>'submitted'],
            'staff_notes'=>['type'=>'TEXT','null'=>TRUE], 'resolution'=>['type'=>'TEXT','null'=>TRUE],
            'version'=>['type'=>'INT','unsigned'=>TRUE,'default'=>1],
            'idempotency_key'=>['type'=>'VARCHAR','constraint'=>80], 'request_hash'=>['type'=>'CHAR','constraint'=>64],
            'created_at'=>['type'=>'DATETIME','default'=>'CURRENT_TIMESTAMP'],
            'updated_at'=>['type'=>'DATETIME','default'=>'CURRENT_TIMESTAMP'], 'resolved_at'=>['type'=>'DATETIME','null'=>TRUE],
        ])->add_key('id',primary:TRUE)->add_key(['reporter_id','idempotency_key'],unique:TRUE)
            ->add_key('maintenance_id',unique:TRUE)->add_key(['status','id'])->add_key(['assigned_to','id'])
            ->add_foreign_key('asset_id','assets','id','RESTRICT','RESTRICT')
            ->add_foreign_key('asset_instance_id','asset_instances','id','RESTRICT','RESTRICT')
            ->add_foreign_key('reporter_id','users','id','RESTRICT','RESTRICT')
            ->add_foreign_key('assigned_to','users','id','RESTRICT','RESTRICT')
            ->add_foreign_key('maintenance_id','asset_maintenance','id','RESTRICT','RESTRICT')->create_table('asset_problem_reports',FALSE);
        $forge->add_field(['report_id'=>$big, 'jpeg'=>['type'=>'MEDIUMBLOB']])->add_key('report_id',primary:TRUE)
            ->add_foreign_key('report_id','asset_problem_reports','id','CASCADE','RESTRICT')->create_table('asset_problem_photos',FALSE);
    }
    public function down() { $this->lava->dbforge->drop_table('asset_problem_photos'); $this->lava->dbforge->drop_table('asset_problem_reports'); }
}
