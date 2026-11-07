<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
class Password_reset_model extends Model {
    private $connection;
    public function __construct()
    {
        parent::__construct(); $lava=lava_instance();
        if(!isset($lava->properties['db']))$lava->call->database();
        $this->connection=$lava->db;
    }
    public function request(string $sealed): void { $this->connection->raw('INSERT INTO password_reset_requests (email_encrypted) VALUES (?)',[$sealed]); }
    public function requests(int $limit): array { return $this->connection->raw("SELECT * FROM password_reset_requests WHERE processed_at IS NULL ORDER BY id LIMIT {$limit}")->fetchAll(PDO::FETCH_ASSOC); }
    public function processed(int $id): void { $this->connection->raw('UPDATE password_reset_requests SET processed_at=UTC_TIMESTAMP(),email_encrypted=NULL WHERE id=?',[$id]); }
    public function revoke(int $user): void { $this->connection->raw('UPDATE password_reset_tokens SET used_at=COALESCE(used_at,UTC_TIMESTAMP()) WHERE user_id=?',[$user]); }
    public function create(int $user,string $email,string $hash): int
    {
        $this->connection->raw('INSERT INTO password_reset_tokens (user_id,email,token_hash,expires_at) VALUES (?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 MINUTE))',[$user,$email,$hash]);
        return (int)$this->connection->raw('SELECT LAST_INSERT_ID()')->fetchColumn();
    }
    public function token(string $hash,bool $lock=FALSE): ?array
    {
        return $this->connection->raw('SELECT * FROM password_reset_tokens WHERE token_hash=? AND used_at IS NULL AND expires_at>UTC_TIMESTAMP()'.($lock?' FOR UPDATE':''),[$hash])->fetch(PDO::FETCH_ASSOC)?:null;
    }
    public function change_password(int $user,string $hash): void
    {
        $this->connection->raw('UPDATE users SET password=?,auth_version=auth_version+1,updated_at=UTC_TIMESTAMP() WHERE id=?',[$hash,$user]);
        $this->connection->raw('DELETE FROM refresh_tokens WHERE user_id=?',[$user]);
        $this->revoke($user);
        $this->connection->raw("UPDATE email_deliveries d JOIN password_reset_tokens t ON t.id=d.password_reset_id SET d.status='skipped',d.last_error_code='reset_consumed',d.payload_encrypted=NULL,d.updated_at=UTC_TIMESTAMP() WHERE t.user_id=? AND d.status IN ('pending','retry','sending')",[$user]);
    }
}
