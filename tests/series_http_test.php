<?php
require __DIR__ . '/support/http_database.php';
$users = []; $categories = []; $assets = []; $facility = null;
try {
    query($db, 'INSERT INTO departments (department_code, department_name) VALUES (?, ?)', [$prefix,'Series fixtures']); $department = (int) $db->lastInsertId();
    $roles = query($db, 'SELECT role_name,id FROM roles')->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach (['a'=>'faculty_employee','b'=>'faculty_employee','coordinator'=>'booking_admin','custodian'=>'asset_custodian'] as $name=>$role) {
        query($db, 'INSERT INTO users (username,email,password,role,is_active,role_id,department_id) VALUES (?,?,?,?,1,?,?)', [$prefix.'.'.$name,$prefix.'.'.$name.'@example.invalid',password_hash($password,PASSWORD_DEFAULT),'user',$roles[$role],$department]); $users[$name]=(int)$db->lastInsertId();
        $tokens[$name]=expect_status(request_api('POST','/api/auth/login',['email'=>$prefix.'.'.$name.'@example.invalid','password'=>$password]),200,$name.' login')['data']['access_token'];
    }
    foreach (['facility_categories','asset_categories'] as $table) { query($db,"INSERT INTO {$table} (category_name) VALUES (?)",[$prefix]); $categories[$table]=(int)$db->lastInsertId(); }
    query($db,'INSERT INTO facilities (facility_code,facility_name,category_id,capacity,created_by) VALUES (?,?,?,30,?)',[$prefix,'Series room',$categories['facility_categories'],$users['coordinator']]); $facility=(int)$db->lastInsertId();
    foreach (['quantity','individual'] as $mode) {
        query($db,'INSERT INTO assets (asset_code,asset_name,category_id,owning_department_id,tracking_mode,total_quantity,usable_quantity,created_by) VALUES (?,?,?,?,?,?,?,?)',[$prefix.'.'.$mode,'Series asset',$categories['asset_categories'],$department,$mode,$mode==='quantity'?10:0,$mode==='quantity'?10:0,$users['custodian']]); $assets[$mode]=(int)$db->lastInsertId();
    }
    query($db,'INSERT INTO asset_instances (asset_id,asset_tag) VALUES (?,?)',[$assets['individual'],$prefix]); $item=(int)$db->lastInsertId();
    $date=(new DateTimeImmutable('+1 day',new DateTimeZone('Asia/Manila')))->setTime(9,0);
    $common=['facility_id'=>$facility,'start_datetime'=>$date->format(DATE_ATOM),'end_datetime'=>$date->modify('+1 hour')->format(DATE_ATOM),'activity_name'=>'Weekly meeting','purpose'=>'Test recurrence','participants_count'=>10];
    $body=['booking'=>$common,'recurrence'=>['frequency'=>'weekly','count'=>3],'idempotency_key'=>bin2hex(random_bytes(16))];
    $first=expect_status(request_api('POST','/api/facility-booking-series',$body,$tokens['a']),201,'weekly series created')['data'];
    $id=(int)$first['series']['id'];
    check(count($first['bookings'])===3 && count(array_filter($first['bookings'],fn($b)=>$b['status']==='PENDING'))===3,'each date starts pending');
    check(!isset($first['series']['request_hash']) && !isset($first['series']['idempotency_key']),'internal series keys hidden');
    $replay=expect_status(request_api('POST','/api/facility-booking-series',$body,$tokens['a']),201,'series retry')['data'];
    check($replay['replayed']===TRUE && (int)$replay['series']['id']===$id,'retry reuses series');
    $changed=$body; $changed['recurrence']['count']=2;
    expect_status(request_api('POST','/api/facility-booking-series',$changed,$tokens['a']),409,'changed retry rejected');
    expect_status(request_api('GET','/api/booking-series/'.$id,null,$tokens['b']),403,'series ownership protected');
    expect_status(request_api('GET','/api/booking-series/'.$id,null,$tokens['coordinator']),200,'reviewer can view series');
    $before=(int)query($db,'SELECT COUNT(*) FROM facility_bookings WHERE requester_id=?',[$users['a']])->fetchColumn();
    $bad=$body; $bad['idempotency_key']=bin2hex(random_bytes(16)); $bad['recurrence']=['frequency'=>'weekly','count'=>2,'interval'=>4];
    $bad['booking']['start_datetime']=$date->modify('+9 days')->format(DATE_ATOM); $bad['booking']['end_datetime']=$date->modify('+9 days +1 hour')->format(DATE_ATOM);
    expect_status(request_api('POST','/api/facility-booking-series',$bad,$tokens['a']),422,'later occurrence exceeds advance window');
    check((int)query($db,'SELECT COUNT(*) FROM facility_bookings WHERE requester_id=?',[$users['a']])->fetchColumn()===$before,'failed later occurrence rolls back earlier dates');
    check((int)query($db,'SELECT COUNT(*) FROM booking_series WHERE requester_id=?',[$users['a']])->fetchColumn()===1,'failed batch leaves no group');
    check((int)query($db,'SELECT COUNT(*) FROM notifications WHERE recipient_id=?',[$users['a']])->fetchColumn()===3,'failed batch leaves no submission notifications');
    $blocked=$date->modify('+7 days')->setTimezone(new DateTimeZone('UTC'));
    query($db,"INSERT INTO facility_bookings (booking_reference,requester_id,department_id,facility_id,start_datetime,end_datetime,activity_name,purpose,participants_count,status) VALUES (?,?,?,?,?,?,'Conflict','Fixture',1,'APPROVED')",['SER-'.bin2hex(random_bytes(8)),$users['b'],$department,$facility,$blocked->format('Y-m-d H:i:s'),$blocked->modify('+1 hour')->format('Y-m-d H:i:s')]);
    $conflict=$body; $conflict['idempotency_key']=bin2hex(random_bytes(16));
    expect_status(request_api('POST','/api/facility-booking-series',$conflict,$tokens['a']),409,'unavailable later date rejects batch');
    check((int)query($db,'SELECT COUNT(*) FROM facility_bookings WHERE requester_id=?',[$users['a']])->fetchColumn()===$before,'conflict rollback preserves prior series only');
    expect_status(request_api('PATCH','/api/facility-bookings/'.$first['bookings'][0]['id'].'/approve',null,$tokens['coordinator']),200,'first occurrence approved independently');
    $view=expect_status(request_api('GET','/api/booking-series/'.$id,null,$tokens['a']),200,'series displays current occurrence status')['data'];
    check($view['bookings'][0]['status']==='APPROVED' && $view['bookings'][1]['status']==='PENDING','approval does not approve other dates');
    foreach (['quantity','individual'] as $mode) {
        $booking=['asset_id'=>$assets[$mode],'usage_start'=>$date->format(DATE_ATOM),'expected_return'=>$date->modify('+1 hour')->format(DATE_ATOM),'purpose'=>'Repeated asset request','quantity_requested'=>1];
        if($mode==='individual')$booking['instance_ids']=[$item];
        $asset_series=expect_status(request_api('POST','/api/asset-booking-series',['booking'=>$booking,'recurrence'=>['frequency'=>'daily','count'=>2],'idempotency_key'=>bin2hex(random_bytes(16))],$tokens['a']),201,$mode.' recurrence')['data'];
        check(count($asset_series['bookings'])===2,'asset dates materialized');
        if($mode==='individual')check($asset_series['bookings'][0]['instance_ids']===[$item] && $asset_series['bookings'][1]['instance_ids']===[$item],'requester item selection preserved per date');
    }
    $long=['asset_id'=>$assets['quantity'],'usage_start'=>$date->format(DATE_ATOM),'expected_return'=>$date->modify('+60 days')->format(DATE_ATOM),'purpose'=>'Long borrowing policy','quantity_requested'=>10];
    $loan=expect_status(request_api('POST','/api/asset-bookings',$long,$tokens['a']),201,'asset return may exceed the advance window')['data'];
    expect_status(request_api('PATCH','/api/asset-bookings/'.$loan['id'].'/approve',null,$tokens['custodian']),200,'long asset booking may be approved');
    $late=$date->modify('+20 days');
    $conflicting=$long; $conflicting['usage_start']=$late->format(DATE_ATOM); $conflicting['expected_return']=$late->modify('+1 hour')->format(DATE_ATOM); $conflicting['quantity_requested']=1;
    expect_status(request_api('POST','/api/asset-bookings',$conflicting,$tokens['b']),409,'long reservation blocks a later in-window request');
    $tail='?start='.rawurlencode($late->format(DATE_ATOM)).'&end='.rawurlencode($date->modify('+60 days')->format(DATE_ATOM));
    $availability=expect_status(request_api('GET','/api/assets/'.$assets['quantity'].'/availability'.$tail,null,$tokens['a']),200,'availability accepts an in-window start with a later return')['data'];
    $return=$date->modify('+60 days')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    check($availability['available_quantity']===0 && $availability['advance_booking_window']['within_limit']===TRUE && $loan['expected_return']===$return,'long reservation retains its full return date and blocks the requested interval');
    $too_late=$long; $too_late['usage_start']=$date->modify('+31 days')->format(DATE_ATOM);
    expect_status(request_api('POST','/api/asset-bookings',$too_late,$tokens['a']),422,'unlimited duration does not extend the start-date window');
    expect_status(request_api('POST','/api/facility-bookings',$common+['recurrence'=>['frequency'=>'weekly','count'=>2]],$tokens['a']),422,'single route cannot silently discard recurrence');
    echo "Passed {$checks} recurring-request checks.\n";
} finally {
    if($users){$ids=array_values($users);$marks=implode(',',array_fill(0,count($ids),'?'));
        query($db,"DELETE FROM notifications WHERE recipient_id IN ({$marks})",$ids);
        query($db,"DELETE FROM audit_logs WHERE actor_user_id IN ({$marks})",$ids);
        query($db,"DELETE FROM asset_booking_instances WHERE asset_booking_id IN (SELECT id FROM asset_bookings WHERE requester_id IN ({$marks}))",$ids);
        query($db,"DELETE FROM facility_bookings WHERE requester_id IN ({$marks})",$ids);
        query($db,"DELETE FROM asset_bookings WHERE requester_id IN ({$marks})",$ids);
        query($db,"DELETE FROM booking_series WHERE requester_id IN ({$marks})",$ids);
        if($facility!==null)query($db,'DELETE FROM facilities WHERE id=?',[$facility]);
        foreach($assets as $asset){query($db,'DELETE FROM asset_instances WHERE asset_id=?',[$asset]);query($db,'DELETE FROM assets WHERE id=?',[$asset]);}
        foreach($categories as $table=>$category)query($db,"DELETE FROM {$table} WHERE id=?",[$category]);
        query($db,"DELETE FROM refresh_tokens WHERE user_id IN ({$marks})",$ids);query($db,"DELETE FROM users WHERE id IN ({$marks})",$ids);
    }
    if($department!==null)query($db,'DELETE FROM departments WHERE id=?',[$department]);
}
