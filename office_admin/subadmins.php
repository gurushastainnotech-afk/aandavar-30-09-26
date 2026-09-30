<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
requireLogin('office_admin');

$db   = getDB();
$user = currentUser();

/* ── Ensure required tables exist ──────────────────────────────────────────── */
$db->exec("CREATE TABLE IF NOT EXISTS edit_unlock_requests (
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
    KEY idx_eur_sub    (submission_id),
    KEY idx_eur_req    (requested_by),
    KEY idx_eur_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS submission_area_images (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS visualizer_snapshots (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    submission_id INT UNSIGNED NOT NULL,
    filename      VARCHAR(255) NOT NULL,
    owner_name    VARCHAR(255) NOT NULL DEFAULT '',
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_vs_sub (submission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* ── Migrate area_type to free-text if it was an ENUM ─────────────────────── */
try {
    $col = $db->query("SHOW COLUMNS FROM submission_areas LIKE 'area_type'")->fetch();
    if ($col && stripos($col['Type'], 'enum') !== false)
        $db->exec("ALTER TABLE submission_areas MODIFY COLUMN area_type VARCHAR(120) NOT NULL DEFAULT ''");
} catch (Exception $e) {}

/* ── Migrate status columns to VARCHAR ───────────────────────────────────────*/
try {
    $scol = $db->query("SHOW COLUMNS FROM submissions LIKE 'status'")->fetch();
    if ($scol && stripos($scol['Type'], 'enum') !== false)
        $db->exec("ALTER TABLE submissions MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'enquiry'");
} catch (Exception $e) {}
try {
    $ssd = $db->query("SHOW COLUMNS FROM submission_status_details LIKE 'status'")->fetch();
    if ($ssd && stripos($ssd['Type'], 'enum') !== false)
        $db->exec("ALTER TABLE submission_status_details MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'enquiry'");
} catch (Exception $e) {}

/* ── Column existence checks ──────────────────────────────────────────────── */
$columnChecks = [
    'tile_chosen'          => "ALTER TABLE submission_areas ADD COLUMN tile_chosen VARCHAR(255) NOT NULL DEFAULT '' AFTER area_count",
    'tile_size_key'        => "ALTER TABLE submission_areas ADD COLUMN tile_size_key VARCHAR(30) NOT NULL DEFAULT '2|2|4' AFTER tile_chosen",
    'wastage_pct'          => "ALTER TABLE submission_areas ADD COLUMN wastage_pct TINYINT UNSIGNED NOT NULL DEFAULT 10 AFTER tile_size_key",
    'tile_surface'         => "ALTER TABLE submission_areas ADD COLUMN tile_surface VARCHAR(10) NOT NULL DEFAULT 'floor' AFTER wastage_pct",
    'wall_tile_size_key'   => "ALTER TABLE submission_areas ADD COLUMN wall_tile_size_key VARCHAR(30) NOT NULL DEFAULT '2|2|4' AFTER tile_surface",
    'wall_wastage_pct'     => "ALTER TABLE submission_areas ADD COLUMN wall_wastage_pct TINYINT UNSIGNED NOT NULL DEFAULT 10 AFTER wall_tile_size_key",
    'floor_tile_size_key'  => "ALTER TABLE submission_areas ADD COLUMN floor_tile_size_key VARCHAR(30) NOT NULL DEFAULT '2|2|4' AFTER wall_wastage_pct",
    'floor_wastage_pct'    => "ALTER TABLE submission_areas ADD COLUMN floor_wastage_pct TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER floor_tile_size_key",
    'step_count'           => "ALTER TABLE submission_areas ADD COLUMN step_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER floor_wastage_pct",
    'riser_count'          => "ALTER TABLE submission_areas ADD COLUMN riser_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER step_count",
    'step_tile_size_key'   => "ALTER TABLE submission_areas ADD COLUMN step_tile_size_key VARCHAR(30) NOT NULL DEFAULT '3|1|1' AFTER riser_count",
    'step_wastage_pct'     => "ALTER TABLE submission_areas ADD COLUMN step_wastage_pct DECIMAL(5,2) NOT NULL DEFAULT 10.00 AFTER step_tile_size_key",
    'riser_tile_size_key'  => "ALTER TABLE submission_areas ADD COLUMN riser_tile_size_key VARCHAR(30) NOT NULL DEFAULT '3|0.8|1' AFTER step_wastage_pct",
    'riser_wastage_pct'    => "ALTER TABLE submission_areas ADD COLUMN riser_wastage_pct DECIMAL(5,2) NOT NULL DEFAULT 10.00 AFTER riser_tile_size_key",
    'step_riser_tile_size_key' => "ALTER TABLE submission_areas ADD COLUMN step_riser_tile_size_key VARCHAR(30) NOT NULL DEFAULT '3|1|1' AFTER riser_wastage_pct",
    'step_riser_wastage_pct'  => "ALTER TABLE submission_areas ADD COLUMN step_riser_wastage_pct DECIMAL(5,2) NOT NULL DEFAULT 10.00 AFTER step_riser_tile_size_key",
    'is_bathroom'          => "ALTER TABLE submission_areas ADD COLUMN is_bathroom TINYINT(1) NOT NULL DEFAULT 0 AFTER floor_wastage_pct",
    // Custom tile size JSON storage (w, h, ppb)
    'floor_tile_custom'    => "ALTER TABLE submission_areas ADD COLUMN floor_tile_custom JSON NULL AFTER floor_wastage_pct",
    'wall_tile_custom'     => "ALTER TABLE submission_areas ADD COLUMN wall_tile_custom JSON NULL AFTER wall_wastage_pct",
    'step_tile_custom'     => "ALTER TABLE submission_areas ADD COLUMN step_tile_custom JSON NULL AFTER step_wastage_pct",
    'riser_tile_custom'    => "ALTER TABLE submission_areas ADD COLUMN riser_tile_custom JSON NULL AFTER riser_wastage_pct",
    'step_riser_tile_custom'=> "ALTER TABLE submission_areas ADD COLUMN step_riser_tile_custom JSON NULL AFTER step_riser_wastage_pct",
    'area_height'          => "ALTER TABLE submission_areas ADD COLUMN area_height DECIMAL(6,2) NULL AFTER area_breadth",
    'lengths'              => "ALTER TABLE submission_areas ADD COLUMN lengths JSON NULL AFTER area_height",
    'breadths'             => "ALTER TABLE submission_areas ADD COLUMN breadths JSON NULL AFTER lengths",
    'heights'              => "ALTER TABLE submission_areas ADD COLUMN heights JSON NULL AFTER breadths",
    'floor_sqft'           => "ALTER TABLE submission_areas ADD COLUMN floor_sqft DECIMAL(12,2) NULL AFTER total_sqft",
    'wall_sqft'            => "ALTER TABLE submission_areas ADD COLUMN wall_sqft DECIMAL(12,2) NULL AFTER floor_sqft",
    'riser_landing_length' => "ALTER TABLE submission_areas ADD COLUMN riser_landing_length DECIMAL(10,2) NULL AFTER riser_wastage_pct",
    'riser_landing_breadth'=> "ALTER TABLE submission_areas ADD COLUMN riser_landing_breadth DECIMAL(10,2) NULL AFTER riser_landing_length",
    'riser_landing_sqft'   => "ALTER TABLE submission_areas ADD COLUMN riser_landing_sqft DECIMAL(12,2) NULL AFTER riser_landing_breadth",
    'riser_landing_tiles'  => "ALTER TABLE submission_areas ADD COLUMN riser_landing_tiles INT UNSIGNED NOT NULL DEFAULT 0 AFTER riser_landing_sqft",
];
foreach ($columnChecks as $col => $sql) {
    try {
        $chk = $db->query("SHOW COLUMNS FROM submission_areas LIKE '$col'")->fetch();
        if (!$chk) $db->exec($sql);
    } catch (Exception $e) {}
}

/* Bathroom partition columns */
try {
    $bpCheck = $db->query("SHOW COLUMNS FROM submission_areas LIKE 'bath_light_h'")->fetch();
    if (!$bpCheck) {
        $db->exec("ALTER TABLE submission_areas
            ADD COLUMN bath_light_h       DECIMAL(6,2) NULL AFTER is_bathroom,
            ADD COLUMN bath_high_h        DECIMAL(6,2) NULL AFTER bath_light_h,
            ADD COLUMN bath_high2_h       DECIMAL(6,2) NULL AFTER bath_high_h,
            ADD COLUMN bath_dark_h        DECIMAL(6,2) NULL AFTER bath_high2_h,
            ADD COLUMN bath_fall_h        DECIMAL(6,2) NULL AFTER bath_dark_h,
            ADD COLUMN bath_tile_size_key VARCHAR(30)  NOT NULL DEFAULT '2|2|4' AFTER bath_fall_h,
            ADD COLUMN bath_wastage_pct   TINYINT UNSIGNED NOT NULL DEFAULT 10   AFTER bath_tile_size_key");
    }
} catch (Exception $e) {}

try {
    $bh2Check = $db->query("SHOW COLUMNS FROM submission_areas LIKE 'bath_high2_h'")->fetch();
    if (!$bh2Check) {
        $db->exec("ALTER TABLE submission_areas ADD COLUMN bath_high2_h DECIMAL(6,2) NULL AFTER bath_high_h");
    }
} catch (Exception $e) {}

try {
    $bfCheck = $db->query("SHOW COLUMNS FROM submission_areas LIKE 'bath_fall_h'")->fetch();
    if (!$bfCheck) {
        $db->exec("ALTER TABLE submission_areas ADD COLUMN bath_fall_h DECIMAL(6,2) NULL AFTER bath_dark_h");
    }
} catch (Exception $e) {}

try {
    $btCustom = $db->query("SHOW COLUMNS FROM submission_areas LIKE 'bath_tile_custom'")->fetch();
    if (!$btCustom) {
        $db->exec("ALTER TABLE submission_areas ADD COLUMN bath_tile_custom JSON NULL AFTER bath_wastage_pct");
    }
} catch (Exception $e) {}

try {
    $oldCol = $db->query("SHOW COLUMNS FROM submission_areas LIKE 'bath_tile_size'")->fetch();
    $newCol = $db->query("SHOW COLUMNS FROM submission_areas LIKE 'bath_tile_size_key'")->fetch();
    if ($oldCol && !$newCol)
        $db->exec("ALTER TABLE submission_areas CHANGE COLUMN bath_tile_size bath_tile_size_key VARCHAR(30) NOT NULL DEFAULT '2|2|4'");
} catch (Exception $e) {}

try {
    $csCol = $db->query("SHOW COLUMNS FROM submission_areas LIKE 'calc_summary'")->fetch();
    if (!$csCol)
        $db->exec("ALTER TABLE submission_areas ADD COLUMN calc_summary JSON NULL AFTER bath_wastage_pct");
} catch (Exception $e) {}

try {
    $db->exec("UPDATE submission_areas
               SET total_sqft = COALESCE(floor_sqft,0) + COALESCE(wall_sqft,0),
                   area_sqft = COALESCE(floor_sqft,0) + COALESCE(wall_sqft,0)
               WHERE tile_surface IN ('floor','wall','both')
                 AND (
                      total_sqft IS NULL
                      OR ABS(COALESCE(total_sqft,0) - (COALESCE(floor_sqft,0) + COALESCE(wall_sqft,0))) > 0.01
                 )");
} catch (Exception $e) {}

try {
    $orCheck = $db->query("SHOW COLUMNS FROM submissions LIKE 'other_status_reason'")->fetch();
    if (!$orCheck)
        $db->exec("ALTER TABLE submissions ADD COLUMN other_status_reason TEXT NULL AFTER status");
} catch (Exception $e) {}

try {
    $editorCheck = $db->query("SHOW COLUMNS FROM submissions LIKE 'last_edited_by'")->fetch();
    if (!$editorCheck)
        $db->exec("ALTER TABLE submissions ADD COLUMN last_edited_by INT UNSIGNED NULL AFTER last_edited_at");
} catch (Exception $e) {}

/* ── Ensure uploads directory exists ──────────────────────────────────────── */
if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0775, true);

/* ── Image URL helper ─────────────────────────────────────────────────────── */
function imgUrl(string $f): string {
    if ($f === '') return '';
    return rtrim(UPLOAD_URL, '/') . '/' . ltrim($f, '/');
}

/* ── Save a base64 camera image ───────────────────────────────────────────── */
function sa_saveCamImg(string $b64): ?string {
    if (empty($b64) || !str_contains($b64, ',')) return null;
    $data = base64_decode(explode(',', $b64, 2)[1]);
    if (!$data || strlen($data) < 100) return null;
    $fname = 'ai_cam_' . bin2hex(random_bytes(8)) . '.jpg';
    if (file_put_contents(UPLOAD_DIR . $fname, $data) === false) return null;
    return $fname;
}

/* ── Save an uploaded file ────────────────────────────────────────────────── */
function sa_saveUploadedImg(array $file): ?string {
    if ($file['error'] !== UPLOAD_ERR_OK) return null;
    if (!is_uploaded_file($file['tmp_name'])) return null;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp'])) return null;
    $fname = 'ai_img_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $fname)) return null;
    return $fname;
}

/* ── Insert one area image row ────────────────────────────────────────────── */
function sa_insertImg(PDO $db, int $areaId, int $subId, string $fname, string $type, int $order): void {
    $db->prepare("INSERT INTO submission_area_images
                  (area_id,submission_id,image_filename,image_type,sort_order)
                  VALUES (?,?,?,?,?)")
       ->execute([$areaId, $subId, $fname, $type, $order]);
}

function sa_sanitiseCalcSummary(string $raw): ?string {
    if (trim($raw) === '' || trim($raw) === '{}') return null;
    $dec = json_decode($raw, true);
    if (!is_array($dec)) return null;
    $allowed = ['sqft','surf','floor','wall','bath','meta'];
    $clean   = array_intersect_key($dec, array_flip($allowed));
    if (empty($clean)) return null;
    return json_encode($clean, JSON_UNESCAPED_UNICODE);
}

function sa_deleteAreas(PDO $db, int $submissionId, array $areaIds, ?int $estimateId = null): void {
    $areaIds = array_values(array_unique(array_filter(array_map('intval', $areaIds))));
    if (empty($areaIds)) return;

    $placeholders = implode(',', array_fill(0, count($areaIds), '?'));

    $imgQ = $db->prepare("SELECT image_filename FROM submission_area_images WHERE area_id IN ($placeholders) AND submission_id=?");
    $imgQ->execute(array_merge($areaIds, [$submissionId]));
    foreach ($imgQ->fetchAll() as $imgRow) {
        $fp = UPLOAD_DIR . $imgRow['image_filename'];
        if (file_exists($fp)) @unlink($fp);
    }

    $db->prepare("DELETE FROM submission_area_images WHERE area_id IN ($placeholders) AND submission_id=?")
       ->execute(array_merge($areaIds, [$submissionId]));
    $db->prepare("DELETE FROM submission_areas WHERE id IN ($placeholders) AND submission_id=?")
       ->execute(array_merge($areaIds, [$submissionId]));

    if ($estimateId) {
        $db->prepare("DELETE FROM submission_estimate_rooms WHERE estimate_id=? AND area_id IN ($placeholders)")
           ->execute(array_merge([$estimateId], $areaIds));
    }
}

/* ── Fetch ONLY this office-admin's sub-admins ────────────────────────────── */
/* ── Fetch ALL sub-admins across the system ────────────────────────────────── */
$mySubsQ = $db->prepare(
    "SELECT u.*,
            (SELECT COUNT(*) FROM user_sessions WHERE user_id=u.id AND is_active=1)            AS active_sessions,
            (SELECT COUNT(*) FROM submissions    WHERE submitted_by=u.id)                       AS total_subs,
            (SELECT COUNT(*) FROM submissions    WHERE submitted_by=u.id AND status='billed')   AS hot_subs,
            (SELECT COUNT(*) FROM submissions    WHERE submitted_by=u.id AND order_completed=1) AS completed_subs
     FROM users u
     WHERE u.role = 'sub_admin'
     ORDER BY u.username"
);
$mySubsQ->execute();
$mySubs = $mySubsQ->fetchAll();

/* ── PRG redirect ─────────────────────────────────────────────────────────── */
function redirectBack(string $msg, string $type = 'success'): never {
    $qs = [];
    if (isset($_GET['phone_lookup']) && $_GET['phone_lookup'] !== '') $qs['phone_lookup'] = $_GET['phone_lookup'];
    if (empty($qs['phone_lookup']) && !empty($_POST['lookup_phone']))  $qs['phone_lookup'] = $_POST['lookup_phone'];
    $qs['flash']      = $msg;
    $qs['flash_type'] = $type;
    header('Location: subadmins.php?' . http_build_query($qs));
    exit;
}

/* ═══════════════════════════════════════════════════════════════════════════
   POST ACTIONS
═══════════════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    $getOwnedSub = function(int $id) use ($db): array|false {
        if (!$id) return false;
        $stmt = $db->prepare("SELECT * FROM submissions WHERE id=?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: false;
    };

    /* ── Upload visualizer output image ───────────────────────────────────── */
    if ($action === 'upload_viz_snapshot') {
        $subId     = (int)($_POST['submission_id'] ?? 0);
        $ownerName = trim($_POST['owner_name'] ?? '');
        if (!$subId) redirectBack('Invalid submission ID.', 'error');
        if (!is_dir(UPLOAD_DIR) && !@mkdir(UPLOAD_DIR, 0775, true)) redirectBack('Upload directory could not be created.', 'error');
        if (!is_writable(UPLOAD_DIR)) redirectBack('Upload directory is not writable. Contact administrator.', 'error');

        $saved = false; $fname = '';

        /* Only file upload — no camera/base64 path */
        if (isset($_FILES['snapshot_file']) && is_array($_FILES['snapshot_file']['name'])) {
            /* Multiple files */
            $names  = (array)$_FILES['snapshot_file']['name'];
            $tmps   = (array)$_FILES['snapshot_file']['tmp_name'];
            $errors = (array)$_FILES['snapshot_file']['error'];
            $savedCount = 0;
            foreach ($tmps as $k => $tmp) {
                if ($errors[$k] !== UPLOAD_ERR_OK) continue;
                if (!is_uploaded_file($tmp)) continue;
                $ext = strtolower(pathinfo($names[$k], PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg','jpeg','png','webp'])) continue;
                if (!@getimagesize($tmp)) continue;
                $fn = 'viz_snap_' . $subId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (move_uploaded_file($tmp, UPLOAD_DIR . $fn)) {
                    $db->prepare("INSERT INTO visualizer_snapshots (submission_id, filename, owner_name, created_at) VALUES (?, ?, ?, NOW())")
                       ->execute([$subId, $fn, $ownerName]);
                    logActivity($user['id'], 'oa_viz_snapshot', "sub=$subId file=$fn");
                    $savedCount++;
                    $saved = true;
                }
            }
            if ($saved) redirectBack("🎨 {$savedCount} visualizer image(s) saved successfully!", 'success');
        } elseif (isset($_FILES['snapshot_file']) && $_FILES['snapshot_file']['error'] === UPLOAD_ERR_OK) {
            /* Single file fallback */
            $tmpPath = $_FILES['snapshot_file']['tmp_name'];
            if (!is_uploaded_file($tmpPath)) redirectBack('Invalid file upload.', 'error');
            $ext = strtolower(pathinfo($_FILES['snapshot_file']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','webp'])) redirectBack('Only JPEG, PNG, WebP images are allowed.', 'error');
            if (!@getimagesize($tmpPath)) redirectBack('Uploaded file is not a valid image.', 'error');
            $fname = 'viz_snap_' . $subId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (!move_uploaded_file($tmpPath, UPLOAD_DIR . $fname)) redirectBack('Could not save image.', 'error');
            $db->prepare("INSERT INTO visualizer_snapshots (submission_id, filename, owner_name, created_at) VALUES (?, ?, ?, NOW())")
               ->execute([$subId, $fname, $ownerName]);
            logActivity($user['id'], 'oa_viz_snapshot', "sub=$subId file=$fname");
            redirectBack('🎨 Visualizer image saved successfully!', 'success');
        }

        redirectBack('❌ No valid images received.', 'error');
    }

    /* ── ACTION: edit_status ──────────────────────────────────────────────── */
    if ($action === 'edit_status') {
        try {
            $id   = (int)($_POST['submission_id'] ?? 0);
            $orig = $getOwnedSub($id);
            if (!$orig) redirectBack('Record not found.', 'error');

            $allowedStatuses = ['enquiry','billed','outside_purchase','others'];
            $newStatus   = in_array($_POST['status'] ?? '', $allowedStatuses) ? $_POST['status'] : ($orig['status'] ?: 'enquiry');
        $otherReason = trim($_POST['other_status_reason'] ?? '');
        $purchDate   = trim($_POST['purchase_date']  ?? '') ?: null;
        $nextVisitDate = trim($_POST['next_visit_date'] ?? '') ?: null;
        $purchNotes  = trim($_POST['purchase_notes'] ?? '');
        $orderDone   = ($newStatus === 'billed') ? 1 : 0;
        $detDesc     = trim($_POST['detail_description'] ?? '');
        $vendorName  = trim($_POST['vendor_name']   ?? '');
        $vendorDet   = trim($_POST['vendor_details'] ?? '');

        if ($nextVisitDate !== null) {
            $parsedVisitDate = DateTime::createFromFormat('!Y-m-d', $nextVisitDate);
            $dateErrors = DateTime::getLastErrors();
            if (!$parsedVisitDate || ($dateErrors !== false && ($dateErrors['warning_count'] || $dateErrors['error_count'])) || $parsedVisitDate->format('Y-m-d') !== $nextVisitDate) {
                redirectBack('Please enter the next visit date in YYYY-MM-DD format.', 'error');
            }
        }

        if ($newStatus !== $orig['status']) {
            $db->prepare("INSERT INTO submission_edits (submission_id,edited_by,field_name,old_value,new_value) VALUES (?,?,?,?,?)")
               ->execute([$id, $user['id'], 'status', $orig['status'], $newStatus]);
        }
        if (($orig['next_visit_date'] ?: null) !== $nextVisitDate) {
            $db->prepare("INSERT INTO submission_edits (submission_id,edited_by,field_name,old_value,new_value) VALUES (?,?,?,?,?)")
               ->execute([$id, $user['id'], 'next_visit_date', $orig['next_visit_date'], $nextVisitDate]);
        }

        $db->prepare("UPDATE submissions
                      SET status=?,order_completed=?,purchase_date=?,purchase_notes=?,
                          other_status_reason=?,next_visit_date=?,last_edited_at=NOW(),last_edited_by=?
                      WHERE id=?")
           ->execute([$newStatus, $orderDone, $purchDate, $purchNotes, $otherReason, $nextVisitDate, $user['id'], $id]);

        $ex = $db->prepare("SELECT id FROM submission_status_details WHERE submission_id=?");
        $ex->execute([$id]);
        if ($ex->fetchColumn()) {
            $db->prepare("UPDATE submission_status_details
                          SET detail_description=?,vendor_name=?,vendor_details=?,
                              other_reason=?,status=?,next_visit_date=?,updated_at=NOW()
                          WHERE submission_id=?")
               ->execute([$detDesc, $vendorName, $vendorDet, $otherReason, $newStatus, $nextVisitDate, $id]);
        } else {
            $db->prepare("INSERT INTO submission_status_details
                          (submission_id,detail_description,vendor_name,vendor_details,other_reason,status,next_visit_date)
                          VALUES (?,?,?,?,?,?,?)")
               ->execute([$id, $detDesc, $vendorName, $vendorDet, $otherReason, $newStatus, $nextVisitDate]);
        }

        $db->prepare("INSERT INTO office_admin_reports
                      (office_admin_id,submission_id,report_type,report_data,sent_to_main)
                      VALUES (?,?,?,?,1)")
           ->execute([$user['id'], $id,
             $orderDone ? 'order_completed' : 'status_update',
             json_encode(['old_status'=>$orig['status'],'new_status'=>$newStatus,
                          'purchase_date'=>$purchDate,'next_visit_date'=>$nextVisitDate,
                          'office_admin'=>$user['username']])]);

        $resolveWastage = function(string $prefix, string $surface, string $sk) : int {
            $key = $prefix . '_' . $surface . '_wastage_pct';
            $val = $_POST[$key][$sk] ?? '0';
            if ($val === 'custom') {
                $custKey = $prefix . '_' . $surface . '_wastage_custom';
                return max(0, (int)($_POST[$custKey][$sk] ?? 0));
            }
            return max(0, (int)$val);
        };

        $parseDimensionArray = function(string $field, string $sk) : array {
            $raw = $_POST[$field][$sk] ?? null;
            if (is_array($raw)) {
                return array_values(array_filter($raw, function($v) { return trim((string)$v) !== ''; }));
            }
            if (!is_string($raw)) {
                return [];
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return [];
            }
            return array_values(array_filter($decoded, function($v) { return trim((string)$v) !== ''; }));
        };

        $calcTileArea = function(string $tileKey, ?string $customJson = null): float {
            if ($tileKey === 'custom' && $customJson) {
                $custom = json_decode($customJson, true);
                if (is_array($custom)) {
                    return max(0.0, (float)($custom['w'] ?? 0) * (float)($custom['h'] ?? 0));
                }
            }
            if (!$tileKey || $tileKey === 'custom') return 0.0;
            $p = explode('|', $tileKey);
            return max(0.0, (float)($p[0] ?? 0) * (float)($p[1] ?? 0));
        };

        $calcRowsSqft = function(array $lengths, array $breadths, array $heights): float {
            $max = max(count($lengths), count($breadths), count($heights));
            $total = 0.0;
            for ($i = 0; $i < $max; $i++) {
                $total += (float)($lengths[$i] ?? 0) * (float)($breadths[$i] ?? 0) * (float)($heights[$i] ?? 0);
            }
            return round($total, 2);
        };

        /* ── EXISTING ROOMS — update ──────────────────────────────────────── */
        $eAreaIds = $_POST['edit_area_id'] ?? [];

        $existingAreaStmt = $db->prepare("SELECT id FROM submission_areas WHERE submission_id=?");
        $existingAreaStmt->execute([$id]);
        $existingAreaIds = array_map('intval', $existingAreaStmt->fetchAll(PDO::FETCH_COLUMN));
        $postedAreaIds = array_values(array_filter(array_map('intval', (array)$eAreaIds)));
        $explicitDeleteIds = array_values(array_filter(array_map('intval', $_POST['delete_area_ids'] ?? [])));
        $deletedAreaIds = array_values(array_unique(array_merge(
            array_diff($existingAreaIds, $postedAreaIds),
            $explicitDeleteIds
        )));

        if (!empty($deletedAreaIds)) {
            sa_deleteAreas($db, $id, $deletedAreaIds, isset($estId) ? (int)$estId : null);
        }

        $aUpd = $db->prepare(
            "UPDATE submission_areas SET
                area_type=?,area_length=?,area_breadth=?,area_height=?,
                lengths=?,breadths=?,heights=?,
                total_sqft=?,area_sqft=?,floor_sqft=?,wall_sqft=?,area_count=?,
                tile_chosen=?,tile_surface=?,
            wall_tile_size_key=?,wall_wastage_pct=?,wall_tile_custom=?,
            floor_tile_size_key=?,floor_wastage_pct=?,floor_tile_custom=?,
                is_bathroom=?,bath_light_h=?,bath_high_h=?,bath_high2_h=?,bath_dark_h=?,bath_fall_h=?,
                bath_tile_size_key=?,bath_wastage_pct=?,
            step_count=?,step_tile_size_key=?,step_wastage_pct=?,step_tile_custom=?,
            riser_count=?,riser_tile_size_key=?,riser_wastage_pct=?,riser_tile_custom=?,
            riser_landing_length=?,riser_landing_breadth=?,riser_landing_sqft=?,riser_landing_tiles=?,
            step_riser_tile_size_key=?,step_riser_wastage_pct=?,step_riser_tile_custom=?,
                calc_summary=?
             WHERE id=? AND submission_id=?"
        );

        foreach ($eAreaIds as $sk => $aId) {
            $aId = (int)$aId;
            if (!$aId) continue;
            $at   = trim($_POST['edit_area_type'][$sk]    ?? '');
            $lengths = $parseDimensionArray('edit_lengths_json', $sk);
            if (empty($lengths) && isset($_POST['edit_lengths'][$sk])) {
                $lengths = array_values(array_filter((array)$_POST['edit_lengths'][$sk], function($v) { return trim((string)$v) !== ''; }));
            }
            $breadths = $parseDimensionArray('edit_breadths_json', $sk);
            if (empty($breadths) && isset($_POST['edit_breadths'][$sk])) {
                $breadths = array_values(array_filter((array)$_POST['edit_breadths'][$sk], function($v) { return trim((string)$v) !== ''; }));
            }
            $heights = $parseDimensionArray('edit_heights_json', $sk);
            if (empty($heights) && isset($_POST['edit_heights'][$sk])) {
                $heights = array_values(array_filter((array)$_POST['edit_heights'][$sk], function($v) { return trim((string)$v) !== ''; }));
            }
            $lengths = array_filter($lengths, function($v){ return trim((string)$v) !== ''; });
            $breadths = array_filter($breadths, function($v){ return trim((string)$v) !== ''; });
            $heights = array_filter($heights, function($v){ return trim((string)$v) !== ''; });
            $al = ($_POST['edit_area_length'][$sk] ?? '') !== '' ? (float)$_POST['edit_area_length'][$sk] : null;
            if ($al === null && $lengths) { $al = 0.0; foreach ($lengths as $v) { $al += (float)$v; } $al = round($al, 2); }
            $ab = ($_POST['edit_area_breadth'][$sk] ?? '') !== '' ? (float)$_POST['edit_area_breadth'][$sk] : null;
            if ($ab === null && $breadths) { $ab = 0.0; foreach ($breadths as $v) { $ab += (float)$v; } $ab = round($ab, 2); }
            $ah = ($_POST['edit_area_height'][$sk] ?? '') !== '' ? (float)$_POST['edit_area_height'][$sk] : null;
            if ($ah === null && $heights) { $ah = 0.0; foreach ($heights as $v) { $ah += (float)$v; } $ah = round($ah, 2); }
            $ac   = ($_POST['edit_area_count'][$sk]   ?? '') !== '' ? (int)$_POST['edit_area_count'][$sk]     : null;
            $tc   = trim($_POST['edit_tile_chosen'][$sk]      ?? '');
            $ts   = trim($_POST['edit_tile_surface'][$sk]     ?? 'floor');
            $ftsk = trim($_POST['edit_floor_tile_size_key'][$sk] ?? '2|2|4');
            $fwp  = $resolveWastage('edit', 'floor', $sk);
            $wtsk = trim($_POST['edit_wall_tile_size_key'][$sk]  ?? '2|2|4');
            $wwp  = $resolveWastage('edit', 'wall', $sk);
            $ib   = (($_POST['edit_is_bathroom'][$sk] ?? '0') === '1') ? 1 : 0;
            $blh  = ($_POST['edit_bath_light_h'][$sk] ?? '') !== '' ? (float)$_POST['edit_bath_light_h'][$sk] : null;
            $bhh  = ($_POST['edit_bath_high_h'][$sk]  ?? '') !== '' ? (float)$_POST['edit_bath_high_h'][$sk]  : null;
            $bh2  = ($_POST['edit_bath_high2_h'][$sk] ?? '') !== '' ? (float)$_POST['edit_bath_high2_h'][$sk] : null;
            $bdh  = ($_POST['edit_bath_dark_h'][$sk]  ?? '') !== '' ? (float)$_POST['edit_bath_dark_h'][$sk]  : null;
            $bfh  = ($_POST['edit_bath_fall_h'][$sk]  ?? '') !== '' ? (float)$_POST['edit_bath_fall_h'][$sk]  : null;
            $btsk = '2|2|4';
            $bwp  = 0;
            $stsk = trim($_POST['edit_step_tile_size_key'][$sk] ?? '3|1|1');
            $swp  = $resolveWastage('edit', 'step', $sk);
            $rtsk = trim($_POST['edit_riser_tile_size_key'][$sk] ?? '3|0.8|1');
            $rwp  = $resolveWastage('edit', 'riser', $sk);
            $srtsk = trim($_POST['edit_step_riser_tile_size_key'][$sk] ?? $stsk);
            $srwp  = $resolveWastage('edit', 'step_riser', $sk) ?: $swp;

            // Custom tile JSON values (prefixes: f,w,s,r,sr)
            $floorCustom = null;
            $fwW = $_POST['edit_f_custw'][$sk] ?? '';
            $fwH = $_POST['edit_f_custh'][$sk] ?? '';
            $fwP = $_POST['edit_f_custppb'][$sk] ?? '';
            if (trim((string)$fwW) !== '' || trim((string)$fwH) !== '' || trim((string)$fwP) !== '') {
              $floorCustom = json_encode(['w'=> (float)$fwW, 'h'=> (float)$fwH, 'ppb'=> (int)$fwP], JSON_UNESCAPED_UNICODE);
            }
            $wallCustom = null;
            $wwW = $_POST['edit_w_custw'][$sk] ?? '';
            $wwH = $_POST['edit_w_custh'][$sk] ?? '';
            $wwP = $_POST['edit_w_custppb'][$sk] ?? '';
            if (trim((string)$wwW) !== '' || trim((string)$wwH) !== '' || trim((string)$wwP) !== '') {
              $wallCustom = json_encode(['w'=> (float)$wwW, 'h'=> (float)$wwH, 'ppb'=> (int)$wwP], JSON_UNESCAPED_UNICODE);
            }
            $stepCustom = null;
            $sWw = $_POST['edit_s_custw'][$sk] ?? $_POST['edit_scustw'][$sk] ?? '';
            $sWh = $_POST['edit_s_custh'][$sk] ?? $_POST['edit_scusth'][$sk] ?? '';
            $sWp = $_POST['edit_s_custppb'][$sk] ?? $_POST['edit_scustppb'][$sk] ?? '';
            if (trim((string)$sWw) !== '' || trim((string)$sWh) !== '' || trim((string)$sWp) !== '') {
              $stepCustom = json_encode(['w'=> (float)$sWw, 'h'=> (float)$sWh, 'ppb'=> (int)$sWp], JSON_UNESCAPED_UNICODE);
            }
            $riserCustom = null;
            $rWw = $_POST['edit_r_custw'][$sk] ?? '';
            $rWh = $_POST['edit_r_custh'][$sk] ?? '';
            $rWp = $_POST['edit_r_custppb'][$sk] ?? '';
            if (trim((string)$rWw) !== '' || trim((string)$rWh) !== '' || trim((string)$rWp) !== '') {
              $riserCustom = json_encode(['w'=> (float)$rWw, 'h'=> (float)$rWh, 'ppb'=> (int)$rWp], JSON_UNESCAPED_UNICODE);
            }
            $srCustom = null;
            $srWw = $_POST['edit_sr_custw'][$sk] ?? $_POST['edit_srcustw'][$sk] ?? '';
            $srWh = $_POST['edit_sr_custh'][$sk] ?? $_POST['edit_srcusth'][$sk] ?? '';
            $srWp = $_POST['edit_sr_custppb'][$sk] ?? $_POST['edit_srcustppb'][$sk] ?? '';
            if (trim((string)$srWw) !== '' || trim((string)$srWh) !== '' || trim((string)$srWp) !== '') {
              $srCustom = json_encode(['w'=> (float)$srWw, 'h'=> (float)$srWh, 'ppb'=> (int)$srWp], JSON_UNESCAPED_UNICODE);
            }
            $postedFloorSqft = ($_POST['edit_floor_sqft'][$sk] ?? '') !== '' ? (float)$_POST['edit_floor_sqft'][$sk] : null;
            $postedWallSqft  = ($_POST['edit_wall_sqft'][$sk] ?? '') !== '' ? (float)$_POST['edit_wall_sqft'][$sk] : null;
            $postedTotalSqft = ($_POST['edit_total_sqft'][$sk] ?? '') !== '' ? (float)$_POST['edit_total_sqft'][$sk] : null;
            $tot  = $postedTotalSqft;
            if ($tot === null) {
                $tot = $calcRowsSqft(array_values($lengths), array_values($breadths), array_values($heights));
                $tot = $tot > 0 ? $tot : (($al && $ab) ? round($al * $ab, 2) : null);
            }
            $floorSqft = $postedFloorSqft ?? $tot;
            $wallSqft  = $postedWallSqft  ?? $tot;
            $cs   = sa_sanitiseCalcSummary($_POST['edit_calc_summary'][$sk] ?? '');
            // NEW STEP/RISER SUPPORT: Extract and save step/riser counts
            $sc   = (int)($_POST['edit_step_count'][$sk]  ?? 0);
            $rc   = (int)($_POST['edit_riser_count'][$sk] ?? 0);
            $rll  = ($_POST['edit_riser_landing_length'][$sk] ?? '') !== '' ? (float)$_POST['edit_riser_landing_length'][$sk] : null;
            $rlb  = ($_POST['edit_riser_landing_breadth'][$sk] ?? '') !== '' ? (float)$_POST['edit_riser_landing_breadth'][$sk] : null;
            $rls  = ($rll && $rlb) ? round($rll * $rlb, 2) : null;
            $landingTileKey = $ts === 'step' ? $stsk : ($ts === 'step_riser' ? $srtsk : $rtsk);
            $landingCustom = $ts === 'step' ? $stepCustom : ($ts === 'step_riser' ? $srCustom : $riserCustom);
            $rTileArea = $calcTileArea($landingTileKey, $landingCustom);
            $rlt  = ($rls && $rTileArea > 0) ? (int)ceil($rls / $rTileArea) : 0;
            $aUpd->execute([$at,$al,$ab,$ah,
                             json_encode(array_values($lengths), JSON_UNESCAPED_UNICODE),
                             json_encode(array_values($breadths), JSON_UNESCAPED_UNICODE),
                             json_encode(array_values($heights), JSON_UNESCAPED_UNICODE),
                             $tot,$tot,$floorSqft,$wallSqft,$ac,$tc,$ts,
                             $wtsk,$wwp,$wallCustom,$ftsk,$fwp,$floorCustom,$ib,$blh,$bhh,$bh2,$bdh,$bfh,$btsk,$bwp,
                             $sc,$stsk,$swp,$stepCustom,$rc,$rtsk,$rwp,$riserCustom,$rll,$rlb,$rls,$rlt,$srtsk,$srwp,$srCustom,$cs,
                             $aId,$id]);
        }

        /* ── EXISTING ROOMS — images ──────────────────────────────────────── */
        $imgAreaSks = $_POST['img_area_sk'] ?? [];
        $imgCamMap  = $_POST['img_cam_data'] ?? [];

        foreach ($imgAreaSks as $sk) {
            $aId = (int)($eAreaIds[$sk] ?? 0);
            if (!$aId) continue;
            $cntQ = $db->prepare("SELECT COUNT(*) FROM submission_area_images WHERE area_id=?");
            $cntQ->execute([$aId]);
            $ec = (int)$cntQ->fetchColumn();
            $sv = 0;
            $cam = trim($imgCamMap[$sk] ?? '');
            if ($cam && str_starts_with($cam, 'data:image') && ($ec + $sv) < 6) {
                $fn = sa_saveCamImg($cam);
                if ($fn) { sa_insertImg($db, $aId, $id, $fn, 'camera', $ec + $sv); $sv++; }
            }
            $fk = 'edit_area_img_files';
            if (isset($_FILES[$fk]['name'][$sk]) && is_array($_FILES[$fk]['name'][$sk])) {
                foreach ($_FILES[$fk]['name'][$sk] as $k => $fname) {
                    if (($ec + $sv) >= 4) break;
                    $finfo = [
                        'name'     => $fname,
                        'tmp_name' => $_FILES[$fk]['tmp_name'][$sk][$k] ?? '',
                        'error'    => $_FILES[$fk]['error'][$sk][$k]    ?? UPLOAD_ERR_NO_FILE,
                        'size'     => 0,
                    ];
                    $fn = sa_saveUploadedImg($finfo);
                    if ($fn) { sa_insertImg($db, $aId, $id, $fn, 'upload', $ec + $sv); $sv++; }
                }
            }
        }

        /* ── NEW ROOMS — insert + images ──────────────────────────────────── */
        $nAreaTypes = $_POST['new_area_type'] ?? [];
        $nCamMap    = $_POST['new_img_cam_data'] ?? [];

        $maxQ = $db->prepare("SELECT COALESCE(MAX(sort_order),0) FROM submission_areas WHERE submission_id=?");
        $maxQ->execute([$id]);
        $sortOff = (int)$maxQ->fetchColumn();

        $aIns = $db->prepare(
            "INSERT INTO submission_areas
                (submission_id,area_type,area_length,area_breadth,area_height,lengths,breadths,heights,area_sqft,total_sqft,floor_sqft,wall_sqft,area_count,
                 tile_chosen,tile_surface,
             wall_tile_size_key,wall_wastage_pct,wall_tile_custom,
             floor_tile_size_key,floor_wastage_pct,floor_tile_custom,
                 is_bathroom,bath_light_h,bath_high_h,bath_high2_h,bath_dark_h,bath_fall_h,bath_tile_size_key,bath_wastage_pct,
             step_count,step_tile_size_key,step_wastage_pct,step_tile_custom,
             riser_count,riser_tile_size_key,riser_wastage_pct,riser_tile_custom,
             riser_landing_length,riser_landing_breadth,riser_landing_sqft,riser_landing_tiles,
             step_riser_tile_size_key,step_riser_wastage_pct,step_riser_tile_custom,
                 calc_summary,sort_order)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );

        foreach ($nAreaTypes as $sk => $at) {
            $at = trim($at);
            if ($at === '') continue;
            $lengths = $parseDimensionArray('new_lengths_json', $sk);
            if (empty($lengths) && isset($_POST['new_lengths'][$sk])) {
                $lengths = array_values(array_filter((array)$_POST['new_lengths'][$sk], function($v) { return trim((string)$v) !== ''; }));
            }
            $breadths = $parseDimensionArray('new_breadths_json', $sk);
            if (empty($breadths) && isset($_POST['new_breadths'][$sk])) {
                $breadths = array_values(array_filter((array)$_POST['new_breadths'][$sk], function($v) { return trim((string)$v) !== ''; }));
            }
            $heights = $parseDimensionArray('new_heights_json', $sk);
            if (empty($heights) && isset($_POST['new_heights'][$sk])) {
                $heights = array_values(array_filter((array)$_POST['new_heights'][$sk], function($v) { return trim((string)$v) !== ''; }));
            }
            $lengths = array_filter($lengths, function($v){ return trim((string)$v) !== ''; });
            $breadths = array_filter($breadths, function($v){ return trim((string)$v) !== ''; });
            $heights = array_filter($heights, function($v){ return trim((string)$v) !== ''; });
            $al = ($_POST['new_area_length'][$sk] ?? '') !== '' ? (float)$_POST['new_area_length'][$sk] : null;
            if ($al === null && $lengths) { $al = 0.0; foreach ($lengths as $v) { $al += (float)$v; } $al = round($al, 2); }
            $ab = ($_POST['new_area_breadth'][$sk] ?? '') !== '' ? (float)$_POST['new_area_breadth'][$sk] : null;
            if ($ab === null && $breadths) { $ab = 0.0; foreach ($breadths as $v) { $ab += (float)$v; } $ab = round($ab, 2); }
            $ah = ($_POST['new_area_height'][$sk] ?? '') !== '' ? (float)$_POST['new_area_height'][$sk] : null;
            if ($ah === null && $heights) { $ah = 0.0; foreach ($heights as $v) { $ah += (float)$v; } $ah = round($ah, 2); }
            $ac   = ($_POST['new_area_count'][$sk]   ?? '') !== '' ? (int)$_POST['new_area_count'][$sk]     : null;
            $tc   = trim($_POST['new_tile_chosen'][$sk]      ?? '');
            $ts   = trim($_POST['new_tile_surface'][$sk]     ?? 'floor');
            $ftsk = trim($_POST['new_floor_tile_size_key'][$sk] ?? '2|2|4');
            $fwp  = $resolveWastage('new', 'floor', $sk);
            $wtsk = trim($_POST['new_wall_tile_size_key'][$sk]  ?? '2|2|4');
            $wwp  = $resolveWastage('new', 'wall', $sk);
            $ib   = (($_POST['new_is_bathroom'][$sk] ?? '0') === '1') ? 1 : 0;
            $blh  = ($_POST['new_bath_light_h'][$sk] ?? '') !== '' ? (float)$_POST['new_bath_light_h'][$sk] : null;
            $bhh  = ($_POST['new_bath_high_h'][$sk]  ?? '') !== '' ? (float)$_POST['new_bath_high_h'][$sk]  : null;
            $bh2  = ($_POST['new_bath_high2_h'][$sk] ?? '') !== '' ? (float)$_POST['new_bath_high2_h'][$sk] : null;
            $bdh  = ($_POST['new_bath_dark_h'][$sk]  ?? '') !== '' ? (float)$_POST['new_bath_dark_h'][$sk]  : null;
            $bfh  = ($_POST['new_bath_fall_h'][$sk]  ?? '') !== '' ? (float)$_POST['new_bath_fall_h'][$sk]  : null;
            $btsk = '2|2|4';
            $bwp  = 0;
            $stsk = trim($_POST['new_step_tile_size_key'][$sk] ?? '3|1|1');
            $swp  = $resolveWastage('new', 'step', $sk);
            $rtsk = trim($_POST['new_riser_tile_size_key'][$sk] ?? '3|0.8|1');
            $rwp  = $resolveWastage('new', 'riser', $sk);
            $srtsk = trim($_POST['new_step_riser_tile_size_key'][$sk] ?? $stsk);
            $srwp  = $resolveWastage('new', 'step_riser', $sk) ?: $swp;
            $postedFloorSqft = ($_POST['new_floor_sqft'][$sk] ?? '') !== '' ? (float)$_POST['new_floor_sqft'][$sk] : null;
            $postedWallSqft  = ($_POST['new_wall_sqft'][$sk] ?? '') !== '' ? (float)$_POST['new_wall_sqft'][$sk] : null;
            $postedTotalSqft = ($_POST['new_total_sqft'][$sk] ?? '') !== '' ? (float)$_POST['new_total_sqft'][$sk] : null;
            $tot  = $postedTotalSqft;
            if ($tot === null) {
                $tot = $calcRowsSqft(array_values($lengths), array_values($breadths), array_values($heights));
                $tot = $tot > 0 ? $tot : (($al && $ab) ? round($al * $ab, 2) : null);
            }
            $floorSqft = $postedFloorSqft ?? $tot;
            $wallSqft  = $postedWallSqft  ?? $tot;
            $cs   = sa_sanitiseCalcSummary($_POST['new_calc_summary'][$sk] ?? '');
            // New area custom tile JSON values
            $nfloorCustom = null;
            $nfwW = $_POST['new_f_custw'][$sk] ?? '';
            $nfwH = $_POST['new_f_custh'][$sk] ?? '';
            $nfwP = $_POST['new_f_custppb'][$sk] ?? '';
            if (trim((string)$nfwW) !== '' || trim((string)$nfwH) !== '' || trim((string)$nfwP) !== '') {
              $nfloorCustom = json_encode(['w'=> (float)$nfwW, 'h'=> (float)$nfwH, 'ppb'=> (int)$nfwP], JSON_UNESCAPED_UNICODE);
            }
            $nwallCustom = null;
            $nwwW = $_POST['new_w_custw'][$sk] ?? '';
            $nwwH = $_POST['new_w_custh'][$sk] ?? '';
            $nwwP = $_POST['new_w_custppb'][$sk] ?? '';
            if (trim((string)$nwwW) !== '' || trim((string)$nwwH) !== '' || trim((string)$nwwP) !== '') {
              $nwallCustom = json_encode(['w'=> (float)$nwwW, 'h'=> (float)$nwwH, 'ppb'=> (int)$nwwP], JSON_UNESCAPED_UNICODE);
            }
            $nstepCustom = null;
            $nsWw = $_POST['new_s_custw'][$sk] ?? $_POST['new_scustw'][$sk] ?? '';
            $nsWh = $_POST['new_s_custh'][$sk] ?? $_POST['new_scusth'][$sk] ?? '';
            $nsWp = $_POST['new_s_custppb'][$sk] ?? $_POST['new_scustppb'][$sk] ?? '';
            if (trim((string)$nsWw) !== '' || trim((string)$nsWh) !== '' || trim((string)$nsWp) !== '') {
              $nstepCustom = json_encode(['w'=> (float)$nsWw, 'h'=> (float)$nsWh, 'ppb'=> (int)$nsWp], JSON_UNESCAPED_UNICODE);
            }
            $nriserCustom = null;
            $nrWw = $_POST['new_r_custw'][$sk] ?? '';
            $nrWh = $_POST['new_r_custh'][$sk] ?? '';
            $nrWp = $_POST['new_r_custppb'][$sk] ?? '';
            if (trim((string)$nrWw) !== '' || trim((string)$nrWh) !== '' || trim((string)$nrWp) !== '') {
              $nriserCustom = json_encode(['w'=> (float)$nrWw, 'h'=> (float)$nrWh, 'ppb'=> (int)$nrWp], JSON_UNESCAPED_UNICODE);
            }
            $nsrCustom = null;
            $nsrWw = $_POST['new_sr_custw'][$sk] ?? $_POST['new_srcustw'][$sk] ?? '';
            $nsrWh = $_POST['new_sr_custh'][$sk] ?? $_POST['new_srcusth'][$sk] ?? '';
            $nsrWp = $_POST['new_sr_custppb'][$sk] ?? $_POST['new_srcustppb'][$sk] ?? '';
            if (trim((string)$nsrWw) !== '' || trim((string)$nsrWh) !== '' || trim((string)$nsrWp) !== '') {
              $nsrCustom = json_encode(['w'=> (float)$nsrWw, 'h'=> (float)$nsrWh, 'ppb'=> (int)$nsrWp], JSON_UNESCAPED_UNICODE);
            }
            // NEW STEP/RISER SUPPORT: Extract step/riser counts for new areas
            $sc   = (int)($_POST['new_step_count'][$sk]  ?? 0);
            $rc   = (int)($_POST['new_riser_count'][$sk] ?? 0);
            $rll  = ($_POST['new_riser_landing_length'][$sk] ?? '') !== '' ? (float)$_POST['new_riser_landing_length'][$sk] : null;
            $rlb  = ($_POST['new_riser_landing_breadth'][$sk] ?? '') !== '' ? (float)$_POST['new_riser_landing_breadth'][$sk] : null;
            $rls  = ($rll && $rlb) ? round($rll * $rlb, 2) : null;
            $landingTileKey = $ts === 'step' ? $stsk : ($ts === 'step_riser' ? $srtsk : $rtsk);
            $landingCustom = $ts === 'step' ? $nstepCustom : ($ts === 'step_riser' ? $nsrCustom : $nriserCustom);
            $rTileArea = $calcTileArea($landingTileKey, $landingCustom);
            $rlt  = ($rls && $rTileArea > 0) ? (int)ceil($rls / $rTileArea) : 0;
            $sortOff++;
            $aIns->execute([$id,$at,$al,$ab,$ah,
                             json_encode(array_values($lengths), JSON_UNESCAPED_UNICODE),
                             json_encode(array_values($breadths), JSON_UNESCAPED_UNICODE),
                             json_encode(array_values($heights), JSON_UNESCAPED_UNICODE),
                             $tot,$tot,$floorSqft,$wallSqft,$ac,$tc,$ts,
                             $wtsk,$wwp,$nwallCustom,$ftsk,$fwp,$nfloorCustom,$ib,$blh,$bhh,$bh2,$bdh,$bfh,$btsk,$bwp,
                             $sc,$stsk,$swp,$nstepCustom,$rc,$rtsk,$rwp,$nriserCustom,$rll,$rlb,$rls,$rlt,$srtsk,$srwp,$nsrCustom,$cs,$sortOff]);
            $newAreaId = (int)$db->lastInsertId();
            $newAreaMap[$sk] = $newAreaId;
            $sv = 0;
            $cam = trim($nCamMap[$sk] ?? '');
            if ($cam && str_starts_with($cam, 'data:image') && $sv < 6) {
                $fn = sa_saveCamImg($cam);
                if ($fn) { sa_insertImg($db, $newAreaId, $id, $fn, 'camera', $sv); $sv++; }
            }
            $fk = 'new_area_img_files';
            if (isset($_FILES[$fk]['name'][$sk]) && is_array($_FILES[$fk]['name'][$sk])) {
                foreach ($_FILES[$fk]['name'][$sk] as $k => $fname) {
                    if ($sv >= 6) break;
                    $finfo = [
                        'name'     => $fname,
                        'tmp_name' => $_FILES[$fk]['tmp_name'][$sk][$k] ?? '',
                        'error'    => $_FILES[$fk]['error'][$sk][$k]    ?? UPLOAD_ERR_NO_FILE,
                        'size'     => 0,
                    ];
                    $fn = sa_saveUploadedImg($finfo);
                    if ($fn) { sa_insertImg($db, $newAreaId, $id, $fn, 'upload', $sv); $sv++; }
                }
            }
        }

        /* ── PROPAGATE EXTRA_BOX TO ESTIMATE ROOMS (if estimate exists) ─── */
        $newAreaMap = $newAreaMap ?? [];
        $estQ = $db->prepare("SELECT id FROM submission_estimates WHERE submission_id=? LIMIT 1");
        $estQ->execute([$id]);
        $estId = $estQ->fetchColumn();
        if ($estId) {
            $updExtra = $db->prepare("UPDATE submission_estimate_rooms SET extra_box=? WHERE estimate_id=? AND area_id=? AND surface=?");
            foreach ($eAreaIds as $sk => $aId) {
                $aId = (int)$aId; if (!$aId) continue;
                if (isset($_POST['edit_extra_box_step'][$sk])) {
                    $ex = (int)$_POST['edit_extra_box_step'][$sk];
                    $updExtra->execute([$ex, $estId, $aId, 'step']);
                }
                if (isset($_POST['edit_extra_box_riser'][$sk])) {
                    $ex = (int)$_POST['edit_extra_box_riser'][$sk];
                    $updExtra->execute([$ex, $estId, $aId, 'riser']);
                }
            }
            if (!empty($newAreaMap)) {
                foreach ($newAreaMap as $nsk => $newAId) {
                    if (isset($_POST['new_extra_box_step'][$nsk])) {
                        $ex = (int)$_POST['new_extra_box_step'][$nsk];
                        $updExtra->execute([$ex, $estId, $newAId, 'step']);
                    }
                    if (isset($_POST['new_extra_box_riser'][$nsk])) {
                        $ex = (int)$_POST['new_extra_box_riser'][$nsk];
                        $updExtra->execute([$ex, $estId, $newAId, 'riser']);
                    }
                }
            }
        }

          // Propagate custom tile label and recalculated box_count to estimate rows
          try {
            $erQ = $db->prepare("SELECT * FROM submission_estimate_rooms WHERE estimate_id=?");
            $erQ->execute([$estId]);
            $erRows = $erQ->fetchAll(PDO::FETCH_ASSOC);
            if ($erRows) {
              // gather affected area ids (existing + newly created)
              $areaIds = array_values(array_unique(array_merge(array_values($eAreaIds), array_values($newAreaMap))));
              if ($areaIds) {
                $ph = implode(',', array_fill(0, count($areaIds), '?'));
                $aQ = $db->prepare("SELECT * FROM submission_areas WHERE id IN ($ph)");
                $aQ->execute($areaIds);
                $areasById = [];
                foreach ($aQ->fetchAll(PDO::FETCH_ASSOC) as $ar) $areasById[(int)$ar['id']] = $ar;

                // local helpers (match logic in admin/estimate_modal.php)
                $estTileLabel = function($key, $customJson=null){
                  if ($customJson) {
                    $cj = is_string($customJson) ? json_decode($customJson, true) : $customJson;
                    if (is_array($cj) && isset($cj['w']) && isset($cj['h']) && isset($cj['ppb'])) {
                      $w = (float)$cj['w']; $h = (float)$cj['h']; $ppb = (int)$cj['ppb'];
                      if ($w && $h) return trim($w) . '×' . trim($h) . ' ft · ' . $ppb . '/box';
                    }
                  }
                  if (!$key) return '—';
                  if ($key === 'custom') return 'Custom';
                  $p = explode('|', $key);
                  $w = (float)($p[0] ?? 0); $h = (float)($p[1] ?? 0); $ppb = (int)($p[2] ?? 1);
                  if (!$w || !$h) return $key;
                  return $w . '×' . $h . ' ft · ' . $ppb . '/box';
                };

                $estBoxCount = function($sqft, $key, $wastePct, $customJson=null) {
                  if ($sqft <= 0) return 0;
                  $tileArea = 0.0;
                  $ppb = 1;
                  if ($customJson) {
                    $cj = is_string($customJson) ? json_decode($customJson, true) : $customJson;
                    if (is_array($cj)) {
                      $w = (float)($cj['w'] ?? $cj['width'] ?? $cj['custw'] ?? 0);
                      $h = (float)($cj['h'] ?? $cj['height'] ?? $cj['custh'] ?? 0);
                      $ppb = max(1, (int)($cj['ppb'] ?? $cj['pieces_per_box'] ?? $cj['custppb'] ?? 1));
                      $tileArea = $w * $h;
                    }
                  } else {
                    if (!$key || $key === 'custom') return 0;
                    $p = explode('|', $key);
                    $tileArea = (float)($p[0] ?? 0) * (float)($p[1] ?? 0);
                    $ppb = max(1, (int)($p[2] ?? 1));
                  }
                  if ($tileArea <= 0) return 0;
                  $needed = (int)ceil($sqft / $tileArea);
                  $extra  = (int)ceil($needed * ($wastePct / 100));
                  return (int)ceil(($needed + $extra) / $ppb);
                };

                $estBoxCountByItem = function($itemCount, $key, $wastePct, $customJson=null) {
                  if ($itemCount <= 0) return 0;
                  if ($customJson) {
                    $cj = is_string($customJson) ? json_decode($customJson, true) : $customJson;
                    $ppb = is_array($cj) ? max(1, (int)($cj['ppb'] ?? $cj['pieces_per_box'] ?? $cj['custppb'] ?? 1)) : 1;
                  } else {
                    if (!$key || $key === 'custom') return 0;
                    $p = explode('|', $key);
                    $ppb = max(1, (int)($p[2] ?? 1));
                  }
                  if ($ppb <= 0) return 0;
                  $needed = $itemCount;
                  $extra  = (int)ceil($needed * ($wastePct / 100));
                  return (int)ceil(($needed + $extra) / $ppb);
                };

                $updQ = $db->prepare("UPDATE submission_estimate_rooms SET tile_size_label=?, box_count=? WHERE estimate_id=? AND area_id=? AND surface=?");

                foreach ($erRows as $erRow) {
                  $aId = (int)($erRow['area_id'] ?? 0);
                  if (!$aId || !isset($areasById[$aId])) continue;
                  $area = $areasById[$aId];
                  $surface = $erRow['surface'] ?? 'floor';
                  $label = $erRow['tile_size_label'] ?? '';
                  $boxes = (int)($erRow['box_count'] ?? 0);

                  if ($surface === 'floor') {
                    $key = $area['floor_tile_size_key'] ?: $area['tile_size_key'] ?: null;
                    $custom = $area['floor_tile_custom'] ?? null;
                    $waste = (float)($area['floor_wastage_pct'] ?? $area['wastage_pct'] ?? 10);
                    $sqft = (float)($area['floor_sqft'] ?? $area['area_sqft'] ?? 0);
                    $label = $estTileLabel($key, $custom);
                    $boxes = $estBoxCount($sqft, $key, $waste, $custom);
                  } elseif ($surface === 'wall') {
                    $key = $area['wall_tile_size_key'] ?: null;
                    $custom = $area['wall_tile_custom'] ?? null;
                    $waste = (float)($area['wall_wastage_pct'] ?? 10);
                    $wSqft = (float)($area['wall_sqft'] ?? $area['total_sqft'] ?? 0);
                    $label = $estTileLabel($key, $custom);
                    $boxes = $estBoxCount($wSqft, $key, $waste, $custom);
                  } elseif ($surface === 'bath') {
                    $label = '';
                    $boxes = 0;
                  } elseif ($surface === 'step' || $surface === 'riser') {
                    $keyField = $surface === 'step' ? 'step_tile_size_key' : 'riser_tile_size_key';
                    $key = $area[$keyField] ?? null;
                    $custom = $area[$surface . '_tile_custom'] ?? null;
                    $waste = (float)($area[$surface . '_wastage_pct'] ?? 10);
                    $itemCount = (int)($erRow['item_count'] ?? 0);
                    if ($surface === 'step') {
                        $itemCount = (int)($area['step_count'] ?? $itemCount) + (int)($area['riser_landing_tiles'] ?? 0);
                    } elseif ($surface === 'riser') {
                        $itemCount = (int)($area['riser_count'] ?? $itemCount) + (int)($area['riser_landing_tiles'] ?? 0);
                    }
                    $label = $estTileLabel($key, $custom);
                    $boxes = $estBoxCountByItem($itemCount, $key, $waste, $custom);
                  }

                  $updQ->execute([$label, $boxes, $estId, $aId, $surface]);
                }
              }
            }
          } catch (Throwable $e) {
            // don't block save on propagation failure
            error_log("[subadmins.php] estimate propagation failed: " . $e->getMessage());
          }

        /* ── FULL SYNC: print / estimate / reports from updated areas ───── */
        if ($estId) {
            try {
                $syncTileLabel = function($key, $customJson = null): string {
                    if ($customJson) {
                        $custom = is_string($customJson) ? json_decode($customJson, true) : $customJson;
                        if (is_array($custom)) {
                            $w = (float)($custom['w'] ?? $custom['width'] ?? $custom['custw'] ?? 0);
                            $h = (float)($custom['h'] ?? $custom['height'] ?? $custom['custh'] ?? 0);
                            $ppb = (int)($custom['ppb'] ?? $custom['pieces_per_box'] ?? $custom['custppb'] ?? 0);
                            if ($w > 0 && $h > 0 && $ppb > 0) {
                                return number_format($w, 2) . '×' . number_format($h, 2) . ' ft · ' . $ppb . '/box';
                            }
                        }
                    }
                    if (!$key) return '—';
                    if ($key === 'custom') return 'Custom';
                    $p = explode('|', (string)$key);
                    $w = (float)($p[0] ?? 0);
                    $h = (float)($p[1] ?? 0);
                    $ppb = (int)($p[2] ?? 1);
                    return ($w > 0 && $h > 0) ? "{$w}×{$h} ft · {$ppb}/box" : (string)$key;
                };

                $syncCustomTile = function($customJson): ?array {
                    if (is_string($customJson) && $customJson !== '') {
                        $decoded = json_decode($customJson, true);
                        $customJson = is_array($decoded) ? $decoded : null;
                    }
                    if (!is_array($customJson)) return null;
                    $w = (float)($customJson['w'] ?? $customJson['width'] ?? $customJson['custw'] ?? 0);
                    $h = (float)($customJson['h'] ?? $customJson['height'] ?? $customJson['custh'] ?? 0);
                    $ppb = (int)($customJson['ppb'] ?? $customJson['pieces_per_box'] ?? $customJson['custppb'] ?? 0);
                    if ($w <= 0 || $h <= 0 || $ppb <= 0) return null;
                    return ['area' => $w * $h, 'ppb' => $ppb];
                };

                $syncBoxCount = function(float $sqft, ?string $key, float $wastePct, $customJson = null) use ($syncCustomTile): int {
                    if ($sqft <= 0) return 0;
                    $custom = $syncCustomTile($customJson);
                    if ($custom) {
                        $tileArea = $custom['area'];
                        $ppb = max(1, (int)$custom['ppb']);
                    } else {
                        if (!$key || $key === 'custom') return 0;
                        $p = explode('|', $key);
                        $tileArea = (float)($p[0] ?? 0) * (float)($p[1] ?? 0);
                        $ppb = max(1, (int)($p[2] ?? 1));
                    }
                    if ($tileArea <= 0) return 0;
                    $needed = (int)ceil($sqft / $tileArea);
                    $extra = (int)ceil($needed * ($wastePct / 100));
                    return (int)ceil(($needed + $extra) / $ppb);
                };

                $syncBoxCountByItem = function(int $itemCount, ?string $key, float $wastePct, $customJson = null) use ($syncCustomTile): int {
                    if ($itemCount <= 0) return 0;
                    $custom = $syncCustomTile($customJson);
                    if ($custom) {
                        $ppb = max(1, (int)$custom['ppb']);
                    } else {
                        if (!$key || $key === 'custom') return 0;
                        $p = explode('|', $key);
                        $ppb = max(1, (int)($p[2] ?? 1));
                    }
                    $extra = (int)ceil($itemCount * ($wastePct / 100));
                    return (int)ceil(($itemCount + $extra) / $ppb);
                };

                $deriveEstimateRows = function(array $area) use ($syncTileLabel, $syncBoxCount, $syncBoxCountByItem): array {
                    $rows = [];
                    $surface = $area['tile_surface'] ?? 'floor';
                    $areaName = $area['area_type'] ?: 'Room';
                    $floorSqft = (float)($area['floor_sqft'] ?? $area['total_sqft'] ?? $area['area_sqft'] ?? 0);
                    $wallSqft = (float)($area['wall_sqft'] ?? $area['total_sqft'] ?? $area['area_sqft'] ?? 0);
                    $L = (float)($area['area_length'] ?? 0);
                    $B = (float)($area['area_breadth'] ?? 0);

                    if ($surface === 'floor' || $surface === 'both') {
                        $key = $area['floor_tile_size_key'] ?: ($area['tile_size_key'] ?? '2|2|4');
                        $custom = $area['floor_tile_custom'] ?? null;
                        $waste = (float)($area['floor_wastage_pct'] ?? $area['wastage_pct'] ?? 10);
                        $rows[] = [
                            'key' => (int)$area['id'] . '|floor|' . ($surface === 'both' ? $areaName . ' — Floor' : $areaName),
                            'area_id' => (int)$area['id'],
                            'area_name' => $areaName . ($surface === 'both' ? ' — Floor' : ''),
                            'surface' => 'floor',
                            'total_sqft' => $floorSqft,
                            'tile_size_label' => $syncTileLabel($key, $custom),
                            'box_count' => $syncBoxCount($floorSqft, $key, $waste, $custom),
                            'item_count' => 0,
                        ];
                    }

                    if ($surface === 'wall' || $surface === 'both') {
                        $key = $area['wall_tile_size_key'] ?: '1|1|8';
                        $custom = $area['wall_tile_custom'] ?? null;
                        $waste = (float)($area['wall_wastage_pct'] ?? 10);
                        $rows[] = [
                            'key' => (int)$area['id'] . '|wall|' . ($surface === 'both' ? $areaName . ' — Wall' : $areaName),
                            'area_id' => (int)$area['id'],
                            'area_name' => $areaName . ($surface === 'both' ? ' — Wall' : ''),
                            'surface' => 'wall',
                            'total_sqft' => $wallSqft,
                            'tile_size_label' => $syncTileLabel($key, $custom),
                            'box_count' => $syncBoxCount($wallSqft, $key, $waste, $custom),
                            'item_count' => 0,
                        ];
                    }

                    $stepCount = (int)($area['step_count'] ?? 0);
                    if (($area['tile_surface'] ?? '') === 'step') {
                        $stepCount += (int)($area['riser_landing_tiles'] ?? 0);
                    }
                    if ($stepCount > 0) {
                        $key = $area['step_tile_size_key'] ?? $area['step_riser_tile_size_key'] ?? '3|1|1';
                        $custom = $area['step_tile_custom'] ?? $area['step_riser_tile_custom'] ?? null;
                        $waste = (float)($area['step_wastage_pct'] ?? $area['step_riser_wastage_pct'] ?? 10);
                        $name = "{$areaName} — Steps";
                        $rows[] = [
                            'key' => (int)$area['id'] . '|step|' . $name,
                            'area_id' => (int)$area['id'],
                            'area_name' => $name,
                            'surface' => 'step',
                            'total_sqft' => 0.0,
                            'tile_size_label' => $syncTileLabel($key, $custom),
                            'box_count' => $syncBoxCountByItem($stepCount, $key, $waste, $custom),
                            'item_count' => $stepCount,
                        ];
                    }

                    $riserCount = (int)($area['riser_count'] ?? 0) + (int)($area['riser_landing_tiles'] ?? 0);
                    if ($riserCount > 0) {
                        $key = $area['riser_tile_size_key'] ?? $area['step_riser_tile_size_key'] ?? '3|0.8|1';
                        $custom = $area['riser_tile_custom'] ?? $area['step_riser_tile_custom'] ?? null;
                        $waste = (float)($area['riser_wastage_pct'] ?? $area['step_riser_wastage_pct'] ?? 10);
                        $name = "{$areaName} — Risers";
                        $rows[] = [
                            'key' => (int)$area['id'] . '|riser|' . $name,
                            'area_id' => (int)$area['id'],
                            'area_name' => $name,
                            'surface' => 'riser',
                            'total_sqft' => (float)($area['riser_landing_sqft'] ?? 0),
                            'tile_size_label' => $syncTileLabel($key, $custom),
                            'box_count' => $syncBoxCountByItem($riserCount, $key, $waste, $custom),
                            'item_count' => $riserCount,
                        ];
                    }

                    return $rows;
                };

                $areaRowsStmt = $db->prepare("SELECT * FROM submission_areas WHERE submission_id=? ORDER BY sort_order, id");
                $areaRowsStmt->execute([$id]);
                $freshAreas = $areaRowsStmt->fetchAll(PDO::FETCH_ASSOC);

                $derivedRows = [];
                foreach ($freshAreas as $freshArea) {
                    foreach ($deriveEstimateRows($freshArea) as $row) {
                        $derivedRows[$row['key']] = $row;
                    }
                }

                $existingRowsStmt = $db->prepare("SELECT * FROM submission_estimate_rooms WHERE estimate_id=? ORDER BY sort_order, id");
                $existingRowsStmt->execute([$estId]);
                $existingRows = [];
                $existingRowsByStableKey = [];
                foreach ($existingRowsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $key = ((int)($row['area_id'] ?? 0)) . '|' . ($row['surface'] ?? 'floor') . '|' . trim($row['area_name'] ?? '');
                    $existingRows[$key] = $row;
                    if (($row['surface'] ?? '') !== 'bath') {
                        $stableKey = ((int)($row['area_id'] ?? 0)) . '|' . ($row['surface'] ?? 'floor');
                        $existingRowsByStableKey[$stableKey] = $row;
                    }
                }

                $updateEstimateRow = $db->prepare(
                    "UPDATE submission_estimate_rooms
                     SET area_name=?,surface=?,total_sqft=?,tile_size_label=?,box_count=?,item_count=?,
                         discount_pct=?,mrp_total=?,mop_total=?,sort_order=?,updated_at=NOW()
                     WHERE id=? AND estimate_id=?"
                );
                $insertEstimateRow = $db->prepare(
                    "INSERT INTO submission_estimate_rooms
                        (estimate_id,submission_id,area_id,area_name,surface,total_sqft,tile_size_label,
                         box_count,item_count,extra_box,mrp_per_box,mop_per_box,discount_pct,mrp_total,mop_total,
                         offer,include_in_print,sort_order)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
                );

                $sortOrder = 0;
                $usedEstimateRowIds = [];
                foreach ($derivedRows as $key => $row) {
                    $stableKey = $row['area_id'] . '|' . $row['surface'];
                    $saved = $existingRows[$key] ?? (($row['surface'] !== 'bath') ? ($existingRowsByStableKey[$stableKey] ?? null) : null);
                    $extraBox = $saved ? (int)($saved['extra_box'] ?? 0) : 0;
                    $mrpPerBox = $saved ? (float)($saved['mrp_per_box'] ?? 0) : 0.0;
                    $mopPerBox = $saved ? (float)($saved['mop_per_box'] ?? 0) : 0.0;
                    $totalBoxes = max(0, (int)$row['box_count'] + $extraBox);
                    $discountPct = $mrpPerBox > 0 ? round(max(0, 100 - (($mopPerBox / $mrpPerBox) * 100)), 2) : 0.0;
                    $mrpTotal = round($totalBoxes * $mrpPerBox, 2);
                    $mopTotal = round($totalBoxes * $mopPerBox, 2);

                    if ($saved) {
                        $usedEstimateRowIds[(int)$saved['id']] = true;
                        $updateEstimateRow->execute([
                            $row['area_name'], $row['surface'], $row['total_sqft'], $row['tile_size_label'],
                            $row['box_count'], $row['item_count'], $discountPct, $mrpTotal, $mopTotal,
                            $sortOrder, (int)$saved['id'], $estId
                        ]);
                    } else {
                        $insertEstimateRow->execute([
                            $estId, $id, $row['area_id'], $row['area_name'], $row['surface'], $row['total_sqft'],
                            $row['tile_size_label'], $row['box_count'], $row['item_count'], 0, 0, 0, 0, 0, 0,
                            null, 1, $sortOrder
                        ]);
                    }
                    $sortOrder++;
                }

                $deleteEstimateRow = $db->prepare("DELETE FROM submission_estimate_rooms WHERE id=? AND estimate_id=?");
                foreach ($existingRows as $row) {
                    if (empty($usedEstimateRowIds[(int)$row['id']])) {
                        $deleteEstimateRow->execute([(int)$row['id'], $estId]);
                    }
                }

                $db->prepare("UPDATE submission_estimates SET updated_by=?, updated_at=NOW() WHERE id=?")
                   ->execute([$user['id'], $estId]);
            } catch (Throwable $e) {
                error_log("[subadmins.php] full estimate sync failed: " . $e->getMessage());
            }
        }

        try {
            $areaSummaryStmt = $db->prepare("SELECT COUNT(*) AS area_count,
                                                   COALESCE(SUM(
                                                     CASE
                                                       WHEN tile_surface IN ('floor','wall','both')
                                                         THEN COALESCE(total_sqft, COALESCE(floor_sqft,0) + COALESCE(wall_sqft,0))
                                                       ELSE COALESCE(total_sqft,0)
                                                     END
                                                   ),0) AS total_sqft
                                            FROM submission_areas
                                            WHERE submission_id=?");
            $areaSummaryStmt->execute([$id]);
            $areaSummary = $areaSummaryStmt->fetch(PDO::FETCH_ASSOC) ?: ['area_count' => 0, 'total_sqft' => 0];
            $latestReportStmt = $db->prepare("SELECT id, report_data FROM office_admin_reports WHERE submission_id=? ORDER BY id DESC LIMIT 1");
            $latestReportStmt->execute([$id]);
            $latestReport = $latestReportStmt->fetch(PDO::FETCH_ASSOC);
            if ($latestReport) {
                $reportData = json_decode($latestReport['report_data'] ?? '', true);
                if (!is_array($reportData)) $reportData = [];
                $reportData['area_count'] = (int)$areaSummary['area_count'];
                $reportData['total_sqft'] = (float)$areaSummary['total_sqft'];
                $reportData['areas_synced_at'] = date('Y-m-d H:i:s');
                $db->prepare("UPDATE office_admin_reports SET report_data=? WHERE id=?")
                   ->execute([json_encode($reportData, JSON_UNESCAPED_UNICODE), (int)$latestReport['id']]);
            }
        } catch (Throwable $e) {
            error_log("[subadmins.php] report sync failed: " . $e->getMessage());
        }

        logActivity($user['id'], 'oa_edit_submission', "id=$id status=$newStatus next_visit_date=" . ($nextVisitDate ?? 'none') . " done=$orderDone");
        redirectBack(
            $orderDone
                ? '✅ Order billed — record locked and report sent to Main Admin.'
                : '✅ Status & areas updated.'
        );
        } catch (Throwable $e) {
            error_log(sprintf("[subadmins.php edit_status] %s in %s on line %d", $e->getMessage(), $e->getFile(), $e->getLine()));
            redirectBack('⚠️ Save failed: ' . $e->getMessage(), 'error');
        }
    }

    /* ── Request unlock ───────────────────────────────────────────────────── */
    if ($action === 'request_unlock') {
        $id     = (int)($_POST['submission_id'] ?? 0);
        $reason = trim($_POST['unlock_reason'] ?? '');
        $orig   = $getOwnedSub($id);
        if (!$orig) redirectBack('Record not found.', 'error');
        if (!$reason) redirectBack('Please provide a reason for the unlock request.', 'error');
        $existQ = $db->prepare("SELECT id FROM edit_unlock_requests WHERE submission_id=? AND status='pending'");
        $existQ->execute([$id]);
        if ($existQ->fetchColumn()) redirectBack('An unlock request is already pending for this record.', 'warn');
        $db->prepare("INSERT INTO edit_unlock_requests (submission_id,requested_by,reason) VALUES (?,?,?)")
           ->execute([$id, $user['id'], $reason]);
        logActivity($user['id'], 'oa_unlock_request', "Requested unlock for submission id=$id");
        redirectBack('🔓 Unlock request sent to Main Admin.');
    }

    /* ── Add area images (standalone) ────────────────────────────────────── */
    if ($action === 'add_area_images') {
        $areaId = (int)($_POST['area_id']       ?? 0);
        $subId  = (int)($_POST['submission_id'] ?? 0);
        if (!$areaId) redirectBack('Invalid request.', 'error');
        $vQ = $db->prepare("SELECT id FROM submission_areas WHERE id=?");
        $vQ->execute([$areaId]);
        if (!$vQ->fetchColumn()) redirectBack('Area not found.', 'error');
        if (!is_dir(UPLOAD_DIR) && !@mkdir(UPLOAD_DIR, 0775, true)) redirectBack('❌ Upload directory could not be created.', 'error');
        if (!is_writable(UPLOAD_DIR)) redirectBack('❌ Upload directory is not writable.', 'error');
        $cntQ = $db->prepare("SELECT COUNT(*) FROM submission_area_images WHERE area_id=?");
        $cntQ->execute([$areaId]); $existCount = (int)$cntQ->fetchColumn();
        if ($existCount >= 6) redirectBack('Maximum 6 images already uploaded for this area.', 'error');
        $saved   = 0;
        $camData = trim($_POST['camera_image'] ?? '');
        if ($camData && str_starts_with($camData, 'data:image') && ($existCount + $saved) < 6) {
            $fn = sa_saveCamImg($camData);
            if ($fn) { sa_insertImg($db, $areaId, $subId, $fn, 'camera', $existCount + $saved); $saved++; }
        }
        if (isset($_FILES['area_images']) && !empty($_FILES['area_images']['name'][0])) {
            $names  = (array)$_FILES['area_images']['name'];
            $tmps   = (array)$_FILES['area_images']['tmp_name'];
            $errors = (array)$_FILES['area_images']['error'];
            foreach ($tmps as $k => $tmp) {
                if (($existCount + $saved) >= 6) break;
                $finfo = ['name'=>$names[$k]??'','tmp_name'=>$tmp,'error'=>$errors[$k]??UPLOAD_ERR_NO_FILE,'size'=>0];
                $fn = sa_saveUploadedImg($finfo);
                if ($fn) { sa_insertImg($db, $areaId, $subId, $fn, 'upload', $existCount + $saved); $saved++; }
            }
        }
        redirectBack(
            $saved ? "✅ $saved image(s) saved successfully." : '❌ No valid images received.',
            $saved ? 'success' : 'error'
        );
    }

    /* ── Delete area image ────────────────────────────────────────────────── */
    if ($action === 'delete_area_image') {
        $imgId = (int)($_POST['img_id'] ?? 0);
        $imgQ  = $db->prepare("SELECT image_filename FROM submission_area_images WHERE id=?");
        $imgQ->execute([$imgId]);
        $imgRow = $imgQ->fetch();
        if ($imgRow) {
            $fullPath = UPLOAD_DIR . $imgRow['image_filename'];
            if (file_exists($fullPath)) @unlink($fullPath);
            $db->prepare("DELETE FROM submission_area_images WHERE id=?")->execute([$imgId]);
            redirectBack('🗑 Image deleted.');
        }
        redirectBack('Image not found.', 'error');
    }

    /* ── Delete area (standalone AJAX/form) ───────────────────────────────── */
    if ($action === 'delete_area') {
        $areaId = (int)($_POST['area_id']       ?? 0);
        $subId  = (int)($_POST['submission_id'] ?? 0);
        if (!$areaId || !$subId) redirectBack('Invalid request.', 'error');
        $imgDelQ = $db->prepare("SELECT image_filename FROM submission_area_images WHERE area_id=? AND submission_id=?");
        $imgDelQ->execute([$areaId, $subId]);
        foreach ($imgDelQ->fetchAll() as $imgRow) {
            $fp = UPLOAD_DIR . $imgRow['image_filename'];
            if (file_exists($fp)) @unlink($fp);
        }
        $db->prepare("DELETE FROM submission_area_images WHERE area_id=? AND submission_id=?")->execute([$areaId, $subId]);
        $db->prepare("DELETE FROM submission_areas WHERE id=? AND submission_id=?")->execute([$areaId, $subId]);
        logActivity($user['id'], 'oa_delete_area', "area=$areaId sub=$subId");
        redirectBack('🗑 Area deleted.');
    }

    /* ── Delete visualizer snapshot ───────────────────────────────────────── */
    if ($action === 'delete_viz_snapshot') {
        $snapId = (int)($_POST['snap_id'] ?? 0);
        $snapQ  = $db->prepare("SELECT filename FROM visualizer_snapshots WHERE id=?");
        $snapQ->execute([$snapId]);
        $snapRow = $snapQ->fetch();
        if ($snapRow) {
            $fullPath = UPLOAD_DIR . $snapRow['filename'];
            if (file_exists($fullPath)) @unlink($fullPath);
            $db->prepare("DELETE FROM visualizer_snapshots WHERE id=?")->execute([$snapId]);
            redirectBack('🗑 Image deleted.');
        }
        redirectBack('Image not found.', 'error');
    }
}

/* ── Flash ────────────────────────────────────────────────────────────────── */
$flashMsg  = '';
$flashType = 'success';
if (!empty($_GET['flash'])) {
    $flashMsg  = htmlspecialchars($_GET['flash']);
    $flashType = in_array($_GET['flash_type'] ?? '', ['success','error','warn','info']) ? $_GET['flash_type'] : 'success';
}

/* ── Phone lookup ─────────────────────────────────────────────────────────── */
$phoneLookup    = trim($_GET['phone_lookup'] ?? '');
$lookedUpSub    = null;
$lookedUpAreas  = [];
$lookedUpDetail = null;
$lookedUpImgs   = [];
$lookedUpUnlock = null;
$lookupError    = '';

if ($phoneLookup !== '') {
    $lq = $db->prepare(
        "SELECT s.*, u.username AS sub_name, sd.code AS zone, d.code AS dir_code,
                editor.username AS last_edited_by, latest_edit.edited_at AS last_edit_at
         FROM submissions s
         JOIN users u           ON s.submitted_by     = u.id
         JOIN sub_directions sd ON s.sub_direction_id = sd.id
         JOIN directions d      ON sd.direction_id    = d.id
         LEFT JOIN (
             SELECT se.submission_id, se.edited_by, se.edited_at
             FROM submission_edits se
             INNER JOIN (
                 SELECT submission_id, MAX(id) AS id
                 FROM submission_edits
                 GROUP BY submission_id
             ) newest ON newest.id = se.id
         ) latest_edit ON latest_edit.submission_id = s.id
         LEFT JOIN users editor ON editor.id = COALESCE(s.last_edited_by, latest_edit.edited_by)
         WHERE (s.owner_number LIKE ? OR s.representative_number LIKE ?)
         ORDER BY COALESCE(s.device_submitted_at, s.submitted_at) DESC
         LIMIT 1"
    );
    $lq->execute(['%' . $phoneLookup . '%', '%' . $phoneLookup . '%']);
    $lookedUpSub = $lq->fetch() ?: null;

    if ($lookedUpSub) {
        $aq = $db->prepare("SELECT * FROM submission_areas WHERE submission_id=? ORDER BY sort_order");
        $aq->execute([$lookedUpSub['id']]);
        $lookedUpAreas = $aq->fetchAll();

        $dq = $db->prepare("SELECT * FROM submission_status_details WHERE submission_id=?");
        $dq->execute([$lookedUpSub['id']]);
        $lookedUpDetail = $dq->fetch() ?: null;

        $uq = $db->prepare("SELECT status FROM edit_unlock_requests WHERE submission_id=? ORDER BY id DESC LIMIT 1");
        $uq->execute([$lookedUpSub['id']]);
        $lookedUpUnlock = $uq->fetchColumn() ?: null;

        $aIds = array_column($lookedUpAreas, 'id');
        if ($aIds) {
            $in3 = implode(',', array_fill(0, count($aIds), '?'));
            $iq  = $db->prepare("SELECT * FROM submission_area_images WHERE area_id IN ($in3) ORDER BY area_id, sort_order");
            $iq->execute($aIds);
            foreach ($iq->fetchAll() as $img) $lookedUpImgs[(int)$img['area_id']][] = $img;
        }
    } else {
        $lookupError = 'No record found for this phone number in the database.';
    }
}

/* ── Visualizer snapshots ─────────────────────────────────────────────────── */
$vizSnapshots = [];
if ($lookedUpSub) {
    $vsQ = $db->prepare("SELECT * FROM visualizer_snapshots WHERE submission_id=? ORDER BY id DESC");
    $vsQ->execute([$lookedUpSub['id']]);
    $vizSnapshots = $vsQ->fetchAll();
}

/* ── Helper functions ─────────────────────────────────────────────────────── */
function statusLabel(string $s): string {
    return [
        'enquiry'          => '🔵 Enquiry',
        'billed'           => '✅ Billed',
        'outside_purchase' => '🏪 Outside Purchase',
        'others'           => '📝 Others',
        'hot'              => '🔵 Enquiry',
        'warm'             => '🔵 Enquiry',
        'cold'             => '📝 Others',
    ][$s] ?? ucfirst($s);
}
function statusBadgeClass(string $s): string {
    return [
        'enquiry'          => 'blue',
        'billed'           => 'success',
        'outside_purchase' => 'warn',
        'others'           => 'gray',
        'hot'              => 'blue',
        'warm'             => 'blue',
        'cold'             => 'gray',
    ][$s] ?? 'blue';
}

$vizUrl   = APP_URL . '/office_admin/visualizer/index.php';
$vizUrlJs = json_encode($vizUrl, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);

pageHead('Walk-In Customer — Office Admin');
topbar($user['username'], $user['role']);
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">

<style>
:root {
  --tc-ink:       #0d1117;
  --tc-font-head: 'Syne', sans-serif;
  --tc-font-mono: 'DM Mono', monospace;
}

/* Employee strip */
.emp-strip{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px}
.emp-chip{background:#fff;border:1px solid var(--c-gray-200);border-radius:10px;padding:9px 14px;box-shadow:var(--shadow);display:flex;align-items:center;gap:10px;min-width:160px;flex:1}
.emp-avatar{width:36px;height:36px;border-radius:50%;background:var(--c-primary-light);display:flex;align-items:center;justify-content:center;font-weight:700;color:var(--c-primary);font-size:.95rem;flex-shrink:0}
.emp-stat-mini{display:flex;gap:6px;margin-top:4px}
.esm{font-size:.7rem;background:var(--c-gray-50);border-radius:5px;padding:2px 7px;font-weight:600;color:var(--c-gray-600)}
.esm.billed{background:#dcfce7;color:#15803d}
.esm.done{background:#bbf7d0;color:#166534}

/* Walk-in box */
.walkin-box{background:#fff;border:1px solid var(--c-gray-200);border-radius:14px;padding:24px;box-shadow:var(--shadow);margin-bottom:20px}
.walkin-title{font-size:1.05rem;font-weight:700;color:var(--c-gray-800);margin-bottom:6px;display:flex;align-items:center;gap:8px}
.walkin-sub{font-size:.82rem;color:var(--c-gray-500);margin-bottom:18px}
.phone-input-wrap{display:flex;gap:8px;align-items:stretch}
.phone-input-wrap input{font-size:1rem;letter-spacing:.04em;flex:1}
.phone-input-wrap button{flex-shrink:0}

/* Result card */
.result-card{background:#fff;border:1px solid var(--c-gray-200);border-radius:14px;overflow:hidden;box-shadow:0 2px 14px rgba(0,0,0,.07);margin-bottom:20px}
.result-head{padding:14px 18px;background:var(--c-gray-50);border-bottom:1px solid var(--c-gray-200);display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap}
.result-body{padding:16px 18px}
.result-foot{padding:10px 18px;border-top:1px solid var(--c-gray-100);background:var(--c-gray-50);display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.comp-banner-r{background:#16a34a;color:#fff;font-size:.74rem;font-weight:700;padding:5px 18px;text-align:center}

/* Info rows */
.info-row{display:flex;gap:6px;margin-bottom:5px;font-size:.84rem}
.info-label{color:var(--c-gray-500);min-width:120px;flex-shrink:0;font-size:.76rem;padding-top:2px}
.info-val{color:var(--c-gray-800);font-weight:500;flex:1;word-break:break-word}

/* Calc summary */
.area-calc-summary{background:#f8fafc;border:1px solid #e2e8f0;border-radius:7px;padding:8px 12px;margin-top:6px;font-size:.73rem}
.acs-row{display:flex;justify-content:space-between;padding:2px 0;border-bottom:1px solid #f1f5f9}
.acs-row:last-child{border-bottom:none}
.acs-k{color:#64748b;font-weight:600}
.acs-v{color:#0f172a;font-family:'DM Mono',monospace;font-weight:500}
.acs-v.amber{color:#b45309;font-weight:700}

/* Misc */
.sec-div{margin-top:10px;padding-top:9px;border-top:1px dashed var(--c-gray-200)}
.sec-lbl{font-size:.7rem;color:var(--c-gray-400);text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px}
.new-cust-hint{background:linear-gradient(135deg,#f0fdf4,#dcfce7);border:1px solid #86efac;border-radius:12px;padding:22px 24px;text-align:center;margin-bottom:20px}
.new-cust-hint h3{font-size:.98rem;font-weight:700;color:#15803d;margin-bottom:4px}
.new-cust-hint p{font-size:.83rem;color:#166534;margin-bottom:0}

/* Locked banner */
.billed-lock-banner{background:linear-gradient(135deg,#dcfce7,#bbf7d0);border:2px solid #4ade80;border-radius:10px;padding:12px 16px;margin-bottom:12px;display:flex;align-items:center;gap:10px}
.billed-lock-banner .blb-icon{font-size:1.4rem;flex-shrink:0}
.billed-lock-banner .blb-text{flex:1}
.billed-lock-banner .blb-title{font-weight:700;color:#15803d;font-size:.9rem}
.billed-lock-banner .blb-sub{font-size:.78rem;color:#166534;margin-top:2px}

/* ══════════════════════════════════════════════
   AREA CARD — improved layout
══════════════════════════════════════════════ */
.area-card{
  border:1px solid var(--c-gray-200);border-radius:10px;
  overflow:hidden;margin-bottom:10px;
  box-shadow:0 1px 4px rgba(0,0,0,.05);
  transition:box-shadow .2s;
}
.area-card:hover{box-shadow:0 3px 12px rgba(0,0,0,.1)}
.area-card-head{
  display:flex;align-items:center;gap:8px;
  padding:10px 14px;background:#f8fafc;
  border-bottom:1px solid var(--c-gray-200);
}
.area-card-name{font-weight:700;font-size:.9rem;color:var(--c-gray-800);flex:1}
.area-card-meta{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.area-tag{padding:2px 8px;border-radius:5px;font-size:.72rem;font-weight:600;white-space:nowrap}
.area-tag.sqft{background:#f1f5f9;color:#475569}
.area-tag.tile{background:#ecfdf5;color:#15803d}
.area-tag.wall{background:#fef3c7;color:#92400e}
.area-tag.bath{background:#ede9fe;color:#6d28d9}
.area-tag.photos{background:#f1f5f9;color:#64748b}
.area-card-body{padding:12px 14px}
.room-data-grid{
  display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));
  gap:8px;margin-bottom:10px;
}
.room-data-item{
  background:#f8fafc;border:1px solid var(--c-gray-200);border-radius:10px;
  padding:10px 12px;
}
.room-data-item .rk{
  display:block;font-size:.65rem;font-weight:800;letter-spacing:.08em;
  text-transform:uppercase;color:#64748b;margin-bottom:4px;
}
.room-data-item .rv{
  display:block;font-size:.86rem;font-weight:700;color:#0f172a;
}
.room-data-item.accent .rv{color:#d97706}
.room-calc-grid{
  display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));
  gap:8px;margin-bottom:10px;
}
.room-calc-card{
  background:#fff;border:1px solid var(--c-gray-200);border-radius:10px;
  padding:10px 12px;
}
.room-calc-title{
  font-size:.74rem;font-weight:800;color:#334155;margin-bottom:8px;
}
.room-calc-line{
  display:flex;justify-content:space-between;gap:8px;
  font-size:.75rem;padding:3px 0;border-bottom:1px dashed #e2e8f0;
}
.room-calc-line:last-child{border-bottom:none}
.room-calc-line span:first-child{color:#64748b}
.room-calc-line span:last-child{color:#0f172a;font-weight:700;text-align:right}
.room-rows-grid{
  display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
  gap:8px;margin-bottom:10px;
}
.room-row-card{
  background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:10px 12px;
}
.room-row-card .rr-title{
  font-size:.74rem;font-weight:800;color:#9a3412;margin-bottom:6px;
}
.room-row-card .rr-line{
  font-size:.75rem;color:#7c2d12;line-height:1.45;
}
.room-row-card .rr-line strong{color:#431407}

/* Delete area button */
.btn-del-area{
  display:inline-flex;align-items:center;gap:4px;
  padding:3px 10px;border-radius:6px;font-size:.7rem;font-weight:700;
  background:#fff0f0;border:1px solid #fca5a5;color:#dc2626;
  cursor:pointer;transition:all .15s;flex-shrink:0;
}
.btn-del-area:hover{background:#dc2626;color:#fff;border-color:#dc2626}

/* Viz area button */
.btn-viz-area{
  display:inline-flex;align-items:center;gap:4px;
  padding:3px 10px;border-radius:6px;font-size:.7rem;font-weight:700;
  background:#ede9fe;border:1px solid #a78bfa;color:#6d28d9;
  cursor:pointer;transition:all .15s;flex-shrink:0;
}
.btn-viz-area:hover{background:#6d28d9;color:#fff;border-color:#6d28d9}

/* ══════════════════════════════════════════════
   AREA IMAGE GRID — row/column, bigger cells
══════════════════════════════════════════════ */
.area-img-grid{
  display:grid;
  grid-template-columns:repeat(4,1fr);
  gap:8px;
  margin-top:8px;
}
@media(max-width:600px){
  .area-img-grid{grid-template-columns:repeat(2,1fr)}
}
.area-img-item{
  position:relative;
  aspect-ratio:4/3;
  border-radius:8px;overflow:hidden;
  border:1.5px solid var(--c-gray-200);
  cursor:pointer;background:var(--c-gray-100);
  transition:border-color .15s,transform .15s;
}
.area-img-item:hover{border-color:#6d28d9;transform:scale(1.02)}
.area-img-item img{
  width:100%;height:100%;
  object-fit:cover;display:block;
  transition:transform .2s;
}
.area-img-item:hover img{transform:scale(1.06)}
.area-img-overlay{
  position:absolute;inset:0;
  background:linear-gradient(to top,rgba(0,0,0,.55) 0%,transparent 50%);
  display:flex;align-items:flex-end;justify-content:space-between;
  padding:6px 8px;opacity:0;transition:opacity .15s;
}
.area-img-item:hover .area-img-overlay{opacity:1}
.viz-send-pill{
  background:rgba(79,70,229,.9);color:#fff;border:none;
  border-radius:5px;padding:3px 8px;font-size:.62rem;font-weight:700;
  cursor:pointer;white-space:nowrap;backdrop-filter:blur(4px);
  transition:background .15s;
}
.viz-send-pill:hover{background:#4f46e5}

/* ══════════════════════════════════════════════
   VISUALIZER OUTPUT SECTION — full-width grid
══════════════════════════════════════════════ */
.viz-output-section{
  background:linear-gradient(135deg,#1e1b4b 0%,#2e1065 100%);
  border:2px solid #6d28d9;border-radius:14px;
  padding:18px;margin-top:12px;
}
.viz-output-header{
  display:flex;align-items:center;justify-content:space-between;
  margin-bottom:14px;flex-wrap:wrap;gap:8px;
}
.viz-output-title{
  font-size:.85rem;font-weight:800;color:#c4b5fd;
  letter-spacing:.06em;text-transform:uppercase;
  display:flex;align-items:center;gap:8px;
}
.viz-count-badge{
  background:rgba(167,139,250,.25);border:1px solid rgba(167,139,250,.4);
  color:#c4b5fd;border-radius:20px;padding:2px 10px;
  font-size:.68rem;font-weight:700;
}

/* Visualizer images — larger 2-column grid */
.viz-img-grid{
  display:grid;
  grid-template-columns:repeat(auto-fill,minmax(200px,1fr));
  gap:10px;
  margin-bottom:14px;
}
@media(max-width:500px){
  .viz-img-grid{grid-template-columns:1fr 1fr}
}
.viz-snap-item{
  position:relative;border-radius:10px;overflow:hidden;
  border:2px solid rgba(124,58,237,.4);
  background:#0d0a1a;
  transition:border-color .15s,transform .15s;
  cursor:pointer;
}
.viz-snap-item:hover{border-color:#f5a623;transform:scale(1.02)}
.viz-snap-item img{
  width:100%;aspect-ratio:16/9;
  object-fit:cover;display:block;
  transition:transform .2s;
}
.viz-snap-item:hover img{transform:scale(1.04)}
.viz-snap-overlay{
  position:absolute;inset:0;
  background:linear-gradient(to top,rgba(0,0,0,.7) 0%,transparent 45%);
  display:flex;align-items:flex-end;justify-content:space-between;
  padding:8px 10px;opacity:0;transition:opacity .15s;
}
.viz-snap-item:hover .viz-snap-overlay{opacity:1}
.viz-snap-date{
  font-size:.65rem;color:rgba(255,255,255,.8);
  font-family:'DM Mono',monospace;
}
.viz-snap-del{
  background:rgba(220,38,38,.85);color:#fff;border:none;
  border-radius:5px;padding:3px 8px;font-size:.62rem;font-weight:700;
  cursor:pointer;transition:background .15s;
}
.viz-snap-del:hover{background:#dc2626}

/* Viz upload zone */
.viz-upload-zone{
  border:2px dashed rgba(167,139,250,.4);border-radius:10px;
  padding:20px;text-align:center;cursor:pointer;
  transition:border-color .2s,background .2s;
  position:relative;background:rgba(109,40,217,.08);
}
.viz-upload-zone:hover,.viz-upload-zone.drag-over{
  border-color:#a78bfa;background:rgba(109,40,217,.18);
}
.viz-upload-zone input[type=file]{
  position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;
}
.viz-upload-icon{font-size:2rem;margin-bottom:8px;opacity:.7}
.viz-upload-label{font-size:.82rem;color:#a78bfa;pointer-events:none}
.viz-upload-label strong{color:#c4b5fd;display:block;margin-bottom:2px}
.viz-upload-hint{font-size:.68rem;color:rgba(167,139,250,.6);margin-top:4px}

/* Upload previews */
.viz-upload-previews{
  display:grid;grid-template-columns:repeat(auto-fill,minmax(80px,1fr));
  gap:6px;margin:10px 0;
}
.viz-upload-preview-item{
  position:relative;border-radius:6px;overflow:hidden;
  border:1.5px solid rgba(124,58,237,.4);
  aspect-ratio:16/9;background:#0d0a1a;
}
.viz-upload-preview-item img{width:100%;height:100%;object-fit:cover;display:block}
.viz-upload-preview-del{
  position:absolute;top:2px;right:2px;background:rgba(220,38,38,.9);
  color:#fff;border:none;border-radius:3px;
  width:16px;height:16px;font-size:.55rem;cursor:pointer;
  display:flex;align-items:center;justify-content:center;
}

.viz-save-btn{
  width:100%;padding:10px;border:none;border-radius:8px;
  background:linear-gradient(135deg,#6d28d9,#4f46e5);
  color:#fff;font-size:.84rem;font-weight:700;cursor:pointer;
  display:flex;align-items:center;justify-content:center;gap:8px;
  transition:all .15s;margin-top:10px;
}
.viz-save-btn:hover:not(:disabled){background:linear-gradient(135deg,#7c3aed,#4338ca);transform:translateY(-1px)}
.viz-save-btn:disabled{opacity:.5;cursor:not-allowed}
.viz-snap-status{font-size:.72rem;margin-top:6px;text-align:center;color:#a78bfa;min-height:16px}

/* Visualizer buttons */
.btn-visualizer{display:inline-flex;align-items:center;gap:7px;background:linear-gradient(135deg,#6d28d9,#4f46e5);color:#fff;border:none;border-radius:8px;padding:8px 16px;font-size:.82rem;font-weight:700;cursor:pointer;transition:all .18s;text-decoration:none;box-shadow:0 2px 12px rgba(109,40,217,.35);letter-spacing:.02em}
.btn-visualizer:hover{background:linear-gradient(135deg,#7c3aed,#4338ca);box-shadow:0 4px 18px rgba(109,40,217,.5);transform:translateY(-1px);color:#fff;text-decoration:none}
.btn-visualizer-locked{display:inline-flex;align-items:center;gap:7px;background:linear-gradient(135deg,#059669,#047857);color:#fff;border:none;border-radius:8px;padding:8px 16px;font-size:.82rem;font-weight:700;cursor:pointer;transition:all .18s;text-decoration:none;box-shadow:0 2px 12px rgba(5,150,105,.35);letter-spacing:.02em}
.btn-visualizer-locked:hover{background:linear-gradient(135deg,#10b981,#059669);transform:translateY(-1px);color:#fff;text-decoration:none}

/* Viz picker panel */
#vizPickerPanel{display:none;position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#1e1b4b;border:2px solid #6d28d9;border-radius:14px;padding:14px 18px;z-index:8000;box-shadow:0 8px 48px rgba(0,0,0,.6);min-width:320px;max-width:92vw;backdrop-filter:blur(12px)}
#vizPickerPanel.open{display:block}
.vpp-title{font-size:.78rem;font-weight:800;color:#c4b5fd;letter-spacing:.06em;text-transform:uppercase;margin-bottom:10px;display:flex;align-items:center;justify-content:space-between}
.vpp-close{background:none;border:none;color:#a78bfa;cursor:pointer;font-size:1rem;padding:0 2px}
.vpp-grid{display:flex;gap:8px;flex-wrap:wrap;max-height:200px;overflow-y:auto;margin-bottom:10px}
.vpp-item{display:flex;flex-direction:column;align-items:center;gap:4px;cursor:pointer}
.vpp-item img{height:58px;width:58px;object-fit:cover;border-radius:7px;border:2px solid rgba(167,139,250,.3);transition:border-color .15s,transform .15s}
.vpp-item:hover img{border-color:#a78bfa;transform:scale(1.06)}
.vpp-item.selected img{border-color:#f5a623;outline:2px solid #f5a623}
.vpp-name{font-size:.6rem;color:#c4b5fd;font-weight:600;text-align:center;max-width:60px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.vpp-hint{font-size:.7rem;color:#7c6fcd;margin-bottom:8px;line-height:1.4}
.vpp-actions{display:flex;gap:8px}
.vpp-btn{flex:1;padding:7px 12px;border-radius:7px;font-size:.78rem;font-weight:700;cursor:pointer;border:none;transition:all .15s}
.vpp-btn.launch{background:#6d28d9;color:#fff}
.vpp-btn.launch:hover{background:#7c3aed}
.vpp-btn.cancel{background:#312e81;color:#c4b5fd}
.vpp-btn.cancel:hover{background:#3730a3}

/* Print button */
.btn-print{display:inline-flex;align-items:center;gap:6px;background:linear-gradient(135deg,#0369a1,#0284c7);color:#fff;border:none;border-radius:8px;padding:8px 16px;font-size:.82rem;font-weight:700;cursor:pointer;transition:all .18s;box-shadow:0 2px 8px rgba(3,105,161,.3);text-decoration:none}
.btn-print:hover{background:linear-gradient(135deg,#075985,#0369a1);transform:translateY(-1px);color:#fff;text-decoration:none}

/* Lightbox */
#lightbox{position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.92);display:none;align-items:center;justify-content:center;flex-direction:column;padding:16px;backdrop-filter:blur(4px)}
#lightbox.open{display:flex}
#lightbox-img{max-width:min(92vw,1100px);max-height:85vh;object-fit:contain;border-radius:10px;box-shadow:0 8px 48px rgba(0,0,0,.6);background:#222}
#lightbox-caption{margin-top:10px;color:rgba(255,255,255,.75);font-size:.82rem;text-align:center}
#lightbox-close{position:fixed;top:14px;right:14px;background:rgba(255,255,255,.12);border:none;color:#fff;font-size:1.4rem;width:40px;height:40px;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center}
#lightbox-close:hover{background:rgba(255,255,255,.25)}
#lightbox-nav{display:flex;gap:12px;margin-top:12px}
.lb-btn{background:rgba(255,255,255,.12);border:none;color:#fff;padding:6px 18px;border-radius:6px;cursor:pointer;font-size:1rem}
.lb-btn:hover{background:rgba(255,255,255,.25)}
.lb-btn:disabled{opacity:.3;cursor:default}
#lightbox-counter{color:rgba(255,255,255,.5);font-size:.8rem;padding:0 8px;display:flex;align-items:center}

/* Delete confirm dialog */
.del-confirm-overlay{
  position:fixed;inset:0;z-index:9000;background:rgba(0,0,0,.6);
  display:none;align-items:center;justify-content:center;
}
.del-confirm-overlay.open{display:flex}
.del-confirm-box{
  background:#fff;border-radius:12px;padding:24px 28px;
  max-width:380px;width:90%;box-shadow:0 8px 40px rgba(0,0,0,.3);text-align:center;
}
.del-confirm-icon{font-size:2.4rem;margin-bottom:8px}
.del-confirm-title{font-size:1rem;font-weight:700;color:#1f2937;margin-bottom:6px}
.del-confirm-sub{font-size:.83rem;color:#6b7280;margin-bottom:20px}
.del-confirm-actions{display:flex;gap:10px;justify-content:center}

@media print {
  *{color:#000!important;background:#fff!important;box-shadow:none!important}
  img{filter:grayscale(100%)!important}
  .no-print,.mob-nav,.sidebar,.topbar,#lightbox,#vizPickerPanel,.modal-overlay,
  .result-foot,.btn-visualizer,.btn-visualizer-locked,.btn-print,
  .viz-snap-del,.viz-output-section,.del-confirm-overlay{display:none!important}
  .result-card{border:1px solid #999!important;page-break-inside:avoid}
  @page{margin:15mm;size:A4}
}
</style>

<!-- Lightbox -->
<div id="lightbox" onclick="if(event.target===this)closeLb()">
  <button id="lightbox-close" onclick="closeLb()">✕</button>
  <img id="lightbox-img" src="" alt="">
  <div id="lightbox-caption"></div>
  <div id="lightbox-nav">
    <button class="lb-btn" id="lb-prev" onclick="lbNav(-1)">← Prev</button>
    <span id="lightbox-counter"></span>
    <button class="lb-btn" id="lb-next" onclick="lbNav(1)">Next →</button>
  </div>
</div>

<!-- Delete area confirm dialog -->
<div class="del-confirm-overlay" id="delAreaOverlay">
  <div class="del-confirm-box">
    <div class="del-confirm-icon">🗑️</div>
    <div class="del-confirm-title">Delete this area?</div>
    <div class="del-confirm-sub" id="delAreaSub">This will permanently remove the area and all its photos.</div>
    <div class="del-confirm-actions">
      <button class="btn btn-ghost" onclick="closeDelAreaConfirm()">Cancel</button>
      <button class="btn btn-danger" id="delAreaConfirmBtn">Delete Area</button>
    </div>
  </div>
</div>

<!-- Viz picker panel -->
<div id="vizPickerPanel">
  <div class="vpp-title">
    <span>🏠 Open in 3D Visualizer</span>
    <button class="vpp-close" onclick="closeVizPicker()">✕</button>
  </div>
  <p class="vpp-hint">Click images to select which tiles to send. If none selected, all tiles are sent.</p>
  <div class="vpp-grid" id="vppGrid"></div>
  <div class="vpp-actions">
    <button class="vpp-btn cancel" onclick="closeVizPicker()">Cancel</button>
    <button class="vpp-btn launch" onclick="launchVisualizer()">🚀 Open Visualizer</button>
  </div>
</div>

<div class="app-wrap">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="main-content">

    <div class="page-header" style="margin-bottom:16px">
      <div class="page-title">🚶 Walk-In Customer</div>
      <div class="page-sub">Search by phone to find &amp; update any record across the full database</div>
    </div>

    <?php if ($flashMsg): alert($flashMsg, $flashType); endif; ?>

    <!-- Employee strip -->
    <?php if ($mySubs): ?>
    <div class="emp-strip">
      <?php foreach ($mySubs as $sa): $online = (int)$sa['active_sessions'] > 0; ?>
      <div class="emp-chip">
        <div class="emp-avatar"><?= strtoupper(substr($sa['username'], 0, 1)) ?></div>
        <div style="flex:1;min-width:0">
          <div style="font-weight:700;font-size:.88rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($sa['username']) ?></div>
          <div style="font-size:.7rem;color:var(--c-gray-400);margin-bottom:4px">
            <?php if ($online): ?><span style="color:var(--c-success)">● Online</span><?php else: ?><span>● Offline</span><?php endif; ?>
          </div>
          <div class="emp-stat-mini">
            <span class="esm"><?= (int)$sa['total_subs'] ?> total</span>
            <span class="esm billed"><?= (int)$sa['hot_subs'] ?> billed</span>
            <span class="esm done"><?= (int)$sa['completed_subs'] ?> done</span>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Phone lookup -->
    <div class="walkin-box">
      <div class="walkin-title">
        <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
        </svg>
        Search Walk-In Customer
      </div>
      <div class="walkin-sub">Enter the customer's phone number to search across all records in the database.</div>
      <form method="GET" id="lookupForm" autocomplete="off">
        <div class="phone-input-wrap">
          <input class="form-control" type="tel" name="phone_lookup" id="phone_lookup"
                 placeholder="Enter phone number…"
                 value="<?= htmlspecialchars($phoneLookup) ?>"
                 inputmode="numeric" pattern="[0-9+\-\s]{6,15}" autofocus>
          <button type="submit" class="btn btn-primary">🔍 Search</button>
          <?php if ($phoneLookup): ?>
          <a href="subadmins.php" class="btn btn-ghost">✕ Clear</a>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <?php if ($phoneLookup !== ''): ?>
      <?php if ($lookedUpSub): ?>
        <?php
          $s            = $lookedUpSub;
          $areas        = $lookedUpAreas;
          $detail       = $lookedUpDetail;
          $locked       = (bool)$s['order_completed'];
          $isBilled     = ($s['status'] === 'billed');
          $unlockStatus = $lookedUpUnlock;
          $dispTime     = $s['device_submitted_at'] ?: $s['submitted_at'];
          $badgeClass   = statusBadgeClass($s['status']);

          $propGal = [];
          if (!empty($s['landmark_picture']))   $propGal[] = ['url'=>imgUrl($s['landmark_picture']),   'cap'=>'Landmark'];
          if (!empty($s['additional_picture'])) $propGal[] = ['url'=>imgUrl($s['additional_picture']), 'cap'=>'Additional'];
          if (!empty($s['tile_image']))         $propGal[] = ['url'=>imgUrl($s['tile_image']),          'cap'=>'Tile Image'];
          $propGalJs = json_encode($propGal, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);

          $sJs     = json_encode($s,      JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
          $dJs     = json_encode($detail, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
          $areasJs = json_encode($areas,  JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);

          $aImgMapJs = [];
          foreach ($areas as $a) $aImgMapJs[$a['id']] = $lookedUpImgs[(int)$a['id']] ?? [];
          $aImgsJs = json_encode($aImgMapJs, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);

          $vizTiles = [];
          if (!empty($s['tile_image'])) {
              $vizTiles[] = ['name'=>'Property Tile','url'=>imgUrl($s['tile_image']),'area'=>'Property','imgId'=>0];
          }
          foreach ($areas as $a) {
              $aImgsForArea = $lookedUpImgs[(int)$a['id']] ?? [];
              foreach ($aImgsForArea as $idx => $img) {
                  $vizTiles[] = ['name'=>$a['area_type'].($idx>0?' #'.($idx+1):''),'url'=>imgUrl($img['image_filename']),'area'=>$a['area_type'],'imgId'=>(int)$img['id']];
              }
          }
          $vizTilesJs = json_encode($vizTiles, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
          $ownerJs    = json_encode($s['owner_name'], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);

          $vizSnapsJs = json_encode(array_map(fn($vs) => [
              'url'  => imgUrl($vs['filename']),
              'date' => date('d M Y H:i', strtotime($vs['created_at'])),
              'id'   => (int)$vs['id'],
          ], $vizSnapshots), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);

          $printUrl = 'print_submission.php?id=' . (int)$s['id'] . ($phoneLookup ? '&phone=' . urlencode($phoneLookup) : '');
        ?>

        <div class="result-card">
          <?php if ($locked): ?>
          <div class="comp-banner-r">✅ Billed<?= $s['purchase_date'] ? ' — ' . date('d M Y', strtotime($s['purchase_date'])) : '' ?></div>
          <?php endif; ?>

          <div class="result-head">
            <div>
              <div style="font-weight:700;font-size:1.05rem"><?= htmlspecialchars($s['owner_name']) ?></div>
              <div style="font-size:.8rem;color:var(--c-gray-500);margin-top:2px"><?= htmlspecialchars($s['owner_number']) ?></div>
              <span style="font-size:.72rem;background:#f0fdf4;color:#15803d;padding:2px 8px;border-radius:4px;display:inline-block;margin-top:4px">
                👤 Sub-Admin: <?= htmlspecialchars($s['sub_name']) ?>
              </span>
            </div>
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:5px">
              <span class="badge badge-<?= $badgeClass ?>">
                <span class="badge-dot"></span><?= statusLabel($s['status']) ?>
              </span>
              <span class="badge badge-blue" style="font-size:.7rem">
                <?= htmlspecialchars($s['dir_code'] ?? '') ?> › <?= htmlspecialchars($s['zone'] ?? '') ?>
              </span>
            </div>
          </div>

          <div class="result-body">

            <?php if ($locked): ?>
            <div class="billed-lock-banner">
              <div class="blb-icon">🔒</div>
              <div class="blb-text">
                <div class="blb-title">Order billed — status updates are open</div>
                <div class="blb-sub">
                  You can update this customer's status from the button below.
                </div>
              </div>
            </div>
            <?php endif; ?>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 16px">
              <div class="info-row"><span class="info-label">Representative</span><span class="info-val"><?= htmlspecialchars($s['representative_name'] ?? '') ?></span></div>
              <div class="info-row"><span class="info-label">Rep Phone</span><span class="info-val"><?= htmlspecialchars($s['representative_number'] ?? '') ?></span></div>
              <div class="info-row"><span class="info-label">Village / Town</span><span class="info-val"><?= htmlspecialchars($s['village_city_town'] ?? '') ?></span></div>
              <div class="info-row"><span class="info-label">Street</span><span class="info-val"><?= htmlspecialchars($s['street'] ?? '') ?></span></div>
              <?php if ($s['purchase_date']): ?>
              <div class="info-row"><span class="info-label">Purchase Date</span><span class="info-val" style="color:#15803d;font-weight:700">📅 <?= date('d M Y', strtotime($s['purchase_date'])) ?></span></div>
              <?php endif; ?>
              <?php if (!empty($s['other_status_reason'])): ?>
              <div class="info-row"><span class="info-label">Reason</span><span class="info-val"><?= htmlspecialchars($s['other_status_reason']) ?></span></div>
              <?php endif; ?>
            </div>

            <?php if ($propGal): ?>
            <div class="sec-div">
              <div class="sec-lbl">Property Images</div>
              <div style="display:flex;gap:8px;flex-wrap:wrap">
                <?php foreach ($propGal as $pIdx => $pg): ?>
                <img src="<?= htmlspecialchars($pg['url']) ?>" alt="<?= htmlspecialchars($pg['cap']) ?>"
                     loading="lazy"
                     style="height:80px;width:80px;object-fit:cover;border-radius:8px;cursor:pointer;border:1px solid var(--c-gray-200);transition:transform .15s"
                     onmouseover="this.style.transform='scale(1.04)'" onmouseout="this.style.transform=''"
                     onclick='openLb(<?= $propGalJs ?>,<?= $pIdx ?>)'>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endif; ?>

            <?php if ($areas): ?>
            <div class="sec-div">
              <div class="sec-lbl" style="display:flex;align-items:center;justify-content:space-between">
                <span>Areas / Rooms — <strong><?= count($areas) ?></strong> area(s)</span>
              </div>

              <?php foreach ($areas as $a):
                $aImgsForArea = $lookedUpImgs[(int)$a['id']] ?? [];
                $sqft         = ($a['tile_surface'] ?? 'floor') === 'both'
                                ? ((float)($a['floor_sqft'] ?? 0) + (float)($a['wall_sqft'] ?? 0))
                                : ($a['total_sqft'] ?? $a['area_sqft'] ?? null);
                $surf         = $a['tile_surface'] ?? 'floor';
                $calcSummary  = !empty($a['calc_summary']) ? json_decode($a['calc_summary'], true) : null;

                /* Build gallery arrays */
                $aGal = [];
                foreach ($aImgsForArea as $img)
                    $aGal[] = ['url'=>imgUrl($img['image_filename']),'cap'=>htmlspecialchars($a['area_type']).' — '.ucfirst($img['image_type'])];
                $aGalJs = json_encode($aGal, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);

                $aVizTiles = [];
                foreach ($aImgsForArea as $idx2 => $img2)
                    $aVizTiles[] = ['name'=>$a['area_type'].($idx2>0?' #'.($idx2+1):''),'url'=>imgUrl($img2['image_filename']),'area'=>$a['area_type'],'imgId'=>(int)$img2['id']];
                $aVizJs = json_encode($aVizTiles, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
              ?>

              <div class="area-card">
                <!-- Area header -->
                <div class="area-card-head">
                  <span class="area-card-name"><?= htmlspecialchars($a['area_type']) ?></span>
                  <div class="area-card-meta">
                    <?php if ($sqft): ?>
                    <span class="area-tag sqft"><?= number_format((float)$sqft) ?> sqft</span>
                    <?php endif; ?>
                    <?php if (!empty($a['area_length']) && !empty($a['area_breadth'])): ?>
                    <span class="area-tag sqft"><?= $a['area_length'] ?>×<?= $a['area_breadth'] ?><?= !empty($a['area_height'])?' h:'.$a['area_height']:'' ?> ft</span>
                    <?php endif; ?>
                    <?php if (!empty($a['tile_chosen'])): ?>
                    <span class="area-tag tile">🪟 <?= htmlspecialchars($a['tile_chosen']) ?></span>
                    <?php endif; ?>
                    <?php if ($surf !== 'floor'): ?>
                    <span class="area-tag wall"><?= $surf==='wall'?'🧱 Wall':'🏠 Floor+Wall' ?></span>
                    <?php endif; ?>
                    <?php if (!empty($a['is_bathroom'])): ?>
                    <span class="area-tag bath">🛁 Bathroom</span>
                    <?php endif; ?>
                    <span class="area-tag photos">📷 <?= count($aImgsForArea) ?>/4</span>
                  </div>
                  <!-- Action buttons -->
                  <div style="display:flex;gap:6px;margin-left:8px">
                    <?php if ($aImgsForArea): ?>
                    <button type="button" class="btn-viz-area"
                            onclick='openVizPickerForArea(<?= $aVizJs ?>,<?= $ownerJs ?>,"<?= htmlspecialchars($a['area_type'],ENT_QUOTES) ?>")'>
                      <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
                      3D Viz
                    </button>
                    <?php endif; ?>
                    <?php if (!$locked || $unlockStatus === 'approved'): ?>
                    <button type="button" class="btn-del-area"
                            onclick='confirmDelArea(<?= (int)$a['id'] ?>,<?= (int)$s['id'] ?>,"<?= htmlspecialchars($a['area_type'],ENT_QUOTES) ?>")'>
                      <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                      Delete
                    </button>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- Area body -->
                <div class="area-card-body">
                  <?php
                    $csSqft = is_array($calcSummary['sqft'] ?? null) ? $calcSummary['sqft'] : [];
                    $csFloor = is_array($calcSummary['floor'] ?? null) ? $calcSummary['floor'] : [];
                    $csWall = is_array($calcSummary['wall'] ?? null) ? $calcSummary['wall'] : [];
                    $csBath = is_array($calcSummary['bath'] ?? null) ? $calcSummary['bath'] : [];
                    $csMeta = is_array($calcSummary['meta'] ?? null) ? $calcSummary['meta'] : [];
                    $csRows = is_array($csMeta['rows'] ?? null) ? $csMeta['rows'] : [];
                    $roomMultiplier = (int)($csMeta['multiplier'] ?? ($a['area_count'] ?? 1));
                  ?>

                  <div class="room-data-grid">
                    <div class="room-data-item"><span class="rk">Surface</span><span class="rv"><?= htmlspecialchars(ucwords(str_replace('_',' ', $surf ?: 'floor'))) ?></span></div>
                    <div class="room-data-item"><span class="rk">Length</span><span class="rv"><?= $a['area_length'] !== null && $a['area_length'] !== '' ? number_format((float)$a['area_length'], 2).' ft' : '—' ?></span></div>
                    <div class="room-data-item"><span class="rk">Breadth</span><span class="rv"><?= $a['area_breadth'] !== null && $a['area_breadth'] !== '' ? number_format((float)$a['area_breadth'], 2).' ft' : '—' ?></span></div>
                    <div class="room-data-item"><span class="rk">Height</span><span class="rv"><?= $a['area_height'] !== null && $a['area_height'] !== '' ? number_format((float)$a['area_height'], 2).' ft' : '—' ?></span></div>
                    <div class="room-data-item"><span class="rk">Multiple Rooms</span><span class="rv"><?= max(1, $roomMultiplier) ?></span></div>
                    <div class="room-data-item accent"><span class="rk">Overall Sqft</span><span class="rv"><?= isset($csSqft['overall_total']) ? number_format((float)$csSqft['overall_total'], 2).' ft²' : ($sqft ? number_format((float)$sqft,2).' ft²' : '—') ?></span></div>
                    <div class="room-data-item accent"><span class="rk">Grand Total Boxes</span><span class="rv"><?= isset($csMeta['grand_total_boxes']) ? number_format((int)$csMeta['grand_total_boxes']) : '—' ?></span></div>
                  </div>

                  <div class="room-calc-grid">
                    <div class="room-calc-card">
                      <div class="room-calc-title">Floor Calculation</div>
                      <div class="room-calc-line"><span>Single room</span><span><?= isset($csSqft['single_room_floor']) ? number_format((float)$csSqft['single_room_floor'], 2).' ft²' : '—' ?></span></div>
                      <div class="room-calc-line"><span>Total</span><span><?= isset($csSqft['floor_total']) ? number_format((float)$csSqft['floor_total'], 2).' ft²' : '—' ?></span></div>
                      <div class="room-calc-line"><span>Tile size</span><span><?= htmlspecialchars((string)($csFloor['tile_size'] ?? '—')) ?></span></div>
                      <div class="room-calc-line"><span>Wastage</span><span><?= isset($csFloor['waste_pct']) ? htmlspecialchars((string)$csFloor['waste_pct']).'%' : '—' ?></span></div>
                      <div class="room-calc-line"><span>Boxes / room</span><span><?= isset($csFloor['boxes_single']) ? number_format((int)$csFloor['boxes_single']) : '—' ?></span></div>
                      <div class="room-calc-line"><span>Total boxes</span><span><?= isset($csFloor['boxes_total']) ? number_format((int)$csFloor['boxes_total']) : '—' ?></span></div>
                    </div>

                    <div class="room-calc-card">
                      <div class="room-calc-title">Wall Calculation</div>
                      <div class="room-calc-line"><span>Single room</span><span><?= isset($csSqft['single_room_wall']) ? number_format((float)$csSqft['single_room_wall'], 2).' ft²' : '—' ?></span></div>
                      <div class="room-calc-line"><span>Total</span><span><?= isset($csSqft['wall_total']) ? number_format((float)$csSqft['wall_total'], 2).' ft²' : '—' ?></span></div>
                      <div class="room-calc-line"><span>Tile size</span><span><?= htmlspecialchars((string)($csWall['tile_size'] ?? '—')) ?></span></div>
                      <div class="room-calc-line"><span>Wastage</span><span><?= isset($csWall['waste_pct']) ? htmlspecialchars((string)$csWall['waste_pct']).'%' : '—' ?></span></div>
                      <div class="room-calc-line"><span>Boxes / room</span><span><?= isset($csWall['boxes_single']) ? number_format((int)$csWall['boxes_single']) : '—' ?></span></div>
                      <div class="room-calc-line"><span>Total boxes</span><span><?= isset($csWall['boxes_total']) ? number_format((int)$csWall['boxes_total']) : '—' ?></span></div>
                    </div>

                    <?php if (!empty($a['is_bathroom'])): ?>
                    <div class="room-calc-card">
                      <div class="room-calc-title">Bathroom Partition</div>
                      <div class="room-calc-line"><span>Light height</span><span><?= $a['bath_light_h'] !== null && $a['bath_light_h'] !== '' ? number_format((float)$a['bath_light_h'], 2).' ft' : '—' ?></span></div>
                      <div class="room-calc-line"><span>Highlight 1</span><span><?= $a['bath_high_h'] !== null && $a['bath_high_h'] !== '' ? number_format((float)$a['bath_high_h'], 2).' ft' : '—' ?></span></div>
                      <div class="room-calc-line"><span>Highlight 2</span><span><?= $a['bath_high2_h'] !== null && $a['bath_high2_h'] !== '' ? number_format((float)$a['bath_high2_h'], 2).' ft' : '—' ?></span></div>
                      <div class="room-calc-line"><span>Dark height</span><span><?= $a['bath_dark_h'] !== null && $a['bath_dark_h'] !== '' ? number_format((float)$a['bath_dark_h'], 2).' ft' : '—' ?></span></div>
                      <div class="room-calc-line"><span>Fall height</span><span><?= $a['bath_fall_h'] !== null && $a['bath_fall_h'] !== '' ? number_format((float)$a['bath_fall_h'], 2).' ft' : '—' ?></span></div>
                      <div class="room-calc-line"><span>Wall length</span><span><?= isset($csBath['wall_length']) ? number_format((float)$csBath['wall_length'], 2).' ft' : '—' ?></span></div>
                      <div class="room-calc-line"><span>Wall tile size</span><span><?= htmlspecialchars((string)($csBath['tile_size'] ?? '—')) ?></span></div>
                      <div class="room-calc-line"><span>Wall wastage</span><span><?= isset($csBath['waste_pct']) ? htmlspecialchars((string)$csBath['waste_pct']).'%' : '—' ?></span></div>
                      <div class="room-calc-line"><span>Partition area</span><span><?= isset($csBath['area_single']) ? number_format((float)$csBath['area_single'], 2).' ft²' : '—' ?></span></div>
                      <div class="room-calc-line"><span>Boxes / room</span><span><?= isset($csBath['boxes_single']) ? number_format((int)$csBath['boxes_single']) : '—' ?></span></div>
                    </div>
                    <?php endif; ?>
                  </div>

                  <?php if (!empty($csBath['parts']) && is_array($csBath['parts'])): ?>
                  <div class="room-rows-grid" style="margin-top:-2px">
                    <?php foreach ($csBath['parts'] as $bp): ?>
                    <div class="room-row-card">
                      <div class="rr-title"><?= htmlspecialchars((string)($bp['label'] ?? 'Partition')) ?></div>
                      <div class="rr-line">Requested height: <strong><?= isset($bp['requested_height']) ? number_format((float)$bp['requested_height'], 2).' ft' : '—' ?></strong></div>
                      <div class="rr-line">Applied height: <strong><?= isset($bp['height']) ? number_format((float)$bp['height'], 2).' ft' : '—' ?></strong></div>
                      <div class="rr-line">Area: <strong><?= isset($bp['area']) ? number_format((float)$bp['area'], 2).' ft²' : '—' ?></strong></div>
                      <div class="rr-line">Boxes: <strong><?= isset($bp['boxes']) ? number_format((int)$bp['boxes']) : '—' ?></strong></div>
                    </div>
                    <?php endforeach; ?>
                  </div>
                  <?php endif; ?>

                  <?php if ($csRows): ?>
                  <div class="room-rows-grid">
                    <?php foreach ($csRows as $rowInfo): ?>
                    <div class="room-row-card">
                      <div class="rr-title">Row <?= (int)($rowInfo['row'] ?? 0) ?></div>
                      <div class="rr-line">Length: <strong><?= number_format((float)($rowInfo['length'] ?? 0), 2) ?> ft</strong></div>
                      <div class="rr-line">Breadth: <strong><?= number_format((float)($rowInfo['breadth'] ?? 0), 2) ?> ft</strong></div>
                      <div class="rr-line">Height: <strong><?= number_format((float)($rowInfo['height'] ?? 0), 2) ?> ft</strong></div>
                      <?php if (!empty($rowInfo['floor'])): ?>
                      <div class="rr-line">Floor: <strong><?= htmlspecialchars((string)($rowInfo['floor_formula'] ?? '')) ?></strong> = <strong><?= number_format((float)($rowInfo['floor_area'] ?? 0), 2) ?> ft²</strong></div>
                      <?php endif; ?>
                      <?php if (!empty($rowInfo['wall'])): ?>
                      <div class="rr-line">Wall: <strong><?= htmlspecialchars((string)($rowInfo['wall_formula'] ?? '')) ?></strong> = <strong><?= number_format((float)($rowInfo['wall_area'] ?? 0), 2) ?> ft²</strong></div>
                      <?php endif; ?>
                      <div class="rr-line">Row total: <strong><?= number_format((float)($rowInfo['total_area'] ?? 0), 2) ?> ft²</strong></div>
                    </div>
                    <?php endforeach; ?>
                  </div>
                  <?php endif; ?>

                  <?php if ($aImgsForArea): ?>
                  <!-- 4-column image grid -->
                  <div class="area-img-grid">
                    <?php foreach ($aImgsForArea as $aIdx => $img): ?>
                    <div class="area-img-item" onclick='openLb(<?= $aGalJs ?>,<?= $aIdx ?>)'>
                      <img src="<?= htmlspecialchars(imgUrl($img['image_filename'])) ?>"
                           alt="Area photo" loading="lazy"
                           onerror="this.onerror=null;this.style.display='none'">
                      <div class="area-img-overlay">
                        <span style="font-size:.6rem;color:rgba(255,255,255,.7)"><?= $aIdx+1 ?>/<?= count($aImgsForArea) ?></span>
                        <button class="viz-send-pill"
                                onclick="event.stopPropagation();sendSingleToViz('<?= addslashes(htmlspecialchars($a['area_type'],ENT_QUOTES)) ?>','<?= addslashes(imgUrl($img['image_filename'])) ?>',<?= $ownerJs ?>)"
                                title="Send to 3D Visualizer">⬡ 3D</button>
                      </div>
                    </div>
                    <?php endforeach; ?>
                  </div>
                  <?php else: ?>
                  <div style="font-size:.75rem;color:var(--c-gray-400);padding:8px 0;font-style:italic;text-align:center">
                    📷 No photos uploaded for this area yet
                  </div>
                  <?php endif; ?>
                </div>
              </div>

              <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if ($detail && ($detail['detail_description'] || !empty($detail['vendor_name']))): ?>
            <div class="sec-div">
              <div class="sec-lbl">Notes</div>
              <?php if (!empty($detail['vendor_name'])): ?>
              <div class="info-row"><span class="info-label">Vendor</span><span class="info-val"><?= htmlspecialchars($detail['vendor_name']) ?></span></div>
              <?php endif; ?>
              <?php if (!empty($detail['detail_description'])): ?>
              <div class="info-row"><span class="info-label">Notes</span><span class="info-val"><?= htmlspecialchars($detail['detail_description']) ?></span></div>
              <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- ══════════════════════════════════════════════════════
                 VISUALIZER OUTPUT SECTION
            ══════════════════════════════════════════════════════ -->
            <div class="sec-div">
              <div class="viz-output-section">
                <div class="viz-output-header">
                  <div class="viz-output-title">
                    <svg width="16" height="16" fill="none" stroke="#c4b5fd" stroke-width="2" viewBox="0 0 24 24">
                      <path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/>
                    </svg>
                    3D Visualizer Outputs
                    <span class="viz-count-badge" id="snapCountBadge"><?= count($vizSnapshots) ?> image<?= count($vizSnapshots)===1?'':'s' ?></span>
                  </div>
                </div>

                <!-- Saved visualizer images grid -->
                <?php if ($vizSnapshots): ?>
                <div class="viz-img-grid" id="vizSnapContainer">
                  <?php
                  /* Build full gallery for lightbox navigation */
                  $vizGal = [];
                  foreach ($vizSnapshots as $vsi)
                      $vizGal[] = ['url'=>imgUrl($vsi['filename']), 'cap'=>'3D Visualizer Output — '.date('d M Y H:i',strtotime($vsi['created_at']))];
                  $vizGalJs = json_encode($vizGal, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
                  ?>
                  <?php foreach ($vizSnapshots as $vsIdx => $vsi): ?>
                  <div class="viz-snap-item" id="vsi-<?= (int)$vsi['id'] ?>"
                       onclick='openLb(<?= $vizGalJs ?>,<?= $vsIdx ?>)'>
                    <img src="<?= htmlspecialchars(imgUrl($vsi['filename'])) ?>"
                         alt="Visualizer Output" loading="lazy"
                         onerror="this.onerror=null;this.style.opacity='.4'">
                    <div class="viz-snap-overlay">
                      <span class="viz-snap-date"><?= date('d M Y', strtotime($vsi['created_at'])) ?></span>
                      <form method="POST" onsubmit="return false" style="display:inline" id="delvf-<?= (int)$vsi['id'] ?>">
                        <input type="hidden" name="csrf"         value="<?= csrfToken() ?>">
                        <input type="hidden" name="action"       value="delete_viz_snapshot">
                        <input type="hidden" name="snap_id"      value="<?= (int)$vsi['id'] ?>">
                        <input type="hidden" name="lookup_phone" value="<?= htmlspecialchars($phoneLookup) ?>">
                        <button type="button" class="viz-snap-del"
                                onclick="event.stopPropagation();confirmDelViz(<?= (int)$vsi['id'] ?>)"
                                title="Delete this image">🗑</button>
                      </form>
                    </div>
                  </div>
                  <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div id="vizSnapContainer" style="margin-bottom:14px">
                  <p style="font-size:.8rem;color:#7c6fcd;text-align:center;padding:12px 0;margin:0">
                    No visualizer outputs saved yet. Upload your 3D renders below.
                  </p>
                </div>
                <?php endif; ?>

                <!-- Upload zone -->
                <form method="POST" action="subadmins.php?phone_lookup=<?= urlencode($phoneLookup) ?>"
                      enctype="multipart/form-data" id="vizSnapForm">
                  <input type="hidden" name="csrf"          value="<?= csrfToken() ?>">
                  <input type="hidden" name="action"        value="upload_viz_snapshot">
                  <input type="hidden" name="submission_id" value="<?= (int)$s['id'] ?>">
                  <input type="hidden" name="owner_name"    value="<?= htmlspecialchars($s['owner_name']) ?>">

                  <div class="viz-upload-zone" id="vizUploadZone">
                    <input type="file" id="vizSnapFile" name="snapshot_file[]"
                           accept="image/jpeg,image/png,image/webp"
                           multiple onchange="onVizSnapFilesChange(this)">
                    <div class="viz-upload-icon">🖼️</div>
                    <div class="viz-upload-label">
                      <strong>Click to select or drag &amp; drop</strong>
                      Upload 3D visualizer output images
                    </div>
                    <div class="viz-upload-hint">JPEG · PNG · WebP · Multiple files allowed</div>
                  </div>

                  <!-- Preview grid for selected files -->
                  <div class="viz-upload-previews" id="vizUploadPreviews" style="display:none"></div>

                  <button type="submit" class="viz-save-btn" id="vizSnapSaveBtn" disabled>
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                      <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                      <polyline points="17 21 17 13 7 13 7 21"/>
                      <polyline points="7 3 7 8 15 8"/>
                    </svg>
                    <span id="vizSnapSaveLbl">Save Visualizer Images</span>
                  </button>
                  <div class="viz-snap-status" id="vizSnapStatus"></div>
                </form>
              </div>
            </div>

            <div style="margin-top:10px;font-size:.72rem;color:var(--c-gray-400);line-height:1.7">
              Submitted by: <strong><?= htmlspecialchars($s['sub_name']) ?></strong> on <?= date('d M Y', strtotime($dispTime)) ?><br>
              <?php if (!empty($s['last_edited_at'])): ?>
                Edited by: <strong><?= htmlspecialchars($s['last_edited_by'] ?: 'Unknown user') ?></strong> on <?= date('d M Y, h:i A', strtotime($s['last_edited_at'])) ?>
              <?php else: ?>
                Edited by: <strong>Not yet edited</strong>
              <?php endif; ?>
            </div>
          </div>

          <div class="result-foot">
            <?php if (true): ?>
              <button type="button" class="btn btn-primary"
                      onclick='openEdit(<?= $sJs ?>,<?= $dJs ?>,<?= $areasJs ?>,<?= $aImgsJs ?>,<?= $vizSnapsJs ?>)'>
                ✏️ Update Status
              </button>
            <?php elseif ($unlockStatus === 'approved'): ?>
              <button type="button" class="btn btn-primary"
                      onclick='openEdit(<?= $sJs ?>,<?= $dJs ?>,<?= $areasJs ?>,<?= $aImgsJs ?>,<?= $vizSnapsJs ?>)'>
                🔓 Edit (Unlocked)
              </button>
            <?php elseif ($unlockStatus === 'pending'): ?>
              <button type="button" class="btn btn-ghost" disabled>⏳ Unlock Pending…</button>
            <?php elseif ($unlockStatus === 'rejected'): ?>
              <button type="button" class="btn btn-danger"
                      onclick='openUnlockModal(<?= (int)$s['id'] ?>,"<?= htmlspecialchars($s['owner_name'],ENT_QUOTES) ?>")'>
                ❌ Re-request Unlock
              </button>
            <?php else: ?>
              <button type="button" class="btn btn-ghost"
                      onclick='openUnlockModal(<?= (int)$s['id'] ?>,"<?= htmlspecialchars($s['owner_name'],ENT_QUOTES) ?>")'>
                🔒 Request Unlock
              </button>
            <?php endif; ?>

            <?php if ($vizTiles): ?>
              <?php if ($isBilled || $locked): ?>
              <button type="button" class="btn-visualizer-locked"
                      onclick='openVizPickerAll(<?= $vizTilesJs ?>,<?= $ownerJs ?>)'>
                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
                🔒 View in 3D Visualizer (<?= count($vizTiles) ?>)
              </button>
              <?php else: ?>
              <button type="button" class="btn-visualizer"
                      onclick='openVizPickerAll(<?= $vizTilesJs ?>,<?= $ownerJs ?>)'>
                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
                Open in 3D Visualizer (<?= count($vizTiles) ?> tiles)
              </button>
              <?php endif; ?>
            <?php else: ?>
              <button type="button" class="btn-visualizer" style="opacity:.5;cursor:not-allowed" disabled>
                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
                3D Visualizer (no tiles yet)
              </button>
            <?php endif; ?>

            <a href="#" class="btn-print" onclick="openPrintModal('<?= htmlspecialchars($printUrl) ?>'); return false;">
              <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <polyline points="6 9 6 2 18 2 18 9"/>
                <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                <rect x="6" y="14" width="12" height="8"/>
              </svg>
              Print A4
            </a>

            <?php if (!empty($s['latitude']) && !empty($s['longitude'])): ?>
            <a href="https://maps.google.com/?q=<?= urlencode($s['latitude'].','.$s['longitude']) ?>"
               target="_blank" rel="noopener" class="btn btn-ghost">🗺 Map</a>
            <?php endif; ?>
          </div>
        </div>

        <!-- ══════════════════════════════════════════════════════
             PRINT MODAL
        ══════════════════════════════════════════════════════ -->
        <div class="modal-overlay" id="printModalOverlay">
          <div class="modal" id="printModal" style="max-width:900px; width:95%; height:90vh; display:flex; flex-direction:column; padding:0;">
            <div class="modal-head" style="padding:12px 16px; border-bottom:1px solid #e5e7eb;">
              <h3 style="margin:0; font-size:1.1rem;">🖨️ Print Preview</h3>
              <button type="button" class="modal-close" onclick="closePrintModal()">×</button>
            </div>
            <div class="modal-body" style="flex:1; padding:0; background:#f3f4f6;">
              <iframe id="printIframe" src="" style="width:100%; height:100%; border:none;"></iframe>
            </div>
            <div class="modal-foot" style="padding:12px 16px; border-top:1px solid #e5e7eb; background:#f9fafb; display:flex; justify-content:flex-end; gap:8px;">
              <button type="button" class="btn btn-ghost" onclick="closePrintModal()">Close</button>
              <button type="button" class="btn btn-primary" onclick="document.getElementById('printIframe').contentWindow.print()">Print Document</button>
            </div>
          </div>
        </div>

        <script>
        function openPrintModal(url) {
          document.getElementById('printIframe').src = url;
          document.getElementById('printModalOverlay').classList.add('active');
          document.getElementById('printModal').classList.add('active');
        }
        function closePrintModal() {
          document.getElementById('printModalOverlay').classList.remove('active');
          document.getElementById('printModal').classList.remove('active');
          document.getElementById('printIframe').src = '';
        }
        </script>

        <script>
const _VIZ_URL = <?= $vizUrlJs ?>;
const _SUB_ID  = <?= (int)$s['id'] ?>;
const _LOOKUP_PHONE = <?= json_encode($phoneLookup) ?>;
        </script>

      <?php else: ?>
        <div class="new-cust-hint">
          <svg width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
            <circle cx="9" cy="7" r="4"/>
            <line x1="19" y1="8" x2="19" y2="14"/>
            <line x1="22" y1="11" x2="16" y2="11"/>
          </svg>
          <h3>No Record Found</h3>
          <p>No submission matches <strong><?= htmlspecialchars($phoneLookup) ?></strong> anywhere in the database.</p>
        </div>
        <?php if ($lookupError): ?>
        <div class="alert alert-warn" style="margin-bottom:16px"><?= htmlspecialchars($lookupError) ?></div>
        <?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($phoneLookup === ''): ?>
    <div style="text-align:center;padding:40px 20px;color:var(--c-gray-400)">
      <svg width="52" height="52" fill="none" stroke="currentColor" stroke-width="1.2" viewBox="0 0 24 24"
           style="margin-bottom:12px;opacity:.4">
        <path d="M22 16.92V19a2 2 0 0 1-2.18 2A19.86 19.86 0 0 1 3.07 5.18 2 2 0 0 1 5 3h2.08a2 2 0 0 1 2 1.72c.13 1 .37 1.97.72 2.9a2 2 0 0 1-.45 2.11L8.09 11a16 16 0 0 0 4.91 4.91l1.27-1.27a2 2 0 0 1 2.11-.45c.93.35 1.9.59 2.9.72A2 2 0 0 1 22 16.92z"/>
      </svg>
      <p style="font-size:.9rem;margin:0">Enter a phone number above to look up a walk-in customer.</p>
    </div>
    <?php endif; ?>

  </div>
</div>

<!-- ══ MODALS ════════════════════════════════════════════════════════════════ -->
<?php include __DIR__ . '/update_status_modal.php'; ?>

<nav class="mob-nav"><div class="mob-nav-items">
  <a href="dashboard.php" class="mob-nav-item">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
      <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
      <rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>
    </svg>Home
  </a>
  <a href="subadmins.php" class="mob-nav-item active">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
      <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>
    </svg>Walk-In
  </a>
  <a href="reports.php" class="mob-nav-item">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
      <line x1="18" y1="20" x2="18" y2="10"/>
      <line x1="12" y1="20" x2="12" y2="4"/>
      <line x1="6" y1="20" x2="6" y2="14"/>
    </svg>Reports
  </a>
</div></nav>

<?php pageFooter(); ?>

<script>
/* ── Upload URL constant for the modal ─────────────────────────────────── */
const _EMUPURL = <?= json_encode(rtrim(UPLOAD_URL, '/')) ?>;

/* ══ LIGHTBOX ═══════════════════════════════════════════════════════════════ */
let lbGal=[], lbIdx=0;
function openLb(gal,idx){
  lbGal=Array.isArray(gal)?gal:[];lbIdx=idx||0;
  renderLb();
  document.getElementById('lightbox').classList.add('open');
  document.body.style.overflow='hidden';
}
function renderLb(){
  const item=lbGal[lbIdx];if(!item)return;
  const img=document.getElementById('lightbox-img');
  img.src='';img.src=item.url;
  document.getElementById('lightbox-caption').textContent=item.cap||'';
  document.getElementById('lightbox-counter').textContent=
    lbGal.length>1?(lbIdx+1)+' / '+lbGal.length:'';
  document.getElementById('lb-prev').disabled=lbIdx===0;
  document.getElementById('lb-next').disabled=lbIdx===lbGal.length-1;
  document.getElementById('lightbox-nav').style.display=lbGal.length>1?'flex':'none';
}
function lbNav(dir){const n=lbIdx+dir;if(n>=0&&n<lbGal.length){lbIdx=n;renderLb();}}
function closeLb(){
  document.getElementById('lightbox').classList.remove('open');
  document.body.style.overflow='';
  lbGal=[];lbIdx=0;
}
document.addEventListener('keydown',e=>{
  if(!document.getElementById('lightbox').classList.contains('open'))return;
  if(e.key==='Escape')     closeLb();
  if(e.key==='ArrowRight') lbNav(1);
  if(e.key==='ArrowLeft')  lbNav(-1);
});

/* ══ DELETE AREA CONFIRM ════════════════════════════════════════════════════ */
let _delAreaId=0, _delSubId=0;
function confirmDelArea(areaId, subId, areaName){
  _delAreaId=areaId; _delSubId=subId;
  document.getElementById('delAreaSub').textContent=
    `"${areaName}" and all its photos will be permanently deleted. This cannot be undone.`;
  document.getElementById('delAreaOverlay').classList.add('open');
  document.body.style.overflow='hidden';
}
function closeDelAreaConfirm(){
  document.getElementById('delAreaOverlay').classList.remove('open');
  document.body.style.overflow='';
}
document.getElementById('delAreaConfirmBtn').addEventListener('click',function(){
  if(!_delAreaId||!_delSubId) return;
  const form=document.createElement('form');
  form.method='POST';form.action='subadmins.php?phone_lookup='+encodeURIComponent(typeof _LOOKUP_PHONE!=='undefined'?_LOOKUP_PHONE:'');
  const fields={
    csrf: document.querySelector('#editStatusForm [name="csrf"]')?.value||'',
    action:'delete_area',
    area_id:_delAreaId,
    submission_id:_delSubId,
    lookup_phone: typeof _LOOKUP_PHONE!=='undefined'?_LOOKUP_PHONE:''
  };
  for(const [k,v] of Object.entries(fields)){
    const inp=document.createElement('input');inp.type='hidden';inp.name=k;inp.value=v;form.appendChild(inp);
  }
  document.body.appendChild(form);form.submit();
});

/* ══ DELETE VIZ IMAGE CONFIRM ════════════════════════════════════════════════ */
function confirmDelViz(snapId){
  if(!confirm('Delete this visualizer image? This cannot be undone.')) return;
  document.getElementById('delvf-'+snapId).submit();
}
/* Make the delete viz forms actually submit via POST */
document.querySelectorAll('[id^="delvf-"]').forEach(f=>{
  f.removeAttribute('onsubmit');f.setAttribute('method','POST');
});

/* ══ VISUALIZER OUTPUT UPLOAD ════════════════════════════════════════════════ */
let _vizPendingFiles = [];

function onVizSnapFilesChange(input){
  const files=Array.from(input.files||[]);
  _vizPendingFiles=[];
  files.forEach(f=>{
    if(!['image/jpeg','image/png','image/webp'].includes(f.type)) return;
    _vizPendingFiles.push(f);
  });
  renderVizPreviews();
}

function renderVizPreviews(){
  const wrap=document.getElementById('vizUploadPreviews');
  const btn=document.getElementById('vizSnapSaveBtn');
  const lbl=document.getElementById('vizSnapSaveLbl');
  wrap.innerHTML='';
  if(!_vizPendingFiles.length){
    wrap.style.display='none';
    btn.disabled=true;
    lbl.textContent='Save Visualizer Images';
    return;
  }
  wrap.style.display='grid';
  btn.disabled=false;
  lbl.textContent=`Save ${_vizPendingFiles.length} Image${_vizPendingFiles.length>1?'s':''}`;
  _vizPendingFiles.forEach((f,i)=>{
    const reader=new FileReader();
    reader.onload=e=>{
      const div=document.createElement('div');div.className='viz-upload-preview-item';
      div.innerHTML=`<img src="${e.target.result}" alt="preview">
        <button type="button" class="viz-upload-preview-del" onclick="removeVizPreview(${i})">✕</button>`;
      wrap.appendChild(div);
    };
    reader.readAsDataURL(f);
  });
}

function removeVizPreview(idx){
  _vizPendingFiles.splice(idx,1);
  /* Rebuild the file input */
  try{
    const dt=new DataTransfer();
    _vizPendingFiles.forEach(f=>dt.items.add(f));
    document.getElementById('vizSnapFile').files=dt.files;
  }catch(e){}
  renderVizPreviews();
}

/* Drag & drop */
document.addEventListener('DOMContentLoaded',()=>{
  const zone=document.getElementById('vizUploadZone');
  if(zone){
    zone.addEventListener('dragover',e=>{e.preventDefault();zone.classList.add('drag-over');});
    zone.addEventListener('dragleave',()=>zone.classList.remove('drag-over'));
    zone.addEventListener('drop',e=>{
      e.preventDefault();zone.classList.remove('drag-over');
      const dropped=Array.from(e.dataTransfer.files).filter(f=>['image/jpeg','image/png','image/webp'].includes(f.type));
      if(!dropped.length) return;
      try{
        const dt=new DataTransfer();
        dropped.forEach(f=>dt.items.add(f));
        const inp=document.getElementById('vizSnapFile');
        inp.files=dt.files;onVizSnapFilesChange(inp);
      }catch(er){}
    });
  }

  const vizForm=document.getElementById('vizSnapForm');
  if(vizForm){
    vizForm.addEventListener('submit',e=>{
      const fileInput=document.getElementById('vizSnapFile');
      const hasFiles=fileInput&&fileInput.files&&fileInput.files.length>0;
      if(!hasFiles){
        e.preventDefault();
        document.getElementById('vizSnapStatus').textContent='⚠️ Please select at least one image first.';
        return;
      }
      const btn=document.getElementById('vizSnapSaveBtn');
      const lbl=document.getElementById('vizSnapSaveLbl');
      if(btn){btn.disabled=true;}
      if(lbl){lbl.textContent='⏳ Uploading…';}
      document.getElementById('vizSnapStatus').textContent='Saving images…';
    });
  }
});

/* ══ VISUALIZER INTEGRATION ═════════════════════════════════════════════════ */
let _vizAllTiles=[],_vizSelItems=[],_vizOwnerName='';

function openVizPickerAll(tiles,ownerName){
  _vizAllTiles=tiles||[];_vizSelItems=[];_vizOwnerName=ownerName||'';
  renderVizPicker(_vizAllTiles);
  document.getElementById('vizPickerPanel').classList.add('open');
}
function openVizPickerForArea(tiles,ownerName,areaLabel){
  _vizAllTiles=tiles||[];_vizSelItems=[];_vizOwnerName=ownerName||areaLabel||'';
  renderVizPicker(_vizAllTiles,areaLabel);
  document.getElementById('vizPickerPanel').classList.add('open');
}
function sendSingleToViz(areaName,imgUrl,ownerName){
  storeAndOpenViz([{name:areaName,url:imgUrl,area:areaName}],ownerName||areaName);
}
function renderVizPicker(tiles,areaLabel){
  const grid=document.getElementById('vppGrid');
  const title=document.querySelector('.vpp-title span');
  if(title) title.textContent=areaLabel?`🏠 ${areaLabel} — 3D Visualizer`:'🏠 Open in 3D Visualizer';
  grid.innerHTML='';
  if(!tiles.length){
    grid.innerHTML='<p style="font-size:.78rem;color:#7c6fcd;padding:8px">No tile images found.</p>';
    return;
  }
  tiles.forEach((t,i)=>{
    const div=document.createElement('div');div.className='vpp-item';
    div.innerHTML=`<img src="${escHtml(t.url)}" alt="${escHtml(t.name)}" loading="lazy" onerror="this.style.opacity='.3'"><div class="vpp-name">${escHtml(t.name)}</div>`;
    div.addEventListener('click',()=>{
      div.classList.toggle('selected');
      if(div.classList.contains('selected')) _vizSelItems.push(i);
      else _vizSelItems=_vizSelItems.filter(x=>x!==i);
    });
    grid.appendChild(div);
  });
}
function closeVizPicker(){
  document.getElementById('vizPickerPanel').classList.remove('open');
  _vizSelItems=[];_vizAllTiles=[];_vizOwnerName='';
}
function launchVisualizer(){
  const toSend=_vizSelItems.length>0?_vizSelItems.map(i=>_vizAllTiles[i]):_vizAllTiles;
  if(!toSend.length){alert('No tile images available.');return;}
  storeAndOpenViz(toSend,_vizOwnerName);
}
function storeAndOpenViz(tiles,ownerName){
  try{
    localStorage.setItem('ae_viz_tiles',JSON.stringify(tiles));
    localStorage.setItem('ae_viz_owner',ownerName||'');
  }catch(e){}
  const vizUrl=(typeof _VIZ_URL!=='undefined'&&_VIZ_URL)?_VIZ_URL:'../visualizer/index.php';
  const subId=(typeof _SUB_ID!=='undefined'&&_SUB_ID)?_SUB_ID:0;
  window.location.href = vizUrl+'?from_sub=1&submission_id='+subId;
  closeVizPicker();
}

/* Listen for images posted back from the visualizer window */
window.addEventListener('message',e=>{
  if(!e.data||e.data.type!=='viz_snip_saved')return;
  const container=document.getElementById('vizSnapContainer');
  if(container&&e.data.url){
    const div=document.createElement('div');div.className='viz-snap-item';
    div.style.cursor='pointer';
    div.innerHTML=`
      <img src="${escHtml(e.data.url)}" alt="Visualizer Output" style="width:100%;aspect-ratio:16/9;object-fit:cover;display:block"
           onclick='openLb([{url:"${escHtml(e.data.url)}",cap:"3D Visualizer Output — just saved"}],0)'>
      <div class="viz-snap-overlay">
        <span class="viz-snap-date">Just now</span>
      </div>`;
    container.prepend(div);
    const badge=document.getElementById('snapCountBadge');
    if(badge){
      const n=(parseInt(badge.textContent)||0)+1;
      badge.textContent=n+' image'+(n===1?'':'s');
    }
  }
});

/* Modal helpers */
function openModal(id){
  const el=document.getElementById(id);if(el) el.style.display='flex';
  document.body.style.overflow='hidden';
}
function closeModal(id){
  const el=document.getElementById(id);if(el) el.style.display='none';
  document.body.style.overflow='';
}

function escHtml(s){
  return String(s||'')
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}
</script>
