# Asset QR identification and handover

## Scope

The user restricted QR support to **assets only** on 2026-11-07. Quantity pools
use one label per asset record; individually tracked models use one label per
`asset_instances` record. A pooled label does not identify individual chairs.
Facilities have no QR labels or attendance endpoints. The older facility QR
examples in the integration attachment are superseded by this decision.

Migration `011_create_qr_labels.php` creates the registry with opaque random
192-bit identifiers, unique resource associations, foreign keys and an
exactly-one-source constraint. It backfills existing pools and instances.
New records created through `Catalog_service` receive identifiers in their
existing catalog transaction. Applied migrations must not be edited.

## API and permissions

- `GET /api/qr/{identifier}` identifies an active asset using `asset.view`.
- `GET /api/qr-labels/asset/{id}` returns the label for a quantity pool.
- `GET /api/qr-labels/asset-instance/{id}` returns a specific tagged item's label.
- `POST` to either label endpoint accepts only
  `{"action":"revoke|regenerate","expected_identifier":"<current identifier>"}`.
  Both `asset.view` and `asset.update` are required for these changes.

Identifiers contain 48 lowercase hexadecimal characters; the printable payload
is `cfabs:qr:<identifier>`. It contains no origin, credentials, private fields or
booking data and works independently of deployment URLs. Every lookup requires
current authentication and permission checks. Responses contain only basic
asset identity, status and condition; availability is checked using existing APIs.
Inactive assets cannot resolve. Managers may inspect inactive label records.

Revocation disables new lookups and handovers. Regeneration replaces the old
identifier permanently. A stale `expected_identifier` fails with HTTP 409 rather
than unexpectedly replacing a newer label. Repeated revocation is idempotent.
Label changes lock the parent asset before the instance/label, matching existing
catalog/custody ordering. Audit records store label fingerprints, not payloads.

## Existing handover rules remain authoritative

Existing release and return endpoints optionally accept `qr_identifiers`:

```json
{
  "quantity": 2,
  "condition_status": "good",
  "idempotency_key": "a-unique-batch-reference",
  "qr_identifiers": ["<48-character identifier>"]
}
```

For a quantity pool, provide exactly one matching label and enter the batch
quantity. For individual assets, also provide `instance_ids`; labels must match
that exact set. Duplicate, unrelated, unknown and revoked labels are rejected.
Label validation runs inside the existing asset/booking transaction. Existing
selected-item, balance, physical-stock, time-window, condition, authorization,
notification and ledger checks still apply. QR scanning never triggers a write.

The canonical sorted scan list is included in the existing idempotency hash.
Retries preserve the original payload/key; an already committed receipt can be
recovered even after a label is revoked. Existing manual handovers keep their
original request hashes and remain available for missing/unreadable labels or
loss reports. New scanned handovers add a fingerprint-only audit event.

## Frontend

The separate React app provides **Scan an asset** in the navigation and **QR
label** beside pooled catalog records and tagged items. Labels render locally
with `qrcode.react`; SVG downloads and print layout contain no remote QR service.
Authorized managers can revoke or regenerate with an explicit confirmation.

`@zxing/browser` is loaded on demand for camera or image decoding. Camera access
starts only after **Use camera**, uses no audio, and stops on scan, stop, disabled
state or unmount. Camera scanning requires HTTPS or localhost and permission.
PNG/JPEG/WebP images up to 10 MB are decoded locally; no image is uploaded to the
backend. Paste or hardware-scanner input remains available.

Release/return forms can scan labels to select eligible items. The existing
review/confirmation step remains mandatory. Changing selected items after scans
requires matching scans or clearing scans to use a manual batch. The server
revalidates labels and booking eligibility when the batch is confirmed.

QR lookup now links to the separate asset problem-reporting workflow, preselecting
the resource and exact tagged item when applicable. Only system administrators
review and assign reports; assigned custodians can perform work. See
[asset-problems.md](asset-problems.md) for the implemented reporting/assignment
workflow and private-photo rules. Facility reporting remains outside QR scope.

## Verification

```powershell
$env:CFABS_RUN_DB_TESTS = '1'
$env:CFABS_TEST_URL = 'http://127.0.0.1:3000'
php tests/qr_http_test.php
```

The new HTTP suite passed **107 checks**, covering automatic issuance, asset-only
scope, permission enforcement, minimal payloads, invalid/revoked labels,
regeneration conflicts, scans without writes, pool/item mismatches, partial
custody, approved-period enforcement, selected-item protection and safe retries.
All fixtures are disposable and removed in `finally`.

Frontend tests currently pass **101 tests**, including five QR cases for strict
payload parsing, exact item matching, duplicate rejection and pool semantics.
Production build/TypeScript and lint pass. The existing catalog suite passed
91 checks after automatic label issuance was added. The existing handover suite
passed 140 checks, including concurrent releases and same-key retries through
two independent PHP servers. Together, these three backend suites passed
**338 checks** (107 QR + 91 catalog + 140 custody).

Browser verification covered desktop invalid-input feedback, successful lookup,
mobile scanner/label layouts without horizontal overflow, SVG download and
decoding the generated label from a PNG image. A pooled QR-assisted partial
release was reviewed, explicitly confirmed and recorded once in the existing
history, with its receipt and released quantity visible. Its disposable account,
stock, booking, ledger entries and QR label were removed after verification.
Live camera capture still requires testing with a physical device and permission;
the camera was not enabled during this automated verification.
