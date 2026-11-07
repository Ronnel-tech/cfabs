<?php
require __DIR__ . '/support/http_database.php';
$users = []; $categories = []; $facility = null; $asset = null; $role = null; $complete = FALSE;
$day = (new DateTimeImmutable('+2 days', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
$start = $day . 'T08:00:00+08:00'; $end = $day . 'T17:00:00+08:00';
$left = $day . ' 01:00:00'; $right = $day . ' 02:00:00';
$params = ['start' => $start, 'end' => $end];
$tokens = [];
$get = function ($name, $filters = [], $status = 200) use (&$params, &$tokens) {
    return expect_status(request_api('GET', '/api/calendar/events?' . http_build_query(array_replace($params, $filters)), null, $tokens[$name] ?? null), $status, 'calendar ' . ($name ?: 'anonymous'))['data'] ?? null;
};
try {
    query($db, 'INSERT INTO departments (department_code,department_name) VALUES (?,?)', [$prefix, 'Calendar QA department']);
    $department = (int) $db->lastInsertId();
    $roles = query($db, 'SELECT role_name,id FROM roles')->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach (['owner'=>'faculty_employee','other'=>'faculty_employee','coordinator'=>'booking_admin','custodian'=>'asset_custodian','admin'=>'system_admin','none'=>null] as $name=>$role_name) {
        query($db, 'INSERT INTO users (username,email,password,role,is_active,department_id,role_id) VALUES (?,?,?,?,1,?,?)', [$prefix.'.'.$name,$prefix.'.'.$name.'@example.invalid',password_hash($password,PASSWORD_DEFAULT),'user',$department,$role_name ? $roles[$role_name] : null]);
        $users[$name] = (int) $db->lastInsertId();
        $tokens[$name] = expect_status(request_api('POST','/api/auth/login',['email'=>$prefix.'.'.$name.'@example.invalid','password'=>$password]),200,$name.' login')['data']['access_token'];
    }
    foreach (['facility_categories','asset_categories'] as $table) {
        query($db,"INSERT INTO {$table} (category_name) VALUES (?)",[$prefix]);
        $categories[$table] = (int) $db->lastInsertId();
    }
    query($db,'INSERT INTO facilities (facility_code,facility_name,category_id,capacity,created_by) VALUES (?,?,?,30,?)',[$prefix,'QA Calendar Hall',$categories['facility_categories'],$users['coordinator']]);
    $facility=(int)$db->lastInsertId();
    query($db,'INSERT INTO assets (asset_code,asset_name,category_id,owning_department_id,tracking_mode,total_quantity,usable_quantity,created_by) VALUES (?,?,?,?,?,10,10,?)',[$prefix,'QA Calendar Projectors',$categories['asset_categories'],$department,'quantity',$users['custodian']]);
    $asset=(int)$db->lastInsertId();
    $booking_ids=[];
    foreach (['PENDING','APPROVED','REJECTED','CANCELLED','COMPLETED'] as $status) {
        query($db,'INSERT INTO facility_bookings (booking_reference,requester_id,department_id,purpose,status,facility_id,start_datetime,end_datetime,activity_name,participants_count) VALUES (?,?,?,?,?,?,?,?,?,1)',[$prefix.'.'.substr($status,0,3),$users['owner'],$department,'Private fixture purpose',$status,$facility,$left,$right,'Private fixture activity']);
        $booking_ids[$status]=(int)$db->lastInsertId();
    }
    query($db,'INSERT INTO asset_bookings (booking_reference,requester_id,department_id,purpose,status,asset_id,usage_start,expected_return,quantity_requested) VALUES (?,?,?,?,?,?,?,?,3)',[$prefix.'.asset',$users['owner'],$department,'Private asset purpose','APPROVED',$asset,$left,$right]);
    query($db,'INSERT INTO facility_maintenance (facility_id,start_datetime,end_datetime,reason,created_by) VALUES (?,?,?,?,?)',[$facility,$day.' 03:00:00',$day.' 04:00:00','Private facility repair',$users['coordinator']]);
    query($db,'INSERT INTO asset_maintenance (asset_id,quantity_affected,start_datetime,reason,created_by) VALUES (?,1,?,?,?)',[$asset,$left,'Private asset repair',$users['custodian']]);
    $asset_maintenance=(int)$db->lastInsertId();
    $params += ['facility_id'=>$facility,'asset_id'=>$asset];
    $get('', [], 401);
    $get('none', [], 403);
    foreach ([['start'=>'2026-02-30T08:00:00+08:00'], ['start'=>'broken'], ['start'=>$end], ['end'=>'2030-12-01T00:00:00Z'], ['unknown'=>'x'], ['facility_id'=>['1']], ['asset_id'=>'0'], ['status'=>'bogus'], ['type'=>'bogus']] as $bad) $get('owner',$bad,422);
    $owner=$get('owner');
    check(count($owner['events'])===8,'owner sees own five facility bookings, asset booking, and two maintenance events');
    check($owner['timezone']==='Asia/Manila','campus timezone declared');
    $types=array_count_values(array_column($owner['events'],'type'));
    check(count($types)===4,'all four event categories included');
    $own_pending=array_values(array_filter($owner['events'],fn($e)=>($e['booking_id']??null)===$booking_ids['PENDING']))[0];
    check($own_pending['description']==='Private fixture purpose' && $own_pending['can_view_details'],'owner has own request details');
    check($own_pending['start']===$day.'T01:00:00Z' && $own_pending['end']===$day.'T02:00:00Z','campus offsets normalize to actual UTC instants');
    $other=$get('other');
    check(count($other['events'])===4,'other user sees committed bookings and maintenance only');
    foreach ($other['events'] as $event) {
        check(!$event['can_view_details'] && !array_intersect_key($event,array_flip(['booking_id','description','department_id','booking_reference'])), 'private fields absent');
        check(str_starts_with($event['id'],'busy-'),'shared event IDs opaque');
    }
    check(!str_contains(json_encode($other),'Private fixture') && !str_contains(json_encode($other),'Private asset'),'request and maintenance descriptions not leaked');
    check(count($get('other',['status'=>'PENDING'])['events'])===0,'pending status cannot expose another requester');
    check(count($get('coordinator',['type'=>'facility_booking'])['events'])===5,'coordinator sees all facility booking statuses');
    $admin=$get('admin',['type'=>'facility_booking']);
    check(count($admin['events'])===1 && !$admin['events'][0]['can_view_details'],'system admin does not inherit business booking view_all');
    $get('other',['type'=>'facility_booking','department_id'=>$department],403);
    $get('owner',['department_id'=>$department],422);
    check(count($get('coordinator',['type'=>'facility_booking','department_id'=>$department])['events'])===5,'authorized booking department filter');
    check(count($get('owner',['type'=>'facility_booking','status'=>'CANCELLED'])['events'])===1,'cancelled own booking is explicitly labelled');
    $get('owner',['type'=>'asset_maintenance','status'=>'APPROVED'],422);
    check(count($get('owner',['facility_category_id'=>$categories['facility_categories'],'asset_id'=>'999999999'])['events'])===6,'category and exact resource filters applied per kind');
    check(count($get('owner',['facility_category_id'=>'999999999','asset_category_id'=>'999999999'])['events'])===0,'unmatched categories return no events');
    $ongoing=array_values(array_filter($other['events'],fn($e)=>$e['type']==='asset_maintenance'))[0];
    check($ongoing['open_ended'] && $ongoing['scheduled_end']===null && $ongoing['end']===$day.'T09:00:00Z','open maintenance clipped to visible range without inventing an end');
    check(!isset($ongoing['description']),'public maintenance hides reason');
    $maintenance=$get('custodian',['type'=>'asset_maintenance']);
    check($maintenance['events'][0]['description']==='Private asset repair','custodian receives authorized repair detail');
    query($db,'UPDATE asset_maintenance SET expected_completion = DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR), start_datetime = DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 HOUR) WHERE id=?',[$asset_maintenance]);
    check($get('owner',['type'=>'asset_maintenance'])['events'][0]['open_ended'],'overdue maintenance remains ongoing');
    query($db,"UPDATE asset_maintenance SET status='completed',actual_completion=UTC_TIMESTAMP() WHERE id=?",[$asset_maintenance]);
    check(count($get('owner',['type'=>'asset_maintenance'])['events'])===0,'completed maintenance no longer occupies calendar');
    query($db,'INSERT INTO roles (role_name,is_system_role) VALUES (?,0)',[$prefix.'.only']);
    $role=(int)$db->lastInsertId();
    query($db,"INSERT INTO role_permissions (role_id,permission_id) SELECT ?,id FROM permissions WHERE permission_key='facility.view'",[$role]);
    query($db,'UPDATE users SET role_id=? WHERE id=?',[$role,$users['none']]);
    $only=$get('none');
    check(count(array_filter($only['events'],fn($e)=>$e['kind']==='asset'))===0,'default feed excludes unpermitted resource kind');
    $get('none',['type'=>'asset_booking'],403);
    query($db,'UPDATE users SET is_active=0 WHERE id=?',[$users['none']]);
    $get('none',[],401);
    // Boundaries use half-open intervals: a booking ending exactly at start is absent.
    $boundary=$get('owner',['type'=>'facility_booking','start'=>$day.'T10:00:00+08:00']);
    check(count($boundary['events'])===0,'back-to-back interval boundary excluded');
    $batch=[]; $values=[];
    for($i=0;$i<1001;$i++) {
        $batch[]='(?,?,?,?,?,?,?,?,?,1)';
        array_push($values,$prefix.'.c'.$i,$users['owner'],$department,'Capacity fixture','PENDING',$facility,$left,$right,'Capacity fixture');
    }
    query($db,'INSERT INTO facility_bookings (booking_reference,requester_id,department_id,purpose,status,facility_id,start_datetime,end_datetime,activity_name,participants_count) VALUES '.implode(',',$batch),$values);
    $get('owner',['type'=>'facility_booking'],422);
    query($db,'DELETE FROM facility_bookings WHERE requester_id=? AND booking_reference LIKE ?',[$users['owner'],$prefix.'.c%']);
    $complete=TRUE;
    echo "Passed {$checks} calendar HTTP and privacy checks.\n";
} finally {
    if ($complete && getenv('CFABS_KEEP_CALENDAR_FIXTURE')==='1') {
        file_put_contents($root.'/runtime/calendar-ui-fixture.json',json_encode(compact('prefix','users','categories','facility','asset','department','role','password','day'),JSON_THROW_ON_ERROR));
        echo "Kept exact test fixtures for browser QA; remove using their runtime manifest after verification.\n";
    } else {
        foreach ($users as $id) {
            query($db,'DELETE FROM facility_bookings WHERE requester_id=?',[$id]);
            query($db,'DELETE FROM asset_bookings WHERE requester_id=?',[$id]);
            query($db,'DELETE FROM refresh_tokens WHERE user_id=?',[$id]);
            query($db,'DELETE FROM audit_logs WHERE actor_user_id=?',[$id]);
        }
        if($facility!==null) { query($db,'DELETE FROM facility_maintenance WHERE facility_id=?',[$facility]); query($db,'DELETE FROM facilities WHERE id=?',[$facility]); }
        if($asset!==null) { query($db,'DELETE FROM asset_maintenance WHERE asset_id=?',[$asset]); query($db,'DELETE FROM assets WHERE id=?',[$asset]); }
        foreach($users as $id) query($db,'DELETE FROM users WHERE id=?',[$id]);
        foreach($categories as $table=>$id) query($db,"DELETE FROM {$table} WHERE id=?",[$id]);
        if($role!==null) { query($db,'DELETE FROM role_permissions WHERE role_id=?',[$role]); query($db,'DELETE FROM roles WHERE id=?',[$role]); }
        if($department!==null) query($db,'DELETE FROM departments WHERE id=?',[$department]);
    }
}
