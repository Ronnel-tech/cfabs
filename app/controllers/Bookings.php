<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Bookings extends Controller {
    public function __construct()
    {
        parent::__construct();
        $this->call->library(['account_access', 'booking_service']);
        header('Cache-Control: no-store');
    }

    public function facilities() { $this->resources('facility'); }
    public function assets() { $this->resources('asset'); }
    public function facility_availability($id) { $this->availability('facility', $id); }
    public function asset_availability($id) { $this->availability('asset', $id); }
    public function facility_index() { $this->index('facility'); }
    public function asset_index() { $this->index('asset'); }
    public function facility_show($id) { $this->show('facility', $id); }
    public function asset_show($id) { $this->show('asset', $id); }
    public function facility_create() { $this->create('facility'); }
    public function asset_create() { $this->create('asset'); }
    public function facility_series() { $this->create_series('facility'); }
    public function asset_series() { $this->create_series('asset'); }

    public function series_show($id)
    {
        $actor = $this->account_access->require_user();
        $this->respond(function () use ($actor,$id) {
            $model = $this->call->model('Series_model');
            $series = $model->find_series($this->booking_rules->positive_id($id));
            if (!$series) throw new DomainException('Series not found.', 404);
            if ((int) $series['requester_id'] !== (int) $actor['id'] && !in_array($series['resource_kind'] . '_booking.view_all', $actor['permissions'], TRUE)) throw new DomainException('Forbidden', 403);
            return $this->booking_service->series_result($series);
        });
    }

    private function create_series(string $kind)
    {
        $actor = $this->account_access->require_user($kind . '_booking.create');
        $body = $this->account_access->json_body();
        $this->respond(fn() => $this->booking_service->create_series($kind, $actor, $body), 201);
    }
    public function facility_approve($id) { $this->approve('facility', $id); }
    public function asset_approve($id) { $this->approve('asset', $id); }
    public function facility_reject($id) { $this->reject('facility', $id); }
    public function asset_reject($id) { $this->reject('asset', $id); }
    public function facility_cancel($id) { $this->cancel('facility', $id); }
    public function asset_cancel($id) { $this->cancel('asset', $id); }

    public function notifications()
    {
        $actor = $this->account_access->require_user();
        $this->respond(function () use ($actor) {
            [$limit, $offset] = $this->pagination();
            return $this->Booking_model->notifications((int) $actor['id'], $limit, $offset);
        });
    }

    public function read_notification($id)
    {
        $actor = $this->account_access->require_user();
        $this->respond(function () use ($actor, $id) {
            if (!$this->Booking_model->read_notification((int) $actor['id'], $this->booking_rules->positive_id($id))) {
                throw new DomainException('Notification not found.', 404);
            }
            return ['is_read' => TRUE];
        });
    }

    private function resources(string $kind)
    {
        $this->account_access->require_user($kind . '.view');
        $this->respond(function () use ($kind) {
            [$limit, $offset] = $this->pagination();
            return $this->Booking_model->resources($kind, $limit, $offset);
        });
    }

    private function availability(string $kind, $id)
    {
        $this->account_access->require_user($kind . '.view');
        $this->respond(function () use ($kind, $id) {
            $query = $this->api->get_query_params();
            [$start, $end] = $this->booking_rules->interval($query['start'] ?? null, $query['end'] ?? null);
            return $this->booking_service->availability($kind, $this->booking_rules->positive_id($id), $start, $end);
        });
    }

    private function index(string $kind)
    {
        $actor = $this->account_access->require_user();
        $this->respond(function () use ($kind, $actor) {
            [$limit, $offset] = $this->pagination();
            $mine = $this->api->get_query_params()['mine'] ?? '0';
            if (!in_array($mine, ['0', '1'], TRUE)) throw new DomainException('mine must be 0 or 1.', 422);
            return $this->Booking_model->bookings($kind, (int) $actor['id'],
                $mine !== '1' && in_array($kind . '_booking.view_all', $actor['permissions'], TRUE), $limit, $offset);
        });
    }

    private function show(string $kind, $id)
    {
        $actor = $this->account_access->require_user();
        $this->respond(function () use ($kind, $actor, $id) {
            $booking = $this->Booking_model->booking($kind, $this->booking_rules->positive_id($id));
            if (!$booking) throw new DomainException('Booking not found.', 404);
            if ((int) $booking['requester_id'] !== (int) $actor['id']
                && !in_array($kind . '_booking.view_all', $actor['permissions'], TRUE)) throw new DomainException('Forbidden', 403);
            if ($kind === 'asset') {
                $booking['selected_items'] = $this->Booking_model->selected_instance_details((int) $booking['id']);
                $booking['instance_ids'] = array_map('intval', array_column($booking['selected_items'], 'id'));
            }
            return $booking;
        });
    }

    private function create(string $kind)
    {
        $actor = $this->account_access->require_user($kind . '_booking.create');
        $body = $this->account_access->json_body();
        $this->respond(fn() => $this->booking_service->create($kind, $actor, $body), 201);
    }

    private function approve(string $kind, $id)
    {
        $actor = $this->account_access->require_user($kind . '_booking.approve');
        $this->respond(fn() => $this->booking_service->approve($kind, $this->booking_rules->positive_id($id), $actor));
    }

    private function reject(string $kind, $id)
    {
        $actor = $this->account_access->require_user($kind . '_booking.reject');
        $body = $this->account_access->json_body();
        $this->respond(fn() => $this->booking_service->reject($kind, $this->booking_rules->positive_id($id), $actor,
            $this->booking_rules->text($body['reason'] ?? null, 4000, 'reason')));
    }

    private function pagination(): array
    {
        $query = $this->api->get_query_params();
        $page = $this->booking_rules->positive_id($query['page'] ?? 1);
        $limit = $this->booking_rules->positive_id($query['per_page'] ?? 20);
        if ($page > 1000000 || $limit > 100) throw new DomainException('Pagination limit exceeded.', 422);
        return [$limit, ($page - 1) * $limit];
    }

    private function cancel(string $kind, $id)
    {
        $actor = $this->account_access->require_user();
        $body = $this->account_access->json_body();
        $this->respond(function () use ($kind, $id, $actor, $body) {
            if (array_keys($body) !== ['reason']) throw new DomainException('Provide a cancellation reason only.', 422);
            return $this->booking_service->cancel($kind, $this->booking_rules->positive_id($id), $actor,
                $this->booking_rules->text($body['reason'], 4000, 'reason'));
        });
    }

    private function respond(callable $action, int $status = 200)
    {
        try { $data = $action(); }
        catch (DomainException $error) { $this->api->respond_error($error->getMessage(), $error->getCode() ?: 422); }
        $this->api->respond(['success' => TRUE, 'data' => $data], $status);
    }
}
