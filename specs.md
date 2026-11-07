# Campus Facility and Asset Booking System

## AI-Agent-Ready Software Requirements & Technical Specifications

**File:** `specs.md`\
**Project:** Campus Facility and Asset Booking System (CFABS)\
**Frontend:** React + Vite\
**Backend:** LavaLust PHP Framework\
**Database:** MySQL / MariaDB\
**API:** RESTful JSON API\
**Status:** Baseline specification --- verify project-specific details
before implementation

> **Critical framework rule:** LavaLust has its own architecture and
> conventions. Follow the installed LavaLust version and the existing
> repository. Do not impose Laravel, CodeIgniter 4, Symfony, or generic
> MVC/HMVC patterns that are not supported by this project.

------------------------------------------------------------------------

## Confirmed Requirements

The following decisions supersede conflicting baseline options and open questions elsewhere in this document.

### Database Migrations

Create and modify all application tables through LavaLust's existing migration feature in `app/migrations/`. Follow existing numbered filenames, migration classes, and `up()` / `down()` methods. Reuse DBForge methods where supported and define indexes, constraints, and foreign keys through migrations. Do not substitute manual phpMyAdmin changes or standalone SQL imports for migrations. Preserve applied migrations and add subsequent schema changes in new migrations. Reconcile the proposed users and refresh-token schemas with the installed authentication implementation. Verify migrations on a clean development database and document rollback limitations.

### Asset Tracking

Support both quantity-tracked pools and individually tracked assets. Each asset record uses one tracking mode; never count the same physical stock in both modes. Add `asset_instances` and a booking-to-instance allocation table for individual tracking through LavaLust migrations. Requesters select specific available instances. Store their item selections with the pending request and recheck those exact instances during approval.

### Availability and Pending Requests

Requesters must see facility availability, available individual items, and remaining asset quantities for their selected date and time interval. Allow overlapping pending requests; pending requests do not reserve resources. Approved requests reserve facilities, individual items, or quantities during the approved interval. Account for maintenance, damaged/lost items, and released items that remain unavailable without counting the same unavailable stock twice. For quantity pools, calculate capacity throughout the interval using peak simultaneous reserved quantities rather than summing all bookings that merely overlap it.

Refresh displayed availability when selected dates change and automatically after relevant approvals, cancellations, returns, maintenance, or inventory changes, without a page reload. The update mechanism and acceptable refresh delay remain implementation decisions. Availability displayed in the frontend is advisory; revalidate it on the backend during approval within a transaction using supported locking.

### Recurring Requests

Confirmed: recurring requests are allowed for facilities and assets. Every occurrence follows the existing availability, advance-window, facility-hours, and independent approval rules. Recurrence does not automatically approve or reserve pending dates. Initial daily/weekly series are finite; implementation limits and atomic-submission behavior are documented in `docs/development.md`.

### Facility Turnover Buffer

Confirmed: no cleanup gap is required between facility bookings. Use half-open intervals: a booking ending at 10:00 permits another booking starting at 10:00. Expose a zero-minute turnover buffer in facility availability metadata.

### Advance Booking Window

Confirmed: facilities and assets may start up to 30 campus calendar days ahead, inclusive, using Asia/Manila dates. Enforce the starting-date limit at submission and approval and expose it in availability responses. Asset bookings have no fixed maximum duration: require an explicit return date/time after the start, which may fall beyond the 30-day start-date window. Check availability across the entire requested interval. Existing approved/historical records are retained.

### Facility Operating Hours

Confirmed: facilities are bookable every calendar day from 08:00 to 17:00 in Asia/Manila. Requests must remain within one campus day and these hours; exactly 08:00–17:00 is allowed. Apply the same check at submission and approval and expose the hours in availability responses. This decision applies to facilities, not multi-day asset borrowing, which has no fixed maximum duration. Special-event exceptions remain undecided; recurring requests are allowed with independent occurrence review, and the confirmed advance limit and zero buffer are defined above.

### Cancellation Rules

Both requesters and staff with the corresponding `cancel_any` permission may cancel approved bookings before their scheduled start, provided assets have not been released. Cancellation requires a reason, an audit record, and an in-app notification. Release reservations atomically with the cancellation and show the resulting availability on subsequent reads. Keep previously rejected requests rejected; requesters may submit a new request. Released assets require the return process rather than cancellation.

### Partial Asset Handovers

Support multiple partial releases and returns per asset booking. Custodians may release assets only during the approved booking period. Record each batch in a LavaLust-migrated handover ledger, with exact item IDs for individually tracked assets. Prevent over-release, over-return, duplicate submissions, and repeat release of the same item under one booking. Track lost quantities separately from physically returned quantities. Damaged/lost stock must reduce availability; inspection returns remain unavailable until inspected. Preserve existing booking fields as summary projections rather than using one release/return record as the entire history.

### Automatic Rejection and Notifications

When approving a request, reserve the resource and recheck overlapping pending requests against the resulting availability. Automatically reject each pending request that can no longer be fulfilled as submitted. Leave individually feasible pending requests pending, even when they compete with each other, because they reserve no capacity. Record an explicit conflict reason and audit every approval and automatic rejection. Make approval, reservation, and resulting rejection changes atomic under appropriate locking; notify users only after a successful commit.

Notify the approved requester and each automatically rejected requester through recipient-specific in-app notifications. Rejection notifications must explain the conflict. Refresh availability and remaining quantities automatically. Rejected requesters may submit a new request using different dates, resources, or quantities. An automatic rejection must not be attributed to a human rejection action.

------------------------------------------------------------------------
# 1. Purpose and Product Overview

CFABS is a web-based campus resource management system for requesting,
approving, scheduling, releasing, returning, and monitoring
institutional facilities and assets.

The system replaces manual or fragmented reservation processes with a
centralized workflow. It must help prevent scheduling conflicts,
preserve accountability, improve resource utilization, and provide
reliable operational records.

## 1.1 Goals

1.  Allow authorized faculty and employees to request facilities and
    assets.
2.  Show availability before a request is submitted.
3.  Prevent conflicting approved facility reservations.
4.  Prevent asset over-allocation and conflicting reservations.
5.  Support review, approval, rejection, cancellation, release, and
    return.
6.  Maintain facility, asset, category, department, and user records.
7.  Track maintenance and temporary unavailability.
8.  Provide dashboards, notifications, reports, and audit trails.
9.  Enforce permissions on the backend, not merely in React.
10. Preserve historical booking records for accountability.

## 1.2 Out of Scope Unless Explicitly Approved

-   Online payments
-   Biometric attendance
-   RFID hardware integration
-   SMS gateway
-   Native mobile app
-   AI forecasting
-   External university-system integrations
-   Public anonymous booking
-   Automatic approval without configured policy

------------------------------------------------------------------------

# 2. Technology and System Boundaries

## 2.1 Frontend

-   React
-   Vite
-   JavaScript (ES modules)
-   React Router
-   Tailwind CSS
-   Fetch API or the existing HTTP client
-   Context API or another justified state-management solution

The frontend is responsible for presentation, user interaction,
client-side usability validation, navigation, and displaying API state.

## 2.2 Backend

-   PHP using the installed LavaLust version
-   LavaLust-native controllers, models, routing, configuration,
    database layer, libraries, helpers, and response mechanisms
-   REST API returning JSON
-   JWT authentication only through the existing implementation or a
    verified compatible library
-   Server-side validation and authorization

## 2.3 Database

-   MySQL or MariaDB
-   InnoDB tables
-   Foreign keys
-   Unique constraints
-   Transactions and appropriate locking for booking decisions
-   Explicit indexes for common lookup and reporting paths

## 2.4 Trust Boundary

The React application is **not trusted** to enforce authorization,
booking rules, or inventory correctness.

Every protected API request must be authenticated and authorized by
LavaLust. Every booking operation must be validated against current
database state.

------------------------------------------------------------------------

# 3. User Roles

The baseline roles are:

1.  **Faculty / Employee** --- requests resources and manages own
    bookings.
2.  **Booking Administrator / Facility Coordinator** --- reviews and
    manages facility bookings.
3.  **Asset Custodian** --- manages assets, asset bookings, releases,
    returns, and asset maintenance.
4.  **System Administrator** --- manages system configuration, users,
    roles, permissions, and master data.

Roles may be assigned additional permissions only through an authorized
administrative process. Do not hardcode assumptions that every
administrator is an approver for every resource type.

## 3.1 Scope Rules

-   A user may view and manage their own bookings.
-   Administrative access may be global or limited to assigned
    department/resource scope.
-   The baseline design assumes global administrative scope; if the
    institution requires departmental scope, implement an explicit scope
    model rather than relying on UI filtering.
-   A user cannot approve their own request.
-   A System Administrator does not automatically become a business
    approver unless the relevant permission is granted.

------------------------------------------------------------------------

# 4. Exact Permission Matrix

Legend:

-   **Y** --- allowed
-   **Own** --- allowed only for records owned by the authenticated user
-   **Assigned** --- allowed only within explicitly assigned scope
-   **---** --- not allowed by default

  -----------------------------------------------------------------------------------------------
  Capability                 Faculty /         Booking Admin       Asset Custodian   System Admin
                              Employee                                             
  --------------------- -------------- --------------------- --------------------- --------------
  View own profile                   Y                     Y                     Y              Y

  Update own profile                 Y                     Y                     Y              Y
  (permitted fields)                                                               

  View active                        Y                     Y                     Y              Y
  facilities                                                                       

  View facility                      Y                     Y                     Y              Y
  availability                                                                     

  Create facility                    Y                     Y                     Y              Y
  booking                                                                          

  View own facility                Own                   Own                   Own            Own
  bookings                                                                         

  View all facility                ---                     Y                   ---    By explicit
  bookings                                                                             permission

  Approve facility                 ---                     Y                   ---    By explicit
  booking                                                                              permission

  Reject facility                  ---                     Y                   ---    By explicit
  booking                                                                              permission

  Cancel own eligible              Own                   Own                   Own            Own
  booking                                                                          

  Cancel any facility              ---                     Y                   ---    By explicit
  booking                                                                              permission

  Create/edit facility             ---                     Y                   ---              Y

  Deactivate facility              ---                     Y                   ---              Y

  Schedule facility                ---                     Y                   ---              Y
  maintenance/block                                                                

  View active assets                 Y                     Y                     Y              Y

  View asset                         Y                     Y                     Y              Y
  availability                                                                     

  Create asset booking               Y                     Y                     Y              Y

  View own asset                   Own                   Own                   Own            Own
  bookings                                                                         

  View all asset                   ---                   ---                     Y    By explicit
  bookings                                                                             permission

  Approve asset booking            ---                   ---                     Y    By explicit
                                                                                       permission

  Reject asset booking             ---                   ---                     Y    By explicit
                                                                                       permission

  Cancel own eligible              Own                   Own                   Own            Own
  asset booking                                                                    

  Cancel any asset                 ---                   ---                     Y    By explicit
  booking                                                                              permission

  Register/edit assets             ---                   ---                     Y              Y

  Deactivate/retire                ---                   ---                     Y              Y
  assets                                                                           

  Confirm asset release            ---                   ---                     Y    By explicit
                                                                                       permission

  Confirm asset return             ---                   ---                     Y    By explicit
                                                                                       permission

  Record asset                     ---                   ---                     Y    By explicit
  condition/damage                                                                     permission

  Schedule asset                   ---                   ---                     Y              Y
  maintenance                                                                      

  View operational                 ---                     Y       Y (asset scope)              Y
  reports                                                                          

  Manage users                     ---                   ---                   ---              Y

  Activate/deactivate              ---                   ---                   ---              Y
  accounts                                                                         

  Assign roles                     ---                   ---                   ---              Y

  Manage permissions               ---                   ---                   ---              Y

  Manage departments               ---                   ---                   ---              Y

  Manage categories                ---    Y (facility scope)       Y (asset scope)              Y

  Configure booking                ---    Y (facility scope)       Y (asset scope)              Y
  policies                                                                         

  View audit logs                  ---   Assigned/authorized   Assigned/authorized              Y

  Delete audit logs                ---                   ---                   ---            ---
  -----------------------------------------------------------------------------------------------

**Implementation note:** "By explicit permission" means the backend must
check a named permission. It must not infer authorization merely because
a user has a role named `System Administrator`.

------------------------------------------------------------------------

# 5. Functional Requirements

## 5.1 Authentication and Accounts

The system must support:

-   Login and logout
-   Access-token validation
-   Refresh-token lifecycle
-   Authenticated-user endpoint
-   Password change and reset (if included in the approved scope)
-   Account activation and deactivation
-   Role assignment
-   Protected routes and API endpoints

Rules:

-   Only active accounts may authenticate.
-   Passwords must be securely hashed using PHP's supported password
    hashing facilities.
-   JWT secrets must remain on the backend.
-   The backend must validate token signature, expiration, and relevant
    claims.
-   Refresh tokens must be revocable and stored securely (prefer hashed
    token storage).
-   A disabled account must not continue to use refresh-token
    functionality.
-   Never return password hashes, refresh tokens, or secret
    configuration.

## 5.2 Departments

Department management includes:

-   Department name and code
-   Description
-   Active/inactive status
-   Users assigned to department
-   Department-based booking/report filters

Department codes must be unique. Deactivation must not erase historical
records.

## 5.3 Facility Management

Authorized users can:

-   Create and edit facilities
-   Assign categories and locations
-   Define capacity and description
-   Define opening hours and booking constraints
-   Add facility images (optional)
-   Activate/deactivate a facility
-   Schedule maintenance or blackout periods

Facility deactivation must not delete historical bookings.

## 5.4 Asset Management

Authorized users can:

-   Register assets
-   Assign unique asset codes
-   Categorize assets
-   Record serial numbers where applicable
-   Define quantity and tracking method
-   Record condition and location
-   Assign custodians
-   Mark assets unavailable, under maintenance, or retired
-   Review asset history

Two inventory tracking modes are supported conceptually:

-   **Quantity-tracked:** identical or interchangeable units managed by
    count.
-   **Individually tracked:** each physical item has its own
    asset-instance identifier and lifecycle.

Do not implement both modes partially. Confirm which mode is required
for each asset category and design the schema accordingly.

## 5.5 Facility Booking

A requester must be able to:

1.  Browse facilities.
2.  Open facility details.
3.  Check availability for a date.
4.  Select start and end times.
5.  Enter activity/purpose and participant count.
6.  Submit a request.
7.  View status and booking details.
8.  Cancel an eligible request.
9.  View booking history.

Required validation:

-   Start time precedes end time.
-   Booking is not in the past.
-   Facility is active and bookable.
-   Requested participant count does not exceed capacity.
-   Time is within configured operating hours.
-   No conflicting active reservation or blocking maintenance exists.
-   Required fields are present and valid.

## 5.6 Asset Booking

A requester must be able to:

1.  Browse assets.
2.  View availability and asset details.
3.  Specify quantity or select specific asset instances.
4.  Specify usage start and expected return.
5.  Enter purpose.
6.  Submit a request.
7.  Track approval.
8.  View release and return records.

Required validation:

-   Requested quantity is positive.
-   Requested quantity is available for the entire requested interval.
-   Asset is active and bookable.
-   Usage start precedes expected return.
-   The request does not conflict with maintenance or unavailable
    periods.
-   Individual asset instances cannot be double-booked.

## 5.7 Approval Workflow

Facility and asset approvals are separate permissions and workflows.

### Facility booking lifecycle

`PENDING → APPROVED → COMPLETED`

Alternative transitions:

-   `PENDING → REJECTED`
-   `PENDING → CANCELLED`
-   `APPROVED → CANCELLED` (subject to policy and cancellation reason)
-   `APPROVED → COMPLETED` (system or authorized completion process)

### Asset booking lifecycle

`PENDING → APPROVED → READY_FOR_RELEASE → RELEASED → RETURNED → COMPLETED`

Alternative transitions:

-   `PENDING → REJECTED`
-   `PENDING → CANCELLED`
-   `APPROVED → CANCELLED`
-   `READY_FOR_RELEASE → CANCELLED` (if not released and policy permits)
-   `RELEASED → OVERDUE` (system-calculated or operational status)
-   `RELEASED → RETURNED`
-   `RETURNED → COMPLETED` (after inspection/confirmation)

Do not treat `OVERDUE` as a replacement for the physical transaction
status if an asset is still released. Prefer a derived overdue flag or a
clearly defined state model.

### Approval rules

-   Only a user with the correct permission can approve or reject.
-   The requester cannot approve their own request.
-   Rejection requires a reason.
-   Approval records approver ID and timestamp.
-   Availability must be rechecked during approval.
-   Approval must be atomic with reservation/inventory updates.
-   Status transitions must be validated on the backend.
-   Invalid transitions must return a client error without modifying
    data.
-   All meaningful transitions must be auditable.

## 5.8 Asset Release and Return

Only authorized custodians may confirm release and return.

Release must capture:

-   Released by
-   Release timestamp
-   Quantity or specific item instances released
-   Condition at release
-   Remarks (optional)

Return must capture:

-   Received by
-   Return timestamp
-   Quantity returned
-   Condition at return
-   Damage/loss notes
-   Remarks

Rules:

-   A booking cannot be marked released before approval.
-   The actual released quantity must not exceed approved quantity.
-   Return quantity and outstanding quantity must be tracked.
-   Damaged/lost assets must be reflected in inventory availability.
-   A returned asset must not become available if it requires inspection
    or maintenance.
-   Overdue records must remain visible until resolved.

## 5.9 Maintenance and Blackout Scheduling

Facility maintenance blocks a facility during a defined date/time range.

Asset maintenance reduces the available quantity or marks individual
instances unavailable.

Rules:

-   Maintenance periods must be validated for start/end.
-   Conflicting approved bookings must be flagged before maintenance is
    confirmed.
-   Maintenance cannot silently invalidate existing bookings.
-   Authorized personnel must resolve conflicts (reschedule, cancel, or
    document exception).
-   Completed maintenance must record completion and responsible user.
-   Historical maintenance records must be retained.

## 5.10 Notifications

In-app notifications should cover:

-   Request submitted
-   Approved or rejected
-   Cancelled
-   Schedule modified
-   Upcoming facility booking
-   Asset release readiness
-   Upcoming return
-   Overdue asset
-   Maintenance/unavailability affecting a booking

Notifications must be recipient-specific. Users must not access another
user's private notifications.

## 5.11 Dashboards

### Faculty / Employee

-   Pending requests
-   Approved upcoming bookings
-   Recent history
-   Notifications
-   Quick booking actions

### Booking Administrator

-   Pending facility requests
-   Today's and upcoming facility schedule
-   Maintenance blocks
-   Facility utilization
-   Recent approval/rejection activity

### Asset Custodian

-   Pending asset requests
-   Assets currently released
-   Upcoming releases and returns
-   Overdue assets
-   Available and maintenance inventory
-   Asset utilization

### System Administrator

-   User and resource totals
-   Booking totals by status
-   Operational overview
-   System administration activity
-   Audit access, subject to policy

Dashboard figures must come from the API/database, not hardcoded
client-side values.

## 5.12 Reports

Facility reports:

-   Booking history by facility
-   Utilization by date range
-   Requests by department
-   Status counts
-   Most frequently booked facilities

Asset reports:

-   Inventory by category/status/location
-   Borrowing history
-   Utilization
-   Overdue assets
-   Damaged/lost assets
-   Maintenance history
-   Frequently borrowed assets

Administrative reports:

-   Requests by user/department
-   Approval/rejection trends
-   Cancellation history
-   Audit events

Filters should include applicable date ranges, department, category,
resource, and status. CSV export may be implemented after core
reporting.

------------------------------------------------------------------------

# 6. Booking and Availability Rules

## 6.1 Facility Overlap Rule

Two time intervals overlap when:

`existing_start < requested_end AND existing_end > requested_start`

This allows a booking to end exactly when another begins, unless a
configurable buffer is required.

The overlap check must include all statuses that reserve the facility
(normally `APPROVED`; optionally `PENDING` only if the campus adopts
temporary holds). Cancelled, rejected, and completed historical bookings
must not block new bookings.

The institution must decide whether to include:

-   Setup/cleanup buffer
-   Pending-request hold
-   Maximum advance booking window
-   Minimum/maximum duration
-   Cancellation deadline
-   Recurring booking support

Do not invent these policies; make them configurable or ask for
confirmation.

## 6.2 Asset Quantity Rule

For quantity-tracked assets, calculate reservations that overlap the
requested interval and subtract those quantities from usable inventory.

`available_quantity = total_usable_quantity - overlapping_reserved_quantity`

The calculation must account for:

-   Approved bookings
-   Released but not returned assets
-   Maintenance quantities
-   Damaged/lost quantities
-   Other inventory adjustments

Do not count rejected or cancelled bookings as active reservations.

For individually tracked assets, availability is calculated per item
instance.

## 6.3 Concurrency and Race Conditions

Booking submission and approval must protect against two requests being
accepted for the same remaining resource.

Implementation must:

1.  Begin a database transaction.
2.  Lock or otherwise serialize the relevant resource/inventory rows
    using supported MySQL/InnoDB mechanisms.
3.  Recalculate availability inside the transaction.
4.  Validate conflicts and quantities.
5.  Insert/update the booking and reservation data.
6.  Commit only when all operations succeed.
7.  Roll back on any failure.

Do not rely on a preliminary availability API response as proof that the
resource remains available.

------------------------------------------------------------------------

# 7. Database Design

## 7.1 General Conventions

-   Use InnoDB.
-   Use primary keys on every table.
-   Use foreign keys for relational integrity.
-   Use `DATETIME` or `TIMESTAMP` consistently; document timezone
    handling.
-   Store timestamps in UTC where practical; display in campus local
    time (`Asia/Manila`).
-   Use `DECIMAL` only for numeric values requiring exact decimal
    precision; do not use floating point for quantities.
-   Use appropriate indexes for filtering, joining, and sorting.
-   Avoid deleting referenced operational records.
-   Use `created_at` and `updated_at` consistently.
-   Store status values consistently (enum or lookup strategy; choose
    one and apply consistently).
-   Never store derived availability as an uncontrolled mutable value.
    If cached, define reconciliation rules.

## 7.2 ERD (Mermaid)

``` mermaid
erDiagram
    DEPARTMENTS ||--o{ USERS : contains
    ROLES ||--o{ USERS : assigned
    ROLES ||--o{ ROLE_PERMISSIONS : grants
    PERMISSIONS ||--o{ ROLE_PERMISSIONS : included

    FACILITY_CATEGORIES ||--o{ FACILITIES : categorizes
    FACILITIES ||--o{ FACILITY_BOOKINGS : receives
    USERS ||--o{ FACILITY_BOOKINGS : requests
    DEPARTMENTS ||--o{ FACILITY_BOOKINGS : submits
    USERS ||--o{ FACILITY_BOOKINGS : approves

    ASSET_CATEGORIES ||--o{ ASSETS : categorizes
    DEPARTMENTS ||--o{ ASSETS : owns
    USERS ||--o{ ASSETS : custodians
    ASSETS ||--o{ ASSET_BOOKINGS : requested
    USERS ||--o{ ASSET_BOOKINGS : requests
    DEPARTMENTS ||--o{ ASSET_BOOKINGS : submits
    USERS ||--o{ ASSET_BOOKINGS : approves

    FACILITIES ||--o{ FACILITY_MAINTENANCE : has
    USERS ||--o{ FACILITY_MAINTENANCE : schedules
    ASSETS ||--o{ ASSET_MAINTENANCE : has
    USERS ||--o{ ASSET_MAINTENANCE : schedules

    USERS ||--o{ NOTIFICATIONS : receives
    USERS ||--o{ AUDIT_LOGS : performs
    USERS ||--o{ REFRESH_TOKENS : owns
```

This is the baseline logical ERD. If individually tracked asset
instances are required, add `asset_instances` and a booking-to-instance
allocation table before implementation.

## 7.3 Tables and Constraints

### `departments`

  Column            Type (suggested)   Constraints
  ----------------- ------------------ ----------------------------
  id                BIGINT UNSIGNED    PK, auto increment
  department_code   VARCHAR(30)        NOT NULL, UNIQUE
  department_name   VARCHAR(150)       NOT NULL
  description       TEXT               NULL
  status            VARCHAR(20)        NOT NULL, default `active`
  created_at        DATETIME           NOT NULL
  updated_at        DATETIME           NOT NULL

### `roles`

  Column           Type              Constraints
  ---------------- ----------------- -------------------------
  id               BIGINT UNSIGNED   PK
  role_name        VARCHAR(80)       NOT NULL, UNIQUE
  description      VARCHAR(255)      NULL
  is_system_role   BOOLEAN           NOT NULL, default false
  created_at       DATETIME          NOT NULL
  updated_at       DATETIME          NOT NULL

Seed baseline role names consistently: `faculty_employee`,
`booking_admin`, `asset_custodian`, `system_admin`.

### `permissions`

  Column           Type              Constraints
  ---------------- ----------------- ------------------
  id               BIGINT UNSIGNED   PK
  permission_key   VARCHAR(120)      NOT NULL, UNIQUE
  description      VARCHAR(255)      NULL

Use stable permission keys, e.g.: `facility.view`, `facility.create`,
`facility.update`, `facility.deactivate`, `facility.maintenance.manage`,
`facility_booking.create`, `facility_booking.view_all`,
`facility_booking.approve`, `facility_booking.reject`,
`facility_booking.cancel_any`, `asset.view`, `asset.create`,
`asset.update`, `asset.deactivate`, `asset_booking.create`,
`asset_booking.view_all`, `asset_booking.approve`,
`asset_booking.reject`, `asset_booking.release`, `asset_booking.return`,
`asset.maintenance.manage`, `user.manage`, `role.manage`,
`permission.manage`, `department.manage`, `report.facility.view`,
`report.asset.view`, `audit.view`.

### `role_permissions`

  Column          Type              Constraints
  --------------- ----------------- -------------------------------
  id              BIGINT UNSIGNED   PK
  role_id         BIGINT UNSIGNED   FK → roles.id, NOT NULL
  permission_id   BIGINT UNSIGNED   FK → permissions.id, NOT NULL

Constraint: `UNIQUE(role_id, permission_id)`.

### `users`

  -----------------------------------------------------------------------
  Column                  Type                    Constraints
  ----------------------- ----------------------- -----------------------
  id                      BIGINT UNSIGNED         PK

  employee_number         VARCHAR(50)             UNIQUE, NULL if not
                                                  required

  first_name              VARCHAR(100)            NOT NULL

  last_name               VARCHAR(100)            NOT NULL

  email                   VARCHAR(190)            NOT NULL, UNIQUE

  password_hash           VARCHAR(255)            NOT NULL

  department_id           BIGINT UNSIGNED         FK → departments.id,
                                                  nullable only if policy
                                                  allows

  role_id                 BIGINT UNSIGNED         FK → roles.id, NOT NULL

  account_status          VARCHAR(20)             NOT NULL, default
                                                  `active`

  last_login_at           DATETIME                NULL

  created_at              DATETIME                NOT NULL

  updated_at              DATETIME                NOT NULL
  -----------------------------------------------------------------------

Never expose `password_hash` through user API responses.

### `facility_categories`

  Column          Type              Constraints
  --------------- ----------------- ----------------------------
  id              BIGINT UNSIGNED   PK
  category_name   VARCHAR(100)      NOT NULL, UNIQUE
  description     TEXT              NULL
  status          VARCHAR(20)       NOT NULL, default `active`
  created_at      DATETIME          NOT NULL
  updated_at      DATETIME          NOT NULL

### `facilities`

  Column                 Type              Constraints
  ---------------------- ----------------- -----------------------------
  id                     BIGINT UNSIGNED   PK
  facility_code          VARCHAR(50)       NOT NULL, UNIQUE
  facility_name          VARCHAR(150)      NOT NULL
  category_id            BIGINT UNSIGNED   FK → facility_categories.id
  building               VARCHAR(120)      NULL
  location_details       VARCHAR(255)      NULL
  description            TEXT              NULL
  capacity               INT UNSIGNED      NOT NULL
  operating_hours_json   JSON              NULL (if supported)
  status                 VARCHAR(25)       NOT NULL
  image_path             VARCHAR(255)      NULL
  created_by             BIGINT UNSIGNED   FK → users.id
  created_at             DATETIME          NOT NULL
  updated_at             DATETIME          NOT NULL

Constraints: - `capacity > 0` - status limited to approved values such
as `active`, `inactive`, `maintenance` - Index `(category_id, status)`

If the installed MySQL version or project conventions do not support
JSON appropriately, use normalized operating-hours tables instead.

### `facility_bookings`

  Column                Type              Constraints
  --------------------- ----------------- -------------------------------
  id                    BIGINT UNSIGNED   PK
  booking_reference     VARCHAR(30)       NOT NULL, UNIQUE
  requester_id          BIGINT UNSIGNED   FK → users.id, NOT NULL
  department_id         BIGINT UNSIGNED   FK → departments.id, NOT NULL
  facility_id           BIGINT UNSIGNED   FK → facilities.id, NOT NULL
  start_datetime        DATETIME          NOT NULL
  end_datetime          DATETIME          NOT NULL
  activity_name         VARCHAR(180)      NOT NULL
  purpose               TEXT              NOT NULL
  participants_count    INT UNSIGNED      NOT NULL
  status                VARCHAR(25)       NOT NULL
  approver_id           BIGINT UNSIGNED   FK → users.id, NULL
  approval_remarks      TEXT              NULL
  submitted_at          DATETIME          NOT NULL
  approved_at           DATETIME          NULL
  rejected_at           DATETIME          NULL
  cancelled_at          DATETIME          NULL
  completed_at          DATETIME          NULL
  cancellation_reason   TEXT              NULL
  created_at            DATETIME          NOT NULL
  updated_at            DATETIME          NOT NULL

Constraints: - `CHECK (start_datetime < end_datetime)` (verify
enforcement in deployed DB version) - `CHECK (participants_count > 0)` -
`CHECK (participants_count <= facility.capacity)` must also be enforced
in application transaction because it references another row - Index
`(facility_id, start_datetime, end_datetime, status)` - Index
`(requester_id, status, submitted_at)` - Index
`(department_id, submitted_at)` - Index `(status, start_datetime)`

**Important:** MySQL does not provide a simple exclusion constraint for
arbitrary time overlaps. Prevent overlaps with transaction-based locking
and availability validation in the backend.

### `asset_categories`

  Column          Type              Constraints
  --------------- ----------------- ---------------------------------------
  id              BIGINT UNSIGNED   PK
  category_name   VARCHAR(100)      NOT NULL, UNIQUE
  description     TEXT              NULL
  tracking_mode   VARCHAR(25)       NOT NULL (`quantity` or `individual`)
  status          VARCHAR(20)       NOT NULL
  created_at      DATETIME          NOT NULL
  updated_at      DATETIME          NOT NULL

### `assets`

  Column                 Type              Constraints
  ---------------------- ----------------- --------------------------
  id                     BIGINT UNSIGNED   PK
  asset_code             VARCHAR(60)       NOT NULL, UNIQUE
  asset_name             VARCHAR(150)      NOT NULL
  category_id            BIGINT UNSIGNED   FK → asset_categories.id
  owning_department_id   BIGINT UNSIGNED   FK → departments.id
  description            TEXT              NULL
  serial_number          VARCHAR(120)      NULL, UNIQUE if present
  total_quantity         INT UNSIGNED      NOT NULL
  usable_quantity        INT UNSIGNED      NOT NULL
  condition_status       VARCHAR(25)       NOT NULL
  location               VARCHAR(180)      NULL
  custodian_id           BIGINT UNSIGNED   FK → users.id, NULL
  status                 VARCHAR(25)       NOT NULL
  created_by             BIGINT UNSIGNED   FK → users.id
  created_at             DATETIME          NOT NULL
  updated_at             DATETIME          NOT NULL

Constraints: - `total_quantity >= 0` - `usable_quantity >= 0` -
`usable_quantity <= total_quantity` - Index `(category_id, status)` -
Index `(owning_department_id, status)`

For quantity-tracked inventory, `usable_quantity` must be reconciled
with damaged, lost, retired, and maintenance units. For individually
tracked inventory, quantities must not be treated as proof of item-level
availability.

### `asset_bookings`

  Column               Type              Constraints
  -------------------- ----------------- ---------------------
  id                   BIGINT UNSIGNED   PK
  booking_reference    VARCHAR(30)       NOT NULL, UNIQUE
  requester_id         BIGINT UNSIGNED   FK → users.id
  department_id        BIGINT UNSIGNED   FK → departments.id
  asset_id             BIGINT UNSIGNED   FK → assets.id
  quantity_requested   INT UNSIGNED      NOT NULL
  usage_start          DATETIME          NOT NULL
  expected_return      DATETIME          NOT NULL
  purpose              TEXT              NOT NULL
  status               VARCHAR(30)       NOT NULL
  approver_id          BIGINT UNSIGNED   FK → users.id, NULL
  approval_remarks     TEXT              NULL
  submitted_at         DATETIME          NOT NULL
  approved_at          DATETIME          NULL
  rejected_at          DATETIME          NULL
  cancelled_at         DATETIME          NULL
  released_by          BIGINT UNSIGNED   FK → users.id, NULL
  released_at          DATETIME          NULL
  quantity_released    INT UNSIGNED      NULL
  release_condition    VARCHAR(25)       NULL
  received_by          BIGINT UNSIGNED   FK → users.id, NULL
  returned_at          DATETIME          NULL
  quantity_returned    INT UNSIGNED      NULL
  return_condition     VARCHAR(25)       NULL
  return_remarks       TEXT              NULL
  completed_at         DATETIME          NULL
  created_at           DATETIME          NOT NULL
  updated_at           DATETIME          NOT NULL

Constraints: - `quantity_requested > 0` -
`usage_start < expected_return` -
`quantity_released <= quantity_requested` -
`quantity_returned <= quantity_released` - Index
`(asset_id, usage_start, expected_return, status)` - Index
`(requester_id, status, submitted_at)` - Index
`(status, expected_return)`

For multiple partial releases/returns, replace single release/return
columns with dedicated transaction tables. Confirm whether partial
transactions are required before implementation.

### `facility_maintenance`

  Column           Type              Constraints
  ---------------- ----------------- ---------------------
  id               BIGINT UNSIGNED   PK
  facility_id      BIGINT UNSIGNED   FK → facilities.id
  start_datetime   DATETIME          NOT NULL
  end_datetime     DATETIME          NOT NULL
  reason           TEXT              NOT NULL
  status           VARCHAR(20)       NOT NULL
  created_by       BIGINT UNSIGNED   FK → users.id
  completed_by     BIGINT UNSIGNED   FK → users.id, NULL
  completed_at     DATETIME          NULL
  created_at       DATETIME          NOT NULL
  updated_at       DATETIME          NOT NULL

Constraint: `start_datetime < end_datetime`. Index
`(facility_id, start_datetime, end_datetime, status)`.

### `asset_maintenance`

  Column                Type              Constraints
  --------------------- ----------------- ---------------------
  id                    BIGINT UNSIGNED   PK
  asset_id              BIGINT UNSIGNED   FK → assets.id
  quantity_affected     INT UNSIGNED      NOT NULL
  start_datetime        DATETIME          NOT NULL
  expected_completion   DATETIME          NULL
  actual_completion     DATETIME          NULL
  reason                TEXT              NOT NULL
  status                VARCHAR(20)       NOT NULL
  created_by            BIGINT UNSIGNED   FK → users.id
  completed_by          BIGINT UNSIGNED   FK → users.id, NULL
  created_at            DATETIME          NOT NULL
  updated_at            DATETIME          NOT NULL

Constraints: `quantity_affected > 0`; indexes
`(asset_id, status, start_datetime)`.

### `notifications`

  Column                Type              Constraints
  --------------------- ----------------- -------------------------
  id                    BIGINT UNSIGNED   PK
  recipient_id          BIGINT UNSIGNED   FK → users.id
  title                 VARCHAR(180)      NOT NULL
  message               TEXT              NOT NULL
  notification_type     VARCHAR(40)       NOT NULL
  related_entity_type   VARCHAR(40)       NULL
  related_entity_id     BIGINT UNSIGNED   NULL
  is_read               BOOLEAN           NOT NULL, default false
  read_at               DATETIME          NULL
  created_at            DATETIME          NOT NULL

Indexes: `(recipient_id, is_read, created_at)`.

### `audit_logs`

  -----------------------------------------------------------------------
  Column                  Type                    Constraints
  ----------------------- ----------------------- -----------------------
  id                      BIGINT UNSIGNED         PK

  actor_user_id           BIGINT UNSIGNED         FK → users.id, NULL for
                                                  system event

  action                  VARCHAR(100)            NOT NULL

  entity_type             VARCHAR(80)             NOT NULL

  entity_id               BIGINT UNSIGNED         NULL

  old_values_json         JSON                    NULL

  new_values_json         JSON                    NULL

  ip_address              VARCHAR(45)             NULL

  user_agent              VARCHAR(255)            NULL

  created_at              DATETIME                NOT NULL
  -----------------------------------------------------------------------

Indexes: `(actor_user_id, created_at)`,
`(entity_type, entity_id, created_at)`, `(action, created_at)`.

Audit logs are append-only through application functionality. No
ordinary role may edit or delete them.

### `refresh_tokens` (if required by JWT implementation)

  Column        Type              Constraints
  ------------- ----------------- ------------------
  id            BIGINT UNSIGNED   PK
  user_id       BIGINT UNSIGNED   FK → users.id
  token_hash    VARCHAR(255)      NOT NULL, UNIQUE
  expires_at    DATETIME          NOT NULL
  revoked_at    DATETIME          NULL
  created_at    DATETIME          NOT NULL
  device_info   VARCHAR(255)      NULL

Do not store raw refresh tokens when a hashed representation is
feasible.

------------------------------------------------------------------------

# 8. Foreign Key and Deletion Policy

Recommended behavior:

-   Departments: restrict deletion when users or historical records
    reference them; deactivate instead.
-   Roles: restrict deletion while assigned; system roles must not be
    deleted casually.
-   Users: deactivate accounts; preserve booking and audit history.
-   Facilities: deactivate; preserve bookings and maintenance.
-   Assets: retire/deactivate; preserve booking and maintenance history.
-   Categories: restrict deletion while referenced; deactivate instead.
-   Bookings: never hard-delete through normal UI.
-   Maintenance: retain historical records.
-   Audit logs: append-only and retained under institutional policy.

Use `ON DELETE RESTRICT` (or equivalent safe behavior) for operational
history. Use cascading deletion only for clearly disposable dependent
records such as role-permission mappings, after confirming the framework
migration conventions.

------------------------------------------------------------------------

# 9. REST API Contract

API prefix: `/api/v1`

Use consistent resource names, HTTP verbs, status codes, pagination,
filtering, and JSON responses.

## Authentication

  Method   Endpoint                  Permission
  -------- ------------------------- -----------------------------------
  POST     `/auth/login`             Public
  POST     `/auth/refresh`           Valid refresh token
  POST     `/auth/logout`            Authenticated
  GET      `/auth/me`                Authenticated
  POST     `/auth/forgot-password`   Public, if implemented
  POST     `/auth/reset-password`    Valid reset token, if implemented

## Users, Roles, Departments

  -----------------------------------------------------------------------
  Method                  Endpoint                Permission
  ----------------------- ----------------------- -----------------------
  GET                     `/users`                `user.manage`

  GET                     `/users/{id}`           `user.manage` or own
                                                  record

  POST                    `/users`                `user.manage`

  PUT/PATCH               `/users/{id}`           `user.manage` or own
                                                  permitted profile
                                                  fields

  PATCH                   `/users/{id}/status`    `user.manage`

  GET                     `/roles`                Authenticated / policy

  POST/PUT                `/roles`                `role.manage`

  GET                     `/permissions`          `permission.manage`

  GET                     `/departments`          Authenticated

  POST/PUT                `/departments`          `department.manage`
  -----------------------------------------------------------------------

## Facilities

  Method      Endpoint                          Permission
  ----------- --------------------------------- --------------------------------
  GET         `/facilities`                     Authenticated
  GET         `/facilities/{id}`                Authenticated
  POST        `/facilities`                     `facility.create`
  PUT/PATCH   `/facilities/{id}`                `facility.update`
  PATCH       `/facilities/{id}/status`         `facility.deactivate`
  GET         `/facilities/{id}/availability`   Authenticated
  GET         `/facility-categories`            Authenticated
  POST/PUT    `/facility-categories`            Category-management permission

## Facility Bookings

  -------------------------------------------------------------------------------------------
  Method                  Endpoint                            Permission
  ----------------------- ----------------------------------- -------------------------------
  GET                     `/facility-bookings`                Own records or
                                                              `facility_booking.view_all`

  GET                     `/facility-bookings/{id}`           Owner or authorized approver

  POST                    `/facility-bookings`                `facility_booking.create`

  PATCH                   `/facility-bookings/{id}/approve`   `facility_booking.approve`

  PATCH                   `/facility-bookings/{id}/reject`    `facility_booking.reject`

  PATCH                   `/facility-bookings/{id}/cancel`    Owner or
                                                              `facility_booking.cancel_any`

  GET                     `/facility-bookings/my`             Authenticated
  -------------------------------------------------------------------------------------------

## Assets

  -----------------------------------------------------------------------------
  Method                  Endpoint                      Permission
  ----------------------- ----------------------------- -----------------------
  GET                     `/assets`                     Authenticated

  GET                     `/assets/{id}`                Authenticated

  POST                    `/assets`                     `asset.create`

  PUT/PATCH               `/assets/{id}`                `asset.update`

  PATCH                   `/assets/{id}/status`         `asset.deactivate`

  GET                     `/assets/{id}/availability`   Authenticated

  GET                     `/asset-categories`           Authenticated

  POST/PUT                `/asset-categories`           Asset-category
                                                        management permission
  -----------------------------------------------------------------------------

## Asset Bookings

  -------------------------------------------------------------------------------------
  Method                  Endpoint                         Permission
  ----------------------- -------------------------------- ----------------------------
  GET                     `/asset-bookings`                Own records or
                                                           `asset_booking.view_all`

  GET                     `/asset-bookings/{id}`           Owner or authorized
                                                           custodian

  POST                    `/asset-bookings`                `asset_booking.create`

  PATCH                   `/asset-bookings/{id}/approve`   `asset_booking.approve`

  PATCH                   `/asset-bookings/{id}/reject`    `asset_booking.reject`

  PATCH                   `/asset-bookings/{id}/cancel`    Owner or
                                                           `asset_booking.cancel_any`

  PATCH                   `/asset-bookings/{id}/release`   `asset_booking.release`

  PATCH                   `/asset-bookings/{id}/return`    `asset_booking.return`

  GET                     `/asset-bookings/my`             Authenticated
  -------------------------------------------------------------------------------------

## Maintenance

  --------------------------------------------------------------------------------------------
  Method                  Endpoint                                Permission
  ----------------------- --------------------------------------- ----------------------------
  GET/POST                `/facility-maintenance`                 Facility maintenance
                                                                  permission

  PUT/PATCH               `/facility-maintenance/{id}`            Facility maintenance
                                                                  permission

  PATCH                   `/facility-maintenance/{id}/complete`   Facility maintenance
                                                                  permission

  GET/POST                `/asset-maintenance`                    `asset.maintenance.manage`

  PUT/PATCH               `/asset-maintenance/{id}`               `asset.maintenance.manage`

  PATCH                   `/asset-maintenance/{id}/complete`      `asset.maintenance.manage`
  --------------------------------------------------------------------------------------------

## Notifications, Reports, Audit

  Method   Endpoint                     Permission
  -------- ---------------------------- -----------------------------------
  GET      `/notifications`             Authenticated; recipient-filtered
  PATCH    `/notifications/{id}/read`   Recipient
  PATCH    `/notifications/read-all`    Recipient
  GET      `/reports/facilities`        `report.facility.view`
  GET      `/reports/assets`            `report.asset.view`
  GET      `/reports/bookings`          Authorized report permission
  GET      `/reports/dashboard`         Role-appropriate data
  GET      `/audit-logs`                `audit.view`

All endpoints must enforce ownership and permission checks in the
backend. Never rely on frontend route guards alone.

------------------------------------------------------------------------

# 10. JSON Response Standards

## Success

``` json
{
  "success": true,
  "message": "Booking request submitted successfully.",
  "data": {
    "id": 123,
    "booking_reference": "FAC-2026-000123",
    "status": "pending"
  }
}
```

## Validation Error

``` json
{
  "success": false,
  "message": "The submitted data is invalid.",
  "errors": {
    "start_datetime": ["Start time must be before end time."]
  }
}
```

## Paginated List

``` json
{
  "success": true,
  "message": "Records retrieved successfully.",
  "data": [],
  "meta": {
    "current_page": 1,
    "per_page": 10,
    "total": 0,
    "last_page": 1
  }
}
```

Use appropriate HTTP status codes:

-   `200` --- successful retrieval or update
-   `201` --- successful creation
-   `204` --- successful operation with no response body, if appropriate
-   `400` --- malformed or invalid request
-   `401` --- unauthenticated
-   `403` --- authenticated but unauthorized
-   `404` --- resource not found or not visible to the requester
-   `409` --- booking conflict or invalid state transition
-   `422` --- validation failure (if this convention is adopted
    consistently)
-   `429` --- rate limit exceeded
-   `500` --- unexpected server error

Do not expose stack traces or SQL errors in production.

------------------------------------------------------------------------

# 11. LavaLust-Specific Implementation Conventions

## 11.1 Mandatory Inspection

Before writing backend code, the agent must:

1.  Inspect the actual repository structure.
2.  Identify the exact installed LavaLust version.
3.  Read existing controllers, models, routes, configuration, libraries,
    and API response patterns.
4.  Check official documentation for the installed version:
    https://lavalust.netlify.app/
5.  Reuse existing project conventions.
6.  Confirm how authentication, JWT, request validation, database
    transactions, migrations, and REST responses are implemented in this
    repository.

## 11.2 Architecture Rules

-   Use LavaLust's native MVC architecture and supported conventions.
-   Controllers must extend the appropriate native controller base
    class.
-   Models must follow the installed LavaLust model conventions.
-   Define routes using the framework's native route configuration.
-   Use LavaLust's database connection and query builder.
-   Use the native request/response lifecycle and supported REST
    response helpers.
-   Use framework-supported validation and authentication mechanisms.
-   Use existing libraries/helpers before introducing new abstractions.
-   Keep React as a separate frontend application consuming the API.

## 11.3 Prohibited Assumptions

Do not assume:

-   Laravel's `artisan`, Eloquent, service providers, middleware, or
    `app/Http/` structure.
-   CodeIgniter 4's namespace, filters, services, or migration
    conventions.
-   A particular HMVC module layout unless the installed LavaLust
    version and project support it.
-   A custom `Services/` or `Repositories/` layer is required.
-   A specific JWT package or API response method exists.
-   A migration command or migration class format is known without
    inspecting the project.

## 11.4 Directory Structure

The actual repository is authoritative. Do not force it into a
hypothetical structure.

Use only directories and mechanisms present in the installed LavaLust
version or deliberately added in a compatible, documented manner.
Preserve the framework's core directory (including `scheme/`) and do not
modify framework internals without explicit approval.

## 11.5 Controller Responsibilities

Controllers should:

-   Receive and interpret the request.
-   Authenticate and authorize the operation using supported mechanisms.
-   Validate input.
-   Call appropriate model methods.
-   Coordinate transactional business operations using supported
    framework/database features.
-   Return consistent JSON responses.
-   Avoid unrelated business logic and duplicated SQL.

## 11.6 Model Responsibilities

Models should:

-   Extend/use the native LavaLust model pattern.
-   Follow existing naming and table conventions.
-   Encapsulate data access and appropriate data-level rules.
-   Use parameterized queries/query builder.
-   Avoid direct output, view rendering, or HTTP response manipulation.
-   Avoid introducing a second ORM.

## 11.7 Business Logic Placement

Availability checks, approval transitions, quantity allocation,
release/return, and conflict prevention must live in the backend and fit
the native LavaLust architecture.

If a complex operation requires a reusable class, first verify where
LavaLust expects custom libraries and how existing code loads them. Add
the smallest compatible abstraction; do not introduce architecture for
architecture's sake.

## 11.8 Routes and REST

-   Follow LavaLust route syntax and route-loading conventions.
-   Use explicit HTTP methods and resource-oriented paths.
-   Keep route definitions organized and readable.
-   Protect endpoints with supported authentication/authorization
    mechanisms.
-   Verify the framework's supported REST response helpers before using
    them.
-   Do not invent methods or classes based on other frameworks.

## 11.9 Database and Migrations

-   Follow the migration mechanism supported by the installed version.
-   Inspect existing migration files and commands before creating
    migrations.
-   Use the configured database connection.
-   Use transactions and locking supported by the database driver.
-   Never manually alter production data as a substitute for a
    migration.
-   Do not reset or drop a database without explicit approval.

## 11.10 Environment Configuration

-   Keep database credentials, JWT secrets, refresh-token keys, and
    other secrets in environment configuration.
-   Do not commit `.env` files containing secrets.
-   Validate required configuration at startup where feasible.
-   Keep frontend environment variables public-safe; never place backend
    secrets in Vite variables.
-   Use separate development and production configuration.

------------------------------------------------------------------------

# 12. React Frontend Conventions

Suggested organization (adapt to the existing project):

``` text
src/
├── assets/
├── components/
│   ├── ui/
│   ├── layout/
│   ├── forms/
│   ├── tables/
│   └── feedback/
├── features/
│   ├── auth/
│   ├── dashboard/
│   ├── facilities/
│   ├── facility-bookings/
│   ├── assets/
│   ├── asset-bookings/
│   ├── maintenance/
│   ├── notifications/
│   ├── reports/
│   └── users/
├── layouts/
├── routes/
├── services/
├── hooks/
├── context/
├── lib/
├── utils/
├── App.jsx
└── main.jsx
```

Frontend rules:

-   Keep API calls in service modules, not scattered through components.
-   Use reusable UI components and consistent design tokens.
-   Protect routes for UX, but always rely on backend authorization.
-   Handle loading, empty, error, success, and unauthorized states.
-   Validate forms for usability; backend remains authoritative.
-   Avoid oversized components and duplicated logic.
-   Use environment variables for the API base URL.
-   Never expose JWT signing secrets or database credentials.
-   Use accessible labels, keyboard navigation, and responsive layouts.

------------------------------------------------------------------------

# 13. Security Requirements

-   Hash passwords with PHP's secure password hashing APIs.
-   Use parameterized database queries.
-   Validate all user-controlled input server-side.
-   Enforce authorization for every protected endpoint and record.
-   Protect against XSS and unsafe file uploads.
-   Restrict CORS to trusted frontend origins.
-   Use HTTPS in production.
-   Apply rate limiting to login and token endpoints where supported.
-   Use secure token expiration and revocation.
-   Never log raw passwords or tokens.
-   Avoid exposing personal data in booking calendar views.
-   Prevent mass assignment of sensitive fields such as role, status,
    approver, and ownership.
-   Audit privileged actions.
-   Do not trust client-provided requester IDs; derive identity from
    authenticated context.
-   Do not trust client-provided approval or release status; transition
    it through authorized backend operations.

------------------------------------------------------------------------

# 14. Audit Requirements

Record at minimum:

-   User/account changes
-   Role and permission changes
-   Facility creation, edits, deactivation
-   Asset creation, edits, retirement
-   Booking submission
-   Approval/rejection
-   Cancellation
-   Asset release/return
-   Maintenance scheduling/completion
-   Policy/configuration changes

Each event should include actor (or system), action, entity type/ID,
timestamp, and relevant before/after values. Avoid storing secrets or
unnecessary personal information in audit payloads.

------------------------------------------------------------------------

# 15. Testing Strategy

## Backend

-   Authentication and token expiry
-   Permission checks for each role
-   Record ownership checks
-   Input validation
-   Facility CRUD
-   Asset CRUD
-   Availability calculations
-   Booking overlap detection
-   Simultaneous booking attempts
-   Approval/rejection/cancellation transitions
-   Asset quantity allocation
-   Release/return validation
-   Maintenance restrictions
-   Database rollback behavior
-   API response shape

## Frontend

-   Login/logout and token handling
-   Protected routes and role navigation
-   Form validation
-   API loading/error/empty states
-   Facility/asset browsing
-   Availability display
-   Booking submission and history
-   Approval/rejection interfaces
-   Release/return interfaces
-   Responsive behavior and accessibility

## Integration

-   React communicates with the correct LavaLust API base URL.
-   Authenticated requests carry tokens as designed.
-   Unauthorized requests are handled consistently.
-   Status changes update UI data.
-   Concurrent requests cannot overbook resources.
-   Transactions rollback on failed operations.

------------------------------------------------------------------------

# 16. Development Phases

## Phase 1 --- Foundation

-   Inspect existing project and LavaLust version
-   Confirm roles and policies
-   Database schema and migrations
-   Authentication and JWT
-   User, role, permission, department management
-   API response conventions
-   React routing and layout

## Phase 2 --- Resource Catalog

-   Facility categories and facilities
-   Asset categories and assets
-   Resource details
-   Availability endpoints
-   Admin CRUD interfaces

## Phase 3 --- Booking

-   Facility booking submission
-   Asset booking submission
-   Availability and conflict prevention
-   Approval/rejection
-   Cancellation
-   Booking history

## Phase 4 --- Asset Operations

-   Release and return
-   Condition tracking
-   Overdue handling
-   Maintenance
-   Notifications

## Phase 5 --- Administration and Reports

-   Dashboards
-   Calendar
-   Reports
-   Audit logs
-   Export, if approved

## Phase 6 --- Stabilization and Deployment

-   Integration testing
-   Security review
-   Performance optimization
-   Production environment configuration
-   Deployment documentation
-   User/admin guide

------------------------------------------------------------------------

# 17. AI Coding Agent Rules (Mandatory)

1.  **Inspect first.** Before editing, inspect relevant files, framework
    version, dependencies, routes, models, migrations, and existing
    patterns.
2.  **LavaLust first.** Follow native LavaLust conventions. Do not port
    Laravel or CodeIgniter architecture into this project.
3.  **No destructive changes without approval.** Never delete project
    files, reset databases, overwrite major modules, or remove
    dependencies without explicit authorization.
4.  **Preserve working code.** Make focused, incremental changes.
5.  **No invented framework APIs.** Verify methods, classes, helpers,
    routing, migrations, and JWT implementation against installed
    code/docs.
6.  **Backend is authoritative.** Enforce permissions, validation, state
    transitions, and booking rules server-side.
7.  **Transaction-critical operations.** Booking approvals, availability
    allocation, release/return, and inventory updates must be atomic
    where required.
8.  **Do not leak secrets.** Never place secrets in React, source
    control, logs, or API responses.
9.  **Follow existing naming.** Do not rename files, tables, endpoints,
    or conventions unnecessarily.
10. **Document changes.** Update API/database documentation when
    introducing schema or endpoint changes.
11. **Test the change.** Run relevant tests or provide reproducible
    manual verification steps.
12. **Report completion.** Summarize files created/modified, schema
    changes, endpoints, tests, and known limitations.
13. **Ask when requirements conflict.** Do not guess approval policy,
    asset tracking mode, cancellation rules, or scope permissions.
14. **Keep changes reviewable.** Prefer small diffs and clear
    implementation steps.

------------------------------------------------------------------------

# 18. Definition of Done

A feature is complete when:

-   Requirements and business rules are implemented.
-   Database changes follow existing LavaLust migration conventions.
-   API routes and responses follow project standards.
-   Backend validation and authorization are present.
-   Booking and inventory integrity is preserved.
-   React integration is functional.
-   Loading, error, empty, and success states are handled.
-   Relevant tests pass or verification steps are documented.
-   No unrelated files were removed or rewritten.
-   Documentation is updated.
-   Secrets and sensitive data are protected.
-   The agent provides a clear summary of its changes.

------------------------------------------------------------------------

# 19. Decisions Requiring Stakeholder Confirmation

The AI agent must not silently invent answers to these questions:

1.  Can students book, or only faculty/employees?
2.  Is approval performed by one coordinator or multiple approvers?
3.  Does approval depend on department, facility, or asset category?
4.  Resolved: pending requests do not hold availability; overlapping pending requests are allowed.
5.  Resolved: recurring requests are allowed; each occurrence follows normal validation and independent approval.
6.  Resolved: zero buffer; back-to-back facility bookings are allowed.
7.  Resolved: bookings may start up to 30 campus calendar days ahead, inclusive.
8.  Resolved: requesters and authorized staff may cancel before the scheduled start, provided assets have not been released.
9.  Resolved: support both quantity-tracked and individually tracked assets.
10. Resolved: support partial releases and returns; release only during the approved booking period.
11. What is the process for damaged, lost, or overdue assets?
12. Is departmental administrative scope required?
13. Are email notifications and export required in the initial release?
14. What retention policy applies to audit logs and booking records?

------------------------------------------------------------------------

**End of Specification**



### Deferred External Integrations (2026-11-07)

The user added [External Integrations and Extended System Features](docs/external-integrations.md) as follow-on scope. Preserve the attached document as requirements, not a description of existing implementation. Finish the current reporting/CSV and core verification work first; do not start integrations or deployment configuration in that increment.

Then implement and test one integration at a time in the supplied order: Google Sign-In, FullCalendar, email notifications, QR identification/scanning, and Excel/PDF exports. Reuse current LavaLust MVC, authentication, grants, handover ledger, notifications, and existing tables. Any necessary schema changes must use new LavaLust migrations. Example endpoints and payloads in the attachment are illustrative and must be adapted to actual APIs. Calendar events remain advisory and permission-scoped; QR scans must retain explicit authorized actions and existing release timing/idempotency rules. Account linking/domain policy, delivery-provider configuration, facility attendance eligibility, pooled-item QR identity, and maintenance-report assignment must be resolved against actual capabilities when their phase begins. These workflows are not all implemented today. Existing booking policies remain authoritative unless the user explicitly changes them. Deployment setup remains deferred.

The earlier completion estimate covered the core system; these additional integrations expand the remaining scope. All five follow-on integrations are pending.

## Full Follow-on Integration Specification

The following user-supplied requirements are accepted follow-on scope, deferred until the current core reporting and verification work is complete. Suggested structures and APIs must be reconciled with the installed implementation.

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

**Approved scope update (2026-11-07): QR codes apply to assets only.**
Use one label per quantity-based asset pool; custodians enter each handover's
quantity. Each individually tracked asset instance has its own label, matching
the requester's selected items. Facility QR labels and QR attendance are excluded
by the user's latest instruction, superseding the original integration attachment.
If facility attendance is introduced separately in future, only authorized
facility staff may record it. QR-linked asset problem reports now use the separate
administrator-review and assigned-custodian workflow documented in
`docs/asset-problems.md`. See `docs/asset-qr.md` for implemented QR endpoints.

### Objective

Implement QR code identification for physical assets.

QR codes support inventory identification and explicitly confirmed asset releases
and returns. They must not approve bookings or perform custody actions on scan.

### QR Code Types

Implement asset labels for both tracking modes:

**Asset QR Code**

Each physical asset must have a unique QR identifier.

Examples:

- AST-001
- AST-002
- AST-003

The identifier must be unique and must not expose sensitive information.

Use the existing asset codes and asset tags for human-readable captions. Encode
an opaque identifier rather than those captions in each QR code.

### QR Code Generation

- Generate an identifier when a quantity-based asset pool or individually tracked asset instance is registered.
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

### Maintenance Reporting

Future scope: allow authorized users to report an asset problem through its QR page.

The report should support:

- Description of the issue.
- Date and time reported.
- Reporting user.
- Optional photo attachment.
- Current resource identification.
- Maintenance status.

The asset reporting workflow is now implemented separately from maintenance
scheduling. Only system administrators with asset maintenance permission may
review and assign reports; active assigned custodians may start, update and
resolve work. Reporters see their own reports and private optional JPEG/PNG
photo. Starting work reuses current maintenance and booking-conflict checks.
See `docs/asset-problems.md` for access, states, photo safeguards and endpoints.

### Suggested API Endpoints

Use the project's existing REST conventions.

- `GET /api/qr/{identifier}` - Resolve a QR identifier.
- `GET /api/qr-labels/{kind}/{id}` - View a pooled asset or tagged-item label.
- `POST /api/qr-labels/{kind}/{id}` - Revoke or regenerate its identifier.
- `POST /api/asset-bookings/{id}/release` - Explicitly confirm asset release.
- `POST /api/asset-bookings/{id}/return` - Explicitly confirm asset return.
- Maintenance-report endpoints remain future scope.

### Security and Validation

- QR scanning must not automatically approve bookings.
- QR scanning must not automatically release equipment.
- Verify user identity and permissions on every operation.
- Verify booking ownership or authorized custodian access.
- Verify that the booking is in an eligible status.
- Prevent duplicate release and return transactions.
- Validate whether the operation is allowed at the current time.
- Record all transactions in an auditable history.
- Use database transactions for operations that modify multiple related records.
- Prevent unauthorized modification of QR identifiers.

### Acceptance Criteria

- Every quantity pool and individually tracked asset instance has a unique QR identifier.
- Scanning a valid code displays the correct resource.
- Invalid or revoked QR codes are rejected.
- Authorized custodians can record releases and returns.
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

**Approved access update (2026-11-07): Excel and PDF exports are available only
to the `system_admin` role, together with the report's current permission.**
Booking administrators, custodians and other roles cannot export these formats,
even if they have report-view grants. Existing JSON/CSV access is unchanged.
The implemented reports are the current facility booking summaries, asset booking
summaries and asset inventory, using their existing filters and queries. Other
report types listed below remain future scope. See `docs/report-exports.md`.

### Objective

Allow system administrators with the relevant report permission to generate and
download administrative reports in Excel and PDF formats.

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

**Assets**
- QR identifiers live in the separate `qr_labels` registry, with unique
  associations to quantity pools or individual asset instances.

**Facility Bookings**
- QR attendance fields are excluded from the approved asset-only QR scope.

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
| 4 | QR Code System | Asset identification and confirmed release/return |
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

### Confirmed Google Account Eligibility (2026-11-07)

The user confirmed that Google Sign-In may support both school-issued Google accounts and personal Gmail accounts. Eligibility still requires an existing active, authorized local campus account; this does not authorize open registration, automatic role assignment, or bypassing secure account linking. Verify Google tokens on the LavaLust backend and retain local roles, permissions and account deactivation as authoritative. Implement the authentication integration only after core verification, following the deferred integration specification above.

### Google Sign-In Implementation Status (2026-11-07)

- Added Google Identity Services sign-in and password-confirmed linking/unlinking in My account, with no automatic registration or email-based linking.
- Added LavaLust migration `009_create_google_identities.php` for unique Google subject mappings and expiring, single-use challenges. Existing campus sessions, live permissions and password login remain authoritative.
- Added backend signature/claim verification using `firebase/php-jwt`, fixed Google HTTPS keys with caching, and replay protection. Google credentials and campus tokens remain in frontend memory.
- The supplied Web OAuth client ID is configured locally; its downloaded JSON lists no authorized origins. Google Cloud origins and a real Google linking/sign-in walkthrough remain to be verified before this integration is considered complete.
- Local setup, endpoints, dependency and verification details: `docs/google-sign-in.md`. Production deployment remains deferred.

### Google Sign-In Live Verification (2026-11-07)

The user confirmed that account linking and subsequent Google Sign-In work with
their account after configuring the local authorized origins. Production OAuth
origins and deployment remain deferred.

### FullCalendar Implementation (2026-11-07)

The separate frontend now provides a permission-scoped booking calendar with
month, week, day, agenda and custom date-range views, resource/category/status
filters, authorized requester-department filtering and event details. Calendar
selections reuse the existing availability/request pages. A read-only LavaLust
event feed projects the existing booking/maintenance tables, with private request
details restricted to owners and current authorized staff. The calendar remains
advisory; stock, custody, inspection and booking approval use the existing backend
rules. No duplicate tables or alternative backend frameworks are introduced.
Implementation contract and verification commands are in `docs/calendar.md`.

### Email and Password Recovery Implementation (2026-11-07)

Resend was selected. Added a persistent encrypted outbox and CLI-only preview,
queue and delivery worker, using existing notifications/reminder eligibility and
live recipient authorization. Migration `010_create_email_outbox.php` adds mail
tracking and secure password recovery. Historical notifications are not mailed
retroactively. The frontend supports single-use password reset links; successful
resets invalidate old access/refresh sessions without changing Google links.

Sending remains disabled: the user has no verified sender domain yet, and the
API key posted in chat must be revoked and privately replaced. No live email was
sent during implementation. Test-domain use is limited by Resend to the account's
own address; institution-wide delivery requires sender verification. Production
scheduling/deployment remain deferred. See `docs/email-notifications.md` for setup,
supported events, retry limits, privacy rules and verification commands.
# Deployment configuration decision (2026-11-07)

Role-policy update (2026-11-07): system administrators, booking administrators,
and asset custodians cannot create facility or asset bookings, including
recurring requests. Staff may view schedules and availability for operations;
requester accounts with the appropriate creation grants submit bookings.
Migration 013 removes the old staff creation grants, and effective permission
checks also exclude them. Dashboards show personal requests for requesters,
facility activity for booking administrators, asset custody for custodians,
and authorized management totals for system administrators. Default dashboard
dates include the past 30 days and next 31 days, so upcoming bookings appear.

The user selected Render for the LavaLust backend, Vercel for the separate React
frontend, and retention of the current hosted MySQL database. Configured origins
are `https://cfabs-1.onrender.com` and `https://cfabs-frontend.vercel.app`.
See `docs/deployment.md`. Configuration is prepared locally; live redeployment,
environment setup, operational scheduling and smoke verification remain required.
