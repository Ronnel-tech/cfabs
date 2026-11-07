<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Problem_report_model extends Model {
    private $connection;
    public function __construct() { parent::__construct(); $lava=lava_instance(); if(!isset($lava->properties['db']))$lava->call->database(); $this->connection=$lava->db; }
    public function find(int $id, bool $lock=FALSE): ?array
    {
        return $this->connection->raw('SELECT * FROM asset_problem_reports WHERE id=?'.($lock?' FOR UPDATE':''),[$id])->fetch(PDO::FETCH_ASSOC)?:null;
    }
    public function by_key(int $actor,string $key): ?array { return $this->connection->raw('SELECT * FROM asset_problem_reports WHERE reporter_id=? AND idempotency_key=?',[$actor,$key])->fetch(PDO::FETCH_ASSOC)?:null; }
    public function linked(int $maintenance): ?array { return $this->connection->raw('SELECT * FROM asset_problem_reports WHERE maintenance_id=?',[$maintenance])->fetch(PDO::FETCH_ASSOC)?:null; }
    public function insert(array $fields,?string $photo): array
    {
        $columns=implode(',',array_keys($fields));$marks=implode(',',array_fill(0,count($fields),'?'));
        try { $this->connection->raw("INSERT INTO asset_problem_reports ({$columns}) VALUES ({$marks})",array_values($fields)); }
        catch(PDOException $error) {
            if($error->getCode()!=='23000')throw $error;
            $old=$this->by_key((int)$fields['reporter_id'],$fields['idempotency_key']);
            if(!$old)throw $error;
            if(!hash_equals($old['request_hash'],$fields['request_hash']))throw new DomainException('Submission reference already used for another report.',409);
            return [(int)$old['id'],TRUE];
        }
        $id=(int)$this->connection->last_id();
        if($photo!==null)$this->connection->raw('INSERT INTO asset_problem_photos (report_id,jpeg) VALUES (?,?)',[$id,$photo]);
        return [$id,FALSE];
    }
    public function update(int $id,array $fields): void
    {
        $allowed=['assigned_to','maintenance_id','status','staff_notes','resolution','resolved_at'];
        if(!$fields || array_diff(array_keys($fields),$allowed))throw new InvalidArgumentException('Unknown report changes.');
        $set=implode(',',array_map(fn($key)=>$key.'=?',array_keys($fields)));
        $this->connection->raw("UPDATE asset_problem_reports SET {$set},version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=?",[...array_values($fields),$id]);
    }
    public function visible(int $id): ?array
    {
        return $this->connection->raw($this->select().' WHERE p.id=?',[$id])->fetch(PDO::FETCH_ASSOC)?:null;
    }
    private function select(): string
    {
        return 'SELECT p.id,p.asset_id,p.asset_instance_id,p.reporter_id,p.assigned_to,p.maintenance_id,p.description,p.quantity_affected,p.status,p.staff_notes,p.resolution,p.version,p.created_at,p.updated_at,p.resolved_at,
            a.asset_name,a.asset_code,i.asset_tag,u.username AS assigned_name,
            EXISTS(SELECT 1 FROM asset_problem_photos f WHERE f.report_id=p.id) AS has_photo
            FROM asset_problem_reports p JOIN assets a ON a.id=p.asset_id LEFT JOIN asset_instances i ON i.id=p.asset_instance_id LEFT JOIN users u ON u.id=p.assigned_to';
    }
    public function listing(array $actor,bool $admin,bool $staff,string $scope,?string $status,int $limit,int $offset): array
    {
        $values=[];$where=[];
        if($scope==='mine'){$where[]='p.reporter_id=?';$values[]=$actor['id'];}
        elseif($scope==='assigned'){$where[]='p.assigned_to=?';$values[]=$actor['id'];}
        elseif(!$admin)throw new DomainException('Forbidden',403);
        if(!$admin && !$staff && $scope!=='mine')throw new DomainException('Forbidden',403);
        if($status!==null){$where[]='p.status=?';$values[]=$status;}
        $sql=$where?' WHERE '.implode(' AND ',$where):'';
        return $this->connection->raw($this->select()."{$sql} ORDER BY p.id DESC LIMIT {$limit} OFFSET {$offset}",$values)->fetchAll(PDO::FETCH_ASSOC);
    }
    public function assignees(int $limit,int $offset): array
    {
        return $this->connection->raw("SELECT u.id,u.username,u.first_name,u.last_name FROM users u JOIN roles r ON r.id=u.role_id
            WHERE u.is_active=1 AND r.role_name='asset_custodian' AND EXISTS(SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=u.role_id AND p.permission_key='asset.maintenance.manage') ORDER BY u.id LIMIT {$limit} OFFSET {$offset}")->fetchAll(PDO::FETCH_ASSOC);
    }
    public function photo(int $id): ?string { return $this->connection->raw('SELECT jpeg FROM asset_problem_photos WHERE report_id=?',[$id])->fetchColumn()?:null; }
    public function audit(int $actor,int $id,string $event,array $details=[]): void
    {
        $this->connection->raw('INSERT INTO audit_logs (actor_user_id,action,entity_type,entity_id,new_values_json,created_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP())',[$actor,'asset_problem.'.$event,'asset_problem',$id,json_encode($details,JSON_THROW_ON_ERROR)]);
    }
    public function notify(array $report,string $event): void
    {
        $lava=lava_instance();$lava->call->model('Notification_model');
        $targets=[(int)$report['reporter_id']];
        if($report['assigned_to']!==null)$targets[]=(int)$report['assigned_to'];
        if($event==='submitted') {
            $admins=$this->connection->raw("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE u.is_active=1 AND r.role_name='system_admin'
                AND EXISTS(SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=u.role_id AND p.permission_key='asset.maintenance.manage')")->fetchAll(PDO::FETCH_COLUMN);
            $targets=array_merge($targets,array_map('intval',$admins));
        }
        foreach(array_unique($targets) as $target)$lava->Notification_model->record($target,'asset_problem.'.$event,'asset_problem',(int)$report['id'],'Asset problem report '.str_replace('_',' ',$event),'Asset problem report #'.$report['id'].': '.str_replace('_',' ',$event).'. Open asset problem reports for the current status.');
    }
}
