-- =============================================================================
-- MIGRATION 005: Three SDO-spec gaps
--   1) notifications: generalized to also carry "new discrepancy" alerts,
--      not just overdue-inspection escalation.
--   2) discrepancies: assigned_to_user_id — "Assigned M&T worker" per SRS.
--   3) task_assignments: auto_reassigned_at / auto_reassigned_from_user_id —
--      tracks the system's own automatic reassignment of stale tasks,
--      distinct from a supervisor's manual reassignment.
-- Run this against an existing database. A fresh install gets all of this
-- from schema.sql directly and does not need this file.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1) notifications — add `type`, make schedule_id nullable, add discrepancy_id
-- -----------------------------------------------------------------------------
ALTER TABLE notifications
    ADD COLUMN type ENUM('ESCALATION', 'DISCREPANCY') NOT NULL DEFAULT 'ESCALATION' AFTER id,
    MODIFY COLUMN schedule_id INT UNSIGNED NULL COMMENT 'Set for type=ESCALATION.',
    ADD COLUMN discrepancy_id INT UNSIGNED NULL COMMENT 'Set for type=DISCREPANCY.' AFTER schedule_id,
    MODIFY COLUMN escalation_level TINYINT UNSIGNED NULL COMMENT '1=SDO alert, 2=XEN escalation, 3=SE escalation. NULL for type=DISCREPANCY.',
    MODIFY COLUMN days_overdue INT UNSIGNED NULL COMMENT 'Set for type=ESCALATION only.',
    ADD CONSTRAINT fk_notifications_discrepancy
        FOREIGN KEY (discrepancy_id) REFERENCES discrepancies(id) ON DELETE CASCADE,
    ADD UNIQUE KEY uq_notifications_discrepancy (discrepancy_id),
    ADD INDEX idx_notifications_type_scope (type, division, sub_division);

-- -----------------------------------------------------------------------------
-- 2) discrepancies — "Assigned M&T worker" (SRS Discrepancy Section)
-- -----------------------------------------------------------------------------
ALTER TABLE discrepancies
    ADD COLUMN assigned_to_user_id INT UNSIGNED NULL COMMENT 'The M&T worker assigned to investigate/resolve this — distinct from who reported it.' AFTER reported_by_user_id,
    ADD CONSTRAINT fk_discrepancies_assigned_to
        FOREIGN KEY (assigned_to_user_id) REFERENCES users(id) ON DELETE SET NULL,
    ADD INDEX idx_discrepancies_assigned_to (assigned_to_user_id);

-- -----------------------------------------------------------------------------
-- 3) task_assignments — automatic reassignment tracking (SRS Schedule section:
--    "Automatically reassigned inspections"). See run_auto_reassignment_sweep()
--    in config/helpers.php for the logic and threshold.
-- -----------------------------------------------------------------------------
ALTER TABLE task_assignments
    ADD COLUMN auto_reassigned_at TIMESTAMP NULL COMMENT 'Set by the system when this task was auto-reassigned for being stale — NULL if never auto-reassigned or if last reassignment was manual.' AFTER assigned_to_user_id,
    ADD COLUMN auto_reassigned_from_user_id INT UNSIGNED NULL COMMENT 'Who it was auto-reassigned away from.' AFTER auto_reassigned_at,
    ADD CONSTRAINT fk_tasks_auto_reassigned_from
        FOREIGN KEY (auto_reassigned_from_user_id) REFERENCES users(id) ON DELETE SET NULL;
