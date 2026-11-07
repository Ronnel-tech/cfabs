<?php
require __DIR__.'/support/http_database.php';
$users=[];$assets=[];$reports=[];$category=null;$items=[];$maintenance=[];
function problem_post(int $id,string $action,array $body,string $token): array { return request_api('POST','/api/asset-problem-reports/'.$id.'/'.$action,$body,$token); }
function problem_photo_http(int $id,string $token): array {
    global $base;
    $bytes=file_get_contents($base.'/api/asset-problem-reports/'.$id.'/photo',FALSE,stream_context_create(['http'=>['header'=>'Authorization: Bearer '.$token,'ignore_errors'=>TRUE,'timeout'=>30]]));
    preg_match('/\s(\d{3})\s/',$http_response_header[0]??'',$m);return [(int)($m[1]??0),$bytes,implode("\n",$http_response_header)];
}
try {
    query($db,'INSERT INTO departments (department_code,department_name) VALUES (?,?)',[$prefix,'Problem report fixtures']);$department=(int)$db->lastInsertId();
    $roles=query($db,'SELECT role_name,id FROM roles')->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach(['admin'=>'system_admin','owner'=>'faculty_employee','other'=>'faculty_employee','staff'=>'asset_custodian','otherstaff'=>'asset_custodian'] as $name=>$role) {
        query($db,'INSERT INTO users (username,email,password,role,is_active,role_id,department_id) VALUES (?,?,?,?,1,?,?)',[$prefix.'.'.$name,$prefix.'.'.$name.'@example.invalid',password_hash($password,PASSWORD_DEFAULT),'user',$roles[$role],$department]);$users[$name]=(int)$db->lastInsertId();
        $tokens[$name]=expect_status(request_api('POST','/api/auth/login',['email'=>$prefix.'.'.$name.'@example.invalid','password'=>$password]),200,$name.' login')['data']['access_token'];
    }
    query($db,'INSERT INTO asset_categories (category_name) VALUES (?)',[$prefix]);$category=(int)$db->lastInsertId();
    foreach(['quantity','individual'] as $mode) {
        query($db,'INSERT INTO assets (asset_code,asset_name,category_id,owning_department_id,tracking_mode,total_quantity,usable_quantity,created_by) VALUES (?,?,?,?,?,?,?,?)',[$prefix.'.'.$mode,'Problem fixture '.$mode,$category,$department,$mode,$mode==='quantity'?10:0,$mode==='quantity'?10:0,$users['admin']]);$assets[$mode]=(int)$db->lastInsertId();
    }
    query($db,'INSERT INTO asset_instances (asset_id,asset_tag) VALUES (?,?)',[$assets['individual'],$prefix.'.item']);$item=(int)$db->lastInsertId();
    $qr=bin2hex(random_bytes(24));query($db,'INSERT INTO qr_labels (identifier,asset_id) VALUES (?,?)',[$qr,$assets['quantity']]);
    $image=imagecreatetruecolor(2,2);ob_start();imagepng($image);$png=ob_get_clean().'DROP_PRIVATE_METADATA';imagedestroy($image);
    $draft=['asset_id'=>$assets['quantity'],'quantity_affected'=>2,'description'=>'Private issue details: loose chair joints','photo'=>'data:image/png;base64,'.base64_encode($png),'qr_identifier'=>$qr,'idempotency_key'=>bin2hex(random_bytes(16))];
    expect_status(request_api('POST','/api/asset-problem-reports',$draft),401,'anonymous report denied');
    $row=expect_status(request_api('POST','/api/asset-problem-reports',$draft,$tokens['owner']),201,'report submitted')['data'];$id=(int)$row['id'];$reports[]=$id;
    check($row['status']==='submitted' && (int)$row['has_photo']===1,'submitted report has private photo');
    check(!isset($row['request_hash']) && !isset($row['idempotency_key']) && !isset($row['photo']),'response omits internal credentials and photo bytes');
    $again=expect_status(request_api('POST','/api/asset-problem-reports',$draft,$tokens['owner']),201,'submission retry')['data'];check((int)$again['id']===$id,'retry creates one report');
    expect_status(request_api('POST','/api/asset-problem-reports',array_replace($draft,['description'=>'Changed']),$tokens['owner']),409,'submission reference cannot change payload');
    check(query($db,'SELECT COUNT(*) FROM asset_maintenance WHERE asset_id=?',[$assets['quantity']])->fetchColumn()==0,'report does not create maintenance');
    expect_status(request_api('GET','/api/asset-problem-reports/'.$id,null,$tokens['other']),404,'other requester cannot view report');
    expect_status(request_api('GET','/api/asset-problem-reports/'.$id,null,$tokens['staff']),404,'unassigned custodian cannot read private report');
    check(problem_photo_http($id,$tokens['other'])[0]===404,'photo shares report authorization');
    [$code,$jpeg,$headers]=problem_photo_http($id,$tokens['owner']);check($code===200 && str_starts_with($jpeg,"\xff\xd8"),'PNG re-encoded to JPEG');
    check(!str_contains($jpeg,'DROP_PRIVATE_METADATA') && str_contains(strtolower($headers),'cache-control: no-store'),'photo metadata removed and response private');
    expect_status(request_api('GET','/api/asset-problem-reports?scope=all',null,$tokens['owner']),403,'requester cannot inspect global queue');
    expect_status(request_api('GET','/api/asset-problem-assignees',null,$tokens['staff']),403,'custodian cannot review assignee directory');
    $assign=['expected_version'=>(int)$row['version'],'assigned_to'=>$users['staff'],'notes'=>'Please inspect these chairs'];
    expect_status(problem_post($id,'assign',$assign,$tokens['staff']),403,'custodian cannot assign');
    expect_status(problem_post($id,'assign',array_replace($assign,['assigned_to'=>$users['owner']]),$tokens['admin']),422,'requester is not an eligible custodian');
    query($db,'UPDATE users SET is_active=0 WHERE id=?',[$users['staff']]);
    expect_status(problem_post($id,'assign',$assign,$tokens['admin']),422,'inactive custodian cannot be assigned');
    query($db,'UPDATE users SET is_active=1 WHERE id=?',[$users['staff']]);
    $row=expect_status(problem_post($id,'assign',$assign,$tokens['admin']),200,'administrator assigns work')['data'];
    expect_status(problem_post($id,'assign',$assign,$tokens['admin']),409,'stale version protected');
    expect_status(request_api('GET','/api/asset-problem-reports/'.$id,null,$tokens['staff']),200,'assigned custodian may read');
    $queue=expect_status(request_api('GET','/api/asset-problem-reports?scope=assigned',null,$tokens['staff']),200,'assigned queue')['data'];check(count($queue)===1 && (int)$queue[0]['id']===$id,'assigned queue scoped to current custodian');
    expect_status(problem_post($id,'start',['expected_version'=>(int)$row['version']],$tokens['otherstaff']),403,'unassigned custodian cannot start');
    check(query($db,'SELECT COUNT(*) FROM asset_maintenance WHERE asset_id=?',[$assets['quantity']])->fetchColumn()==0,'unauthorized start rolls back maintenance and notifications');
    $row=expect_status(problem_post($id,'start',['expected_version'=>(int)$row['version']],$tokens['staff']),200,'assigned custodian starts')['data'];$maintenance[]=(int)$row['maintenance_id'];
    check($row['status']==='in_progress' && $row['maintenance_id']!==null,'existing maintenance linked atomically');
    $range='?start='.rawurlencode(gmdate('Y-m-d\TH:i:s\Z',time()+60)).'&end='.rawurlencode(gmdate('Y-m-d\TH:i:s\Z',time()+3600));
    $availability=expect_status(request_api('GET','/api/assets/'.$assets['quantity'].'/availability'.$range,null,$tokens['owner']),200,'availability during report work')['data'];check($availability['available_quantity']===8,'maintenance reduces availability by affected quantity');
    expect_status(request_api('PATCH','/api/asset-maintenance/'.$row['maintenance_id'].'/complete',null,$tokens['otherstaff']),403,'direct maintenance endpoint cannot bypass assignment');
    $row=expect_status(problem_post($id,'progress',['expected_version'=>(int)$row['version'],'notes'=>'Replacement joints prepared'],$tokens['staff']),200,'progress recorded')['data'];check($row['staff_notes']==='Replacement joints prepared','progress visible to reporter');
    $row=expect_status(problem_post($id,'assign',['expected_version'=>(int)$row['version'],'assigned_to'=>$users['otherstaff'],'notes'=>'Continue repair'],$tokens['admin']),200,'administrator reassigns active work')['data'];
    expect_status(request_api('GET','/api/asset-problem-reports/'.$id,null,$tokens['staff']),404,'previous assignee loses private access');
    expect_status(problem_post($id,'resolve',['expected_version'=>(int)$row['version'],'notes'=>'Fixed'],$tokens['staff']),403,'previous assignee cannot resolve');
    $resolve=['expected_version'=>(int)$row['version'],'notes'=>'Joints replaced and tested'];
    $row=expect_status(problem_post($id,'resolve',$resolve,$tokens['otherstaff']),200,'assigned custodian resolves')['data'];check($row['status']==='resolved' && $row['resolution']===$resolve['notes'],'resolution synchronized with maintenance completion');
    expect_status(problem_post($id,'resolve',$resolve,$tokens['otherstaff']),200,'resolution retry recovers completed result');
    expect_status(problem_post($id,'resolve',$resolve,$tokens['staff']),403,'completed maintenance cannot expose report to old assignee');
    check(query($db,"SELECT COUNT(*) FROM audit_logs WHERE entity_type='asset_problem' AND entity_id=? AND action='asset_problem.resolved'",[$id])->fetchColumn()==1,'resolution audited once');
    check(query($db,'SELECT usable_quantity FROM assets WHERE id=?',[$assets['quantity']])->fetchColumn()==10,'resolution does not change baseline inventory');
    $make=function($extra=[])use($draft,$tokens){return request_api('POST','/api/asset-problem-reports',array_replace($draft,['photo'=>null,'idempotency_key'=>bin2hex(random_bytes(16))],$extra),$tokens['owner']);};
    expect_status($make(['reporter_id'=>$users['other']]),422,'reporter identity cannot be forged');
    expect_status($make(['photo'=>'data:image/svg+xml;base64,'.base64_encode('<svg/>')]),422,'SVG attachment rejected');
    expect_status($make(['quantity_affected'=>11]),422,'pool quantity bounded');
    expect_status($make(['asset_id'=>$assets['individual'],'asset_instance_id'=>$item,'quantity_affected'=>1]),422,'QR must match actual report resource');
    $single=expect_status($make(['asset_id'=>$assets['individual'],'asset_instance_id'=>$item,'quantity_affected'=>1,'qr_identifier'=>null]),422,'explicit null QR is invalid');
    $manual=$draft;unset($manual['qr_identifier']);$manual['photo']=null;$manual['asset_id']=$assets['individual'];$manual['asset_instance_id']=$item;$manual['quantity_affected']=1;$manual['idempotency_key']=bin2hex(random_bytes(16));
    $single=expect_status(request_api('POST','/api/asset-problem-reports',$manual,$tokens['owner']),201,'specific item report')['data'];$reports[]=(int)$single['id'];
    $single=expect_status(problem_post((int)$single['id'],'reject',['expected_version'=>(int)$single['version'],'notes'=>'Duplicate inspection already recorded'],$tokens['admin']),200,'administrator rejection with reason')['data'];check($single['status']==='rejected','report rejected');
    expect_status(problem_post((int)$single['id'],'assign',['expected_version'=>(int)$single['version'],'assigned_to'=>$users['staff'],'notes'=>'Again'],$tokens['admin']),409,'closed report cannot be reassigned');
    $third=expect_status($make(),201,'second pool issue')['data'];$thirdId=(int)$third['id'];$reports[]=$thirdId;
    $third=expect_status(problem_post($thirdId,'assign',['expected_version'=>(int)$third['version'],'assigned_to'=>$users['staff'],'notes'=>'Inspect'],$tokens['admin']),200,'assign cancellable work')['data'];
    $third=expect_status(problem_post($thirdId,'start',['expected_version'=>(int)$third['version']],$tokens['staff']),200,'start cancellable work')['data'];$maintenance[]=(int)$third['maintenance_id'];
    expect_status(request_api('PATCH','/api/asset-maintenance/'.$third['maintenance_id'].'/cancel',null,$tokens['staff']),200,'linked maintenance cancellation');
    $third=expect_status(request_api('GET','/api/asset-problem-reports/'.$thirdId,null,$tokens['owner']),200,'report after cancellation')['data'];check($third['status']==='assigned' && $third['maintenance_id']===null,'cancelled work remains assigned for follow-up');
    $third=expect_status(problem_post($thirdId,'start',['expected_version'=>(int)$third['version']],$tokens['staff']),200,'restart cancelled work')['data'];$maintenance[]=(int)$third['maintenance_id'];
    expect_status(request_api('PATCH','/api/asset-maintenance/'.$third['maintenance_id'].'/complete',null,$tokens['staff']),200,'existing maintenance completion syncs report');
    check(query($db,'SELECT status FROM asset_problem_reports WHERE id=?',[$thirdId])->fetchColumn()==='resolved','direct completion synchronizes status');
    require __DIR__.'/support/mail_runtime.php';$lava=lava_instance();
    $oldNotice=query($db,"SELECT * FROM notifications WHERE recipient_id=? AND related_entity_type='asset_problem' AND related_entity_id=? ORDER BY id LIMIT 1",[$users['staff'],$id])->fetch(PDO::FETCH_ASSOC);
    check($lava->mail_service->context($oldNotice)===null,'email rechecks revoked assignment');
    $notice=query($db,"SELECT * FROM notifications WHERE recipient_id=? AND related_entity_type='asset_problem' AND related_entity_id=? ORDER BY id DESC LIMIT 1",[$users['owner'],$id])->fetch(PDO::FETCH_ASSOC);
    $context=$lava->mail_service->context($notice);check($context!==null && !str_contains(json_encode($context['fields']),'Private issue'),'email contains no issue details or photo');
    query($db,'UPDATE users SET role_id=? WHERE id=?',[$roles['asset_custodian'],$users['admin']]);
    expect_status(request_api('GET','/api/asset-problem-reports?scope=all',null,$tokens['admin']),403,'demoted administrator loses queue access');
    echo "Passed {$checks} asset problem report checks.\n";
} finally {
    if($users) {
        $ids=array_values($users);$marks=implode(',',array_fill(0,count($ids),'?'));
        query($db,"DELETE FROM notifications WHERE related_entity_type='asset_problem' AND related_entity_id IN (SELECT id FROM asset_problem_reports WHERE reporter_id IN ({$marks}))",$ids);
        query($db,"DELETE FROM notifications WHERE related_entity_type='asset_maintenance' AND related_entity_id IN (SELECT id FROM asset_maintenance WHERE created_by IN ({$marks}))",$ids);
        query($db,"DELETE FROM notifications WHERE recipient_id IN ({$marks})",$ids);query($db,"DELETE FROM audit_logs WHERE actor_user_id IN ({$marks})",$ids);
        query($db,"DELETE FROM asset_problem_reports WHERE reporter_id IN ({$marks})",$ids);
        query($db,"DELETE FROM asset_maintenance WHERE created_by IN ({$marks})",$ids);
        foreach($assets as $asset){query($db,'DELETE FROM asset_instances WHERE asset_id=?',[$asset]);query($db,'DELETE FROM assets WHERE id=?',[$asset]);}
        if($category!==null)query($db,'DELETE FROM asset_categories WHERE id=?',[$category]);
        query($db,"DELETE FROM refresh_tokens WHERE user_id IN ({$marks})",$ids);query($db,"DELETE FROM users WHERE id IN ({$marks})",$ids);
    }
    if($department!==null)query($db,'DELETE FROM departments WHERE id=?',[$department]);
}
