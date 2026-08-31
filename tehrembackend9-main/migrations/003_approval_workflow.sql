-- =============================================================================
-- FILE: migrations/003_approval_workflow.sql
-- PURPOSE: Upgrades an ALREADY-DEPLOYED database (one that already ran
-- schema.sql + migrations/002_...) to add the multi-level Approval Workflow
-- (spec 3.10: M&T submit -> SDO -> XEN -> SE, chain length configurable per
-- meter category), without dropping any data.
--
-- For a FRESH install, just run schema.sql (already up to date) — you do
-- NOT need to run this file too.
--
-- Requires MySQL 8.0.29+ for "ADD COLUMN IF NOT EXISTS" support. Run once:
--   mysql -h <host> -P <port> -u <user> -p <database> < migrations/003_approval_workflow.sql
-- =============================================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- 1) inspections: add approval-state columns
-- -----------------------------------------------------------------------------
ALTER TABLE inspections
    ADD COLUMN IF NOT EXISTS overall_status         ENUM('PENDING_APPROVAL','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING_APPROVAL' AFTER task_id,
    ADD COLUMN IF NOT EXISTS current_approval_level  TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER overall_status;

ALTER TABLE inspections
    ADD INDEX IF NOT EXISTS idx_inspections_approval (overall_status, current_approval_level);

-- Existing rows predate the workflow — treat anything already on file as
-- already fully approved rather than retroactively blocking it in a review
-- queue nobody expects to see it in.
UPDATE inspections SET overall_status = 'APPROVED', current_approval_level = 0
WHERE overall_status = 'PENDING_APPROVAL';

-- -----------------------------------------------------------------------------
-- 2) approval_workflow_rules — new table (per-category approval chain)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS approval_workflow_rules (
    category        ENUM('B1','B2','B3','B4') NOT NULL PRIMARY KEY,
    requires_sdo    TINYINT(1) NOT NULL DEFAULT 1,
    requires_xen    TINYINT(1) NOT NULL DEFAULT 1,
    requires_se     TINYINT(1) NOT NULL DEFAULT 1,
    updated_at      TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO approval_workflow_rules (category, requires_sdo, requires_xen, requires_se) VALUES
    ('B1', 1, 0, 0),
    ('B2', 1, 1, 0),
    ('B3', 1, 1, 1),
    ('B4', 1, 1, 1);

-- -----------------------------------------------------------------------------
-- 3) inspection_approvals — new table (per-level decision audit trail)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS inspection_approvals (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inspection_id       INT UNSIGNED NOT NULL,
    level               TINYINT UNSIGNED NOT NULL,
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
