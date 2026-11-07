<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Google_identity_model extends Model {
    private $connection;
    public function __construct()
    {
        parent::__construct();
        $lava = lava_instance();
        if (!isset($lava->properties['db'])) $lava->call->database();
        $this->connection = $lava->db;
    }
    public function for_user(int $id): ?array
    {
        return $this->connection->raw('SELECT google_email, linked_at FROM google_identities WHERE user_id = ?', [$id])->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    public function owner(string $subject): ?int
    {
        $id = $this->connection->raw('SELECT user_id FROM google_identities WHERE google_sub = ?', [$subject])->fetchColumn();
        return $id === FALSE ? null : (int) $id;
    }
    public function challenge(string $intent, ?int $id): array
    {
        $token = bin2hex(random_bytes(32));
        $nonce = bin2hex(random_bytes(32));
        $this->connection->raw('DELETE FROM google_challenges WHERE expires_at <= UTC_TIMESTAMP() LIMIT 1000');
        $this->connection->raw('INSERT INTO google_challenges (token_hash, nonce_hash, intent, user_id, expires_at)
            VALUES (?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE))', [hash('sha256', $token), hash('sha256', $nonce), $intent, $id]);
        return ['challenge_token' => $token, 'nonce' => $nonce, 'expires_in' => 300];
    }
    /** Call inside the same transaction as linking/session issuance. */
    public function consume(string $token, string $nonce, string $intent, ?int $id): bool
    {
        $row = $this->connection->raw('SELECT * FROM google_challenges WHERE token_hash = ? AND expires_at > UTC_TIMESTAMP() FOR UPDATE', [hash('sha256', $token)])->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['intent'] !== $intent || ($row['user_id'] === null ? null : (int) $row['user_id']) !== $id
            || !hash_equals($row['nonce_hash'], hash('sha256', $nonce))) return FALSE;
        $this->connection->raw('DELETE FROM google_challenges WHERE token_hash = ?', [$row['token_hash']]);
        return TRUE;
    }
    public function link(int $id, array $claims): void
    {
        $this->connection->raw('INSERT INTO google_identities (user_id, google_sub, google_email) VALUES (?, ?, ?)', [$id, $claims['sub'], $claims['email']]);
    }
    public function unlink(int $id): void
    {
        $this->connection->raw('DELETE FROM google_identities WHERE user_id = ?', [$id]);
        $this->connection->raw('DELETE FROM google_challenges WHERE user_id = ?', [$id]);
    }
}
