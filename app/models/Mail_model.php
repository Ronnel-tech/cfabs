<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Mail_model extends Model {
    private $connection;
    public function __construct()
    {
        parent::__construct(); $lava=lava_instance();
        if(!isset($lava->properties['db']))$lava->call->database();
        $this->connection=$lava->db;
    }
    public function lock(): bool { return (int)$this->connection->raw("SELECT GET_LOCK(CONCAT('cfabs_mail_',LEFT(SHA2(DATABASE(),256),40)),0)")->fetchColumn()===1; }
    public function unlock(): void { $this->connection->raw("SELECT RELEASE_LOCK(CONCAT('cfabs_mail_',LEFT(SHA2(DATABASE(),256),40)))"); }
    public function discover(int $limit, ?int $recipient): array
    {
        $extra=$recipient===null?'':' AND n.recipient_id=?';
        return $this->connection->raw("SELECT n.* FROM notifications n WHERE n.email_eligible=1
            AND NOT EXISTS(SELECT 1 FROM email_deliveries d WHERE d.notification_id=n.id){$extra}
            ORDER BY n.id LIMIT {$limit}",$recipient===null?[]:[$recipient])->fetchAll(PDO::FETCH_ASSOC);
    }
    public function notification(int $id): ?array { return $this->connection->raw('SELECT * FROM notifications WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC)?:null; }
    public function pending_requests(): int { return (int)$this->connection->raw('SELECT COUNT(*) FROM password_reset_requests WHERE processed_at IS NULL')->fetchColumn(); }
    public function queue(?int $notification, ?int $reset, int $recipient, ?string $payload, ?string $skip=null): void
    {
        $this->connection->raw('INSERT INTO email_deliveries (notification_id,password_reset_id,recipient_id,delivery_key,payload_encrypted,status,last_error_code) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id',[$notification,$reset,$recipient,bin2hex(random_bytes(32)),$payload,$skip===null?'pending':'skipped',$skip]);
    }
    public function due(int $limit,?int $recipient): array
    {
        $extra=$recipient===null?'':' AND recipient_id=?';
        return $this->connection->raw("SELECT * FROM email_deliveries WHERE status IN ('pending','retry','sending') AND next_attempt_at<=UTC_TIMESTAMP(){$extra} ORDER BY id LIMIT {$limit}",$recipient===null?[]:[$recipient])->fetchAll(PDO::FETCH_ASSOC);
    }
    public function begin_attempt(int $id): void
    {
        $this->connection->raw("UPDATE email_deliveries SET status='sending',attempts=attempts+1,first_attempt_at=COALESCE(first_attempt_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP() WHERE id=?",[$id]);
    }
    public function finish(int $id,string $status,?string $error=null,?string $provider=null,int $delay=0): void
    {
        $terminal=in_array($status,['accepted','skipped','failed'],TRUE);
        $this->connection->raw('UPDATE email_deliveries SET status=?,last_error_code=?,provider_id=?,next_attempt_at=?,accepted_at=CASE WHEN ?=\'accepted\' THEN UTC_TIMESTAMP() ELSE accepted_at END,payload_encrypted=CASE WHEN ?=1 THEN NULL ELSE payload_encrypted END,updated_at=UTC_TIMESTAMP() WHERE id=?',[$status,$error,$provider,gmdate('Y-m-d H:i:s',time()+$delay),$status,$terminal?1:0,$id]);
    }
    public function source(string $entity,int $id): ?array
    {
        $tables=['facility_booking'=>'facility_bookings','asset_booking'=>'asset_bookings','facility_maintenance'=>'facility_maintenance','asset_maintenance'=>'asset_maintenance','asset_problem'=>'asset_problem_reports','user'=>'users','password_reset'=>'password_reset_tokens'];
        if(!isset($tables[$entity]))return null;
        return $this->connection->raw('SELECT * FROM '.$tables[$entity].' WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC)?:null;
    }
}
