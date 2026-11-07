# Development

Run commands from `Lavalust/` using PHP 8.2 or newer. Keep database credentials
in the existing ignored `.env`; do not commit credentials or replace local settings.

Run `composer install` before starting the backend. Google Sign-In setup and
its migration, verification checks and remaining Google Cloud configuration
are documented in [google-sign-in.md](google-sign-in.md).

Email outbox, Resend setup and password recovery are documented in
[email-notifications.md](email-notifications.md). Sending is disabled pending
private sender configuration; `php lava email:dispatch` is a safe preview.

## Database foundation

Use the installed LavaLust migration command:

```powershell
php lava migration status
php lava migration run
```

Migration `003_create_resource_catalog.php` adds departments, facility categories,
asset categories, facilities, assets, and individual asset instances. Existing
authentication migrations remain unchanged. User references deliberately match
the existing `INT UNSIGNED` user IDs; new catalog IDs use `BIGINT UNSIGNED`.
Tracking mode belongs to each asset record, allowing both modes in one category.
Do not store the same physical inventory in a pool and in individual instances.

Run migrations only against the intended database. Migration controller actions
are CLI-only; public HTTP requests must not create, alter, or drop tables.
Migration loading is explicit in the controller, avoiding database connections
and migration initialization on unrelated page requests.

MySQL schema changes are not transactional. This migration refuses pre-existing
catalog tables rather than silently accepting incompatible schemas. If it fails
partway, inspect the error and partially created tables before retrying. Its
`down()` drops the catalog tables in reverse dependency order and destroys their
data. Do not invoke rollback, refresh, or reset without explicit approval.

## Verification

```powershell
php -l app/migrations/003_create_resource_catalog.php
php tests/availability_test.php
php lava serve --port=3000
```

The dependency-free availability checks cover interval boundaries, simultaneous
allocations, peak demand, individual-item capacity, and invalid input. The
calculator is a reusable application library, not an API or inventory authority.
Its caller must select approved reservations and relevant unavailability and
recheck current inventory under database locks during approval.

## Authentication and permissions

Migration `004_create_access_control.php` adds roles, permissions, role grants,
audit logs, and nullable profile/role/department fields on existing users. It
preserves the native `password`, `role`, `is_active`, and refresh-token columns.
Existing users receive no automatic campus role or privileges. New API accounts
require an active department. CLI administrators may initially have no department.

The API uses LavaLust's JWT signing, signature validation, refresh-token HMAC
storage, rate limiter, and JSON responses. Set distinct random `JWT_SECRET` and
`REFRESH_TOKEN_KEY` values in `.env`; `php lava jwt:generate` only fills missing
keys unless explicitly forced. Issuer/audience are `cfabs` / `cfabs-clients`.

Endpoints accept JSON and return token data under `data`:

- `POST /api/auth/login` — email and password; limited to 10 requests/minute/IP.
- `POST /api/auth/refresh` — rotate a `refresh_token`; replay is rejected.
- `POST /api/auth/logout` — authenticated revocation of the supplied own refresh token.
- `GET /api/auth/me` — safe profile and current database permissions.
- `GET /api/users` — paginated directory, requires `user.manage`.
- `GET /api/users/{id}` — own profile or `user.manage`.
- `POST /api/users` — create an account, requires `user.manage`; assigning a
  non-default role also requires `role.manage`.
- `PATCH /api/users/{id}/status` — boolean `is_active`, requires `user.manage`;
  self-status changes are blocked.
- `GET /api/roles` — authenticated role list.
- `GET /api/permissions` — requires `permission.manage`.

Deactivation immediately blocks protected requests and refresh, and removes the
account's refresh tokens. Logout revokes the supplied refresh token; its already
issued access token remains valid until expiry (15 minutes). Clients must discard
both tokens on logout. No tokens or passwords are included in audit records.
Permissions are read from the database on protected requests; JWT role/scopes
and the legacy role column do not authorize campus actions. System administrators
do not receive facility/asset approval permissions by implication.

### Initial administrator

Create an administrator explicitly; no default password is provided. In PowerShell,
prompt for a password securely, then pipe it to the CLI (never pass it as an argument):

```powershell
$credential = Get-Credential -UserName campusadmin
$credential.GetNetworkCredential().Password | php lava admin:create campusadmin --email=admin@example.edu
Remove-Variable credential
```

Passwords must be 12–72 bytes; usernames use 3–100 letters, digits, dots,
underscores or hyphens. Bootstrap refuses duplicate identities and is inaccessible
over HTTP. Store the actual administrator credentials in your password manager.

### Integration checks

With the server running on port 3012:

```powershell
$env:CFABS_RUN_DB_TESTS = '1'
$env:CFABS_TEST_URL = 'http://127.0.0.1:3012'
php tests/auth_http_test.php
Remove-Item Env:CFABS_RUN_DB_TESTS, Env:CFABS_TEST_URL
```

These opt-in tests create randomly named temporary accounts and a department in
the configured database. They clean up only their own rows, including fixture
audit entries, in `finally`. Run against a development database. They exercise
real HTTP and database behavior, including refresh replay, role escalation,
password whitespace, deactivation, and CLI bootstrap.

## Implementation status

Implemented: catalog and booking migrations, interval calculator, authentication,
database-backed permissions, explicit administrator bootstrap, basic account
administration, resource browsing/availability, pending requests, approvals,
automatic/manual rejection, private notifications, catalog management, and
role/grant administration, booking cancellation, partial asset handovers, inspection,
maintenance scheduling/closure, filtered operational reports, inventory summaries,
CSV exports, audit viewing, facility completion, and daily/weekly recurring requests.
Confirmed booking policies are implemented: daily facility hours of 08:00–17:00
Asia/Manila, a 30-calendar-day start-date window, zero facility buffer, and no
fixed maximum asset duration (an explicit return date/time is still required).
The reminder dispatcher is implemented; deployment scheduler wiring, additional
staff dashboard metrics, maintenance rescheduling/special-event policies, and the remaining React booking/management workflows remain. No demo
accounts or default passwords are created. Requesters select specific individual items.

## Booking API

Migration `005_create_booking_workflow.php` creates facility/asset bookings,
specific-item allocations, maintenance records, and notifications. Existing
`users.id` foreign-key types are preserved. All schema changes use migrations.

- `GET /api/facilities` and `/api/assets` — active resource lists.
- `GET /api/facilities/{id}/availability?start=...&end=...` — availability and
  blocked calendar intervals without other requesters' details.
- `GET /api/assets/{id}/availability?start=...&end=...` — minimum available
  quantity throughout the selected interval and available specific items.
- `GET/POST /api/facility-bookings` and `/api/asset-bookings` — list or request.
- `GET /api/facility-bookings/{id}` and `/api/asset-bookings/{id}` — own booking,
  or a reviewer with the corresponding `view_all` permission.
- `PATCH /api/{facility|asset}-bookings/{id}/approve` — respective approval permission.
- `PATCH /api/{facility|asset}-bookings/{id}/reject` — respective rejection
  permission and JSON `reason`.
- `GET /api/notifications` and `PATCH /api/notifications/{id}/read` — own notifications.

List endpoints use `page` and `per_page` (maximum 100). All booking dates must be
ISO 8601 with seconds and an offset, for example `2026-12-10T09:00:00+08:00`.
URL-encode offsets in query parameters. Availability response fields ending in
`_utc` and stored database dates are UTC.

Facility requests require `facility_id`, `start_datetime`, `end_datetime`,
`activity_name`, `purpose`, and `participants_count`. Asset requests require
`asset_id`, `usage_start`, `expected_return`, `purpose`, and `quantity_requested`.
Individual-mode requests additionally require `instance_ids` with exactly that
many distinct, available item IDs. Department and requester come from the current
account, never from untrusted request fields.

Pending requests reserve nothing. Approval locks the parent resource, rechecks
availability using READ COMMITTED isolation, writes the approval, then rejects
each newly infeasible overlapping pending request. Individually feasible pending
requests remain pending even when they compete with each other. Notifications
and audit records commit in the same transaction. Automatic rejection records a
system actor and the triggering approval; users receive a reason.

For pooled inventory, `usable_quantity` excludes permanent unusable stock;
temporary maintenance is subtracted through dated maintenance rows, not also from
that baseline. Individual availability comes from instance states, reservations,
and maintenance. Overdue released inventory and returned items awaiting inspection
remain unavailable. Partial release, return, loss, and inspection endpoints use the handover ledger below.

Campus-wide facility hours are confirmed below. Resources with a custom
`operating_hours_json` are blocked from submission/approval until that policy is
implemented for custom schedules. The confirmed facility buffer is zero; approved cancellation follows the confirmed pre-start rule.

Availability endpoints return fresh database data with `Cache-Control: no-store`.
Automatic browser refresh/polling will be connected in the React frontend; the
current API alone does not push real-time updates to a browser.

### Booking checks

Run `php tests/booking_rules_test.php` for date/input validation. For end-to-end
booking checks, run two independent PHP server processes on ports 3012 and 3013:

```powershell
$env:CFABS_RUN_DB_TESTS = '1'
$env:CFABS_TEST_URL = 'http://127.0.0.1:3012'
$env:CFABS_TEST_SECOND_URL = 'http://127.0.0.1:3013'
php tests/booking_http_test.php
Remove-Item Env:CFABS_RUN_DB_TESTS, Env:CFABS_TEST_URL, Env:CFABS_TEST_SECOND_URL
```

The opt-in checks create temporary development records and remove their own rows
afterward. They cover facility conflicts, 6/5/4 quantity allocation, exact-item
selection, notifications/ownership, self-approval denial, and simultaneous
approvals through independent server processes.

## Catalog management

No additional tables or dependencies are needed for this increment; the APIs use
the existing catalog migrations and native LavaLust database layer.

- `GET/POST /api/departments`, `PATCH /api/departments/{id}` and
  `PATCH /api/departments/{id}/status` — department administration.
- `GET/POST /api/facility-categories` and `/api/asset-categories`; PATCH the
  corresponding `/{id}` or `/{id}/status` — category administration.
- `GET/POST /api/facilities` and `/api/assets`; GET/PATCH the corresponding
  `/{id}`, or PATCH `/{id}/status` — resource administration.
- `GET/POST /api/assets/{id}/instances`; PATCH an individual
  `/{instance_id}` or `/{instance_id}/status` — specifically tracked items.

Authenticated users can list active departments/categories. Facility and asset
reads require the respective view permission. Category writes require the scoped
category-management permission; department writes require `department.manage`.
Facility/asset creation, edits, and status changes use their distinct create,
update, and deactivate permissions. Item registration uses `asset.create`, edits
use `asset.update`, and retirement uses `asset.deactivate`.

Lists support `page`, `per_page` (maximum 100), and `include_inactive=1` for users
with the corresponding management/update permission. New records start active.
Status changes accept only `{"status":"active"}` / `{"status":"inactive"}`;
assets and instances also support `retired`. Records are never hard-deleted.

Department creation requires `department_code` and `department_name`; categories
require `category_name`. Facilities require `facility_code`, `facility_name`,
`category_id`, and positive integer `capacity`. Assets require `asset_code`,
`asset_name`, `category_id`, `owning_department_id`, and `tracking_mode`.
Quantity pools may set integer `total_quantity` and `usable_quantity`, with
`0 <= usable_quantity <= total_quantity`; individual assets leave both at zero
and are counted through instances. Instances require a globally unique `asset_tag`
and may have a unique nullable `serial_number`. Tracking mode is immutable.

Optional condition values are `good`, `fair`, `damaged`, `lost`, or `inspection`.
Unavailable pools must have zero usable quantity. A supplied `custodian_id` must
identify an active user with asset release permission. Metadata fields include
descriptions and locations; system-owned IDs, timestamps, creator, and status
cannot be mass-assigned through general create/update payloads. Operating-hours
configuration remains unavailable until its booking policy is implemented.

Catalog writes serialize identity checks using a database-scoped MySQL advisory
lock, then lock parent resources in the same order as booking approvals. Duplicate
identities return HTTP 409. They preserve audit snapshots in the same transaction.
Resolve outstanding requests before reducing stock or making a requested resource
unavailable. Facility capacity cannot fall below an outstanding request's count.
Deactivate/reassign active dependent records before deactivating categories or
departments. These guards preserve existing reservations and historical records.

With the development server running, execute the opt-in HTTP suite:

```powershell
$env:CFABS_RUN_DB_TESTS = '1'
$env:CFABS_TEST_URL = 'http://127.0.0.1:3012'
php tests/catalog_http_test.php
Remove-Item Env:CFABS_RUN_DB_TESTS, Env:CFABS_TEST_URL
```

The suite creates and removes its own temporary records. It exercises scoped
permissions, duplicate identities, item/pool separation, inventory bounds,
existing-booking protection, retirement visibility, and audit records.

## Frontend handoff

The React/Vite/TypeScript/Tailwind application now lives in the sibling
`../frontend/` directory (`Booking-System/frontend/`), outside this backend Git
repository, for separate deployment. See `../frontend/README.md` for setup, design tokens, session
handling, and current scope. Initial screens connect to existing LavaLust login,
profile, dashboard, notifications, permission-gated reports, resource catalogs,
date-based availability, request creation, recurring series, and own-only tracking
APIs. Staff approval/cancellation/handover controls
and management screens remain frontend work. Follow the UI/UX rules in `../frontend/AGENTS.md`
and verify pages visually and interactively.

## Role and account access administration

These APIs reuse the existing roles, permissions, user assignments, and audit
tables. No additional dependencies or schema changes are needed.

- `POST /api/roles` — create a custom role with `role_name` and optional
  `description`; requires `role.manage`. New roles start without grants.
- `PATCH /api/roles/{id}` — edit metadata with `role.manage`. System role names
  and the `is_system_role` flag cannot be changed through the API.
- `GET /api/roles/{id}/permissions` — inspect grants with `permission.manage`.
- `PUT /api/roles/{id}/permissions` — replace the complete grant list using
  `{"permission_keys":["facility.view","facility_booking.approve"]}`; requires
  both `role.manage` and `permission.manage`. Read the current list first and
  include every permission that should remain. Unknown/duplicate keys are rejected.
- `PATCH /api/users/{id}/role` — assign `{"role_id":123}`; requires both
  `user.manage` and `role.manage`.
- `PATCH /api/users/{id}/department` — assign an active `department_id`; requires
  `user.manage`. This also lets the bootstrap administrator acquire a department.
- `PATCH /api/users/{id}` — update `first_name` and/or `last_name` for yourself,
  or for another account with `user.manage`. Role, email, password, department,
  and status cannot be changed through the profile endpoint.

Role names use 3–80 lowercase letters, digits or underscores, starting with a
letter. Permission definitions are the installed backend's registry; introduce
new supported permission keys through a migration rather than arbitrary API text.
Granting approval permission explicitly is allowed for an authorized permission
administrator; the administrator role name never implies business approval.
Booking self-approval remains forbidden regardless of granted permissions.

Role assignments revoke the affected account's refresh tokens. Existing access
tokens still consult current database grants on each protected request, so new
permissions and revocation take effect without trusting stale JWT role claims.

Role/grant edits, account role assignments, and account status changes share a
database-scoped advisory lock. Authorization is rechecked after acquiring it.
Edits that would leave no active account with all three of `user.manage`,
`role.manage`, and `permission.manage` return 409 and roll back. This includes
concurrent demotions and removing a role's last administration grants. Keep an
explicitly provisioned administrator available; CLI bootstrap can recover an
installation that has no complete administrator. Self-status changes remain
blocked. All successful changes have before/after audit records without passwords.

Run the opt-in access suite with two PHP servers on ports 3012 and 3013:

```powershell
$env:CFABS_RUN_DB_TESTS = '1'
$env:CFABS_TEST_URL = 'http://127.0.0.1:3012'
$env:CFABS_TEST_SECOND_URL = 'http://127.0.0.1:3013'
php tests/access_http_test.php
Remove-Item Env:CFABS_RUN_DB_TESTS, Env:CFABS_TEST_URL, Env:CFABS_TEST_SECOND_URL
```

Tests create temporary accounts, roles, and departments and clean up only those
records. They check delegated administration, immediate revocation, protected
system identities, profile ownership, audited edits, and concurrent demotions.
Last-administrator checks skip when pre-existing complete administrators would
make removal of the test accounts safe; existing users/grants are never modified.

### Database connection reuse

The installed database loader can replace the connection on a repeated call.
Application models and the account access library therefore check the controller's
registered `properties['db']` entry before loading it. All models within a request
must share that adapter so profile updates, permission checks, rollback guards,
booking writes, notifications, and audits see the same transaction. Framework
internals remain unchanged. Access integration tests verify both read-your-writes
and rollback of a blocked last-administrator deactivation.

## Cancellation

Both requesters and staff with the resource's `cancel_any` permission may cancel
approved bookings before the scheduled start, provided assets have not been
released. This is the confirmed policy; no additional cutoff is configured.

Use `PATCH /api/facility-bookings/{id}/cancel` or
`PATCH /api/asset-bookings/{id}/cancel` with `{"reason":"Plans changed"}`.
Owners may also cancel their pending requests. Other users require the respective
staff permission. Approval status, cutoff, ownership, current account access,
and release state are checked under the resource lock. Released assets follow
the return workflow and cannot be cancelled. Terminal bookings cannot be cancelled
again.

Cancellation records its reason and actor, adds an in-app notification, and releases
the reservation in the same transaction. Quantity and exact-item availability
reflect the change on the next API read. Allocation rows remain as history.
Previously rejected requests stay rejected; users may submit new requests.
`tests/booking_http_test.php` covers owner/staff cancellation, forbidden actors,
the scheduled-start cutoff, released assets, restored availability, and concurrent
approval after this change.

## Partial asset handovers

Migration `006_create_asset_handovers.php` adds immutable `asset_handovers` and
`asset_handover_items` ledgers and the separate `asset_bookings.quantity_lost`
summary. Existing release/return fields remain cumulative projections; lost units
are never counted as physically returned. No existing handover history is invented.

- `POST /api/asset-bookings/{id}/release` — `asset_booking.release`; only within
  `usage_start <= now < expected_return`, as confirmed.
- `POST /api/asset-bookings/{id}/return` — `asset_booking.return`; permits partial
  physical returns or reported loss. Damage/loss also requires `asset.condition.manage`.
- `POST /api/asset-handovers/{id}/inspect` — inspect units from a return marked
  `inspection`; requires `asset.condition.manage`.
- `POST /api/asset-bookings/{id}/complete` — close an underfilled booking once all
  released units are returned/accounted for and inspections are resolved; requires
  `asset_booking.return` and JSON `reason`. This frees any unused approved balance.
- `GET /api/asset-bookings/{id}/handovers` — paginated history for the owner or
  a user with `asset_booking.view_all`.

Release/return/inspection payload example:

```json
{
  "quantity": 3,
  "condition_status": "good",
  "idempotency_key": "one-uuid-per-user-action"
}
```

Individually tracked assets additionally require exactly `quantity` distinct
`instance_ids`. Quantities must be positive integers. Release condition is `good`
or `fair`; return condition may also be `damaged`, `lost`, or `inspection`.
Inspection results may be `good`, `fair`, or `damaged`. Damage, loss and inspection
require `remarks`. A returned item cannot be released again under the same booking.

Keep one idempotency key per user action across retries: 16–80 letters, digits,
underscores or hyphens. Matching retries return the original event with
`replayed: true`; a different payload/actor under that key returns 409. Successful
responses contain `handover`, updated `booking`, and `replayed`. Internal request
hashes and retry keys are excluded. Concurrent writes share the resource lock and
READ COMMITTED transaction, including notifications and audit records.

For pools, the unreleased approved balance stays reserved during the booking.
Good/fair returned quantities become available; inspection returns remain held.
Damaged or lost units reduce usable inventory once, while total registered stock
and ledger records preserve accountability. Inspection may be resolved in batches.
For individual items, availability uses exact release/return/inspection history
and current item condition. Overdue outstanding units remain unavailable; unreleased
promises expire at the booked end. Full settlement automatically completes a fully
released booking after inspections are resolved.

If damage, loss, or inspection makes an existing approved reservation infeasible,
notify its requester and audit the conflict for custodian resolution. Approved
requests are not silently rejected or cancelled. Release rechecks physical stock.

Bookings with pre-existing nonzero release/return summaries but no matching ledger
cannot accept new handovers until an explicit, verified reconciliation migration
records the real history. Availability handles such legacy records conservatively.

With independent servers running on ports 3012 and 3013:

```powershell
$env:CFABS_RUN_DB_TESTS = '1'
$env:CFABS_TEST_URL = 'http://127.0.0.1:3012'
$env:CFABS_TEST_SECOND_URL = 'http://127.0.0.1:3013'
php tests/handover_http_test.php
Remove-Item Env:CFABS_RUN_DB_TESTS, Env:CFABS_TEST_URL, Env:CFABS_TEST_SECOND_URL
```

These opt-in checks create their own approved booking fixtures with active windows
instead of changing the system clock. They cover partial batches, inspection holds,
separate loss accounting, exact item custody, ownership, release timing, completion,
safe retries, and simultaneous handovers. Cleanup removes only the suite's records.

## Remaining backend checklist

- Complete: booking/approval/rejection/cancellation, finite daily/weekly recurrence,
  partial asset release/return/inspection, maintenance scheduling/closure, private
  notifications, reminder dispatch, filtered reports, inventory summaries, CSV
  exports, personal dashboard, audit viewing, and facility completion.
- Pending deployment: configure an operating-system scheduler for reminder dispatch
  and verify deployment settings; the CLI implementation alone does not schedule jobs.
- Pending product decisions: maintenance rescheduling/exception review and
  special-event overrides. Existing APIs enforce confirmed policies without overrides.
- Pending interface work: additional staff dashboard metrics and React integration.
  Work in the sibling `../frontend/` and follow its `AGENTS.md`; verify automatic
  availability refresh and notification feedback in the browser.

## Backend readiness review — 2026-11-07

All nine migrations are applied, with none pending. The five dependency-free
suites and nine HTTP/database/CLI integration suites passed 720 checks, including
concurrent approvals/handovers/administration, notification privacy, finite
recurrence, and long asset borrowing. Syntax checks passed for the 18 reviewed
PHP files and the updated integration-test helper. Integration fixtures were
removed after the checks.

One series-retry request returned HTTP 500 during verification; its cause was not
established and it did not reproduce in the completed rerun. The HTTP test helper
now reports allowlisted error categories without exposing response bodies, tokens,
credentials, or query bindings. No application source changes were needed during
this review. Browser integration, deployment scheduling, and the pending items
above still require separate verification.

## Initial reporting and facility completion

- `GET /api/dashboard` — the authenticated user's own booking counts by status
  and currently overdue assets. Role or query parameters cannot widen ownership.
- `GET /api/reports/facilities` — requires `report.facility.view`.
- `GET /api/reports/assets` — requires `report.asset.view`; also returns a
  separate list of currently overdue loans, independent of the report interval.
- `GET /api/audit-logs` — requires `audit.view`; filters `actor_user_id`,
  `entity_type`, and exact `action`, with `page` / `per_page` (maximum 100).
- `PATCH /api/facility-bookings/{id}/complete` — requires the existing
  `facility_booking.approve` coordinator permission, rechecked inside the resource
  transaction. Only approved bookings whose scheduled end has passed can complete.
  Repeating a completed action returns its existing state without duplicate
  notifications or audit records.

Reports/dashboard accept paired ISO 8601 `start` and `end` timestamps including
seconds and a timezone offset. Without a range they use the preceding 30 days in
UTC. Booking reports count intervals overlapping `[start,end)`; audit logs filter
event creation time. Unsupported filters and invalid pagination/ranges return 422.

Operational reports include status counts and paginated resources ranked by booking
count (default 100 per page). `reserved_seconds` sums scheduled booking durations clipped to the selected
range for approved/active/completed reservations. It is not quantity-weighted,
actual handover duration, or an occupancy percentage. Department/category filters,
CSV exports, and inventory summaries are implemented in the expanded reporting
section below. Occupancy percentages with operating-hours denominators are not
implemented. Audit viewing has no edit/delete endpoint.

Run the opt-in development checks with a PHP server on port 3012:

```powershell
$env:CFABS_RUN_DB_TESTS = '1'
php tests/report_http_test.php
Remove-Item Env:CFABS_RUN_DB_TESTS
```

The suite creates and removes its own fixtures and exercises ownership, scoped
report/audit permissions, range validation, bounded pagination, aggregate totals,
completion eligibility, and completion retry behavior. No schema changes or new
dependencies are introduced for reporting.

## Maintenance scheduling

Use `GET/POST /api/facility-maintenance` or `/api/asset-maintenance`, and
`PATCH /api/{facility|asset}-maintenance/{id}/complete` or `/{id}/cancel`.
Each endpoint requires the respective `facility.maintenance.manage` or
`asset.maintenance.manage` permission. Lists support `page`, `per_page` (maximum
100), and `resource_id`, and retain closed records. No new tables are required.

Facility payloads require `facility_id`, ISO 8601 `start_datetime`, `end_datetime`,
and `reason`. Asset payloads require `asset_id`, `start_datetime`, `reason`, and
positive `quantity_affected`; optional `expected_completion` may be null for an
open-ended block. Individually tracked assets additionally require a matching
`asset_instance_id` and quantity 1. Pools must not supply instance IDs.

Scheduling locks the resource using the same order as booking approval and
handover, then checks committed reservations and existing maintenance. Infeasible
maintenance returns 409 and rolls back without changing bookings or leaving a
maintenance row. Resolve booking conflicts or choose a different interval first;
there is no force override. Pending requests remain pending and receive a notice
when their requested interval/item is affected; approval rechecks availability.

Temporary pool maintenance reduces dated availability without subtracting the
usable-stock baseline again. Individual work blocks only its item. Open-ended or
overdue unfinished work remains unavailable until explicitly completed/cancelled,
including physical release checks. Completing work records responsible staff and
the actual timestamp; future work must be cancelled rather than marked complete.
Completion does not automatically repair damaged items or clear inspection holds.
Cancellation retains its actor/time in the audit, without fabricating a work
completion timestamp. Repeated closure to the same state is idempotent.

Run `php tests/maintenance_http_test.php` with `CFABS_RUN_DB_TESTS=1` and a
development server on port 3012. The suite cleans up its own fixtures and checks
permissions, conflicts, rollback, stock conservation, individual-item blocks,
overdue maintenance, closure timestamps, and restored availability. Deployment
scheduling of overrun alerts and advanced exception/rescheduling workflows remain on the checklist.

## Scheduled in-app notifications

Migration `007_add_notification_dispatch_key.php` adds a nullable unique dispatch
key to existing notifications. Existing approval/rejection/handover notices keep
working without a dispatch key. The scheduled dispatcher is CLI-only; its HTTP
route returns 404 before initializing the application.

```powershell
php lava notifications:dispatch
php lava notifications:dispatch --send --hours=24
php lava notifications:dispatch --recipient=123
```

The first command is a dry run: it reports `eligible`, `already_sent`, and
`inserted` counts without creating notifications or changing read state. `--send`
enables writes; `--hours` selects a 1–168 hour upcoming window (default 24).
`--recipient` restricts preview/delivery to one positive user ID. Invalid flags
fail instead of silently widening the window or recipient scope.

Events cover approved upcoming facilities/assets, approaching asset return
deadlines, physically outstanding overdue assets, and overdue unfinished facility
or asset maintenance. Fully returned/lost-accounted stock is not reported as an
overdue loan. Open-ended maintenance has no overdue deadline. Inactive recipients
are excluded. Borrowers receive their own reminders; overdue asset notices also
reach users with `asset_booking.return`, and maintenance overruns reach users with
the corresponding maintenance-management permission under the current global scope.

The worker locks each parent resource and rechecks its current record before
inserting notifications. It does not approve, reject, cancel, or complete records.
A database-scoped worker lock prevents concurrent dispatchers. Unique keys cover
entity/event/deadline/recipient, so repeat runs do not duplicate an event or reset
read state. A changed deadline or newly authorized recipient can produce a new
notice. Overdue notices are sent once per deadline, not daily. Messages use campus
time (`Asia/Manila`); storage remains UTC. Internal keys are excluded from inbox APIs.

### Deployment scheduler

No operating-system task is installed by development code. At deployment, configure
cron or Windows Task Scheduler to run every five minutes with the repository as
the working directory. Example Windows action:

- Program: `C:\wamp64\bin\php\php8.3.28\php.exe`
- Arguments: `"C:\wamp64\www\Booking-System\Lavalust\lava" notifications:dispatch --send --hours=24`
- Start in: `C:\wamp64\www\Booking-System\Lavalust`

Use the deployed PHP executable/path on other hosts. Capture output/errors and
monitor exit status; an already-running worker exits nonzero. Notifications are
in-app only; email/SMS delivery is not introduced. Future React work will use
useful compatible libraries in its separate folder, without duplicate packages.

Run `php tests/reminder_cli_test.php` with `CFABS_RUN_DB_TESTS=1` to exercise actual
CLI dispatch against temporary fixtures. Tests restrict sends to their own user
IDs, verify read-state preservation, repeat dispatch, deadline changes, inactive
recipients, and permission-based recipients, and clean up their records.

## Expanded report filters and CSV

Booking reports now accept `department_id`, `category_id`, `resource_id`,
`requester_id`, and `status` alongside paired `start`/`end`. Filters apply to both
status totals and resource aggregates; current overdue asset rows also honor the
filters but remain independent of the reporting date range. Department means the
booking's recorded department; category refers to the resource's current category.

JSON resource lists support `page` and `per_page` (default/maximum 100) and expose
`pagination.has_more`. Add `format=csv` for a filtered resource-summary download.
CSV exports ignore neither pagination nor truncation: page parameters are rejected,
and exports exceeding 10,000 resources require narrower filters. Untrusted cells
that resemble spreadsheet formulas are quoted as text. Export permissions are
identical to the corresponding JSON report permissions.

`GET /api/reports/inventory` requires `report.asset.view`. Filter by owning
`department_id`, `category_id`, `resource_id`, `status`, or `tracking_mode`; JSON
pagination and CSV export work the same way. Pool counts use registered/usable
stock, while individual counts derive from item records and conditions. These
are inventory counts, not free quantities for a date: use the availability API
for reservations, physical custody, inspection, and maintenance effects.

## Confirmed facility hours

Facilities are bookable every day, including weekends, from 08:00 to 17:00
Asia/Manila. `Facility_hours` validates normalized UTC intervals at submission and
approval. A request must fit within one campus day; an exact 08:00–17:00 request
is valid. Availability includes `operating_hours` and `within_operating_hours` and
reports an out-of-hours selected interval as unavailable. Maintenance may occur
outside booking hours. Existing historical records are retained, and asset
borrowing windows are unchanged. Unsupported per-facility custom schedules still
fail closed. The advance limit is 30 days and the facility buffer is zero. Recurring requests are supported below; asset bookings have no fixed maximum duration. Special-event exceptions await a decision.

Use `php tests/facility_hours_test.php` for UTC boundary/weekend checks. The
expanded `report_http_test.php` checks filters, inventory pagination, permission
boundaries, and spreadsheet-safe CSV. Booking/catalog fixtures use 09:00–11:00
campus time to match the confirmed policy.

## Confirmed advance-booking limit

Facilities and assets may start up to 30 calendar days ahead, inclusive, based on
Asia/Manila dates. This includes the whole 30th campus day, rather than a rolling
720-hour cutoff. Submission and approval enforce the limit. Availability exposes
`advance_booking_window` with `days`, `timezone`, `latest_start_date`, and
`within_limit`; out-of-window selections are unavailable. Existing approvals and
history are retained. The limit applies to the start date; asset bookings have no
fixed maximum duration. An explicit return date/time after the start is required
and may fall beyond the 30-day start-date window. Availability is checked across
the entire requested interval, including each recurring occurrence. Run `php tests/advance_window_test.php`
for midnight, inclusive-day, and leap-year boundaries.

The confirmed facility turnover buffer is zero minutes. Booked intervals are
half-open, so a booking ending at 10:00 permits another to start at 10:00.
Facility availability includes `turnover_buffer_minutes: 0` in its operating-hours
metadata. The existing interval boundary tests verify both adjoining edges.

## Recurring booking requests

Migration `008_create_booking_series.php` creates the series header and nullable
`series_id` foreign keys on existing facility/asset bookings. Existing bookings
and their history are retained. A series is a finite collection of normal pending
requests, not an automatically approved reservation or an indefinitely running job.

Use `POST /api/facility-booking-series` or `/api/asset-booking-series` with the
corresponding booking-create permission:

```json
{
  "booking": {
    "facility_id": 1,
    "start_datetime": "2026-11-09T09:00:00+08:00",
    "end_datetime": "2026-11-09T10:00:00+08:00",
    "activity_name": "Weekly meeting",
    "purpose": "Department planning",
    "participants_count": 10
  },
  "recurrence": {"frequency": "weekly", "interval": 1, "count": 4},
  "idempotency_key": "one-unique-key-per-series"
}
```

The booking object uses the normal fields for that resource kind. Asset series
also preserve the requester-selected `instance_ids` for every occurrence. Supported
frequencies are `daily` and `weekly`, interval 1–4, and count 2–30. Repeats follow
the same Asia/Manila wall-clock times, preserving multi-day asset duration. Every
starting date must still fit the 30-day advance window. Monthly patterns, unbounded
recurrence, per-date overrides, and automatic renewals are not supported.

All dates are validated in one transaction using the existing booking service and
LavaLust savepoints. If a later occurrence is unavailable or invalid, the whole
submission rolls back, including the group, earlier requests, notifications, and
audits. Error messages identify the failing occurrence/date. Pending requests do
not reserve capacity. Each occurrence is approved, rejected, cancelled, released,
or returned independently through the existing endpoints and policies. Approval
of one occurrence does not automatically approve its siblings.

Series keys are normalized to lowercase to match their database identity.
Retries with the same requester/key and payload return the original group and
current occurrence states with `replayed: true` (HTTP 201). A different payload
under that key returns 409. An advisory lock serializes identical submission keys;
per-resource locks serialize availability decisions. Single-booking endpoints
reject a `recurrence` payload instead of silently discarding it.

`GET /api/booking-series/{id}` returns the group and occurrences to the owner or a
reviewer with the relevant `view_all` permission. Internal hashes/keys are hidden.
Normal booking lists include `series_id` so the frontend can group occurrences.
No bulk approval/cancellation endpoint is introduced.

The HTTP suite also verifies long asset borrowing: a 60-day loan may start within
the advance window, retain its full return date, and block conflicting later
requests. A start date beyond the advance window is still rejected.

Run `php tests/recurrence_rules_test.php` for calendar planning and the opt-in
`php tests/series_http_test.php` against the development server for real transaction
rollback, retries, ownership, per-occurrence review, and both asset tracking modes.

## Frontend foundation — 2026-11-07

The first frontend increment is in `../frontend/`: shared design tokens/components,
login with memory-only tokens and refresh rotation, protected responsive navigation,
personal overview, private notifications with mark-as-read, read-only profile,
and resource reports gated by current explicit permissions. Active views poll
at 30-second intervals; permission profiles refresh on focus and every 60 seconds.
The backend remains unchanged in this increment. No schema change was needed.

Validation: production build/type checks and Oxlint pass; 14 dependency-free Node
checks cover API sessions, refresh concurrency and late responses, account/session
changes, revocation retries, errors, cancellation, and UTC/campus dates. Browser QA
uses disposable database accounts, verifies actual LavaLust sign-in/sign-out,
invalid credentials, notification read feedback, report permissions, responsive
navigation, and loading/empty states at desktop and 390px mobile widths.

The next increment adds facility/asset catalogs and date-based availability (see
below). Remaining frontend work includes request forms, specific-item selection,
recurring bookings, request tracking, approvals/cancellation, handovers, and
management screens. Deployment configuration is deferred until the complete
system works end to end. Special-event/maintenance-rescheduling decisions remain
separate product questions.

Automatic refresh was verified by inserting a private notice after the requester's
empty notification screen loaded: polling displayed it without a click or reload.
Disabling the exact temporary requester fixture then triggered a protected API
401, failed refresh, cleared session, and an announced sign-in-again message.
Both temporary QA users, their notices/tokens/audits, and the temporary helper
were removed after verification; the owned development servers were stopped.

## Resource browsing and availability — 2026-11-07

The sibling frontend now consumes existing catalog/detail and availability APIs
for facilities and assets; no backend or migration changes were required.
Permission-gated catalogs paginate active resources. Selected-period checks show
whole-period availability and minimum free asset quantity, with available asset
tags for individually tracked stock. Facility details include seven days of
selectable open intervals, excluding approved bookings and maintenance; pending
requests do not block the schedule. Intervals are clipped/merged within campus
08:00–17:00 hours and elapsed time today is excluded. Trailing calendar dates
outside the 30-day start window are disabled.

Datetime controls explicitly send Asia/Manila offsets, independent of the device
timezone. Invalid/past/reversed periods, facility hours and the inclusive 30-day
start-date limit are checked before querying; asset returns can extend beyond
that window. Active availability and schedule queries poll every 30 seconds,
refetch on focus, and show loading, error, unavailable and available states.
Availability checks do not create or reserve a booking. Deployment configuration
remains deferred at the user's request.

Build/type checks, Oxlint, and 22 Node checks passed, including eight new checks
for campus dates, intervals, advance boundaries, long asset loans, merged blocks,
back-to-back times, unfinished maintenance, and elapsed operating time.

Live browser checks confirmed an approved 9–10 AM facility booking blocks that
period, while a pending 10–11 AM request does not remove it from the seven-day
open-time view. Choosing the adjacent 8–9 AM slot updated the inputs and returned
available. A 10-unit asset pool with a six-unit approved reservation displayed
four free units; changing the disposable reservation to eight units updated the
screen to two free units through polling without a click or reload. Mobile
resource/date controls were reviewed at 390px without horizontal overflow.

The individually tracked fixture exposed only its unreserved asset tag; its
approved item was absent from the available list. Native date-control changes
also showed the end-before-start validation message and suppressed availability
until the interval was valid again. Remaining request forms will let requesters
select those available tags; browsing does not select or reserve an item.

A return date about 60 days after the asset start remained valid and returned
full-period availability. The facility open-time view was also reviewed on
mobile as a single-column schedule with selectable intervals and no horizontal
overflow. The exact temporary QA account, reservations, allocations, asset tags,
resources, categories, department, tokens/audits, and helper were removed after
verification. The owned preview servers were stopped. Next: request forms,
specific-item selection, recurring requests, and requester booking tracking.

## Requester submission and tracking — 2026-11-07

Resource details now provide permission-gated request forms. Facility requests
collect activity, participant count, and purpose. Asset requests collect a valid
quantity or requester-selected available asset tags. An assigned department is
required, and the backend remains authoritative for its active status. Forms use
the existing date/availability controls and campus timezone. Submit buttons guard
duplicate clicks and freeze dates/items while processing. Receipts link directly
to saved bookings; pending requests still do not reserve stock or facilities.

Finite daily/weekly recurrence uses the existing series endpoints. Dates are
previewed before submission, every occurrence must meet the advance window, and
all dates/items are checked together by the backend. A later failure rolls back
the entire series. Series retry after uncertain network/server/processing-lock
responses preserves the exact payload/key; uncertain singleton writes cannot be
automatically retried because their endpoint has no idempotency contract.

`GET /api/facility-bookings?mine=1` and `/api/asset-bookings?mine=1` now explicitly
restrict lists to the authenticated owner, including staff with `view_all`.
Default staff listing behavior is preserved. `mine` accepts only `0` or `1`.
Authorized asset-booking detail now adds `selected_items` (ID/tag/serial) while
preserving the existing integer `instance_ids` array; ownership/grant checks run
before the model query. The frontend shows human-readable selected tags.

`/requests` paginates own bookings by kind. Detail/series screens show campus
dates, statuses, purpose, quantities, and rejection/cancellation reasons, and
refresh while visible. Each series occurrence keeps its own decision. Staff
approval and cancellation controls, handover operations, and management UI remain
subsequent work. Deployment configuration stays deferred.

Validation so far: build/type checks, Oxlint and 33 Node checks pass. The browser
created a pending facility request and opened its saved detail. A weekly asset
series deliberately conflicted on occurrence 2; the UI identified the date and
kept form input. A database check confirmed zero series/partial pending rows for
that QA requester after failure. Removing the exact temporary blocker and
resubmitting created two pending occurrences with separate tracking links.

The final targeted backend run passed 132 booking/notification checks, including
new own-only staff-list behavior, unchanged default staff lists, invalid filter
rejection, selected asset-tag details, unauthorized requester denial, and
concurrent approvals. The recurring-request suite passed 54 checks. Frontend
checks passed 33, with no additional dependencies or schema changes.

Browser QA also submitted one requester-selected projector tag on mobile; the
saved request detail showed exactly that tag and quantity 1. The checkbox form,
receipt, and tracking details were reviewed at 390px without horizontal overflow.
Series tracking listed both independently pending occurrences before its temporary
QA statuses were changed to exercise approved/rejected tracking and reason display.
The status changes used only disposable fixtures; staff approval UI remains pending.

Final browser checks confirmed own-only facility/asset history, independent
approved/rejected occurrence states, and the rejected occurrence's human-readable
reason. Mobile layouts were checked at 390px and 320px with no horizontal overflow.
The expected database row counts and exact selected tag were verified independently.
All exact QA fixtures, UI-created bookings/series, allocations, tokens/audits, and
the temporary helper were removed. Owned preview servers were stopped. Next:
staff approval/rejection queues and requester/staff cancellation controls, followed
by asset release/return and administration screens. Deployment remains deferred.


### Frontend booking decisions

The sibling frontend now provides `/review` and `/review/:kind/:id`, guarded by
the matching booking `view_all` permission. The existing paginated list API is
reused without backend changes; all statuses appear newest first. Individual
booking details now expose explicit approve/reject/cancel actions as permitted.
Requesters use their existing detail routes to cancel eligible own requests.
Confirmation dialogs explain automatic conflict rejection and require reasons
for rejection/cancellation. Approval displays the automatic rejection count.
Decision buttons remain disabled through the authoritative refresh. Unknown
responses require an explicit successful status check before another action.
The API remains authoritative for concurrent decisions, current grants, cutoffs,
resource availability, and released-asset protections.

Validation: frontend build, Oxlint, and 39 Node checks passed. Browser QA used
disposable facility requests to exercise approval with automatic conflict
rejection, staff cancellation, manual rejection, and requester cancellation.
Responsive dialog checks cover mobile widths. No migrations, dependencies,
application backend changes, or deployment configuration were introduced.


### Frontend partial handovers and custody snapshot

`GET /api/asset-bookings/{id}/custody` authorizes the owner or
`asset_booking.view_all` and reads under the existing resource/booking lock.
It returns the current booking, tracking mode/status, ledger totals, a summary
reconciliation flag, unreleased/outstanding instance IDs, and remaining inspection
holds tied to their source returns. It contains no retry keys/hashes. Existing
models and transaction utilities are reused; no schema change is needed.

The sibling frontend asset-detail page includes partial release/return forms,
condition/remarks controls, exact-tag choices, inspection resolution, explicit
underfilled completion, and paginated immutable batch history. The authoritative
snapshot supplies action balances even when history has multiple pages. Local
eligibility is advisory; writes retain the existing transactional checks. Damage
and loss require the separate condition-management grant. Lost inventory is not
counted as physically returned. Native confirmation dialogs review every batch.

Unknown responses preserve the serialized payload/key in memory for exact retry.
Background refresh failures keep mounted forms and their recovery state; new
batches are disabled until custody can be refreshed. A reload loses the in-memory
attempt, so check history before recording a new batch. Successful writes refresh
custody, booking/history, notices, dashboard, and availability queries. Deployment
configuration remains deferred.


Browser verification used disposable accounts and assets: released 6 of 10 pool
units, returned 3 normally and 3 for inspection, resolved the source inspection,
and completed the unused four-unit balance. An individual booking released only
the selected tag and recorded its loss separately from physical returns. Native
review dialogs, tag choices, receipts/history, and a 320px layout were checked;
no horizontal overflow was present. Temporary browser fixtures were removed.
Frontend production build, Oxlint, and 48 Node checks passed.

The isolated HTTP/database suite passed 140 handover/inventory checks, including
snapshot ownership, inspection balances, reconciliation, exact-item states,
release timing, damage/loss accounting, concurrent over-release prevention, and
same-key concurrent retries. An earlier run sharing the browser server timed out;
the full rerun used dedicated ports 3012/3013 and completed successfully. PHP
syntax checks passed for all changed PHP files. Test fixtures were cleaned up.

## Frontend catalog administration

The separate React application now exposes `/manage` with permission-filtered
sections for departments, both category catalogs, facilities, assets, and nested
`/manage/instances/:assetId` tagged items. It uses existing Catalog controller
routes; backend source and schema are unchanged. Create/update/deactivate grants
remain separate, inactive lists require update access, and no delete UI exists.

Forms load all active lookup pages, preserve inactive current references visibly,
validate byte limits and stock quantities, preserve immutable tracking mode, and
send only changed fields on edit. Status changes use a native confirmation dialog.
Server dependency and outstanding-booking safeguards remain authoritative.
Unknown writes are not automatically retried; users must check the refreshed list.
Custodian assignment/image-path editing and account/role screens remain pending.
Deployment configuration remains deferred.

Validation: production build, TypeScript, Oxlint, and all 54 frontend Node checks
passed. Browser checks with a disposable administrator created a department,
asset category, individual asset, and exact tagged item; edited the item location;
and confirmed its inactive status. Saved location/status/tracking mode were also
verified directly in the database before cleanup. Desktop and 320px mobile
layouts, loading feedback, modal focus, and status confirmation were reviewed;
no horizontal overflow was present. Temporary records/accounts and QA scripts
were removed. No backend regression suite was rerun because backend application
source was unchanged in this increment.

## Frontend access administration

The separate React app provides account administration at
`/administration/accounts` and shared-role administration at
`/administration/roles`, using the existing Accounts and Access APIs. No backend
application source, migrations, dependencies, or deployment settings changed.

Account controls follow user.manage; changing roles additionally needs role.manage.
Without that grant, creation leaves role selection to the backend's faculty
employee default. Names, active department, role and status use separate endpoints;
self-status controls are hidden. Username/email edits and password resets are not
exposed because existing APIs do not support them. Initial passwords retain exact
bytes, stay in memory, and are excluded from confirmation summaries.

Role details require role.manage. Permission inspection needs permission.manage;
full-set replacement needs both. Each grant editor starts with a fresh snapshot,
keeps selections across filtering, and reviews additions/removals. A pre-write
read detects intervening grant changes and requires reopening the editor. The
existing API has no compare-and-swap/version field, so a concurrent change after
that check can still follow last-write-wins behavior.

All writes require review and are never automatically retried. Unknown outcomes
instruct users to close and check the current list. A guarded session-refresh event
reuses the auth provider's existing live profile loader after writes. Backend
system-role naming, live-grant checks and last-administrator guards stay in force.
Role assignment revokes the affected account's refresh tokens.

Frontend validation passed the production build, TypeScript, Oxlint, and 62 Node
checks. Browser checks used disposable accounts and roles, covered name and active
department edits, reduced-access role assignment, account deactivation, filtered
permission selection, explicit removal review, and detection of an intervening
permission edit. A reopened editor loaded the current grant set successfully.
Desktop and 320px mobile layouts and native dialogs were reviewed without
horizontal overflow. A development hot update required a fresh memory-only login;
the account-status workflow then completed successfully.

A separate permission.manage-only browser session confirmed that accounts/role
editing controls are absent and grant checkboxes/save controls are disabled while
permission inspection and filtering remain available. Saved account name,
department, role, inactive status, and the empty test-role grant set were verified
directly before cleanup. All disposable browser accounts, roles, departments,
metadata and helper scripts were removed. The local preview servers were stopped.

The existing isolated HTTP/database access suite passed all 88 checks, including
live grant revocation, protected system role names, last complete administrator
protection, concurrent demotions, audit records, and fixture cleanup. Its PHP
servers used ports 3012/3013 and were stopped after the successful run.

## React maintenance management

The separate frontend now exposes `/maintenance/facility` and
`/maintenance/asset`, guarded by the matching maintenance-management grant.
Lists use the existing newest-first pagination and resource_id filter. Resource
selectors load every page; update permission permits inactive records. Without
resource-view access, staff can filter by ID and close existing work, while this
UI disables scheduling because it cannot browse resources or tracking details.

Scheduling uses explicit Asia/Manila dates and existing backend payloads. Work
may already have started, facility ends are required and future, asset ends may
be omitted, and maintenance does not inherit requester booking hours/windows.
Pool quantities are checked against usable baseline; individual work selects one
exact tag. Existing server checks protect aggregate stock and committed bookings.
Pending requests remain pending and receive maintenance notices.

Scheduled/active/overdue timing is derived without changing persisted status.
Expected-end passage never implies completion. Started open work can be completed
or cancelled; future work can only be cancelled. Review dialogs explain availability
and condition/status effects. The existing shared confirmation dialog now accepts
caller-specific review text, success feedback, session refresh, and cache keys;
account/role administration retains its original defaults. No automatic mutation
retry, rescheduling endpoint, schema, dependency, or deployment change was introduced.
The existing LavaLust Maintenance_model history query adds an optional asset_tag
label by joining its exact item ID; writes and permission checks are unchanged.
The list and closure review use that label with an ID fallback.

Validation so far: all 71 frontend Node checks, production build, TypeScript and
Oxlint passed. The isolated backend maintenance suite passed 58 HTTP/database
checks covering permission boundaries, overlapping work, committed-booking
conflicts, aggregate stock bounds, overdue release blocking, exact-item holds,
explicit completion, restored availability, audit/completion attribution and
fixture cleanup. Its server on port 3012 was stopped after the successful run.

Browser verification confirmed overdue facility work could be explicitly completed
and new future facility maintenance could be scheduled outside booking hours
(06:00–07:00). Future work exposed cancellation without a completion action.
Open-ended maintenance affecting three of ten pool units showed seven available
in the existing resource availability screen for a future borrowing period.

The browser availability screen refreshed from seven back to ten pool units after
explicit completion, with usable baseline unchanged. Exact-tag review selected
only the second projector tag, derived quantity one, and displayed open-ended
future work clearly at 320px without horizontal overflow.

Final browser checks confirmed cancellation of the future second-tag record,
readable asset tags in history, facility/resource filtering, completion attribution,
and desktop/mobile layouts. A saved preview is retained outside the project.
Direct database checks verified pool baseline ten, three-unit maintenance completed,
one-unit second-tag maintenance cancelled, and both open-ended ends stored as NULL.
All disposable browser records/accounts, metadata, and helper scripts were removed;
owned frontend and browser API servers were stopped.

After the read-only asset-tag label addition, the complete isolated backend suite
passed 65 checks, including filtered facility history, null pool tag labels, and
exact item identity/tag labels. Changed PHP files passed syntax checks. Frontend
validation remained green at 71 tests, production build/TypeScript, and Oxlint.
All integration fixtures were cleaned by the suite; its final server was stopped.

## Report filters, inventory and CSV frontend

Reports now provides permission-gated facility booking, asset booking and asset
inventory views using existing Reports endpoints. Draft fields are applied or reset
explicitly; mode changes reset their filters and pagination. Dates are paired
Asia/Manila timestamps with an exclusive end, or the existing rolling 30-day
server default. Resource, category, department and requester filters follow each
endpoint's actual fields. Catalog-name suggestions use existing visible catalogs;
manual IDs remain available for historical records or unavailable suggestions.

Status counts cover all matching bookings. Resource rows are paginated; scheduled
hours count approved-or-later duration clipped to the range, without implying
occupancy percentages or quantity-weighted asset usage. Overdue rows are a separate
current, at-most-100 snapshot that ignores date range and honors other filters.
Inventory displays registered/usable baseline stock, including item-derived counts.

Authenticated CSV downloads reuse the existing client refresh rotation and server
exports. A CSV MIME check rejects HTML/JSON success responses; safe JSON failures,
export-limit errors and network failures stay visible. Late downloads after account
changes are rejected, unmounts abort, and token values never enter download URLs.
CSV queries omit pagination, retain applied filters, and pin the displayed server
period using the backend's second-precision ISO format. Downloads cover up to
10,000 resource rows; status summaries and current-overdue rows are separate UI
sections. No backend application source, migrations or dependencies changed.

The user's full external-integration attachment was embedded in specs.md, with an
archival copy in docs/external-integrations.md. Google Sign-In, FullCalendar,
email, QR workflows and Excel/PDF exports remain pending follow-on scope, in that
order after core reporting/verification. Existing business rules and LavaLust
migrations remain authoritative. Deployment configuration remains deferred.

Validation: production build, TypeScript, Oxlint, and all 79 frontend checks passed,
including CSV/JSON shared refresh, forbidden/limited export failures, unexpected
content types, session changes, aborts, filter scope, and pinned second-precision
report periods. The existing isolated backend suite passed 59 reporting/privacy/
facility-completion checks, including combined filters, CSV formula escaping,
non-truncated exports, inventory counts/pagination, and authorization. Its port
3012 server was stopped after successful fixture cleanup.

Browser checks combined resource, department, category, requester, status, and an
explicit campus date period. The facility screen and downloaded CSV both showed
one completed booking with 3600 scheduled seconds. A conflicting inventory filter
showed the empty state; selecting individual tracking returned two registered and
one usable item, matching its downloaded CSV. The 320px layout had no page overflow;
its labeled inventory table supported keyboard horizontal scrolling.

The browser's asset booking view also showed one matching released booking,
24 scheduled hours, and two currently outstanding units. Downloaded CSV files were
parsed and checked against the exact filtered fixture IDs/counts before removal.
All disposable report accounts, resources, bookings, metadata, helper scripts, and
test downloads were removed. Owned local preview/API servers were stopped.
A fixture setup initially exceeded the booking-reference column length; its partial
owned records were cleaned, references shortened, and the corrected fixture passed
all browser checks. The external-integration work remains pending, not implemented.

## Core workflow verification and final UI gaps

Core verification found that the existing facility-completion and audit APIs had
no corresponding staff UI. Request/review details now expose completion for an
APPROVED facility booking after its scheduled end, requiring the actual backend
permission facility_booking.approve. Eligibility refreshes every 30 seconds and
is checked again on submit; the backend retains transactional and idempotent
checks, requester notification and audit recording. The completion timestamp is
shown. Asset completion continues through its established custody workflow.

The new /audit page requires audit.view and reads the existing audit-logs endpoint.
It supports the existing paired date range, actor_user_id, exact entity_type/action
filters, total-aware pagination, fresh-on-entry reads, visible polling and safe
expandable recorded values. Backend APIs and business permissions are unchanged.
No edit/delete/export operation is exposed. This is separate from the later
Google/FullCalendar/email/QR/Excel-PDF scope, which remains pending.

The migration status check confirmed all nine existing migrations (000–008)
applied with zero pending. No migrations were run or rolled back during this
verification. Frontend tests, build/TypeScript, and Oxlint passed; the suite now
contains 83 checks, including elapsed-end completion eligibility and audit filters.

Browser checks used independent requester, booking-admin, and audit-only sessions.
The requester had no completion/audit controls; future approved work exposed no
completion action. Staff completed ended approved work through review, received
its timestamp, and the owner received the private completion notification.
Audit-only access supported exact actor/entity/action filtering, a 21-row fixture
across two pages, and expandable recorded values at 320px without page overflow.
The actual facility_booking.completed event matched the staff and booking IDs.

### Complete core regression result (2026-11-07)

All 14 existing PHP suites completed successfully against the configured development
database using isolated servers on ports 3012/3013:

| Suite | Checks |
| --- | ---: |
| Booking rules | 12 |
| Availability | 12 |
| Advance window | 5 |
| Facility hours | 6 |
| Recurrence rules | 9 |
| Authentication/authorization | 68 |
| Access administration | 88 |
| Catalog integrity | 91 |
| Booking/notifications/concurrent approval | 132 |
| Recurring submissions | 54 |
| Partial custody/inventory/concurrent retries | 140 |
| Maintenance/inventory protection | 65 |
| Reports/privacy/facility completion | 59 |
| Recipient-scoped CLI reminders | 23 |
| **Total** | **764** |

Frontend validation passed 83 Node tests, production build/TypeScript, and Oxlint.
Browser verification covered role separation, future/ended completion eligibility,
completion timestamp and private notification, exact audit filters, pagination, and
mobile recorded-value display. Direct verification found exactly one completion
audit and one completion notice. All disposable UI fixtures and helpers were removed;
the regression suites completed their cleanup, and owned servers were stopped.
Migration status remained 9 applied / 0 pending; no schema reset or migration run
was performed. This verifies the current core workflows, not the deferred external
integrations or deployment configuration.

The user confirmed the next Google integration may support school accounts and
personal Gmail, still limited to existing active authorized campus accounts and
secure linking. That decision is recorded in specs.md; Google authentication is
not implemented by this verification increment.
# Asset QR increment (2026-11-07)

QR support is restricted to assets by the user's latest decision: one label per
quantity pool and one per individually tracked item. Migration 011 adds and
backfills `qr_labels`; new catalog records receive labels atomically. No facility
QR or attendance endpoints are introduced. See [asset-qr.md](asset-qr.md) for
permissions, regeneration/revocation, optional scanned handover payloads and tests.

The separate frontend provides asset scanning, catalog label links, local SVG
generation/download, print layout and optional scans in the existing confirmed
release/return forms. `qrcode.react` renders labels; `@zxing/browser` decodes
camera/image input on demand. Deployment and live Resend configuration remain
deferred. QR-linked maintenance-report submission/assignment remains separate
pending scope rather than being treated as already implemented.
# Administrator document exports (2026-11-07)

The existing Reports endpoints now accept `format=xlsx` and `format=pdf` for
`system_admin` users who also retain the relevant report-view grant. Other roles'
JSON/CSV access is unchanged. The frontend offers these formats only to system
administrators and preserves existing applied filters and reporting periods.
See [report-exports.md](report-exports.md) for limits, dependencies, audit logging
and verification. No database migration or deployment configuration was added.
# Asset problem reporting (2026-11-07)

Migration 012 adds asset problem reports and private photos. Only system
administrators with asset maintenance permission may review and assign reports.
Assigned custodians can start/update/resolve work; existing maintenance stock and
booking checks are reused. Reporter privacy, optimistic versions, notifications
and linked maintenance completion/cancellation are enforced on the backend. See
[asset-problems.md](asset-problems.md) for the lifecycle and API. The separate
frontend exposes Asset problems and a Report a problem link from asset QR lookup.
