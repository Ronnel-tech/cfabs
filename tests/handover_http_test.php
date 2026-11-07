<?php
require __DIR__ . '/support/http_database.php';
$users = [];
$assets = [];
$bookings = [];
$category = null;

function movement(string $path, int $quantity, string $condition, string $token, array $extra = []): array {
    return request_api('POST', $path, array_replace(['quantity' => $quantity, 'condition_status' => $condition,
        'idempotency_key' => bin2hex(random_bytes(16))], $extra), $token);
}
function stock(int $asset, string $token): int {
    $range = '?start=' . rawurlencode(gmdate('Y-m-d\TH:i:s\Z', time() + 60)) . '&end=' . rawurlencode(gmdate('Y-m-d\TH:i:s\Z', time() + 3600));
    return expect_status(request_api('GET', '/api/assets/' . $asset . '/availability' . $range, null, $token), 200, 'current stock')['data']['available_quantity'];
}
function approved_fixture(PDO $db, int $asset, int $quantity, array $items = [], bool $future = FALSE): int {
    global $prefix, $users, $department, $bookings;
    // Isolate handover behavior without waiting for an approved future booking to start.
    $start = gmdate('Y-m-d H:i:s', time() + ($future ? 10800 : -3600));
    $end = gmdate('Y-m-d H:i:s', time() + ($future ? 14400 : 7200));
    query($db, 'INSERT INTO asset_bookings (booking_reference, requester_id, department_id, asset_id, usage_start, expected_return,
        quantity_requested, purpose, status, approver_id, approved_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
        [strtoupper(bin2hex(random_bytes(10))), $users['a'], $department, $asset, $start, $end, $quantity, 'Temporary handover fixture', 'APPROVED', $users['custodian']]);
    $id = (int) $db->lastInsertId();
    $bookings[] = $id;
    foreach ($items as $item) query($db, 'INSERT INTO asset_booking_instances (asset_booking_id, asset_instance_id) VALUES (?, ?)', [$id, $item]);
    return $id;
}
function concurrent_releases(int $booking, array $bodies, string $token): array {
    global $base;
    $second = rtrim(getenv('CFABS_TEST_SECOND_URL') ?: 'http://127.0.0.1:3013', '/');
    check($second !== $base, 'concurrent handovers use independent servers');
    $multi = curl_multi_init();
    $handles = [];
    foreach ([[$base, $bodies[0]], [$second, $bodies[1]]] as [$url, $body]) {
        $handle = curl_init($url . '/api/asset-bookings/' . $booking . '/release');
        curl_setopt_array($handle, [CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_RETURNTRANSFER => TRUE, CURLOPT_TIMEOUT => 45,
            CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token]]);
        curl_multi_add_handle($multi, $handle);
        $handles[] = $handle;
    }
    do {
        curl_multi_exec($multi, $running);
        if ($running) curl_multi_select($multi, 1);
    } while ($running);
    $responses = [];
    foreach ($handles as $handle) {
        $responses[] = [curl_getinfo($handle, CURLINFO_RESPONSE_CODE), json_decode(curl_multi_getcontent($handle), TRUE)];
        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
    }
    curl_multi_close($multi);
    return $responses;
}

try {
    query($db, 'INSERT INTO departments (department_code, department_name) VALUES (?, ?)', [$prefix, 'Temporary handover fixtures']);
    $department = (int) $db->lastInsertId();
    $roles = query($db, 'SELECT role_name, id FROM roles')->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach (['a' => 'faculty_employee', 'b' => 'faculty_employee', 'custodian' => 'asset_custodian'] as $name => $role) {
        query($db, 'INSERT INTO users (username, email, password, role, is_active, role_id, department_id) VALUES (?, ?, ?, ?, 1, ?, ?)',
            [$prefix . '.' . $name, $prefix . '.' . $name . '@example.invalid', password_hash($password, PASSWORD_DEFAULT), 'user', $roles[$role], $department]);
        $users[$name] = (int) $db->lastInsertId();
        $tokens[$name] = expect_status(request_api('POST', '/api/auth/login', ['email' => $prefix . '.' . $name . '@example.invalid', 'password' => $password]), 200, $name . ' login')['data']['access_token'];
    }
    query($db, 'INSERT INTO asset_categories (category_name) VALUES (?)', [$prefix]);
    $category = (int) $db->lastInsertId();
    foreach (['normal' => 10, 'damage' => 4, 'partial' => 4, 'timing' => 4, 'race' => 4, 'replay' => 4, 'individual' => 0] as $name => $quantity) {
        query($db, 'INSERT INTO assets (asset_code, asset_name, category_id, owning_department_id, tracking_mode, total_quantity, usable_quantity, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$prefix . '.' . $name, 'Temporary handover stock', $category, $department,
                $name === 'individual' ? 'individual' : 'quantity', $quantity, $quantity, $users['custodian']]);
        $assets[$name] = (int) $db->lastInsertId();
    }
    $id = approved_fixture($db, $assets['normal'], 10);
    $path = '/api/asset-bookings/' . $id;
    expect_status(movement($path . '/release', 1, 'good', $tokens['a']), 403, 'requester cannot release stock');
    expect_status(request_api('GET', $path . '/custody', null, $tokens['b']), 403, 'custody snapshot ownership enforced');
    $snapshot = expect_status(request_api('GET', $path . '/custody', null, $tokens['a']), 200, 'owner custody snapshot')['data'];
    check($snapshot['reconciled'] === TRUE && $snapshot['tracking_mode'] === 'quantity' && $snapshot['totals']['released'] === 0, 'new custody totals are reconciled');

    $key = bin2hex(random_bytes(16));
    $first = expect_status(movement($path . '/release', 6, 'good', $tokens['custodian'], ['idempotency_key' => $key]), 200, 'partial release six')['data'];
    check((int) $first['booking']['quantity_released'] === 6 && $first['booking']['status'] === 'RELEASED', 'partial release totals');
    check(!isset($first['handover']['request_hash']) && !isset($first['handover']['idempotency_key']), 'internal retry metadata excluded');
    $replay = expect_status(movement($path . '/release', 6, 'good', $tokens['custodian'], ['idempotency_key' => $key]), 200, 'same release retry')['data'];
    check($replay['replayed'] === TRUE && $replay['handover']['id'] === $first['handover']['id'], 'retry reuses one event');
    expect_status(movement($path . '/release', 5, 'good', $tokens['custodian'], ['idempotency_key' => $key]), 409, 'key cannot be reused with another payload');
    expect_status(movement($path . '/release', 5, 'good', $tokens['custodian']), 409, 'over-release rejected');
    check(stock($assets['normal'], $tokens['a']) === 0, 'unreleased approved balance remains reserved');
    expect_status(movement($path . '/return', 7, 'good', $tokens['custodian']), 409, 'over-return rejected');
    expect_status(movement($path . '/return', 3, 'good', $tokens['custodian']), 200, 'partial return three');
    check(stock($assets['normal'], $tokens['a']) === 3, 'good partial return frees exactly three');
    expect_status(movement($path . '/release', 4, 'fair', $tokens['custodian']), 200, 'release remaining four');
    $held = expect_status(movement($path . '/return', 7, 'inspection', $tokens['custodian'], ['remarks' => 'Inspect equipment before reuse']), 200, 'remaining stock returned for inspection')['data'];
    check($held['booking']['status'] === 'RETURNED' && stock($assets['normal'], $tokens['a']) === 3, 'inspection stock stays unavailable');
    expect_status(movement('/api/asset-handovers/' . $held['handover']['id'] . '/inspect', 8, 'good', $tokens['custodian']), 409, 'inspection cannot exceed its hold');
    expect_status(movement('/api/asset-handovers/' . $held['handover']['id'] . '/inspect', 2, 'good', $tokens['custodian']), 200, 'partial inspection clears two');
    check(stock($assets['normal'], $tokens['a']) === 5, 'inspection frees only cleared units');
    $snapshot = expect_status(request_api('GET', $path . '/custody', null, $tokens['custodian']), 200, 'staff partial inspection snapshot')['data'];
    check($snapshot['reconciled'] === TRUE && $snapshot['totals']['inspection'] === 5 && count($snapshot['inspection_holds']) === 1, 'partial inspection totals and open hold agree');
    check((int) $snapshot['inspection_holds'][0]['remaining_quantity'] === 5 && (int) $snapshot['inspection_holds'][0]['id'] === (int) $held['handover']['id'], 'inspection source and remaining quantity exposed');

    $done = expect_status(movement('/api/asset-handovers/' . $held['handover']['id'] . '/inspect', 5, 'good', $tokens['custodian']), 200, 'remaining inspection clears')['data'];
    check($done['booking']['status'] === 'COMPLETED' && stock($assets['normal'], $tokens['a']) === 10, 'fully settled booking completes');
    $history = expect_status(request_api('GET', $path . '/handovers', null, $tokens['a']), 200, 'owner handover history')['data'];
    check(count($history) === 6, 'ledger has one row per physical action, not per retry');
    expect_status(request_api('GET', $path . '/handovers', null, $tokens['b']), 403, 'handover history ownership');
    $snapshot = expect_status(request_api('GET', $path . '/custody', null, $tokens['a']), 200, 'completed custody snapshot')['data'];
    check($snapshot['inspection_holds'] === [] && $snapshot['totals']['inspection'] === 0, 'resolved holds disappear from snapshot');
    query($db, 'UPDATE asset_bookings SET quantity_returned = quantity_returned - 1 WHERE id = ?', [$id]);
    $mismatch = expect_status(request_api('GET', $path . '/custody', null, $tokens['a']), 200, 'legacy mismatch can be inspected')['data'];
    check($mismatch['reconciled'] === FALSE, 'snapshot flags ledger mismatch without inventing records');
    query($db, 'UPDATE asset_bookings SET quantity_returned = quantity_returned + 1 WHERE id = ?', [$id]);
    echo "Partial quantities, inspection and safe retries verified.\n";

    $damage = approved_fixture($db, $assets['damage'], 4);
    $future = approved_fixture($db, $assets['damage'], 2, [], TRUE);
    $path = '/api/asset-bookings/' . $damage;
    expect_status(movement($path . '/release', 4, 'good', $tokens['custodian']), 200, 'damage fixture released');
    expect_status(movement($path . '/return', 2, 'damaged', $tokens['custodian']), 422, 'damage requires notes');
    expect_status(movement($path . '/return', 2, 'damaged', $tokens['custodian'], ['remarks' => 'Two broken units']), 200, 'damage recorded');
    $inspection = expect_status(movement($path . '/return', 1, 'inspection', $tokens['custodian'], ['remarks' => 'One requires inspection']), 200, 'damaged pool inspection hold')['data'];
    $loss_key = bin2hex(random_bytes(16));
    $loss = expect_status(movement($path . '/return', 1, 'lost', $tokens['custodian'], ['remarks' => 'Unit reported lost', 'idempotency_key' => $loss_key]), 200, 'lost unit accounted separately')['data'];
    check((int) $loss['booking']['quantity_returned'] === 3 && (int) $loss['booking']['quantity_lost'] === 1, 'loss is not a physical return');
    check(stock($assets['damage'], $tokens['a']) === 0, 'damage loss and inspection never double-count free stock');
    $inspection_done = expect_status(movement('/api/asset-handovers/' . $inspection['handover']['id'] . '/inspect', 1, 'damaged', $tokens['custodian'], ['remarks' => 'Inspection confirmed damage']), 200, 'inspection damage recorded')['data'];
    check($inspection_done['booking']['status'] === 'COMPLETED', 'loss and inspected returns settle booking');
    check((int) query($db, 'SELECT usable_quantity FROM assets WHERE id = ?', [$assets['damage']])->fetchColumn() === 0, 'unusable stock removed once');
    expect_status(movement($path . '/return', 1, 'lost', $tokens['custodian'], ['remarks' => 'Unit reported lost', 'idempotency_key' => $loss_key]), 200, 'loss retry after completion');
    check((int) query($db, 'SELECT usable_quantity FROM assets WHERE id = ?', [$assets['damage']])->fetchColumn() === 0, 'loss retry does not subtract stock twice');
    check((int) query($db, "SELECT COUNT(*) FROM notifications WHERE related_entity_type = 'asset_booking' AND related_entity_id = ? AND notification_type = 'asset_booking.inventory_changed'", [$future])->fetchColumn() > 0, 'affected future reservations notified for review');
    check(query($db, 'SELECT status FROM asset_bookings WHERE id = ?', [$future])->fetchColumn() === 'APPROVED', 'approved future requests are not silently cancelled');

    $partial = approved_fixture($db, $assets['partial'], 4);
    $path = '/api/asset-bookings/' . $partial;
    expect_status(movement($path . '/release', 2, 'good', $tokens['custodian']), 200, 'underfilled booking release');
    expect_status(request_api('POST', $path . '/complete', ['reason' => 'Still outstanding'], $tokens['custodian']), 409, 'cannot complete with unreturned stock');
    expect_status(movement($path . '/return', 2, 'good', $tokens['custodian']), 200, 'underfilled booking return');
    expect_status(request_api('POST', $path . '/complete', ['reason' => 'Remaining units no longer required'], $tokens['custodian']), 200, 'explicit completion closes unused approved balance');
    check(stock($assets['partial'], $tokens['a']) === 4, 'explicit completion releases unused reservation');
    $early = approved_fixture($db, $assets['timing'], 1, [], TRUE);
    expect_status(movement('/api/asset-bookings/' . $early . '/release', 1, 'good', $tokens['custodian']), 409, 'release before booking window forbidden');
    echo "Damage, loss, completion and release timing verified.\n";

    $items = [];
    foreach (['first', 'second'] as $name) {
        query($db, 'INSERT INTO asset_instances (asset_id, asset_tag) VALUES (?, ?)', [$assets['individual'], $prefix . '.' . $name]);
        $items[] = (int) $db->lastInsertId();
    }
    $individual = approved_fixture($db, $assets['individual'], 2, $items);
    $path = '/api/asset-bookings/' . $individual;
    expect_status(movement($path . '/release', 1, 'good', $tokens['custodian']), 422, 'individual release requires item IDs');
    expect_status(movement($path . '/release', 1, 'good', $tokens['custodian'], ['instance_ids' => [$items[0]]]), 200, 'one selected item released');
    $snapshot = expect_status(request_api('GET', $path . '/custody', null, $tokens['a']), 200, 'individual custody snapshot')['data'];
    check($snapshot['outstanding_instance_ids'] === [$items[0]] && $snapshot['unreleased_instance_ids'] === [$items[1]], 'individual release and return choices come from complete ledger');

    $item_hold = expect_status(movement($path . '/return', 1, 'inspection', $tokens['custodian'], ['instance_ids' => [$items[0]], 'remarks' => 'Inspect serial item']), 200, 'specific item held')['data'];
    check(stock($assets['individual'], $tokens['a']) === 0, 'held item and unreleased selected item unavailable');
    $snapshot = expect_status(request_api('GET', $path . '/custody', null, $tokens['a']), 200, 'individual inspection snapshot')['data'];
    check($snapshot['outstanding_instance_ids'] === [] && $snapshot['inspection_holds'][0]['instance_ids'] === [$items[0]], 'returned item moves from outstanding to exact inspection source');

    expect_status(movement('/api/asset-handovers/' . $item_hold['handover']['id'] . '/inspect', 1, 'good', $tokens['custodian'], ['instance_ids' => [$items[0]]]), 200, 'specific item inspected');
    check(stock($assets['individual'], $tokens['a']) === 1, 'only returned inspected item available');
    expect_status(movement($path . '/release', 1, 'good', $tokens['custodian'], ['instance_ids' => [$items[0]]]), 409, 'same item cannot be released twice under one booking');
    expect_status(movement($path . '/release', 1, 'fair', $tokens['custodian'], ['instance_ids' => [$items[1]]]), 200, 'remaining selected item released');
    $lost_item = expect_status(movement($path . '/return', 1, 'lost', $tokens['custodian'], ['instance_ids' => [$items[1]], 'remarks' => 'Second item lost']), 200, 'specific lost item recorded')['data'];
    check($lost_item['booking']['status'] === 'COMPLETED' && stock($assets['individual'], $tokens['a']) === 1, 'specific item loss preserves correct availability');

    $race = approved_fixture($db, $assets['race'], 4);
    $responses = concurrent_releases($race, [
        ['quantity' => 3, 'condition_status' => 'good', 'idempotency_key' => bin2hex(random_bytes(16))],
        ['quantity' => 3, 'condition_status' => 'good', 'idempotency_key' => bin2hex(random_bytes(16))],
    ], $tokens['custodian']);
    $statuses = array_column($responses, 0); sort($statuses);
    check($statuses === [200, 409], 'simultaneous batches cannot over-release');
    check((int) query($db, 'SELECT quantity_released FROM asset_bookings WHERE id = ?', [$race])->fetchColumn() === 3, 'concurrent release total remains within approval');
    $same = approved_fixture($db, $assets['replay'], 4);
    $body = ['quantity' => 2, 'condition_status' => 'good', 'idempotency_key' => bin2hex(random_bytes(16))];
    $responses = concurrent_releases($same, [$body, $body], $tokens['custodian']);
    check(array_column($responses, 0) === [200, 200], 'concurrent retries both return success');
    check((int) query($db, 'SELECT COUNT(*) FROM asset_handovers WHERE asset_booking_id = ?', [$same])->fetchColumn() === 1, 'concurrent same-key requests create one event');
    check((int) query($db, 'SELECT quantity_released FROM asset_bookings WHERE id = ?', [$same])->fetchColumn() === 2, 'concurrent retries do not duplicate physical release');
    echo "Passed {$checks} partial handover and inventory checks.\n";
} finally {
    if ($users) {
        $ids = array_values($users);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        query($db, "DELETE FROM notifications WHERE recipient_id IN ({$marks})", $ids);
        query($db, "DELETE FROM audit_logs WHERE actor_user_id IN ({$marks})", $ids);
        query($db, "DELETE FROM asset_handover_items WHERE handover_id IN (SELECT h.id FROM asset_handovers h JOIN asset_bookings b ON b.id = h.asset_booking_id WHERE b.requester_id IN ({$marks}))", $ids);
        query($db, "DELETE FROM asset_handovers WHERE source_handover_id IS NOT NULL AND asset_booking_id IN (SELECT id FROM asset_bookings WHERE requester_id IN ({$marks}))", $ids);
        query($db, "DELETE FROM asset_handovers WHERE asset_booking_id IN (SELECT id FROM asset_bookings WHERE requester_id IN ({$marks}))", $ids);
        query($db, "DELETE FROM asset_booking_instances WHERE asset_booking_id IN (SELECT id FROM asset_bookings WHERE requester_id IN ({$marks}))", $ids);
        query($db, "DELETE FROM asset_bookings WHERE requester_id IN ({$marks})", $ids);
        foreach ($assets as $asset) {
            query($db, 'DELETE FROM asset_instances WHERE asset_id = ?', [$asset]);
            query($db, 'DELETE FROM assets WHERE id = ?', [$asset]);
        }
        if ($category !== null) query($db, 'DELETE FROM asset_categories WHERE id = ?', [$category]);
        query($db, "DELETE FROM refresh_tokens WHERE user_id IN ({$marks})", $ids);
        query($db, "DELETE FROM users WHERE id IN ({$marks})", $ids);
    }
    if ($department !== null) query($db, 'DELETE FROM departments WHERE id = ?', [$department]);
}
