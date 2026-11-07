<?php
require __DIR__ . '/support/http_database.php';
$users = [];
$categories = [];
$facilities = [];
$assets = [];
$bookings = [];

try {
    $roles = query($db, 'SELECT role_name, id FROM roles')->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach (['admin' => 'system_admin', 'coordinator' => 'booking_admin', 'custodian' => 'asset_custodian', 'faculty' => 'faculty_employee'] as $name => $role) {
        query($db, 'INSERT INTO users (username, email, password, role, is_active, role_id) VALUES (?, ?, ?, ?, 1, ?)',
            [$prefix . '.' . $name, $prefix . '.' . $name . '@example.invalid', password_hash($password, PASSWORD_DEFAULT), 'user', $roles[$role]]);
        $users[$name] = (int) $db->lastInsertId();
        $tokens[$name] = expect_status(request_api('POST', '/api/auth/login', ['email' => $prefix . '.' . $name . '@example.invalid', 'password' => $password]), 200, $name . ' login')['data']['access_token'];
    }
    expect_status(request_api('POST', '/api/departments', ['department_code' => $prefix, 'department_name' => 'Test'], $tokens['faculty']), 403, 'faculty cannot create department');
    $created = expect_status(request_api('POST', '/api/departments', ['department_code' => $prefix, 'department_name' => 'Test department'], $tokens['admin']), 201, 'administrator creates department')['data'];
    $department = (int) $created['id'];
    expect_status(request_api('POST', '/api/departments', ['department_code' => $prefix, 'department_name' => 'Duplicate'], $tokens['admin']), 409, 'duplicate department returns conflict');
    expect_status(request_api('PATCH', '/api/departments/' . $department, ['department_name' => 'Updated department'], $tokens['admin']), 200, 'department update');
    expect_status(request_api('GET', '/api/departments', null, $tokens['faculty']), 200, 'authenticated department list');
    foreach ($users as $id) query($db, 'UPDATE users SET department_id = ? WHERE id = ?', [$department, $id]);
    expect_status(request_api('PATCH', '/api/departments/' . $department . '/status', ['status' => 'inactive'], $tokens['admin']), 409, 'active department dependencies protected');
    expect_status(request_api('POST', '/api/facility-categories', ['category_name' => $prefix], $tokens['custodian']), 403, 'custodian cannot manage facility categories');
    $categories['facility_categories'] = (int) expect_status(request_api('POST', '/api/facility-categories', ['category_name' => $prefix], $tokens['coordinator']), 201, 'facility category created')['data']['id'];
    $categories['asset_categories'] = (int) expect_status(request_api('POST', '/api/asset-categories', ['category_name' => $prefix], $tokens['custodian']), 201, 'asset category created')['data']['id'];
    expect_status(request_api('POST', '/api/facilities', ['facility_code' => $prefix, 'facility_name' => 'Test hall', 'category_id' => $categories['facility_categories'], 'capacity' => -1], $tokens['coordinator']), 422, 'negative facility capacity rejected');
    $facility = expect_status(request_api('POST', '/api/facilities', [
        'facility_code' => $prefix, 'facility_name' => 'Test hall', 'category_id' => $categories['facility_categories'], 'capacity' => 50,
    ], $tokens['coordinator']), 201, 'facility registered')['data'];
    $facilities[] = (int) $facility['id'];
    check((int) $facility['created_by'] === $users['coordinator'], 'creator is server assigned');
    expect_status(request_api('PATCH', '/api/facilities/' . $facility['id'], ['status' => 'inactive'], $tokens['coordinator']), 422, 'status cannot bypass dedicated endpoint');
    expect_status(request_api('PATCH', '/api/facility-categories/' . $categories['facility_categories'] . '/status', ['status' => 'inactive'], $tokens['coordinator']), 409, 'active facility category protected');

    $pool = ['asset_code' => $prefix . '.pool', 'asset_name' => 'Test chairs', 'category_id' => $categories['asset_categories'],
        'owning_department_id' => $department, 'tracking_mode' => 'quantity', 'total_quantity' => 10, 'usable_quantity' => 10];
    expect_status(request_api('POST', '/api/assets', array_replace($pool, ['usable_quantity' => 11]), $tokens['custodian']), 422, 'usable quantity bounded by stock');
    expect_status(request_api('POST', '/api/assets', array_replace($pool, ['total_quantity' => 1.5]), $tokens['custodian']), 422, 'fractional inventory rejected');
    expect_status(request_api('POST', '/api/assets', $pool + ['custodian_id' => $users['faculty']], $tokens['custodian']), 422, 'custodian assignment requires release permission');
    $quantity = expect_status(request_api('POST', '/api/assets', $pool + ['custodian_id' => $users['custodian']], $tokens['custodian']), 201, 'quantity pool registered')['data'];
    $assets[] = (int) $quantity['id'];
    expect_status(request_api('PATCH', '/api/assets/' . $quantity['id'], ['tracking_mode' => 'individual'], $tokens['custodian']), 409, 'tracking conversion blocked');
    expect_status(request_api('POST', '/api/assets/' . $quantity['id'] . '/instances', ['asset_tag' => $prefix], $tokens['custodian']), 422, 'pool cannot contain individual records');
    $individual = expect_status(request_api('POST', '/api/assets', array_replace($pool, ['asset_code' => $prefix . '.individual', 'tracking_mode' => 'individual', 'total_quantity' => 0, 'usable_quantity' => 0]), $tokens['custodian']), 201, 'individual asset registered')['data'];
    $assets[] = (int) $individual['id'];
    expect_status(request_api('PATCH', '/api/assets/' . $individual['id'], ['total_quantity' => 3], $tokens['custodian']), 422, 'individual count cannot duplicate instance inventory');
    $instance = expect_status(request_api('POST', '/api/assets/' . $individual['id'] . '/instances', ['asset_tag' => $prefix . '.tag', 'serial_number' => $prefix . '.serial'], $tokens['custodian']), 201, 'item registered')['data'];
    expect_status(request_api('POST', '/api/assets/' . $individual['id'] . '/instances', ['asset_tag' => $prefix . '.tag'], $tokens['custodian']), 409, 'duplicate asset tag returns conflict');
    expect_status(request_api('GET', '/api/assets/' . $individual['id'] . '/instances', null, $tokens['faculty']), 200, 'requester can browse items');
    expect_status(request_api('PATCH', '/api/assets/' . $quantity['id'] . '/instances/' . $instance['id'], ['location' => 'Another parent'], $tokens['custodian']), 422, 'item cannot be edited through quantity parent');
    echo "Catalog creation and permission boundaries verified.\n";

    $date = new DateTimeImmutable('+10 days', new DateTimeZone('UTC'));
    $start = $date->setTime(1, 0)->format('Y-m-d\TH:i:s\Z');
    $end = $date->setTime(3, 0)->format('Y-m-d\TH:i:s\Z');
    $facility_booking = expect_status(request_api('POST', '/api/facility-bookings', ['facility_id' => (int) $facility['id'],
        'start_datetime' => $start, 'end_datetime' => $end, 'activity_name' => 'Test event', 'purpose' => 'Catalog integrity', 'participants_count' => 40], $tokens['faculty']), 201, 'facility request for integrity checks')['data'];
    $bookings['facility'][] = (int) $facility_booking['id'];
    expect_status(request_api('PATCH', '/api/facilities/' . $facility['id'], ['capacity' => 30], $tokens['coordinator']), 409, 'pending capacity protected');
    expect_status(request_api('PATCH', '/api/facility-bookings/' . $facility_booking['id'] . '/approve', null, $tokens['coordinator']), 200, 'facility approved through existing workflow');
    expect_status(request_api('PATCH', '/api/facilities/' . $facility['id'] . '/status', ['status' => 'inactive'], $tokens['coordinator']), 409, 'reserved facility cannot disappear');
    $quantity_booking = expect_status(request_api('POST', '/api/asset-bookings', ['asset_id' => (int) $quantity['id'],
        'usage_start' => $start, 'expected_return' => $end, 'purpose' => 'Catalog integrity', 'quantity_requested' => 6], $tokens['faculty']), 201, 'quantity request for integrity checks')['data'];
    $bookings['asset'][] = (int) $quantity_booking['id'];
    expect_status(request_api('PATCH', '/api/assets/' . $quantity['id'], ['usable_quantity' => 5], $tokens['custodian']), 409, 'pending inventory protected');
    expect_status(request_api('PATCH', '/api/assets/' . $quantity['id'], ['total_quantity' => 12, 'usable_quantity' => 12], $tokens['custodian']), 200, 'inventory increase allowed');
    $item_booking = expect_status(request_api('POST', '/api/asset-bookings', ['asset_id' => (int) $individual['id'],
        'usage_start' => $start, 'expected_return' => $end, 'purpose' => 'Catalog integrity', 'quantity_requested' => 1, 'instance_ids' => [(int) $instance['id']]], $tokens['faculty']), 201, 'individual request for integrity checks')['data'];
    $bookings['asset'][] = (int) $item_booking['id'];
    expect_status(request_api('PATCH', '/api/assets/' . $individual['id'] . '/instances/' . $instance['id'] . '/status', ['status' => 'retired'], $tokens['custodian']), 409, 'requested item cannot be retired');
    expect_status(request_api('PATCH', '/api/assets/' . $individual['id'] . '/instances/' . $instance['id'], ['condition_status' => 'damaged'], $tokens['custodian']), 409, 'requested item condition protected');
    expect_status(request_api('PATCH', '/api/assets/' . $individual['id'] . '/instances/' . $instance['id'], ['location' => 'Storage B'], $tokens['custodian']), 200, 'nonavailability metadata can change');
    $spare = expect_status(request_api('POST', '/api/assets/' . $individual['id'] . '/instances', ['asset_tag' => $prefix . '.spare'], $tokens['custodian']), 201, 'unreserved spare registered')['data'];
    expect_status(request_api('PATCH', '/api/assets/' . $individual['id'] . '/instances/' . $spare['id'] . '/status', ['status' => 'retired'], $tokens['custodian']), 200, 'unreserved spare retired');
    expect_status(request_api('GET', '/api/assets/' . $individual['id'] . '/instances?include_inactive=1', null, $tokens['faculty']), 403, 'inactive inventory limited to managers');
    $all = expect_status(request_api('GET', '/api/assets/' . $individual['id'] . '/instances?include_inactive=1', null, $tokens['custodian']), 200, 'manager sees retired item')['data'];
    check(count($all) === 2, 'retired item preserved');
    $audit_count = (int) query($db, "SELECT COUNT(*) FROM audit_logs WHERE entity_type = 'asset_instance' AND entity_id = ?", [$spare['id']])->fetchColumn();
    check($audit_count === 2, 'creation and retirement audited');
    echo "Passed {$checks} catalog and booking-integrity checks.\n";
} finally {
    if ($users) {
        $ids = array_values($users);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        query($db, "DELETE FROM notifications WHERE recipient_id IN ({$marks})", $ids);
        // Every audit event in this suite has one of its explicitly owned actors.
        query($db, "DELETE FROM audit_logs WHERE actor_user_id IN ({$marks})", $ids);
        query($db, "DELETE FROM asset_booking_instances WHERE asset_booking_id IN (SELECT id FROM asset_bookings WHERE requester_id IN ({$marks}))", $ids);
        query($db, "DELETE FROM asset_bookings WHERE requester_id IN ({$marks})", $ids);
        query($db, "DELETE FROM facility_bookings WHERE requester_id IN ({$marks})", $ids);
        foreach ($assets as $id) {
            query($db, 'DELETE FROM asset_instances WHERE asset_id = ?', [$id]);
            query($db, 'DELETE FROM assets WHERE id = ?', [$id]);
        }
        foreach ($facilities as $id) query($db, 'DELETE FROM facilities WHERE id = ?', [$id]);
        foreach ($categories as $table => $id) query($db, "DELETE FROM {$table} WHERE id = ?", [$id]);
        query($db, "DELETE FROM refresh_tokens WHERE user_id IN ({$marks})", $ids);
        query($db, "DELETE FROM users WHERE id IN ({$marks})", $ids);
    }
    if ($department !== null) query($db, 'DELETE FROM departments WHERE id = ? AND department_code = ?', [$department, $prefix]);
}
