# Administrator Excel and PDF exports

## Access and supported reports

The user limited Excel/PDF exports to **system administrators** on 2026-11-07.
`Reports` verifies the authenticated user's current database `role_name` is
`system_admin`, as well as the existing `report.facility.view` or
`report.asset.view` permission. JWT claims and frontend controls do not grant
access. Role demotion and account deactivation take effect on existing sessions.

Supported endpoints:

- `/api/reports/facilities?format=xlsx` or `format=pdf`
- `/api/reports/assets?format=xlsx` or `format=pdf`
- `/api/reports/inventory?format=xlsx` or `format=pdf`

Use the same validated filters as the existing JSON/CSV endpoints. Exports contain
all matching resource rows rather than the current page; pagination parameters
are rejected. Operational exports pin the reporting interval shown on screen,
including the rolling default. Inventory exports are current snapshots.

Existing report grants still control JSON and CSV. A booking administrator or
custodian with report-view access can continue using those formats, but receives
HTTP 403 for Excel/PDF. The frontend only offers the additional formats to the
system administrator role. No schema or role/grant migration was necessary.

These exports reuse existing aggregate reports: resource booking counts and
clipped reserved seconds, or baseline inventory counts. They do not add detailed
booking, maintenance, overdue, audit or user-activity reports. Those broader report
types in the original integration attachment remain future scope.

## Generation and safeguards

`Report_export` generates real `.xlsx` and `.pdf` bytes on the LavaLust backend.
Composer locks PhpSpreadsheet 5.10 and Dompdf 3.1 with their required libraries;
run `composer install` and `composer check-platform-reqs` after pulling changes.
No browser export library, external rendering service or new backend framework is
used. Current PHP 8.3 extensions satisfy the installed requirements.

Excel supports up to **10,000 resource rows** and PDF up to **500**. The query
fetches only the limit plus one, then rejects an oversized export with HTTP 422.
Users must narrow filters; results are never silently truncated.

The workbook includes report title, campus timestamps, applied filters, row count,
frozen headers, column filters and wrapped names. Identifiers and untrusted text
use explicit string cells, preserving leading zeros/large IDs and preventing
formula activation. Safe quantity/duration columns remain numeric.

The landscape PDF escapes all dynamic text, uses a bundled Unicode font, repeats
table headers and numbers pages. Remote access, PHP, JavaScript and URL protocols
are disabled in the renderer. No untrusted HTML, external fonts, links or images
are passed to it. The PDF notes distinguish baseline usable stock from dated
availability and reserved seconds from occupancy percentages.

Generated files are returned as private, non-cacheable attachments with MIME
validation in the authenticated frontend download helper. Access/refresh handling,
account-change protection and cancellation reuse the existing API client. Export
data is generated in memory without saving report files to public storage.

After successful generation and before sending bytes, `report.exported` is written
to existing audit logs with format, report, filter IDs, UTC period and resource
count. It records generation, not proof the browser saved the file. Report
contents, private booking purposes and credentials are not copied into the audit.

## Verification

```powershell
php tests/report_export_test.php
$env:CFABS_RUN_DB_TESTS = '1'
$env:CFABS_TEST_URL = 'http://127.0.0.1:3000'
php tests/report_export_http_test.php
php tests/report_http_test.php
```

Passed **129 backend checks**: 16 file-format/safety checks, 54 administrator
export HTTP checks, and 59 existing reporting/privacy/completion checks. Tests
open XLSX output, verify literal formula-like strings, exact IDs, numeric counts,
filters and empty results, and check PDF/XLSX limits. HTTP tests cover all three
reports in both formats, unauthorized roles, private headers, audit metadata,
demotion/deactivation, validation, and preserved CSV access. Fixtures are cleaned.

The PDF was rendered and inspected, including its final page. An 80-row fixture
rendered across seven pages with repeated headers, intact final row and page
numbers. Frontend tests pass **106 tests**, including document-specific MIME,
filter/period, role-control, session-change and refresh-rotation checks. Build,
TypeScript, lint and Composer platform checks pass.

Browser checks confirmed administrator Excel and PDF downloads for the applied
inventory department filter, success feedback and mobile layout without horizontal
overflow. A separate custodian session exposed CSV only, with no document-format
selector. Both disposable UI accounts and their inventory/audit fixtures were
removed after verification. Migration status remains 12 applied / 0 pending.
