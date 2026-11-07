<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Series_model extends Model {
    private $connection;
    public function __construct()
    {
        parent::__construct(); $lava = lava_instance();
        if (!isset($lava->properties['db'])) $lava->call->database();
        $this->connection = $lava->db;
    }
    public function find_series(int $id): ?array
    {
        return $this->connection->raw('SELECT * FROM booking_series WHERE id = ?', [$id])->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    public function by_key(int $owner, string $key): ?array
    {
        return $this->connection->raw('SELECT * FROM booking_series WHERE requester_id = ? AND idempotency_key = ?', [$owner,$key])->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    public function insert_series(string $kind, int $owner, array $rule, string $key, string $hash): int
    {
        $this->connection->raw('INSERT INTO booking_series (requester_id, resource_kind, frequency, interval_step, occurrence_count, timezone, idempotency_key, request_hash, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())', [$owner,$kind,$rule['frequency'],$rule['interval'],$rule['count'],'Asia/Manila',$key,$hash]);
        return (int) $this->connection->last_id();
    }
    public function members(array $series): array
    {
        $schema = lava_instance()->Booking_model->schema($series['resource_kind']);
        $rows = $this->connection->raw("SELECT * FROM {$schema['table']} WHERE series_id = ? ORDER BY {$schema['start']}, id", [$series['id']])->fetchAll(PDO::FETCH_ASSOC);
        if ($series['resource_kind'] === 'asset') {
            foreach ($rows as &$row) $row['instance_ids'] = lava_instance()->Booking_model->selected_instances((int) $row['id']);
        }
        return $rows;
    }
    public function lock_key(int $owner, string $key, bool $release = FALSE): bool
    {
        $sql = $release ? "SELECT RELEASE_LOCK(CONCAT('cfabs_series_', LEFT(SHA2(CONCAT(DATABASE(), ':', ?, ':', ?), 256), 40)))"
            : "SELECT GET_LOCK(CONCAT('cfabs_series_', LEFT(SHA2(CONCAT(DATABASE(), ':', ?, ':', ?), 256), 40)), 10)";
        return (int) $this->connection->raw($sql, [$owner,$key])->fetchColumn() === 1;
    }
}
