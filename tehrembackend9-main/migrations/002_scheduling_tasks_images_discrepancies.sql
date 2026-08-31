-- =============================================================================
-- FILE: migrations/002_scheduling_tasks_images_discrepancies.sql
-- PURPOSE: Upgrades an ALREADY-DEPLOYED database (one that already ran the
-- original schema.sql) to the current schema, without dropping any data.
--
-- Adds: Meter Scheduling (schedules), Task Assignment (task_assignments),
-- Image Capture (inspection_images), Discrepancy Reporting (discrepancies),
-- plus GPS / offline-sync / task-linkage columns on inspections, and
-- division / sub_division / category columns on consumers.
--
-- For a FRESH install, just run schema.sql (already up to date) — you do
-- NOT need to run this file too.
--
-- Requires MySQL 8.0.29+ for "ADD COLUMN IF NOT EXISTS" support. Run once:
--   mysql -h <host> -P <port> -u <user> -p <database> < migrations/002_scheduling_tasks_images_discrepancies.sql
-- =============================================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- 1) consumers: add Scheduling System filter columns
-- -----------------------------------------------------------------------------
ALTER TABLE consumers
    ADD COLUMN IF NOT EXISTS division     VARCHAR(100) NULL AFTER consumer_address,
    ADD COLUMN IF NOT EXISTS sub_division VARCHAR(100) NULL AFTER division,
    ADD COLUMN IF NOT EXISTS category     ENUM('B1','B2','B3','B4') NULL AFTER tariff_category;

ALTER TABLE consumers
    ADD INDEX IF NOT EXISTS idx_consumers_division (division, sub_division),
    ADD INDEX IF NOT EXISTS idx_consumers_category (category);

-- -----------------------------------------------------------------------------
-- 2) inspections: add offline-sync id, GPS capture, and task linkage
-- -----------------------------------------------------------------------------
ALTER TABLE inspections
    ADD COLUMN IF NOT EXISTS client_uuid         VARCHAR(64)   NULL AFTER id,
    ADD COLUMN IF NOT EXISTS gps_latitude        DECIMAL(10,7) NULL AFTER inspection_datetime,
    ADD COLUMN IF NOT EXISTS gps_longitude       DECIMAL(10,7) NULL AFTER gps_latitude,
    ADD COLUMN IF NOT EXISTS gps_accuracy_meters DECIMAL(8,2)  NULL AFTER gps_longitude,
    ADD COLUMN IF NOT EXISTS task_id             INT UNSIGNED  NULL AFTER ctpt_box_status_code;

ALTER TABLE inspections
    ADD UNIQUE INDEX IF NOT EXISTS uq_inspections_client_uuid (client_uuid),
    ADD INDEX IF NOT EXISTS idx_inspections_task (task_id);

-- -----------------------------------------------------------------------------
-- 3) schedules — new table (Meter Scheduling System)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS schedules (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    consumer_id           INT UNSIGNED NOT NULL,
    quarter               VARCHAR(7)   NOT NULL COMMENT 'e.g. "2026-Q3"',
    division              VARCHAR(100) NULL,
    sub_division          VARCHAR(100) NULL,
    category              ENUM('B1','B2','B3','B4') NULL,
    scheduled_date        DATE         NOT NULL,
    status                ENUM('PENDING','ASSIGNED','COMPLETED','CANCELLED') NOT NULL DEFAULT 'PENDING',
    is_manual_override    TINYINT(1)   NOT NULL DEFAULT 0,
    override_reason       VARCHAR(255) NULL,
    generated_by_user_id  INT UNSIGNED NULL,
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
-- 4) task_assignments — new table (Task Assignment)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS task_assignments (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    schedule_id           INT UNSIGNED NULL,
    consumer_id           INT UNSIGNED NOT NULL,
    assigned_to_user_id   INT UNSIGNED NOT NULL,
    assigned_by_user_id   INT UNSIGNED NOT NULL,
    status                ENUM('PENDING','IN_PROGRESS','COMPLETED','CANCELLED') NOT NULL DEFAULT 'PENDING',
    due_date              DATE NULL,
    notes                 VARCHAR(255) NULL,
    inspection_id         INT UNSIGNED NULL,
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
    INDEX idx_tasks_assigned_to (assigned_to_user_id, status),
    INDEX idx_tasks_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Now that task_assignments exists, link inspections.task_id to it.
-- (Wrapped defensively — re-running this file twice would fail on a
-- duplicate constraint name, so only run this migration once per database.)
ALTER TABLE inspections
    ADD CONSTRAINT fk_inspections_task
        FOREIGN KEY (task_id) REFERENCES task_assignments(id) ON DELETE SET NULL;

-- -----------------------------------------------------------------------------
-- 5) inspection_images — new table (Image Capture)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS inspection_images (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inspection_id   INT UNSIGNED NOT NULL,
    image_type      ENUM('METER','SEAL','INSTALLATION','LOAD') NOT NULL,
    file_path       VARCHAR(255) NOT NULL,
    gps_latitude    DECIMAL(10,7) NULL,
    gps_longitude   DECIMAL(10,7) NULL,
    captured_at     DATETIME NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_images_inspection
        FOREIGN KEY (inspection_id) REFERENCES inspections(id) ON DELETE CASCADE,
    INDEX idx_images_inspection (inspection_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 6) discrepancies — new table (Discrepancy Reporting)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS discrepancies (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inspection_id         INT UNSIGNED NULL,
    consumer_id           INT UNSIGNED NULL,
    type                  ENUM('THEFT','SLOWNESS','DAMAGE','TAMPERING','ABNORMAL_READING') NOT NULL,
    description           TEXT NOT NULL,
    severity              ENUM('LOW','MEDIUM','HIGH','CRITICAL') NOT NULL,
    photo_evidence_path   VARCHAR(255) NULL,
    status                ENUM('OPEN','UNDER_REVIEW','RESOLVED','DISMISSED') NOT NULL DEFAULT 'OPEN',
    reported_by_user_id   INT UNSIGNED NOT NULL,
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
    INDEX idx_discrepancies_type (type),
    INDEX idx_discrepancies_severity (severity),
    INDEX idx_discrepancies_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
