<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Mail_service {
    private $lava;
    public function __construct()
    {
        $this->lava=lava_instance();
        $this->lava->call->model(['Mail_model','Account_model','Booking_model']);
        $this->lava->call->library(['mail_template','mail_configuration','reminder_service']);
    }
    public function context(array $n): ?array
    {
        $user=$this->lava->Account_model->by_id((int)$n['recipient_id']);
        if (!$user || !Mail_configuration::address($user['email'])) return null;
        $entity=$n['related_entity_type']??''; $type=$n['notification_type'];
        $record=$this->lava->Mail_model->source($entity,(int)($n['related_entity_id']??0));
        if (!$record || !$this->lava->mail_template->event($type)) return null;
        if ($entity==='user') {
            if ((int)$record['id']!==(int)$user['id'] || !str_starts_with($type,'account.')) return null;
            if (($type==='account.deactivated') !== ((int)$user['is_active']===0)) return null;
            return ['user'=>$user,'fields'=>['Current status'=>(int)$user['is_active']===1?'Active':'Inactive','Recorded'=>$this->local($n['created_at'])],'path'=>'/account'];
        }
        if ($entity==='asset_problem') {
            if ((int)$user['is_active']!==1 || !str_starts_with($type,'asset_problem.'))return null;
            $permissions=$this->lava->Account_model->permissions((int)$user['id']);
            $staff=in_array('asset.maintenance.manage',$permissions,TRUE);
            if ((int)$record['reporter_id']!==(int)$user['id'] && !($staff && (($user['role_name']??null)==='system_admin' || (int)$record['assigned_to']===(int)$user['id'])))return null;
            return ['user'=>$user,'fields'=>['Reference'=>'Asset problem report #'.$record['id'],'Current status'=>str_replace('_',' ',$record['status']),'Recorded'=>$this->local($n['created_at'])],'path'=>'/asset-problems/'.$record['id']];
        }
        if ((int)$user['is_active']!==1 || !preg_match('/^(facility|asset)_(booking|maintenance)$/D',$entity,$match)) return null;
        [$unused,$kind,$category]=$match;
        $permissions=$this->lava->Account_model->permissions((int)$user['id']);
        if ($category==='maintenance') {
            if (!in_array($kind.'.maintenance.manage',$permissions,TRUE)) return null;
            if (!str_starts_with($type,$kind.'_maintenance.') && $type!==$kind.'_maintenance_overdue') return null;
        } elseif ((int)$record['requester_id']!==(int)$user['id'] && !($type==='asset_overdue' && in_array('asset_booking.return',$permissions,TRUE))) return null;
        if (in_array($type,['facility_upcoming','asset_upcoming','asset_return_due','asset_overdue','facility_maintenance_overdue','asset_maintenance_overdue'],TRUE)) {
            $source=['kind'=>$kind,'maintenance'=>$category==='maintenance','id'=>(int)$record['id']];
            $event=$this->lava->reminder_service->event($source,$record,time(),168*3600);
            if (!$event || $event[0]!==$type) return null;
            $key=hash('sha256',json_encode([$entity,(int)$record['id'],$event[0],$event[1],(int)$user['id']],JSON_THROW_ON_ERROR));
            if (!hash_equals($key,(string)($n['dispatch_key']??''))) return null;
        }
        $resource=$this->lava->Booking_model->resource($kind,(int)$record[$kind.'_id']);
        if (!$resource) return null;
        $start=$record[$category==='maintenance'?'start_datetime':($kind==='facility'?'start_datetime':'usage_start')];
        $end=$record[$category==='maintenance'?($kind==='facility'?'end_datetime':'expected_completion'):($kind==='facility'?'end_datetime':'expected_return')];
        return ['user'=>$user,'fields'=>[
            'Resource'=>$resource[$kind.'_name'],
            'Reference'=>$record['booking_reference']??ucfirst($kind).' maintenance #'.$record['id'],
            'Start'=>$this->local($start),'End / expected return'=>$end===null?'Not scheduled':$this->local($end),
            'Current status'=>str_replace('_',' ',strtolower($record['status'])),'Recorded'=>$this->local($n['created_at']),
        ],'path'=>$category==='booking'?'/requests/'.$kind.'/'.$record['id']:'/maintenance/'.$kind];
    }
    public function payload(array $context, string $event, ?string $url=null): array
    {
        $user=$context['user'];
        $name=trim(($user['first_name']??'').' '.($user['last_name']??'')) ?: $user['username'];
        $url=$url??$this->lava->mail_configuration->app_url().$context['path'];
        return ['from'=>$this->lava->mail_configuration->sender(),'to'=>[$user['email']]]
            +$this->lava->mail_template->render($event,$name,$context['fields'],$url);
    }
    public function dispatch(string $mode,int $limit,?int $recipient=null,?Resend_transport $transport=null): array
    {
        if (!in_array($mode,['preview','queue','send'],TRUE) || $limit<1 || $limit>100) throw new DomainException('Choose preview, queue or send with a limit of 1–100.');
        if ($mode!=='preview') { $this->lava->mail_configuration->validate($mode==='send'); $this->lava->call->library('mail_cipher'); }
        $model=$this->lava->Mail_model;
        if (!$model->lock()) throw new DomainException('Another email worker is running.');
        $counts=['mode'=>$mode,'reset_requests_pending'=>0,'reset_queued'=>0,'candidates'=>0,'queued'=>0,'suppressed'=>0,'ready'=>0,'accepted'=>0,'retry'=>0,'failed'=>0,'skipped'=>0];
        try {
            $counts['reset_requests_pending']=$model->pending_requests();
            if ($mode!=='preview') {
                $this->lava->call->library('password_reset_service');
                $counts['reset_queued']=$this->lava->password_reset_service->prepare($limit,$recipient);
            }
            foreach($model->discover($limit,$recipient) as $n) {
                $counts['candidates']++;
                $context=$this->context($n);
                $skip=!$context?'recipient_or_event_ineligible':(strtotime($n['created_at'].' UTC')<time()-86400?'notification_expired':null);
                if ($skip!==null) $counts['suppressed']++;
                if ($mode==='preview') continue;
                $payload=$skip===null?$this->lava->mail_cipher->seal($this->payload($context,$n['notification_type'])):null;
                $model->queue((int)$n['id'],null,(int)$n['recipient_id'],$payload,$skip);
                $counts['queued']++;
            }
            $due=$model->due($limit,$recipient); $counts['ready']=count($due);
            if ($mode!=='send') return $counts;
            if ($transport===null) { $this->lava->call->library('resend_transport'); $transport=$this->lava->resend_transport; }
            foreach($due as $row) {
                $first=$row['first_attempt_at']===null?time():strtotime($row['first_attempt_at'].' UTC');
                if ((int)$row['attempts']>=5 || $first<=time()-23*3600) {
                    $model->finish((int)$row['id'],'failed','retry_window_exhausted'); $counts['failed']++; continue;
                }
                $reason=$this->delivery_ineligible($row);
                if ($reason!==null) { $model->finish((int)$row['id'],'skipped',$reason); $counts['skipped']++; continue; }
                try { $payload=$this->lava->mail_cipher->open($row['payload_encrypted']); }
                catch (Throwable $error) { $model->finish((int)$row['id'],'failed','payload_unreadable'); $counts['failed']++; continue; }
                $user=$this->lava->Account_model->by_id((int)$row['recipient_id']);
                if (!$user || ($payload['to'][0]??null)!==$user['email']) { $model->finish((int)$row['id'],'skipped','recipient_changed'); $counts['skipped']++; continue; }
                // Persist intent before network I/O. Crashes retry the same
                // encrypted payload and provider key inside Resend's 24h window.
                $model->begin_attempt((int)$row['id']);
                try { $result=$transport->send($payload,$row['delivery_key']); }
                catch (Throwable $error) { $result=['accepted'=>FALSE,'retryable'=>TRUE,'code'=>'transport_error','retry_after'=>0]; }
                if ($result['accepted']) { $model->finish((int)$row['id'],'accepted',null,$result['provider_id']); $counts['accepted']++; continue; }
                $delay=max([60,300,1800,7200,14400][min(4,(int)$row['attempts'])],(int)($result['retry_after']??0));
                $retry=$result['retryable'] && (int)$row['attempts']<4 && time()+$delay<$first+23*3600;
                $state=$retry?'retry':'failed';
                $model->finish((int)$row['id'],$state,$result['code'],null,$delay); $counts[$state]++;
                error_log('CFABS email delivery '.(int)$row['id'].': '.$result['code']);
            }
            return $counts;
        } finally { $model->unlock(); }
    }
    private function delivery_ineligible(array $row): ?string
    {
        if ($row['password_reset_id']!==null) {
            $reset=$this->lava->Mail_model->source('password_reset',(int)$row['password_reset_id']);
            $user=$this->lava->Account_model->by_id((int)$row['recipient_id']);
            return $reset && $user && (int)$user['is_active']===1 && $reset['used_at']===null
                && strtotime($reset['expires_at'].' UTC')>time() && $reset['email']===$user['email']?null:'reset_expired_or_revoked';
        }
        $n=$this->lava->Mail_model->notification((int)$row['notification_id']);
        if (!$n || strtotime($n['created_at'].' UTC')<time()-86400) return 'notification_expired';
        return $this->context($n)!==null?null:'recipient_or_event_ineligible';
    }
    private function local(string $utc): string
    {
        return (new DateTimeImmutable($utc,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'))->format('M j, Y g:i A').' (UTC+8)';
    }
}
