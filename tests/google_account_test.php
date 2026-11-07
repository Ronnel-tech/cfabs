<?php
/** Real database/model/controller checks with a process-local trusted key provider. */
require __DIR__ . '/support/http_database.php';
define('PREVENT_DIRECT_ACCESS', TRUE);
require $root . '/vendor/autoload.php';
require $root . '/app/libraries/Google_identity.php';
class Model { public function __construct() {} }
class Controller {
    public function __construct() {}
    public function __get($name) { return lava_instance()->$name; }
}
require $root . '/app/models/Account_model.php';
require $root . '/app/models/Google_identity_model.php';
require $root . '/app/controllers/Google_auth.php';
class Google_test_response extends RuntimeException {
    public function __construct(public array $body, int $status) { parent::__construct('Test response', $status); }
}
$client = 'isolated-test.apps.googleusercontent.com';
$old_client = getenv('GOOGLE_CLIENT_ID');
putenv('GOOGLE_CLIENT_ID=' . $client);
$private = openssl_pkey_new(['private_key_bits' => 2048]);
if (!$private) throw new RuntimeException('Set OPENSSL_CONF to your PHP OpenSSL config before running this test.');
$rsa = openssl_pkey_get_details($private)['rsa'];
$encode = fn($value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
$jwks = ['keys' => [['kty' => 'RSA', 'alg' => 'RS256', 'kid' => 'test-key', 'n' => $encode($rsa['n']), 'e' => $encode($rsa['e'])]]];
$adapter = new class($db) {
    public function __construct(private PDO $pdo) {}
    public function raw($sql, $values = []) { return query($this->pdo, $sql, $values); }
    public function transaction() { $this->pdo->beginTransaction(); }
    public function commit() { $this->pdo->commit(); }
    public function roll_back() { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); }
};
$fake = (object) ['db' => $adapter, 'properties' => ['db' => $adapter],
    'call' => new class { public function library($name) {} public function model($name) {} },
    'cache' => new class($jwks) {
        public function __construct(private array $jwks) {}
        public function get($name) { return ['jwks' => $this->jwks, 'fetched_at' => time(), 'expires_at' => time() + 300]; }
    },
    'api' => new class {
        public function rate_limit(...$args) {}
        public function respond($body) { throw new Google_test_response($body, 200); }
        public function respond_error($message, $code) { throw new Google_test_response(['error' => $message], $code); }
        public function issue_tokens($user) { return ['test_user_id' => (int) $user['id']]; }
    },
];
function lava_instance() { global $fake; return $fake; }
$fake->Account_model = new Account_model();
$fake->Google_identity_model = new Google_identity_model();
$fake->google_identity = new Google_identity();
$actor = null;
$body = [];
$fake->account_access = new class {
    public function json_body() { global $body; return $body; }
    public function issue_tokens($user) { global $fake; return $fake->api->issue_tokens($user); }
    public function require_user() {
        global $actor, $fake;
        $user = $actor ? $fake->Account_model->by_id($actor) : null;
        if (!$user || (int) $user['is_active'] !== 1) $fake->api->respond_error('Unauthorized', 401);
        return $user;
    }
};
$controller = new Google_auth();
$call = function ($action, $value, $status, $name) use ($controller) {
    global $body;
    $body = $value;
    try { $controller->$action(); }
    catch (Google_test_response $response) { return expect_status([$response->getCode(), $response->body], $status, $name); }
    throw new RuntimeException('Missing response');
};
$subject = $prefix . '-google';
$sign = function ($nonce, $overrides = []) use ($private, $client, $subject) {
    return Firebase\JWT\JWT::encode(array_replace(['iss' => 'https://accounts.google.com', 'aud' => $client,
        'sub' => $subject, 'email' => 'personal@gmail.com', 'email_verified' => TRUE, 'iat' => time(), 'exp' => time() + 300, 'nonce' => $nonce], $overrides), $private, 'RS256', 'test-key');
};
$ids = [];
$challenge_tokens = [];
try {
    foreach (['first', 'second'] as $name) {
        query($db, 'INSERT INTO users (username,email,password,role,is_active) VALUES (?,?,?, ?,1)', [$prefix . '.' . $name, $prefix . '.' . $name . '@example.invalid', password_hash($password, PASSWORD_DEFAULT), 'user']);
        $ids[] = (int) $db->lastInsertId();
    }
    $new_challenge = function ($intent) use ($call, &$challenge_tokens) {
        $result = $call('challenge', ['intent' => $intent], 200, $intent . ' challenge')['data'];
        $challenge_tokens[] = $result['challenge_token'];
        return $result;
    };
    $call('status', [], 401, 'anonymous status blocked');
    $call('challenge', ['intent' => 'link'], 401, 'anonymous linking blocked');
    $call('challenge', ['intent' => 'bad'], 422, 'invalid intent blocked');
    $login = $new_challenge('login');
    $call('login', ['credential' => $sign($login['nonce']), 'challenge_token' => $login['challenge_token']], 401, 'matching Google email cannot create/link a campus account');
    $actor = $ids[0];
    $link = $new_challenge('link');
    $payload = ['credential' => $sign($link['nonce']), 'challenge_token' => $link['challenge_token'], 'password' => 'wrong'];
    $call('link', $payload, 422, 'fresh campus password required');
    $payload['password'] = $password;
    $bad = $payload;
    $bad['credential'] = $sign(str_repeat('f', 64));
    $call('link', $bad, 422, 'wrong nonce blocked without expiring campus session');
    $actor = $ids[1];
    $call('link', $payload, 422, 'challenge bound to campus account');
    $actor = $ids[0];
    $call('link', $payload, 200, 'link with password and verified Google credential');
    check($fake->Google_identity_model->owner($subject) === $ids[0], 'Google subject mapped to intended campus user');
    $linked = $call('status', [], 200, 'linked status')['data'];
    check(!isset($linked['google_sub']) && $linked['google_email'] === 'personal@gmail.com', 'status excludes Google subject');
    $call('link', $payload, 409, 'duplicate linking blocked');
    $actor = null;
    $signed = ['credential' => $sign($login['nonce'], ['email' => 'changed@campus.edu']), 'challenge_token' => $login['challenge_token']];
    $session = $call('login', $signed, 200, 'Google login uses stable subject despite email change')['data'];
    check($session['test_user_id'] === $ids[0], 'campus user passed to existing session issuer');
    $call('login', $signed, 401, 'consumed challenge cannot be replayed');
    $challenge = $new_challenge('login');
    query($db, 'UPDATE google_challenges SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE token_hash = ?', [hash('sha256', $challenge['challenge_token'])]);
    $call('login', ['credential' => $sign($challenge['nonce']), 'challenge_token' => $challenge['challenge_token']], 401, 'expired challenge blocked');
    $challenge = $new_challenge('login');
    query($db, 'UPDATE users SET is_active = 0 WHERE id = ?', [$ids[0]]);
    $call('login', ['credential' => $sign($challenge['nonce']), 'challenge_token' => $challenge['challenge_token']], 401, 'inactive campus account blocked');
    query($db, 'UPDATE users SET is_active = 1 WHERE id = ?', [$ids[0]]);
    $actor = $ids[1];
    $challenge = $new_challenge('link');
    $call('link', ['credential' => $sign($challenge['nonce']), 'challenge_token' => $challenge['challenge_token'], 'password' => $password], 409, 'Google subject cannot link to second campus user');
    $actor = $ids[0];
    $call('unlink', ['password' => 'wrong'], 422, 'unlink requires campus password');
    $call('unlink', ['password' => $password], 200, 'unlink succeeds');
    check($fake->Google_identity_model->owner($subject) === null, 'subject mapping removed');
    check($fake->Google_identity_model->for_user($ids[0]) === null, 'campus link removed');
    $actor = null;
    $challenge = $new_challenge('login');
    $call('login', ['credential' => $sign($challenge['nonce']), 'challenge_token' => $challenge['challenge_token']], 401, 'unlinked Google credential cannot log in');
    check(query($db, 'SELECT COUNT(*) FROM users WHERE id IN (?,?) AND role_id IS NULL', $ids)->fetchColumn() == 2, 'Google authentication never grants roles');
    echo "Passed {$checks} Google account controller/database checks.\n";
} finally {
    if ($db->inTransaction()) $db->rollBack();
    foreach ($challenge_tokens as $token) query($db, 'DELETE FROM google_challenges WHERE token_hash = ?', [hash('sha256', $token)]);
    foreach ($ids as $id) {
        query($db, 'DELETE FROM google_challenges WHERE user_id = ?', [$id]);
        query($db, 'DELETE FROM google_identities WHERE user_id = ?', [$id]);
        query($db, 'DELETE FROM audit_logs WHERE actor_user_id = ?', [$id]);
        query($db, 'DELETE FROM users WHERE id = ?', [$id]);
    }
    putenv($old_client === FALSE ? 'GOOGLE_CLIENT_ID' : 'GOOGLE_CLIENT_ID=' . $old_client);
}
