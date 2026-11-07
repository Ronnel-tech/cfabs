<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Calendar extends Controller {
    public function __construct()
    {
        parent::__construct();
        $this->call->library(['account_access', 'booking_rules']);
        $this->call->model('Calendar_model');
        header('Cache-Control: no-store');
    }

    public function events()
    {
        $actor = $this->account_access->require_user();
        try {
            $query = $this->api->get_query_params();
            $allowed = ['start','end','type','status','facility_id','asset_id','facility_category_id','asset_category_id','department_id'];
            if (array_diff(array_keys($query), $allowed)) throw new DomainException('Unsupported calendar filter.', 422);
            [$start, $end] = $this->booking_rules->interval($query['start'] ?? null, $query['end'] ?? null);
            if (strtotime($end . ' UTC') - strtotime($start . ' UTC') > 90 * 86400) throw new DomainException('Choose a calendar range of at most 90 days.', 422);
            $types = ['facility_booking','asset_booking','facility_maintenance','asset_maintenance'];
            if (isset($query['type'])) {
                if (!in_array($query['type'], $types, TRUE)) throw new DomainException('Invalid calendar category.', 422);
                $types = [$query['type']];
            }
            $filters = [];
            foreach (['facility_id','asset_id','facility_category_id','asset_category_id','department_id'] as $key) {
                if (isset($query[$key])) $filters[$key] = $this->booking_rules->positive_id($query[$key]);
            }
            if (isset($query['status'])) {
                if (!in_array($query['status'], ['PENDING','APPROVED','READY_FOR_RELEASE','RELEASED','RETURNED','COMPLETED','REJECTED','CANCELLED'], TRUE)) throw new DomainException('Invalid booking status.', 422);
                if (isset($query['type']) && str_ends_with($query['type'], '_maintenance')) throw new DomainException('Booking status cannot filter maintenance.', 422);
                $filters['status'] = $query['status'];
            }
            if (isset($filters['department_id'])) {
                if (count($types) !== 1 || !str_ends_with($types[0], '_booking')) throw new DomainException('Choose one booking category before filtering by requester department.', 422);
                $kind = explode('_', $types[0])[0];
                if (!in_array($kind . '_booking.view_all', $actor['permissions'], TRUE)) throw new DomainException('Department filtering requires permission to view all bookings of this kind.', 403);
            }
            $events = [];
            $permitted = FALSE;
            foreach ($types as $type) {
                [$kind, $category] = explode('_', $type);
                if (!in_array($kind . '.view', $actor['permissions'], TRUE)) {
                    if (isset($query['type'])) throw new DomainException('Forbidden', 403);
                    continue;
                }
                $permitted = TRUE;
                foreach ($this->Calendar_model->events($kind, $category === 'maintenance', $actor, $start, $end, $filters) as $event) {
                    $events[] = $event;
                    if (count($events) > 1000) throw new DomainException('More than 1,000 calendar events. Narrow the dates or filters.', 422);
                }
            }
            if (!$permitted) throw new DomainException('Calendar access requires facility or asset viewing permission.', 403);
            usort($events, fn($left, $right) => strcmp($left['start'], $right['start']) ?: strcmp($left['id'], $right['id']));
            $data = ['events' => $events, 'timezone' => 'Asia/Manila', 'start' => str_replace(' ', 'T', $start) . 'Z', 'end' => str_replace(' ', 'T', $end) . 'Z'];
        } catch (DomainException $error) {
            $this->api->respond_error($error->getMessage(), $error->getCode() ?: 422);
        }
        $this->api->respond(['success' => TRUE, 'data' => $data]);
    }
}
