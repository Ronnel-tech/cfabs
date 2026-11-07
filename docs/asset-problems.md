# Asset problem reports and custodian work

## Approved access

The user confirmed on 2026-11-07 that **only system administrators may review
and assign reports**. These actions require the current `system_admin` role and
`asset.maintenance.manage` grant. An active asset custodian with that grant can be
assigned. Other maintenance staff cannot read or change an unassigned report.

An active account with `asset.view` may report a problem with an active asset.
Reporters read their own reports and photos. Assigned custodians with current
maintenance permission can read their assigned reports, start work, record
progress and resolve work. Authorized system administrators can perform those
work actions too. Reassignment immediately revokes the former custodian's private
report/photo access; email delivery rechecks the same current access.

## Lifecycle and inventory

Migration `012_create_asset_problem_reports.php` adds `asset_problem_reports`
and a private photo table. Existing catalog, users, maintenance, notifications and
audit tables are reused. There are no new dependencies or facility-report routes.

Reports progress through `submitted`, `assigned`, `in_progress`, then `resolved`.
An administrator may reject a submitted/assigned report with a reason. Closed
reports cannot be reassigned. Administrator reassignment of active work retains
its linked maintenance record. Report text, asset identity and photos are
immutable after submission; the detail screen shows current status, latest staff
notes, assignment and resolution. Audit logs retain the action/version trail.

Submission does not alter inventory or reserve stock. A pool report names the
affected quantity; an individual report identifies exactly one active item.
QR-originated forms carry the same identifier/item association and revalidate it
on submission. Manual reports remain supported.

Starting work calls the existing `Maintenance_service::create` within its asset
transaction. Work starts now and can have an optional future expected completion.
The report link and state update commit with the maintenance block. Existing
stock, overlapping maintenance and committed booking checks remain authoritative;
staff must resolve booking conflicts before starting work.

Completing linked maintenance resolves the report in the same transaction.
Cancelling linked work returns the report to `assigned` and allows a new start.
Both the report-specific and existing maintenance-close endpoints enforce the
current assignment or administrator role for linked work. Existing unlinked
maintenance keeps its behavior. Completion does not repair damaged inventory or
reactivate resources automatically.

## Concurrency and notifications

Submission uses a reporter-scoped idempotency key and content hash; identical
retries return the original report. Changed content with the same key conflicts.
Updates require integer `expected_version`. Writes lock the parent asset before
report rows and reject stale versions. An already committed resolution with the
same notes can be recovered without duplicate resolution events.

Submission notifies the reporter and eligible administrators. Assignment and work
updates notify the reporter and current custodian. In-app notices and the existing
Resend outbox support submitted, assigned, started, progress, rejected, resolved
and work-cancelled events. Emails contain only reference/current status/timestamp;
descriptions, staff notes, photos and resolution text are excluded. Live Resend
sending remains disabled until separately configured.

## Private photos

One optional JPEG/PNG attachment up to 2 MB is accepted as a JSON data URL.
Dimensions are limited to 4096 pixels per side and eight megapixels. The backend
decodes and re-encodes pixels to JPEG, stripping source metadata and appended
payloads. SVGs and arbitrary files are rejected. Photos are stored in the database,
never public filesystem paths. Responses expose `has_photo`, not binary data.

The authenticated photo endpoint repeats report authorization and responds with
`no-store`, JPEG MIME and `nosniff`. The frontend fetches bytes with its existing
bearer/session protection, displays a temporary blob URL and revokes it on close.
There are no access tokens in image URLs or persistent browser photo storage.

## API and frontend

- `GET/POST /api/asset-problem-reports` lists scoped reports or submits one.
- `GET /api/asset-problem-reports/{id}` reads a permitted report.
- `GET /api/asset-problem-reports/{id}/photo` reads its private JPEG.
- `GET /api/asset-problem-assignees` lists eligible custodians for administrators.
- `POST /api/asset-problem-reports/{id}/{action}` supports `assign`, `reject`,
  `start`, `progress`, `resolve` with action-specific validated fields.

Lists use `scope=mine|assigned|all`, optional status, and bounded pagination.
The separate React app provides **Asset problems**, a reviewed submission form,
report details and permission-scoped work dialogs. Asset QR lookup includes
**Report a problem**, preselecting the pool or exact tagged item.

## Verification

```powershell
php tests/problem_photo_test.php
$env:CFABS_RUN_DB_TESTS = '1'
$env:CFABS_TEST_URL = 'http://127.0.0.1:3000'
php tests/problem_reports_http_test.php
php tests/maintenance_http_test.php
php tests/mail_components_test.php
php tests/mail_outbox_test.php
```

Passed **286 backend checks**: 114 reporting/access/lifecycle checks, 12 private
photo checks, 65 existing maintenance checks, 54 email component checks and 41
persistent-outbox checks with a fake provider. No external email was sent.
Checks cover submission retries, mass-assignment rejection, private report/photo
access, administrator-only review, eligible custodians, stale versions, partial
pool maintenance, reassignment, resolution retries, existing-endpoint bypass
prevention, cancelled/restarted work, baseline stock protection, and email access
rechecks. All HTTP/database fixtures are removed in `finally`.

Frontend validation passes **111 tests**, production build/TypeScript and lint.
Its new cases cover role/action controls, versioned updates, exact item/quantity
validation, QR reporting association and authenticated photo downloads.
Migration status is 13 applied / 0 pending.

Browser verification covered a reporter submitting and reviewing their own issue,
administrator-only assignment, reviewed maintenance start and resolution, and the
reporter seeing the final resolved status/notes. The linked maintenance record
was confirmed completed in the database. The mobile detail view fits without
horizontal overflow and exposes no assignment controls to the reporter. All
disposable UI accounts, report/notification/audit records, maintenance, catalog
fixtures and credential helpers were removed after verification.
