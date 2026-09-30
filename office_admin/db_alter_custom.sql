-- ============================================================
-- db_alter_custom.sql — Only ALTER statements for migration
-- Run this after importing the main database.sql
-- ============================================================

-- Add tile_chosen column to submission_areas if not exists
ALTER TABLE submission_areas ADD COLUMN IF NOT EXISTS tile_chosen VARCHAR(255) NOT NULL DEFAULT '' AFTER area_count;

-- Add tile_size_key column to submission_areas if not exists
ALTER TABLE submission_areas ADD COLUMN IF NOT EXISTS tile_size_key VARCHAR(30) NOT NULL DEFAULT '2|2|4' AFTER tile_chosen;

-- Add wastage_pct column to submission_areas if not exists
ALTER TABLE submission_areas ADD COLUMN IF NOT EXISTS wastage_pct TINYINT UNSIGNED NOT NULL DEFAULT 10 AFTER tile_size_key;

-- Add tile_surface column to submission_areas if not exists
ALTER TABLE submission_areas ADD COLUMN IF NOT EXISTS tile_surface VARCHAR(10) NOT NULL DEFAULT 'floor' AFTER wastage_pct;

-- Add wall_tile_size_key column to submission_areas if not exists
ALTER TABLE submission_areas ADD COLUMN IF NOT EXISTS wall_tile_size_key VARCHAR(30) NOT NULL DEFAULT '2|2|4' AFTER tile_surface;

-- Add wall_wastage_pct column to submission_areas if not exists
ALTER TABLE submission_areas ADD COLUMN IF NOT EXISTS wall_wastage_pct TINYINT UNSIGNED NOT NULL DEFAULT 10 AFTER wall_tile_size_key;

-- Add floor_tile_size_key column to submission_areas if not exists
ALTER TABLE submission_areas ADD COLUMN IF NOT EXISTS floor_tile_size_key VARCHAR(30) NOT NULL DEFAULT '2|2|4' AFTER wall_wastage_pct;

-- Add floor_wastage_pct column to submission_areas if not exists
ALTER TABLE submission_areas ADD COLUMN IF NOT EXISTS floor_wastage_pct TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER floor_tile_size_key;

-- Add is_bathroom column to submission_areas if not exists
ALTER TABLE submission_areas ADD COLUMN IF NOT EXISTS is_bathroom TINYINT(1) NOT NULL DEFAULT 0 AFTER floor_wastage_pct;

-- Add area_height column to submission_areas if not exists
ALTER TABLE submission_areas ADD COLUMN IF NOT EXISTS area_height DECIMAL(6,2) NULL AFTER area_breadth;

-- Add bathroom partition columns if not exists
ALTER TABLE submission_areas ADD COLUMN IF NOT EXISTS bath_light_h DECIMAL(6,2) NULL AFTER is_bathroom;
ALTER TABLE submission_areas ADD COLUMN IF NOT EXISTS bath_high_h DECIMAL(6,2) NULL AFTER bath_light_h;
ALTER TABLE submission_areas ADD COLUMN IF NOT EXISTS bath_dark_h DECIMAL(6,2) NULL AFTER bath_high_h;
ALTER TABLE submission_areas ADD COLUMN IF NOT EXISTS bath_tile_size_key VARCHAR(30) NOT NULL DEFAULT '2|2|4' AFTER bath_dark_h;
ALTER TABLE submission_areas ADD COLUMN IF NOT EXISTS bath_wastage_pct TINYINT UNSIGNED NOT NULL DEFAULT 10 AFTER bath_tile_size_key;

-- Add calc_summary column to submission_areas if not exists
ALTER TABLE submission_areas ADD COLUMN IF NOT EXISTS calc_summary JSON NULL AFTER bath_wastage_pct;

-- Password expiry support for both sales executives and marketing executives
ALTER TABLE users ADD COLUMN IF NOT EXISTS password_expires_at DATETIME NULL AFTER is_locked;
UPDATE users
SET password_expires_at = DATE_ADD(NOW(), INTERVAL 1 DAY)
WHERE role = 'office_admin'
  AND password_expires_at IS NULL;

-- Estimate extra box adjustment.
-- Positive values add boxes; negative values subtract boxes.
ALTER TABLE submission_estimate_rooms
  ADD COLUMN IF NOT EXISTS extra_box INT NOT NULL DEFAULT 0
  COMMENT 'Manual box adjustment; negative values subtract from base box count'
  AFTER box_count;

-- Keep saved totals aligned with the adjusted box count.
UPDATE submission_estimate_rooms
SET
  mrp_total = ROUND(GREATEST(0, box_count + extra_box) * mrp_per_box, 2),
  mop_total = ROUND(GREATEST(0, box_count + extra_box) * mop_per_box, 2),
  discount_pct = CASE
    WHEN mrp_per_box > 0 THEN ROUND(100 - ((mop_per_box / mrp_per_box) * 100), 2)
    ELSE 0
  END;

-- Add other_status_reason column to submissions if not exists
ALTER TABLE submissions ADD COLUMN IF NOT EXISTS other_status_reason TEXT NULL AFTER status;

-- Change status columns to VARCHAR if needed
ALTER TABLE submissions MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'enquiry';
ALTER TABLE submission_status_details MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'enquiry';
