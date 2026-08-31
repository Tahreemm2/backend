-- =============================================================================
-- FILE: seed.sql
-- PROJECT: MEPCO LT/HT TOU Meter Testing — Development / Demo Seed Data
--
-- Run this AFTER schema.sql, and ONLY in local/dev/staging environments.
-- Do NOT run this against a real production database — it inserts
-- placeholder accounts (including an "admin" login) with a known
-- shared password.
--
-- Mirrors the mock data that already exists in the Flutter app so the
-- app keeps working identically once pointed at the real API:
--   - lib/features/auth/bloc/auth_bloc.dart          -> users below
--   - lib/features/inspection/config/inspection_config.dart
--        -> consumers / form_options below
--
-- All seeded users share the password: test1234
-- The hash below is a real, verified bcrypt hash of "test1234" (cost 10).
-- CHANGE ALL PASSWORDS before using this data outside of local development.
--
-- Usage:
--   mysql -h <host> -P <port> -u <user> -p <database> < schema.sql
--   mysql -h <host> -P <port> -u <user> -p <database> < seed.sql
-- =============================================================================

SET NAMES utf8mb4;

INSERT INTO users
    (employee_id, full_name, username, password_hash, role_code, scope_code, scope_name, contact_number, is_first_login)
VALUES
    ('EMP-1042', 'Ghulam Mustafa',        'g.mustafa',     '$2b$10$Z5MAuON9tEQjZmOaTDBWcOx0a9dqQScu1KKFGyD3oCvAaoJvK6.my', 'MT',    'SUB_DIVISION', 'Multan North Sub-Division',       '03001234892', 0),
    ('EMP-2015', 'Zulfiqar Ahmed',        'z.ahmed.sdo',   '$2b$10$Z5MAuON9tEQjZmOaTDBWcOx0a9dqQScu1KKFGyD3oCvAaoJvK6.my', 'SDO',   'SUB_DIVISION', 'Bahawalpur East Sub-Division',    '03011115543', 0),
    ('EMP-3007', 'Arshad Mehmood',        'a.mehmood.xen', '$2b$10$Z5MAuON9tEQjZmOaTDBWcOx0a9dqQScu1KKFGyD3oCvAaoJvK6.my', 'XEN',   'DIVISION',     'Multan Division',                 '03021231221', 0),
    ('EMP-4001', 'Tariq Hussain Malik',   't.malik.se',    '$2b$10$Z5MAuON9tEQjZmOaTDBWcOx0a9dqQScu1KKFGyD3oCvAaoJvK6.my', 'SE',    'CIRCLE',       'Multan Circle',                    '03031110098', 0),
    ('EMP-9999', 'System Administrator',  'admin',         '$2b$10$Z5MAuON9tEQjZmOaTDBWcOx0a9dqQScu1KKFGyD3oCvAaoJvK6.my', 'ADMIN', 'NATIONAL',     'National (All Regions)',          '03041110001', 1),
    ('EMP-1099', 'Imran Farooq',          'i.farooq.new',  '$2b$10$Z5MAuON9tEQjZmOaTDBWcOx0a9dqQScu1KKFGyD3oCvAaoJvK6.my', 'MT',    'SUB_DIVISION', 'Dera Ghazi Khan Sub-Division',    '03051116634', 1);

INSERT INTO consumers
    (reference_number, meter_id, consumer_name, consumer_address, division, sub_division, consumer_account, tariff_category, category, sanctioned_load)
VALUES
    ('REF-2025-00142', 'MTR-LHR-2024-00987', 'Haji Textile Mills (Pvt) Ltd.',        'Plot 14-B, SITE Area, Lahore',      'Lahore Division',      'Lahore North Sub-Division',  'LHR-04-2200-1429', 'Industrial B-2',       'B2', '250 kW'),
    ('REF-2025-00891', 'MTR-MUL-2023-03341', 'Punjab Agricultural Tube Well Scheme', 'Chak 42/ML, Multan District',       'Multan Division',      'Multan North Sub-Division',  'MUL-07-1100-0042', 'Agricultural A-2',     'B1', '50 kW'),
    ('REF-2025-01203', 'MTR-FSD-2022-07715', 'Faisalabad Municipal Corporation',     'Civil Lines, Faisalabad',           'Faisalabad Division',  'Faisalabad City Sub-Division','FSD-01-3300-0080', 'Commercial C-1',       'B3', '100 kW'),
    ('REF-2025-00057', 'MTR-BWP-2024-01122', 'Ibrahim Flour Mills',                  'GT Road, Bahawalpur',               'Bahawalpur Division',  'Bahawalpur East Sub-Division','BWP-09-4400-0320', 'Industrial B-3 (TOU)', 'B4', '500 kW');

INSERT INTO form_options (dropdown_key, code, label, description, sort_order) VALUES
    ('SEAL_CONDITION', 'INTACT',   'Intact',   'All seals are present and undamaged.',        1),
    ('SEAL_CONDITION', 'BROKEN',   'Broken',   'One or more seals are physically broken.',     2),
    ('SEAL_CONDITION', 'TAMPERED', 'Tampered', 'Evidence of tampering or unauthorized access.',3),
    ('SEAL_CONDITION', 'MISSING',  'Missing',  'Seals are absent entirely.',                   4),
    ('CTPT_BOX', 'SECURED',    'Secured',    'Box is properly locked and undamaged.',              1),
    ('CTPT_BOX', 'ACCESSIBLE', 'Accessible', 'Box is unlocked but otherwise intact.',              2),
    ('CTPT_BOX', 'TAMPERED',   'Tampered',   'Box shows signs of forced or unauthorized access.',  3),
    ('CTPT_BOX', 'DAMAGED',    'Damaged',    'Box is physically damaged or broken.',               4);

-- -----------------------------------------------------------------------------
-- Meter Scheduling System + Task Assignment demo data
-- One schedule entry per consumer for the current quarter, one of them
-- already assigned as a task to the seeded MT user (g.mustafa, EMP-1042).
-- -----------------------------------------------------------------------------
INSERT INTO schedules (consumer_id, quarter, division, sub_division, category, scheduled_date, status, generated_by_user_id)
SELECT
    c.id,
    CONCAT(YEAR(CURDATE()), '-Q', QUARTER(CURDATE())),
    c.division, c.sub_division, c.category,
    DATE_ADD(CURDATE(), INTERVAL (c.id * 5) DAY),
    'PENDING',
    (SELECT id FROM users WHERE username = 'admin' LIMIT 1)
FROM consumers c;

UPDATE schedules s
INNER JOIN consumers c ON c.id = s.consumer_id
SET s.status = 'ASSIGNED'
WHERE c.reference_number = 'REF-2025-00142';

INSERT INTO task_assignments (schedule_id, consumer_id, assigned_to_user_id, assigned_by_user_id, status, due_date, notes)
SELECT
    s.id, s.consumer_id,
    (SELECT id FROM users WHERE username = 'g.mustafa' LIMIT 1),
    (SELECT id FROM users WHERE username = 'z.ahmed.sdo' LIMIT 1),
    'PENDING',
    DATE_ADD(CURDATE(), INTERVAL 7 DAY),
    'Routine quarterly TOU meter check.'
FROM schedules s
INNER JOIN consumers c ON c.id = s.consumer_id
WHERE c.reference_number = 'REF-2025-00142';
