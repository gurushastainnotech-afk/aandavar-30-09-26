-- Office Admin migration: status history, multi-bills, overdue close.
-- Compatible with MySQL 5.7/8.x and MariaDB. Run after the base schema.

SET @db = DATABASE();

ALTER TABLE submissions MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'enquiry';
ALTER TABLE submission_status_details MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'enquiry';

CREATE TABLE IF NOT EXISTS submission_bills (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    submission_id INT UNSIGNED NOT NULL,
    bill_no INT UNSIGNED NOT NULL,
    billed_date DATE NULL,
    purchase_notes TEXT NULL,
    detail_description TEXT NULL,
    vendor_name VARCHAR(255) NOT NULL DEFAULT '',
    vendor_details TEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_submission_bill (submission_id, bill_no),
    KEY idx_bills_submission (submission_id),
    KEY idx_bills_date (billed_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS submission_status_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    submission_id INT UNSIGNED NOT NULL,
    status VARCHAR(50) NOT NULL,
    detail_description TEXT NULL,
    vendor_name VARCHAR(255) NOT NULL DEFAULT '',
    vendor_details TEXT NULL,
    other_reason TEXT NULL,
    purchase_date DATE NULL,
    purchase_notes TEXT NULL,
    next_visit_date DATE NULL,
    recorded_by INT UNSIGNED NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_status_history_submission (submission_id),
    KEY idx_status_history_date (recorded_at),
    KEY idx_status_history_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add columns only when missing. This avoids ADD COLUMN IF NOT EXISTS,
-- which is unsupported by some MySQL versions.
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='submissions' AND COLUMN_NAME='other_status_reason')=0,'ALTER TABLE submissions ADD COLUMN other_status_reason TEXT NULL AFTER status','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='submissions' AND COLUMN_NAME='purchase_date')=0,'ALTER TABLE submissions ADD COLUMN purchase_date DATE NULL','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='submissions' AND COLUMN_NAME='purchase_notes')=0,'ALTER TABLE submissions ADD COLUMN purchase_notes TEXT NULL AFTER purchase_date','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='submissions' AND COLUMN_NAME='order_completed')=0,'ALTER TABLE submissions ADD COLUMN order_completed TINYINT(1) NOT NULL DEFAULT 0','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='submissions' AND COLUMN_NAME='bill_count')=0,'ALTER TABLE submissions ADD COLUMN bill_count INT UNSIGNED NOT NULL DEFAULT 0','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='submissions' AND COLUMN_NAME='next_visit_date')=0,'ALTER TABLE submissions ADD COLUMN next_visit_date DATE NULL','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='submissions' AND COLUMN_NAME='overdue_closed_at')=0,'ALTER TABLE submissions ADD COLUMN overdue_closed_at DATETIME NULL','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='submissions' AND COLUMN_NAME='alt_mobile')=0,'ALTER TABLE submissions ADD COLUMN alt_mobile VARCHAR(20) NULL','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='submissions' AND COLUMN_NAME='last_edited_at')=0,'ALTER TABLE submissions ADD COLUMN last_edited_at DATETIME NULL','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='submissions' AND COLUMN_NAME='last_edited_by')=0,'ALTER TABLE submissions ADD COLUMN last_edited_by INT UNSIGNED NULL','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='submission_areas' AND COLUMN_NAME='bill_no')=0,'ALTER TABLE submission_areas ADD COLUMN bill_no INT UNSIGNED NOT NULL DEFAULT 1','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Add indexes only when missing.
SET @sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='submissions' AND INDEX_NAME='idx_sub_status')=0,'ALTER TABLE submissions ADD INDEX idx_sub_status (status)','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='submissions' AND INDEX_NAME='idx_sub_overdue')=0,'ALTER TABLE submissions ADD INDEX idx_sub_overdue (status,next_visit_date,overdue_closed_at)','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='submission_areas' AND INDEX_NAME='idx_area_bill_no')=0,'ALTER TABLE submission_areas ADD INDEX idx_area_bill_no (submission_id,bill_no)','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='submission_status_history' AND INDEX_NAME='idx_status_history_submission_status_date')=0,'ALTER TABLE submission_status_history ADD INDEX idx_status_history_submission_status_date (submission_id,status,recorded_at)','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Backfill existing records once.
INSERT INTO submission_status_history
    (submission_id,status,detail_description,vendor_name,vendor_details,other_reason,purchase_date,purchase_notes,next_visit_date,recorded_by,recorded_at)
SELECT s.id,s.status,COALESCE(d.detail_description,''),COALESCE(d.vendor_name,''),d.vendor_details,
       COALESCE(d.other_reason,s.other_status_reason),s.purchase_date,s.purchase_notes,s.next_visit_date,
       s.last_edited_by,COALESCE(s.last_edited_at,s.submitted_at)
FROM submissions s
LEFT JOIN submission_status_details d ON d.submission_id=s.id
WHERE NOT EXISTS (SELECT 1 FROM submission_status_history h WHERE h.submission_id=s.id);

INSERT IGNORE INTO submission_bills
    (submission_id,bill_no,billed_date,purchase_notes,detail_description,vendor_name,vendor_details,created_by)
SELECT s.id,1,s.purchase_date,s.purchase_notes,COALESCE(d.detail_description,''),COALESCE(d.vendor_name,''),d.vendor_details,s.last_edited_by
FROM submissions s
LEFT JOIN submission_status_details d ON d.submission_id=s.id
WHERE s.status='billed' OR s.order_completed=1 OR s.purchase_date IS NOT NULL;

UPDATE submissions s
JOIN (SELECT submission_id,MAX(bill_no) AS max_bill_no FROM submission_bills GROUP BY submission_id) b
  ON b.submission_id=s.id
SET s.bill_count=b.max_bill_no;

SELECT 'office_admin_2026_09_16 migration completed' AS migration_status;
