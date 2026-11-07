# 15. External Integrations and Extended System Features

## 15.1 General Implementation Instructions

The system must support five major integrations:

1. Google Sign-In
2. Facility and Asset Calendar using FullCalendar
3. QR Code Identification and Scanning
4. Email Notifications
5. Excel and PDF Report Generation

These integrations must extend the existing Campus Facility and Asset Booking System without replacing or disrupting its core functionality.

### Mandatory Development Rules

Before implementing any integration:

- Inspect the existing project structure, database schema, authentication implementation, API routes, controllers, models, and installed dependencies.
- Follow the actual architecture and conventions of the installed LavaLust framework.
- Consult the official LavaLust documentation when framework-specific implementation details are needed: https://lavalust.netlify.app/
- Do not introduce Laravel, CodeIgniter 4, or other framework conventions into the LavaLust backend.
- Do not invent framework methods, classes, middleware, services, or configuration structures.
- Reuse existing authentication, authorization, database, and API response mechanisms.
- Do not duplicate existing functionality.
- Do not unnecessarily modify existing database tables or rename existing fields.
- Do not modify LavaLust framework core files.
- Do not remove existing features or change existing business rules without explicit authorization.
- Use environment variables for credentials, API keys, and other sensitive configuration.
- Validate all operations on the backend. Frontend validation is not a substitute for backend security.

Implement each integration independently and verify that existing system functionality continues working.

---

# 15.2 Integration 1: Google Sign-In

### Objective

Allow authorized faculty members and employees to authenticate using their existing Google accounts.

Use Google Identity Services for frontend authentication and validate Google ID tokens on the LavaLust backend.

### User Workflow

1. The user opens the system login page.
2. The user selects "Continue with Google."
3. Google authenticates the user.
4. The React frontend receives a Google ID token.
5. The frontend sends the ID token to the backend authentication endpoint.
6. The backend validates the ID token.
7. The backend verifies the Google account's email and authorization status.
8. The system identifies the corresponding local user account.
9. The backend issues the system's own authentication credentials using the existing authentication implementation.
10. The user is redirected to the appropriate dashboard based on their existing assigned role and permissions.

### Technical Requirements

Frontend:
- Integrate Google Identity Services.
- Add a Google Sign-In button to the existing login interface.
- Handle authentication success and failure.
- Send the ID token securely to the backend.
- Never store Google client secrets in frontend code.

Backend:
- Create an endpoint for Google authentication.
- Verify the ID token signature, issuer, audience, expiration, and other required claims.
- Verify that the email is marked as verified.
- Verify that the Google account satisfies the institution's configured email-domain policy.
- Find and validate the corresponding local user account.
- Issue the system's existing authentication token or session.
- Apply the existing role-based access control.

Suggested endpoint:

`POST /api/auth/google`

Example request:

```json
{
  "id_token": "GOOGLE_ID_TOKEN"
}
```

Example response:

```json
{
  "success": true,
  "message": "Authentication successful.",
  "data": {
    "access_token": "SYSTEM_ACCESS_TOKEN",
    "user": {
      "id": 1,
      "name": "Example Faculty",
      "email": "faculty@university.edu.ph",
      "role": "Faculty"
    }
  }
}
```

The response must follow the project's existing API response conventions. The example above is illustrative.

### Account Authorization Rules

- Google authentication must not automatically grant system access.
- A Google account must correspond to an authorized local account or pass the institution's configured registration and approval process.
- Do not automatically assign administrative roles.
- Never determine a user's system role using Google profile information.
- Existing system roles and permissions remain authoritative.
- Prevent unauthorized account linking.
- If an email already belongs to an existing local account, account linking must follow a secure verification process.
- Administrators must retain the ability to deactivate accounts.

### Configuration

Use environment variables for:

```env
GOOGLE_CLIENT_ID=
```

Do not expose private credentials or secrets in frontend source code.

### Acceptance Criteria

- Authorized Google accounts can sign in.
- Unauthorized Google accounts cannot access protected pages or APIs.
- Invalid or expired ID tokens are rejected.
- Unverified email addresses are rejected.
- Existing username/password authentication continues working if supported.
- Existing role and permission assignments remain unchanged.
- Authentication failures display understandable messages.

---

# 15.3 Integration 2: Facility and Asset Calendar

### Objective

Provide a unified calendar interface that displays facility reservations, asset bookings, and maintenance schedules.

Use FullCalendar for React.

The calendar must help faculty members, administrators, and custodians understand resource availability and prevent scheduling conflicts.

### Calendar Views

Implement:

- Monthly calendar view.
- Weekly calendar view.
- Daily calendar view.
- List or agenda view.
- Previous and next navigation.
- Today navigation.
- Date selection.
- Event detail display.

### Calendar Categories

Display the following event categories:

1. Facility Bookings
2. Asset Bookings
3. Facility Maintenance
4. Asset Maintenance

Use visually distinguishable event styles or labels.

The event category must also be identifiable through text, not color alone.

### Calendar Filters

Provide filters for:

- Facility.
- Asset.
- Facility category.
- Asset category.
- Department, subject to authorization.
- Booking status.
- Event category.
- Date range.

Users must only see information permitted by their assigned role and permissions.

### Calendar Data Sources

The calendar must retrieve information from the existing database records, including:

- `facility_bookings`
- `asset_bookings`
- `facility_maintenance`
- `asset_maintenance`

Use the actual table and field names found in the existing database. Do not create duplicate booking tables for the calendar.

### Suggested API

`GET /api/calendar/events?start=2026-11-01&end=2026-11-30`

Optional filters:

- `facility_id`
- `asset_id`
- `department_id`
- `status`
- `type`

Example normalized event:

```json
{
  "id": "facility-booking-101",
  "title": "Auditorium Reservation",
  "start": "2026-11-09T08:00:00",
  "end": "2026-11-09T12:00:00",
  "type": "facility_booking",
  "status": "approved",
  "resource_id": 1
}
```

The backend must normalize records from different database tables into a consistent calendar event format.

### Booking Availability Rules

The calendar must reflect the actual booking rules enforced by the backend.

- Approved bookings must appear as confirmed reservations.
- Pending bookings may appear as pending requests, but must not be represented as confirmed.
- Rejected and cancelled bookings must not block resource availability.
- Maintenance schedules must make affected resources unavailable according to their applicable maintenance status and date range.
- Overlapping reservations must be prevented by backend validation.
- Facility and asset availability must be calculated from actual database records.
- Do not rely on frontend calendar events as proof of availability.

Use the established booking overlap rule:

`existing_start < requested_end AND existing_end > requested_start`

Apply the existing booking status and resource availability rules when evaluating conflicts.

### Timezone

Use the institution's configured timezone consistently.

Default timezone:

`Asia/Manila`

Store and compare timestamps consistently, and convert them appropriately for calendar display.

### Acceptance Criteria

- Users can navigate monthly, weekly, and daily calendar views.
- Approved facility and asset bookings appear correctly.
- Maintenance schedules appear correctly.
- Filters return the correct events.
- Unauthorized users cannot retrieve restricted booking details.
- Cancelled or rejected reservations do not incorrectly block availability.
- The calendar reflects database changes without requiring manual database updates.
- Calendar display and backend availability calculations use consistent date and time rules.

---

# 15.4 Integration 3: QR Code Identification and Scanning

### Objective

Implement QR code identification for campus facilities and physical assets.

QR codes must support inventory identification, asset release and return, facility check-in and check-out, and maintenance reporting.

### QR Code Types

Implement two types:

**A. Asset QR Code**

Each physical asset must have a unique QR identifier.

Examples:

- AST-001
- AST-002
- AST-003

The identifier must be unique and must not expose sensitive information.

**B. Facility QR Code**

Each registered facility must have a unique QR identifier.

Examples:

- FAC-001
- FAC-002
- FAC-003

Use the actual system's facility and asset identification conventions if they already exist.

### QR Code Generation

- Generate a QR code when an asset or facility is registered.
- Provide a way to view and download the generated QR code.
- Allow administrators or authorized custodians to print QR labels.
- QR identifiers must remain associated with their corresponding database records.
- Prevent duplicate QR identifiers.
- Support QR code regeneration or revocation when necessary.
- Do not encode passwords, JWTs, private information, or sensitive booking data inside QR codes.

Prefer opaque, unpredictable identifiers or secure URLs that reference a backend resource.

### Asset QR Workflow

**Asset identification:**

1. The authorized user scans an asset QR code.
2. The frontend sends the QR identifier to the backend.
3. The backend verifies the identifier.
4. The system retrieves the asset record.
5. The user sees the permitted asset details and current availability.

**Asset release:**

1. The custodian scans the asset QR code.
2. The backend identifies the asset.
3. The backend retrieves active and approved bookings.
4. The system verifies the booking, borrowing user, release eligibility, and asset availability.
5. The custodian confirms the release.
6. The backend records the transaction and release timestamp.
7. The asset status and booking status are updated according to existing business rules.

**Asset return:**

1. The custodian scans the asset QR code.
2. The backend retrieves the active borrowing record.
3. The custodian records the returned condition.
4. The system records the return timestamp.
5. The backend updates the booking and asset status.
6. Any damage or missing-item report is recorded when applicable.

### Facility QR Workflow

1. A user scans the facility QR code at the facility entrance.
2. The system identifies the facility.
3. The backend checks the user's identity and approved booking.
4. The user checks in if the booking is eligible.
5. The system records the actual arrival timestamp.
6. When the user finishes using the facility, the system records check-out.
7. Actual facility usage can be compared with the originally scheduled reservation.

Only authorized users must be able to perform check-in and check-out operations.

### Maintenance Reporting

Allow authorized users to report a facility or asset problem through its QR page.

The report should support:

- Description of the issue.
- Date and time reported.
- Reporting user.
- Optional photo attachment.
- Current resource identification.
- Maintenance status.

Maintenance reports must follow the existing maintenance approval and assignment workflow.

### Suggested API Endpoints

Use the project's existing REST conventions.

- `GET /api/qr/{identifier}` - Resolve a QR identifier.
- `POST /api/assets/{id}/release` - Record asset release.
- `POST /api/assets/{id}/return` - Record asset return.
- `POST /api/facilities/{id}/check-in` - Record facility check-in.
- `POST /api/facilities/{id}/check-out` - Record facility check-out.
- `POST /api/maintenance/reports` - Submit a maintenance report.

### Security and Validation

- QR scanning must not automatically approve bookings.
- QR scanning must not automatically release equipment.
- Verify user identity and permissions on every operation.
- Verify booking ownership or authorized custodian access.
- Verify that the booking is in an eligible status.
- Prevent duplicate check-in, release, and return transactions.
- Validate whether the operation is allowed at the current time.
- Record all transactions in an auditable history.
- Use database transactions for operations that modify multiple related records.
- Prevent unauthorized modification of QR identifiers.

### Acceptance Criteria

- Every registered asset and facility can have a unique QR code.
- Scanning a valid code displays the correct resource.
- Invalid or revoked QR codes are rejected.
- Authorized custodians can record releases and returns.
- Eligible users can check in and check out of facilities.
- Duplicate transactions are prevented.
- Maintenance reports can be associated with the correct resource.
- All important actions are recorded in audit logs.

---

# 15.5 Integration 4: Email Notifications

### Objective

Implement automated email notifications for important system events.

Use an external email delivery provider such as Resend, or another provider approved by the institution.

Email delivery must complement the system's existing notification functionality.

### Notification Events

Implement email notifications for:

**Authentication and Accounts**
- Account registration or approval.
- Account activation or deactivation when appropriate.
- Password reset requests, if password authentication is supported.

**Facility Bookings**
- Booking submission confirmation.
- Booking approval.
- Booking rejection.
- Booking cancellation.
- Upcoming reservation reminder.
- Schedule changes.

**Asset Bookings**
- Asset booking submission.
- Booking approval or rejection.
- Asset release confirmation.
- Upcoming return reminder.
- Overdue asset reminder.
- Asset return confirmation.

**Maintenance**
- Maintenance report submission.
- Maintenance assignment.
- Maintenance status changes.
- Maintenance completion.

### Email Content

Each email must contain:

- Appropriate subject.
- Recipient name where available.
- Relevant facility or asset name.
- Booking or maintenance reference.
- Date and time where applicable.
- Current status.
- Clear explanation of the event.
- Appropriate system link when useful.

Do not include passwords, access tokens, or unnecessary sensitive information in emails.

### Suggested Email Templates

Examples:

- Your facility booking has been approved.
- Your asset booking has been rejected.
- Your reservation is scheduled for tomorrow.
- Your borrowed asset is due for return.
- A maintenance issue has been assigned to you.

Use consistent branding and professional email layouts.

### Backend Requirements

- Centralize email delivery logic according to existing LavaLust project conventions.
- Use environment variables for provider credentials.
- Validate recipient addresses.
- Handle provider failures gracefully.
- Prevent duplicate notification delivery.
- Record notification status where appropriate.
- Do not allow email delivery failures to incorrectly roll back an already successful booking operation.
- Use an existing supported queue or scheduled task mechanism if available.
- If asynchronous delivery is needed and no suitable mechanism exists, design a persistent notification/outbox mechanism and process it using an explicitly configured scheduled task.
- Never assume that an unsupported background job or queue system exists.

### Suggested Configuration

```env
MAIL_PROVIDER=
MAIL_API_KEY=
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME=
```

Never commit actual credentials to source control.

### Suggested API

If the application exposes notification history:

`GET /api/notifications`

Users must only be able to retrieve notifications they are authorized to see.

### Acceptance Criteria

- Important booking and maintenance events trigger the appropriate email.
- Emails contain accurate information.
- Provider failures are logged and handled.
- Duplicate emails are prevented where possible.
- Sensitive credentials are never exposed.
- Existing in-app notifications continue functioning.
- Email failures do not corrupt booking records.

---

# 15.6 Integration 5: Excel and PDF Report Generation

### Objective

Allow authorized users to generate and download administrative reports in Excel and PDF formats.

This integration must support facility management, asset inventory, booking administration, maintenance monitoring, and utilization analysis.

### Supported Export Formats

**Excel (.xlsx)**

Use a PHP-compatible spreadsheet library, such as PhpSpreadsheet, after verifying compatibility with the installed PHP version and project dependencies.

**PDF (.pdf)**

Use a PHP-compatible PDF generation library, such as Dompdf, after verifying compatibility with the installed PHP version and project dependencies.

Do not install incompatible or unnecessary packages.

### Required Reports

**A. Facility Reports**
- Facility inventory.
- Facility booking history.
- Facility utilization.
- Most frequently booked facilities.
- Facility maintenance history.
- Facility availability summary.

**B. Asset Reports**
- Asset inventory.
- Asset booking history.
- Asset borrowing and return history.
- Overdue assets.
- Asset condition reports.
- Asset maintenance history.
- Most frequently borrowed assets.

**C. Administrative Reports**
- Booking approval and rejection history.
- Department booking activity.
- User booking activity, subject to authorization.
- Monthly booking summary.
- Audit activity reports, restricted to authorized roles.

### Report Filters

Allow authorized users to filter reports using:

- Start date.
- End date.
- Facility.
- Asset.
- Department.
- Booking status.
- Maintenance status.
- Report category.

The backend must validate all filters and apply authorization restrictions before generating the report.

### Report Contents

Reports should contain:

- Institution or system name.
- Report title.
- Date range.
- Generation timestamp.
- Relevant filters.
- Table headers.
- Report data.
- Summary totals where applicable.
- Page numbers for PDF reports.
- Appropriate footer.

### Excel Requirements

- Use clearly labeled worksheets.
- Use readable column widths and headers.
- Apply appropriate date and number formats.
- Include useful summary sections when applicable.
- Ensure exported values are properly escaped and safe.
- Prevent spreadsheet formula injection when exporting user-controlled data.
- Preserve the correct values from the database.

### PDF Requirements

- Use a clean, professional, printable layout.
- Support multiple pages.
- Repeat table headers where appropriate.
- Avoid cutting off important columns.
- Include generation timestamps and page numbers.
- Handle long reports correctly.

### Suggested API Endpoints

Examples:

- `GET /api/reports/facilities`
- `GET /api/reports/assets`
- `GET /api/reports/bookings`
- `GET /api/reports/maintenance`
- `GET /api/reports/utilization`

Support format selection through an appropriate query parameter or separate export endpoints.

Example:

`GET /api/reports/bookings?format=xlsx&start_date=2026-11-01&end_date=2026-11-30`

### Authorization Requirements

- Faculty members may only export information permitted by their assigned permissions.
- Booking administrators may export booking information within their authorized scope.
- Asset custodians may export asset and maintenance information within their authorized scope.
- System administrators may access reports according to their assigned permissions.
- Sensitive administrative and audit information must remain restricted.

### Acceptance Criteria

- Authorized users can export supported reports.
- Excel files open correctly.
- PDF files render correctly.
- Date and status filters work.
- Exported data matches database records.
- Unauthorized users cannot export restricted information.
- Large reports are handled without exposing server resources unnecessarily.
- Export operations are logged when required by the audit policy.

---

# 15.7 Database and Schema Change Requirements

Before creating or modifying database structures, inspect the existing schema.

Only introduce additional columns or tables when required by the implementation.

Potential additional fields include:

**Users**
- Google account identifier, if needed for secure account linking.

**Facilities**
- QR identifier.

**Assets**
- QR identifier.

**Facility Bookings**
- Actual check-in timestamp.
- Actual check-out timestamp.

**Asset Bookings**
- Actual release timestamp.
- Actual return timestamp.
- Return condition, if not already supported.

**Notifications**
- Delivery channel.
- Delivery status.
- Provider reference.
- Sent timestamp.
- Failure information, where appropriate.

**Audit Logs**
- QR-related operations.
- Export generation.
- Authentication-related events where appropriate.

Do not blindly add all suggested fields. Reuse existing columns when equivalent functionality already exists.

Any new schema must include appropriate:

- Primary keys.
- Foreign keys.
- Unique constraints.
- Indexes.
- Nullability rules.
- Deletion policies.
- Audit requirements.

Preserve historical booking, maintenance, and transaction records.

---

# 15.8 Integration Security Requirements

All integrations must follow the system's existing authentication and role-based authorization model.

Mandatory security requirements:

1. Never trust frontend-provided roles or permissions.
2. Validate all incoming requests on the backend.
3. Protect API credentials using environment variables.
4. Verify third-party authentication tokens properly.
5. Prevent unauthorized access to booking and asset records.
6. Prevent QR code manipulation and unauthorized transactions.
7. Prevent duplicate booking, release, return, and check-in operations.
8. Validate uploaded files and attachments.
9. Restrict exported data according to user permissions.
10. Record important administrative and operational actions in audit logs.
11. Use database transactions for multi-record operations.
12. Avoid exposing sensitive user information in calendar events, QR pages, and emails.

All external integrations must fail safely. A third-party service outage must not corrupt existing booking, asset, or facility records.

---

# 15.9 Implementation Order

Implement integrations in the following sequence:

| Phase | Integration | Expected Result |
|---|---|---|
| 1 | Google Sign-In | Secure Google authentication |
| 2 | FullCalendar | Unified facility and asset calendar |
| 3 | Email Notifications | Automated communication |
| 4 | QR Code System | Asset tracking and facility check-in |
| 5 | Excel/PDF Exports | Administrative reporting |

Each integration must be completed and tested before proceeding to the next phase.

### Required Testing

For every integration, test:

- Successful operation.
- Invalid input.
- Unauthorized access.
- Missing or incomplete records.
- Duplicate requests.
- Third-party service failure.
- Database failure.
- Unexpected API responses.
- Compatibility with existing system functionality.

---

# 15.10 Mandatory AI Coding Agent Instructions

When implementing these integrations, the coding agent must:

1. Read the existing `specs.md` before making changes.
2. Inspect the actual repository before proposing an implementation.
3. Follow the existing LavaLust framework architecture and conventions.
4. Review the existing database before creating migrations or changing schemas.
5. Reuse existing authentication and authorization mechanisms.
6. Implement one integration at a time.
7. Explain the intended changes before making major architectural modifications.
8. Avoid rewriting working features unnecessarily.
9. Never delete, overwrite, or replace existing project files without explicit authorization.
10. Never perform destructive database operations without explicit authorization and a verified backup.
11. Preserve existing functionality and data.
12. Run appropriate tests after every implementation phase.
13. Document newly introduced dependencies, environment variables, API endpoints, database changes, and setup instructions.
14. Report incomplete functionality honestly rather than claiming that an integration is complete.

### Definition of Done

An integration is considered complete only when:

- Frontend implementation is functional.
- Backend implementation is functional.
- Database changes are verified.
- Authentication and authorization are enforced.
- Error handling is implemented.
- Relevant tests pass.
- Existing features continue working.
- Setup and configuration instructions are documented.
- No credentials or secrets are committed to the repository.