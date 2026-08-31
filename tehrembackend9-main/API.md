# MEPCO LT/HT TOU Meter Testing — API Reference

Base URL (local): `http://localhost:8000`
Base URL (Railway): `https://<your-app>.up.railway.app`

All responses are JSON. All protected routes require:
```
Authorization: Bearer <token>
```

---

## Auth

### POST `/api/login.php` — login
```json
// request
{ "action": "login", "username": "g.mustafa", "password": "test1234" }

// response (returning user)
{
  "success": true, "requires_otp": false,
  "employee_id": "EMP-1042", "full_name": "Ghulam Mustafa", "username": "g.mustafa",
  "role_code": "MT", "scope_code": "SUB_DIVISION", "scope_name": "Multan North Sub-Division",
  "token": "…", "is_first_login": false, "contact_masked": "03**-***-4892"
}

// response (first-time login → OTP required)
{
  "success": true, "requires_otp": true, "temp_token": "…", "cooldown_seconds": 60,
  "employee_id": "EMP-9999", "full_name": "System Administrator", "username": "admin",
  "role_code": "ADMIN", "scope_code": "NATIONAL", "scope_name": "National (All Regions)",
  "contact_masked": "03**-***-0001"
}
```

### POST `/api/login.php` — verify OTP
```json
{ "action": "verify_otp", "temp_token": "…", "otp_code": "123456" }
```
Returns the same shape as a successful login (with a real `token`).

### POST `/api/login.php` — resend OTP
```json
{ "action": "resend_otp", "temp_token": "…" }
// -> { "success": true, "message": "A new OTP has been sent.", "cooldown_seconds": 60 }
```

### POST `/api/logout.php` 🔒
Revokes the current bearer token server-side.
```json
{ "success": true, "message": "Logged out successfully." }
```

### GET `/api/me.php` 🔒
Restores a session on app relaunch — validates the stored token and returns the profile (no `token` field, since none is re-issued).

### POST `/api/change_password.php` 🔒
Any authenticated user (any role) changes their **own** password. Requires proving knowledge of the current password — distinct from `PUT /api/admin/users.php`, which is ADMIN-only and resets any user's password without it.
```json
{ "current_password": "old-pass", "new_password": "new-pass-min-8-chars" }
```
On success, revokes every other active token for this user (other devices are signed out); the token used for this request remains valid.
```json
{ "success": true, "message": "Password changed successfully." }
```
Errors: `401 INVALID_CURRENT_PASSWORD` if `current_password` doesn't match; `422 VALIDATION_ERROR` if `new_password` is missing, under 8 characters, or identical to the current password.

---

## Geographic Scope Enforcement (SDO / XEN access control)

Every listing/lookup endpoint below that touches consumer-linked data (tasks, discrepancies, inspections, schedules, approvals, dashboard, consumers) enforces the caller's own geographic scope **server-side**, not just as a UI default:

- **SDO** (`scope_code = SUB_DIVISION`) — restricted to their own sub-division(s). An SDO may supervise more than one; `scope_name` can list several, delimited by comma/semicolon/slash/`"and"` (e.g. `"Multan North Sub-Division, Multan South Sub-Division"`).
- **XEN** (`scope_code = DIVISION`) — restricted to their own division.
- **SE / ADMIN** — unrestricted. (SE's scope is a *circle*, which spans multiple divisions, but `consumers`/`schedules` only carry `division`/`sub_division` columns — there's no `circle` column yet, so true circle-level enforcement isn't possible until the schema grows one. This is a known gap, not an oversight.)

Any `division`/`sub_division` query parameter the client sends is still honored **on top of** this — it can only narrow the result further within the caller's own scope, never widen it or substitute for it. Implementation: `enforced_scope()` / `enforced_scope_sql()` / `is_within_enforced_scope()` in `config/helpers.php`.

SDO's access to **Scheduling** is additionally **view-only** — generating, manually creating, overriding, or deleting a schedule entry requires XEN/SE/ADMIN (`schedules are meant to be automatic, with Admin involvement if needed`).

---

## Inspection data

### GET `/api/data.php?action=form-options` 🔒
```json
{ "success": true, "seal_conditions": [{"code":"INTACT","label":"Intact"}, …], "ctpt_box_statuses": [ … ] }
```

### GET `/api/data.php?action=consumer-fetch&ref=REF-2025-00142` 🔒
```json
{
  "success": true, "meter_id": "MTR-LHR-2024-00987", "consumer_name": "Haji Textile Mills (Pvt) Ltd.",
  "consumer_address": "Plot 14-B, SITE Area, Lahore", "consumer_account": "LHR-04-2200-1429",
  "tariff_category": "Industrial B-2", "sanctioned_load": "250 kW"
}
```
404 if the reference number isn't found.

### POST `/api/data.php?action=inspection-submit` 🔒
Field roles (M&T) only — supervisory roles (SDO/XEN/SE/ADMIN) get `403 FORBIDDEN_ROLE`.
```json
{
  "client_uuid": "b3f1c2a0-...-offline-queue-id",
  "reference_number": "REF-2025-00142", "meter_id": "MTR-LHR-2024-00987",
  "consumer_account": "LHR-04-2200-1429", "inspection_datetime": "2026-07-05T10:30:00.000Z",
  "readings": { "kwh": 1234.5, "kvarh": 210.0, "mdi": 45.2 },
  "tou_readings": { "peak": 300.0, "off_peak": 150.0, "day": null, "night": null },
  "infrastructure": { "seal_condition": "INTACT", "ctpt_box_status": "SECURED" },
  "gps": { "latitude": 30.1575, "longitude": 71.5249, "accuracy_meters": 12.4 },
  "images": [
    { "type": "METER", "data_base64": "data:image/jpeg;base64,....", "latitude": 30.1575, "longitude": 71.5249, "captured_at": "2026-07-05T10:28:00.000Z" },
    { "type": "SEAL",  "data_base64": "data:image/jpeg;base64,....", "latitude": 30.1575, "longitude": 71.5249, "captured_at": "2026-07-05T10:29:00.000Z" }
  ],
  "task_id": 7,
  "load_details": "No anomalies observed."
}
// -> { "success": true, "message": "Inspection submitted successfully.", "id": 1, "image_urls": ["https://…/uploads/inspections/1/ab12cd.jpg", …],
//      "overall_status": "PENDING_APPROVAL", "current_approval_level": 1 }
```
Notes:
- `gps` is **mandatory** — `latitude`/`longitude` must be valid coordinates, and `accuracy_meters` (if sent) must be ≤ `GPS_MAX_ACCURACY_METERS` (env, default 150m).
- `images` requires **2 to 12** entries, each geo-tagged (`latitude`/`longitude`) and timestamped (`captured_at`); `type` is one of `METER`, `SEAL`, `INSTALLATION`, `LOAD`.
- `client_uuid` (optional but recommended) makes the submission **idempotent** for offline sync — replaying the same `client_uuid` after a flaky connection returns the original record (`"duplicate": true`) instead of erroring or double-inserting.
- Without a `client_uuid`, an exact repeat (same `reference_number` + `consumer_account` + `inspection_datetime`) is rejected with `409 DUPLICATE_INSPECTION`.
- `task_id` (optional) links this submission to an assigned task — on success, that task (and its parent schedule, if any) is automatically marked `COMPLETED`.
- Every submission is automatically enrolled in the **Approval Workflow** — see below. `overall_status` starts `PENDING_APPROVAL` at `current_approval_level` 1 (SDO), unless the consumer's category is configured to skip review, in which case it's `APPROVED` immediately.

### GET `/api/data.php?action=inspections-list` 🔒
`?search=&status=&division=&sub_division=&category=&date_from=&date_to=&page=&per_page=` (all optional; a bare `?limit=` from older clients still works).
- MT: always scoped to inspections they personally submitted.
- SDO/XEN: server-side enforced to their own scope (see above). SE/ADMIN: full list.
- `status` filters by Approval Workflow outcome (`PENDING_APPROVAL`/`APPROVED`/`REJECTED`); `search` matches meter ID, reference number, consumer account, or consumer name.
- Returns GPS, `task_id`, `image_count`, and consumer division/sub_division/category per record, plus `total` for pagination.

### GET `/api/data.php?action=inspection-detail&id=42` 🔒
Full single-record view — every reading field, GPS, and the **uploaded image list** (each with `image_url`, GPS, type, and capture time). Same scoping as `inspections-list`, plus the record's own submitter. When `overall_status = REJECTED`, also includes `rejection: {role_code, remarks, created_at, approver_name}` for the most recent reject decision — the one piece of Approval Workflow history exposed outside `/api/approvals.php`, so the submitting M&T can see why without needing supervisory access.

---

## Meter Scheduling System (supervisory roles: SDO / XEN / SE / ADMIN — 🔒🔐)

### `/api/admin/schedules.php`
| Method | Query / Body | Result |
|---|---|---|
| GET | `?division=&sub_division=&category=B2&quarter=2026-Q3&status=PENDING&page=&per_page=` | filtered, paginated list — SDO/XEN server-side scoped |
| GET | `?id=5` | single schedule entry — scoped |
| POST `?action=generate` (XEN/SE/ADMIN only) | body: `{"quarter"?, "division"?, "sub_division"?, "category"?}` | auto-generates one schedule row per matching consumer lacking one for that quarter (idempotent — safe to re-run); dates are spread evenly across the quarter |
| POST (XEN/SE/ADMIN only) | body: `{"consumer_id", "quarter", "scheduled_date", "category"?}` | manual single create |
| PUT (XEN/SE/ADMIN only) | `?id=5`, body: any of `{"scheduled_date","status","override_reason","category"}` | manual override (always sets `is_manual_override=1`) |
| DELETE (XEN/SE/ADMIN only) | `?id=5` | remove a schedule entry |

`quarter` is always `"YYYY-Qn"`, e.g. `"2026-Q3"`. Filters (`division`, `sub_division`, `category`) are AND-combined; `category` is one of `B1`–`B4`. **SDO's access here is view-only** — see "Geographic Scope Enforcement" above.

---

## Task Assignment (🔒 — access is role-scoped per method, not admin-exclusive)

### `/api/tasks.php`
| Method | Who | Query / Body | Result |
|---|---|---|---|
| GET | anyone | `?status=&page=&per_page=` | field team (MT etc.): **their own** tasks only, forced server-side |
| GET | supervisory | `?status=&assigned_to=&division=&sub_division=&page=&per_page=` | full list, filterable — SDO/XEN server-side scoped. Also triggers the auto-reassignment sweep (see below). |
| GET | scoped | `?id=7` | single task (field team: own only; supervisory: within their scope) |
| POST | supervisory only | `{"consumer_id" or "schedule_id", "assigned_to_user_id", "due_date"?, "notes"?}` | assigns a task; if from a schedule, marks it `ASSIGNED`. Target consumer/schedule must be within the caller's scope; an SDO may only assign to a worker in their own sub-division. |
| PUT | assignee | `?id=7`, `{"status":"IN_PROGRESS"}` | field team can start their own task |
| PUT | supervisory | `?id=7`, any of `{"status","assigned_to_user_id","due_date","notes"}` | full update / reassignment — within the caller's scope |
| DELETE | supervisory only | `?id=7` | soft-cancels (`status=CANCELLED`) — preserves history; within the caller's scope |

Task `status` values: `PENDING`, `IN_PROGRESS`, `COMPLETED`, `CANCELLED`. `COMPLETED` is normally set automatically when the assignee submits the linked inspection (see `task_id` above), not via this endpoint directly. Every row also includes `inspection_overall_status` (`PENDING_APPROVAL`\|`APPROVED`\|`REJECTED`\|`null` if no inspection is linked yet) — the Approval Workflow outcome of the linked inspection, so the assignee can see it without a separate request. A `REJECTED` inspection reopens its task to `PENDING` (see `/api/approvals.php` below), which is what lets the assignee inspect again.

**Automatic reassignment** (SRS Schedule section: "Automatically reassigned inspections") — every row includes `auto_reassigned_at`/`auto_reassigned_from_name` (both `NULL` if never auto-reassigned). No cron exists in this deployment, so — same pattern as escalation alerts above — `run_auto_reassignment_sweep()` in `config/helpers.php` runs opportunistically whenever a supervisory role loads `GET /api/tasks.php` or `GET /api/dashboard.php`: any task still `PENDING`/`IN_PROGRESS` more than 15 days past its due date (assumption — not specified exactly by the SRS; confirm cadence with the client) gets automatically reassigned **once** to the least-loaded active M&T worker in the same sub-division, then never auto-reassigned again (a second miss is a people problem for a supervisor to handle manually). `GET /api/admin/schedules.php` also surfaces `auto_reassigned_at`/`auto_reassigned_from_name` per schedule entry, for visibility on the Schedule screen.

---

## Discrepancy Reporting (🔒 — report: anyone; triage: supervisory roles)

### `/api/discrepancies.php`
| Method | Who | Query / Body | Result |
|---|---|---|---|
| GET | anyone | `?status=&type=&severity=&division=&sub_division=&category=&assigned_to=&page=&per_page=` | field team: **their own** reports only |
| GET | supervisory | same filters | full list — SDO/XEN server-side scoped |
| GET | scoped | `?id=9` | single record (field team: own only; supervisory: within their scope) |
| POST | anyone | `{"reference_number"? or "consumer_id"?, "inspection_id"?, "type", "description", "severity", "photo_evidence_base64"?}` | report a discrepancy — also creates a `DISCREPANCY`-type row in `notifications` (see Notifications section above) |
| PUT | supervisory only | `?id=9`, any of `{"status":"UNDER_REVIEW"\|"RESOLVED"\|"DISMISSED", "resolution_notes", "assigned_to_user_id"}` | triage / resolve / assign — within the caller's scope |

`type`: `THEFT`, `SLOWNESS`, `DAMAGE`, `TAMPERING`, `ABNORMAL_READING`. `severity`: `LOW`, `MEDIUM`, `HIGH`, `CRITICAL`. Every row includes `assigned_to_user_id`/`assigned_to_name` — the "Assigned M&T worker" per SRS, distinct from `reported_by_name` (who found it) and `resolved_by_name` (who closed it). `assigned_to_user_id` must reference an active user with `role_code = 'MT'`; an SDO may only assign within their own sub-division (same rule as task assignment).

---

## Approval Workflow (spec 3.10 — supervisory roles: SDO / XEN / SE / ADMIN — 🔒🔐)

A submitted inspection moves through a sequential chain of reviews:
**SDO → XEN → SE**. Which levels a given inspection must pass depends on the
consumer's billing category (`B1`–`B4`) — see `approval_rules.php` below.
Each inspection carries `overall_status` (`PENDING_APPROVAL`|`APPROVED`|`REJECTED`)
and `current_approval_level` (`1`=SDO, `2`=XEN, `3`=SE, `0`=fully decided).

### `/api/approvals.php`
| Method | Query / Body | Result |
|---|---|---|
| GET | `?status=PENDING\|APPROVED\|REJECTED&division=&sub_division=&category=&page=&per_page=` | SDO/XEN/SE: `status=PENDING` (default) is **their own review queue** (inspections currently at their level, server-side scoped to their own sub-division(s)/division); `APPROVED`/`REJECTED` shows inspections **they** decided. ADMIN: unscoped to any one level. |
| GET | `?id=42` | single inspection — full readings + `history` (every level decided so far); scoped |
| POST `?action=decide` | `{"inspection_id", "decision":"APPROVE"\|"REJECT", "remarks"?}` | Records the decision at the inspection's current level (must be within the caller's scope). `APPROVE` advances to the next required level (or finalizes `APPROVED` if none remain); `REJECT` finalizes `REJECTED` immediately. |

Only the role matching `current_approval_level` may decide it (`403 FORBIDDEN_ROLE` otherwise); ADMIN may decide on behalf of any level. Deciding an already-finalized inspection returns `409 INSPECTION_ALREADY_FINALIZED`.

On `REJECT`, this also reopens the linked task (any `task_assignments` row with a matching `inspection_id`, unless it's `CANCELLED`) back to `PENDING` in the same transaction — otherwise `inspection-submit` would permanently refuse to attach a new inspection to it (`"This task has already been completed."`), and the M&T would have no way to re-inspect. The response includes `task_reopened` (bool) so a caller can confirm this happened without a separate query. The M&T sees this as their task's "Start Inspection" button reappearing (relabeled "Inspect Again"); see `overall_status`/`rejection` on `inspection-detail` above for how they learn of the rejection itself.

### `/api/admin/approval_rules.php` (ADMIN only)
| Method | Query | Body | Result |
|---|---|---|---|
| GET | — / `?category=B2` | — | all 4 category rules, or one |
| PUT | `?category=B2` | any of `{"requires_sdo","requires_xen","requires_se"}` (bool) | updates that category's required chain — all `false` auto-approves that category on submission |

Default chain (editable any time, no deploy needed): `B1`→SDO only, `B2`→SDO+XEN, `B3`/`B4`→SDO+XEN+SE.

---

## Dashboard & Analytics (spec 3.11 — supervisory roles: SDO / XEN / SE / ADMIN — 🔒🔐)

### GET `/api/dashboard.php?quarter=2026-Q3&division=&sub_division=&category=`
All filters optional (`quarter` defaults to the current one). SDO/XEN are server-side scoped to their own sub-division(s)/division across every section below (see "Geographic Scope Enforcement"). Returns:
```json
{
  "success": true, "quarter": "2026-Q3", "filters": { "division": null, "sub_division": null, "category": null },
  "summary": { "total_scheduled": 40, "total_meters_tested": 31, "pending_inspections": 9, "completed_schedules": 28, "completion_rate_pct": 70.0 },
  "approval_pipeline": { "pending_sdo": 4, "pending_xen": 2, "pending_se": 1, "approved": 22, "rejected": 2 },
  "discrepancy_trends": { "by_type": [{"type":"THEFT","count":3}, …], "by_severity": [ … ], "by_month": [{"month":"2026-07","count":5}, …], "total_open": 6 },
  "team_performance": [ { "user_id": 1, "full_name": "…", "scope_name": "…", "assigned_count": 12, "completed_count": 10, "completion_rate_pct": 83.3, "avg_completion_hours": 26.4 } ]
}
```

---

## Notifications: Escalation Alerts & New-Discrepancy Alerts (supervisory roles: SDO / XEN / SE / ADMIN — 🔒🔐)

Two notification types, both served from `GET /api/alerts.php`:

**1) ESCALATION** — implements: *"if an inspection is not completed within one month, an alert/report is sent to the SDO. If it remains unresolved, it is escalated to the XEN and then the SE."*

No background worker exists in this deployment (plain PHP, single web dyno) — escalation state is computed on every `GET` request rather than by a scheduled job, and upserted idempotently, so it's safe to call as often as the Alerts screen is opened.

**Escalation levels** (only the 1-month/level-1 threshold is specified by the SRS; the 60/90-day follow-on thresholds are this implementation's own assumption for "remains unresolved" — confirm with the client if a different cadence is wanted):
| Level | Recipient | Threshold |
|---|---|---|
| 1 | SDO | ≥ 30 days overdue, not yet COMPLETED/CANCELLED |
| 2 | XEN | ≥ 60 days overdue |
| 3 | SE | ≥ 90 days overdue |

Each role sees only their own level, scoped to their own division/sub-division (same `enforced_scope_sql()` rule as everywhere else — SE/ADMIN unrestricted). ADMIN sees every level, unfiltered.

**2) DISCREPANCY** — implements: *"new discrepancy notifications."* Created directly by `POST /api/discrepancies.php` the moment one is reported (no on-read recompute needed — this is an instant event, not a time-based threshold). **Not** level-gated like ESCALATION — every supervisory role whose scope covers the discrepancy sees it (SDO, XEN, and SE alike), since it's a plain notification, not a graduated hand-off chain.

### GET `/api/alerts.php?unread_only=1&type=ESCALATION|DISCREPANCY`
`type` is optional — omit it to get both kinds merged, newest-unread-first.
```json
{ "success": true, "total": 2, "data": [
  { "id": 1, "type": "ESCALATION", "schedule_id": 40, "discrepancy_id": null, "escalation_level": 1, "days_overdue": 34,
    "message": "Inspection for REF-2025-00142 (...) has not been completed within one month (34 days overdue).",
    "is_read": false, "reference_number": "REF-2025-00142", "meter_id": "...", "consumer_name": "...",
    "scheduled_date": "2026-06-30", "schedule_status": "PENDING", "created_at": "..." },
  { "id": 2, "type": "DISCREPANCY", "schedule_id": null, "discrepancy_id": 7, "escalation_level": null,
    "message": "New high discrepancy reported for REF-2025-00201 (...): Tampering",
    "discrepancy_type": "TAMPERING", "discrepancy_severity": "HIGH", "discrepancy_status": "OPEN",
    "is_read": false, "reference_number": "REF-2025-00201", "meter_id": "...", "consumer_name": "...", "created_at": "..." }
] }
```

### POST `/api/alerts.php?action=mark-read`
```json
{ "id": 1 }
```
`404 NOT_FOUND` if the id doesn't exist or belongs to a level/scope outside the caller's own (e.g. a XEN can't mark an SDO-level ESCALATION alert read; a DISCREPANCY alert can be marked read by any supervisory role in scope).

---

## Consumer/Meter Records (GET: supervisory roles, SDO/XEN scoped — 🔒; writes: ADMIN only — 🔒🔐)

### `/api/admin/consumers.php`
| Method | Who | Query | Body |
|---|---|---|---|
| GET | supervisory roles | `?id=` / `?search=` / `?division=&sub_division=&category=B2` / `?page=&per_page=` | — |
| POST | ADMIN only | — | `reference_number, meter_id, consumer_name, consumer_address, consumer_account, tariff_category, sanctioned_load, division?, sub_division?, category?` |
| PUT | ADMIN only | `?id=3` | any subset of the above |
| DELETE | ADMIN only | `?id=3` | — (hard delete) |

`category` (used by the Scheduling System) is one of `B1`–`B4`. `search` matches reference number, consumer name, or consumer account. SDO/XEN GET requests are server-side scoped to their own sub-division(s)/division — see "Geographic Scope Enforcement" above; `division`/`sub_division` filters can only narrow further within that.

---

## Admin management (ADMIN role only — all 🔒🔐)

### `/api/admin/users.php`
| Method | Query | Body | Result |
|---|---|---|---|
| GET | — | — | paginated list (`?page=`, `?per_page=`) |
| GET | `?id=5` | — | single user |
| POST | — | `employee_id, full_name, username, password, role_code, scope_code, scope_name, contact_number` | create |
| PUT | `?id=5` | any subset of the above (+ `is_active`, `password` to reset) | update |
| DELETE | `?id=5` | — | deactivate (soft delete; also revokes their tokens) |

### `/api/admin/form_options.php`
| Method | Query | Body |
|---|---|---|
| GET | `?id=` / `?dropdown_key=SEAL_CONDITION\|CTPT_BOX` | — |
| POST | — | `dropdown_key, code, label, description, sort_order` |
| PUT | `?id=2` | `label, description, sort_order, is_active` |
| DELETE | `?id=2` | — |

---

## Error shape

Every error response follows:
```json
{ "success": false, "message": "Human readable message.", "error_code": "MACHINE_CODE" }
```
Common `error_code` values: `AUTH_MISSING_TOKEN`, `AUTH_INVALID_TOKEN`, `AUTH_TOKEN_EXPIRED`, `AUTH_ACCOUNT_DISABLED`, `INVALID_CREDENTIALS`, `OTP_INVALID`, `OTP_SESSION_EXPIRED`, `FORBIDDEN_ROLE`, `VALIDATION_ERROR`, `DUPLICATE_USER`, `DUPLICATE_REFERENCE`, `DUPLICATE_OPTION`, `DUPLICATE_SCHEDULE`, `DUPLICATE_INSPECTION`, `IMAGE_VALIDATION_ERROR`, `CONSUMER_NOT_FOUND`, `USER_NOT_FOUND`, `USER_INACTIVE`, `OPTION_NOT_FOUND`, `SCHEDULE_NOT_FOUND`, `TASK_NOT_FOUND`, `DISCREPANCY_NOT_FOUND`, `INSPECTION_NOT_FOUND`, `INSPECTION_ALREADY_FINALIZED`.

---

## Storage note: uploaded photos

Inspection and discrepancy photos are decoded from base64, validated (JPEG/PNG/WEBP, ≤8MB), and written to local disk under `uploads/`, served statically at `/uploads/...` (the `php -S -t .` dev server and Railway's Nixpacks config both serve the whole repo root). **Railway's filesystem is ephemeral** — files written to disk do not survive a redeploy. For production durability, swap `store_base64_image()` in `config/helpers.php` for an S3-compatible object store call; every caller (`data.php`, `discrepancies.php`) goes through that one function, so it's a single-point change.
