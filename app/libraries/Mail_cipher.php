<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once SYSTEM_DIR . 'libraries/Encryption.php';

/** Reuse LavaLust encryption with authentication for stored mail/reset links. */
class Mail_cipher extends Encryption {
    public function __construct()
    {
        if (strlen((string) config_item('encryption_key')) < 32) throw new RuntimeException('A strong APP_KEY is required for email payload encryption.');
        parent::__construct('aes-256-gcm');
    }
    public function seal(array $payload): string
    {
        return $this->encrypt(json_encode($payload, JSON_THROW_ON_ERROR));
    }
    public function open(string $sealed): array
    {
        // Avoid passing malformed/truncated input to OpenSSL.
        $raw = base64_decode($sealed, TRUE);
        if ($raw === FALSE || strlen($raw) < 29) throw new RuntimeException('Invalid encrypted email payload.');
        $plain = $this->decrypt($sealed);
        if ($plain === FALSE) throw new RuntimeException('Invalid encrypted email payload.');
        $payload = json_decode($plain, TRUE, 32, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) throw new RuntimeException('Invalid encrypted email payload.');
        return $payload;
    }
}
