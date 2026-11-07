<?php
define('PREVENT_DIRECT_ACCESS', TRUE);
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/app/libraries/Google_identity.php';
ini_set('zend.exception_ignore_args', '1');

// Only this CLI process supplies trusted test keys. No HTTP/configuration bypass.
$client = 'test-google.apps.googleusercontent.com';
$before = getenv('GOOGLE_CLIENT_ID');
putenv('GOOGLE_CLIENT_ID=' . $client);
$private = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if (!$private) throw new RuntimeException('Set OPENSSL_CONF to your PHP OpenSSL config before running this test.');
$rsa = openssl_pkey_get_details($private)['rsa'];
$encode = fn($value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
$jwks = ['keys' => [['kty' => 'RSA', 'alg' => 'RS256', 'kid' => 'test-key', 'n' => $encode($rsa['n']), 'e' => $encode($rsa['e'])]]];
$cache = new class($jwks) {
    public function __construct(private array $jwks) {}
    public function get($name) { return ['jwks' => $this->jwks, 'fetched_at' => time(), 'expires_at' => time() + 300]; }
};
$fake = (object) ['call' => new class { public function library($name) {} }, 'cache' => $cache];
function lava_instance() { global $fake; return $fake; }
$claims = ['iss' => 'https://accounts.google.com', 'aud' => $client, 'sub' => '123456789',
    'email' => 'person@gmail.com', 'email_verified' => TRUE, 'iat' => time(), 'exp' => time() + 300, 'nonce' => str_repeat('a', 64)];
$sign = fn($value, $key = null, $alg = 'RS256', $kid = 'test-key') => Firebase\JWT\JWT::encode($value, $key ?? $private, $alg, $kid);
$checks = 0;
$verifier = new Google_identity();
try {
    if ($verifier->verify($sign($claims))['sub'] !== $claims['sub']) throw new RuntimeException('Valid RSA credential rejected');
    $checks++;
    foreach ([
        ['iss' => 'evil.invalid'], ['aud' => 'other-client'], ['aud' => [$client]], ['azp' => 'other-client'],
        ['email_verified' => FALSE], ['email_verified' => 'true'], ['email' => 'broken'], ['email' => []],
        ['sub' => ''], ['sub' => []], ['exp' => time() - 1], ['exp' => null], ['iat' => time() + 60],
        ['iat' => null], ['nonce' => ''], ['nonce' => []],
    ] as $override) {
        try { $verifier->verify($sign(array_replace($claims, $override))); }
        catch (Throwable $error) { $checks++; continue; }
        throw new RuntimeException('Invalid claim accepted: ' . array_key_first($override));
    }
    $other = openssl_pkey_new(['private_key_bits' => 2048]);
    foreach ([$sign($claims, $other), $sign($claims, str_repeat('x', 32), 'HS256'), $sign($claims, null, 'RS256', 'unknown'), 'broken', 'a.b.c', str_repeat('a', 16385)] as $bad) {
        try { $verifier->verify($bad); }
        catch (Throwable $error) { $checks++; continue; }
        throw new RuntimeException('Invalid signature/header accepted');
    }
    foreach (['person@campus.edu', 'person@gmail.com'] as $email) {
        $verifier->verify($sign(array_replace($claims, ['email' => $email])));
        $checks++;
    }
    putenv('GOOGLE_CLIENT_ID=');
    try { $verifier->verify($sign($claims)); throw new LogicException('Unconfigured verifier accepted credential'); }
    catch (RuntimeException $error) { if ($error->getCode() !== 503) throw $error; $checks++; }
    echo "Passed {$checks} Google credential verification checks.\n";
} finally {
    putenv($before === FALSE ? 'GOOGLE_CLIENT_ID' : 'GOOGLE_CLIENT_ID=' . $before);
}
