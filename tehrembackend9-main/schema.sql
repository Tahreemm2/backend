-- =============================================================================
-- FILE: schema.sql
-- PROJECT: MEPCO LT/HT TOU Meter Testing — Backend Database Schema
-- ENGINE: MySQL 8.0+ (compatible with Railway MySQL plugin)
--
-- This schema was reverse-engineered directly from the Flutter frontend
-- (MNT-main) data contracts, specifically:
--   - lib/core/models/user_model.dart          -> users / auth_tokens
--   - lib/features/inspection/config/inspection_config.dart
--        -> consumers, form_options
--   - lib/features/inspection/bloc/inspection_models.dart -> inspections
--
-- Import with:
--   mysql -h <host> -P <port> -u <user> -p <database> < schema.sql
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- -----------------------------------------------------------------------------
-- TABLE: users
-- Mirrors UserModel.fromJson() field-for-field (role_code / scope_code enums
-- match UserRoleExtension.fromCode() and GeographicScopeExtension.fromCode()).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id       VARCHAR(20)  NOT NULL UNIQUE,
    full_name         VARCHAR(150) NOT NULL,
    username          VARCHAR(100) NOT NULL UNIQUE,
    password_hash     VARCHAR(255) NOT NULL,
    role_code         ENUM('MT','SDO','XEN','SE','ADMIN') NOT NULL,
    scope_code        ENUM('SUB_DIVISION','DIVISION','CIRCLE','REGION','NATIONAL') NOT NULL,
    scope_name        VARCHAR(150) NOT NULL,
    contact_number    VARCHAR(20)  NULL COMMENT 'Raw phone number, e.g. 03001234567. Masked at API output time.',
    is_first_login    TINYINT(1)   NOT NULL DEFAULT 0,
    is_active         TINYINT(1)   NOT NULL DEFAULT 1,

    -- OTP verification workflow (first-time login)
    otp_code          VARCHAR(6)   NULL,
    otp_expires_at    DATETIME     NULL,
    otp_temp_token    VARCHAR(128) NULL UNIQUE COMMENT 'Short-lived handle issued at login, required to verify/resend OTP.',
    otp_temp_expires_at DATETIME   NULL,

    created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- TABLE: auth_tokens
-- Bearer tokens issued after successful login (or OTP verification).
-- Sent back to the app as UserModel.token and required on every
-- protected api/data.php call via "Authorization: Bearer <token>".
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS auth_tokens (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    token       VARCHAR(128) NOT NULL UNIQUE,
    expires_at  DATETIME NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_auth_tokens_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_auth_tokens_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- TABLE: consumers
-- Backs the "Auto-Fetch Data" feature (ConsumerFetchResult in
-- inspection_config.dart / kMockConsumerDatabase). Looked up by reference_number.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS consumers (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference_number  VARCHAR(50)  NOT NULL UNIQUE,
    meter_id          VARCHAR(50)  NOT NULL,
    consumer_name     VARCHAR(150) NOT NULL,
    consumer_address  VARCHAR(255) NOT NULL,
    division          VARCHAR(100) NULL COMMENT 'Used as a Scheduling System filter.',
    sub_division      VARCHAR(100) NULL COMMENT 'Used as a Scheduling System filter.',
    consumer_account  VARCHAR(50)  NOT NULL,
    tariff_category   VARCHAR(50)  NOT NULL,
    category          ENUM('B1','B2','B3','B4') NULL COMMENT 'Billing category used as a Scheduling System filter (distinct from the free-text tariff_category).',
    sanctioned_load   VARCHAR(50)  NOT NULL,
    created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_consumers_reference (reference_number),
    INDEX idx_consumers_division (division, sub_division),
    INDEX idx_consumers_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- TABLE: form_options
-- Backs FormOptionsConfig (seal_conditions / ctpt_box_statuses dropdowns),
-- so options can be edited by an admin without a mobile app release.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS form_options (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dropdown_key  ENUM('SEAL_CONDITION','CTPT_BOX') NOT NULL,
    code          VARCHAR(30)  NOT NULL,
    label         VARCHAR(100) NOT NULL,
    description   VARCHAR(255) NULL,
    sort_order    INT UNSIGNED NOT NULL DEFAULT 0,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    UNIQUE KEY uq_dropdown_code (dropdown_key, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- TABLE: inspections
-- Backs InspectionSubmissionPayload.toJson() — POST /api/data.php?action=inspection-submit
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS inspections (
    id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_uuid            VARCHAR(64)  NULL COMMENT 'Client-generated UUID, used for idempotent offline sync (conflict resolution: re-submitting the same client_uuid returns the original record instead of erroring/duplicating).',
    reference_number       VARCHAR(50)  NOT NULL,
    meter_id               VARCHAR(50)  NOT NULL,
    consumer_account       VARCHAR(50)  NOT NULL,
    inspection_datetime    DATETIME     NOT NULL,

    gps_latitude           DECIMAL(10,7) NULL COMMENT 'Auto-captured at submission time; mandatory field per Field Data Collection spec.',
    gps_longitude          DECIMAL(10,7) NULL,
    gps_accuracy_meters    DECIMAL(8,2)  NULL COMMENT 'Device-reported location accuracy; validated against GPS_MAX_ACCURACY_METERS.',

    kwh                    DECIMAL(12,2) NOT NULL,
    kvarh                  DECIMAL(12,2) NOT NULL,
    mdi                    DECIMAL(12,2) NOT NULL,
    load_details           TEXT NULL,

    tou_peak               DECIMAL(12,2) NULL,
    tou_off_peak           DECIMAL(12,2) NULL,
    tou_day                DECIMAL(12,2) NULL,
    tou_night              DECIMAL(12,2) NULL,

    seal_condition_code    VARCHAR(20) NOT NULL,
    ctpt_box_status_code   VARCHAR(20) NOT NULL,

    task_id                INT UNSIGNED NULL COMMENT 'Set when this inspection was submitted against an assigned task (Task Assignment feature); completing it auto-marks the task COMPLETED.',

    overall_status          ENUM('PENDING_APPROVAL','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING_APPROVAL' COMMENT 'Approval Workflow (3.10) outcome. Set to APPROVED at insert time if the consumer''s category requires no review levels.',
    current_approval_level  TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1=SDO, 2=XEN, 3=SE (see APPROVAL_LEVEL_ROLES); 0 once overall_status is APPROVED or the terminal level at which it was REJECTED remains for audit.',

    submitted_by_user_id   INT UNSIGNED NOT NULL,
    created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_inspections_user
        FOREIGN KEY (submitted_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    UNIQUE KEY uq_inspections_client_uuid (client_uuid),
    INDEX idx_inspections_reference (reference_number),
    INDEX idx_inspections_created (created_at),
    INDEX idx_inspections_task (task_id),
    INDEX idx_inspections_approval (overall_status, current_approval_level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- TABLE: schedules
-- Backs the Meter Scheduling System (3.2): auto-generated quarterly inspection
-- schedules, filterable by Division / Sub-division / Category (B1-B4), with
-- support for manual admin override of any auto-generated entry.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS schedules (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    consumer_id           INT UNSIGNED NOT NULL,
    quarter               VARCHAR(7)   NOT NULL COMMENT 'e.g. "2026-Q3"',
    division              VARCHAR(100) NULL COMMENT 'Denormalized copy of consumers.division at generation time, for fast filtering.',
    sub_division          VARCHAR(100) NULL,
    category              ENUM('B1','B2','B3','B4') NULL,
    scheduled_date        DATE         NOT NULL,
    status                ENUM('PENDING','ASSIGNED','COMPLETED','CANCELLED') NOT NULL DEFAULT 'PENDING',
    is_manual_override    TINYINT(1)   NOT NULL DEFAULT 0,
    override_reason       VARCHAR(255) NULL,
    generated_by_user_id  INT UNSIGNED NULL COMMENT 'Admin/supervisor who triggered generation or made the manual override.',
    created_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_schedules_consumer
        FOREIGN KEY (consumer_id) REFERENCES consumers(id) ON DELETE CASCADE,
    CONSTRAINT fk_schedules_generated_by
        FOREIGN KEY (generated_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_schedule_consumer_quarter (consumer_id, quarter),
    INDEX idx_schedules_quarter (quarter),
    INDEX idx_schedules_filters (division, sub_division, category),
    INDEX idx_schedules_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- TABLE: task_assignments
-- Backs Task Assignment (3.3): assigning a scheduled (or ad-hoc) meter
-- inspection to a specific field team member, and tracking its lifecycle
-- from PENDING -> IN_PROGRESS -> COMPLETED.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS task_assignments (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    schedule_id           INT UNSIGNED NULL COMMENT 'NULL for ad-hoc assignments not tied to the quarterly schedule.',
    consumer_id           INT UNSIGNED NOT NULL,
    assigned_to_user_id   INT UNSIGNED NOT NULL COMMENT 'The field team member (typically role MT) responsible for the visit.',
    auto_reassigned_at            TIMESTAMP NULL COMMENT 'Set by the system when this task was auto-reassigned for being stale — NULL if never auto-reassigned or if last reassignment was manual. See run_auto_reassignment_sweep() in config/helpers.php.',
    auto_reassigned_from_user_id  INT UNSIGNED NULL COMMENT 'Who it was auto-reassigned away from.',
    assigned_by_user_id   INT UNSIGNED NOT NULL,
    status                ENUM('PENDING','IN_PROGRESS','COMPLETED','CANCELLED') NOT NULL DEFAULT 'PENDING',
    due_date              DATE NULL,
    notes                 VARCHAR(255) NULL,
    inspection_id         INT UNSIGNED NULL COMMENT 'Set once the field team submits the inspection that completes this task.',
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_tasks_schedule
        FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE SET NULL,
    CONSTRAINT fk_tasks_consumer
        FOREIGN KEY (consumer_id) REFERENCES consumers(id) ON DELETE CASCADE,
    CONSTRAINT fk_tasks_assigned_to
        FOREIGN KEY (assigned_to_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_tasks_assigned_by
        FOREIGN KEY (assigned_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_tasks_inspection
        FOREIGN KEY (inspection_id) REFERENCES inspections(id) ON DELETE SET NULL,
    CONSTRAINT fk_tasks_auto_reassigned_from
        FOREIGN KEY (auto_reassigned_from_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_tasks_assigned_to (assigned_to_user_id, status),
    INDEX idx_tasks_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE inspections
    ADD CONSTRAINT fk_inspections_task
        FOREIGN KEY (task_id) REFERENCES task_assignments(id) ON DELETE SET NULL;

-- -----------------------------------------------------------------------------
-- TABLE: inspection_images
-- Backs Image Capture (3.5): 2-12 geo-tagged, timestamped photos per
-- inspection (Meter / Seals / Installation / Load). Files are stored on
-- local disk under uploads/inspections/ and served statically (nixpacks.toml
-- runs `php -S -t .`, so /uploads/... is publicly reachable at that path).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS inspection_images (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inspection_id   INT UNSIGNED NOT NULL,
    image_type      ENUM('METER','SEAL','INSTALLATION','LOAD') NOT NULL,
    file_path       VARCHAR(255) NOT NULL COMMENT 'Relative path under uploads/, e.g. inspections/42/a1b2c3.jpg',
    gps_latitude    DECIMAL(10,7) NULL,
    gps_longitude   DECIMAL(10,7) NULL,
    captured_at     DATETIME NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_images_inspection
        FOREIGN KEY (inspection_id) REFERENCES inspections(id) ON DELETE CASCADE,
    INDEX idx_images_inspection (inspection_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- TABLE: discrepancies
-- Backs Discrepancy Reporting (3.8): theft/slowness/damage/tampering/abnormal
-- readings flagged during (or independently of) an inspection, each with a
-- description, severity level, and photo evidence.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS discrepancies (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inspection_id         INT UNSIGNED NULL COMMENT 'Optional link to the inspection visit during which this was found.',
    consumer_id           INT UNSIGNED NULL,
    type                  ENUM('THEFT','SLOWNESS','DAMAGE','TAMPERING','ABNORMAL_READING') NOT NULL,
    description           TEXT NOT NULL,
    severity              ENUM('LOW','MEDIUM','HIGH','CRITICAL') NOT NULL,
    photo_evidence_path   VARCHAR(255) NULL,
    status                ENUM('OPEN','UNDER_REVIEW','RESOLVED','DISMISSED') NOT NULL DEFAULT 'OPEN',
    reported_by_user_id   INT UNSIGNED NOT NULL,
    assigned_to_user_id   INT UNSIGNED NULL COMMENT 'The M&T worker assigned to investigate/resolve this — distinct from who reported it.',
    resolved_by_user_id   INT UNSIGNED NULL,
    resolution_notes      VARCHAR(255) NULL,
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_discrepancies_inspection
        FOREIGN KEY (inspection_id) REFERENCES inspections(id) ON DELETE SET NULL,
    CONSTRAINT fk_discrepancies_consumer
        FOREIGN KEY (consumer_id) REFERENCES consumers(id) ON DELETE SET NULL,
    CONSTRAINT fk_discrepancies_reporter
        FOREIGN KEY (reported_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_discrepancies_resolver
        FOREIGN KEY (resolved_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_discrepancies_assigned_to
        FOREIGN KEY (assigned_to_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_discrepancies_type (type),
    INDEX idx_discrepancies_severity (severity),
    INDEX idx_discrepancies_status (status),
    INDEX idx_discrepancies_assigned_to (assigned_to_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- TABLE: approval_workflow_rules
-- Backs the Approval Workflow (3.10): which of the SDO / XEN / SE review
-- levels a meter category must pass through before an inspection counts as
-- APPROVED ("Workflow depends on meter category"). One row per category,
-- admin-editable via api/admin/approval_rules.php — no code change needed
-- to retune the chain. A category with all three flags 0 is auto-approved
-- on submission (no manual review required).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS approval_workflow_rules (
    category        ENUM('B1','B2','B3','B4') NOT NULL PRIMARY KEY,
    requires_sdo    TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Level 1 of the review chain.',
    requires_xen    TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Level 2 of the review chain.',
    requires_se     TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Level 3 of the review chain.',
    updated_at      TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sensible production defaults (not demo data — required for the Approval
-- Workflow to function at all, so this ships in schema.sql rather than
-- seed.sql): lower-value LT categories need fewer sign-offs, HT/high-load
-- categories go through the full chain. Adjust anytime via the admin API.
INSERT IGNORE INTO approval_workflow_rules (category, requires_sdo, requires_xen, requires_se) VALUES
    ('B1', 1, 0, 0),
    ('B2', 1, 1, 0),
    ('B3', 1, 1, 1),
    ('B4', 1, 1, 1);

-- -----------------------------------------------------------------------------
-- TABLE: inspection_approvals
-- Audit trail for the Approval Workflow (3.10): one row per level decided
-- (M&T's own submission is the implicit Level 1 "Submit" and is not
-- recorded here — this table starts at the first supervisory review level).
-- inspections.overall_status / current_approval_level always reflect the
-- latest state; this table is the immutable history behind it.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS inspection_approvals (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inspection_id       INT UNSIGNED NOT NULL,
    level               TINYINT UNSIGNED NOT NULL COMMENT '1=SDO, 2=XEN, 3=SE.',
    role_code           ENUM('SDO','XEN','SE') NOT NULL,
    action              ENUM('APPROVED','REJECTED') NOT NULL,
    approver_user_id    INT UNSIGNED NOT NULL,
    remarks             VARCHAR(255) NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_approvals_inspection
        FOREIGN KEY (inspection_id) REFERENCES inspections(id) ON DELETE CASCADE,
    CONSTRAINT fk_approvals_approver
        FOREIGN KEY (approver_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    UNIQUE KEY uq_approvals_inspection_level (inspection_id, level),
    INDEX idx_approvals_inspection (inspection_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- TABLE: notifications
-- Backs the overdue-inspection escalation chain: "if an inspection is not
-- completed within one month, an alert/report is sent to the SDO. If it
-- remains unresolved, it is escalated to the XEN and then the SE."
--
-- There is no background job in this deployment (plain PHP on a single
-- Railway web dyno, no queue/cron worker) — rows here are computed and
-- upserted on-demand by GET /api/alerts.php whenever ANY supervisory user
-- opens their alerts, using the UNIQUE KEY below for idempotency (recompute
-- never creates duplicates, only fills in newly-crossed thresholds). See
-- api/alerts.php for the exact day thresholds (30 / 60 / 90 days overdue).
-- -----------------------------------------------------------------------------
-- type=INSPECTION_DECISION rows (added alongside ESCALATION/DISCREPANCY) are
-- how a submitting M&T learns their inspection was approved or rejected by
-- SDO/XEN/SE (spec 3.10 feedback loop) — targeted at one specific person via
-- recipient_user_id rather than scoped by division/sub_division like the
-- other two types, since only the submitter should see it. Written by
-- api/approvals.php?action=decide at the moment a decision is recorded, and
-- read back via GET /api/alerts.php (which an M&T caller may now also call,
-- scoped to their own recipient_user_id rows only — see that file).
CREATE TABLE IF NOT EXISTS notifications (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type              ENUM('ESCALATION', 'DISCREPANCY', 'INSPECTION_DECISION') NOT NULL DEFAULT 'ESCALATION',
    schedule_id       INT UNSIGNED NULL COMMENT 'Set for type=ESCALATION.',
    discrepancy_id    INT UNSIGNED NULL COMMENT 'Set for type=DISCREPANCY.',
    inspection_id     INT UNSIGNED NULL COMMENT 'Set for type=INSPECTION_DECISION.',
    recipient_user_id INT UNSIGNED NULL COMMENT 'Set for type=INSPECTION_DECISION — the M&T who submitted the inspection being decided. NULL for the other two (role/scope-gated) types.',
    escalation_level  TINYINT UNSIGNED NULL COMMENT '1=SDO alert, 2=XEN escalation, 3=SE escalation. NULL for type=DISCREPANCY / INSPECTION_DECISION.',
    division          VARCHAR(100) NULL COMMENT 'Denormalized from schedules/consumers, for scoped filtering without a join.',
    sub_division      VARCHAR(100) NULL,
    days_overdue      INT UNSIGNED NULL COMMENT 'Set for type=ESCALATION only.',
    message           VARCHAR(255) NOT NULL,
    is_read           TINYINT(1) NOT NULL DEFAULT 0,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_notifications_schedule
        FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE CASCADE,
    CONSTRAINT fk_notifications_discrepancy
        FOREIGN KEY (discrepancy_id) REFERENCES discrepancies(id) ON DELETE CASCADE,
    CONSTRAINT fk_notifications_inspection
        FOREIGN KEY (inspection_id) REFERENCES inspections(id) ON DELETE CASCADE,
    CONSTRAINT fk_notifications_recipient
        FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_notifications_schedule_level (schedule_id, escalation_level),
    UNIQUE KEY uq_notifications_discrepancy (discrepancy_id),
    INDEX idx_notifications_scope (escalation_level, division, sub_division),
    INDEX idx_notifications_type_scope (type, division, sub_division),
    INDEX idx_notifications_recipient (recipient_user_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- SEED DATA
-- Development/demo seed data (mock users, consumers, dropdown options) now
-- lives in a separate file: seed.sql — run it AFTER this file, and only in
-- local/dev/staging. This file (schema.sql) is pure DDL and is safe to run
-- against a production database with no risk of inserting placeholder
-- accounts or demo records.
--
--   mysql -h <host> -P <port> -u <user> -p <database> < schema.sql
--   mysql -h <host> -P <port> -u <user> -p <database> < seed.sql   -- dev/staging only
-- =============================================================================
