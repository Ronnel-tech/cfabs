<?php
require __DIR__ . '/support/http_database.php';
$users = [];
$roles_created = [];
$departments = [];

function complete_administrators(PDO $db): int {
    return (int) query($db, "SELECT COUNT(*) FROM (SELECT u.id FROM users u
        JOIN role_permissions rp ON rp.role_id = u.role_id JOIN permissions p ON p.id = rp.permission_id
        WHERE u.is_active = 1 AND p.permission_key IN ('user.manage','role.manage','permission.manage')
        GROUP BY u.id HAVING COUNT(DISTINCT p.permission_key) = 3) administrators")->fetchColumn();
}

try {
    $roles = query($db, 'SELECT role_name, id FROM roles')->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach (['active', 'inactive'] as $status) {
        query($db, 'INSERT INTO departments (department_code, department_name, status) VALUES (?, ?, ?)', [$prefix . '.' . $status, 'Temporary access fixture', $status]);
        $departments[$status] = (int) $db->lastInsertId();
    }
    foreach (['admin' => 'system_admin', 'staff' => 'faculty_employee', 'limited' => 'faculty_employee'] as $name => $role) {
        query($db, 'INSERT INTO users (username, email, password, role, is_active, role_id, department_id)
            VALUES (?, ?, ?, ?, 1, ?, ?)', [$prefix . '.' . $name, $prefix . '.' . $name . '@example.invalid',
                password_hash($password, PASSWORD_DEFAULT), 'user', $roles[$role], $departments['active']]);
        $users[$name] = (int) $db->lastInsertId();
        $login = expect_status(request_api('POST', '/api/auth/login', ['email' => $prefix . '.' . $name . '@example.invalid', 'password' => $password]), 200, $name . ' login')['data'];
        $tokens[$name] = $login['access_token'];
        $refresh[$name] = $login['refresh_token'];
    }
    expect_status(request_api('POST', '/api/roles', ['role_name' => $prefix . '_reader'], $tokens['staff']), 403, 'ordinary user cannot create roles');
    expect_status(request_api('PATCH', '/api/users/' . $users['staff'], ['role_id' => $roles['system_admin']], $tokens['staff']), 422, 'profile cannot assign privileges');
    $profile = expect_status(request_api('PATCH', '/api/users/' . $users['staff'], ['first_name' => 'Updated', 'last_name' => 'Employee'], $tokens['staff']), 200, 'own name update')['data'];
    check($profile['first_name'] === 'Updated' && !isset($profile['password']), 'profile safe fields only: ' . json_encode([
        'first_name' => $profile['first_name'] ?? null, 'last_name' => $profile['last_name'] ?? null, 'fields' => array_keys($profile),
    ]));
    expect_status(request_api('PATCH', '/api/users/' . $users['admin'], ['first_name' => 'Unauthorized'], $tokens['staff']), 403, 'another user profile blocked');
    expect_status(request_api('PATCH', '/api/users/' . $users['staff'] . '/department', ['department_id' => $departments['inactive']], $tokens['admin']), 422, 'inactive department assignment rejected');
    expect_status(request_api('PATCH', '/api/users/' . $users['staff'] . '/department', ['department_id' => $departments['active']], $tokens['admin']), 200, 'active department assignment');
    expect_status(request_api('POST', '/api/roles', ['role_name' => $prefix . '_invalid', 'is_system_role' => TRUE], $tokens['admin']), 422, 'system role flag cannot be assigned');
    expect_status(request_api('PATCH', '/api/roles/' . $roles['system_admin'], ['role_name' => $prefix . '_renamed_system'], $tokens['admin']), 409, 'system role name protected');

    foreach (['reader', 'limited', 'control'] as $name) {
        $role = expect_status(request_api('POST', '/api/roles', ['role_name' => $prefix . '_' . $name, 'description' => 'Temporary test role'], $tokens['admin']), 201, $name . ' role created')['data'];
        $roles_created[$name] = (int) $role['id'];
        check((int) $role['is_system_role'] === 0, 'new role is not a system role');
    }
    expect_status(request_api('POST', '/api/roles', ['role_name' => $prefix . '_reader'], $tokens['admin']), 409, 'duplicate role name rejected');
    expect_status(request_api('PATCH', '/api/roles/' . $roles_created['reader'], ['description' => 'Updated test description'], $tokens['admin']), 200, 'role metadata edited');
    expect_status(request_api('PUT', '/api/roles/' . $roles_created['reader'] . '/permissions', ['permission_keys' => ['unknown.permission']], $tokens['admin']), 422, 'unknown permission rejected');
    expect_status(request_api('PUT', '/api/roles/' . $roles_created['reader'] . '/permissions', ['permission_keys' => ['asset.view', 'asset.view']], $tokens['admin']), 422, 'duplicate grant rejected');
    expect_status(request_api('PUT', '/api/roles/' . $roles_created['reader'] . '/permissions', ['permission_keys' => ['asset.view']], $tokens['admin']), 200, 'explicit initial grants');
    expect_status(request_api('PATCH', '/api/users/' . $users['staff'] . '/role', ['role_id' => $roles_created['reader']], $tokens['admin']), 200, 'existing account assigned custom role');
    check((int) query($db, 'SELECT COUNT(*) FROM refresh_tokens WHERE user_id = ?', [$users['staff']])->fetchColumn() === 0, 'role assignment revokes refresh tokens');
    $staff = expect_status(request_api('GET', '/api/auth/me', null, $tokens['staff']), 200, 'existing token uses current role')['data'];
    check($staff['role_name'] === $prefix . '_reader' && $staff['permissions'] === ['asset.view'], 'database grants replace old token privileges');
    expect_status(request_api('GET', '/api/facilities', null, $tokens['staff']), 403, 'old facility access revoked immediately');
    expect_status(request_api('GET', '/api/assets', null, $tokens['staff']), 200, 'explicit asset access allowed');
    expect_status(request_api('POST', '/api/auth/refresh', ['refresh_token' => $refresh['staff']]), 401, 'pre-assignment refresh token unusable');
    expect_status(request_api('PUT', '/api/roles/' . $roles_created['reader'] . '/permissions', ['permission_keys' => ['facility.view', 'facility_booking.approve']], $tokens['admin']), 200, 'business approval explicitly granted');
    $updated = expect_status(request_api('GET', '/api/auth/me', null, $tokens['staff']), 200, 'grant changes visible without re-login')['data'];
    check(in_array('facility_booking.approve', $updated['permissions'], TRUE), 'explicit approval permission effective');
    expect_status(request_api('GET', '/api/assets', null, $tokens['staff']), 403, 'removed grant takes effect on existing access token');
    expect_status(request_api('GET', '/api/roles/' . $roles_created['reader'] . '/permissions', null, $tokens['staff']), 403, 'grant administration remains protected');
    expect_status(request_api('PUT', '/api/roles/' . $roles_created['limited'] . '/permissions', ['permission_keys' => ['user.manage']], $tokens['admin']), 200, 'limited account-management role');
    expect_status(request_api('PATCH', '/api/users/' . $users['limited'] . '/role', ['role_id' => $roles_created['limited']], $tokens['admin']), 200, 'limited administrator assigned');
    expect_status(request_api('GET', '/api/users', null, $tokens['limited']), 200, 'delegated user management works');
    expect_status(request_api('PATCH', '/api/users/' . $users['staff'] . '/role', ['role_id' => $roles['system_admin']], $tokens['limited']), 403, 'user.manage alone cannot assign roles');
    echo "Role assignment, explicit grants and live revocation verified.\n";

    $full = ['user.manage', 'role.manage', 'permission.manage'];
    expect_status(request_api('PUT', '/api/roles/' . $roles_created['control'] . '/permissions', ['permission_keys' => $full], $tokens['admin']), 200, 'explicit control role configured');
    expect_status(request_api('PATCH', '/api/users/' . $users['admin'] . '/role', ['role_id' => $roles_created['control']], $tokens['admin']), 200, 'administrator moved to test-owned control role');
    if (complete_administrators($db) === 1) {
        expect_status(request_api('PATCH', '/api/users/' . $users['admin'] . '/role', ['role_id' => $roles['faculty_employee']], $tokens['admin']), 409, 'last administrator role protected');
        expect_status(request_api('PUT', '/api/roles/' . $roles_created['control'] . '/permissions', ['permission_keys' => []], $tokens['admin']), 409, 'last administrator grants protected');
        expect_status(request_api('PATCH', '/api/users/' . $users['admin'] . '/status', ['is_active' => FALSE], $tokens['limited']), 409, 'delegated administrator cannot disable last complete administrator');
        check((int) query($db, 'SELECT is_active FROM users WHERE id = ?', [$users['admin']])->fetchColumn() === 1, 'blocked deactivation rolled back');
    } else {
        echo "Last-administrator removal checks skipped: other complete administrators exist.\n";
    }
    expect_status(request_api('PATCH', '/api/users/' . $users['staff'] . '/role', ['role_id' => $roles_created['control']], $tokens['admin']), 200, 'second test administrator assigned');
    if (complete_administrators($db) === 2) {
        $second = rtrim(getenv('CFABS_TEST_SECOND_URL') ?: 'http://127.0.0.1:3013', '/');
        check($second !== $base, 'concurrent administration uses independent servers');
        $multi = curl_multi_init();
        $handles = [];
        foreach ([[$base, 'admin'], [$second, 'staff']] as [$url, $name]) {
            $handle = curl_init($url . '/api/users/' . $users[$name] . '/role');
            curl_setopt_array($handle, [CURLOPT_CUSTOMREQUEST => 'PATCH', CURLOPT_RETURNTRANSFER => TRUE, CURLOPT_TIMEOUT => 45,
                CURLOPT_POSTFIELDS => json_encode(['role_id' => (int) $roles['faculty_employee']]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $tokens[$name]]]);
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
        check($statuses === [200, 409], 'simultaneous demotions preserve one complete administrator');
        check(complete_administrators($db) === 1, 'one complete administrator remains');
    } else {
        echo "Concurrent last-administrator check skipped: other complete administrators exist.\n";
    }
    check((int) query($db, "SELECT COUNT(*) FROM audit_logs WHERE entity_type = 'role' AND entity_id = ?", [$roles_created['reader']])->fetchColumn() >= 4, 'role metadata and permission changes audited');
    echo "Passed {$checks} access-administration checks.\n";
} finally {
    if ($users) {
        $ids = array_values($users);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        query($db, "DELETE FROM audit_logs WHERE actor_user_id IN ({$marks}) OR (entity_type = 'user' AND entity_id IN ({$marks}))", [...$ids, ...$ids]);
        query($db, "DELETE FROM refresh_tokens WHERE user_id IN ({$marks})", $ids);
        query($db, "DELETE FROM notifications WHERE recipient_id IN ({$marks})", $ids);
        query($db, "DELETE FROM users WHERE id IN ({$marks})", $ids);
    }
    foreach ($roles_created as $id) {
        query($db, "DELETE FROM audit_logs WHERE entity_type = 'role' AND entity_id = ?", [$id]);
        query($db, 'DELETE FROM role_permissions WHERE role_id = ?', [$id]);
        query($db, 'DELETE FROM roles WHERE id = ?', [$id]);
    }
    foreach ($departments as $id) query($db, 'DELETE FROM departments WHERE id = ?', [$id]);
}
