# Email notifications and password recovery

Resend is the selected provider. Delivery is **disabled until configured**. No
live provider sends were performed during implementation. A sender domain is not
yet verified; the supplied chat credential must be revoked and replaced privately.
Never put provider credentials in the frontend, repository, command arguments or chat.

## Configuration

Set these values in the backend's ignored `.env` (keep the existing strong `APP_KEY`):

```dotenv
MAIL_PROVIDER=resend
MAIL_ENABLED=0
MAIL_API_KEY=
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME=CFABS
MAIL_APP_URL=http://127.0.0.1:5173
```

Use a Resend sending key and a sender on your verified domain, then set
`MAIL_ENABLED=1` when ready to send. The frontend URL must be trusted HTTPS;
HTTP is allowed only for localhost development. A localhost link works only on
the development computer. Production domains/deployment remain deferred.

Without a verified domain, Resend restricts testing to the Resend account's own
email address. Follow its [testing-domain guidance](https://resend.com/docs/api-reference/errors)
and [sender setup](https://resend.com/docs/dashboard/domains/introduction).
Do not enable campus-wide delivery using the test sender. We have not configured
or contacted Resend with the exposed key.

The application uses the documented [Resend HTTPS API](https://resend.com/docs/api-reference/emails/send-email)
through a focused LavaLust application library, with no new PHP dependency. The
installed framework Email library uses PHP `mail()` and cannot implement this API
transport. TLS peer/hostname verification stay enabled; supported Windows builds
use the native CA store, as in Google verification.

## Schema and dispatch

Migration `010_create_email_outbox.php` adds encrypted email delivery tracking,
password-reset requests/tokens, a notification eligibility flag and an account
session version. Historical in-app notifications remain ineligible for email;
new ones are eligible after migration. No existing migration was rewritten.

Run commands from `Lavalust/`:

```powershell
php lava email:dispatch                         # preview; no writes or network
php lava email:dispatch --queue --limit=25        # queue only; no network
php lava email:dispatch --send --limit=25         # configured provider submission
php lava email:dispatch --send --recipient=123   # restrict delivery to one user
```

Queue/send require a valid sender, app URL and encryption key. Sending also
requires `MAIL_ENABLED=1` and a key. Preview reports pending reset requests,
notification candidates and due deliveries without printing addresses or bodies.
The pending-reset count is global; the recipient option limits prepared known
reset requests and notification deliveries. Request discovery is batch bounded.
`--queue` and `--send` are mutually exclusive. The underlying HTTP worker route
returns 404. No background scheduler is assumed or installed.

For automatic operation, explicitly configure a scheduler later to run the
existing `notifications:dispatch --send` reminder command followed by
`email:dispatch --send`, typically once per minute. Run previews first. This is
not a deployment configuration change performed by this phase.

Email discovery reads committed notifications after business transactions finish.
Provider failures cannot roll back bookings or handovers. A database worker lock
prevents overlapping dispatchers; unique source IDs prevent requeueing. Outgoing
payloads use the framework's authenticated AES-256-GCM encryption and existing
`APP_KEY`. Retain that key while jobs are pending. Sender, recipient and content
are frozen once queued; later configuration changes do not rewrite pending mail.

Resend acceptance is recorded as `accepted`, **not confirmed inbox delivery**.
Terminal deliveries purge their encrypted content. Errors record safe codes only,
never provider bodies, credentials or reset links. Transient failures use bounded
backoff; permanent errors stop. Retries/crash recovery reuse the same payload and
provider key, with at most five attempts and a 23-hour retry window inside
[Resend's 24-hour idempotency period](https://resend.com/docs/dashboard/emails/idempotency-keys).
Unattempted notification mail expires after 24 hours. Terminal/ambiguous jobs are
not automatically resent with new keys. No webhook/bounce-delivery claim is made.

## Events and authorization

Existing booking submission/approval/rejection/cancellation/completion,
release/return/inspection, inventory/maintenance impacts and scheduled reminders
reuse existing in-app notifications. Account creation, actual activation changes,
password changes and maintenance scheduling/completion/cancellation also produce
in-app notifications. Maintenance notices follow the existing manager-recipient
rules. Future maintenance-report assignment and direct rescheduling workflows
are not invented here; add their event mappings when those workflows exist.

Immediately before each attempt, the worker rechecks account status, current
email, ownership and required staff permissions. Inactive users receive no
business mail; a deactivation notice may reach its own affected account. Changed
email addresses suppress old queued content. Reminder eligibility and dispatch
keys are recalculated using the existing reminder service, so cancelled or changed
bookings cannot emit stale reminders. Templates include resource/reference,
campus times, event explanation, current status and an authorized system link;
they omit free-text private purposes, credentials and unrelated recipient data.

## Password recovery

`/forgot-password` and `/reset-password` are separate frontend pages. Public API:

- `GET /api/auth/password-reset/config`: enabled flag only.
- `POST /api/auth/password-reset/request`: JSON `{email}`.
- `POST /api/auth/password-reset/confirm`: JSON `{token,password,confirmation}`.

Requests are rate limited per IP and address. With sending configured, every
valid address follows the same encrypted asynchronous insert path and generic
response; the HTTP handler never queries account existence. The worker looks up
active accounts, ignores unknown/inactive addresses, clears processed request
addresses and ignores requests older than one hour. It generates a random 256-bit,
single-use reset credential with a 30-minute lifetime, storing only its hash in
the token table. The email payload containing the link is encrypted. New reset
requests supersede earlier credentials when processed.

The link puts the credential in a URL fragment, so it is not sent in initial HTTP
URLs. The frontend captures it in memory and clears the fragment. Confirmation
preserves password bytes and shares account-creation policy (12–72 bytes, no NUL),
requires confirmation and validates the current active account/email under locks.
A successful reset invalidates all reset links and refresh tokens and increments
the account session version, immediately invalidating old access tokens too.
Native LavaLust token issuance/signing/storage remain in use; version-zero legacy
tokens remain compatible until a reset. Google linking is preserved, and later
Google/password sign-ins receive the current version. There is no automatic login.
A password-change confirmation is queued without including the password.

This follows the relevant [OWASP recovery guidance](https://cheatsheetseries.owasp.org/cheatsheets/Forgot_Password_Cheat_Sheet.html).
When email is unconfigured, the request page clearly directs users to campus
administration; already-issued valid reset links can still be confirmed.

## Verification

```powershell
php tests/mail_components_test.php
$env:CFABS_RUN_DB_TESTS='1'
$env:CFABS_TEST_URL='http://127.0.0.1:3000'
php tests/mail_outbox_test.php
php tests/password_reset_http_test.php
```

Database outbox tests use real models/transactions and an explicitly injected
fake transport only. No HTTP/environment test mode bypasses provider verification.
Run related authentication/access/maintenance/reminder/Google regressions and the
frontend's `npm test`, `npm run build`, `npm run lint`. Tests clean exact fixtures;
email source cascades allow notification/reset fixture removal. Live provider
acceptance and inbox arrival remain to be verified after private configuration.

Local checks on 2026-11-07 passed: 54 mail component checks, 41 real-database outbox
checks with a fake provider, 53 reset HTTP/session checks, 68 authentication,
78 access-administration, 65 maintenance, 23 reminder and 54 Google controller
checks. Last-administrator removal/concurrency cases were conditionally skipped
because this development database already has other complete administrators.
Frontend tests (96), production build and lint passed. Desktop/mobile recovery
screens were reviewed without entering or changing a real account's credentials.
## Asset problem-report notifications

The asset problem workflow now records submitted, assigned, started, progress,
rejected, resolved and work-cancelled notices through the existing outbox.
Delivery rechecks current report ownership, the system administrator role with
maintenance permission, or current custodian assignment with that permission.
Old assignees cannot receive queued private-report email after reassignment.
Emails include only report reference, current status and recorded time; issue
text, staff notes, resolution text and photos stay inside the authorized app.
This uses the same disabled-by-default Resend configuration and fake-provider
tests; no live email was sent during implementation.
