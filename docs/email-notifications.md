Brevo is the selected provider. Delivery stays disabled until an API key is configured and tested. The verified sender in the current Brevo account is CFABS <ronnellogdat@gmail.com>; Brevo warns that a freemail domain is not recommended for deliverability. Use an authenticated campus domain before broad production sending. Keep credentials in the backend's ignored `.env`, never in the frontend or repository.

## Configuration

```dotenv
MAIL_PROVIDER=brevo
MAIL_ENABLED=0
MAIL_API_KEY=
MAIL_FROM_ADDRESS=ronnellogdat@gmail.com
MAIL_FROM_NAME=CFABS
MAIL_APP_URL=http://127.0.0.1:5173
```

The backend submits to Brevo's HTTPS transactional email API using its `api-key` header. Create an API key in Brevo under SMTP & API → API keys & MCP, place it privately in `.env`, and set `MAIL_ENABLED=1` only when ready to send. The local frontend URL works only on the development computer; production requires a trusted HTTPS frontend URL. TLS peer and hostname checks remain enabled.

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

Production uses a cron-job.org job named `CFABS production email dispatch`
every five minutes. It sends a POST request to
`https://cfabs-1.onrender.com/api/internal/scheduled-mail` with the
`X-CFABS-Dispatch-Token` header. Its token must match the Render service's
`MAIL_DISPATCH_TOKEN` environment variable. The route prepares reminders and
sends due email; requests without the token return 404. The job retains response
history for troubleshooting. `.github/workflows/dispatch-mail.yml` is available
for manual fallback from the GitHub Actions tab.

Email discovery reads committed notifications after business transactions finish.
Provider failures cannot roll back bookings or handovers. A database worker lock
prevents overlapping dispatchers; unique source IDs prevent requeueing. Outgoing
payloads use the framework's authenticated AES-256-GCM encryption and existing
`APP_KEY`. Retain that key while jobs are pending. Sender, recipient and content
are frozen once queued; later configuration changes do not rewrite pending mail.

Brevo acceptance is recorded as `accepted`, **not confirmed inbox delivery**.
Terminal deliveries purge their encrypted content. Errors record safe codes only,
never provider bodies, credentials or reset links. Transient failures use bounded
backoff; permanent errors stop. Retries/crash recovery reuse the same payload and
provider key, with at most five attempts and a 25-minute retry window inside
[Brevo's 30-minute idempotency period](https://developers.brevo.com/docs/heterogenous-versions-batch-emails).
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
This uses the same disabled-by-default Brevo configuration and fake-provider
tests; no live email was sent during implementation.
