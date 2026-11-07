<?php
require __DIR__ . '/support/http_database.php';
$users = []; $category = null; $asset = null; $facility_category = null; $facility = null;
$reference = substr($prefix, -12);
function dispatch_test(int $recipient, bool $send): array {
    global $root;
    $args = [PHP_BINARY, '-d', 'xdebug.mode=off', 'lava', 'notifications:dispatch', '--recipient=' . $recipient];
    if ($send) $args[] = '--send';
    $process = proc_open($args, [['pipe','r'], ['pipe','w'], ['pipe','w']], $pipes, $root);
    fclose($pipes[0]); $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
    check(proc_close($process) === 0, 'dispatcher succeeds');
    $result = json_decode(trim($out), TRUE);
    check(is_array($result) && array_key_exists('inserted', $result), 'dispatcher returns counts only');
    return $result;
}
try {
    query($db, 'INSERT INTO departments (department_code, department_name) VALUES (?, ?)', [$prefix, 'Reminder fixtures']); $department = (int) $db->lastInsertId();
    $roles = query($db, 'SELECT role_name, id FROM roles')->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach (['owner' => 'faculty_employee', 'staff' => 'asset_custodian', 'coordinator' => 'booking_admin'] as $name => $role) {
        query($db, 'INSERT INTO users (username, email, password, role, is_active, role_id, department_id) VALUES (?, ?, ?, ?, 1, ?, ?)', [$prefix . '.' . $name, $prefix . '.' . $name . '@example.invalid', password_hash($password, PASSWORD_DEFAULT), 'user', $roles[$role], $department]); $users[$name] = (int) $db->lastInsertId();
    }
    query($db, 'INSERT INTO asset_categories (category_name) VALUES (?)', [$prefix]); $category = (int) $db->lastInsertId();
    query($db, 'INSERT INTO assets (asset_code, asset_name, category_id, owning_department_id, tracking_mode, total_quantity, usable_quantity, created_by) VALUES (?, ?, ?, ?, ?, 10, 10, ?)', [$prefix, 'Reminder asset', $category, $department, 'quantity', $users['staff']]); $asset = (int) $db->lastInsertId();
    foreach (['upcoming','return_due','overdue','settled','cancelled','far'] as $case) {
        $released = in_array($case, ['return_due','overdue','settled'], TRUE);
        $start = gmdate('Y-m-d H:i:s', time() + ($released ? -7200 : ($case === 'far' ? 172800 : 3600)));
        $end = gmdate('Y-m-d H:i:s', time() + (in_array($case, ['overdue','settled'], TRUE) ? -3600 : ($case === 'far' ? 176400 : 7200)));
        query($db, 'INSERT INTO asset_bookings (booking_reference, requester_id, department_id, asset_id, usage_start, expected_return, quantity_requested, purpose, status, quantity_released, quantity_returned)
            VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)', [$reference . '.' . $case, $users['owner'], $department, $asset, $start, $end, 'Reminder fixture', $case === 'cancelled' ? 'CANCELLED' : ($released ? 'RELEASED' : 'APPROVED'), $released ? 1 : null, $case === 'settled' ? 1 : null]);
    }
    query($db, 'INSERT INTO asset_maintenance (asset_id, quantity_affected, start_datetime, expected_completion, reason, status, created_by) VALUES (?, 1, UTC_TIMESTAMP() - INTERVAL 2 HOUR, UTC_TIMESTAMP() - INTERVAL 1 HOUR, ?, ?, ?)', [$asset, 'Overdue fixture', 'scheduled', $users['staff']]);
    query($db, 'INSERT INTO facility_categories (category_name) VALUES (?)', [$prefix]); $facility_category = (int) $db->lastInsertId();
    query($db, 'INSERT INTO facilities (facility_code, facility_name, category_id, capacity, created_by) VALUES (?, ?, ?, 20, ?)', [$prefix, 'Reminder facility', $facility_category, $users['coordinator']]); $facility = (int) $db->lastInsertId();
    query($db, "INSERT INTO facility_bookings (booking_reference, requester_id, department_id, facility_id, start_datetime, end_datetime, activity_name, purpose, participants_count, status)
        VALUES (?, ?, ?, ?, UTC_TIMESTAMP() + INTERVAL 1 HOUR, UTC_TIMESTAMP() + INTERVAL 2 HOUR, 'Reminder test', 'Fixture', 2, 'APPROVED')", [$reference, $users['owner'], $department, $facility]);
    query($db, "INSERT INTO facility_maintenance (facility_id, start_datetime, end_datetime, reason, status, created_by)
        VALUES (?, UTC_TIMESTAMP() - INTERVAL 4 HOUR, UTC_TIMESTAMP() - INTERVAL 3 HOUR, 'Overdue work', 'scheduled', ?)", [$facility, $users['coordinator']]);
    $preview = dispatch_test($users['owner'], FALSE);
    check($preview['dry_run'] === TRUE && $preview['eligible'] === 4 && $preview['inserted'] === 0, 'preview selects upcoming facilities/assets, due and overdue only');
    check((int) query($db, 'SELECT COUNT(*) FROM notifications WHERE recipient_id = ?', [$users['owner']])->fetchColumn() === 0, 'dry run makes no notification writes');
    $sent = dispatch_test($users['owner'], TRUE); check($sent['inserted'] === 4, 'owner receives four reminders');
    query($db, 'UPDATE notifications SET is_read = 1 WHERE recipient_id = ?', [$users['owner']]);
    $repeat = dispatch_test($users['owner'], TRUE);
    check($repeat['inserted'] === 0 && $repeat['already_sent'] === 4, 'repeat dispatch does not duplicate reminders');
    check((int) query($db, 'SELECT COUNT(*) FROM notifications WHERE recipient_id = ? AND is_read = 1', [$users['owner']])->fetchColumn() === 4, 'repeat preserves read state');
    $staff = dispatch_test($users['staff'], TRUE); check($staff['inserted'] === 2, 'authorized staff receives asset overdue and maintenance overdue');
    check(dispatch_test($users['coordinator'], TRUE)['inserted'] === 1, 'facility overdue work targets authorized coordinators');
    query($db, "UPDATE asset_bookings SET expected_return = expected_return - INTERVAL 1 MINUTE WHERE requester_id = ? AND booking_reference = ?", [$users['owner'], $reference . '.overdue']);
    check(dispatch_test($users['owner'], TRUE)['inserted'] === 1, 'changed deadline gets its own deduplication key');
    query($db, 'UPDATE users SET is_active = 0 WHERE id = ?', [$users['owner']]);
    check(dispatch_test($users['owner'], TRUE)['eligible'] === 0, 'inactive recipients excluded');
    echo "Passed {$checks} scheduled reminder checks.\n";
} finally {
    if ($users) {
        $ids = array_values($users); $marks = implode(',', array_fill(0, count($ids), '?'));
        query($db, "DELETE FROM notifications WHERE recipient_id IN ({$marks})", $ids);
        query($db, "DELETE FROM asset_bookings WHERE requester_id IN ({$marks})", $ids);
        query($db, "DELETE FROM facility_bookings WHERE requester_id IN ({$marks})", $ids);
        if ($facility !== null) { query($db, 'DELETE FROM facility_maintenance WHERE facility_id = ?', [$facility]); query($db, 'DELETE FROM facilities WHERE id = ?', [$facility]); }
        if ($facility_category !== null) query($db, 'DELETE FROM facility_categories WHERE id = ?', [$facility_category]);
        if ($asset !== null) { query($db, 'DELETE FROM asset_maintenance WHERE asset_id = ?', [$asset]); query($db, 'DELETE FROM assets WHERE id = ?', [$asset]); }
        if ($category !== null) query($db, 'DELETE FROM asset_categories WHERE id = ?', [$category]);
        query($db, "DELETE FROM users WHERE id IN ({$marks})", $ids);
    }
    if ($department !== null) query($db, 'DELETE FROM departments WHERE id = ?', [$department]);
}
