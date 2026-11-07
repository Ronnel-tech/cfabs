<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Qr_model extends Model {
    private $connection;
    private const COLUMNS = ['asset' => 'asset_id', 'asset-instance' => 'asset_instance_id'];

    public function __construct()
    {
        parent::__construct();
        $lava = lava_instance();
        if (!isset($lava->properties['db'])) $lava->call->database();
        $this->connection = $lava->db;
    }

    public function current(string $kind, int $id, bool $lock = FALSE): ?array
    {
        $column = self::COLUMNS[$kind] ?? null;
        if (!$column) throw new InvalidArgumentException('Unknown QR resource.');
        return $this->connection->raw("SELECT * FROM qr_labels WHERE {$column} = ?" . ($lock ? ' FOR UPDATE' : ''), [$id])->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Called inside the catalog transaction; unique keys enforce one label per record. */
    public function create(string $kind, int $id): void
    {
        $column = self::COLUMNS[$kind] ?? null;
        if (!$column) throw new InvalidArgumentException('Unknown QR resource.');
        $this->connection->raw("INSERT INTO qr_labels (identifier, {$column}) VALUES (?, ?)", [bin2hex(random_bytes(24)), $id]);
    }

    public function find(string $identifier, bool $lock = FALSE): ?array
    {
        return $this->connection->raw('SELECT * FROM qr_labels WHERE identifier = ? AND revoked_at IS NULL' . ($lock ? ' FOR UPDATE' : ''), [$identifier])->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function change(int $id, string $action): void
    {
        if ($action === 'revoke') {
            $this->connection->raw('UPDATE qr_labels SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP()), updated_at = UTC_TIMESTAMP() WHERE id = ?', [$id]);
        } elseif ($action === 'regenerate') {
            $this->connection->raw('UPDATE qr_labels SET identifier = ?, revoked_at = NULL, updated_at = UTC_TIMESTAMP() WHERE id = ?', [bin2hex(random_bytes(24)), $id]);
        } else throw new InvalidArgumentException('Unknown QR action.');
    }

    public function audit(int $actor, array $before, array $after, string $action): void
    {
        $summary = fn($row) => json_encode(['fingerprint' => hash('sha256', $row['identifier']), 'revoked' => $row['revoked_at'] !== null], JSON_THROW_ON_ERROR);
        $this->connection->raw('INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, old_values_json, new_values_json, created_at)
            VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())', [$actor, 'qr.' . $action, 'qr_label', $before['id'], $summary($before), $summary($after)]);
    }
}
