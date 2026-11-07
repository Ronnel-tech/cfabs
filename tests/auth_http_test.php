<?php
require __DIR__ . '/support/http_database.php';
$names = array_map(fn($name) => $prefix . '.' . $name, ['faculty', 'admin', 'unassigned', 'created', 'bootstrap']);

try {
    expect_status(request_api('GET', '/api/auth/me'), 401, 'anonymous profile blocked');
    expect_status(request_api('GET', '/api/users'), 401, 'anonymous user directory blocked');
    check(request_api('GET', '/admin-bootstrap')[0] === 404, 'web bootstrap blocked');
    expect_status(request_api('POST', '/api/auth/login', null, null, '{'), 400, 'malformed JSON rejected');
    expect_status(request_api('POST', '/api/auth/login', ['email' => [], 'password' => []]), 422, 'wrong credential types rejected');

    query($db, 'INSERT INTO departments (department_code, department_name) VALUES (?, ?)', [$prefix, 'Temporary integration fixture']);
    $department = (int) $db->lastInsertId();
    $roles = query($db, 'SELECT role_name, id FROM roles')->fetchAll(PDO::FETCH_KEY_PAIR);
    $ids = [];
    foreach (['faculty' => 'faculty_employee', 'admin' => 'system_admin', 'unassigned' => null] as $name => $role) {
        query($db, 'INSERT INTO users (username, email, password, role, is_active, role_id, department_id)
            VALUES (?, ?, ?, ?, 1, ?, ?)', [$prefix . '.' . $name, $prefix . '.' . $name . '@example.invalid',
                password_hash($password, PASSWORD_DEFAULT), 'user', $role === null ? null : $roles[$role], $department]);
        $ids[$name] = (int) $db->lastInsertId();
    }
    expect_status(request_api('POST', '/api/auth/login', ['email' => $prefix . '.faculty@example.invalid', 'password' => 'wrong']), 401, 'wrong password rejected');
    $faculty = expect_status(request_api('POST', '/api/auth/login', ['email' => $prefix . '.faculty@example.invalid', 'password' => $password]), 200, 'faculty login preserves password spaces')['data'];
    $stored = query($db, 'SELECT token FROM refresh_tokens WHERE user_id = ?', [$ids['faculty']])->fetchColumn();
    check(is_string($stored) && hash_equals(hash_hmac('sha256', $faculty['refresh_token'], $environment['REFRESH_TOKEN_KEY']), $stored), 'refresh token stored as native HMAC');
    $me = expect_status(request_api('GET', '/api/auth/me', null, $faculty['access_token']), 200, 'authenticated profile')['data'];
    check(!isset($me['password']) && !isset($me['password_hash']), 'profile excludes password');
    check(in_array('facility_booking.create', $me['permissions'], TRUE) && !in_array('facility_booking.approve', $me['permissions'], TRUE), 'faculty permissions');
    expect_status(request_api('GET', '/api/users', null, $faculty['access_token']), 403, 'faculty user management blocked');
    expect_status(request_api('GET', '/api/permissions', null, $faculty['access_token']), 403, 'faculty permission management blocked');
    expect_status(request_api('GET', '/api/users/' . $ids['faculty'], null, $faculty['access_token']), 200, 'own profile allowed');
    expect_status(request_api('GET', '/api/users/' . $ids['admin'], null, $faculty['access_token']), 403, 'another profile blocked');
    $forged = signed_claims(['sub' => $ids['faculty'], 'iat' => time(), 'exp' => time() + 60, 'iss' => 'cfabs',
        'aud' => 'cfabs-clients', 'type' => 'access', 'role' => 'system_admin', 'scopes' => ['user.manage']], $environment['JWT_SECRET']);
    expect_status(request_api('GET', '/api/users', null, $forged), 403, 'privileged JWT claims cannot override database role');
    $new = expect_status(request_api('POST', '/api/auth/refresh', ['refresh_token' => $faculty['refresh_token']]), 200, 'refresh rotation')['data'];
    check($new['refresh_token'] !== $faculty['refresh_token'], 'refresh token changed');
    expect_status(request_api('POST', '/api/auth/refresh', ['refresh_token' => $faculty['refresh_token']]), 401, 'refresh replay rejected');
    expect_status(request_api('POST', '/api/auth/refresh', ['refresh_token' => $new['access_token']]), 401, 'access token cannot refresh');
    $admin = expect_status(request_api('POST', '/api/auth/login', ['email' => $prefix . '.admin@example.invalid', 'password' => $password]), 200, 'administrator login')['data'];
    $admin_profile = expect_status(request_api('GET', '/api/auth/me', null, $admin['access_token']), 200, 'administrator profile')['data'];
    check(in_array('user.manage', $admin_profile['permissions'], TRUE)
        && !in_array('facility_booking.approve', $admin_profile['permissions'], TRUE)
        && !in_array('asset_booking.approve', $admin_profile['permissions'], TRUE), 'administrator has no implicit business approval');
    expect_status(request_api('POST', '/api/auth/logout', ['refresh_token' => $admin['refresh_token']], $new['access_token']), 401, 'logout cannot revoke another user token');
    $created = expect_status(request_api('POST', '/api/users', [
        'username' => $prefix . '.created', 'email' => $prefix . '.created@example.invalid', 'password' => $password,
        'first_name' => 'Test', 'last_name' => 'Fixture', 'department_id' => $department,
        'is_active' => FALSE, 'role' => 'admin',
    ], $admin['access_token']), 201, 'administrator creates account')['data'];
    check($created['role_name'] === 'faculty_employee' && (int) $created['is_active'] === 1 && !isset($created['password']), 'untrusted extra fields cannot escalate role or leak password');
    expect_status(request_api('GET', '/api/users?per_page=2&page=1', null, $admin['access_token']), 200, 'administrator paginated directory');
    expect_status(request_api('GET', '/api/users?per_page=1000', null, $admin['access_token']), 422, 'pagination bounded');
    expect_status(request_api('PATCH', '/api/users/' . $ids['faculty'] . '/status', ['is_active' => FALSE], $admin['access_token']), 200, 'administrator disables account');
    expect_status(request_api('GET', '/api/auth/me', null, $new['access_token']), 401, 'disabled account access denied immediately');
    expect_status(request_api('POST', '/api/auth/refresh', ['refresh_token' => $new['refresh_token']]), 401, 'disabled account refresh denied');
    check((int) query($db, 'SELECT COUNT(*) FROM refresh_tokens WHERE user_id = ?', [$ids['faculty']])->fetchColumn() === 0, 'disable revokes refresh tokens');
    expect_status(request_api('PATCH', '/api/users/' . $ids['admin'] . '/status', ['is_active' => FALSE], $admin['access_token']), 422, 'self disable blocked');
    $legacy = expect_status(request_api('POST', '/api/auth/login', ['email' => $prefix . '.unassigned@example.invalid', 'password' => $password]), 200, 'legacy unassigned account can authenticate')['data'];
    expect_status(request_api('GET', '/api/users', null, $legacy['access_token']), 403, 'unassigned account has no management permissions');
    expect_status(request_api('POST', '/api/auth/logout', ['refresh_token' => $admin['refresh_token']], $admin['access_token']), 200, 'logout');
    expect_status(request_api('POST', '/api/auth/refresh', ['refresh_token' => $admin['refresh_token']]), 401, 'logged-out refresh denied');

    $process = proc_open([PHP_BINARY, 'lava', 'admin:create', $prefix . '.bootstrap', '--email=' . $prefix . '.bootstrap@example.invalid'],
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $root);
    fwrite($pipes[0], $password . PHP_EOL);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
    check(proc_close($process) === 0 && str_contains($output, 'System administrator created.'), 'CLI admin bootstrap');
    $bootstrap = query($db, 'SELECT u.password, r.role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.username = ?', [$prefix . '.bootstrap'])->fetch(PDO::FETCH_ASSOC);
    check($bootstrap && password_verify($password, $bootstrap['password']) && $bootstrap['role_name'] === 'system_admin', 'CLI bootstrap hashes supplied password and assigns explicit role');
    echo "Passed {$checks} authentication and authorization checks.\n";
} finally {
    // Only this run's random, exact usernames and department are eligible for cleanup.
    $marks = implode(',', array_fill(0, count($names), '?'));
    $fixture_ids = query($db, "SELECT id FROM users WHERE username IN ({$marks})", $names)->fetchAll(PDO::FETCH_COLUMN);
    if ($fixture_ids) {
        $ids_sql = implode(',', array_fill(0, count($fixture_ids), '?'));
        query($db, "DELETE FROM audit_logs WHERE actor_user_id IN ({$ids_sql}) OR (entity_type = 'user' AND entity_id IN ({$ids_sql}))", array_merge($fixture_ids, $fixture_ids));
        query($db, "DELETE FROM refresh_tokens WHERE user_id IN ({$ids_sql})", $fixture_ids);
        query($db, "DELETE FROM notifications WHERE recipient_id IN ({$ids_sql})", $fixture_ids);
        query($db, "DELETE FROM users WHERE id IN ({$ids_sql})", $fixture_ids);
    }
    if ($department !== null) query($db, 'DELETE FROM departments WHERE id = ? AND department_code = ?', [$department, $prefix]);
}

