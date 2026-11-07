<?php
require __DIR__ . '/support/http_database.php';
$names = array_map(fn($name) => $prefix . '.' . $name, ['a', 'b', 'coordinator', 'custodian']);
$facility_ids = [];
$asset_ids = [];
$category_ids = [];
$user_ids = [];
$at = new DateTimeImmutable('+10 days', new DateTimeZone('UTC'));
$start = $at->setTime(1, 0)->format('Y-m-d\TH:i:s\Z');
$end = $at->setTime(3, 0)->format('Y-m-d\TH:i:s\Z');
$range = '?start=' . rawurlencode($start) . '&end=' . rawurlencode($end);

try {
    query($db, 'INSERT INTO departments (department_code, department_name) VALUES (?, ?)', [$prefix, 'Temporary booking fixture']);
    $department = (int) $db->lastInsertId();
    $roles = query($db, 'SELECT role_name, id FROM roles')->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach (['a' => 'faculty_employee', 'b' => 'faculty_employee', 'coordinator' => 'booking_admin', 'custodian' => 'asset_custodian'] as $name => $role) {
        query($db, 'INSERT INTO users (username, email, password, role, is_active, role_id, department_id)
            VALUES (?, ?, ?, ?, 1, ?, ?)', [$prefix . '.' . $name, $prefix . '.' . $name . '@example.invalid',
                password_hash($password, PASSWORD_DEFAULT), 'user', $roles[$role], $department]);
        $user_ids[$name] = (int) $db->lastInsertId();
        $tokens[$name] = expect_status(request_api('POST', '/api/auth/login', [
            'email' => $prefix . '.' . $name . '@example.invalid', 'password' => $password,
        ]), 200, $name . ' login')['data']['access_token'];
    }
    foreach (['facility_categories', 'asset_categories'] as $table) {
        query($db, "INSERT INTO {$table} (category_name) VALUES (?)", [$prefix]);
        $category_ids[$table] = (int) $db->lastInsertId();
    }
    foreach (['primary', 'race'] as $name) {
        query($db, 'INSERT INTO facilities (facility_code, facility_name, category_id, capacity, created_by)
            VALUES (?, ?, ?, 50, ?)', [$prefix . '.' . $name, 'Test facility', $category_ids['facility_categories'], $user_ids['coordinator']]);
        $facility_ids[$name] = (int) $db->lastInsertId();
    }
    foreach (['quantity', 'individual'] as $mode) {
        query($db, 'INSERT INTO assets (asset_code, asset_name, category_id, owning_department_id, tracking_mode, total_quantity, usable_quantity, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$prefix . '.' . $mode, 'Test assets', $category_ids['asset_categories'],
                $department, $mode, $mode === 'quantity' ? 10 : 0, $mode === 'quantity' ? 10 : 0, $user_ids['custodian']]);
        $asset_ids[$mode] = (int) $db->lastInsertId();
    }
    $instances = [];
    foreach (['one', 'two'] as $name) {
        query($db, 'INSERT INTO asset_instances (asset_id, asset_tag) VALUES (?, ?)', [$asset_ids['individual'], $prefix . '.' . $name]);
        $instances[] = (int) $db->lastInsertId();
    }
    $facility = ['facility_id' => $facility_ids['primary'], 'start_datetime' => $start, 'end_datetime' => $end,
        'activity_name' => 'Test event', 'participants_count' => 20, 'purpose' => 'Integration test'];
    $far = (new DateTimeImmutable('+31 days', new DateTimeZone('Asia/Manila')))->setTime(9, 0)->setTimezone(new DateTimeZone('UTC'));
    $far_start = $far->format('Y-m-d\TH:i:s\Z'); $far_end = $far->modify('+2 hours')->format('Y-m-d\TH:i:s\Z');
    expect_status(request_api('POST', '/api/facility-bookings', array_replace($facility, ['start_datetime' => $far_start, 'end_datetime' => $far_end]), $tokens['a']), 422, 'facility advance limit enforced');
    expect_status(request_api('POST', '/api/asset-bookings', ['asset_id' => $asset_ids['quantity'], 'usage_start' => $far_start, 'expected_return' => $far_end, 'quantity_requested' => 1, 'purpose' => 'Too far ahead'], $tokens['a']), 422, 'asset advance limit enforced');
    $far_view = expect_status(request_api('GET', '/api/facilities/' . $facility_ids['primary'] . '/availability?start=' . rawurlencode($far_start) . '&end=' . rawurlencode($far_end), null, $tokens['a']), 200, 'advance limit exposed in availability')['data'];
    check($far_view['available'] === FALSE && $far_view['advance_booking_window']['within_limit'] === FALSE, 'out-of-window date is unavailable');
    query($db, "INSERT INTO facility_bookings (booking_reference, requester_id, department_id, facility_id, start_datetime, end_datetime, activity_name, purpose, participants_count, status)
        VALUES (?, ?, ?, ?, ?, ?, 'Legacy pending', 'Approval limit check', 1, 'PENDING')", ['ADV-' . bin2hex(random_bytes(8)), $user_ids['a'], $department, $facility_ids['primary'], $far->format('Y-m-d H:i:s'), $far->modify('+2 hours')->format('Y-m-d H:i:s')]);
    $legacy_far = (int) $db->lastInsertId();
    expect_status(request_api('PATCH', '/api/facility-bookings/' . $legacy_far . '/approve', null, $tokens['coordinator']), 422, 'approval rechecks advance limit for old pending requests');
    check(query($db, 'SELECT status FROM facility_bookings WHERE id = ?', [$legacy_far])->fetchColumn() === 'PENDING', 'failed approval preserves pending record');
    $f1 = expect_status(request_api('POST', '/api/facility-bookings', $facility, $tokens['a']), 201, 'first facility pending')['data'];
    $f2 = expect_status(request_api('POST', '/api/facility-bookings', $facility, $tokens['b']), 201, 'overlapping facility pending')['data'];
    check($f1['status'] === 'PENDING' && $f2['status'] === 'PENDING', 'pending requests coexist');
    $before = expect_status(request_api('GET', '/api/facilities/' . $facility_ids['primary'] . '/availability' . $range, null, $tokens['a']), 200, 'facility availability before approval')['data'];
    check($before['available'] === TRUE, 'pending requests do not reserve facility');
    expect_status(request_api('PATCH', '/api/facility-bookings/' . $f1['id'] . '/approve', null, $tokens['a']), 403, 'requester cannot approve');
    $approved = expect_status(request_api('PATCH', '/api/facility-bookings/' . $f1['id'] . '/approve', null, $tokens['coordinator']), 200, 'facility approval')['data'];
    check(in_array((int) $f2['id'], $approved['automatically_rejected_ids'], TRUE), 'overlapping request automatically rejected');
    $rejected = expect_status(request_api('GET', '/api/facility-bookings/' . $f2['id'], null, $tokens['b']), 200, 'rejected requester sees result')['data'];
    check($rejected['status'] === 'REJECTED' && $rejected['rejection_source'] === 'automatic' && $rejected['rejection_reason'] !== '', 'automatic rejection records source and reason');
    $after = expect_status(request_api('GET', '/api/facilities/' . $facility_ids['primary'] . '/availability' . $range, null, $tokens['a']), 200, 'updated facility availability')['data'];
    check($after['available'] === FALSE && count($after['unavailable_intervals']) === 1, 'approved interval visible');
    expect_status(request_api('POST', '/api/facility-bookings', $facility, $tokens['b']), 409, 'new conflicting request rejected');
    expect_status(request_api('GET', '/api/facility-bookings/' . $f1['id'], null, $tokens['b']), 403, 'booking ownership enforced');
    $notices = expect_status(request_api('GET', '/api/notifications', null, $tokens['b']), 200, 'requester notifications')['data'];
    $rejection_notices = array_values(array_filter($notices, fn($row) => $row['notification_type'] === 'facility_booking.rejected'));
    check(count($rejection_notices) === 1, 'rejection notification persisted');
    expect_status(request_api('PATCH', '/api/notifications/' . $rejection_notices[0]['id'] . '/read', null, $tokens['a']), 404, 'another user cannot read notification');
    expect_status(request_api('PATCH', '/api/notifications/' . $rejection_notices[0]['id'] . '/read', null, $tokens['b']), 200, 'recipient marks notification read');
    expect_status(request_api('PATCH', '/api/facility-bookings/' . $f1['id'] . '/cancel', ['reason' => 'Unauthorized'], $tokens['b']), 403, 'another requester cannot cancel');
    expect_status(request_api('PATCH', '/api/facility-bookings/' . $f1['id'] . '/cancel', ['reason' => 'Plans changed'], $tokens['a']), 200, 'requester cancels approved facility');
    $freed = expect_status(request_api('GET', '/api/facilities/' . $facility_ids['primary'] . '/availability' . $range, null, $tokens['a']), 200, 'facility availability after cancellation')['data'];
    check($freed['available'] === TRUE, 'cancellation releases facility reservation');
    check(query($db, 'SELECT status FROM facility_bookings WHERE id = ?', [$f2['id']])->fetchColumn() === 'REJECTED', 'cancellation does not revive rejected requests');
    query($db, 'INSERT INTO facility_bookings (booking_reference, requester_id, department_id, facility_id, start_datetime, end_datetime, activity_name, purpose, participants_count, status)
        VALUES (?, ?, ?, ?, UTC_TIMESTAMP() - INTERVAL 2 HOUR, UTC_TIMESTAMP() + INTERVAL 1 HOUR, ?, ?, 1, ?)',
        ['PAST-' . $prefix, $user_ids['a'], $department, $facility_ids['primary'], 'Started fixture', 'Cancellation cutoff', 'APPROVED']);
    $started = (int) $db->lastInsertId();
    expect_status(request_api('PATCH', '/api/facility-bookings/' . $started . '/cancel', ['reason' => 'Too late'], $tokens['a']), 409, 'approved cancellation cutoff enforced');
    echo "Facility workflow verified.\n";

    $asset = ['asset_id' => $asset_ids['quantity'], 'usage_start' => $start, 'expected_return' => $end, 'purpose' => 'Test loan'];
    $q6 = expect_status(request_api('POST', '/api/asset-bookings', $asset + ['quantity_requested' => 6], $tokens['a']), 201, 'six units requested')['data'];
    $q5 = expect_status(request_api('POST', '/api/asset-bookings', $asset + ['quantity_requested' => 5], $tokens['b']), 201, 'five units requested')['data'];
    $q4 = expect_status(request_api('POST', '/api/asset-bookings', $asset + ['quantity_requested' => 4], $tokens['b']), 201, 'four units requested')['data'];
    $approval = expect_status(request_api('PATCH', '/api/asset-bookings/' . $q6['id'] . '/approve', null, $tokens['custodian']), 200, 'six units approved')['data'];
    check(in_array((int) $q5['id'], $approval['automatically_rejected_ids'], TRUE) && !in_array((int) $q4['id'], $approval['automatically_rejected_ids'], TRUE), 'only insufficient-quantity requests rejected');
    $left = expect_status(request_api('GET', '/api/assets/' . $asset_ids['quantity'] . '/availability' . $range, null, $tokens['a']), 200, 'live quantity after approval')['data'];
    check($left['available_quantity'] === 4, 'four units remain');
    expect_status(request_api('PATCH', '/api/asset-bookings/' . $q4['id'] . '/approve', null, $tokens['custodian']), 200, 'remaining four approved');
    $full = expect_status(request_api('GET', '/api/assets/' . $asset_ids['quantity'] . '/availability' . $range, null, $tokens['a']), 200, 'live quantity fully reserved')['data'];
    check($full['available_quantity'] === 0, 'no stock overallocated');
    expect_status(request_api('PATCH', '/api/asset-bookings/' . $q6['id'] . '/cancel', ['reason' => 'Custodian cancellation'], $tokens['custodian']), 200, 'authorized staff cancels approved assets');
    $restored = expect_status(request_api('GET', '/api/assets/' . $asset_ids['quantity'] . '/availability' . $range, null, $tokens['a']), 200, 'stock restored after cancellation')['data'];
    check($restored['available_quantity'] === 6, 'only cancelled quantity released');
    query($db, "UPDATE asset_bookings SET status = 'RELEASED', released_at = UTC_TIMESTAMP(), quantity_released = 4 WHERE id = ?", [$q4['id']]);
    expect_status(request_api('PATCH', '/api/asset-bookings/' . $q4['id'] . '/cancel', ['reason' => 'Cannot bypass return'], $tokens['b']), 409, 'released assets cannot be cancelled');
    echo "Quantity workflow verified.\n";

    $individual = ['asset_id' => $asset_ids['individual'], 'usage_start' => $start, 'expected_return' => $end, 'purpose' => 'Test exact item', 'quantity_requested' => 1];
    expect_status(request_api('POST', '/api/asset-bookings', $individual, $tokens['a']), 422, 'specific selection required');
    $i1 = expect_status(request_api('POST', '/api/asset-bookings', $individual + ['instance_ids' => [$instances[0]]], $tokens['a']), 201, 'first item selected')['data'];
    $i2 = expect_status(request_api('POST', '/api/asset-bookings', $individual + ['instance_ids' => [$instances[0]]], $tokens['b']), 201, 'same item requested pending')['data'];
    $i3 = expect_status(request_api('POST', '/api/asset-bookings', $individual + ['instance_ids' => [$instances[1]]], $tokens['b']), 201, 'different item requested')['data'];
    $decision = expect_status(request_api('PATCH', '/api/asset-bookings/' . $i1['id'] . '/approve', null, $tokens['custodian']), 200, 'specific item approved')['data'];
    $item_detail = expect_status(request_api('GET', '/api/asset-bookings/' . $i1['id'], null, $tokens['a']), 200, 'requester can track selected item tags')['data'];
    check($item_detail['instance_ids'] === [$instances[0]] && count($item_detail['selected_items']) === 1
        && $item_detail['selected_items'][0]['asset_tag'] === $prefix . '.one', 'detail preserves exact IDs and returns only selected item tags');
    expect_status(request_api('GET', '/api/asset-bookings/' . $i1['id'], null, $tokens['b']), 403, 'other requester cannot read selected item details');
    check(in_array((int) $i2['id'], $decision['automatically_rejected_ids'], TRUE) && !in_array((int) $i3['id'], $decision['automatically_rejected_ids'], TRUE), 'exact-item conflicts rejected independently');
    $free = expect_status(request_api('GET', '/api/assets/' . $asset_ids['individual'] . '/availability' . $range, null, $tokens['a']), 200, 'available specific items')['data'];
    check($free['available_quantity'] === 1 && (int) $free['available_instances'][0]['id'] === $instances[1], 'only unreserved item displayed');
    expect_status(request_api('PATCH', '/api/asset-bookings/' . $i1['id'] . '/cancel', ['reason' => 'Item no longer needed'], $tokens['a']), 200, 'selected-item reservation cancelled');
    $items_restored = expect_status(request_api('GET', '/api/assets/' . $asset_ids['individual'] . '/availability' . $range, null, $tokens['a']), 200, 'individual availability after cancellation')['data'];
    check($items_restored['available_quantity'] === 2, 'selected item released without deleting allocation history');
    echo "Individual-item workflow verified.\n";

    $own = array_replace($facility, ['facility_id' => $facility_ids['race']]);
    $self = expect_status(request_api('POST', '/api/facility-bookings', $own, $tokens['coordinator']), 201, 'coordinator own request')['data'];
    $mine = expect_status(request_api('GET', '/api/facility-bookings?mine=1&per_page=100', null, $tokens['coordinator']), 200, 'coordinator can request own-only tracking')['data'];
    check(count($mine) === 1 && (int) $mine[0]['id'] === (int) $self['id'], 'own-only list excludes other users despite view_all grant');
    $all = expect_status(request_api('GET', '/api/facility-bookings?per_page=100', null, $tokens['coordinator']), 200, 'default staff list remains unchanged')['data'];
    check(count($all) > count($mine), 'staff default list still includes authorized campus bookings');
    expect_status(request_api('GET', '/api/asset-bookings?mine=yes', null, $tokens['a']), 422, 'invalid own-only selector rejected');
    $asset_mine = expect_status(request_api('GET', '/api/asset-bookings?mine=1', null, $tokens['custodian']), 200, 'asset staff own-only tracking')['data'];
    check(count($asset_mine) === 0, 'asset staff own-only list excludes requester loans');
    expect_status(request_api('PATCH', '/api/facility-bookings/' . $self['id'] . '/approve', null, $tokens['coordinator']), 403, 'self approval blocked');
    expect_status(request_api('PATCH', '/api/facility-bookings/' . $self['id'] . '/reject', ['reason' => ''], $tokens['coordinator']), 422, 'manual rejection requires reason');
    expect_status(request_api('PATCH', '/api/facility-bookings/' . $self['id'] . '/reject', ['reason' => 'Test withdrawn by coordinator'], $tokens['coordinator']), 200, 'manual rejection');
    $race1 = expect_status(request_api('POST', '/api/facility-bookings', $own, $tokens['a']), 201, 'first concurrent candidate')['data'];
    $race2 = expect_status(request_api('POST', '/api/facility-bookings', $own, $tokens['b']), 201, 'second concurrent candidate')['data'];
    $second_base = rtrim(getenv('CFABS_TEST_SECOND_URL') ?: 'http://127.0.0.1:3013', '/');
    check($second_base !== $base, 'concurrency test requires two independent server processes');
    $multi = curl_multi_init();
    $handles = [];
    foreach ([[$base, $race1['id']], [$second_base, $race2['id']]] as [$server, $id]) {
        $handle = curl_init($server . '/api/facility-bookings/' . $id . '/approve');
        curl_setopt_array($handle, [CURLOPT_CUSTOMREQUEST => 'PATCH', CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_TIMEOUT => 45, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tokens['coordinator']]]);
        curl_multi_add_handle($multi, $handle);
        $handles[] = $handle;
    }
    do {
        curl_multi_exec($multi, $running);
        if ($running) curl_multi_select($multi, 1);
    } while ($running);
    $statuses = [];
    foreach ($handles as $handle) {
        $statuses[] = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
    }
    curl_multi_close($multi);
    sort($statuses);
    check($statuses === [200, 409], 'one simultaneous approval succeeds and the other conflicts');
    check((int) query($db, "SELECT COUNT(*) FROM facility_bookings WHERE facility_id = ? AND status = 'APPROVED'", [$facility_ids['race']])->fetchColumn() === 1, 'database contains exactly one concurrent reservation');
    echo "Passed {$checks} booking and notification checks, including concurrent approval.\n";
} finally {
    if ($user_ids) {
        $ids = array_values($user_ids);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        query($db, "DELETE FROM notifications WHERE recipient_id IN ({$marks})", $ids);
        // Delete only test-owned audit events, including system-generated rejection records.
        foreach (['facility' => 'facility_bookings', 'asset' => 'asset_bookings'] as $kind => $table) {
            query($db, "DELETE FROM audit_logs WHERE entity_type = ? AND entity_id IN (SELECT id FROM {$table} WHERE requester_id IN ({$marks}))", array_merge([$kind . '_booking'], $ids));
        }
        query($db, "DELETE FROM audit_logs WHERE actor_user_id IN ({$marks}) OR (entity_type = 'user' AND entity_id IN ({$marks}))", array_merge($ids, $ids));
        query($db, "DELETE FROM asset_booking_instances WHERE asset_booking_id IN (SELECT id FROM asset_bookings WHERE requester_id IN ({$marks}))", $ids);
        query($db, "DELETE FROM facility_bookings WHERE requester_id IN ({$marks})", $ids);
        query($db, "DELETE FROM asset_bookings WHERE requester_id IN ({$marks})", $ids);
        foreach ($asset_ids as $id) {
            query($db, 'DELETE FROM asset_instances WHERE asset_id = ?', [$id]);
            query($db, 'DELETE FROM assets WHERE id = ?', [$id]);
        }
        foreach ($facility_ids as $id) query($db, 'DELETE FROM facilities WHERE id = ?', [$id]);
        foreach ($category_ids as $table => $id) query($db, "DELETE FROM {$table} WHERE id = ?", [$id]);
        query($db, "DELETE FROM refresh_tokens WHERE user_id IN ({$marks})", $ids);
        query($db, "DELETE FROM users WHERE id IN ({$marks})", $ids);
    }
    if ($department !== null) query($db, 'DELETE FROM departments WHERE id = ? AND department_code = ?', [$department, $prefix]);
}
