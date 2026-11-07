<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Problem_report_service {
    private $lava;
    public function __construct()
    {
        $this->lava=lava_instance();$this->lava->call->library(['booking_service','problem_photo']);
        $this->lava->call->model(['Problem_report_model','Catalog_model']);
    }
    public function admin(array $actor): bool { return ($actor['role_name']??null)==='system_admin' && in_array('asset.maintenance.manage',$actor['permissions'],TRUE); }
    public function staff(array $actor): bool { return in_array('asset.maintenance.manage',$actor['permissions'],TRUE); }
    private function actor(int $id): array
    {
        $actor=$this->lava->Account_model->by_id($id);
        if(!$actor || (int)$actor['is_active']!==1)throw new DomainException('Unauthorized',401);
        $actor['permissions']=$this->lava->Account_model->permissions($id);return $actor;
    }
    public function show(int $id,array $actor): array
    {
        $row=$this->lava->Problem_report_model->visible($id);
        if(!$row || !$this->can_read($row,$actor))throw new DomainException('Problem report not found.',404);
        return $row;
    }
    private function can_read(array $row,array $actor): bool
    {
        return (int)$row['reporter_id']===(int)$actor['id'] || $this->admin($actor)
            || ($this->staff($actor) && (int)$row['assigned_to']===(int)$actor['id']);
    }
    private function work(array $row,array $actor): void
    {
        if(!$this->admin($actor) && !($this->staff($actor) && (int)$row['assigned_to']===(int)$actor['id']))throw new DomainException('Only the assigned custodian or an authorized system administrator may update this work.',403);
    }
    private function version(array $row,array $body): void
    {
        if(!is_int($body['expected_version']??null) || $body['expected_version']!==(int)$row['version'])throw new DomainException('Report changed; refresh before trying again.',409);
    }
    public function submit(array $body,int $actor): array
    {
        if(array_diff(array_keys($body),['asset_id','asset_instance_id','quantity_affected','description','photo','qr_identifier','idempotency_key']))throw new DomainException('Unsupported problem report fields.',422);
        $rules=$this->lava->booking_rules;$asset=$rules->positive_id($body['asset_id']??null);
        $item=isset($body['asset_instance_id'])?$rules->positive_id($body['asset_instance_id']):null;
        $quantity=$rules->positive_id($body['quantity_affected']??null);$description=$rules->text($body['description']??null,4000,'description');
        $key=$body['idempotency_key']??null;
        if(!is_string($key)||!preg_match('/\A[A-Za-z0-9_-]{16,80}\z/',$key))throw new DomainException('Provide a unique 16–80 character idempotency_key.',422);
        $photo=$this->lava->problem_photo->decode($body['photo']??null);
        $qr=$body['qr_identifier']??null;
        if(array_key_exists('qr_identifier',$body)){$this->lava->call->library('qr_service');$qr=$this->lava->qr_service->identifier($qr);}
        $hash=hash('sha256',json_encode([$asset,$item,$quantity,$description,$photo===null?null:hash('sha256',$photo),$qr],JSON_THROW_ON_ERROR));
        return $this->atomic($asset,$actor,function($record,$user)use($asset,$item,$quantity,$description,$key,$photo,$qr,$hash,$actor){
            if(!in_array('asset.view',$user['permissions'],TRUE))throw new DomainException('Forbidden',403);
            $model=$this->lava->Problem_report_model;$old=$model->by_key($actor,$key);
            if($old){if(!hash_equals($old['request_hash'],$hash))throw new DomainException('Submission reference already used for another report.',409);return $model->visible((int)$old['id']);}
            if($record['status']!=='active')throw new DomainException('Asset is inactive.',422);
            if($record['tracking_mode']==='individual') {
                $instance=$item===null?null:$this->lava->Catalog_model->find_record('asset_instances',$item,TRUE);
                if(!$instance || (int)$instance['asset_id']!==$asset || $instance['status']!=='active' || $quantity!==1)throw new DomainException('Select one active tagged item belonging to this asset.',422);
            }elseif($item!==null || $quantity>(int)$record['total_quantity'])throw new DomainException('Affected quantity must fit the registered pool and cannot include an item ID.',422);
            if($qr!==null)$this->lava->qr_service->verify_handover([$qr],$record,$item===null?[]:[$item]);
            [$id,$replay]=$model->insert(['asset_id'=>$asset,'asset_instance_id'=>$item,'reporter_id'=>$actor,'description'=>$description,'quantity_affected'=>$quantity,'idempotency_key'=>$key,'request_hash'=>$hash],$photo);
            if(!$replay){$model->audit($actor,$id,'submitted',['asset_id'=>$asset,'asset_instance_id'=>$item,'quantity_affected'=>$quantity,'has_photo'=>$photo!==null]);$model->notify($model->find($id),'submitted');}
            return $model->visible($id);
        });
    }
    public function change(int $id,string $action,array $body,int $actor): array
    {
        $allowed=$action==='assign'?['expected_version','assigned_to','notes']:['expected_version','notes'];
        if(!in_array($action,['assign','reject','progress'],TRUE) || array_diff(array_keys($body),$allowed))throw new DomainException('Unsupported report action or fields.',422);
        $initial=$this->lava->Problem_report_model->find($id);
        if(!$initial)throw new DomainException('Problem report not found.',404);
        $notes=$this->lava->booking_rules->text($body['notes']??null,4000,'notes');
        return $this->atomic((int)$initial['asset_id'],$actor,function($asset,$user)use($id,$body,$actor,$action,$notes){
            $model=$this->lava->Problem_report_model;$row=$model->find($id,TRUE);
            if($action==='progress'){$this->work($row,$user);if($row['status']!=='in_progress')throw new DomainException('Start assigned work before recording progress.',409);}
            elseif(!$this->admin($user))throw new DomainException('Only system administrators may review and assign reports.',403);
            $this->version($row,$body);
            if(in_array($row['status'],['resolved','rejected'],TRUE))throw new DomainException('Report is already closed.',409);
            $fields=['staff_notes'=>$notes];
            if($action==='assign') {
                $assignee=$this->lava->Account_model->by_id($this->lava->booking_rules->positive_id($body['assigned_to']??null));
                if(!$assignee || (int)$assignee['is_active']!==1 || $assignee['role_name']!=='asset_custodian' || !in_array('asset.maintenance.manage',$this->lava->Account_model->permissions((int)$assignee['id']),TRUE))throw new DomainException('Choose an active asset custodian with maintenance permission.',422);
                $fields+=['assigned_to'=>(int)$assignee['id'],'status'=>$row['status']==='in_progress'?'in_progress':'assigned'];
            }elseif($action==='reject') {
                if($row['maintenance_id']!==null)throw new DomainException('Cancel linked maintenance before rejecting the report.',409);
                $fields['status']='rejected';
            }
            $model->update($id,$fields);$after=$model->find($id);
            $model->audit($actor,$id,$action,['version'=>(int)$after['version'],'assigned_to'=>$after['assigned_to'],'status'=>$after['status']]);
            $model->notify($after,$action==='assign'?'assigned':($action==='reject'?'rejected':'progress'));
            return $model->visible($id);
        });
    }
    public function start(int $id,array $body,int $actor): array
    {
        if(array_diff(array_keys($body),['expected_version','expected_completion']))throw new DomainException('Provide expected_version and optional expected_completion only.',422);
        $model=$this->lava->Problem_report_model;$initial=$model->find($id);
        if(!$initial)throw new DomainException('Problem report not found.',404);
        $fields=['asset_id'=>(int)$initial['asset_id'],'start_datetime'=>gmdate('Y-m-d\TH:i:s\Z'),'reason'=>'Asset problem report #'.$id,
            'quantity_affected'=>(int)$initial['quantity_affected']];
        if($initial['asset_instance_id']!==null)$fields['asset_instance_id']=(int)$initial['asset_instance_id'];
        if(isset($body['expected_completion']))$fields['expected_completion']=$body['expected_completion'];
        $this->lava->call->library('maintenance_service');
        $this->lava->maintenance_service->create('asset',$fields,$actor,function($maintenance)use($model,$id,$body,$actor){
            $row=$model->find($id,TRUE);$this->work($row,$this->actor($actor));$this->version($row,$body);
            if($row['status']!=='assigned' || $row['maintenance_id']!==null)throw new DomainException('Only assigned reports without active work may be started.',409);
            $model->update($id,['status'=>'in_progress','maintenance_id'=>(int)$maintenance['id']]);
            $model->audit($actor,$id,'started',['maintenance_id'=>(int)$maintenance['id']]);$model->notify($model->find($id),'started');
        });
        return $model->visible($id);
    }
    public function resolve(int $id,array $body,int $actor): array
    {
        if(array_diff(array_keys($body),['expected_version','notes']))throw new DomainException('Provide expected_version and notes only.',422);
        $notes=$this->lava->booking_rules->text($body['notes']??null,4000,'notes');
        $row=$this->lava->Problem_report_model->find($id);
        if(!$row)throw new DomainException('Problem report not found.',404);
        $this->work($row,$this->actor($actor));
        if($row['status']==='resolved') {
            if($row['resolution']!==$notes)throw new DomainException('Report was already resolved with different notes.',409);
            return $this->lava->Problem_report_model->visible($id);
        }
        if($row['maintenance_id']===null)throw new DomainException('No linked work is in progress.',409);
        $this->lava->call->library('maintenance_service');
        $this->lava->maintenance_service->close('asset',(int)$row['maintenance_id'],$actor,'completed',function()use($id,$body,$actor,$notes){
            $current=$this->lava->Problem_report_model->find($id,TRUE);$this->work($current,$this->actor($actor));$this->version($current,$body);
            $this->lava->Problem_report_model->update($id,['resolution'=>$notes]);
        });
        return $this->lava->Problem_report_model->visible($id);
    }
    /** Called while the existing maintenance transaction owns the asset lock. */
    public function maintenance_close(int $maintenance,int $actor,string $status): void
    {
        $model=$this->lava->Problem_report_model;$initial=$model->linked($maintenance);
        if(!$initial)return;
        $row=$model->find((int)$initial['id'],TRUE);$this->work($row,$this->actor($actor));
        if($row['status']!=='in_progress')throw new DomainException('Linked problem report is not in progress.',409);
        $fields=$status==='completed'?['status'=>'resolved','resolved_at'=>gmdate('Y-m-d H:i:s'),'resolution'=>$row['resolution']??'Linked maintenance completed.']
            :['status'=>'assigned','maintenance_id'=>null];
        $model->update((int)$row['id'],$fields);$after=$model->find((int)$row['id']);
        $model->audit($actor,(int)$row['id'],$status==='completed'?'resolved':'work_cancelled',['maintenance_id'=>$maintenance]);
        $model->notify($after,$status==='completed'?'resolved':'work_cancelled');
    }
    private function atomic(int $asset,int $actor,callable $work): array
    {
        $this->lava->db->raw('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->lava->db->transaction();
        try {
            $record=$this->lava->Booking_model->resource('asset',$asset,TRUE);
            if(!$record)throw new DomainException('Asset not found.',404);
            $result=$work($record,$this->actor($actor));$this->lava->db->commit();return $result;
        }catch(Throwable $error){$this->lava->db->roll_back();throw $error;}
    }
}
