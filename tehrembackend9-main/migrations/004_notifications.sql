-- =============================================================================
-- FILE: migrations/004_notifications.sql
-- PURPOSE: Upgrades an ALREADY-DEPLOYED database (one that already ran
-- schema.sql + migrations/002.../003...) to add the `notifications` table
-- backing the overdue-inspection escalation chain (SDO -> XEN -> SE). See
-- api/alerts.php for how rows here get computed.
--
-- For a FRESH install, just run schema.sql (already up to date) — you do
-- NOT need to run this file too.
--
--   mysql -h <host> -P <port> -u <user> -p <database> < migrations/004_notifications.sql
-- =============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS notifications (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    schedule_id       INT UNSIGNED NOT NULL,
    escalation_level  TINYINT UNSIGNED NOT NULL COMMENT '1=SDO alert, 2=XEN escalation, 3=SE escalation.',
    division          VARCHAR(100) NULL,
    sub_division      VARCHAR(100) NULL,
    days_overdue      INT UNSIGNED NOT NULL,
    message           VARCHAR(255) NOT NULL,
    is_read           TINYINT(1) NOT NULL DEFAULT 0,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_notifications_schedule
        FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE CASCADE,
    UNIQUE KEY uq_notifications_schedule_level (schedule_id, escalation_level),
    INDEX idx_notifications_scope (escalation_level, division, sub_division)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
