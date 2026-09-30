-- ============================================================
-- db_alter_extra_box.sql — Adds extra_box column for box adjustment
-- Run this after importing the main database.sql and custom alters
-- ============================================================

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS password_expires_at DATETIME NULL AFTER is_locked;

-- Office-admin passwords now use the same 24-hour expiry rule as sub-admins.
-- Existing office admins without an expiry are given a fresh 24-hour window.
UPDATE users
SET password_expires_at = DATE_ADD(NOW(), INTERVAL 1 DAY)
WHERE role = 'office_admin'
  AND password_expires_at IS NULL;

ALTER TABLE submission_areas
  ADD COLUMN IF NOT EXISTS extra_box INT NOT NULL DEFAULT 0 COMMENT 'Extra box count (can be negative, affects total box calculation)' AFTER area_count;

ALTER TABLE submission_estimate_rooms
  ADD COLUMN IF NOT EXISTS extra_box INT NOT NULL DEFAULT 0 COMMENT 'Extra box count (can be negative, affects estimate total box calculation)' AFTER box_count;

UPDATE submission_estimate_rooms
SET
  mrp_total = ROUND(GREATEST(0, box_count + extra_box) * mrp_per_box, 2),
  mop_total = ROUND(GREATEST(0, box_count + extra_box) * mop_per_box, 2);

-- Usage:
--   - Store user input for extra boxes (positive or negative)
--   - In all box calculations, use: total_boxes = GREATEST(0, calculated_boxes + extra_box)
--   - If extra_box is negative, subtract from total

-- Example query for report:
--   SELECT *, (calculated_boxes + extra_box) AS total_boxes
--   FROM submission_areas;
