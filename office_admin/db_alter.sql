-- ============================================================
-- db_alter.sql  — Run this once on your aandavar_db
-- Adds:
--   1. edit_unlock_requests table
--   2. tile_chosen column in submission_areas
-- Safe to re-run (IF NOT EXISTS / IF NOT EXISTS column checks)
-- ============================================================

USE aandavar_db;

-- ── 1. edit_unlock_requests ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS edit_unlock_requests (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    submission_id INT UNSIGNED NOT NULL,
    requested_by  INT UNSIGNED NOT NULL,
    reason        TEXT         NOT NULL,
    status        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    reviewed_by   INT UNSIGNED NULL,
    reviewed_at   DATETIME     NULL,
    reject_note   TEXT         NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_eur_submission (submission_id),
    KEY idx_eur_requested  (requested_by),
    KEY idx_eur_status     (status),
    CONSTRAINT fk_eur_submission
        FOREIGN KEY (submission_id) REFERENCES submissions (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_eur_requested_by
        FOREIGN KEY (requested_by) REFERENCES users (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_eur_reviewed_by
        FOREIGN KEY (reviewed_by) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. Add tile_chosen to submission_areas (safe, idempotent) ──────────────
SET @dbname = DATABASE();
SET @tablename = 'submission_areas';
SET @colname = 'tile_chosen';
SET @stmt = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @colname) = 0,
    CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN tile_chosen VARCHAR(255) NOT NULL DEFAULT '''' COMMENT ''Tile chosen by customer for this area/room'' AFTER area_count'),
    'SELECT ''tile_chosen column already exists'' AS note'
);
PREPARE dyn FROM @stmt;
EXECUTE dyn;
DEALLOCATE PREPARE dyn;

-- ── Verify ──────────────────────────────────────────────────────────────────
SELECT 'edit_unlock_requests table ready' AS status;
SELECT IF(COUNT(*)>0,'tile_chosen column ready','tile_chosen NOT found - check above') AS tile_col_status
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='submission_areas' AND COLUMN_NAME='tile_chosen';



-- ============================================================
-- db_alter_v2.sql — Run this once on your aandavar_db
-- Adds:
--   1. edit_unlock_requests table
--   2. submission_area_images table
--   3. tile_chosen column
--   4. tile_size_key column (stores selected tile size for per-room calc)
--   5. wastage_pct column (per-room wastage %)
--   6. is_bathroom column (flag for bathroom calc mode)
--   7. bath_light_h, bath_high_h, bath_dark_h (partition heights)
--   8. bath_tile_size, bath_wastage_pct (bathroom-specific tile settings)
-- Safe to re-run (IF NOT EXISTS / conditional checks)
-- ============================================================
USE aandavar_db;

-- ── 1. edit_unlock_requests ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS edit_unlock_requests (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    submission_id INT UNSIGNED NOT NULL,
    requested_by  INT UNSIGNED NOT NULL,
    reason        TEXT         NOT NULL,
    status        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    reviewed_by   INT UNSIGNED NULL,
    reviewed_at   DATETIME     NULL,
    reject_note   TEXT         NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_eur_submission (submission_id),
    KEY idx_eur_requested  (requested_by),
    KEY idx_eur_status     (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. submission_area_images ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS submission_area_images (
    id               INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    area_id          INT UNSIGNED     NOT NULL,
    submission_id    INT UNSIGNED     NOT NULL,
    image_filename   VARCHAR(255)     NOT NULL,
    image_type       ENUM('upload','camera') NOT NULL DEFAULT 'upload',
    sort_order       TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sai_area       (area_id),
    KEY idx_sai_submission (submission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Helper: conditional ADD COLUMN macro ──────────────────────────────────
-- tile_chosen
SET @col='tile_chosen';
SET @tbl='submission_areas';
SET @sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@tbl AND COLUMN_NAME=@col)=0,
  CONCAT('ALTER TABLE ',@tbl,' ADD COLUMN ',@col,' VARCHAR(255) NOT NULL DEFAULT '''' COMMENT ''Tile brand chosen by customer'' AFTER area_count'),
  'SELECT ''tile_chosen already exists'' AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- tile_size_key
SET @col='tile_size_key';
SET @sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@tbl AND COLUMN_NAME=@col)=0,
  CONCAT('ALTER TABLE ',@tbl,' ADD COLUMN ',@col,' VARCHAR(30) NOT NULL DEFAULT ''2|2|4'' COMMENT ''Tile size key W|H|PPB for calculator'' AFTER tile_chosen'),
  'SELECT ''tile_size_key already exists'' AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- wastage_pct
SET @col='wastage_pct';
SET @sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@tbl AND COLUMN_NAME=@col)=0,
  CONCAT('ALTER TABLE ',@tbl,' ADD COLUMN ',@col,' TINYINT UNSIGNED NOT NULL DEFAULT 10 COMMENT ''Wastage % for floor tile calc'' AFTER tile_size_key'),
  'SELECT ''wastage_pct already exists'' AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- is_bathroom
SET @col='is_bathroom';
SET @sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@tbl AND COLUMN_NAME=@col)=0,
  CONCAT('ALTER TABLE ',@tbl,' ADD COLUMN ',@col,' TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 if this area uses bathroom partition calculator'' AFTER wastage_pct'),
  'SELECT ''is_bathroom already exists'' AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- bath_light_h
SET @col='bath_light_h';
SET @sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@tbl AND COLUMN_NAME=@col)=0,
  CONCAT('ALTER TABLE ',@tbl,' ADD COLUMN ',@col,' DECIMAL(6,2) NULL COMMENT ''Bathroom light partition height ft'' AFTER is_bathroom'),
  'SELECT ''bath_light_h already exists'' AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- bath_high_h
SET @col='bath_high_h';
SET @sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@tbl AND COLUMN_NAME=@col)=0,
  CONCAT('ALTER TABLE ',@tbl,' ADD COLUMN ',@col,' DECIMAL(6,2) NULL COMMENT ''Bathroom highlight partition height ft'' AFTER bath_light_h'),
  'SELECT ''bath_high_h already exists'' AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- bath_dark_h
SET @col='bath_dark_h';
SET @sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@tbl AND COLUMN_NAME=@col)=0,
  CONCAT('ALTER TABLE ',@tbl,' ADD COLUMN ',@col,' DECIMAL(6,2) NULL COMMENT ''Bathroom dark partition height ft'' AFTER bath_high_h'),
  'SELECT ''bath_dark_h already exists'' AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- bath_tile_size
SET @col='bath_tile_size';
SET @sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@tbl AND COLUMN_NAME=@col)=0,
  CONCAT('ALTER TABLE ',@tbl,' ADD COLUMN ',@col,' VARCHAR(30) NOT NULL DEFAULT ''2|2|4'' COMMENT ''Bathroom tile size key W|H|PPB'' AFTER bath_dark_h'),
  'SELECT ''bath_tile_size already exists'' AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- bath_wastage_pct
SET @col='bath_wastage_pct';
SET @sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@tbl AND COLUMN_NAME=@col)=0,
  CONCAT('ALTER TABLE ',@tbl,' ADD COLUMN ',@col,' TINYINT UNSIGNED NOT NULL DEFAULT 10 COMMENT ''Bathroom tile wastage %'' AFTER bath_tile_size'),
  'SELECT ''bath_wastage_pct already exists'' AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── Verify ──────────────────────────────────────────────────────────────────
SELECT 'edit_unlock_requests' AS tbl, IF(COUNT(*)>0,'✅ Ready','❌ Missing') AS status
FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='edit_unlock_requests'
UNION ALL
SELECT 'submission_area_images', IF(COUNT(*)>0,'✅ Ready','❌ Missing')
FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='submission_area_images';

SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='submission_areas'
  AND COLUMN_NAME IN ('tile_chosen','tile_size_key','wastage_pct','is_bathroom','bath_light_h','bath_high_h','bath_dark_h','bath_tile_size','bath_wastage_pct')
ORDER BY ORDINAL_POSITION;


CREATE TABLE IF NOT EXISTS visualizer_snapshots (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    submission_id INT UNSIGNED NOT NULL,
    filename      VARCHAR(255) NOT NULL,
    owner_name    VARCHAR(255) NOT NULL DEFAULT '',
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_vs_sub (submission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- db_alter_final.sql
-- Run once on: aandavar_db
-- Safe to re-run — all changes use IF NOT EXISTS / conditional checks
--
-- Tables created / verified:
--   1. edit_unlock_requests
--   2. submission_area_images
--   3. visualizer_snapshots          ← stores visualizer images in DB
--
-- Columns added to submission_areas:
--   area_height, tile_chosen, tile_surface, tile_size_key,
--   wastage_pct, wall_tile_size_key, wall_wastage_pct,
--   is_bathroom, bath_light_h, bath_high_h, bath_dark_h,
--   bath_tile_size, bath_wastage_pct
-- ============================================================

USE aandavar_db;

-- ─────────────────────────────────────────────────────────────
-- 1. edit_unlock_requests
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS edit_unlock_requests (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    submission_id INT UNSIGNED NOT NULL,
    requested_by  INT UNSIGNED NOT NULL,
    reason        TEXT         NOT NULL,
    status        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    reviewed_by   INT UNSIGNED NULL,
    reviewed_at   DATETIME     NULL,
    reject_note   TEXT         NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_eur_submission (submission_id),
    KEY idx_eur_requested  (requested_by),
    KEY idx_eur_status     (status),
    CONSTRAINT fk_eur_submission
        FOREIGN KEY (submission_id) REFERENCES submissions (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_eur_requested_by
        FOREIGN KEY (requested_by) REFERENCES users (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_eur_reviewed_by
        FOREIGN KEY (reviewed_by) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- 2. submission_area_images
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS submission_area_images (
    id               INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    area_id          INT UNSIGNED     NOT NULL,
    submission_id    INT UNSIGNED     NOT NULL,
    image_filename   VARCHAR(255)     NOT NULL,
    image_type       ENUM('upload','camera') NOT NULL DEFAULT 'upload',
    sort_order       TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sai_area       (area_id),
    KEY idx_sai_submission (submission_id),
    CONSTRAINT fk_sai_area
        FOREIGN KEY (area_id)       REFERENCES submission_areas (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_sai_submission
        FOREIGN KEY (submission_id) REFERENCES submissions (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- 3. visualizer_snapshots
--    Stores every snapshot taken from the 3-D Visualizer.
--    image_data (MEDIUMBLOB) holds the raw JPEG bytes so the
--    snapshot lives entirely in the database — no filesystem needed.
--    image_filename is kept for backward-compat with file-based saves.
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS visualizer_snapshots (
    id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    submission_id  INT UNSIGNED  NOT NULL,
    filename       VARCHAR(255)  NOT NULL DEFAULT '',
    image_data     MEDIUMBLOB    NULL COMMENT 'Raw JPEG bytes — populated when saving directly to DB',
    image_mime     VARCHAR(50)   NOT NULL DEFAULT 'image/jpeg',
    file_size_kb   INT UNSIGNED  NULL     COMMENT 'Approximate size in KB',
    owner_name     VARCHAR(255)  NOT NULL DEFAULT '',
    saved_by       INT UNSIGNED  NULL     COMMENT 'users.id of the office-admin who saved',
    notes          VARCHAR(500)  NULL     COMMENT 'Optional label / caption for the snapshot',
    created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_vs_submission (submission_id),
    KEY idx_vs_saved_by   (saved_by),
    CONSTRAINT fk_vs_submission
        FOREIGN KEY (submission_id) REFERENCES submissions (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_vs_saved_by
        FOREIGN KEY (saved_by) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Visualizer snapshots — image bytes stored in image_data (MEDIUMBLOB)';

-- ─────────────────────────────────────────────────────────────
-- 4.  submission_areas — conditional column additions
--     Macro: set @col / @sql, PREPARE / EXECUTE / DEALLOCATE
-- ─────────────────────────────────────────────────────────────

-- 4a. area_height
SET @col='area_height'; SET @tbl='submission_areas';
SET @sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@tbl AND COLUMN_NAME=@col)=0,
  CONCAT('ALTER TABLE ',@tbl,' ADD COLUMN ',@col,
         ' DECIMAL(6,2) NULL COMMENT ''Room / wall height in feet'' AFTER area_breadth'),
  'SELECT ''area_height already exists'' AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 4b. tile_chosen
SET @col='tile_chosen';
SET @sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@tbl AND COLUMN_NAME=@col)=0,
  CONCAT('ALTER TABLE ',@tbl,' ADD COLUMN ',@col,
         ' VARCHAR(255) NOT NULL DEFAULT '''' COMMENT ''Tile brand chosen by customer'' AFTER area_count'),
  'SELECT ''tile_chosen already exists'' AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 4c. tile_surface  (floor | wall | both)
SET @col='tile_surface';
SET @sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@tbl AND COLUMN_NAME=@col)=0,
  CONCAT('ALTER TABLE ',@tbl,' ADD COLUMN ',@col,
         ' VARCHAR(10) NOT NULL DEFAULT ''floor'' COMMENT ''floor | wall | both'' AFTER tile_chosen'),
  'SELECT ''tile_surface already exists'' AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 4d. tile_size_key  (floor tile — W|H|PPB)
SET @col='tile_size_key';
SET @sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@tbl AND COLUMN_NAME=@col)=0,
  CONCAT('ALTER TABLE ',@tbl,' ADD COLUMN ',@col,
         ' VARCHAR(30) NOT NULL DEFAULT ''2|2|4'' COMMENT ''Floor tile key W|H|PPB'' AFTER tile_surface'),
  'SELECT ''tile_size_key already exists'' AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 4e. wastage_pct  (floor wastage %)
SET @col='wastage_pct';
SET @sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@tbl AND COLUMN_NAME=@col)=0,
  CONCAT('ALTER TABLE ',@tbl,' ADD COLUMN ',@col,
         ' TINYINT UNSIGNED NOT NULL DEFAULT 10 COMMENT ''Floor tile wastage %'' AFTER tile_size_key'),
  'SELECT ''wastage_pct already exists'' AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 4f. wall_tile_size_key
SET @col='wall_tile_size_key';
SET @sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@tbl AND COLUMN_NAME=@col)=0,
  CONCAT('ALTER TABLE ',@tbl,' ADD COLUMN ',@col,
         ' VARCHAR(30) NOT NULL DEFAULT ''2|2|4'' COMMENT ''Wall tile key W|H|PPB'' AFTER wastage_pct'),
  'SELECT ''wall_tile_size_key already exists'' AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 4g. wall_wastage_pct
SET @col='wall_wastage_pct';
SET @sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@tbl AND COLUMN_NAME=@col)=0,
  CONCAT('ALTER TABLE ',@tbl,' ADD COLUMN ',@col,
         ' TINYINT UNSIGNED NOT NULL DEFAULT 10 COMMENT ''Wall tile wastage %'' AFTER wall_tile_size_key'),
  'SELECT ''wall_wastage_pct already exists'' AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 4h. is_bathroom
SET @col='is_bathroom';
SET @sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@tbl AND COLUMN_NAME=@col)=0,
  CONCAT('ALTER TABLE ',@tbl,' ADD COLUMN ',@col,
         ' TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = use bathroom partition calculator'' AFTER wall_wastage_pct'),
  'SELECT ''is_bathroom already exists'' AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 4i. bath_light_h
SET @col='bath_light_h';
SET @sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@tbl AND COLUMN_NAME=@col)=0,
  CONCAT('ALTER TABLE ',@tbl,' ADD COLUMN ',@col,
         ' DECIMAL(6,2) NULL COMMENT ''Bathroom light partition height ft'' AFTER is_bathroom'),
  'SELECT ''bath_light_h already exists'' AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 4j. bath_high_h
SET @col='bath_high_h';
SET @sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@tbl AND COLUMN_NAME=@col)=0,
  CONCAT('ALTER TABLE ',@tbl,' ADD COLUMN ',@col,
         ' DECIMAL(6,2) NULL COMMENT ''Bathroom highlight partition height ft'' AFTER bath_light_h'),
  'SELECT ''bath_high_h already exists'' AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 4k. bath_dark_h
SET @col='bath_dark_h';
SET @sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@tbl AND COLUMN_NAME=@col)=0,
  CONCAT('ALTER TABLE ',@tbl,' ADD COLUMN ',@col,
         ' DECIMAL(6,2) NULL COMMENT ''Bathroom dark partition height ft'' AFTER bath_high_h'),
  'SELECT ''bath_dark_h already exists'' AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 4l. bath_tile_size
SET @col='bath_tile_size';
SET @sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@tbl AND COLUMN_NAME=@col)=0,
  CONCAT('ALTER TABLE ',@tbl,' ADD COLUMN ',@col,
         ' VARCHAR(30) NOT NULL DEFAULT ''2|2|4'' COMMENT ''Bathroom tile key W|H|PPB'' AFTER bath_dark_h'),
  'SELECT ''bath_tile_size already exists'' AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 4m. bath_wastage_pct
SET @col='bath_wastage_pct';
SET @sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@tbl AND COLUMN_NAME=@col)=0,
  CONCAT('ALTER TABLE ',@tbl,' ADD COLUMN ',@col,
         ' TINYINT UNSIGNED NOT NULL DEFAULT 10 COMMENT ''Bathroom tile wastage %'' AFTER bath_tile_size'),
  'SELECT ''bath_wastage_pct already exists'' AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ─────────────────────────────────────────────────────────────
-- 5.  Migrate area_type from ENUM → VARCHAR (if needed)
--     The PHP does this at runtime; mirror it here for clean installs.
-- ─────────────────────────────────────────────────────────────
SET @coltype = (
    SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='submission_areas' AND COLUMN_NAME='area_type'
);
SET @fix = IF(@coltype IS NOT NULL AND LOWER(@coltype) LIKE 'enum%',
    'ALTER TABLE submission_areas MODIFY COLUMN area_type VARCHAR(120) NOT NULL DEFAULT ''''',
    'SELECT ''area_type is already VARCHAR or does not exist'' AS note'
);
PREPARE s FROM @fix; EXECUTE s; DEALLOCATE PREPARE s;

-- ─────────────────────────────────────────────────────────────
-- 6.  NEW: visualizer_snapshots — add image_data column
--     (in case the table was created earlier without MEDIUMBLOB support)
-- ─────────────────────────────────────────────────────────────
SET @col='image_data'; SET @tbl='visualizer_snapshots';
SET @sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@tbl AND COLUMN_NAME=@col)=0,
  'ALTER TABLE visualizer_snapshots ADD COLUMN image_data MEDIUMBLOB NULL COMMENT ''Raw image bytes'' AFTER filename',
  'SELECT ''image_data already exists'' AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col='image_mime'; SET @tbl='visualizer_snapshots';
SET @sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@tbl AND COLUMN_NAME=@col)=0,
  'ALTER TABLE visualizer_snapshots ADD COLUMN image_mime VARCHAR(50) NOT NULL DEFAULT ''image/jpeg'' AFTER image_data',
  'SELECT ''image_mime already exists'' AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col='file_size_kb'; SET @tbl='visualizer_snapshots';
SET @sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@tbl AND COLUMN_NAME=@col)=0,
  'ALTER TABLE visualizer_snapshots ADD COLUMN file_size_kb INT UNSIGNED NULL COMMENT ''KB'' AFTER image_mime',
  'SELECT ''file_size_kb already exists'' AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col='saved_by'; SET @tbl='visualizer_snapshots';
SET @sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@tbl AND COLUMN_NAME=@col)=0,
  'ALTER TABLE visualizer_snapshots ADD COLUMN saved_by INT UNSIGNED NULL COMMENT ''users.id'' AFTER owner_name',
  'SELECT ''saved_by already exists'' AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col='notes'; SET @tbl='visualizer_snapshots';
SET @sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@tbl AND COLUMN_NAME=@col)=0,
  'ALTER TABLE visualizer_snapshots ADD COLUMN notes VARCHAR(500) NULL COMMENT ''Caption / label'' AFTER saved_by',
  'SELECT ''notes already exists'' AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Add saved_by index if missing
SET @idxExists=(SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='visualizer_snapshots' AND INDEX_NAME='idx_vs_saved_by');
SET @idxSql=IF(@idxExists=0,
    'ALTER TABLE visualizer_snapshots ADD KEY idx_vs_saved_by (saved_by)',
    'SELECT ''idx_vs_saved_by already exists'' AS note');
PREPARE s FROM @idxSql; EXECUTE s; DEALLOCATE PREPARE s;

-- ─────────────────────────────────────────────────────────────
-- 7.  Verification report
-- ─────────────────────────────────────────────────────────────
SELECT
    TABLE_NAME AS `table`,
    IF(COUNT(*)>0, '✅ Ready', '❌ Missing') AS status
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('edit_unlock_requests','submission_area_images','visualizer_snapshots')
GROUP BY TABLE_NAME
UNION ALL
SELECT 'submission_areas', IF(COUNT(*)>=13,'✅ All columns present',CONCAT('⚠️ Only ',COUNT(*),' of 13 new columns found'))
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='submission_areas'
  AND COLUMN_NAME IN (
    'area_height','tile_chosen','tile_surface','tile_size_key','wastage_pct',
    'wall_tile_size_key','wall_wastage_pct','is_bathroom',
    'bath_light_h','bath_high_h','bath_dark_h','bath_tile_size','bath_wastage_pct'
  );

-- Detailed column check on visualizer_snapshots
SELECT
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLUMN_COMMENT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='visualizer_snapshots'
ORDER BY ORDINAL_POSITION;

-- ─────────────────────────────────────────────────────────────
-- DONE — aandavar_db schema is fully up to date.
-- ─────────────────────────────────────────────────────────────



-- ═══════════════════════════════════════════════════════════════════════════════
-- DATABASE SCHEMA — Tile CRM System
-- Run once to create all required tables.
-- Compatible with MySQL 5.7+ / MariaDB 10.3+
-- ═══════════════════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ── directions ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `directions` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `code`       VARCHAR(30) NOT NULL,
  `name`       VARCHAR(100) NOT NULL DEFAULT '',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── sub_directions ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sub_directions` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `direction_id` INT UNSIGNED NOT NULL,
  `code`         VARCHAR(30) NOT NULL,
  `name`         VARCHAR(100) NOT NULL DEFAULT '',
  `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`direction_id`) REFERENCES `directions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── users ─────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `users` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `username`     VARCHAR(80) NOT NULL UNIQUE,
  `password`     VARCHAR(255) NOT NULL,
  `role`         ENUM('main_admin','sub_admin') NOT NULL DEFAULT 'sub_admin',
  `phone`        VARCHAR(20) NOT NULL DEFAULT '',
  `is_active`    TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── submissions ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `submissions` (
  `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `submitted_by`          INT UNSIGNED NOT NULL,
  `sub_direction_id`      INT UNSIGNED NOT NULL,
  `owner_name`            VARCHAR(150) NOT NULL,
  `owner_number`          VARCHAR(25)  NOT NULL,
  `representative_name`   VARCHAR(150) NOT NULL DEFAULT '',
  `representative_number` VARCHAR(25)  NOT NULL DEFAULT '',
  `village_city_town`     VARCHAR(150) NOT NULL DEFAULT '',
  `street`                VARCHAR(150) NOT NULL DEFAULT '',
  `latitude`              DECIMAL(10,7) DEFAULT NULL,
  `longitude`             DECIMAL(10,7) DEFAULT NULL,
  `status`                VARCHAR(30)  NOT NULL DEFAULT 'enquiry',
  `purchase_date`         DATE DEFAULT NULL,
  `purchase_notes`        TEXT DEFAULT NULL,
  `other_status_reason`   TEXT DEFAULT NULL,
  `next_visit_date`       DATE DEFAULT NULL,
  `order_completed`       TINYINT(1) NOT NULL DEFAULT 0,
  `landmark_picture`      VARCHAR(255) DEFAULT NULL,
  `additional_picture`    VARCHAR(255) DEFAULT NULL,
  `device_submitted_at`   DATETIME DEFAULT NULL,
  `submitted_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`submitted_by`)      REFERENCES `users`(`id`)          ON DELETE RESTRICT,
  FOREIGN KEY (`sub_direction_id`)  REFERENCES `sub_directions`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── submission_status_details ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `submission_status_details` (
  `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `submission_id`     INT UNSIGNED NOT NULL UNIQUE,
  `deal_result`       VARCHAR(60)  DEFAULT NULL,
  `vendor_name`       VARCHAR(150) DEFAULT NULL,
  `vendor_details`    TEXT DEFAULT NULL,
  `other_reason`      TEXT DEFAULT NULL,
  `detail_description`TEXT DEFAULT NULL,
  `next_visit_date`   DATE DEFAULT NULL,
  `updated_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`submission_id`) REFERENCES `submissions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── submission_areas ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `submission_areas` (
  `id`                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `submission_id`        INT UNSIGNED NOT NULL,
  `sort_order`           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `area_type`            VARCHAR(100) NOT NULL DEFAULT 'Room',
  `area_length`          DECIMAL(8,2) DEFAULT NULL,
  `area_breadth`         DECIMAL(8,2) DEFAULT NULL,
  `area_height`          DECIMAL(8,2) DEFAULT NULL,
  `area_sqft`            DECIMAL(10,2) GENERATED ALWAYS AS
                         (COALESCE(`area_length` * `area_breadth`, 0)) STORED,
  `total_sqft`           DECIMAL(10,2) DEFAULT NULL,
  `area_count`           SMALLINT UNSIGNED DEFAULT NULL,
  -- surface
  `tile_surface`         VARCHAR(10) NOT NULL DEFAULT 'floor',
  -- tile size keys (separate for wall / floor / bath)
  `tile_size_key`        VARCHAR(30) NOT NULL DEFAULT '2|2|4',
  `wall_tile_size_key`   VARCHAR(30) NOT NULL DEFAULT '2|2|4',
  `floor_tile_size_key`  VARCHAR(30) NOT NULL DEFAULT '2|2|4',
  `bath_tile_size_key`   VARCHAR(30) NOT NULL DEFAULT '2|2|4',
  -- wastage
  `wastage_pct`          DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `wall_wastage_pct`     DECIMAL(5,2) NOT NULL DEFAULT 10.00,
  `floor_wastage_pct`    DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `bath_wastage_pct`     DECIMAL(5,2) NOT NULL DEFAULT 10.00,
  -- tile brand
  `tile_chosen`          VARCHAR(100) DEFAULT NULL,
  -- bathroom partition
  `is_bathroom`          TINYINT(1) NOT NULL DEFAULT 0,
  `bath_light_h`         DECIMAL(6,2) DEFAULT NULL,
  `bath_high_h`          DECIMAL(6,2) DEFAULT NULL,
  `bath_dark_h`          DECIMAL(6,2) DEFAULT NULL,
  `bath_tile_size`       VARCHAR(30) DEFAULT NULL,
  -- timestamps
  `created_at`           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`submission_id`) REFERENCES `submissions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── area_images ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `area_images` (
  `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `area_id`          INT UNSIGNED NOT NULL,
  `submission_id`    INT UNSIGNED NOT NULL,
  `image_filename`   VARCHAR(255) NOT NULL,
  `source`           ENUM('upload','camera') NOT NULL DEFAULT 'upload',
  `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`area_id`)       REFERENCES `submission_areas`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`submission_id`) REFERENCES `submissions`(`id`)      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── visualizer_snapshots ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `visualizer_snapshots` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `submission_id` INT UNSIGNED NOT NULL,
  `filename`      VARCHAR(255) NOT NULL,
  `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`submission_id`) REFERENCES `submissions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── unlock_requests ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `unlock_requests` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `submission_id` INT UNSIGNED NOT NULL,
  `requested_by`  INT UNSIGNED NOT NULL,
  `reason`        TEXT NOT NULL,
  `status`        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `decided_at`    DATETIME DEFAULT NULL,
  `decided_by`    INT UNSIGNED DEFAULT NULL,
  `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`submission_id`) REFERENCES `submissions`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`requested_by`)  REFERENCES `users`(`id`)       ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── activity_log ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `activity_log` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `submission_id` INT UNSIGNED DEFAULT NULL,
  `user_id`       INT UNSIGNED DEFAULT NULL,
  `action`        VARCHAR(80) NOT NULL,
  `note`          TEXT DEFAULT NULL,
  `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sub (`submission_id`),
  INDEX idx_user(`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET foreign_key_checks = 1;

-- ── Sample directions / zones (optional seed) ─────────────────────────────────
-- INSERT INTO `directions` (`code`,`name`) VALUES ('NORTH','North Zone'),('SOUTH','South Zone');
-- INSERT INTO `sub_directions` (`direction_id`,`code`,`name`) VALUES (1,'N1','North Sub 1'),(2,'S1','South Sub 1');


-- ════════════════════════════════════════════════════════════════════
-- update_status_modal v2.1 — Database Migration
-- Run once against your existing database
-- ════════════════════════════════════════════════════════════════════

-- ── [FIX 1] Tile company + design name columns ──────────────────
ALTER TABLE submission_areas
  ADD COLUMN IF NOT EXISTS tile_company      VARCHAR(120)  NULL AFTER tile_chosen,
  ADD COLUMN IF NOT EXISTS tile_design_name  VARCHAR(120)  NULL AFTER tile_company;

-- ── [FIX 2] Size mismatch — separate floor/wall keys already exist
-- (floor_tile_size_key, wall_tile_size_key) — no change needed.
-- Add custom-size columns for when 'custom' is chosen:
ALTER TABLE submission_areas
  ADD COLUMN IF NOT EXISTS floor_tile_custom_w   DECIMAL(6,2) NULL AFTER floor_tile_size_key,
  ADD COLUMN IF NOT EXISTS floor_tile_custom_h   DECIMAL(6,2) NULL AFTER floor_tile_custom_w,
  ADD COLUMN IF NOT EXISTS floor_tile_custom_ppb TINYINT UNSIGNED NULL AFTER floor_tile_custom_h,
  ADD COLUMN IF NOT EXISTS wall_tile_custom_w    DECIMAL(6,2) NULL AFTER wall_tile_size_key,
  ADD COLUMN IF NOT EXISTS wall_tile_custom_h    DECIMAL(6,2) NULL AFTER wall_tile_custom_w,
  ADD COLUMN IF NOT EXISTS wall_tile_custom_ppb  TINYINT UNSIGNED NULL AFTER wall_tile_custom_h;

-- ── [FIX 3] Tile sample image filename ──────────────────────────
ALTER TABLE submission_areas
  ADD COLUMN IF NOT EXISTS tile_image_filename VARCHAR(255) NULL AFTER tile_design_name;

-- ── [FIX 4] Alternate mobile number on submissions ───────────────
ALTER TABLE submissions
  ADD COLUMN IF NOT EXISTS alt_mobile VARCHAR(20) NULL AFTER owner_number;

-- ── Confirm columns ──────────────────────────────────────────────
SHOW COLUMNS FROM submission_areas;
SHOW COLUMNS FROM submissions;
-- ── v7: Ensure office_admin_id column exists on users ─────────────────────
-- This column was added in the main database.sql. Run this only if you
-- are migrating an older installation that didn't have it.
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS office_admin_id INT UNSIGNED NULL DEFAULT NULL
        COMMENT 'For sub_admin: which office_admin manages them';

-- Add the foreign key if it does not exist yet (MySQL 8+ supports IF NOT EXISTS)
-- For older MySQL, skip this line if it errors.
ALTER TABLE users
    ADD CONSTRAINT fk_users_office_admin_v7
        FOREIGN KEY (office_admin_id) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE;
        
        
-- ============================================================
-- aandavar_complete_patch.sql  — v7 FINAL (phpMyAdmin safe)
-- Fix: submitted_by and other FK columns made nullable BEFORE
--      adding SET NULL foreign keys.
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET foreign_key_checks = 0;
SET sql_mode = '';

-- ============================================================
-- HELPER: safe_col
-- ============================================================
DROP PROCEDURE IF EXISTS safe_col;
DELIMITER $$
CREATE PROCEDURE safe_col(IN tbl VARCHAR(64), IN col VARCHAR(64), IN def TEXT)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND COLUMN_NAME = col
  ) THEN
    SET @_sql = CONCAT('ALTER TABLE `', tbl, '` ADD COLUMN `', col, '` ', def);
    PREPARE _st FROM @_sql; EXECUTE _st; DEALLOCATE PREPARE _st;
  END IF;
END$$
DELIMITER ;

-- ============================================================
-- HELPER: safe_fk
-- Drops old FK (any rule), makes the FK column nullable if
-- the rule is SET NULL, then re-adds with correct rule.
-- ============================================================
DROP PROCEDURE IF EXISTS safe_fk;
DELIMITER $$
CREATE PROCEDURE safe_fk(IN tbl VARCHAR(64), IN cname VARCHAR(64), IN def TEXT)
BEGIN
  -- Drop old FK if exists
  IF EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND CONSTRAINT_NAME = cname
  ) THEN
    SET @_drop = CONCAT('ALTER TABLE `', tbl, '` DROP FOREIGN KEY `', cname, '`');
    PREPARE _st FROM @_drop; EXECUTE _st; DEALLOCATE PREPARE _st;
  END IF;
  -- If rule is SET NULL, ensure the column is nullable
  -- We do this by checking if def contains SET NULL
  IF LOCATE('SET NULL', def) > 0 THEN
    -- Extract column name from the FK def: FOREIGN KEY (`col`) REFERENCES...
    SET @_col = TRIM(REPLACE(REPLACE(
        SUBSTRING_INDEX(SUBSTRING_INDEX(def, '`', 4), '`', -1)
    , '(', ''), ')', ''));
    -- Check if column is NOT NULL and make it nullable
    SET @_coltype = (
      SELECT CONCAT(COLUMN_TYPE,
        IF(IS_NULLABLE='NO', ' NULL_NEEDED', ''))
      FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND COLUMN_NAME = @_col
      LIMIT 1
    );
    IF @_coltype IS NOT NULL AND LOCATE('NULL_NEEDED', @_coltype) > 0 THEN
      SET @_basetype = REPLACE(@_coltype, ' NULL_NEEDED', '');
      SET @_nullify = CONCAT('ALTER TABLE `', tbl, '` MODIFY COLUMN `', @_col, '` ', @_basetype, ' NULL');
      PREPARE _st FROM @_nullify; EXECUTE _st; DEALLOCATE PREPARE _st;
    END IF;
  END IF;
  -- Add FK with desired rule
  SET @_add = CONCAT('ALTER TABLE `', tbl, '` ADD CONSTRAINT `', cname, '` ', def);
  PREPARE _st FROM @_add; EXECUTE _st; DEALLOCATE PREPARE _st;
END$$
DELIMITER ;

-- ============================================================
-- HELPER: make_nullable
-- Force a column to NULL directly (used for columns we know
-- must be nullable for SET NULL FKs to work).
-- ============================================================
DROP PROCEDURE IF EXISTS make_nullable;
DELIMITER $$
CREATE PROCEDURE make_nullable(IN tbl VARCHAR(64), IN col VARCHAR(64))
BEGIN
  SET @_ct = (
    SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=tbl AND COLUMN_NAME=col LIMIT 1
  );
  SET @_nn = (
    SELECT IS_NULLABLE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=tbl AND COLUMN_NAME=col LIMIT 1
  );
  IF @_ct IS NOT NULL AND @_nn = 'NO' THEN
    SET @_s = CONCAT('ALTER TABLE `',tbl,'` MODIFY COLUMN `',col,'` ',@_ct,' NULL');
    PREPARE _st FROM @_s; EXECUTE _st; DEALLOCATE PREPARE _st;
  END IF;
END$$
DELIMITER ;


-- ════════════════════════════════════════════════════════════
-- BLOCK 1: CREATE TABLES (all IF NOT EXISTS)
-- ════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `directions` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code`       VARCHAR(10)  NOT NULL,
    `label`      VARCHAR(120) NOT NULL DEFAULT '',
    `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_directions_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sub_directions` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `direction_id`  INT UNSIGNED NOT NULL,
    `code`          VARCHAR(30)  NOT NULL,
    `from_location` VARCHAR(200) NOT NULL DEFAULT '',
    `to_location`   VARCHAR(200) NOT NULL DEFAULT '',
    `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_sub_dir_parent` (`direction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sub_direction_stops` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sub_direction_id` INT UNSIGNED NOT NULL,
    `place_name`       VARCHAR(200) NOT NULL,
    `sort_order`       INT          NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_stops_subdir` (`sub_direction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users` (
    `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username`            VARCHAR(100) NOT NULL,
    `password`            VARCHAR(255) NOT NULL,
    `role`                ENUM('main_admin','office_admin','sub_admin') NOT NULL DEFAULT 'sub_admin',
    `is_active`           TINYINT(1)   NOT NULL DEFAULT 1,
    `is_locked`           TINYINT(1)   NOT NULL DEFAULT 0,
    `password_expires_at` DATETIME     NULL,
    `office_admin_id`     INT UNSIGNED NULL DEFAULT NULL,
    `created_by`          INT UNSIGNED NULL,
    `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_username` (`username`),
    KEY `idx_users_office_admin` (`office_admin_id`),
    KEY `idx_users_created_by`   (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_sessions` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`           INT UNSIGNED NOT NULL,
    `session_token`     VARCHAR(255) NOT NULL,
    `device_info`       VARCHAR(500) NULL,
    `ip_address`        VARCHAR(64)  NULL,
    `location_label`    VARCHAR(200) NULL,
    `device_login_time` DATETIME     NULL,
    `logged_in_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_active`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `logged_out_at`     DATETIME     NULL,
    `is_active`         TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_sessions_token` (`session_token`),
    KEY `idx_sessions_user`   (`user_id`),
    KEY `idx_sessions_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `session_log` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`           INT UNSIGNED NOT NULL,
    `username`          VARCHAR(100) NOT NULL,
    `role`              VARCHAR(50)  NOT NULL,
    `ip_address`        VARCHAR(64)  NULL,
    `device_info`       VARCHAR(500) NULL,
    `device_login_time` DATETIME     NULL,
    `logged_in_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `logged_out_at`     DATETIME     NULL,
    `logout_type`       ENUM('normal','force','force_all','auto') NULL,
    `duration_secs`     INT UNSIGNED NULL,
    `session_token`     VARCHAR(255) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_session_log_token` (`session_token`),
    KEY `idx_session_log_user`  (`user_id`),
    KEY `idx_session_log_in`    (`logged_in_at`),
    KEY `idx_session_log_token` (`session_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sub_admin_locations` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sub_admin_id`     INT UNSIGNED NOT NULL,
    `sub_direction_id` INT UNSIGNED NOT NULL,
    `assigned_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_sub_admin_location` (`sub_admin_id`,`sub_direction_id`),
    KEY `idx_sal_subdir` (`sub_direction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `submissions` (
    `id`                    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `submitted_by`          INT UNSIGNED  NULL,
    `sub_direction_id`      INT UNSIGNED  NOT NULL,
    `owner_name`            VARCHAR(200)  NOT NULL,
    `owner_number`          VARCHAR(30)   NOT NULL DEFAULT '',
    `alt_mobile`            VARCHAR(20)   NULL,
    `representative_name`   VARCHAR(200)  NOT NULL DEFAULT '',
    `representative_number` VARCHAR(30)   NOT NULL DEFAULT '',
    `village_city_town`     VARCHAR(200)  NOT NULL DEFAULT '',
    `street`                VARCHAR(300)  NOT NULL DEFAULT '',
    `area_type`             VARCHAR(100)  NULL,
    `area_sqft`             DECIMAL(10,2) NULL,
    `area_count`            INT UNSIGNED  NULL,
    `landmark_picture`      VARCHAR(500)  NULL,
    `additional_picture`    VARCHAR(500)  NULL,
    `tile_image`            VARCHAR(500)  NULL,
    `status`                VARCHAR(50)   NOT NULL DEFAULT 'enquiry',
    `other_status_reason`   TEXT          NULL,
    `next_visit_date`       DATE          NULL,
    `order_completed`       TINYINT(1)    NOT NULL DEFAULT 0,
    `purchase_date`         DATE          NULL,
    `purchase_notes`        TEXT          NULL,
    `latitude`              DECIMAL(10,7) NULL,
    `longitude`             DECIMAL(10,7) NULL,
    `device_submitted_at`   DATETIME      NULL,
    `unlock_time_iso`       VARCHAR(50)   NULL,
    `submitted_at`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_edited_at`        DATETIME      NULL,
    PRIMARY KEY (`id`),
    KEY `idx_submissions_by`        (`submitted_by`),
    KEY `idx_submissions_dir`       (`sub_direction_id`),
    KEY `idx_submissions_status`    (`status`),
    KEY `idx_submissions_completed` (`order_completed`),
    KEY `idx_submissions_owner`     (`owner_number`),
    KEY `idx_submissions_rep`       (`representative_number`),
    KEY `idx_next_visit_date`       (`next_visit_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `submission_areas` (
    `id`                    INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `submission_id`         INT UNSIGNED      NOT NULL,
    `area_type`             VARCHAR(120)      NOT NULL DEFAULT '',
    `area_length`           DECIMAL(10,2)     NULL,
    `area_breadth`          DECIMAL(10,2)     NULL,
    `area_height`           DECIMAL(6,2)      NULL,
    `total_sqft`            DECIMAL(12,2)     NULL,
    `area_sqft`             DECIMAL(12,2)     NULL,
    `area_count`            SMALLINT          NULL,
    `tile_chosen`           VARCHAR(255)      NOT NULL DEFAULT '',
    `tile_company`          VARCHAR(120)      NULL,
    `tile_design_name`      VARCHAR(120)      NULL,
    `tile_image_filename`   VARCHAR(255)      NULL,
    `tile_surface`          VARCHAR(10)       NOT NULL DEFAULT 'floor',
    `floor_tile_size_key`   VARCHAR(30)       NOT NULL DEFAULT '2|2|4',
    `floor_wastage_pct`     DECIMAL(5,2)      NOT NULL DEFAULT 0.00,
    `floor_tile_custom_w`   DECIMAL(6,2)      NULL,
    `floor_tile_custom_h`   DECIMAL(6,2)      NULL,
    `floor_tile_custom_ppb` TINYINT UNSIGNED  NULL,
    `wall_tile_size_key`    VARCHAR(30)       NOT NULL DEFAULT '2|2|4',
    `wall_wastage_pct`      DECIMAL(5,2)      NOT NULL DEFAULT 10.00,
    `wall_tile_custom_w`    DECIMAL(6,2)      NULL,
    `wall_tile_custom_h`    DECIMAL(6,2)      NULL,
    `wall_tile_custom_ppb`  TINYINT UNSIGNED  NULL,
    `tile_size_key`         VARCHAR(30)       NOT NULL DEFAULT '2|2|4',
    `wastage_pct`           TINYINT UNSIGNED  NOT NULL DEFAULT 10,
    `is_bathroom`           TINYINT(1)        NOT NULL DEFAULT 0,
    `bath_light_h`          DECIMAL(6,2)      NULL,
    `bath_high_h`           DECIMAL(6,2)      NULL,
    `bath_dark_h`           DECIMAL(6,2)      NULL,
    `bath_tile_size_key`    VARCHAR(30)       NOT NULL DEFAULT '2|2|4',
    `bath_tile_size`        VARCHAR(30)       NOT NULL DEFAULT '2|2|4',
    `bath_wastage_pct`      TINYINT UNSIGNED  NOT NULL DEFAULT 10,
    `calc_summary`          JSON              NULL,
    `sort_order`            SMALLINT          NOT NULL DEFAULT 0,
    `created_at`            DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_areas_submission` (`submission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `submission_area_images` (
    `id`             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `area_id`        INT UNSIGNED     NOT NULL,
    `submission_id`  INT UNSIGNED     NOT NULL,
    `image_filename` VARCHAR(500)     NOT NULL,
    `image_type`     ENUM('camera','upload') NOT NULL DEFAULT 'upload',
    `sort_order`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_sai_area`       (`area_id`),
    KEY `idx_sai_submission` (`submission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `submission_status_details` (
    `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `submission_id`      INT UNSIGNED NOT NULL,
    `status`             VARCHAR(50)  NOT NULL DEFAULT 'enquiry',
    `detail_description` TEXT         NULL,
    `deal_result`        VARCHAR(100) NULL,
    `vendor_name`        VARCHAR(200) NULL,
    `vendor_details`     VARCHAR(500) NULL,
    `other_reason`       TEXT         NULL,
    `next_visit_date`    DATE         NULL,
    `created_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_status_details_submission` (`submission_id`),
    KEY `idx_ssd_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `submission_edits` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `submission_id` INT UNSIGNED NOT NULL,
    `edited_by`     INT UNSIGNED NULL,
    `field_name`    VARCHAR(100) NOT NULL,
    `old_value`     TEXT         NULL,
    `new_value`     TEXT         NULL,
    `edited_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_edits_submission` (`submission_id`),
    KEY `idx_edits_editor`     (`edited_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `submission_remove_requests` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `submission_id` INT UNSIGNED NOT NULL,
    `requested_by`  INT UNSIGNED NOT NULL,
    `reason`        TEXT         NOT NULL,
    `status`        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    `reviewed_by`   INT UNSIGNED NULL,
    `reviewed_at`   DATETIME     NULL,
    `reject_note`   TEXT         NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_srr_status`        (`status`),
    KEY `idx_srr_submission_id` (`submission_id`),
    KEY `idx_srr_requested_by`  (`requested_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `edit_unlock_requests` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `submission_id` INT UNSIGNED NOT NULL,
    `requested_by`  INT UNSIGNED NOT NULL,
    `reason`        TEXT         NOT NULL,
    `status`        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    `reviewed_by`   INT UNSIGNED NULL,
    `reviewed_at`   DATETIME     NULL,
    `reject_note`   TEXT         NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_eur_submission` (`submission_id`),
    KEY `idx_eur_requested`  (`requested_by`),
    KEY `idx_eur_status`     (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `office_admin_reports` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `office_admin_id` INT UNSIGNED NULL,
    `submission_id`   INT UNSIGNED NOT NULL,
    `report_type`     ENUM('order_completed','status_update') NOT NULL DEFAULT 'status_update',
    `report_data`     TEXT         NULL,
    `sent_to_main`    TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_oar_office_admin_id` (`office_admin_id`),
    KEY `idx_oar_submission_id`   (`submission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `activity_log` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED NOT NULL,
    `action`      VARCHAR(100) NOT NULL,
    `description` TEXT         NULL,
    `ip_address`  VARCHAR(64)  NULL,
    `device_info` VARCHAR(500) NULL,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_activity_user`   (`user_id`),
    KEY `idx_activity_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `area_alerts` (
    `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `sub_admin_id`  INT UNSIGNED  NOT NULL,
    `reported_lat`  DECIMAL(10,7) NULL,
    `reported_lng`  DECIMAL(10,7) NULL,
    `assigned_area` VARCHAR(200)  NULL,
    `is_read`       TINYINT(1)    NOT NULL DEFAULT 0,
    `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_area_alerts_admin` (`sub_admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `visualizer_snapshots` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `submission_id` INT UNSIGNED NOT NULL,
    `filename`      VARCHAR(255) NOT NULL DEFAULT '',
    `image_data`    MEDIUMBLOB   NULL,
    `image_mime`    VARCHAR(50)  NOT NULL DEFAULT 'image/jpeg',
    `file_size_kb`  INT UNSIGNED NULL,
    `owner_name`    VARCHAR(255) NOT NULL DEFAULT '',
    `saved_by`      INT UNSIGNED NULL,
    `notes`         VARCHAR(500) NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_vs_submission` (`submission_id`),
    KEY `idx_vs_saved_by`   (`saved_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `deleted_submissions` (
    `id`                    INT UNSIGNED  NOT NULL,
    `sub_direction_id`      INT UNSIGNED  NULL,
    `submitted_by`          INT UNSIGNED  NULL,
    `owner_name`            VARCHAR(200)  NULL,
    `owner_number`          VARCHAR(30)   NULL,
    `representative_name`   VARCHAR(200)  NULL,
    `representative_number` VARCHAR(30)   NULL,
    `village_city_town`     VARCHAR(200)  NULL,
    `street`                VARCHAR(300)  NULL,
    `latitude`              DECIMAL(10,7) NULL,
    `longitude`             DECIMAL(10,7) NULL,
    `status`                VARCHAR(50)   NULL,
    `submitted_at`          DATETIME      NULL,
    `last_edited_at`        DATETIME      NULL,
    `deal_result`           VARCHAR(100)  NULL,
    `vendor_name`           VARCHAR(200)  NULL,
    `vendor_details`        VARCHAR(200)  NULL,
    `other_reason`          TEXT          NULL,
    `detail_description`    TEXT          NULL,
    `deleted_at`            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `deleted_by`            INT UNSIGNED  NULL,
    `requested_by`          INT UNSIGNED  NULL,
    `remove_reason`         TEXT          NULL,
    `zone_code`             VARCHAR(50)   NULL,
    `dir_code`              VARCHAR(50)   NULL,
    PRIMARY KEY (`id`),
    KEY `idx_deleted_by`   (`deleted_by`),
    KEY `idx_requested_by` (`requested_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `submission_estimates` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `submission_id` INT UNSIGNED NOT NULL,
    `created_by`    INT UNSIGNED NULL,
    `updated_by`    INT UNSIGNED NULL,
    `notes`         TEXT         NULL,
    `is_finalised`  TINYINT(1)   NOT NULL DEFAULT 0,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_est_submission` (`submission_id`),
    KEY `idx_est_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `submission_estimate_rooms` (
    `id`               INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `estimate_id`      INT UNSIGNED      NOT NULL,
    `submission_id`    INT UNSIGNED      NOT NULL,
    `area_id`          INT UNSIGNED      NULL,
    `area_name`        VARCHAR(200)      NOT NULL DEFAULT '',
    `total_sqft`       DECIMAL(10,2)     NOT NULL DEFAULT 0.00,
    `tile_size_label`  VARCHAR(100)      NOT NULL DEFAULT '',
    `box_count`        INT UNSIGNED      NOT NULL DEFAULT 0,
    `mop_per_sqft`     DECIMAL(10,2)     NOT NULL DEFAULT 0.00,
    `mop_discount`     DECIMAL(5,2)      NOT NULL DEFAULT 0.00,
    `mop_total`        DECIMAL(12,2)     NOT NULL DEFAULT 0.00,
    `mrp_per_sqft`     DECIMAL(10,2)     NOT NULL DEFAULT 0.00,
    `mrp_discount`     DECIMAL(5,2)      NOT NULL DEFAULT 0.00,
    `mrp_total`        DECIMAL(12,2)     NOT NULL DEFAULT 0.00,
    `offer`            TEXT              NULL,
    `include_in_print` TINYINT(1)        NOT NULL DEFAULT 1,
    `sort_order`       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`       DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ser_estimate`   (`estimate_id`),
    KEY `idx_ser_submission` (`submission_id`),
    KEY `idx_ser_area`       (`area_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ════════════════════════════════════════════════════════════
-- BLOCK 2: MAKE FK COLUMNS NULLABLE
-- Must happen BEFORE adding SET NULL foreign keys.
-- ════════════════════════════════════════════════════════════

-- submissions.submitted_by must be NULL for SET NULL FK
CALL make_nullable('submissions','submitted_by');

-- submission_edits.edited_by must be NULL for SET NULL FK
CALL make_nullable('submission_edits','edited_by');

-- office_admin_reports.office_admin_id must be NULL for SET NULL FK
CALL make_nullable('office_admin_reports','office_admin_id');

-- users.office_admin_id and created_by must be NULL for SET NULL FK
CALL make_nullable('users','office_admin_id');
CALL make_nullable('users','created_by');

-- submission_remove_requests.reviewed_by
CALL make_nullable('submission_remove_requests','reviewed_by');

-- edit_unlock_requests.reviewed_by
CALL make_nullable('edit_unlock_requests','reviewed_by');

-- visualizer_snapshots.saved_by
CALL make_nullable('visualizer_snapshots','saved_by');

-- submission_estimates.created_by, updated_by
CALL make_nullable('submission_estimates','created_by');
CALL make_nullable('submission_estimates','updated_by');

-- submission_estimate_rooms.area_id
CALL make_nullable('submission_estimate_rooms','area_id');


-- ════════════════════════════════════════════════════════════
-- BLOCK 3: ADD MISSING COLUMNS TO EXISTING TABLES
-- ════════════════════════════════════════════════════════════

CALL safe_col('users','is_locked',           'TINYINT(1) NOT NULL DEFAULT 0');
CALL safe_col('users','password_expires_at', 'DATETIME NULL');
CALL safe_col('users','office_admin_id',     'INT UNSIGNED NULL DEFAULT NULL');
CALL safe_col('users','created_by',          'INT UNSIGNED NULL');

CALL safe_col('user_sessions','location_label',    'VARCHAR(200) NULL');
CALL safe_col('user_sessions','device_login_time', 'DATETIME NULL');

-- Widen session_log.logout_type ENUM to include 'auto'
SET @_lt = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='session_log' AND COLUMN_NAME='logout_type');
SET @_fix = IF(
    @_lt IS NOT NULL AND LOCATE('auto', @_lt) = 0,
    "ALTER TABLE `session_log` MODIFY COLUMN `logout_type` ENUM('normal','force','force_all','auto') NULL",
    "SELECT 'logout_type ok' AS note"
);
PREPARE _s FROM @_fix; EXECUTE _s; DEALLOCATE PREPARE _s;

-- submissions missing columns
CALL safe_col('submissions','alt_mobile',          'VARCHAR(20) NULL');
CALL safe_col('submissions','tile_image',          'VARCHAR(500) NULL');
CALL safe_col('submissions','other_status_reason', 'TEXT NULL');
CALL safe_col('submissions','order_completed',     'TINYINT(1) NOT NULL DEFAULT 0');
CALL safe_col('submissions','purchase_date',       'DATE NULL');
CALL safe_col('submissions','purchase_notes',      'TEXT NULL');
CALL safe_col('submissions','latitude',            'DECIMAL(10,7) NULL');
CALL safe_col('submissions','longitude',           'DECIMAL(10,7) NULL');
CALL safe_col('submissions','landmark_picture',    'VARCHAR(500) NULL');
CALL safe_col('submissions','additional_picture',  'VARCHAR(500) NULL');
CALL safe_col('submissions','device_submitted_at', 'DATETIME NULL');
CALL safe_col('submissions','unlock_time_iso',     'VARCHAR(50) NULL');
CALL safe_col('submissions','last_edited_at',      'DATETIME NULL');
CALL safe_col('submissions','next_visit_date',     'DATE NULL');
CALL safe_col('submissions','area_type',           'VARCHAR(100) NULL');
CALL safe_col('submissions','area_sqft',           'DECIMAL(10,2) NULL');
CALL safe_col('submissions','area_count',          'INT UNSIGNED NULL');

-- Widen submissions.status ENUM -> VARCHAR if needed
SET @_st = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='submissions' AND COLUMN_NAME='status');
SET @_fix2 = IF(
    @_st LIKE 'enum%',
    "ALTER TABLE `submissions` MODIFY COLUMN `status` VARCHAR(50) NOT NULL DEFAULT 'enquiry'",
    "SELECT 'submissions.status ok' AS note"
);
PREPARE _s FROM @_fix2; EXECUTE _s; DEALLOCATE PREPARE _s;

-- Fix submission_areas.area_type ENUM -> VARCHAR if needed
SET @_at = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='submission_areas' AND COLUMN_NAME='area_type');
SET @_fix3 = IF(
    @_at IS NOT NULL AND @_at LIKE 'enum%',
    "ALTER TABLE `submission_areas` MODIFY COLUMN `area_type` VARCHAR(120) NOT NULL DEFAULT ''",
    "SELECT 'area_type ok' AS note"
);
PREPARE _s FROM @_fix3; EXECUTE _s; DEALLOCATE PREPARE _s;

-- submission_areas all calculator columns
CALL safe_col('submission_areas','area_height',           'DECIMAL(6,2) NULL');
CALL safe_col('submission_areas','total_sqft',            'DECIMAL(12,2) NULL');
CALL safe_col('submission_areas','area_sqft',             'DECIMAL(12,2) NULL');
CALL safe_col('submission_areas','area_count',            'SMALLINT NULL');
CALL safe_col('submission_areas','sort_order',            'SMALLINT NOT NULL DEFAULT 0');
CALL safe_col('submission_areas','tile_chosen',           'VARCHAR(255) NOT NULL DEFAULT ''''');
CALL safe_col('submission_areas','tile_company',          'VARCHAR(120) NULL');
CALL safe_col('submission_areas','tile_design_name',      'VARCHAR(120) NULL');
CALL safe_col('submission_areas','tile_image_filename',   'VARCHAR(255) NULL');
CALL safe_col('submission_areas','tile_surface',          'VARCHAR(10) NOT NULL DEFAULT ''floor''');
CALL safe_col('submission_areas','floor_tile_size_key',   'VARCHAR(30) NOT NULL DEFAULT ''2|2|4''');
CALL safe_col('submission_areas','floor_wastage_pct',     'DECIMAL(5,2) NOT NULL DEFAULT 0.00');
CALL safe_col('submission_areas','floor_tile_custom_w',   'DECIMAL(6,2) NULL');
CALL safe_col('submission_areas','floor_tile_custom_h',   'DECIMAL(6,2) NULL');
CALL safe_col('submission_areas','floor_tile_custom_ppb', 'TINYINT UNSIGNED NULL');
CALL safe_col('submission_areas','wall_tile_size_key',    'VARCHAR(30) NOT NULL DEFAULT ''2|2|4''');
CALL safe_col('submission_areas','wall_wastage_pct',      'DECIMAL(5,2) NOT NULL DEFAULT 10.00');
CALL safe_col('submission_areas','wall_tile_custom_w',    'DECIMAL(6,2) NULL');
CALL safe_col('submission_areas','wall_tile_custom_h',    'DECIMAL(6,2) NULL');
CALL safe_col('submission_areas','wall_tile_custom_ppb',  'TINYINT UNSIGNED NULL');
CALL safe_col('submission_areas','tile_size_key',         'VARCHAR(30) NOT NULL DEFAULT ''2|2|4''');
CALL safe_col('submission_areas','wastage_pct',           'TINYINT UNSIGNED NOT NULL DEFAULT 10');
CALL safe_col('submission_areas','is_bathroom',           'TINYINT(1) NOT NULL DEFAULT 0');
CALL safe_col('submission_areas','bath_light_h',          'DECIMAL(6,2) NULL');
CALL safe_col('submission_areas','bath_high_h',           'DECIMAL(6,2) NULL');
CALL safe_col('submission_areas','bath_dark_h',           'DECIMAL(6,2) NULL');
CALL safe_col('submission_areas','bath_tile_size_key',    'VARCHAR(30) NOT NULL DEFAULT ''2|2|4''');
CALL safe_col('submission_areas','bath_tile_size',        'VARCHAR(30) NOT NULL DEFAULT ''2|2|4''');
CALL safe_col('submission_areas','bath_wastage_pct',      'TINYINT UNSIGNED NOT NULL DEFAULT 10');
CALL safe_col('submission_areas','calc_summary',          'JSON NULL');
CALL safe_col('submission_areas','updated_at',
    'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');

-- Widen submission_status_details.status if needed
SET @_ssd = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='submission_status_details' AND COLUMN_NAME='status');
SET @_fix4 = IF(
    @_ssd LIKE 'enum%',
    "ALTER TABLE `submission_status_details` MODIFY COLUMN `status` VARCHAR(50) NOT NULL DEFAULT 'enquiry'",
    "SELECT 'ssd.status ok' AS note"
);
PREPARE _s FROM @_fix4; EXECUTE _s; DEALLOCATE PREPARE _s;

-- visualizer_snapshots extra columns
CALL safe_col('visualizer_snapshots','image_data',   'MEDIUMBLOB NULL');
CALL safe_col('visualizer_snapshots','image_mime',   'VARCHAR(50) NOT NULL DEFAULT ''image/jpeg''');
CALL safe_col('visualizer_snapshots','file_size_kb', 'INT UNSIGNED NULL');
CALL safe_col('visualizer_snapshots','saved_by',     'INT UNSIGNED NULL');
CALL safe_col('visualizer_snapshots','notes',        'VARCHAR(500) NULL');


-- ════════════════════════════════════════════════════════════
-- BLOCK 4: FOREIGN KEYS
-- safe_fk drops old version, re-adds with correct rule.
-- Nullable columns already fixed in Block 2 above.
-- ════════════════════════════════════════════════════════════

CALL safe_fk('users','fk_users_office_admin',
    'FOREIGN KEY (`office_admin_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE');
CALL safe_fk('users','fk_users_created_by',
    'FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE');

CALL safe_fk('user_sessions','fk_sessions_user',
    'FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE');

CALL safe_fk('sub_directions','fk_sub_directions_parent',
    'FOREIGN KEY (`direction_id`) REFERENCES `directions`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE');

CALL safe_fk('sub_direction_stops','fk_stops_subdir',
    'FOREIGN KEY (`sub_direction_id`) REFERENCES `sub_directions`(`id`) ON DELETE CASCADE ON UPDATE CASCADE');

CALL safe_fk('sub_admin_locations','fk_sal_subadmin',
    'FOREIGN KEY (`sub_admin_id`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE');
CALL safe_fk('sub_admin_locations','fk_sal_subdir',
    'FOREIGN KEY (`sub_direction_id`) REFERENCES `sub_directions`(`id`) ON DELETE CASCADE ON UPDATE CASCADE');

CALL safe_fk('submissions','fk_submissions_user',
    'FOREIGN KEY (`submitted_by`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE');
CALL safe_fk('submissions','fk_submissions_subdir',
    'FOREIGN KEY (`sub_direction_id`) REFERENCES `sub_directions`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE');

CALL safe_fk('submission_areas','fk_areas_submission',
    'FOREIGN KEY (`submission_id`) REFERENCES `submissions`(`id`) ON DELETE CASCADE ON UPDATE CASCADE');

CALL safe_fk('submission_area_images','fk_sai_area',
    'FOREIGN KEY (`area_id`) REFERENCES `submission_areas`(`id`) ON DELETE CASCADE ON UPDATE CASCADE');
CALL safe_fk('submission_area_images','fk_sai_submission',
    'FOREIGN KEY (`submission_id`) REFERENCES `submissions`(`id`) ON DELETE CASCADE ON UPDATE CASCADE');

CALL safe_fk('submission_status_details','fk_status_details_submission',
    'FOREIGN KEY (`submission_id`) REFERENCES `submissions`(`id`) ON DELETE CASCADE ON UPDATE CASCADE');

CALL safe_fk('submission_edits','fk_edits_submission',
    'FOREIGN KEY (`submission_id`) REFERENCES `submissions`(`id`) ON DELETE CASCADE ON UPDATE CASCADE');
CALL safe_fk('submission_edits','fk_edits_editor',
    'FOREIGN KEY (`edited_by`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE');

CALL safe_fk('submission_remove_requests','fk_srr_submission',
    'FOREIGN KEY (`submission_id`) REFERENCES `submissions`(`id`) ON DELETE CASCADE ON UPDATE CASCADE');
CALL safe_fk('submission_remove_requests','fk_srr_requested_by',
    'FOREIGN KEY (`requested_by`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE');
CALL safe_fk('submission_remove_requests','fk_srr_reviewed_by',
    'FOREIGN KEY (`reviewed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE');

CALL safe_fk('edit_unlock_requests','fk_eur_submission',
    'FOREIGN KEY (`submission_id`) REFERENCES `submissions`(`id`) ON DELETE CASCADE ON UPDATE CASCADE');
CALL safe_fk('edit_unlock_requests','fk_eur_requested_by',
    'FOREIGN KEY (`requested_by`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE');
CALL safe_fk('edit_unlock_requests','fk_eur_reviewed_by',
    'FOREIGN KEY (`reviewed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE');

CALL safe_fk('office_admin_reports','fk_oar_admin',
    'FOREIGN KEY (`office_admin_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE');
CALL safe_fk('office_admin_reports','fk_oar_submission',
    'FOREIGN KEY (`submission_id`) REFERENCES `submissions`(`id`) ON DELETE CASCADE ON UPDATE CASCADE');

CALL safe_fk('activity_log','fk_activity_user',
    'FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE');

CALL safe_fk('area_alerts','fk_area_alerts_admin',
    'FOREIGN KEY (`sub_admin_id`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE');

CALL safe_fk('visualizer_snapshots','fk_vs_submission',
    'FOREIGN KEY (`submission_id`) REFERENCES `submissions`(`id`) ON DELETE CASCADE ON UPDATE CASCADE');
CALL safe_fk('visualizer_snapshots','fk_vs_saved_by',
    'FOREIGN KEY (`saved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE');

CALL safe_fk('submission_estimates','fk_est_submission',
    'FOREIGN KEY (`submission_id`) REFERENCES `submissions`(`id`) ON DELETE CASCADE ON UPDATE CASCADE');

CALL safe_fk('submission_estimate_rooms','fk_ser_estimate',
    'FOREIGN KEY (`estimate_id`) REFERENCES `submission_estimates`(`id`) ON DELETE CASCADE ON UPDATE CASCADE');
CALL safe_fk('submission_estimate_rooms','fk_ser_submission',
    'FOREIGN KEY (`submission_id`) REFERENCES `submissions`(`id`) ON DELETE CASCADE ON UPDATE CASCADE');
CALL safe_fk('submission_estimate_rooms','fk_ser_area',
    'FOREIGN KEY (`area_id`) REFERENCES `submission_areas`(`id`) ON DELETE SET NULL ON UPDATE CASCADE');


-- ════════════════════════════════════════════════════════════
-- BLOCK 5: SEED DATA
-- ════════════════════════════════════════════════════════════

INSERT INTO `users` (`username`,`password`,`role`,`is_active`,`is_locked`) VALUES
('mainadmin','$2y$10$OFLJB6D4zFXd63lkbK8TIeMlrNq33r6dTP7vr7g3wUC5s3RUJW.H.','main_admin',1,0)
ON DUPLICATE KEY UPDATE `role`='main_admin',`is_active`=1,`is_locked`=0;

INSERT INTO `users` (`username`,`password`,`role`,`is_active`,`is_locked`) VALUES
('Gurushasta','$2y$12$Akg0y3q1XsNwQ4g6WoDXJOHYK.R1pNlQaOYBZMmVn.pCn3rYEaZDO','main_admin',1,0)
ON DUPLICATE KEY UPDATE `role`='main_admin',`is_active`=1,`is_locked`=0;

INSERT INTO `directions` (`code`,`label`,`is_active`) VALUES
('NE','North East',1),('NW','North West',1),('SE','South East',1),('SW','South West',1)
ON DUPLICATE KEY UPDATE `label`=VALUES(`label`),`is_active`=1;


-- ════════════════════════════════════════════════════════════
-- BLOCK 6: CLEANUP
-- ════════════════════════════════════════════════════════════
DROP PROCEDURE IF EXISTS safe_col;
DROP PROCEDURE IF EXISTS safe_fk;
DROP PROCEDURE IF EXISTS make_nullable;

SET foreign_key_checks = 1;


-- ════════════════════════════════════════════════════════════
-- VERIFICATION REPORT
-- ════════════════════════════════════════════════════════════

-- 1. All 21 tables
SELECT TABLE_NAME AS `Table`, '✅ EXISTS' AS `Status`
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'directions','sub_directions','sub_direction_stops',
    'users','user_sessions','session_log','sub_admin_locations',
    'submissions','submission_areas','submission_area_images',
    'submission_status_details','submission_edits',
    'submission_remove_requests','edit_unlock_requests',
    'office_admin_reports','activity_log','area_alerts',
    'visualizer_snapshots','deleted_submissions',
    'submission_estimates','submission_estimate_rooms'
  )
ORDER BY TABLE_NAME;

-- 2. FK delete rules (SET NULL columns must show SET NULL)
SELECT kcu.TABLE_NAME, kcu.COLUMN_NAME, rc.CONSTRAINT_NAME, rc.DELETE_RULE
FROM information_schema.REFERENTIAL_CONSTRAINTS rc
JOIN information_schema.KEY_COLUMN_USAGE kcu
    ON rc.CONSTRAINT_NAME=kcu.CONSTRAINT_NAME
   AND rc.CONSTRAINT_SCHEMA=kcu.CONSTRAINT_SCHEMA
WHERE rc.CONSTRAINT_SCHEMA=DATABASE()
ORDER BY kcu.TABLE_NAME, rc.CONSTRAINT_NAME;

-- 3. Nullable check on SET NULL FK columns
SELECT TABLE_NAME, COLUMN_NAME, IS_NULLABLE, COLUMN_TYPE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE()
  AND (TABLE_NAME='submissions'            AND COLUMN_NAME='submitted_by')
   OR (TABLE_NAME='submission_edits'       AND COLUMN_NAME='edited_by')
   OR (TABLE_NAME='office_admin_reports'   AND COLUMN_NAME='office_admin_id')
   OR (TABLE_NAME='submission_estimate_rooms' AND COLUMN_NAME='area_id')
   OR (TABLE_NAME='users'                  AND COLUMN_NAME IN('office_admin_id','created_by'))
ORDER BY TABLE_NAME, COLUMN_NAME;

-- ============================================================
-- DONE — v7. Login: mainadmin / Admin@1234
-- ============================================================
USE u157699425_aandavar_db;
SET foreign_key_checks = 0;

-- Add mrp_per_box if missing
SET @s = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='submission_estimate_rooms'
       AND COLUMN_NAME='mrp_per_box') = 0,
    'ALTER TABLE submission_estimate_rooms ADD COLUMN mrp_per_box DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER box_count',
    'SELECT "mrp_per_box already exists" AS note'
);
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Add mop_per_box if missing
SET @s = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='submission_estimate_rooms'
       AND COLUMN_NAME='mop_per_box') = 0,
    'ALTER TABLE submission_estimate_rooms ADD COLUMN mop_per_box DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER mrp_per_box',
    'SELECT "mop_per_box already exists" AS note'
);
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Add discount_pct if missing
SET @s = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='submission_estimate_rooms'
       AND COLUMN_NAME='discount_pct') = 0,
    'ALTER TABLE submission_estimate_rooms ADD COLUMN discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER mop_per_box',
    'SELECT "discount_pct already exists" AS note'
);
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET foreign_key_checks = 1;

-- Verify
SHOW COLUMNS FROM submission_estimate_rooms;

USE u157699425_aandavar_db;
SET foreign_key_checks = 0;

-- Step 1: Add new columns if missing
ALTER TABLE submission_estimate_rooms
    ADD COLUMN IF NOT EXISTS mrp_per_box  DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER box_count,
    ADD COLUMN IF NOT EXISTS mop_per_box  DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER mrp_per_box,
    ADD COLUMN IF NOT EXISTS discount_pct DECIMAL(5,2)  NOT NULL DEFAULT 0.00 AFTER mop_per_box;

-- Step 2: Ensure mrp_total and mop_total exist (they should, but verify)
ALTER TABLE submission_estimate_rooms
    ADD COLUMN IF NOT EXISTS mrp_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS mop_total DECIMAL(12,2) NOT NULL DEFAULT 0.00;

-- Step 3: Drop old per-sqft columns if they still exist
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='submission_estimate_rooms' AND COLUMN_NAME='mrp_per_sqft')>0,
    'ALTER TABLE submission_estimate_rooms DROP COLUMN mrp_per_sqft','SELECT "ok" AS n');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='submission_estimate_rooms' AND COLUMN_NAME='mop_per_sqft')>0,
    'ALTER TABLE submission_estimate_rooms DROP COLUMN mop_per_sqft','SELECT "ok" AS n');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='submission_estimate_rooms' AND COLUMN_NAME='mrp_discount')>0,
    'ALTER TABLE submission_estimate_rooms DROP COLUMN mrp_discount','SELECT "ok" AS n');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='submission_estimate_rooms' AND COLUMN_NAME='mop_discount')>0,
    'ALTER TABLE submission_estimate_rooms DROP COLUMN mop_discount','SELECT "ok" AS n');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET foreign_key_checks = 1;

-- Verify final structure
SHOW COLUMNS FROM submission_estimate_rooms;