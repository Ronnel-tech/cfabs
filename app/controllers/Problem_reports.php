<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Problem_reports extends Controller {
    public function __construct() { parent::__construct();$this->call->library(['account_access','problem_report_service']);header('Cache-Control: no-store'); }
    public function index()
    {
        $actor=$this->account_access->require_user();
        $this->respond(function()use($actor){
            $q=$this->api->get_query_params();
            if(array_diff(array_keys($q),['scope','status','page','per_page']))throw new DomainException('Unsupported report filter.',422);
            $admin=$this->problem_report_service->admin($actor);$staff=$this->problem_report_service->staff($actor);
            $scope=$q['scope']??($admin?'all':'mine');$status=$q['status']??null;
            if(!in_array($scope,['mine','assigned','all'],TRUE) || ($status!==null && !in_array($status,['submitted','assigned','in_progress','resolved','rejected'],TRUE)))throw new DomainException('Invalid scope or status.',422);
            [$page,$limit]=$this->page($q);
            return $this->Problem_report_model->listing($actor,$admin,$staff,$scope,$status,$limit,($page-1)*$limit);
        });
    }
    public function show($id) { $actor=$this->account_access->require_user();$this->respond(fn()=>$this->problem_report_service->show($this->booking_rules->positive_id($id),$actor)); }
    public function create()
    {
        $actor=$this->account_access->require_user('asset.view');
        $this->api->rate_limit('asset-problem:'.$actor['id'],20,3600);
        $body=$this->account_access->json_body();
        $this->respond(fn()=>$this->problem_report_service->submit($body,(int)$actor['id']),201);
    }
    public function assignees()
    {
        $actor=$this->account_access->require_user('asset.maintenance.manage');
        $this->respond(function()use($actor){
            if(!$this->problem_report_service->admin($actor))throw new DomainException('Only system administrators may review and assign reports.',403);
            $q=$this->api->get_query_params();if(array_diff(array_keys($q),['page','per_page']))throw new DomainException('Unsupported assignee filter.',422);
            [$page,$limit]=$this->page($q);return $this->Problem_report_model->assignees($limit,($page-1)*$limit);
        });
    }
    public function change($id,$action)
    {
        $actor=$this->account_access->require_user();$body=$this->account_access->json_body();
        $this->respond(function()use($id,$action,$actor,$body){
            $id=$this->booking_rules->positive_id($id);
            if($action==='start')return $this->problem_report_service->start($id,$body,(int)$actor['id']);
            if($action==='resolve')return $this->problem_report_service->resolve($id,$body,(int)$actor['id']);
            return $this->problem_report_service->change($id,$action,$body,(int)$actor['id']);
        });
    }
    public function photo($id)
    {
        $actor=$this->account_access->require_user();
        $this->respond(function()use($id,$actor){
            $id=$this->booking_rules->positive_id($id);$this->problem_report_service->show($id,$actor);
            $bytes=$this->Problem_report_model->photo($id);
            if($bytes===null)throw new DomainException('Photo not found.',404);
            header('Content-Type: image/jpeg');header('Content-Disposition: inline; filename="asset-problem-'.$id.'.jpg"');
            header('X-Content-Type-Options: nosniff');header('Content-Length: '.strlen($bytes));echo $bytes;exit;
        });
    }
    private function page(array $q): array
    {
        $page=$this->booking_rules->positive_id($q['page']??1);$limit=$this->booking_rules->positive_id($q['per_page']??20);
        if($page>1000000 || $limit>100)throw new DomainException('Pagination limit exceeded.',422);return [$page,$limit];
    }
    private function respond(callable $work,int $status=200)
    {
        try{$data=$work();}catch(DomainException $error){$this->api->respond_error($error->getMessage(),$error->getCode()?:422);}
        $this->api->respond(['success'=>TRUE,'data'=>$data],$status);
    }
}
