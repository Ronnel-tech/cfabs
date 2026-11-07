<?php
require __DIR__ . '/support/http_database.php';
$users = [];
$category = null;
$facility = null;
$asset_category = null; $assets = [];
try {
    query($db, 'INSERT INTO departments (department_code, department_name) VALUES (?, ?)', [$prefix, 'Report fixtures']);
    $department = (int) $db->lastInsertId();
    $roles = query($db, 'SELECT role_name, id FROM roles')->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach (['a' => 'faculty_employee', 'b' => 'faculty_employee', 'admin' => 'system_admin', 'coordinator' => 'booking_admin'] as $name => $role) {
        query($db, 'INSERT INTO users (username, email, password, role, is_active, role_id, department_id) VALUES (?, ?, ?, ?, 1, ?, ?)',
            [$prefix . '.' . $name, $prefix . '.' . $name . '@example.invalid', password_hash($password, PASSWORD_DEFAULT), 'user', $roles[$role], $department]);
        $users[$name] = (int) $db->lastInsertId();
        $tokens[$name] = expect_status(request_api('POST', '/api/auth/login', ['email' => $prefix . '.' . $name . '@example.invalid', 'password' => $password]), 200, $name . ' login')['data']['access_token'];
    }
    query($db, 'INSERT INTO facility_categories (category_name) VALUES (?)', [$prefix]);
    $category = (int) $db->lastInsertId();
    query($db, 'INSERT INTO facilities (facility_code, facility_name, category_id, capacity, created_by) VALUES (?, ?, ?, 50, ?)', [$prefix, 'Report fixture', $category, $users['coordinator']]);
    $facility = (int) $db->lastInsertId();
    foreach (['a', 'b'] as $name) {
        query($db, "INSERT INTO facility_bookings (booking_reference, requester_id, department_id, facility_id, start_datetime, end_datetime, activity_name, purpose, participants_count, status)
            VALUES (?, ?, ?, ?, UTC_TIMESTAMP() - INTERVAL 2 HOUR, UTC_TIMESTAMP() - INTERVAL 1 HOUR, ?, ?, 2, 'APPROVED')",
            [$prefix . '.' . $name, $users[$name], $department, $facility, 'Report test', 'Fixture']);
        $bookings[$name] = (int) $db->lastInsertId();
    }
    expect_status(request_api('GET', '/api/dashboard'), 401, 'anonymous dashboard blocked');
    $own = expect_status(request_api('GET', '/api/dashboard', null, $tokens['a']), 200, 'own dashboard')['data'];
    check($own['facility_bookings']['APPROVED'] === 1, 'dashboard excludes another requester');
    expect_status(request_api('GET', '/api/dashboard?owner_id=' . $users['b'], null, $tokens['a']), 422, 'owner filter cannot widen dashboard');
    expect_status(request_api('GET', '/api/reports/facilities', null, $tokens['a']), 403, 'faculty operational report blocked');
    expect_status(request_api('GET', '/api/reports/assets', null, $tokens['coordinator']), 403, 'facility coordinator cannot read asset reports');
    expect_status(request_api('GET', '/api/audit-logs', null, $tokens['coordinator']), 403, 'audit permission enforced');
    expect_status(request_api('GET', '/api/reports/facilities?start=invalid&end=invalid', null, $tokens['coordinator']), 422, 'invalid report range rejected');
    expect_status(request_api('GET', '/api/audit-logs?per_page=101', null, $tokens['admin']), 422, 'audit pagination bounded');
    expect_status(request_api('PATCH', '/api/facility-bookings/' . $bookings['a'] . '/complete', null, $tokens['a']), 403, 'requester cannot complete facility');
    $completed = expect_status(request_api('PATCH', '/api/facility-bookings/' . $bookings['a'] . '/complete', null, $tokens['coordinator']), 200, 'ended facility completed')['data'];
    check($completed['status'] === 'COMPLETED' && $completed['completed_at'] !== null, 'completion timestamp recorded');
    expect_status(request_api('PATCH', '/api/facility-bookings/' . $bookings['a'] . '/complete', null, $tokens['coordinator']), 200, 'completion retry is idempotent');
    check((int) query($db, "SELECT COUNT(*) FROM audit_logs WHERE entity_type = 'facility_booking' AND entity_id = ? AND action = 'facility_booking.completed'", [$bookings['a']])->fetchColumn() === 1, 'retry does not duplicate completion audit');
    query($db, 'UPDATE facility_bookings SET end_datetime = UTC_TIMESTAMP() + INTERVAL 1 HOUR WHERE id = ?', [$bookings['b']]);
    expect_status(request_api('PATCH', '/api/facility-bookings/' . $bookings['b'] . '/complete', null, $tokens['coordinator']), 409, 'in-progress booking cannot complete');
    $report = expect_status(request_api('GET', '/api/reports/facilities', null, $tokens['coordinator']), 200, 'facility operational report')['data'];
    $row = array_values(array_filter($report['resources'], fn($row) => (int) $row['resource_id'] === $facility));
    check(count($row) === 1 && $row[0]['booking_count'] === 2 && $row[0]['reserved_seconds'] >= 3600, 'resource totals and clipped duration');
    $audit = expect_status(request_api('GET', '/api/audit-logs?actor_user_id=' . $users['coordinator'] . '&entity_type=facility_booking&action=facility_booking.completed', null, $tokens['admin']), 200, 'filtered audit viewer')['data'];
    check($audit['pagination']['total'] === 1 && count($audit['records']) === 1, 'audit filters are exact and paginated');
    expect_status(request_api('GET', '/api/reports/assets', null, $tokens['admin']), 200, 'explicit asset report permission');
    $filtered = expect_status(request_api('GET', '/api/reports/facilities?department_id=' . $department . '&category_id=' . $category . '&requester_id=' . $users['a'] . '&status=COMPLETED', null, $tokens['coordinator']), 200, 'combined report filters')['data'];
    check(($filtered['bookings_by_status']['COMPLETED'] ?? 0) === 1 && count($filtered['resources']) === 1 && $filtered['resources'][0]['booking_count'] === 1, 'filters apply equally to totals and resource rows');
    expect_status(request_api('GET', '/api/reports/facilities?department_id[]=1', null, $tokens['coordinator']), 422, 'array filter rejected');
    query($db, 'UPDATE facilities SET facility_name = ? WHERE id = ?', ['=1+1', $facility]);
    $csv_context = stream_context_create(['http' => ['header' => 'Authorization: Bearer ' . $tokens['coordinator'], 'ignore_errors' => TRUE, 'timeout' => 30]]);
    $csv = file_get_contents($base . '/api/reports/facilities?resource_id=' . $facility . '&format=csv', FALSE, $csv_context);
    check(str_contains($http_response_header[0], '200') && str_contains($csv, "'=1+1"), 'CSV export escapes spreadsheet formulas');
    expect_status(request_api('GET', '/api/reports/facilities?format=csv&page=1', null, $tokens['coordinator']), 422, 'CSV pagination cannot silently truncate export');
    query($db, 'INSERT INTO asset_categories (category_name) VALUES (?)', [$prefix]); $asset_category = (int) $db->lastInsertId();
    foreach (['quantity','individual'] as $mode) {
        query($db, 'INSERT INTO assets (asset_code, asset_name, category_id, owning_department_id, tracking_mode, total_quantity, usable_quantity, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$prefix . '.' . $mode, 'Inventory test', $asset_category, $department, $mode, $mode === 'quantity' ? 10 : 0, $mode === 'quantity' ? 8 : 0, $users['admin']]);
        $assets[$mode] = (int) $db->lastInsertId();
    }
    query($db, "INSERT INTO asset_instances (asset_id, asset_tag, condition_status) VALUES (?, ?, 'good'), (?, ?, 'damaged')", [$assets['individual'], $prefix . '.good', $assets['individual'], $prefix . '.bad']);
    expect_status(request_api('GET', '/api/reports/inventory', null, $tokens['a']), 403, 'inventory reports are protected');
    $inventory = expect_status(request_api('GET', '/api/reports/inventory?category_id=' . $asset_category . '&department_id=' . $department . '&tracking_mode=individual', null, $tokens['admin']), 200, 'individual inventory summary')['data'];
    check(count($inventory['records']) === 1 && (int) $inventory['records'][0]['registered_quantity'] === 2 && (int) $inventory['records'][0]['usable_quantity'] === 1, 'individual stock is derived from item conditions');
    $inventory = expect_status(request_api('GET', '/api/reports/inventory?category_id=' . $asset_category . '&per_page=1', null, $tokens['admin']), 200, 'inventory pagination')['data'];
    check(count($inventory['records']) === 1 && $inventory['pagination']['has_more'] === TRUE, 'inventory pages expose additional results');
    echo "Passed {$checks} reporting, privacy and facility-completion checks.\n";
} finally {
    if ($users) {
        $ids = array_values($users); $marks = implode(',', array_fill(0, count($ids), '?'));
        query($db, "DELETE FROM notifications WHERE recipient_id IN ({$marks})", $ids);
        query($db, "DELETE FROM audit_logs WHERE actor_user_id IN ({$marks})", $ids);
        query($db, "DELETE FROM facility_bookings WHERE requester_id IN ({$marks})", $ids);
        if ($facility !== null) query($db, 'DELETE FROM facilities WHERE id = ?', [$facility]);
        if ($category !== null) query($db, 'DELETE FROM facility_categories WHERE id = ?', [$category]);
        foreach ($assets as $asset) { query($db, 'DELETE FROM asset_instances WHERE asset_id = ?', [$asset]); query($db, 'DELETE FROM assets WHERE id = ?', [$asset]); }
        if ($asset_category !== null) query($db, 'DELETE FROM asset_categories WHERE id = ?', [$asset_category]);
        query($db, "DELETE FROM refresh_tokens WHERE user_id IN ({$marks})", $ids);
        query($db, "DELETE FROM users WHERE id IN ({$marks})", $ids);
    }
    if ($department !== null) query($db, 'DELETE FROM departments WHERE id = ?', [$department]);
}
