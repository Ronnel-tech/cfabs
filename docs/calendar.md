# Booking calendar

The separate React frontend provides `/calendar` using FullCalendar 7's month,
week, day and agenda views. A custom inclusive date range opens an agenda;
requests use an exclusive end boundary. FullCalendar's named-timezone support
and `temporal-polyfill` keep display in `Asia/Manila`, independent of the device.
The page is loaded on demand. Small screens initially use the agenda view.
Dependencies follow the [official React setup](https://fullcalendar.io/docs/react).

## Read-only event feed

`GET /api/calendar/events` requires an existing campus bearer token and at least
`facility.view` or `asset.view`. It reads the existing booking and maintenance
tables through LavaLust MVC; no calendar tables or schema changes are introduced.

Required `start` and `end` are ISO timestamps with seconds and an explicit offset
or `Z`, with `start < end` and at most 90 days. Events overlap using
`existing_start < requested_end AND existing_end > requested_start`.

Optional filters:

| Parameter | Meaning |
| --- | --- |
| `type` | `facility_booking`, `asset_booking`, `facility_maintenance`, `asset_maintenance` |
| `facility_id`, `asset_id` | Exact resource of each kind |
| `facility_category_id`, `asset_category_id` | Resource category of each kind |
| `status` | Existing uppercase booking status; hides maintenance |
| `department_id` | Requester department; requires one booking `type` and its `*_booking.view_all` grant |

The response contains `events`, `timezone`, and UTC `start`/`end`. More than 1,000
visible events returns 422 with instructions to narrow the filters. Nothing is
silently truncated. Unknown filters and invalid ranges are rejected.

## Visibility and availability

- Each resource kind requires its current viewing permission. Explicit requests
  for a forbidden kind return 403; the default feed omits that kind.
- Owners see their own booking details/statuses. `*_booking.view_all` permits
  other request details for that kind. System administrators do not inherit this
  business permission merely from their role name.
- Other users see only committed booking schedule information, opaque event IDs,
  resource names and statuses. No booking ID, reference, requester/department,
  purpose, activity, or quantity is shared in those projections. Other users'
  pending, rejected, cancelled and completed requests are hidden.
- Active maintenance is shared as schedule information. Reasons/record IDs require
  the corresponding `*.maintenance.manage` grant. Completed/cancelled work is
  omitted. Overdue/open-ended maintenance continues through the visible range
  until explicitly closed, matching existing availability rules.

The calendar is advisory and never labels an empty cell as available. Asset
events show the scheduled booking period; overdue custody, returned items awaiting
inspection, partial releases/returns and remaining stock remain authoritative in
the existing availability service. Pending/rejected/cancelled bookings are not
reservations. Calendar selection navigates to the existing resource page with
prefilled dates, where availability and all request rules are checked again.
Drag/reschedule writes are disabled. Date-only selections default to 9–10 AM
on their first date; users can edit both timestamps before checking.

The existing visible-page 30-second polling, focus refresh and bearer-token
handling are reused. Booking, custody, catalog and maintenance writes invalidate
calendar queries. Failed feeds clear displayed events rather than imply availability.

## Verification

```powershell
$env:CFABS_RUN_DB_TESTS='1'
$env:CFABS_TEST_URL='http://127.0.0.1:3000'
php tests/calendar_http_test.php
```

The opt-in test creates exact random fixtures and removes them in `finally`.
`CFABS_KEEP_CALENDAR_FIXTURE=1` deliberately retains successful fixtures with an
ignored runtime manifest for browser QA; remove only those owned fixtures after
the review. Do not use this option for routine test runs.

Run `npm test`, `npm run build` and `npm run lint` in `../frontend/`. Calendar tests
cover date ranges, UTC/campus midnight boundaries, selected-period links and the
existing booking policies, including checks under a non-campus device timezone.
