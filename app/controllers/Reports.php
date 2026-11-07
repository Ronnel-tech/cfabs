<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Reports extends Controller {
    public function __construct()
    {
        parent::__construct();
        $this->call->library(['account_access', 'booking_service']);
        $this->call->model('Report_model');
        header('Cache-Control: no-store');
    }

    public function facilities() { $this->operational('facility'); }
    public function assets() { $this->operational('asset'); }
    public function inventory()
    {
        $actor = $this->account_access->require_user('report.asset.view');
        $this->respond(function () use ($actor) {
            $query = $this->api->get_query_params();
            if (array_diff(array_keys($query), ['department_id','category_id','resource_id','status','tracking_mode','page','per_page','format'])) throw new DomainException('Unsupported inventory filter.', 422);
            $filters = $this->filters($query, TRUE);
            [$page, $limit, $format] = $this->report_page($query, $actor);
            $export = $format !== 'json';
            $rows = $this->Report_model->inventory($filters, $export ? ($format === 'pdf' ? 501 : 10001) : $limit + 1, $export ? 0 : ($page - 1) * $limit);
            if ($export) $this->export('inventory', ['resource_id','asset_code','asset_name','tracking_mode','category_id','owning_department_id','status','registered_quantity','usable_quantity'], $rows, $format, $actor, $filters);
            $more = count($rows) > $limit;
            return ['records' => array_slice($rows, 0, $limit), 'pagination' => ['page' => $page, 'per_page' => $limit, 'has_more' => $more]];
        });
    }

    public function dashboard()
    {
        $actor = $this->account_access->require_user();
        $this->respond(function () use ($actor) {
            $query = $this->api->get_query_params();
            if (array_diff(array_keys($query), ['start','end'])) throw new DomainException('Unsupported dashboard filter.', 422);
            [$start, $end] = $this->range($query);
            return ['start_utc' => $start, 'end_utc' => $end,
                'facility_bookings' => $this->counts($this->Report_model->bookings('facility', $start, $end, (int) $actor['id'])),
                'asset_bookings' => $this->counts($this->Report_model->bookings('asset', $start, $end, (int) $actor['id'])),
                'overdue_assets' => $this->Report_model->overdue((int) $actor['id'])];
        });
    }

    public function audit()
    {
        $this->account_access->require_user('audit.view');
        $this->respond(function () {
            $query = $this->api->get_query_params();
            if (array_diff(array_keys($query), ['start','end','page','per_page','actor_user_id','entity_type','action'])) throw new DomainException('Unsupported audit filter.', 422);
            [$start, $end] = $this->range($query);
            $page = $this->booking_rules->positive_id($query['page'] ?? 1);
            $limit = $this->booking_rules->positive_id($query['per_page'] ?? 20);
            if ($page > 1000000 || $limit > 100) throw new DomainException('Pagination limit exceeded.', 422);
            $filters = [];
            if (isset($query['actor_user_id'])) $filters['actor_user_id'] = $this->booking_rules->positive_id($query['actor_user_id']);
            foreach (['entity_type' => 80, 'action' => 100] as $field => $length) {
                if (isset($query[$field])) $filters[$field] = $this->booking_rules->text($query[$field], $length, $field);
            }
            $result = $this->Report_model->audit($start, $end, $filters, $limit, ($page - 1) * $limit);
            return ['records' => $result['records'], 'pagination' => ['page' => $page, 'per_page' => $limit, 'total' => $result['total']]];
        });
    }

    public function complete_facility($id)
    {
        $actor = $this->account_access->require_user('facility_booking.approve');
        $this->respond(fn() => $this->booking_service->complete_facility($this->booking_rules->positive_id($id), (int) $actor['id']));
    }

    private function operational(string $kind)
    {
        $actor = $this->account_access->require_user('report.' . $kind . '.view');
        $this->respond(function () use ($kind, $actor) {
            $query = $this->api->get_query_params();
            if (array_diff(array_keys($query), ['start','end','department_id','category_id','resource_id','requester_id','status','page','per_page','format'])) throw new DomainException('Unsupported report filter.', 422);
            [$start, $end] = $this->range($query);
            $filters = $this->filters($query, FALSE);
            [$page, $limit, $format] = $this->report_page($query, $actor);
            $export = $format !== 'json';
            $resources = $this->Report_model->resources($kind, $start, $end, $filters, $export ? ($format === 'pdf' ? 501 : 10001) : $limit + 1, $export ? 0 : ($page - 1) * $limit);
            if ($export) $this->export($kind, ['resource_id','resource_name','booking_count','reserved_seconds'], $resources, $format, $actor, $filters, [$start, $end]);
            $data = ['start_utc' => $start, 'end_utc' => $end,
                'bookings_by_status' => $this->counts($this->Report_model->bookings($kind, $start, $end, null, $filters)),
                'resources' => array_slice($resources, 0, $limit),
                'pagination' => ['page' => $page, 'per_page' => $limit, 'has_more' => count($resources) > $limit]];
            if ($kind === 'asset') $data['currently_overdue'] = $this->Report_model->overdue(null, $filters);
            return $data;
        });
    }

    private function range(array $query): array
    {
        if (isset($query['start']) !== isset($query['end'])) throw new DomainException('Provide both start and end.', 422);
        if (!isset($query['start'])) return [gmdate('Y-m-d H:i:s', time() - 30 * 86400), gmdate('Y-m-d H:i:s')];
        return $this->booking_rules->interval($query['start'], $query['end']);
    }

    private function filters(array $query, bool $inventory): array
    {
        $filters = [];
        foreach (['department_id','category_id','resource_id','requester_id'] as $key) {
            if (isset($query[$key])) $filters[$key] = $this->booking_rules->positive_id($query[$key]);
        }
        if (isset($query['status'])) {
            $allowed = $inventory ? ['active','inactive','retired','maintenance'] : ['PENDING','APPROVED','READY_FOR_RELEASE','RELEASED','RETURNED','COMPLETED','REJECTED','CANCELLED'];
            if (!in_array($query['status'], $allowed, TRUE)) throw new DomainException('Invalid status filter.', 422);
            $filters['status'] = $query['status'];
        }
        if (isset($query['tracking_mode'])) {
            if (!in_array($query['tracking_mode'], ['quantity','individual'], TRUE)) throw new DomainException('Invalid tracking mode.', 422);
            $filters['tracking_mode'] = $query['tracking_mode'];
        }
        return $filters;
    }
    private function report_page(array $query, array $actor): array
    {
        $page = $this->booking_rules->positive_id($query['page'] ?? 1);
        $limit = $this->booking_rules->positive_id($query['per_page'] ?? 100);
        if ($page > 1000000 || $limit > 100) throw new DomainException('Pagination limit exceeded.', 422);
        $format = $query['format'] ?? 'json';
        if (!in_array($format, ['json','csv','xlsx','pdf'], TRUE)) throw new DomainException('Use json, csv, xlsx or pdf format.', 422);
        if (in_array($format, ['xlsx','pdf'], TRUE) && ($actor['role_name'] ?? null) !== 'system_admin') throw new DomainException('Only system administrators may export Excel or PDF reports.', 403);
        if ($format !== 'json' && (isset($query['page']) || isset($query['per_page']))) throw new DomainException('Exports use filters, not pagination.', 422);
        return [$page, $limit, $format];
    }
    private function export(string $kind, array $columns, array $rows, string $format, array $actor, array $filters, ?array $period = null): void
    {
        $filename = $kind === 'inventory' ? 'asset-inventory' : $kind . '-bookings';
        if ($format === 'csv') {
            if (count($rows) > 10000) throw new DomainException('Narrow the filters to export at most 10000 resources.', 422);
            $this->call->library('csv_export');
            $this->csv_export->download($filename . '.csv', $columns, $rows);
        }
        $this->call->library('report_export');
        $document = $this->report_export->render($format, $kind, $columns, $rows, $filters, $period);
        $this->Report_model->record_export((int) $actor['id'], $kind, $format, count($rows), $filters, $period);
        header('Content-Type: ' . $document['mime']);
        header('Content-Disposition: attachment; filename="' . $filename . '.' . $format . '"');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . strlen($document['bytes']));
        echo $document['bytes'];
        exit;
    }

    private function counts(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) $counts[$row['status']] = (int) $row['booking_count'];
        return $counts;
    }

    private function respond(callable $action)
    {
        try { $result = $action(); }
        catch (DomainException $error) { $this->api->respond_error($error->getMessage(), $error->getCode() ?: 422); }
        $this->api->respond(['success' => TRUE, 'data' => $result]);
    }
}
