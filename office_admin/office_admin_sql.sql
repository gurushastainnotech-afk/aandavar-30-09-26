-- ============================================================
-- office_admin_sql.sql
-- Run these statements once against your database to ensure
-- all columns required by the office_admin module exist.
-- All statements use IF NOT EXISTS / IGNORE patterns so they
-- are safe to re-run.
-- ============================================================

-- 1. Ensure submissions.status is VARCHAR (not ENUM) to support custom statuses
ALTER TABLE submissions
    MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'enquiry';

-- 2. Ensure other_status_reason column exists (for 'others' status notes)
ALTER TABLE submissions
    ADD COLUMN IF NOT EXISTS other_status_reason TEXT NULL AFTER status;

-- 3. Ensure last_edited_by column exists (links to users.id for "Edited by" display on reports)
ALTER TABLE submissions
    ADD COLUMN IF NOT EXISTS last_edited_by INT UNSIGNED NULL AFTER last_edited_at;

-- 4. Ensure last_edited_at column exists
ALTER TABLE submissions
    ADD COLUMN IF NOT EXISTS last_edited_at DATETIME NULL;

-- 5. Ensure next_visit_date column exists
ALTER TABLE submissions
    ADD COLUMN IF NOT EXISTS next_visit_date DATE NULL;

-- 6. Ensure purchase_date column exists
ALTER TABLE submissions
    ADD COLUMN IF NOT EXISTS purchase_date DATE NULL;

-- 7. Ensure order_completed column exists (flag set when status = 'billed')
ALTER TABLE submissions
    ADD COLUMN IF NOT EXISTS order_completed TINYINT(1) NOT NULL DEFAULT 0;

-- 8. Ensure the edit_unlock_requests table exists
CREATE TABLE IF NOT EXISTS edit_unlock_requests (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    submission_id INT UNSIGNED NOT NULL,
    requested_by  INT UNSIGNED NOT NULL,
    reason        TEXT NOT NULL,
    status        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    reviewed_by   INT UNSIGNED NULL,
    reviewed_at   DATETIME NULL,
    reject_note   TEXT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_eur_sub    (submission_id),
    KEY idx_eur_req    (requested_by),
    KEY idx_eur_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 9. Ensure submission_status_details.status is VARCHAR
ALTER TABLE submission_status_details
    MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'enquiry';

-- ============================================================
-- Summary of columns added / ensured:
--   last_edited_by      : used for "Edited by" display on all report cards
--   other_status_reason : free-text reason field for 'others' status
--   next_visit_date     : used for overdue/upcoming visit tracking
--   order_completed     : flag set to 1 when billed; controls UI lock
--   purchase_date       : recorded when order is marked billed
-- ============================================================
