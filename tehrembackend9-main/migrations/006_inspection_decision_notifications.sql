-- =============================================================================
-- FILE: migrations/006_inspection_decision_notifications.sql
-- PURPOSE: Upgrades an ALREADY-DEPLOYED database to add the feedback loop
-- that was missing from the approval workflow: when SDO/XEN/SE approve or
-- reject a submitted inspection, the submitting M&T now gets a targeted
-- notification (in addition to the status badge already shown on their
-- Recent Inspections / Inspection Detail screens). See api/approvals.php
-- (?action=decide) for where these rows get written, and api/alerts.php for
-- how an M&T reads their own.
--
-- For a FRESH install, just run schema.sql (already up to date) — you do
-- NOT need to run this file too.
--
--   mysql -h <host> -P <port> -u <user> -p <database> < migrations/006_inspection_decision_notifications.sql
-- =============================================================================

SET NAMES utf8mb4;

ALTER TABLE notifications
    MODIFY COLUMN type ENUM('ESCALATION', 'DISCREPANCY', 'INSPECTION_DECISION') NOT NULL DEFAULT 'ESCALATION',
    ADD COLUMN inspection_id INT UNSIGNED NULL COMMENT 'Set for type=INSPECTION_DECISION.' AFTER discrepancy_id,
    ADD COLUMN recipient_user_id INT UNSIGNED NULL COMMENT 'Set for type=INSPECTION_DECISION — the M&T who submitted the inspection being decided. NULL for the other two (role/scope-gated) types.' AFTER inspection_id,
    ADD CONSTRAINT fk_notifications_inspection
        FOREIGN KEY (inspection_id) REFERENCES inspections(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_notifications_recipient
        FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE CASCADE,
    ADD INDEX idx_notifications_recipient (recipient_user_id, is_read);
