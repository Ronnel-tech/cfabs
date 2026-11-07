<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Handovers extends Controller {
    public function __construct()
    {
        parent::__construct();
        $this->call->library(['account_access', 'asset_operations']);
        header('Cache-Control: no-store');
    }

    public function release($id) { $this->movement($id, 'release', 'asset_booking.release'); }
    public function receive($id) { $this->movement($id, 'return', 'asset_booking.return'); }

    public function custody($id)
    {
        $actor = $this->account_access->require_user();
        $this->respond(function () use ($id, $actor) {
            $id = $this->booking_rules->positive_id($id);
            $booking = $this->Booking_model->booking('asset', $id);
            if (!$booking) throw new DomainException('Booking not found.', 404);
            if ((int) $booking['requester_id'] !== (int) $actor['id'] && !in_array('asset_booking.view_all', $actor['permissions'], TRUE)) throw new DomainException('Forbidden', 403);
            return $this->asset_operations->custody($id);
        });
    }

    public function inspect($id)
    {
        $actor = $this->account_access->require_user('asset.condition.manage');
        $body = $this->account_access->json_body();
        $this->respond(function () use ($id, $actor, $body) {
            $source = $this->Handover_model->find_handover($this->booking_rules->positive_id($id));
            if (!$source) throw new DomainException('Handover not found.', 404);
            return $this->asset_operations->handover((int) $source['asset_booking_id'], 'inspection', (int) $actor['id'], $body, (int) $source['id']);
        });
    }

    public function complete($id)
    {
        $actor = $this->account_access->require_user('asset_booking.return');
        $body = $this->account_access->json_body();
        $this->respond(function () use ($id, $actor, $body) {
            if (array_keys($body) !== ['reason']) throw new DomainException('Provide a completion reason only.', 422);
            return $this->asset_operations->complete($this->booking_rules->positive_id($id), (int) $actor['id'], $this->booking_rules->text($body['reason'], 4000, 'reason'));
        });
    }

    public function history($id)
    {
        $actor = $this->account_access->require_user();
        $this->respond(function () use ($id, $actor) {
            $booking = $this->Booking_model->booking('asset', $this->booking_rules->positive_id($id));
            if (!$booking) throw new DomainException('Booking not found.', 404);
            if ((int) $booking['requester_id'] !== (int) $actor['id'] && !in_array('asset_booking.view_all', $actor['permissions'], TRUE)) throw new DomainException('Forbidden', 403);
            $query = $this->api->get_query_params();
            $page = $this->booking_rules->positive_id($query['page'] ?? 1);
            $limit = $this->booking_rules->positive_id($query['per_page'] ?? 20);
            if ($page > 1000000 || $limit > 100) throw new DomainException('Pagination limit exceeded.', 422);
            $rows = $this->Handover_model->history((int) $booking['id'], $limit, ($page - 1) * $limit);
            foreach ($rows as &$row) $row['instance_ids'] = $this->Handover_model->items((int) $row['id']);
            return $rows;
        });
    }

    private function movement($id, string $direction, string $permission)
    {
        $actor = $this->account_access->require_user($permission);
        $body = $this->account_access->json_body();
        $this->respond(fn() => $this->asset_operations->handover($this->booking_rules->positive_id($id), $direction, (int) $actor['id'], $body));
    }

    private function respond(callable $action)
    {
        try { $result = $action(); }
        catch (DomainException $error) { $this->api->respond_error($error->getMessage(), $error->getCode() ?: 422); }
        $this->api->respond(['success' => TRUE, 'data' => $result]);
    }
}
