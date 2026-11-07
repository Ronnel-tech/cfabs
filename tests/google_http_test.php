<?php
require __DIR__ . '/support/http_database.php';
$id = null;
$challenge_tokens = [];
try {
    $config = expect_status(request_api('GET', '/api/auth/google/config'), 200, 'public Google configuration')['data'];
    check(isset($config['enabled'], $config['client_id']) && !isset($config['client_secret']), 'configuration exposes no client secret');
    expect_status(request_api('GET', '/api/auth/google/link'), 401, 'anonymous link status blocked');
    expect_status(request_api('POST', '/api/auth/google/unlink', ['password' => 'wrong']), 401, 'anonymous unlink blocked');
    if (!$config['enabled']) {
        expect_status(request_api('POST', '/api/auth/google/challenge', ['intent' => 'login']), 503, 'unconfigured Google disabled');
    } else {
        expect_status(request_api('POST', '/api/auth/google/challenge', null, null, '{'), 400, 'invalid JSON rejected');
        expect_status(request_api('POST', '/api/auth/google/challenge', ['intent' => 'other']), 422, 'invalid intent rejected');
        expect_status(request_api('POST', '/api/auth/google/challenge', ['intent' => 'link']), 401, 'anonymous link challenge blocked');
        $challenge = expect_status(request_api('POST', '/api/auth/google/challenge', ['intent' => 'login']), 200, 'login challenge')['data'];
        $challenge_tokens[] = $challenge['challenge_token'];
        check(strlen($challenge['nonce']) === 64 && strlen($challenge['challenge_token']) === 64 && $challenge['nonce'] !== $challenge['challenge_token'], 'nonce and secret are separate random values');
        $stored = query($db, 'SELECT * FROM google_challenges WHERE token_hash = ?', [hash('sha256', $challenge['challenge_token'])])->fetch(PDO::FETCH_ASSOC);
        check($stored && $stored['nonce_hash'] === hash('sha256', $challenge['nonce']) && $stored['user_id'] === null, 'server stores challenge hashes');
        expect_status(request_api('POST', '/api/auth/google/login', ['credential' => [], 'challenge_token' => []]), 422, 'invalid credential types rejected');
        expect_status(request_api('POST', '/api/auth/google/login', ['credential' => 'broken', 'challenge_token' => $challenge['challenge_token']]), 401, 'malformed credential rejected');
        expect_status(request_api('POST', '/api/auth/google/link', []), 401, 'anonymous linking blocked');
    }
    query($db, 'INSERT INTO users (username,email,password,role,is_active) VALUES (?,?,?,?,1)', [$prefix, $prefix.'@example.invalid', password_hash($password, PASSWORD_DEFAULT), 'user']);
    $id = (int) $db->lastInsertId();
    $tokens = expect_status(request_api('POST', '/api/auth/login', ['email' => $prefix.'@example.invalid', 'password' => $password]), 200, 'campus password still works')['data'];
    $bearer = $tokens['access_token'];
    $status = expect_status(request_api('GET', '/api/auth/google/link', null, $bearer), 200, 'own link status');
    check(array_key_exists('data', $status) && $status['data'] === null, 'unlinked status is null');
    if ($config['enabled']) {
        $link = expect_status(request_api('POST', '/api/auth/google/challenge', ['intent' => 'link'], $bearer), 200, 'authenticated linking challenge')['data'];
        $challenge_tokens[] = $link['challenge_token'];
        check((int) query($db, 'SELECT user_id FROM google_challenges WHERE token_hash = ?', [hash('sha256', $link['challenge_token'])])->fetchColumn() === $id, 'link challenge bound to authenticated user');
        expect_status(request_api('POST', '/api/auth/google/link', ['credential' => 'broken', 'challenge_token' => $link['challenge_token'], 'password' => $password], $bearer), 422, 'invalid Google credential does not expire campus session');
    }
    expect_status(request_api('POST', '/api/auth/google/unlink', ['password' => 'wrong'], $bearer), 422, 'wrong unlink password rejected');
    expect_status(request_api('POST', '/api/auth/google/unlink', ['password' => $password], $bearer), 200, 'unlink request safely handles unlinked account');
    expect_status(request_api('GET', '/api/auth/me', null, $bearer), 200, 'existing session remains usable');
    expect_status(request_api('POST', '/api/auth/logout', ['refresh_token' => $tokens['refresh_token']], $bearer), 200, 'normal logout');
    echo "Passed {$checks} Google HTTP contract checks.\n";
} finally {
    foreach ($challenge_tokens as $token) query($db, 'DELETE FROM google_challenges WHERE token_hash = ?', [hash('sha256', $token)]);
    if ($id !== null) {
        query($db, 'DELETE FROM google_challenges WHERE user_id = ?', [$id]);
        query($db, 'DELETE FROM google_identities WHERE user_id = ?', [$id]);
        query($db, 'DELETE FROM audit_logs WHERE actor_user_id = ?', [$id]);
        query($db, 'DELETE FROM refresh_tokens WHERE user_id = ?', [$id]);
        query($db, 'DELETE FROM users WHERE id = ? AND username = ?', [$id, $prefix]);
    }
}
