<?php
header('Permissions-Policy: geolocation=(self)');
header('Feature-Policy: geolocation \'self\'');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
requireLogin('sub_admin');
$db   = getDB();
$user = currentUser();
$msg  = ''; $msgType = 'success';
$dupWarning = null;
$submittedTimeDisplay = null;
$effectiveTime = null;

$zones = $db->prepare("SELECT sd.*, d.code as dir_code FROM sub_admin_locations sal JOIN sub_directions sd ON sal.sub_direction_id = sd.id JOIN directions d ON sd.direction_id = d.id WHERE sal.sub_admin_id = ?");
$zones->execute([$user['id']]);
$zones = $zones->fetchAll();

$db->exec("CREATE TABLE IF NOT EXISTS subadmin_gps_captures (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sub_admin_id INT UNSIGNED NOT NULL,
    latitude DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(10,7) NOT NULL,
    submission_id INT UNSIGNED NULL,
    accuracy_m DECIMAL(10,2) NULL,
    source VARCHAR(20) NOT NULL DEFAULT 'auto',
    user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    used_at DATETIME NULL,
    KEY idx_gps_user_created (sub_admin_id, created_at),
    KEY idx_gps_submission (submission_id),
    CONSTRAINT fk_gps_capture_user
        FOREIGN KEY (sub_admin_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$gpsSubmissionColumn = $db->query("SHOW COLUMNS FROM subadmin_gps_captures LIKE 'submission_id'")->fetch();
if (!$gpsSubmissionColumn) {
    $db->exec("ALTER TABLE subadmin_gps_captures ADD COLUMN submission_id INT UNSIGNED NULL AFTER longitude");
    $db->exec("ALTER TABLE subadmin_gps_captures ADD KEY idx_gps_submission (submission_id)");
}

function normalizeCoordinate(?string $value, float $min, float $max): ?string {
    $value = trim((string)$value);
    if ($value === '' || !is_numeric($value)) return null;
    $num = (float)$value;
    if ($num < $min || $num > $max) return null;
    return number_format($num, 7, '.', '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $sdId          = (int)$_POST['sub_direction_id'];
    $owner         = trim($_POST['owner_name']);
    $ownerN        = trim($_POST['owner_number']);
    $rep           = trim($_POST['representative_name']);
    $repN          = trim($_POST['representative_number']);
    $village       = trim($_POST['village_city_town']);
    $street        = trim($_POST['street']);
    $status        = $_POST['status'];
    $lat = normalizeCoordinate($_POST['latitude'] ?? null, -90, 90);
    $lng = normalizeCoordinate($_POST['longitude'] ?? null, -180, 180);
    $gpsCaptureId = (int)($_POST['gps_capture_id'] ?? 0);
    $force = (int)($_POST['force_submit'] ?? 0);

    // ── GPS mandatory server-side check ──
    // Use strict checks so values like "0" are accepted if valid coordinates
    if ($lat === null || $lng === null || $gpsCaptureId <= 0) {
        $msg = 'GPS location is required. Please fetch and save your location before submitting.';
        $msgType = 'error';
        goto render_page;
    }

    $gpsStmt = $db->prepare("SELECT id, latitude, longitude
        FROM subadmin_gps_captures
        WHERE id = ? AND sub_admin_id = ? AND created_at >= (NOW() - INTERVAL 12 HOUR)
        LIMIT 1");
    $gpsStmt->execute([$gpsCaptureId, $user['id']]);
    $gpsCapture = $gpsStmt->fetch();
    if (!$gpsCapture ||
        number_format((float)$gpsCapture['latitude'], 7, '.', '') !== $lat ||
        number_format((float)$gpsCapture['longitude'], 7, '.', '') !== $lng) {
        $msg = 'Saved GPS location could not be verified. Please fetch GPS again.';
        $msgType = 'error';
        goto render_page;
    }

    // ── Next Visit Date ──
    $nextVisitDate = trim($_POST['next_visit_date'] ?? '');
    if ($nextVisitDate === '') {
        $msg = 'Date of Next Visit is required. Please select a visit date before submitting.';
        $msgType = 'error';
        goto render_page;
    }
    $nextVisitDate = ($nextVisitDate !== '') ? $nextVisitDate : null;

    // ── Unlock time ──
    $rawUnlockTime = trim($_POST['unlock_time_iso'] ?? '');
    $unlockTimeIso = $rawUnlockTime ?: null;

    // ── Device submit time ──
    $rawDeviceTime     = trim($_POST['device_submitted_at'] ?? '');
    $deviceSubmittedAt = null;
    if ($rawDeviceTime) {
        $dt = DateTime::createFromFormat('Y-m-d\TH:i:s', $rawDeviceTime);
        if (!$dt) $dt = DateTime::createFromFormat('Y-m-d\TH:i', $rawDeviceTime);
        if ($dt) {
            $deviceSubmittedAt    = $dt->format('Y-m-d H:i:s');
            $submittedTimeDisplay = $dt->format('d M Y, h:i:s A');
        }
    }

    // ── Effective time ──
    if ($rawUnlockTime && $rawDeviceTime) {
        $dtUnlock = DateTime::createFromFormat('Y-m-d\TH:i:s', $rawUnlockTime);
        if (!$dtUnlock) $dtUnlock = DateTime::createFromFormat('Y-m-d\TH:i', $rawUnlockTime);
        $dtSubmit = DateTime::createFromFormat('Y-m-d\TH:i:s', $rawDeviceTime);
        if (!$dtSubmit) $dtSubmit = DateTime::createFromFormat('Y-m-d\TH:i', $rawDeviceTime);
        if ($dtUnlock && $dtSubmit) {
            $diffSecs = $dtSubmit->getTimestamp() - $dtUnlock->getTimestamp();
            if ($diffSecs >= 0) {
                $effH = floor($diffSecs / 3600);
                $effM = floor(($diffSecs % 3600) / 60);
                $effS = $diffSecs % 60;
                if ($effH > 0) $effectiveTime = "{$effH}h {$effM}m {$effS}s";
                elseif ($effM > 0) $effectiveTime = "{$effM}m {$effS}s";
                else $effectiveTime = "{$effS}s";
            }
        }
    }

    $zoneIds = array_column($zones, 'id');
    if (!in_array($sdId, $zoneIds)) {
        $msg = 'Invalid zone selected.'; $msgType = 'error';
    } else {
        if (!$force) {
            $dupStmt = $db->prepare("SELECT s.id, s.owner_name, s.village_city_town, s.street, s.submitted_at, s.device_submitted_at, u.username, sd.code as zone_code FROM submissions s JOIN users u ON s.submitted_by = u.id JOIN sub_directions sd ON s.sub_direction_id = sd.id WHERE s.owner_number = ? LIMIT 1");
            $dupStmt->execute([$ownerN]);
            $dup = $dupStmt->fetch();
            if ($dup) { $dupWarning = $dup; goto render_page; }
        }

        function saveWebcamImage(string $b64, string $prefix): ?string {
            $data    = preg_replace('/^data:image\/\w+;base64,/', '', $b64);
            $decoded = base64_decode($data, true);
            if (!$decoded) return null;
            $fname = $prefix . uniqid() . '.jpg';
            file_put_contents(UPLOAD_DIR . $fname, $decoded);
            return $fname;
        }

        $landmarkFile = null;
        $webcamLandmark = trim($_POST['webcam_landmark'] ?? '');
        if ($webcamLandmark && str_starts_with($webcamLandmark, 'data:image')) {
            $landmarkFile = saveWebcamImage($webcamLandmark, 'wc_lm_');
        } elseif (!empty($_FILES['landmark_picture']['name'])) {
            $ext = strtolower(pathinfo($_FILES['landmark_picture']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','webp'])) {
                $fname = uniqid('lm_') . '.' . $ext;
                move_uploaded_file($_FILES['landmark_picture']['tmp_name'], UPLOAD_DIR . $fname);
                $landmarkFile = $fname;
            }
        } elseif (!empty($_FILES['landmark_picture_cam']['name'])) {
            $ext = strtolower(pathinfo($_FILES['landmark_picture_cam']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','webp'])) {
                $fname = uniqid('lmc_') . '.' . $ext;
                move_uploaded_file($_FILES['landmark_picture_cam']['tmp_name'], UPLOAD_DIR . $fname);
                $landmarkFile = $fname;
            }
        }

        $additionalFile = null;
        $webcamAdditional = trim($_POST['webcam_additional'] ?? '');
        if ($webcamAdditional && str_starts_with($webcamAdditional, 'data:image')) {
            $additionalFile = saveWebcamImage($webcamAdditional, 'wc_ap_');
        } elseif (!empty($_FILES['additional_picture']['name'])) {
            $ext = strtolower(pathinfo($_FILES['additional_picture']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','webp'])) {
                $fname = uniqid('ap_') . '.' . $ext;
                move_uploaded_file($_FILES['additional_picture']['tmp_name'], UPLOAD_DIR . $fname);
                $additionalFile = $fname;
            }
        } elseif (!empty($_FILES['additional_picture_cam']['name'])) {
            $ext = strtolower(pathinfo($_FILES['additional_picture_cam']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','webp'])) {
                $fname = uniqid('apc_') . '.' . $ext;
                move_uploaded_file($_FILES['additional_picture_cam']['tmp_name'], UPLOAD_DIR . $fname);
                $additionalFile = $fname;
            }
        }

        $tileFile = null;
        $webcamTile = trim($_POST['webcam_tile'] ?? '');
        if ($webcamTile && str_starts_with($webcamTile, 'data:image')) {
            $tileFile = saveWebcamImage($webcamTile, 'tile_');
        } elseif (!empty($_FILES['tile_image']['name'])) {
            $ext = strtolower(pathinfo($_FILES['tile_image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','webp'])) {
                $fname = uniqid('tile_') . '.' . $ext;
                move_uploaded_file($_FILES['tile_image']['tmp_name'], UPLOAD_DIR . $fname);
                $tileFile = $fname;
            }
        }

        $db->prepare("INSERT INTO submissions
            (submitted_by, sub_direction_id, owner_name, owner_number, representative_name,
             representative_number, village_city_town, street, landmark_picture,
             additional_picture, tile_image, status, next_visit_date, latitude, longitude,
             device_submitted_at, unlock_time_iso)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([
               $user['id'], $sdId, $owner, $ownerN, $rep, $repN,
               $village, $street, $landmarkFile, $additionalFile, $tileFile,
               $status, $nextVisitDate, $lat, $lng,
               $deviceSubmittedAt, $unlockTimeIso
           ]);
        $submissionId = $db->lastInsertId();
        $db->prepare("UPDATE subadmin_gps_captures SET submission_id = ?, used_at = NOW() WHERE id = ? AND sub_admin_id = ?")
           ->execute([$submissionId, $gpsCaptureId, $user['id']]);

        $areaTypes    = $_POST['area_type']    ?? [];
        $areaLengths  = $_POST['area_length']  ?? [];
        $areaBreadths = $_POST['area_breadth'] ?? [];
        $areaSqfts    = $_POST['area_sqft']    ?? [];
        $areaCounts   = $_POST['area_count']   ?? [];

        $areaStmt = $db->prepare("INSERT INTO submission_areas
            (submission_id, area_type, area_length, area_breadth, area_sqft, total_sqft, area_count, sort_order)
            VALUES (?,?,?,?,?,?,?,?)");

        foreach ($areaTypes as $idx => $aType) {
            $aType = trim($aType);
            if ($aType === '') continue;
            $aLen     = ($areaLengths[$idx]  ?? '') !== '' ? (float)$areaLengths[$idx]  : null;
            $aBreadth = ($areaBreadths[$idx] ?? '') !== '' ? (float)$areaBreadths[$idx] : null;
            $aSqft    = ($areaSqfts[$idx]    ?? '') !== '' ? (float)$areaSqfts[$idx]    : null;
            $aCount   = ($areaCounts[$idx]   ?? '') !== '' ? (int)$areaCounts[$idx]     : null;
            $totalSqft = null;
            if ($aLen !== null && $aBreadth !== null) $totalSqft = round($aLen * $aBreadth, 2);
            elseif ($aSqft !== null) $totalSqft = $aSqft;
            $areaStmt->execute([$submissionId, $aType, $aLen, $aBreadth, $aSqft, $totalSqft, $aCount, $idx]);
        }

        logActivity($user['id'], 'submit_data', "Submitted data for owner: $owner");
        $msg = 'submission_success';
        $_POST = [
            'representative_name'   => $rep,
            'representative_number' => $repN,
            'next_visit_date'       => '',
        ];
    }
}

render_page:
pageHead('New Submission');
topbar($user['username'], $user['role']);

$todayISO    = date('Y-m-d');
$tomorrowISO = date('Y-m-d', strtotime('+1 day'));
$nextWeekISO = date('Y-m-d', strtotime('+7 days'));
?>
<style>
/* ── Layout ── */
.view-toggle{display:flex;gap:6px}
.view-toggle button{display:flex;align-items:center;gap:5px;padding:5px 11px;border:1.5px solid var(--c-gray-200);border-radius:6px;background:none;cursor:pointer;font-size:.78rem;color:var(--c-gray-500);transition:all .15s}
.view-toggle button.active{border-color:var(--c-primary);background:var(--c-primary-light,#eff6ff);color:var(--c-primary)}
.form-section{background:var(--c-gray-50,#f9fafb);border:1.5px solid var(--c-gray-100,#f0f0f0);border-radius:10px;padding:18px;margin-bottom:16px}
.form-section .sec-title{font-size:.7rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--c-gray-400);margin-bottom:14px}
.cust-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:10px}
.field-card{background:#fff;border:1px solid var(--c-gray-200);border-radius:8px;padding:11px 13px;transition:border-color .15s}
.field-card:focus-within{border-color:var(--c-primary)}
.field-card.field-error{border-color:#dc2626 !important;background:#fff5f5}
.field-card .fc-label{font-size:.7rem;color:var(--c-gray-400);font-weight:600;margin-bottom:4px}
.field-card input,.field-card select{width:100%;border:none;outline:none;font-size:.88rem;background:transparent;color:var(--c-gray-800);padding:0}
.field-card input::placeholder{color:var(--c-gray-300)}
.cust-list .field-row{display:flex;align-items:center;gap:12px;padding:9px 0;border-bottom:1px solid var(--c-gray-100)}
.cust-list .field-row:last-child{border-bottom:none}
.cust-list .fl-label{width:155px;flex-shrink:0;font-size:.79rem;font-weight:600;color:var(--c-gray-500)}
.cust-list input,.cust-list select{flex:1;border:none;border-bottom:1.5px solid var(--c-gray-200);outline:none;font-size:.87rem;background:transparent;padding:2px 0;color:var(--c-gray-800);transition:border-color .15s}
.cust-list input:focus,.cust-list select:focus{border-bottom-color:var(--c-primary)}
.cust-list input.input-error{border-bottom-color:#dc2626 !important}

/* ── Clock bar ── */
.clock-bar{background:linear-gradient(135deg,#1e3a8a,#2563eb);color:#fff;border-radius:10px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px}
.clock-main{display:flex;align-items:center;gap:12px}
.clock-icon{font-size:1.5rem;line-height:1}
.clock-time{font-size:1.6rem;font-weight:800;letter-spacing:.03em;font-family:monospace;color:#fff}
.clock-date{font-size:.78rem;color:#93c5fd;margin-top:2px}
.clock-label{font-size:.68rem;text-transform:uppercase;letter-spacing:.1em;color:#bfdbfe;font-weight:600}

/* ── LOCK OVERLAY ── */
.lock-overlay{position:fixed;inset:0;background:rgba(15,23,42,.75);z-index:9998;display:flex;align-items:center;justify-content:center;padding:16px}
.lock-box{background:#fff;border-radius:16px;padding:28px 32px;max-width:400px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.35);position:relative}
.lock-close-btn{position:absolute;top:14px;right:14px;width:32px;height:32px;border:1.5px solid var(--c-gray-200);border-radius:50%;background:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--c-gray-400);font-size:1rem;line-height:1;transition:all .15s;font-family:inherit;text-decoration:none}
.lock-close-btn:hover{border-color:#dc2626;color:#dc2626;background:#fff5f5}
.lock-title{font-size:1.1rem;font-weight:700;color:var(--c-gray-900);margin-bottom:4px;text-align:center}
.lock-sub{font-size:.82rem;color:var(--c-gray-500);text-align:center;margin-bottom:20px}
.lock-icon{font-size:2.8rem;text-align:center;margin-bottom:10px}
.lock-field{margin-bottom:14px}
.lock-field label{font-size:.78rem;font-weight:600;color:var(--c-gray-600);display:block;margin-bottom:5px}
.lock-field input{width:100%;padding:9px 13px;border:1.5px solid var(--c-gray-200);border-radius:8px;font-size:.9rem;outline:none;transition:border-color .15s;font-family:inherit;box-sizing:border-box}
.lock-field input:focus{border-color:var(--c-primary)}
.lock-error{color:#dc2626;font-size:.78rem;margin-top:4px;display:none}
.lock-btn{width:100%;padding:11px;background:var(--c-primary);color:#fff;border:none;border-radius:8px;font-size:.93rem;font-weight:600;cursor:pointer;font-family:inherit;transition:background .15s}
.lock-btn:hover{background:#1d4ed8}
.lock-btn:disabled{background:var(--c-gray-300);cursor:not-allowed}
.lock-nav-row{display:flex;gap:8px;margin-top:14px}
.lock-nav-btn{flex:1;padding:9px 10px;border:1.5px solid var(--c-gray-200);border-radius:8px;background:#fff;cursor:pointer;font-size:.82rem;font-weight:600;color:var(--c-gray-600);font-family:inherit;display:flex;align-items:center;justify-content:center;gap:6px;transition:all .15s;text-decoration:none}
.lock-nav-btn:hover{border-color:var(--c-primary);color:var(--c-primary);background:var(--c-primary-light,#eff6ff)}

/* ── Effective time badge ── */
.eff-time-bar{background:linear-gradient(135deg,#f0fdf4,#ecfdf5);border:2px solid #4ade80;border-radius:10px;padding:12px 16px;margin-bottom:14px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px}
.eff-time-val{font-size:1.3rem;font-weight:800;font-family:monospace;color:#15803d}
.eff-time-label{font-size:.72rem;text-transform:uppercase;letter-spacing:.07em;color:#4ade80;font-weight:700}
.eff-live{font-size:.88rem;font-family:monospace;color:#166534;background:#dcfce7;border-radius:6px;padding:4px 10px;font-weight:600}

/* ── Success card ── */
.success-card{background:linear-gradient(135deg,#f0fdf4,#ecfdf5);border:2px solid #4ade80;border-radius:12px;padding:20px;margin-bottom:16px}
.success-card .sc-header{display:flex;align-items:center;gap:10px;margin-bottom:12px}
.success-card .sc-icon{font-size:2rem;line-height:1}
.success-card .sc-title{font-size:1rem;font-weight:700;color:#15803d}
.success-card .sc-sub{font-size:.82rem;color:#16a34a}
.success-time-box{background:#fff;border:1.5px solid #86efac;border-radius:8px;padding:12px 16px;margin-top:10px}
.success-time-box .stb-label{font-size:.7rem;text-transform:uppercase;letter-spacing:.08em;font-weight:700;color:#4ade80;margin-bottom:4px}
.success-time-box .stb-time{font-size:1.1rem;font-weight:800;color:#15803d;font-family:monospace}
.success-time-box .stb-note{font-size:.72rem;color:#86efac;margin-top:2px}
.success-actions{display:flex;gap:10px;margin-top:14px;flex-wrap:wrap}
.success-actions a,.success-actions button{flex:1;min-width:130px;padding:10px 14px;border-radius:8px;font-size:.85rem;font-weight:600;cursor:pointer;font-family:inherit;display:flex;align-items:center;justify-content:center;gap:6px;text-decoration:none;transition:all .15s;border:none}
.btn-new-form{background:var(--c-primary);color:#fff}
.btn-new-form:hover{background:#1d4ed8}
.btn-dashboard{background:#fff;color:var(--c-gray-700);border:1.5px solid var(--c-gray-200) !important}
.btn-dashboard:hover{border-color:var(--c-primary) !important;color:var(--c-primary)}

/* ── Area rows ── */
.area-rows{display:flex;flex-direction:column;gap:12px}
.area-row-item{background:#fff;border:1px solid var(--c-gray-200);border-radius:10px;padding:14px 16px;position:relative}
.area-row-item.area-has-error{border-color:#dc2626;background:#fff9f9}
.area-row-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
.area-row-num{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--c-primary);background:var(--c-primary-light,#eff6ff);padding:2px 8px;border-radius:4px}
.remove-area{padding:4px 8px;border:1px solid var(--c-gray-200);border-radius:6px;background:none;cursor:pointer;color:var(--c-gray-400);font-size:.78rem;transition:all .15s}
.remove-area:hover{border-color:var(--c-danger);color:var(--c-danger);background:#fff5f5}
.area-fields{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px}
.area-field-wrap{position:relative}
.area-field-wrap .req-star{color:#dc2626;font-size:.7rem;margin-left:2px}
.area-field-err{font-size:.68rem;color:#dc2626;margin-top:3px;display:none}
.area-field-wrap input.inp-err,.area-field-wrap select.inp-err{border-color:#dc2626 !important;background:#fff5f5}
.area-calc-box{background:linear-gradient(135deg,#f0fdf4,#fffbeb);border:1.5px solid #bbf7d0;border-radius:8px;padding:10px 14px;margin-top:10px;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.calc-result{font-size:1.2rem;font-weight:800;color:#16a34a;font-family:monospace}
.calc-label{font-size:.72rem;color:#15803d;font-weight:600}
.calc-formula{font-size:.76rem;color:#4ade80}
.add-area-btn{display:flex;align-items:center;justify-content:center;gap:6px;padding:10px 14px;border:1.5px dashed var(--c-gray-300);border-radius:8px;background:none;cursor:pointer;font-size:.82rem;color:var(--c-gray-500);margin-top:6px;transition:all .15s;width:100%}
.add-area-btn:hover{border-color:var(--c-primary);color:var(--c-primary);background:var(--c-primary-light,#eff6ff)}

/* ── GPS ── */
.gps-section{border-radius:10px;padding:14px 16px;margin-bottom:4px;border:2px solid var(--c-gray-200);background:var(--c-gray-50,#f9fafb);transition:border-color .2s,background .2s}
.gps-section.gps-ok{border-color:#4ade80;background:#f0fdf4}
.gps-section.gps-error{border-color:#dc2626;background:#fff5f5}
.gps-req-badge{display:inline-flex;align-items:center;gap:4px;font-size:.68rem;font-weight:700;color:#dc2626;background:#fee2e2;border:1px solid #fecaca;border-radius:4px;padding:2px 7px;margin-left:8px;vertical-align:middle}
.coord-box{background:#fff;border:1px solid var(--c-gray-200);border-radius:7px;padding:8px 12px;font-size:.82rem;font-family:monospace;color:var(--c-gray-700);flex:1;min-width:200px}
.coord-box a{color:var(--c-primary);text-decoration:none}
.gps-err-msg{font-size:.78rem;color:#dc2626;margin-top:6px;display:none;font-weight:600}

/* ── Dup banner ── */
.dup-banner{background:#fffbeb;border:1.5px solid #f59e0b;border-radius:10px;padding:16px;margin-bottom:16px}
.dup-banner .dup-title{font-weight:700;color:#92400e;font-size:.9rem;margin-bottom:6px;display:flex;align-items:center;gap:6px}
.dup-banner .dup-meta{font-size:.82rem;color:#78350f;margin-bottom:10px;line-height:1.6}
.dup-banner .dup-meta span{background:#fef3c7;border-radius:4px;padding:1px 6px}

/* ── Camera ── */
.cam-tabs{display:flex;gap:0;margin-bottom:10px}
.cam-tab{padding:5px 14px;border:1px solid var(--c-gray-200);background:#fff;cursor:pointer;font-size:.78rem;color:var(--c-gray-500);transition:all .12s}
.cam-tab:first-child{border-radius:6px 0 0 6px;border-right:none}
.cam-tab:last-child{border-radius:0 6px 6px 0}
.cam-tab.active{background:var(--c-primary);color:#fff;border-color:var(--c-primary)}
.cam-box{background:#000;border-radius:8px;overflow:hidden;max-width:340px}
.cam-box video{width:100%;display:block;max-height:220px;object-fit:cover}
.cam-preview{max-width:100%;border-radius:8px;margin-top:8px;display:none}
.cam-actions{display:flex;gap:8px;margin-top:8px;flex-wrap:wrap}
.submit-row{display:flex;align-items:center;gap:12px;justify-content:flex-end;margin-top:16px;flex-wrap:wrap}

/* ── Next Visit Date ── */
.visit-date-section{background:linear-gradient(135deg,#f0f9ff,#e0f2fe);border:1.5px solid #7dd3fc;border-radius:10px;padding:16px 18px;margin-bottom:16px}
.visit-date-section .vd-title{font-size:.7rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#0369a1;margin-bottom:12px;display:flex;align-items:center;gap:6px}
.visit-date-input{width:100%;padding:10px 14px;border:1.5px solid #7dd3fc;border-radius:8px;font-size:.92rem;color:var(--c-gray-800);background:#fff;outline:none;transition:border-color .15s;font-family:inherit;box-sizing:border-box}
.visit-date-input:focus{border-color:#0284c7;box-shadow:0 0 0 3px rgba(14,165,233,.12)}
.visit-shortcuts{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}
.visit-shortcut{padding:4px 10px;border:1px solid #7dd3fc;border-radius:20px;background:#fff;cursor:pointer;font-size:.75rem;color:#0369a1;font-weight:600;transition:all .12s;font-family:inherit}
.visit-shortcut:hover{background:#0284c7;color:#fff;border-color:#0284c7}
.visit-date-preview{margin-top:8px;font-size:.8rem;color:#0369a1;font-weight:600;min-height:20px}
.visit-days-badge{display:inline-block;background:#0284c7;color:#fff;font-size:.72rem;padding:2px 8px;border-radius:12px;font-weight:700;margin-left:6px}

/* ── Validation toast ── */
#validationToast{position:fixed;top:20px;left:50%;transform:translateX(-50%) translateY(-80px);background:#dc2626;color:#fff;padding:12px 24px;border-radius:10px;font-size:.88rem;font-weight:600;box-shadow:0 8px 28px rgba(0,0,0,.2);z-index:9999;transition:transform .35s cubic-bezier(.34,1.56,.64,1),opacity .3s;opacity:0;pointer-events:none;white-space:nowrap;max-width:90vw;text-align:center}
#validationToast.show{transform:translateX(-50%) translateY(0);opacity:1}
</style>

<!-- ══ LOCK OVERLAY ══ -->
<div class="lock-overlay" id="lockOverlay">
  <div class="lock-box">
    <a href="dashboard.php" class="lock-close-btn" title="Back to Dashboard">✕</a>
    <div class="lock-icon">🔒</div>
    <div class="lock-title">Form is Locked</div>
    <div class="lock-sub">Enter the owner's name and phone number to unlock and start filling the form.</div>
    <div class="lock-field">
      <label>Owner Name <span style="color:#dc2626">*</span></label>
      <input type="text" id="lock_owner_name" placeholder="Full name of property owner" autocomplete="off">
    </div>
    <div class="lock-field">
      <label>Owner Phone Number <span style="color:#dc2626">*</span></label>
      <input type="tel" id="lock_owner_phone" placeholder="+91 XXXXX XXXXX" autocomplete="off" maxlength="15">
      <div class="lock-error" id="lock_phone_error">⚠ This phone number already exists in our records. Please check before proceeding.</div>
    </div>
    <button class="lock-btn" id="lockUnlockBtn" onclick="attemptUnlock()">🔓 Unlock &amp; Start</button>
    <div class="lock-nav-row">
      <a href="dashboard.php" class="lock-nav-btn">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>Dashboard
      </a>
      <a href="submit.php" class="lock-nav-btn">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>New Form
      </a>
    </div>
  </div>
</div>

<!-- Validation Toast -->
<div id="validationToast"></div>

<div class="app-wrap">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="main-content">
    <div class="page-header">
      <div class="page-title">New Submission</div>
      <div class="page-sub">Enter field data for your assigned zone</div>
    </div>

    <?php if ($msg === 'submission_success'): ?>
    <div class="success-card">
      <div class="sc-header">
        <div class="sc-icon">✅</div>
        <div>
          <div class="sc-title">Submission Saved Successfully!</div>
          <div class="sc-sub">Your data has been recorded.</div>
        </div>
      </div>
      <div class="success-time-box">
        <div class="stb-label">📅 Submission Time Recorded</div>
        <?php if ($submittedTimeDisplay): ?>
          <div class="stb-time"><?= htmlspecialchars($submittedTimeDisplay) ?></div>
          <div class="stb-note">IST at the moment you clicked Submit</div>
        <?php else: ?>
          <div class="stb-time"><?= date('d M Y, h:i:s A') ?></div>
          <div class="stb-note">Server time in IST (device time was unavailable)</div>
        <?php endif; ?>
      </div>
      <?php if ($effectiveTime): ?>
      <div class="success-time-box" style="margin-top:10px;border-color:#bfdbfe">
        <div class="stb-label" style="color:#2563eb">⏱ Effective Working Time</div>
        <div class="stb-time" style="color:#1e40af"><?= htmlspecialchars($effectiveTime) ?></div>
        <div class="stb-note" style="color:#3b82f6">Time from when you unlocked the form to submission</div>
      </div>
      <?php endif; ?>
      <?php if (!empty($nextVisitDate)): ?>
      <div class="success-time-box" style="margin-top:10px;border-color:#7dd3fc">
        <div class="stb-label" style="color:#0369a1">📅 Next Visit Scheduled</div>
        <div class="stb-time" style="color:#0c4a6e;font-size:1rem"><?= date('d M Y (l)', strtotime($nextVisitDate)) ?></div>
        <div class="stb-note" style="color:#7dd3fc">Follow-up visit planned</div>
      </div>
      <?php endif; ?>
      <div class="success-actions">
        <a href="submit.php" class="btn-new-form">
          <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>New Form
        </a>
        <a href="dashboard.php" class="btn-dashboard">
          <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>Back to Dashboard
        </a>
      </div>
    </div>
    <?php elseif ($msg): alert(htmlspecialchars($msg), $msgType); endif; ?>

    <?php if (!$zones): ?>
      <div style="display:flex;align-items:center;justify-content:center;min-height:280px">
        <div style="background:#fff;border:2px solid #fcd34d;border-radius:16px;padding:36px 40px;max-width:440px;text-align:center;box-shadow:0 8px 32px rgba(0,0,0,.08)">
          <div style="font-size:3rem;margin-bottom:12px">⚠️</div>
          <div style="font-size:1.2rem;font-weight:800;color:#92400e;margin-bottom:8px">No Zones Assigned Yet</div>
          <div style="font-size:.9rem;color:#78350f;line-height:1.6;margin-bottom:20px">
            You have not been assigned to any zone yet.<br>
            Please contact the main admin to get zones assigned before you can submit data.
          </div>
          <a href="dashboard.php" class="btn btn-ghost btn-sm">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            Back to Dashboard
          </a>
        </div>
      </div>
    <?php else: ?>

    <!-- ── LIVE CLOCK ── -->
    <div class="clock-bar">
      <div class="clock-main">
        <div class="clock-icon">🕐</div>
        <div>
          <div class="clock-label">Current Time (IST)</div>
          <div class="clock-time" id="live-clock">--:--:--</div>
          <div class="clock-date" id="live-date">Loading…</div>
        </div>
      </div>
    </div>

    <!-- ── EFFECTIVE TIME ── -->
    <div class="eff-time-bar" id="effTimeBar" style="display:none">
      <div>
        <div class="eff-time-label">⏱ Effective Working Time</div>
        <div class="eff-time-val" id="effTimeLive">0s</div>
        <div style="font-size:.72rem;color:#4ade80;margin-top:2px">Counting from when you unlocked the form</div>
      </div>
      <div class="eff-live" id="effClockLive">--:--:--</div>
    </div>

    <?php if ($dupWarning): ?>
    <div class="dup-banner">
      <div class="dup-title"><svg width="16" height="16" fill="none" stroke="#f59e0b" stroke-width="2" viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>Duplicate Owner Phone Detected</div>
      <div class="dup-meta">Phone <span><?= htmlspecialchars($_POST['owner_number'] ?? '') ?></span> already has a record in our system:<br><strong><?= htmlspecialchars($dupWarning['owner_name']) ?></strong> — <?= htmlspecialchars($dupWarning['village_city_town']) ?>, <?= htmlspecialchars($dupWarning['street']) ?><br>Submitted by <strong><?= htmlspecialchars($dupWarning['username']) ?></strong> on <?= date('d M Y H:i', strtotime($dupWarning['device_submitted_at'] ?: $dupWarning['submitted_at'])) ?> (Zone: <?= htmlspecialchars($dupWarning['zone_code']) ?>)</div>
      <div style="display:flex;gap:8px;flex-wrap:wrap"><a href="submit.php" class="btn btn-ghost btn-sm">← Go Back &amp; Edit</a><button type="button" class="btn btn-sm" style="background:#f59e0b;color:#fff;border:none" onclick="submitForce()">Submit Anyway</button></div>
    </div>
    <?php endif; ?>

    <div class="card">
      <form method="POST" enctype="multipart/form-data" id="mainForm">
        <input type="hidden" name="csrf"                value="<?= csrfToken() ?>">
        <input type="hidden" name="device_submitted_at" id="device_submitted_at">
        <input type="hidden" name="unlock_time_iso"     id="unlock_time_iso">
        <input type="hidden" name="latitude"             id="lat_hidden">
        <input type="hidden" name="longitude"            id="lng_hidden">
        <input type="hidden" name="gps_capture_id"       id="gps_capture_id">
        <input type="hidden" name="force_submit"         id="force_submit" value="0">
        <input type="hidden" name="owner_name"            id="h_owner_name"   value="<?= htmlspecialchars($_POST['owner_name'] ?? '') ?>">
        <input type="hidden" name="owner_number"          id="h_owner_number" value="<?= htmlspecialchars($_POST['owner_number'] ?? '') ?>">
        <input type="hidden" name="representative_name"   id="h_rep_name"     value="<?= htmlspecialchars($_POST['representative_name'] ?? '') ?>">
        <input type="hidden" name="representative_number" id="h_rep_number"   value="<?= htmlspecialchars($_POST['representative_number'] ?? '') ?>">
        <input type="hidden" name="village_city_town"     id="h_village"      value="<?= htmlspecialchars($_POST['village_city_town'] ?? '') ?>">
        <input type="hidden" name="street"                id="h_street"       value="<?= htmlspecialchars($_POST['street'] ?? '') ?>">
        <input type="hidden" name="webcam_landmark"    id="webcam_landmark_data">
        <input type="hidden" name="webcam_additional"  id="webcam_additional_data">

        <!-- Zone -->
        <div class="form-group">
          <label class="form-label">Zone / Sub-direction <span class="req">*</span></label>
          <select class="form-control" name="sub_direction_id" required>
            <option value="">Select zone</option>
            <?php foreach ($zones as $z): ?>
            <option value="<?= $z['id'] ?>" <?= (($_POST['sub_direction_id'] ?? '') == $z['id']) ? 'selected' : '' ?>>
              <?= $z['dir_code'] ?> › <?= htmlspecialchars($z['code']) ?> — <?= htmlspecialchars($z['from_location']) ?> → <?= htmlspecialchars($z['to_location']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="divider"></div>

        <!-- CUSTOMER DETAILS -->
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
          <div style="font-size:.85rem;font-weight:600;color:var(--c-gray-600)">CUSTOMER DETAILS</div>
          <div class="view-toggle">
            <button type="button" id="btn-grid" onclick="setView('grid')"><svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>Grid</button>
            <button type="button" id="btn-list" onclick="setView('list')"><svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/></svg>List</button>
          </div>
        </div>

        <!-- GRID VIEW -->
        <div class="form-section" id="view-grid">
          <div class="sec-title">Owner &amp; Location</div>
          <div class="cust-grid">
            <div class="field-card"><div class="fc-label">Owner Name <span class="req">*</span></div><input type="text" id="g_owner_name" placeholder="Full name" value="<?= htmlspecialchars($_POST['owner_name'] ?? '') ?>" oninput="sync('owner_name',this.value)"></div>
            <div class="field-card"><div class="fc-label">Owner Phone <span class="req">*</span></div><input type="tel" id="g_owner_number" placeholder="+91 XXXXX XXXXX" value="<?= htmlspecialchars($_POST['owner_number'] ?? '') ?>" oninput="sync('owner_number',this.value)" readonly style="background:#f8fafc;color:var(--c-gray-500)"></div>
            <div class="field-card"><div class="fc-label">Influencer Name <span class="req">*</span></div><input type="text" id="g_rep_name" placeholder="Rep name" value="<?= htmlspecialchars($_POST['representative_name'] ?? '') ?>" oninput="sync('rep_name',this.value)"></div>
            <div class="field-card"><div class="fc-label">Influencer Phone <span class="req">*</span></div><input type="tel" id="g_rep_number" placeholder="+91 XXXXX XXXXX" value="<?= htmlspecialchars($_POST['representative_number'] ?? '') ?>" oninput="sync('rep_number',this.value)"></div>
            <div class="field-card"><div class="fc-label">Village / City / Town <span class="req">*</span></div><input type="text" id="g_village" placeholder="e.g. Gandhi Nagar" value="<?= htmlspecialchars($_POST['village_city_town'] ?? '') ?>" oninput="sync('village',this.value)"></div>
            <div class="field-card"><div class="fc-label">Street <span class="req">*</span></div><input type="text" id="g_street" placeholder="Street / Road name" value="<?= htmlspecialchars($_POST['street'] ?? '') ?>" oninput="sync('street',this.value)"></div>
          </div>
        </div>

        <!-- LIST VIEW -->
        <div class="form-section" id="view-list" style="display:none">
          <div class="sec-title">Owner &amp; Location</div>
          <div class="cust-list">
            <div class="field-row"><span class="fl-label">Owner Name <span class="req">*</span></span><input type="text" id="l_owner_name" placeholder="Full name" value="<?= htmlspecialchars($_POST['owner_name'] ?? '') ?>" oninput="sync('owner_name',this.value)"></div>
            <div class="field-row"><span class="fl-label">Owner Phone <span class="req">*</span></span><input type="tel" id="l_owner_number" placeholder="+91 XXXXX XXXXX" value="<?= htmlspecialchars($_POST['owner_number'] ?? '') ?>" oninput="sync('owner_number',this.value)" readonly style="background:#f8fafc;color:var(--c-gray-500)"></div>
            <div class="field-row"><span class="fl-label">Representative <span class="req">*</span></span><input type="text" id="l_rep_name" placeholder="Rep name" value="<?= htmlspecialchars($_POST['representative_name'] ?? '') ?>" oninput="sync('rep_name',this.value)"></div>
            <div class="field-row"><span class="fl-label">Rep Phone <span class="req">*</span></span><input type="tel" id="l_rep_number" placeholder="+91 XXXXX XXXXX" value="<?= htmlspecialchars($_POST['representative_number'] ?? '') ?>" oninput="sync('rep_number',this.value)"></div>
            <div class="field-row"><span class="fl-label">Village / City <span class="req">*</span></span><input type="text" id="l_village" placeholder="e.g. Gandhi Nagar" value="<?= htmlspecialchars($_POST['village_city_town'] ?? '') ?>" oninput="sync('village',this.value)"></div>
            <div class="field-row"><span class="fl-label">Street <span class="req">*</span></span><input type="text" id="l_street" placeholder="Street / Road name" value="<?= htmlspecialchars($_POST['street'] ?? '') ?>" oninput="sync('street',this.value)"></div>
          </div>
        </div>

        <!-- ══ NEXT VISIT DATE ══ -->
        <div class="divider"></div>
        <div class="visit-date-section" id="visit-date-section">
          <div class="vd-title">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            📅 Date of Next Visit
            <span style="color:#dc2626;font-weight:700;margin-left:2px">*</span>
            <span style="font-size:.68rem;font-weight:600;color:#dc2626;background:#fee2e2;border:1px solid #fecaca;border-radius:4px;padding:1px 6px;margin-left:4px;text-transform:none;letter-spacing:0">Required</span>
          </div>
          <div>
            <input type="date" class="visit-date-input" name="next_visit_date" id="next_visit_date" min="<?= $todayISO ?>" value="<?= htmlspecialchars($_POST['next_visit_date'] ?? '') ?>" onchange="updateVisitPreview()" required>
            <div class="visit-shortcuts">
              <button type="button" class="visit-shortcut" onclick="setVisitDate('<?= $tomorrowISO ?>')">Tomorrow</button>
              <button type="button" class="visit-shortcut" onclick="setVisitDate('<?= date('Y-m-d', strtotime('+2 days')) ?>')">+2 Days</button>
              <button type="button" class="visit-shortcut" onclick="setVisitDate('<?= date('Y-m-d', strtotime('+3 days')) ?>')">+3 Days</button>
              <button type="button" class="visit-shortcut" onclick="setVisitDate('<?= $nextWeekISO ?>')">Next Week</button>
              <button type="button" class="visit-shortcut" onclick="setVisitDate('<?= date('Y-m-d', strtotime('+14 days')) ?>')">+2 Weeks</button>
              <button type="button" class="visit-shortcut" onclick="setVisitDate('<?= date('Y-m-d', strtotime('+1 month')) ?>')">+1 Month</button>
              <button type="button" class="visit-shortcut" style="border-color:#f87171;color:#dc2626" onclick="clearVisitDate()">✕ Clear</button>
            </div>
            <div class="visit-date-preview" id="visit_date_preview"></div>
          </div>
        </div>

        <!-- ══ AREA DETAILS ══ -->
        <div class="divider"></div>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
          <div>
            <span style="font-size:.85rem;font-weight:600;color:var(--c-gray-600)">AREA DETAILS</span>
            <span style="font-size:.72rem;color:#dc2626;font-weight:600;margin-left:8px">* All fields required</span>
          </div>
          <span style="font-size:.75rem;color:var(--c-gray-400)">Length × Breadth = auto sq ft</span>
        </div>
        <div class="area-rows" id="area-rows"></div>
        <button type="button" class="add-area-btn" onclick="addAreaRow()">
          <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
          Add Another Area
        </button>

        <!-- ══ GPS LOCATION ══ -->
        <div class="divider"></div>
        <div style="font-size:.85rem;font-weight:600;color:var(--c-gray-600);margin-bottom:10px">
          GPS LOCATION
          <span class="gps-req-badge">📍 Required</span>
        </div>

        <div style="display:flex;gap:6px;margin-bottom:12px">
          <button type="button" class="btn btn-ghost btn-sm" id="gps-mode-auto" onclick="switchGpsMode('auto')" style="border-bottom:2px solid var(--c-primary);color:var(--c-primary)">
            📡 Auto GPS
          </button>
          <button type="button" class="btn btn-ghost btn-sm" id="gps-mode-manual" onclick="switchGpsMode('manual')">
            ✏️ Manual Input
          </button>
        </div>

        <div id="gpsSection" class="gps-section">
          <div id="gps-auto-mode">
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <div class="coord-box" id="coord_display">No coordinates yet</div>
            <button type="button" class="btn btn-ghost btn-sm" onclick="fetchLocationAuto()" id="gpsBtn">
              📡 Fetch GPS
            </button>
            </div>
            <div id="loc_status" style="font-size:.78rem;color:var(--c-gray-500);margin-top:6px"></div>
          </div>

          <div id="gps-manual-mode" style="display:none">
            <div style="font-size:.78rem;color:#0369a1;margin-bottom:10px;font-weight:600">📍 Enter GPS Coordinates Manually</div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:10px">
            <div>
              <label style="font-size:.75rem;font-weight:600;color:var(--c-gray-600);display:block;margin-bottom:4px">Latitude (-90 to 90)</label>
              <input type="number" id="manual_lat" placeholder="e.g. 28.7041" min="-90" max="90" step="0.0001" style="width:100%;padding:8px;border:1.5px solid #7dd3fc;border-radius:6px;font-size:.88rem;font-family:monospace">
            </div>
            <div>
              <label style="font-size:.75rem;font-weight:600;color:var(--c-gray-600);display:block;margin-bottom:4px">Longitude (-180 to 180)</label>
              <input type="number" id="manual_lng" placeholder="e.g. 77.1025" min="-180" max="180" step="0.0001" style="width:100%;padding:8px;border:1.5px solid #7dd3fc;border-radius:6px;font-size:.88rem;font-family:monospace">
            </div>
            </div>
            <button type="button" class="btn btn-primary btn-sm" onclick="saveManualGps()" style="width:100%">
              ✅ Save Coordinates
            </button>
          </div>

          <div id="gps-stored" style="display:none;margin-top:10px;padding:12px;background:#f0fdf4;border:1.5px solid #86efac;border-radius:8px">
            <div style="font-size:.78rem;color:#15803d;font-weight:600;margin-bottom:6px">✅ GPS Coordinates Saved to Database</div>
            <div style="font-size:.88rem;font-family:monospace;color:#166534;word-break:break-all">
            📍 <span id="gps-display-lat">--</span>, <span id="gps-display-lng">--</span>
            <a id="gps-maps-link" href="#" target="_blank" style="margin-left:8px;color:var(--c-primary);text-decoration:none">🗺️ View Map</a>
            </div>
          </div>

          <div id="gpsErrMsg" class="gps-err-msg">GPS location is required before submitting.</div>
          <div id="gps_help_block" style="margin-top:8px;padding:10px;background:#fef3c7;border:1px solid #fcd34d;border-radius:6px;font-size:.82rem;color:#78350f;display:none">
            Location permission is blocked by the browser or page policy. Use Manual Input only when you have verified the coordinates.
          </div>
          <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">
            <button type="button" class="btn btn-ghost btn-sm" onclick="clearGpsCoordinates()">❌ Clear GPS</button>
            <button type="button" class="btn btn-ghost btn-sm" onclick="diagnoseGps()">🔍 Diagnose</button>
          </div>
          <div id="gps_debug" style="margin-top:8px;font-size:.82rem;color:var(--c-gray-600);white-space:pre-wrap;display:none;border:1px solid var(--c-gray-200);padding:10px;border-radius:6px;background:var(--c-gray-50)"></div>
        </div>
            <label class="form-label">Landmark Picture <span class="req">*</span></label>
            <div class="cam-tabs">
              <button type="button" class="cam-tab active" onclick="switchCamTab('lm','file',this)">📁 File</button>
              <button type="button" class="cam-tab" onclick="switchCamTab('lm','webcam',this)">📷 Webcam</button>
              <button type="button" class="cam-tab" onclick="switchCamTab('lm','camera',this)">📱 Camera</button>
            </div>
            <div id="lm-file"><input class="form-control" type="file" name="landmark_picture" id="lm_file_input" accept="image/*" onchange="previewImg(this,'prev_lm');clearWebcam('lm')"><img id="prev_lm" class="cam-preview"></div>
            <div id="lm-webcam" style="display:none">
              <div class="cam-box"><video id="lm_video" autoplay playsinline muted></video><canvas id="lm_canvas" style="display:none"></canvas></div>
              <div class="cam-actions"><button type="button" class="btn btn-ghost btn-sm" onclick="startCam('lm')">▶ Start</button><button type="button" class="btn btn-primary btn-sm" onclick="snapCam('lm')">📸 Capture</button><button type="button" class="btn btn-ghost btn-sm" onclick="retakeCam('lm')">↩ Retake</button></div>
              <img id="lm_snap" class="cam-preview">
            </div>
            <div id="lm-camera" style="display:none"><input class="form-control" type="file" name="landmark_picture_cam" id="lm_cam_input" accept="image/*" capture="environment" onchange="previewImg(this,'prev_lm_cam');clearWebcam('lm')"><img id="prev_lm_cam" class="cam-preview"></div>
          </div>
          <div class="form-group">
            <label class="form-label">Additional Picture <span class="opt">(optional)</span></label>
            <div class="cam-tabs">
              <button type="button" class="cam-tab active" onclick="switchCamTab('ap','file',this)">📁 File</button>
              <button type="button" class="cam-tab" onclick="switchCamTab('ap','webcam',this)">📷 Webcam</button>
              <button type="button" class="cam-tab" onclick="switchCamTab('ap','camera',this)">📱 Camera</button>
            </div>
            <div id="ap-file"><input class="form-control" type="file" name="additional_picture" id="ap_file_input" accept="image/*" onchange="previewImg(this,'prev_ap');clearWebcam('ap')"><img id="prev_ap" class="cam-preview"></div>
            <div id="ap-webcam" style="display:none">
              <div class="cam-box"><video id="ap_video" autoplay playsinline muted></video><canvas id="ap_canvas" style="display:none"></canvas></div>
              <div class="cam-actions"><button type="button" class="btn btn-ghost btn-sm" onclick="startCam('ap')">▶ Start</button><button type="button" class="btn btn-primary btn-sm" onclick="snapCam('ap')">📸 Capture</button><button type="button" class="btn btn-ghost btn-sm" onclick="retakeCam('ap')">↩ Retake</button></div>
              <img id="ap_snap" class="cam-preview">
            </div>
            <div id="ap-camera" style="display:none"><input class="form-control" type="file" name="additional_picture_cam" id="ap_cam_input" accept="image/*" capture="environment" onchange="previewImg(this,'prev_ap_cam');clearWebcam('ap')"><img id="prev_ap_cam" class="cam-preview"></div>
          </div>
          <div class="form-group">
            <label class="form-label">🪟 Tile Image <span class="opt">(optional — for PDF print)</span></label>
            <div class="cam-tabs">
              <button type="button" class="cam-tab active" onclick="switchCamTab('ti','file',this)">📁 File</button>
              <button type="button" class="cam-tab" onclick="switchCamTab('ti','webcam',this)">📷 Webcam</button>
              <button type="button" class="cam-tab" onclick="switchCamTab('ti','camera',this)">📱 Camera</button>
            </div>
            <div id="ti-file"><input class="form-control" type="file" name="tile_image" id="ti_file_input" accept="image/*" onchange="previewImg(this,'prev_ti');clearWebcam('ti')"><img id="prev_ti" class="cam-preview"></div>
            <div id="ti-webcam" style="display:none">
              <div class="cam-box"><video id="ti_video" autoplay playsinline muted></video><canvas id="ti_canvas" style="display:none"></canvas></div>
              <div class="cam-actions"><button type="button" class="btn btn-ghost btn-sm" onclick="startCam('ti')">▶ Start</button><button type="button" class="btn btn-primary btn-sm" onclick="snapCam('ti')">📸 Capture</button><button type="button" class="btn btn-ghost btn-sm" onclick="retakeCam('ti')">↩ Retake</button></div>
              <input type="hidden" name="webcam_tile" id="ti_snap_data">
              <img id="ti_snap" class="cam-preview">
            </div>
            <div id="ti-camera" style="display:none"><input class="form-control" type="file" name="tile_image_cam" id="ti_cam_input" accept="image/*" capture="environment" onchange="previewImg(this,'prev_ti_cam');clearWebcam('ti')"><img id="prev_ti_cam" class="cam-preview"></div>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Status <span class="req">*</span></label>
          <div style="display:flex;gap:10px;flex-wrap:wrap">
            <?php
            $statDef   = ['hot'=>['🟢','Hot','High interest'],'warm'=>['🟡','Warm','Moderate interest'],'cold'=>['🔴','Cold','No interest']];
            $statColors= ['hot'=>'var(--c-success)','warm'=>'var(--c-warn)','cold'=>'var(--c-danger)'];
            $statBgs   = ['hot'=>'var(--c-success-light)','warm'=>'var(--c-warn-light)','cold'=>'var(--c-danger-light)'];
            $curStatus = $_POST['status'] ?? 'warm';
            foreach ($statDef as $val => [$emoji, $label, $sub]):
                $checked = $val === $curStatus ? 'checked' : '';
                $bStyle  = $val === $curStatus ? "border:2px solid {$statColors[$val]};background:{$statBgs[$val]}" : 'border:2px solid var(--c-gray-200)';
            ?>
            <label style="flex:1;min-width:100px;cursor:pointer">
              <input type="radio" name="status" value="<?= $val ?>" <?= $checked ?> style="display:none" class="status-radio">
              <div class="status-opt" data-val="<?= $val ?>" data-color="<?= $statColors[$val] ?>" data-bg="<?= $statBgs[$val] ?>" style="<?= $bStyle ?>;border-radius:8px;padding:12px;text-align:center;transition:all .15s">
                <div style="font-size:1.2rem"><?= $emoji ?></div>
                <div style="font-size:.85rem;font-weight:600;color:<?= $statColors[$val] ?>"><?= $label ?></div>
                <div style="font-size:.75rem;color:var(--c-gray-500)"><?= $sub ?></div>
              </div>
            </label>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="submit-row">
          <button type="button" class="btn btn-ghost" onclick="resetAll()">Reset</button>
          <button type="submit" class="btn btn-primary" id="submitBtn">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>Submit
          </button>
        </div>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>

<nav class="mob-nav">
  <div class="mob-nav-items">
    <a href="dashboard.php" class="mob-nav-item"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>Home</a>
    <a href="submit.php" class="mob-nav-item active"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>Submit</a>
    <a href="my_data.php" class="mob-nav-item"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>My Data</a>
    <a href="locations.php" class="mob-nav-item"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>Zones</a>
  </div>
</nav>
<?php pageFooter(); ?>

<script>
/* ────────────────────────────────────────────────
   LIVE CLOCK
──────────────────────────────────────────────── */
const DAYS   = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
const MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
function pad(n){ return String(n).padStart(2,'0'); }
function fmtFull(d){ const h12=d.getHours()%12||12,ampm=d.getHours()>=12?'PM':'AM'; return pad(h12)+':'+pad(d.getMinutes())+':'+pad(d.getSeconds())+' '+ampm; }
function istParts(){
    const fmt = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Kolkata',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false
    });
    const out = {};
    for (const part of fmt.formatToParts(new Date())) {
        if (part.type !== 'literal') out[part.type] = part.value;
    }
    return out;
}
function nowISO(){
    const n = istParts();
    return n.year+'-'+n.month+'-'+n.day+'T'+n.hour+':'+n.minute+':'+n.second;
}
function nowISTDate(){
    const n = istParts();
    return new Date(Number(n.year), Number(n.month)-1, Number(n.day), Number(n.hour), Number(n.minute), Number(n.second));
}
function updateClock(){ const now=nowISTDate(); document.getElementById('live-clock').textContent=fmtFull(now); document.getElementById('live-date').textContent=DAYS[now.getDay()]+', '+now.getDate()+' '+MONTHS[now.getMonth()]+' '+now.getFullYear(); }
updateClock(); setInterval(updateClock,1000);

/* ────────────────────────────────────────────────
   VALIDATION TOAST
──────────────────────────────────────────────── */
let toastTimer = null;
function showToast(msg){
    const t = document.getElementById('validationToast');
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(()=>t.classList.remove('show'), 3800);
}

/* ────────────────────────────────────────────────
   NEXT VISIT DATE
──────────────────────────────────────────────── */
const DAY_NAMES=['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
const MON_NAMES=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
function setVisitDate(iso){ document.getElementById('next_visit_date').value=iso; updateVisitPreview(); }
function clearVisitDate(){ document.getElementById('next_visit_date').value=''; document.getElementById('visit_date_preview').innerHTML=''; }
function updateVisitPreview(){
    const val=document.getElementById('next_visit_date').value, previewEl=document.getElementById('visit_date_preview');
    if(!val){ previewEl.innerHTML=''; return; }
    const parts=val.split('-'), d=new Date(parseInt(parts[0]),parseInt(parts[1])-1,parseInt(parts[2]));
    const today=new Date(); today.setHours(0,0,0,0);
    const diffDays=Math.round((d-today)/(1000*60*60*24));
    let label=diffDays===0?'today':diffDays===1?'tomorrow':diffDays>0?'in '+diffDays+' day'+(diffDays!==1?'s':''):Math.abs(diffDays)+' day'+(Math.abs(diffDays)!==1?'s':'')+' ago';
    previewEl.innerHTML='📅 '+DAY_NAMES[d.getDay()]+', '+d.getDate()+' '+MON_NAMES[d.getMonth()]+' '+d.getFullYear()+' <span class="visit-days-badge">'+label+'</span>';
}
(function(){ if(document.getElementById('next_visit_date')?.value) updateVisitPreview(); })();

/* ────────────────────────────────────────────────
   LOCK / UNLOCK
──────────────────────────────────────────────── */
let unlockTimeISO='', unlockTimestamp=0;

function attemptUnlock(){
    const nameEl=document.getElementById('lock_owner_name'), phoneEl=document.getElementById('lock_owner_phone');
    const errEl=document.getElementById('lock_phone_error'), btn=document.getElementById('lockUnlockBtn');
    const name=nameEl.value.trim(), phone=phoneEl.value.trim().replace(/\s+/g,'');
    errEl.style.display='none'; nameEl.style.borderColor=''; phoneEl.style.borderColor='';
    if(!name){ nameEl.style.borderColor='#dc2626'; nameEl.focus(); return; }
    if(!phone||phone.length<8){ phoneEl.style.borderColor='#dc2626'; phoneEl.focus(); return; }
    btn.disabled=true; btn.textContent='🔍 Checking…';
    fetch('check_phone.php?phone='+encodeURIComponent(phone))
        .then(r=>r.json())
        .then(data=>{ if(data.exists){ errEl.style.display='block'; phoneEl.style.borderColor='#dc2626'; btn.disabled=false; btn.innerHTML='🔓 Unlock &amp; Start'; return; } doUnlock(name,phone); })
        .catch(()=>doUnlock(name,phone));
}

function doUnlock(name, phone){
    unlockTimeISO=nowISO(); unlockTimestamp=Date.now();
    document.getElementById('unlock_time_iso').value=unlockTimeISO;
    ['g_owner_name','l_owner_name'].forEach(id=>{const el=document.getElementById(id);if(el)el.value=name;});
    ['g_owner_number','l_owner_number'].forEach(id=>{const el=document.getElementById(id);if(el)el.value=phone;});
    document.getElementById('h_owner_name').value=name;
    document.getElementById('h_owner_number').value=phone;
    document.getElementById('lockOverlay').style.display='none';
    document.getElementById('effTimeBar').style.display='flex';
    startEffTimer();
}

document.getElementById('lock_owner_phone').addEventListener('keydown',e=>{if(e.key==='Enter')attemptUnlock();});
document.getElementById('lock_owner_name').addEventListener('keydown',e=>{if(e.key==='Enter')document.getElementById('lock_owner_phone').focus();});

function fmtEffDuration(ms){ const s=Math.floor(ms/1000),h=Math.floor(s/3600),m=Math.floor((s%3600)/60),sec=s%60; if(h>0)return h+'h '+m+'m '+sec+'s'; if(m>0)return m+'m '+sec+'s'; return sec+'s'; }
function startEffTimer(){ setInterval(()=>{ if(!unlockTimestamp)return; const elapsed=Date.now()-unlockTimestamp; const effEl=document.getElementById('effTimeLive'); if(effEl)effEl.textContent=fmtEffDuration(elapsed); const liveClock=document.getElementById('effClockLive'); if(liveClock)liveClock.textContent=fmtFull(new Date()); },1000); }

/* ────────────────────────────────────────────────
   SYNC GRID ↔ LIST ↔ HIDDEN
──────────────────────────────────────────────── */
const syncMap={owner_name:['g_owner_name','l_owner_name','h_owner_name'],owner_number:['g_owner_number','l_owner_number','h_owner_number'],rep_name:['g_rep_name','l_rep_name','h_rep_name'],rep_number:['g_rep_number','l_rep_number','h_rep_number'],village:['g_village','l_village','h_village'],street:['g_street','l_street','h_street']};
function sync(key,val){ syncMap[key].forEach(id=>{const el=document.getElementById(id);if(el&&el.value!==val)el.value=val;}); }

let currentView=localStorage.getItem('submit_view')||'grid';
function setView(v){ currentView=v; document.getElementById('view-grid').style.display=v==='grid'?'':'none'; document.getElementById('view-list').style.display=v==='list'?'':'none'; document.getElementById('btn-grid').classList.toggle('active',v==='grid'); document.getElementById('btn-list').classList.toggle('active',v==='list'); localStorage.setItem('submit_view',v); }
setView(currentView);

/* ────────────────────────────────────────────────
   GPS
──────────────────────────────────────────────── */
let gpsOk = false;
let currentLat = null;
let currentLng = null;
function gpsEl(id){ return document.getElementById(id); }

function switchGpsMode(mode){
    const autoMode = mode === 'auto';
    gpsEl('gps-auto-mode').style.display = autoMode ? 'block' : 'none';
    gpsEl('gps-manual-mode').style.display = autoMode ? 'none' : 'block';
    gpsEl('gps-mode-auto').style.borderBottom = autoMode ? '2px solid var(--c-primary)' : '';
    gpsEl('gps-mode-auto').style.color = autoMode ? 'var(--c-primary)' : '';
    gpsEl('gps-mode-manual').style.borderBottom = autoMode ? '' : '2px solid var(--c-primary)';
    gpsEl('gps-mode-manual').style.color = autoMode ? '' : 'var(--c-primary)';
}

function validCoord(lat, lng){
    return Number.isFinite(lat) && Number.isFinite(lng) && lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180 && !(lat === 0 && lng === 0);
}

async function saveGpsCapture(lat, lng, accuracy, source){
    const body = new URLSearchParams();
    body.set('csrf', document.querySelector('input[name="csrf"]').value);
    body.set('lat', lat.toFixed(7));
    body.set('lng', lng.toFixed(7));
    body.set('source', source);
    if(Number.isFinite(accuracy)) body.set('accuracy', accuracy.toFixed(2));

    const res = await fetch('save_gps.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body
    });
    const data = await res.json().catch(()=>({success:false,msg:'Invalid GPS server response'}));
    if(!res.ok || !data.success) throw new Error(data.msg || 'GPS save failed');
    return data;
}

function applySavedGps(data){
    const lat = Number(data.lat).toFixed(7);
    const lng = Number(data.lng).toFixed(7);
    currentLat = lat;
    currentLng = lng;
    gpsOk = true;

    gpsEl('lat_hidden').value = lat;
    gpsEl('lng_hidden').value = lng;
    gpsEl('gps_capture_id').value = data.capture_id || '';
    gpsEl('coord_display').innerHTML = `<a href="https://maps.google.com/?q=${lat},${lng}" target="_blank">📍 Lat: ${lat} / Lng: ${lng}</a>`;
    gpsEl('gps-display-lat').textContent = lat;
    gpsEl('gps-display-lng').textContent = lng;
    gpsEl('gps-maps-link').href = `https://maps.google.com/?q=${lat},${lng}`;
    gpsEl('gps-stored').style.display = 'block';
    gpsEl('gpsErrMsg').style.display = 'none';
    gpsEl('gps_help_block').style.display = 'none';
    gpsEl('gpsSection').classList.remove('gps-error');
    gpsEl('gpsSection').classList.add('gps-ok');
}

function showGpsError(message, showHelp){
    gpsOk = false;
    gpsEl('gps_capture_id').value = '';
    gpsEl('lat_hidden').value = '';
    gpsEl('lng_hidden').value = '';
    gpsEl('loc_status').textContent = message;
    gpsEl('loc_status').style.color = 'var(--c-danger)';
    gpsEl('gpsSection').classList.add('gps-error');
    gpsEl('gpsSection').classList.remove('gps-ok');
    gpsEl('gpsErrMsg').style.display = 'block';
    gpsEl('gps_help_block').style.display = showHelp ? 'block' : 'none';
}

async function fetchLocationAuto(){
    const btn = gpsEl('gpsBtn');
    if(!navigator.geolocation){
        showGpsError('Geolocation is not supported by this browser.', false);
        return;
    }

    btn.disabled = true;
    btn.textContent = 'Locating...';
    gpsEl('loc_status').textContent = 'Requesting location access...';
    gpsEl('loc_status').style.color = 'var(--c-gray-600)';

    navigator.geolocation.getCurrentPosition(async pos=>{
        const lat = pos.coords.latitude;
        const lng = pos.coords.longitude;
        const accuracy = pos.coords.accuracy;
        try {
            const data = await saveGpsCapture(lat, lng, accuracy, 'auto');
            applySavedGps(data);
            gpsEl('loc_status').textContent = `Location saved to database${data.accuracy ? ' (accuracy ' + data.accuracy + 'm)' : ''}.`;
            gpsEl('loc_status').style.color = 'var(--c-success)';
            btn.textContent = '🔄 Re-fetch GPS';
        } catch (e) {
            showGpsError(e.message, false);
            btn.textContent = '🔄 Retry GPS';
        } finally {
            btn.disabled = false;
        }
    }, err=>{
        let msg = 'Unable to retrieve location.';
        if(err.code === 1) msg = err.message && err.message.includes('permissions policy')
            ? 'Browser blocked geolocation by page permissions policy.'
            : 'Location permission was denied by the browser.';
        if(err.code === 2) msg = 'Position unavailable. Check device location/GPS.';
        if(err.code === 3) msg = 'Location request timed out. Try again.';
        showGpsError(msg, err.code === 1);
        btn.disabled = false;
        btn.textContent = '🔄 Retry GPS';
    }, {enableHighAccuracy:true, timeout:15000, maximumAge:0});
}

async function saveManualGps(){
    const lat = Number(gpsEl('manual_lat').value);
    const lng = Number(gpsEl('manual_lng').value);
    if(!validCoord(lat, lng)){
        showGpsError('Enter valid latitude and longitude values.', false);
        return;
    }
    try {
        const data = await saveGpsCapture(lat, lng, null, 'manual');
        applySavedGps(data);
        gpsEl('loc_status').textContent = 'Manual coordinates saved to database.';
        gpsEl('loc_status').style.color = 'var(--c-success)';
    } catch (e) {
        showGpsError(e.message, false);
    }
}

function clearGpsCoordinates(){
    gpsOk = false;
    currentLat = null;
    currentLng = null;
    ['lat_hidden','lng_hidden','gps_capture_id','manual_lat','manual_lng'].forEach(id=>{ const el=gpsEl(id); if(el) el.value=''; });
    gpsEl('coord_display').textContent = 'No coordinates yet';
    gpsEl('loc_status').textContent = '';
    gpsEl('gps-stored').style.display = 'none';
    gpsEl('gpsErrMsg').style.display = 'none';
    gpsEl('gps_help_block').style.display = 'none';
    gpsEl('gpsSection').classList.remove('gps-ok','gps-error');
}

function diagnoseGps(){
    const dbg = gpsEl('gps_debug');
    const lat = gpsEl('lat_hidden').value || '[empty]';
    const lng = gpsEl('lng_hidden').value || '[empty]';
    const cap = gpsEl('gps_capture_id').value || '[empty]';
    dbg.style.display = 'block';
    dbg.textContent = [
        'GPS DIAGNOSTICS',
        '',
        'Geolocation API: ' + (navigator.geolocation ? 'YES' : 'NO'),
        'Secure context: ' + (window.isSecureContext ? 'YES' : 'NO'),
        'In iframe: ' + (window.top !== window.self ? 'YES' : 'NO'),
        'Saved capture ID: ' + cap,
        'Latitude: ' + lat,
        'Longitude: ' + lng,
        '',
        cap === '[empty]' ? 'Status: coordinates are not saved to database yet.' : 'Status: coordinates are ready for submission.'
    ].join('\n');
}

const checkLocationPermission = fetchLocationAuto;
const fetchLocation = fetchLocationAuto;
const diagnoseLocation = diagnoseGps;

function copyCoords(){
  const lat=document.getElementById('lat_hidden').value, lng=document.getElementById('lng_hidden').value;
  if(!lat||!lng){ showToast('No coordinates to copy.'); return; }
  navigator.clipboard?.writeText(lat+','+lng).then(()=>showToast('Coordinates copied'), ()=>showToast('Copy failed — try manually'));
}

/* ────────────────────────────────────────────────
   AREA ROWS — mandatory fields
──────────────────────────────────────────────── */
let areaIdx=0;
const AREA_TYPES=['Residential','Commercial','Industrial','Agricultural','Mixed Use','Vacant Land','Other'];

function addAreaRow(){
    const idx=areaIdx++;
    const opts=AREA_TYPES.map(t=>`<option value="${t}">${t}</option>`).join('');
    const div=document.createElement('div');
    div.className='area-row-item'; div.id='area-row-'+idx;
    div.innerHTML=`
        <div class="area-row-header">
            <span class="area-row-num">Area ${idx+1}</span>
            <button type="button" class="remove-area" onclick="removeArea(${idx})">✕ Remove</button>
        </div>
        <div class="area-fields">
            <div class="form-group area-field-wrap" style="margin:0">
                <label class="form-label" style="font-size:.75rem">Property Type<span class="req-star">*</span></label>
                <select class="form-control area-required" name="area_type[]" id="atype-${idx}" oninput="clearAreaErr(this)">
                    <option value="">Select…</option>${opts}
                </select>
                <div class="area-field-err" id="err-atype-${idx}">Required</div>
            </div>
            <div class="form-group area-field-wrap" style="margin:0">
                <label class="form-label" style="font-size:.75rem">Length (ft)<span class="req-star">*</span></label>
                <input class="form-control area-required" type="number" name="area_length[]" id="alen-${idx}" min="0" step="0.01" placeholder="e.g. 40" oninput="calcSqft(${idx});clearAreaErr(this)">
                <div class="area-field-err" id="err-alen-${idx}">Required</div>
            </div>
            <div class="form-group area-field-wrap" style="margin:0">
                <label class="form-label" style="font-size:.75rem">Breadth (ft)<span class="req-star">*</span></label>
                <input class="form-control area-required" type="number" name="area_breadth[]" id="abreadth-${idx}" min="0" step="0.01" placeholder="e.g. 60" oninput="calcSqft(${idx});clearAreaErr(this)">
                <div class="area-field-err" id="err-abreadth-${idx}">Required</div>
            </div>
            <div class="form-group area-field-wrap" style="margin:0">
                <label class="form-label" style="font-size:.75rem">Manual Sq Ft <span style="color:var(--c-gray-400);font-weight:400">(if no L×B)</span></label>
                <input class="form-control" type="number" name="area_sqft[]" min="0" step="0.01" placeholder="e.g. 2400" id="sqft-manual-${idx}" oninput="calcSqft(${idx})">
            </div>
            <div class="form-group area-field-wrap" style="margin:0">
                <label class="form-label" style="font-size:.75rem">Bathroom Count<span class="req-star">*</span></label>
                <input class="form-control area-required" type="number" name="area_count[]" id="acount-${idx}" min="0" step="1" placeholder="e.g. 2" oninput="clearAreaErr(this)">
                <div class="area-field-err" id="err-acount-${idx}">Required</div>
            </div>
        </div>
        <div class="area-calc-box" id="calc-box-${idx}" style="display:none">
            <div><div class="calc-label">Total Area</div><div class="calc-result" id="calc-result-${idx}">0</div><div class="calc-formula" id="calc-formula-${idx}"></div></div>
        </div>`;
    document.getElementById('area-rows').appendChild(div);
    renumberAreas();
}

function removeArea(idx){const el=document.getElementById('area-row-'+idx);if(el)el.remove();renumberAreas();}
function renumberAreas(){document.querySelectorAll('.area-row-item').forEach((el,i)=>{const num=el.querySelector('.area-row-num');if(num)num.textContent='Area '+(i+1);});}

function clearAreaErr(el){
    el.classList.remove('inp-err');
    const row=el.closest('.area-field-wrap');
    if(row){const err=row.querySelector('.area-field-err');if(err)err.style.display='none';}
    el.closest('.area-row-item')?.classList.remove('area-has-error');
}

function calcSqft(idx){
    const row=document.getElementById('area-row-'+idx);if(!row)return;
    const lenEl=document.getElementById('alen-'+idx), breadthEl=document.getElementById('abreadth-'+idx), manualEl=document.getElementById('sqft-manual-'+idx);
    const len=parseFloat(lenEl?.value)||0,breadth=parseFloat(breadthEl?.value)||0,manual=parseFloat(manualEl?.value)||0;
    const box=document.getElementById('calc-box-'+idx),res=document.getElementById('calc-result-'+idx),formula=document.getElementById('calc-formula-'+idx);
    if(!box||!res)return;
    if(len>0&&breadth>0){const t=(len*breadth).toFixed(2);box.style.display='flex';res.textContent=parseFloat(t).toLocaleString()+' sq ft';formula.textContent=len+' ft × '+breadth+' ft';}
    else if(manual>0){box.style.display='flex';res.textContent=parseFloat(manual).toLocaleString()+' sq ft';formula.textContent='Manual entry';}
    else{box.style.display='none';}
}

/* ── Validate all area rows — returns list of error messages ── */
function validateAreas(){
    const errors=[];
    document.querySelectorAll('.area-row-item').forEach((row,rowIdx)=>{
        let rowHasErr=false;
        row.querySelectorAll('.area-required').forEach(inp=>{
            const val=(inp.value||'').trim();
            if(!val||val===''){
                inp.classList.add('inp-err');
                const wrap=inp.closest('.area-field-wrap');
                if(wrap){const err=wrap.querySelector('.area-field-err');if(err)err.style.display='block';}
                rowHasErr=true;
            }
        });
        if(rowHasErr){row.classList.add('area-has-error');errors.push('Area '+(rowIdx+1)+': all required fields must be filled');}
    });
    return errors;
}

addAreaRow();

/* ────────────────────────────────────────────────
   STATUS RADIO
──────────────────────────────────────────────── */
document.querySelectorAll('.status-radio').forEach(r=>{
    r.addEventListener('change',()=>{
        document.querySelectorAll('.status-opt').forEach(o=>{o.style.border='2px solid var(--c-gray-200)';o.style.background='';});
        const opt=document.querySelector(`.status-opt[data-val="${r.value}"]`);
        if(opt){opt.style.border=`2px solid ${opt.dataset.color}`;opt.style.background=opt.dataset.bg;}
    });
});

/* ────────────────────────────────────────────────
   FORM SUBMIT — full validation
──────────────────────────────────────────────── */
document.getElementById('mainForm').addEventListener('submit', function(e){
    document.getElementById('device_submitted_at').value=nowISO();

    const isGrid=currentView==='grid';
    const pairs=[
        ['h_owner_name',   isGrid?'g_owner_name'  :'l_owner_name'],
        ['h_owner_number', isGrid?'g_owner_number' :'l_owner_number'],
        ['h_rep_name',     isGrid?'g_rep_name'     :'l_rep_name'],
        ['h_rep_number',   isGrid?'g_rep_number'   :'l_rep_number'],
        ['h_village',      isGrid?'g_village'      :'l_village'],
        ['h_street',       isGrid?'g_street'       :'l_street'],
    ];
    const labels={h_owner_name:'Owner Name',h_owner_number:'Owner Phone',h_rep_name:'Representative Name',h_rep_number:'Rep Phone',h_village:'Village/City',h_street:'Street'};
    const missing=[];

    pairs.forEach(([hid,vid])=>{
        const val=(document.getElementById(vid)?.value||'').trim();
        document.getElementById(hid).value=val;
        if(!val) missing.push(labels[hid]);
    });

    // Landmark picture
    const lmFile=document.getElementById('lm_file_input')?.files?.length;
    const lmCam=document.getElementById('lm_cam_input')?.files?.length;
    const lmWebcam=document.getElementById('webcam_landmark_data')?.value;
    if(!lmFile&&!lmCam&&!lmWebcam) missing.push('Landmark Picture');

    // GPS mandatory
    const lat=(document.getElementById('lat_hidden').value||'').trim();
    const lng=(document.getElementById('lng_hidden').value||'').trim();
    const gpsCaptureId=(document.getElementById('gps_capture_id').value||'').trim();
    if(!lat||!lng||!gpsCaptureId){
        document.getElementById('gpsSection').classList.add('gps-error');
        document.getElementById('gpsErrMsg').style.display='block';
        missing.push('GPS Location');
        // scroll GPS into view
        document.getElementById('gpsSection').scrollIntoView({behavior:'smooth',block:'center'});
    }

    // Area rows
    const areaErrors=validateAreas();
    if(areaErrors.length) missing.push(...areaErrors);

    if(missing.length){
        e.preventDefault();
        // Show first critical missing as toast
        const firstSimple=missing.find(m=>!m.startsWith('Area'));
        showToast('⚠ Required: ' + (firstSimple||missing[0]));
        // Scroll to first area error if any
        const firstErrRow=document.querySelector('.area-has-error');
        if(firstErrRow&&!firstSimple) firstErrRow.scrollIntoView({behavior:'smooth',block:'center'});
        return;
    }
});

function submitForce(){
    document.getElementById('force_submit').value=1;
    document.getElementById('device_submitted_at').value=nowISO();
    document.getElementById('mainForm').submit();
}

/* ────────────────────────────────────────────────
   IMAGE PREVIEW & WEBCAM
──────────────────────────────────────────────── */
function previewImg(input,id){const el=document.getElementById(id);if(!el||!input.files?.[0])return;const r=new FileReader();r.onload=e=>{el.src=e.target.result;el.style.display='block';};r.readAsDataURL(input.files[0]);}

function switchCamTab(prefix,mode,btn){
    ['file','webcam','camera'].forEach(m=>{document.getElementById(prefix+'-'+m).style.display=m===mode?'':'none';});
    btn.closest('.cam-tabs').querySelectorAll('.cam-tab').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    if(mode!=='webcam')stopCam(prefix);
}
const camStreams={};
async function startCam(prefix){try{const stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:'environment'},audio:false});camStreams[prefix]=stream;const video=document.getElementById(prefix+'_video');video.srcObject=stream;video.style.display='block';document.getElementById(prefix+'_snap').style.display='none';}catch(e){alert('Camera error: '+e.message);}}
function snapCam(prefix){
    const video=document.getElementById(prefix+'_video'),canvas=document.getElementById(prefix+'_canvas'),snap=document.getElementById(prefix+'_snap');
    const dataField=document.getElementById(prefix==='ti'?'ti_snap_data':('webcam_'+(prefix==='lm'?'landmark':'additional')+'_data'));
    canvas.width=video.videoWidth;canvas.height=video.videoHeight;canvas.getContext('2d').drawImage(video,0,0);
    const dataUrl=canvas.toDataURL('image/jpeg',0.85);snap.src=dataUrl;snap.style.display='block';video.style.display='none';if(dataField)dataField.value=dataUrl;stopCam(prefix);
}
function retakeCam(prefix){const snap=document.getElementById(prefix+'_snap'),df=document.getElementById(prefix==='ti'?'ti_snap_data':('webcam_'+(prefix==='lm'?'landmark':'additional')+'_data'));snap.style.display='none';if(df)df.value='';startCam(prefix);}
function stopCam(prefix){if(camStreams[prefix]){camStreams[prefix].getTracks().forEach(t=>t.stop());delete camStreams[prefix];}}
function clearWebcam(prefix){const df=document.getElementById('webcam_'+(prefix==='lm'?'landmark':'additional')+'_data');if(df)df.value='';}

/* ────────────────────────────────────────────────
   RESET
──────────────────────────────────────────────── */
function resetAll(){
    ['g_owner_name','g_rep_name','g_rep_number','g_village','g_street','l_owner_name','l_rep_name','l_rep_number','l_village','l_street','h_owner_name','h_rep_name','h_rep_number','h_village','h_street'].forEach(id=>{const el=document.getElementById(id);if(el)el.value='';});
    clearVisitDate();
    document.getElementById('area-rows').innerHTML=''; areaIdx=0; addAreaRow();
    document.getElementById('coord_display').textContent='No coordinates yet — tap Fetch GPS below';
    document.getElementById('loc_status').textContent='';
    document.getElementById('lat_hidden').value=''; document.getElementById('lng_hidden').value=''; document.getElementById('gps_capture_id').value='';
    document.getElementById('gpsSection').classList.remove('gps-ok','gps-error');
    document.getElementById('gpsErrMsg').style.display='none';
    document.getElementById('gps-stored').style.display='none';
    gpsOk=false;
    ['prev_lm','prev_ap','lm_snap','ap_snap','ti_snap'].forEach(id=>{const el=document.getElementById(id);if(el){el.src='';el.style.display='none';}});
    ['webcam_landmark_data','webcam_additional_data','ti_snap_data'].forEach(id=>{const el=document.getElementById(id);if(el)el.value='';});
    stopCam('lm'); stopCam('ap'); stopCam('ti');
}
</script>
