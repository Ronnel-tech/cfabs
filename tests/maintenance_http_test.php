<?php
require __DIR__ . '/support/http_database.php';
$users = []; $assets = []; $categories = []; $facility = null;
try {
    query($db, 'INSERT INTO departments (department_code, department_name) VALUES (?, ?)', [$prefix, 'Maintenance fixtures']);
    $department = (int) $db->lastInsertId();
    $roles = query($db, 'SELECT role_name, id FROM roles')->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach (['faculty' => 'faculty_employee', 'coordinator' => 'booking_admin', 'custodian' => 'asset_custodian'] as $name => $role) {
        query($db, 'INSERT INTO users (username, email, password, role, is_active, role_id, department_id) VALUES (?, ?, ?, ?, 1, ?, ?)', [$prefix . '.' . $name, $prefix . '.' . $name . '@example.invalid', password_hash($password, PASSWORD_DEFAULT), 'user', $roles[$role], $department]);
        $users[$name] = (int) $db->lastInsertId();
        $tokens[$name] = expect_status(request_api('POST', '/api/auth/login', ['email' => $prefix . '.' . $name . '@example.invalid', 'password' => $password]), 200, $name . ' login')['data']['access_token'];
    }
    foreach (['facility_categories','asset_categories'] as $table) {
        query($db, "INSERT INTO {$table} (category_name) VALUES (?)", [$prefix]); $categories[$table] = (int) $db->lastInsertId();
    }
    query($db, 'INSERT INTO facilities (facility_code, facility_name, category_id, capacity, created_by) VALUES (?, ?, ?, 50, ?)', [$prefix, 'Maintenance hall', $categories['facility_categories'], $users['coordinator']]);
    $facility = (int) $db->lastInsertId();
    foreach (['quantity','individual'] as $mode) {
        query($db, 'INSERT INTO assets (asset_code, asset_name, category_id, owning_department_id, tracking_mode, total_quantity, usable_quantity, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$prefix . '.' . $mode, 'Maintenance stock', $categories['asset_categories'], $department, $mode, $mode === 'quantity' ? 10 : 0, $mode === 'quantity' ? 10 : 0, $users['custodian']]);
        $assets[$mode] = (int) $db->lastInsertId();
    }
    query($db, 'INSERT INTO asset_instances (asset_id, asset_tag) VALUES (?, ?)', [$assets['individual'], $prefix]);
    $item = (int) $db->lastInsertId();
    $start = gmdate('Y-m-d\TH:i:s\Z', time() + 86400); $end = gmdate('Y-m-d\TH:i:s\Z', time() + 90000);
    $range = '?start=' . rawurlencode($start) . '&end=' . rawurlencode($end);
    $data = ['facility_id' => $facility, 'start_datetime' => $start, 'end_datetime' => $end, 'reason' => 'Planned repair'];
    expect_status(request_api('POST', '/api/facility-maintenance', $data, $tokens['faculty']), 403, 'faculty cannot schedule maintenance');
    expect_status(request_api('POST', '/api/facility-maintenance', $data, $tokens['custodian']), 403, 'asset permission cannot schedule facility work');
    $maintenance = expect_status(request_api('POST', '/api/facility-maintenance', $data, $tokens['coordinator']), 201, 'facility maintenance scheduled')['data'];
    $snapshot = expect_status(request_api('GET', '/api/facilities/' . $facility . '/availability' . $range, null, $tokens['faculty']), 200, 'facility calendar')['data'];
    check($snapshot['available'] === FALSE, 'maintenance blocks facility');
    expect_status(request_api('POST', '/api/facility-maintenance', $data, $tokens['coordinator']), 409, 'duplicate interval blocked');
    expect_status(request_api('PATCH', '/api/facility-maintenance/' . $maintenance['id'] . '/complete', null, $tokens['coordinator']), 409, 'future work cannot complete');
    expect_status(request_api('PATCH', '/api/facility-maintenance/' . $maintenance['id'] . '/cancel', null, $tokens['coordinator']), 200, 'scheduled work cancelled');
    $facility_history = expect_status(request_api('GET', '/api/facility-maintenance?resource_id=' . $facility, null, $tokens['coordinator']), 200, 'filtered facility maintenance history')['data'];
    check(count($facility_history) === 1 && (int) $facility_history[0]['facility_id'] === $facility && !array_key_exists('asset_tag', $facility_history[0]), 'facility history retains its original fields and resource filter');
    query($db, "INSERT INTO facility_bookings (booking_reference, requester_id, department_id, facility_id, start_datetime, end_datetime, activity_name, purpose, participants_count, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 2, 'APPROVED')", [$prefix, $users['faculty'], $department, $facility, gmdate('Y-m-d H:i:s', time() + 86400), gmdate('Y-m-d H:i:s', time() + 90000), 'Test activity', 'Fixture']);
    expect_status(request_api('POST', '/api/facility-maintenance', $data, $tokens['coordinator']), 409, 'approved booking protected');
    check((int) query($db, "SELECT COUNT(*) FROM facility_maintenance WHERE facility_id = ? AND status = 'scheduled'", [$facility])->fetchColumn() === 0, 'conflict rolls maintenance insertion back');
    $pool = ['asset_id' => $assets['quantity'], 'start_datetime' => $start, 'expected_completion' => $end, 'quantity_affected' => 6, 'reason' => 'Service six units'];
    $p = expect_status(request_api('POST', '/api/asset-maintenance', $pool, $tokens['custodian']), 201, 'pool maintenance scheduled')['data'];
    $snapshot = expect_status(request_api('GET', '/api/assets/' . $assets['quantity'] . '/availability' . $range, null, $tokens['faculty']), 200, 'pool availability')['data'];
    check($snapshot['available_quantity'] === 4, 'dated maintenance reduces stock without changing baseline');
    expect_status(request_api('POST', '/api/asset-maintenance', array_replace($pool, ['quantity_affected' => 5]), $tokens['custodian']), 409, 'aggregate maintenance cannot overallocate inventory');
    check((int) query($db, 'SELECT usable_quantity FROM assets WHERE id = ?', [$assets['quantity']])->fetchColumn() === 10, 'maintenance does not double subtract usable stock');
    expect_status(request_api('PATCH', '/api/asset-maintenance/' . $p['id'] . '/cancel', null, $tokens['custodian']), 200, 'pool maintenance cancelled');
    $pool_history = expect_status(request_api('GET', '/api/asset-maintenance?resource_id=' . $assets['quantity'], null, $tokens['custodian']), 200, 'filtered pool maintenance history')['data'];
    check(count($pool_history) === 1 && $pool_history[0]['asset_tag'] === null && (int) $pool_history[0]['asset_id'] === $assets['quantity'], 'pool history has no invented item tag');
    query($db, "INSERT INTO asset_bookings (booking_reference, requester_id, department_id, asset_id, usage_start, expected_return, quantity_requested, purpose, status)
        VALUES (?, ?, ?, ?, ?, ?, 6, 'Maintenance conflict fixture', 'APPROVED')", [$prefix, $users['faculty'], $department, $assets['quantity'], gmdate('Y-m-d H:i:s', time() + 86400), gmdate('Y-m-d H:i:s', time() + 90000)]);
    $asset_booking = (int) $db->lastInsertId();
    expect_status(request_api('POST', '/api/asset-maintenance', array_replace($pool, ['quantity_affected' => 5]), $tokens['custodian']), 409, 'committed pool reservation protected');
    query($db, "UPDATE asset_bookings SET status = 'CANCELLED' WHERE id = ?", [$asset_booking]);
    $active_pool = expect_status(request_api('POST', '/api/asset-maintenance', array_replace($pool, ['start_datetime' => gmdate('Y-m-d\TH:i:s\Z', time() - 7200)]), $tokens['custodian']), 201, 'active pool work scheduled')['data'];
    query($db, 'UPDATE asset_maintenance SET expected_completion = UTC_TIMESTAMP() - INTERVAL 1 HOUR WHERE id = ?', [$active_pool['id']]);
    query($db, "INSERT INTO asset_bookings (booking_reference, requester_id, department_id, asset_id, usage_start, expected_return, quantity_requested, purpose, status)
        VALUES (?, ?, ?, ?, UTC_TIMESTAMP() - INTERVAL 30 MINUTE, UTC_TIMESTAMP() + INTERVAL 1 HOUR, 5, 'Overrun fixture', 'APPROVED')", [$prefix . '.overrun', $users['faculty'], $department, $assets['quantity']]);
    $overrun_booking = (int) $db->lastInsertId();
    expect_status(request_api('POST', '/api/asset-bookings/' . $overrun_booking . '/release', ['quantity' => 5, 'condition_status' => 'good', 'idempotency_key' => bin2hex(random_bytes(16))], $tokens['custodian']), 409, 'overdue maintenance blocks physical release');
    expect_status(request_api('PATCH', '/api/asset-maintenance/' . $active_pool['id'] . '/complete', null, $tokens['custodian']), 200, 'active pool work explicitly completed');
    $single = ['asset_id' => $assets['individual'], 'asset_instance_id' => $item, 'quantity_affected' => 1, 'start_datetime' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600), 'expected_completion' => $end, 'reason' => 'Inspect item'];
    $i = expect_status(request_api('POST', '/api/asset-maintenance', $single, $tokens['custodian']), 201, 'specific item maintenance')['data'];
    $snapshot = expect_status(request_api('GET', '/api/assets/' . $assets['individual'] . '/availability' . $range, null, $tokens['faculty']), 200, 'item availability')['data'];
    check($snapshot['available_quantity'] === 0, 'specific item blocked');
    query($db, 'UPDATE asset_maintenance SET start_datetime = UTC_TIMESTAMP() - INTERVAL 2 HOUR, expected_completion = UTC_TIMESTAMP() - INTERVAL 1 HOUR WHERE id = ?', [$i['id']]);
    $snapshot = expect_status(request_api('GET', '/api/assets/' . $assets['individual'] . '/availability' . $range, null, $tokens['faculty']), 200, 'overdue maintenance availability')['data'];
    check($snapshot['available_quantity'] === 0, 'overdue work remains unavailable until explicitly closed');
    $done = expect_status(request_api('PATCH', '/api/asset-maintenance/' . $i['id'] . '/complete', null, $tokens['custodian']), 200, 'work completed')['data'];
    check((int) $done['completed_by'] === $users['custodian'] && $done['actual_completion'] !== null, 'responsible staff and completion timestamp retained');
    $snapshot = expect_status(request_api('GET', '/api/assets/' . $assets['individual'] . '/availability' . $range, null, $tokens['faculty']), 200, 'availability restored')['data'];
    check($snapshot['available_quantity'] === 1, 'completion removes maintenance hold');
    $history = expect_status(request_api('GET', '/api/asset-maintenance?resource_id=' . $assets['individual'], null, $tokens['custodian']), 200, 'maintenance history retained')['data'];
    check(count($history) === 1 && $history[0]['asset_tag'] === $prefix && (int) $history[0]['asset_instance_id'] === $item, 'maintenance history labels the exact item without changing its identity');
    echo "Passed {$checks} maintenance and inventory-protection checks.\n";
} finally {
    if ($users) {
        $ids = array_values($users); $marks = implode(',', array_fill(0, count($ids), '?'));
        query($db, "DELETE FROM notifications WHERE recipient_id IN ({$marks})", $ids);
        query($db, "DELETE FROM audit_logs WHERE actor_user_id IN ({$marks})", $ids);
        query($db, "DELETE FROM facility_bookings WHERE requester_id IN ({$marks})", $ids);
        query($db, "DELETE FROM asset_bookings WHERE requester_id IN ({$marks})", $ids);
        if ($facility !== null) { query($db, "DELETE FROM notifications WHERE related_entity_type='facility_maintenance' AND related_entity_id IN (SELECT id FROM facility_maintenance WHERE facility_id=?)", [$facility]); query($db, 'DELETE FROM facility_maintenance WHERE facility_id = ?', [$facility]); query($db, 'DELETE FROM facilities WHERE id = ?', [$facility]); }
        foreach ($assets as $asset) { query($db, "DELETE FROM notifications WHERE related_entity_type='asset_maintenance' AND related_entity_id IN (SELECT id FROM asset_maintenance WHERE asset_id=?)", [$asset]); query($db, 'DELETE FROM asset_maintenance WHERE asset_id = ?', [$asset]); query($db, 'DELETE FROM asset_instances WHERE asset_id = ?', [$asset]); query($db, 'DELETE FROM assets WHERE id = ?', [$asset]); }
        foreach ($categories as $table => $id) query($db, "DELETE FROM {$table} WHERE id = ?", [$id]);
        query($db, "DELETE FROM refresh_tokens WHERE user_id IN ({$marks})", $ids);
        query($db, "DELETE FROM users WHERE id IN ({$marks})", $ids);
    }
    if ($department !== null) query($db, 'DELETE FROM departments WHERE id = ?', [$department]);
}
