<?php
require __DIR__ . '/support/http_database.php';
$users = []; $assets = []; $category = null;
function qr_booking(int $asset, int $quantity, array $items = [], bool $future = FALSE): int {
    global $db, $users, $department, $prefix;
    query($db, "INSERT INTO asset_bookings (booking_reference,requester_id,department_id,asset_id,usage_start,expected_return,quantity_requested,purpose,status)
        VALUES (?,?,?,?,?,?,?,'QR fixture','APPROVED')", [$prefix . bin2hex(random_bytes(3)), $users['faculty'], $department, $asset,
        gmdate('Y-m-d H:i:s', time() + ($future ? 3600 : -1800)), gmdate('Y-m-d H:i:s', time() + 7200), $quantity]);
    $id = (int) $db->lastInsertId();
    foreach ($items as $item) query($db, 'INSERT INTO asset_booking_instances (asset_booking_id,asset_instance_id) VALUES (?,?)', [$id, $item]);
    return $id;
}
function qr_move(int $booking, string $action, array $labels, string $token, array $extra = []): array {
    return request_api('POST', "/api/asset-bookings/{$booking}/{$action}", array_replace([
        'quantity' => 1, 'condition_status' => 'good', 'qr_identifiers' => $labels, 'idempotency_key' => bin2hex(random_bytes(16)),
    ], $extra), $token);
}
try {
    query($db, 'INSERT INTO departments (department_code,department_name) VALUES (?,?)', [$prefix, 'QR fixtures']);
    $department = (int) $db->lastInsertId();
    $roles = query($db, 'SELECT role_name,id FROM roles')->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach (['faculty' => 'faculty_employee', 'custodian' => 'asset_custodian', 'coordinator' => 'booking_admin'] as $name => $role) {
        query($db, 'INSERT INTO users (username,email,password,role,is_active,role_id,department_id) VALUES (?,?,?,?,1,?,?)',
            [$prefix . '.' . $name, $prefix . '.' . $name . '@example.invalid', password_hash($password, PASSWORD_DEFAULT), 'user', $roles[$role], $department]);
        $users[$name] = (int) $db->lastInsertId();
        $tokens[$name] = expect_status(request_api('POST', '/api/auth/login', ['email' => $prefix . '.' . $name . '@example.invalid', 'password' => $password]), 200, $name . ' login')['data']['access_token'];
    }
    query($db, 'INSERT INTO asset_categories (category_name) VALUES (?)', [$prefix]); $category = (int) $db->lastInsertId();
    foreach (['pool' => 'quantity', 'other' => 'quantity', 'individual' => 'individual'] as $name => $mode) {
        $asset = expect_status(request_api('POST', '/api/assets', ['asset_code' => $prefix . '.' . $name, 'asset_name' => 'QR ' . $name,
            'category_id' => $category, 'owning_department_id' => $department, 'tracking_mode' => $mode,
            'total_quantity' => $mode === 'quantity' ? 10 : 0, 'usable_quantity' => $mode === 'quantity' ? 10 : 0], $tokens['custodian']), 201, $name . ' created')['data'];
        $assets[$name] = (int) $asset['id'];
    }
    $labels = [];
    foreach (['pool', 'other'] as $name) {
        $labels[$name] = expect_status(request_api('GET', '/api/qr-labels/asset/' . $assets[$name], null, $tokens['faculty']), 200, 'automatically created pool label')['data'];
    }
    check($labels['pool']['identifier'] !== $labels['other']['identifier'], 'unique opaque identifiers');
    check(preg_match('/\Acfabs:qr:[a-f0-9]{48}\z/', $labels['pool']['payload']) === 1, 'namespaced minimal payload');
    check(!str_contains($labels['pool']['payload'], $prefix), 'no private or resource fields in payload');
    expect_status(request_api('GET', '/api/qr-labels/asset/' . $assets['individual'], null, $tokens['custodian']), 422, 'individual model has no pool label');
    expect_status(request_api('GET', '/api/qr-labels/facility/1', null, $tokens['coordinator']), 422, 'facility QR explicitly excluded');
    check(query($db, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='qr_labels' AND COLUMN_NAME='facility_id'")->fetchColumn() == 0, 'asset-only schema');
    $items = [];
    for ($i = 0; $i < 2; $i++) {
        $item = expect_status(request_api('POST', '/api/assets/' . $assets['individual'] . '/instances', ['asset_tag' => $prefix . '.tag' . $i], $tokens['custodian']), 201, 'item registered')['data'];
        $items[] = (int) $item['id'];
        $labels['item' . $i] = expect_status(request_api('GET', '/api/qr-labels/asset-instance/' . $item['id'], null, $tokens['faculty']), 200, 'item label automatically created')['data'];
    }
    $identifier = $labels['pool']['identifier'];
    expect_status(request_api('GET', '/api/qr/' . $identifier), 401, 'QR requires login');
    expect_status(request_api('GET', '/api/qr/' . str_repeat('0', 48), null, $tokens['faculty']), 404, 'unknown label');
    expect_status(request_api('GET', '/api/qr/not-a-label', null, $tokens['faculty']), 422, 'malformed label');
    $resolved = expect_status(request_api('GET', '/api/qr/' . $identifier, null, $tokens['faculty']), 200, 'requester resolves label')['data'];
    check((int) $resolved['resource']['asset_id'] === $assets['pool'], 'correct resource');
    check(!array_key_exists('requester_id', $resolved['resource']) && !array_key_exists('serial_number', $resolved['resource']), 'minimal permission-scoped response');
    $change = ['action' => 'revoke', 'expected_identifier' => $identifier];
    expect_status(request_api('POST', '/api/qr-labels/asset/' . $assets['pool'], $change, $tokens['faculty']), 403, 'requester cannot revoke');
    expect_status(request_api('POST', '/api/qr-labels/asset/' . $assets['pool'], $change + ['asset_id' => $assets['other']], $tokens['custodian']), 422, 'cannot reassign QR identity');
    expect_status(request_api('POST', '/api/qr-labels/asset/' . $assets['pool'], $change, $tokens['custodian']), 200, 'authorized revocation');
    expect_status(request_api('POST', '/api/qr-labels/asset/' . $assets['pool'], $change, $tokens['custodian']), 200, 'revocation replay');
    check(query($db, "SELECT COUNT(*) FROM audit_logs WHERE actor_user_id=? AND action='qr.revoke'", [$users['custodian']])->fetchColumn() == 1, 'revocation audited once');
    expect_status(request_api('GET', '/api/qr/' . $identifier, null, $tokens['faculty']), 404, 'revoked label rejected');
    $new = expect_status(request_api('POST', '/api/qr-labels/asset/' . $assets['pool'], array_replace($change, ['action' => 'regenerate']), $tokens['custodian']), 200, 'regenerate revoked label')['data'];
    expect_status(request_api('POST', '/api/qr-labels/asset/' . $assets['pool'], array_replace($change, ['action' => 'regenerate']), $tokens['custodian']), 409, 'stale regeneration cannot rotate again');
    expect_status(request_api('GET', '/api/qr/' . $identifier, null, $tokens['faculty']), 404, 'old label remains invalid');
    $identifier = $new['identifier'];
    $booking = qr_booking($assets['pool'], 4);
    $count = query($db, 'SELECT COUNT(*) FROM asset_handovers WHERE asset_booking_id=?', [$booking])->fetchColumn();
    expect_status(request_api('GET', '/api/qr/' . $identifier, null, $tokens['custodian']), 200, 'scan identifies only');
    check(query($db, 'SELECT COUNT(*) FROM asset_handovers WHERE asset_booking_id=?', [$booking])->fetchColumn() == $count, 'scan never releases assets');
    expect_status(qr_move($booking, 'release', [$identifier], $tokens['faculty']), 403, 'scan does not grant release permission');
    expect_status(qr_move($booking, 'release', [$labels['other']['identifier']], $tokens['custodian']), 422, 'wrong pool rejected');
    expect_status(qr_move($booking, 'release', [], $tokens['custodian']), 422, 'empty scan rejected');
    expect_status(qr_move($booking, 'release', [$identifier, $identifier], $tokens['custodian']), 422, 'duplicate scans rejected');
    expect_status(qr_move($booking, 'release', ['bad'], $tokens['custodian']), 422, 'invalid scan rejected');
    $future = qr_booking($assets['other'], 1, [], TRUE);
    expect_status(qr_move($future, 'release', [$labels['other']['identifier']], $tokens['custodian']), 409, 'QR cannot bypass booking period');
    $key = bin2hex(random_bytes(16));
    $released = expect_status(qr_move($booking, 'release', [$identifier], $tokens['custodian'], ['quantity' => 2, 'idempotency_key' => $key]), 200, 'partial QR release')['data'];
    check((int) $released['booking']['quantity_released'] === 2, 'partial quantity preserved');
    $replayed = expect_status(qr_move($booking, 'release', [$identifier], $tokens['custodian'], ['quantity' => 2, 'idempotency_key' => $key]), 200, 'QR replay')['data'];
    check($replayed['replayed'] === TRUE, 'same payload is idempotent');
    expect_status(qr_move($booking, 'release', [$labels['other']['identifier']], $tokens['custodian'], ['quantity' => 2, 'idempotency_key' => $key]), 409, 'same key cannot change scanned label');
    expect_status(qr_move($booking, 'return', [$identifier], $tokens['custodian']), 200, 'partial QR return');
    check(query($db, "SELECT COUNT(*) FROM audit_logs WHERE actor_user_id=? AND action='asset_booking.qr.verified'", [$users['custodian']])->fetchColumn() == 2, 'new QR handovers audited without duplicate replay');
    expect_status(request_api('POST', '/api/qr-labels/asset/' . $assets['pool'], ['action' => 'revoke', 'expected_identifier' => $identifier], $tokens['custodian']), 200, 'revoke while in custody');
    expect_status(qr_move($booking, 'return', [$identifier], $tokens['custodian']), 409, 'revoked label cannot create new return');
    expect_status(qr_move($booking, 'release', [$identifier], $tokens['custodian'], ['quantity' => 2, 'idempotency_key' => $key]), 200, 'committed receipt replay survives revocation');
    $single = qr_booking($assets['individual'], 1, [$items[0]]);
    expect_status(qr_move($single, 'release', [$labels['item1']['identifier']], $tokens['custodian'], ['instance_ids' => [$items[0]]]), 422, 'scan must match selected item');
    expect_status(qr_move($single, 'release', [$labels['item1']['identifier']], $tokens['custodian'], ['instance_ids' => [$items[1]]]), 409, 'scan cannot substitute requester selected item');
    expect_status(qr_move($single, 'release', [$labels['item0']['identifier']], $tokens['custodian'], ['instance_ids' => [$items[0]]]), 200, 'selected individual release');
    expect_status(qr_move($single, 'return', [$labels['item0']['identifier']], $tokens['custodian'], ['instance_ids' => [$items[0]]]), 200, 'same individual returned');
    query($db, "UPDATE assets SET status='inactive' WHERE id=?", [$assets['other']]);
    expect_status(request_api('GET', '/api/qr/' . $labels['other']['identifier'], null, $tokens['custodian']), 404, 'inactive resource cannot resolve');
    expect_status(request_api('GET', '/api/qr-labels/asset/' . $assets['other'], null, $tokens['faculty']), 404, 'inactive labels hidden from requesters');
    expect_status(request_api('GET', '/api/qr-labels/asset/' . $assets['other'], null, $tokens['custodian']), 200, 'manager can maintain inactive labels');
    query($db, 'UPDATE users SET is_active=0 WHERE id=?', [$users['faculty']]);
    expect_status(request_api('GET', '/api/qr/' . $labels['item0']['identifier'], null, $tokens['faculty']), 401, 'inactive account cannot scan');
    echo "Passed {$checks} asset QR checks.\n";
} finally {
    if ($users) {
        $ids = array_values($users); $marks = implode(',', array_fill(0, count($ids), '?'));
        query($db, "DELETE FROM notifications WHERE recipient_id IN ({$marks})", $ids);
        query($db, "DELETE FROM audit_logs WHERE actor_user_id IN ({$marks})", $ids);
        query($db, "DELETE FROM asset_handover_items WHERE handover_id IN (SELECT h.id FROM asset_handovers h JOIN asset_bookings b ON b.id=h.asset_booking_id WHERE b.requester_id IN ({$marks}))", $ids);
        query($db, "DELETE FROM asset_handovers WHERE asset_booking_id IN (SELECT id FROM asset_bookings WHERE requester_id IN ({$marks}))", $ids);
        query($db, "DELETE FROM asset_booking_instances WHERE asset_booking_id IN (SELECT id FROM asset_bookings WHERE requester_id IN ({$marks}))", $ids);
        query($db, "DELETE FROM asset_bookings WHERE requester_id IN ({$marks})", $ids);
        foreach ($assets as $asset) {
            query($db, 'DELETE FROM asset_instances WHERE asset_id=?', [$asset]);
            query($db, 'DELETE FROM assets WHERE id=?', [$asset]);
        }
        if ($category !== null) query($db, 'DELETE FROM asset_categories WHERE id=?', [$category]);
        query($db, "DELETE FROM refresh_tokens WHERE user_id IN ({$marks})", $ids);
        query($db, "DELETE FROM users WHERE id IN ({$marks})", $ids);
    }
    if ($department !== null) query($db, 'DELETE FROM departments WHERE id=? AND department_code=?', [$department, $prefix]);
}
