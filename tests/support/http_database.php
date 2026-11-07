<?php
/** Opt-in integration checks: temporary accounts in the configured database. */
if (PHP_SAPI !== 'cli' || getenv('CFABS_RUN_DB_TESTS') !== '1') {
    fwrite(STDERR, "Set CFABS_RUN_DB_TESTS=1 and start the PHP server before running this test.\n");
    exit(1);
}
$root = dirname(__DIR__, 2);
$environment = [];
foreach (file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$key, $value] = explode('=', $line, 2);
    $value = trim($value);
    if (strlen($value) >= 2 && in_array($value[0], ['"', "'"], TRUE) && $value[-1] === $value[0]) {
        $value = substr($value, 1, -1);
    }
    $environment[trim($key)] = $value;
}
$db = new PDO('mysql:host=' . $environment['DB_HOST'] . ';port=' . ($environment['DB_PORT'] ?: '3306')
    . ';dbname=' . $environment['DB_NAME'] . ';charset=utf8mb4', $environment['DB_USER'], $environment['DB_PASSWORD'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec("SET SESSION time_zone = '+00:00'");
$base = rtrim(getenv('CFABS_TEST_URL') ?: 'http://127.0.0.1:3012', '/');
$prefix = 'cfabstest' . bin2hex(random_bytes(6));
$password = ' ' . bin2hex(random_bytes(16)) . ' ';
$checks = 0;
$department = null;
ini_set('zend.exception_ignore_args', '1');
ini_set('xdebug.show_exception_trace', '0');
ini_set('xdebug.collect_params', '0');

// Do not print stack arguments: HTTP helpers receive temporary bearer tokens.
set_exception_handler(function (Throwable $error): void {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
});

function query(PDO $db, string $sql, array $values = []): PDOStatement {
    $statement = $db->prepare($sql);
    $statement->execute($values);
    return $statement;
}
function request_api(string $method, string $path, $data = null, ?string $bearer = null, ?string $raw = null): array {
    global $base;
    $headers = "Accept: application/json\r\n";
    if ($bearer !== null) $headers .= 'Authorization: Bearer ' . $bearer . "\r\n";
    $options = ['method' => $method, 'ignore_errors' => TRUE, 'timeout' => 30];
    if ($data !== null || $raw !== null) {
        $headers .= "Content-Type: application/json\r\n";
        $options['content'] = $raw ?? json_encode($data, JSON_THROW_ON_ERROR);
    }
    $options['header'] = $headers;
    $response = file_get_contents($base . $path, FALSE, stream_context_create(['http' => $options]));
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $match);
    // Classify server failures without printing HTML, query bindings, or credentials.
    if ((int) ($match[1] ?? 0) >= 500 && preg_match('/SQLSTATE\[[A-Z0-9]+\](?:\s*\[\d+\])?|Maximum execution time|MySQL server has gone away|Lost connection to MySQL server|Lock wait timeout exceeded|Deadlock found|already an active transaction|no active transaction/i', $response ?: '', $failure)) {
        fwrite(STDERR, 'HTTP server failure category: ' . $failure[0] . PHP_EOL);
    }
    return [(int) ($match[1] ?? 0), json_decode($response ?: '', TRUE)];
}
function check(bool $condition, string $name): void {
    global $checks;
    if (!$condition) throw new RuntimeException('Failed: ' . $name);
    $checks++;
}
function expect_status(array $response, int $expected, string $name): array {
    check($response[0] === $expected, $name . ' (HTTP ' . $response[0] . ')');
    check(is_array($response[1]), $name . ' returns JSON');
    return $response[1];
}
function signed_claims(array $claims, string $secret): string {
    $encode = fn($value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    $header = $encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload = $encode(json_encode($claims));
    return $header . '.' . $payload . '.' . $encode(hash_hmac('sha256', $header . '.' . $payload, $secret, TRUE));
}


