<?php
require __DIR__ . '/support/http_database.php';
$users = []; $assets = []; $category = null; $facility_category = null; $facility = null;
function export_http(string $path, ?string $token): array {
    global $base;
    $headers = 'Accept: */*' . "\r\n" . ($token ? 'Authorization: Bearer ' . $token . "\r\n" : '');
    $bytes = file_get_contents($base . $path, FALSE, stream_context_create(['http' => ['header' => $headers, 'ignore_errors' => TRUE, 'timeout' => 60]]));
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $status);
    return [(int) ($status[1] ?? 0), $bytes, implode("\n", $http_response_header)];
}
try {
    query($db, 'INSERT INTO departments (department_code,department_name) VALUES (?,?)', [$prefix, 'Export fixtures']); $department = (int) $db->lastInsertId();
    $roles = query($db, 'SELECT role_name,id FROM roles')->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach (['admin' => 'system_admin', 'custodian' => 'asset_custodian', 'coordinator' => 'booking_admin'] as $name => $role) {
        query($db, 'INSERT INTO users (username,email,password,role,is_active,role_id,department_id) VALUES (?,?,?,?,1,?,?)', [$prefix . '.' . $name, $prefix . '.' . $name . '@example.invalid', password_hash($password,PASSWORD_DEFAULT), 'user', $roles[$role], $department]);
        $users[$name] = (int) $db->lastInsertId();
        $tokens[$name] = expect_status(request_api('POST','/api/auth/login',['email'=>$prefix.'.'.$name.'@example.invalid','password'=>$password]),200,$name.' login')['data']['access_token'];
    }
    query($db, 'INSERT INTO asset_categories (category_name) VALUES (?)', [$prefix]); $category = (int) $db->lastInsertId();
    query($db, 'INSERT INTO facility_categories (category_name) VALUES (?)', [$prefix]); $facility_category = (int) $db->lastInsertId();
    query($db, 'INSERT INTO facilities (facility_code,facility_name,category_id,capacity,created_by) VALUES (?,?,?,10,?)', [$prefix, 'Export hall', $facility_category, $users['admin']]); $facility = (int) $db->lastInsertId();
    foreach (['first','second'] as $name) {
        query($db, "INSERT INTO assets (asset_code,asset_name,category_id,owning_department_id,tracking_mode,total_quantity,usable_quantity,created_by) VALUES (?,?,?,?,'quantity',10,8,?)", [$prefix.'.'.$name,'=Export projector '.$name,$category,$department,$users['admin']]); $assets[$name] = (int) $db->lastInsertId();
    }
    foreach (['facility','asset'] as $kind) {
        if ($kind === 'facility') query($db, "INSERT INTO facility_bookings (booking_reference,requester_id,department_id,facility_id,start_datetime,end_datetime,activity_name,purpose,participants_count,status) VALUES (?,?,?,?,UTC_TIMESTAMP()-INTERVAL 2 HOUR,UTC_TIMESTAMP()-INTERVAL 1 HOUR,'Fixture','Private purpose',2,'COMPLETED')", [$prefix.'.f',$users['admin'],$department,$facility]);
        else query($db, "INSERT INTO asset_bookings (booking_reference,requester_id,department_id,asset_id,usage_start,expected_return,quantity_requested,purpose,status) VALUES (?,?,?,?,UTC_TIMESTAMP()-INTERVAL 2 HOUR,UTC_TIMESTAMP()-INTERVAL 1 HOUR,1,'Private purpose','COMPLETED')", [$prefix.'.a',$users['admin'],$department,$assets['first']]);
    }
    foreach (['xlsx','pdf'] as $format) {
        foreach (['facilities','assets','inventory'] as $kind) {
            $id = $kind === 'facilities' ? $facility : $assets['first'];
            $path = '/api/reports/'.$kind.'?resource_id='.$id.'&format='.$format;
            check(export_http($path,null)[0] === 401, 'anonymous '.$kind.' '.$format.' denied');
            $other = $kind === 'facilities' ? 'coordinator' : 'custodian';
            check(export_http($path,$tokens[$other])[0] === 403, 'report grant without system admin cannot export '.$kind.' '.$format);
            [$status,$bytes,$headers] = export_http($path,$tokens['admin']);
            check($status === 200 && str_starts_with($bytes,$format === 'pdf' ? '%PDF-' : 'PK'), 'admin real '.$kind.' '.$format.' download');
            check(str_contains(strtolower($headers),'content-disposition: attachment;') && str_contains($headers,'.'.$format.'"'), 'safe attachment filename');
            check(str_contains(strtolower($headers),'cache-control: no-store') && str_contains(strtolower($headers),'x-content-type-options: nosniff'), 'private export headers');
            if ($kind === 'inventory' && $format === 'xlsx') {
                $file = tempnam(sys_get_temp_dir(),'cfabs-export-');
                try {
                    file_put_contents($file,$bytes); require_once $root.'/vendor/autoload.php';
                    $book = (new \PhpOffice\PhpSpreadsheet\Reader\Xlsx())->load($file); $sheet = $book->getActiveSheet();
                    check($sheet->getHighestRow() === 8 && (int)$sheet->getCell('A8')->getValue() === $id, 'resource filter excludes other asset');
                    check($sheet->getCell('C8')->getDataType() === 's' && str_starts_with($sheet->getCell('C8')->getValue(),'=Export'), 'API export never activates formulas');
                    $book->disconnectWorksheets();
                } finally { unlink($file); }
            }
        }
    }
    expect_status(request_api('GET','/api/reports/inventory?format=xlsx&page=1',null,$tokens['admin']),422,'document pagination rejected');
    expect_status(request_api('GET','/api/reports/inventory?format=exe',null,$tokens['admin']),422,'unknown format rejected');
    expect_status(request_api('GET','/api/reports/assets?format=pdf&start=invalid&end=invalid',null,$tokens['admin']),422,'invalid period rejected');
    check(export_http('/api/reports/inventory?resource_id='.$assets['first'].'&format=csv',$tokens['custodian'])[0] === 200,'existing custodian CSV access preserved');
    $audit = query($db,"SELECT new_values_json FROM audit_logs WHERE actor_user_id=? AND action='report.exported'",[$users['admin']])->fetchAll(PDO::FETCH_COLUMN);
    check(count($audit) === 6, 'each generated admin document audited once');
    foreach ($audit as $json) { $details = json_decode($json,TRUE); check($details['resource_count'] === 1 && !str_contains($json,'Private purpose'), 'audit keeps metadata only'); }
    query($db,'UPDATE users SET role_id=? WHERE id=?',[$roles['asset_custodian'],$users['admin']]);
    check(export_http('/api/reports/inventory?format=pdf',$tokens['admin'])[0] === 403, 'role demotion enforced against existing access token');
    query($db,'UPDATE users SET role_id=?,is_active=0 WHERE id=?',[$roles['system_admin'],$users['admin']]);
    check(export_http('/api/reports/inventory?format=xlsx',$tokens['admin'])[0] === 401, 'inactive administrator blocked');
    echo "Passed {$checks} admin export HTTP checks.\n";
} finally {
    if ($users) {
        $ids=array_values($users);$marks=implode(',',array_fill(0,count($ids),'?'));
        query($db,"DELETE FROM notifications WHERE recipient_id IN ({$marks})",$ids);
        query($db,"DELETE FROM audit_logs WHERE actor_user_id IN ({$marks})",$ids);
        query($db,"DELETE FROM facility_bookings WHERE requester_id IN ({$marks})",$ids);
        query($db,"DELETE FROM asset_bookings WHERE requester_id IN ({$marks})",$ids);
        if($facility!==null)query($db,'DELETE FROM facilities WHERE id=?',[$facility]);
        if($facility_category!==null)query($db,'DELETE FROM facility_categories WHERE id=?',[$facility_category]);
        foreach($assets as $asset)query($db,'DELETE FROM assets WHERE id=?',[$asset]);
        if($category!==null)query($db,'DELETE FROM asset_categories WHERE id=?',[$category]);
        query($db,"DELETE FROM refresh_tokens WHERE user_id IN ({$marks})",$ids);
        query($db,"DELETE FROM users WHERE id IN ({$marks})",$ids);
    }
    if($department!==null)query($db,'DELETE FROM departments WHERE id=?',[$department]);
}
