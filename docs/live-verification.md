# Live deployment verification

Verified on 2026-11-07 against the deployed Vercel frontend and Render `cfabs-1`
backend, using the existing DEMO accounts. No booking, inventory, permission,
or account configuration was changed during these checks. Login/logout writes
the normal authentication records; test sessions were logged out.

## Passed

- Frontend root and `/asset-problems` deep link return HTTP 200.
- PHP health responds with `status: ok`; unauthenticated API access returns 401.
- CORS preflight returns 204 and permits the exact production frontend origin.
- The frontend's deployed shared API bundle targets `https://cfabs-1.onrender.com`.
- Requester, booking administrator, and asset custodian accounts can log in and
  read their expected roles. Staff have the respective booking approval grants;
  the requester does not.
- For November 9, 2026, 09:00–11:00 Asia/Manila, DEMO-ROOM availability is zero,
  DEMO-CHAIR availability is 30, and DEMO-LAPTOP availability is two.
- Requester notifications and the November 9–11 calendar feed return HTTP 200.
- Requester access to audit logs, account administration, and PDF inventory
  export is denied with HTTP 403.
- Booking administrator access to asset inventory reports and audit logs is
  denied. Custodians can read JSON inventory but cannot export PDF/Excel or
  access account administration.
- Google configuration is enabled and returns a correctly shaped Web client ID.
  This does not verify Google Console origin settings or interactive sign-in.

## Remaining acceptance checks

1. Exercise deployed requester submission and staff decisions through the UI.
2. Test partial asset releases/returns during an approved booking period. The
   current future-dated demo bookings must not be released early.
3. Verify real Google sign-in/linking and phone camera QR scanning.
4. Verify administrator PDF/Excel downloads and private problem-report photos
   through an authorized administrator session.
5. Configure a verified Resend sender, fresh private API key, scheduled reminder
   and outbox dispatch, then verify delivery before enabling real email.
6. Complete mobile acceptance, verify database backup/recovery arrangements,
   and deactivate demo accounts/resources before real use.

DEMO inventory is fictional test data. Existing non-DEMO inventory is preserved.
Credentials and tokens are intentionally excluded from this document.

## Subsequent dashboard and requester-only booking correction

Implemented locally after the live checks above; requires redeploying backend
and frontend. Migration 013 is already applied to the shared database, with
14 applied migrations and none pending. Existing staff creation grants were
removed. The updated backend also filters them from effective permissions,
even if they are accidentally reintroduced. Single and recurring booking
creation is denied to system administrators, booking administrators, and
asset custodians. Catalog creation and staff approval/handover grants remain.

Dashboards now use permission-scoped staff totals rather than each staff user's
personal bookings. Administrators get authorized catalog totals, facility staff
get facility activity, custodians get asset activity and overdue custody, and
requesters retain their own bookings. The default reporting window includes
the past 30 days and next 31 days. Staff schedule links are read-only; request
forms and My requests navigation are reserved for eligible requesters.

Validation: 104 backend reporting/access HTTP checks, 116 frontend tests,
production build, lint, and changed PHP syntax checks passed. Local browser
checks cover distinct facility/custodian/requester dashboards, absent staff
request forms and My requests links, preserved requester booking forms, and
responsive custody dashboard layout without horizontal overflow. Disposable
report-test users were removed; staff booking creation grants total zero.
