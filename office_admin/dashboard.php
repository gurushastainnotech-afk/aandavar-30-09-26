<?php
// ============================================================
// office_admin/dashboard.php
// ============================================================
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
requireLogin('office_admin');

$db   = getDB();
$user = currentUser();

/* ── Ensure tables exist ────────────────────────────────────────────────── */
$db->exec("CREATE TABLE IF NOT EXISTS edit_unlock_requests (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    submission_id INT UNSIGNED NOT NULL,
    requested_by  INT UNSIGNED NOT NULL,
    reason TEXT NOT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    reviewed_by INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    reject_note TEXT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_eur_sub(submission_id),
    KEY idx_eur_req(requested_by),
    KEY idx_eur_status(status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS submission_area_images (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    area_id INT UNSIGNED NOT NULL,
    submission_id INT UNSIGNED NOT NULL,
    image_filename VARCHAR(255) NOT NULL,
    image_type ENUM('upload','camera') NOT NULL DEFAULT 'upload',
    sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sai_area(area_id),
    KEY idx_sai_submission(submission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ── Column guards ───────────────────────────────────────────────────────── */
$colChecks = [
    'submission_areas' => [
        'tile_chosen'        => "ALTER TABLE submission_areas ADD COLUMN tile_chosen VARCHAR(255) NOT NULL DEFAULT '' AFTER area_count",
        'tile_size_key'      => "ALTER TABLE submission_areas ADD COLUMN tile_size_key VARCHAR(30) NOT NULL DEFAULT '2|2|4' AFTER tile_chosen",
        'wastage_pct'        => "ALTER TABLE submission_areas ADD COLUMN wastage_pct TINYINT UNSIGNED NOT NULL DEFAULT 10 AFTER tile_size_key",
        'tile_surface'       => "ALTER TABLE submission_areas ADD COLUMN tile_surface VARCHAR(10) NOT NULL DEFAULT 'floor' AFTER wastage_pct",
        'wall_tile_size_key' => "ALTER TABLE submission_areas ADD COLUMN wall_tile_size_key VARCHAR(30) NOT NULL DEFAULT '2|2|4' AFTER tile_surface",
        'wall_wastage_pct'   => "ALTER TABLE submission_areas ADD COLUMN wall_wastage_pct TINYINT UNSIGNED NOT NULL DEFAULT 10 AFTER wall_tile_size_key",
        'is_bathroom'        => "ALTER TABLE submission_areas ADD COLUMN is_bathroom TINYINT(1) NOT NULL DEFAULT 0 AFTER wall_wastage_pct",
        'area_height'        => "ALTER TABLE submission_areas ADD COLUMN area_height DECIMAL(6,2) NULL AFTER area_breadth",
    ],
];
foreach ($colChecks as $tbl => $cols) {
    foreach ($cols as $col => $sql) {
        try {
            $chk = $db->query("SHOW COLUMNS FROM $tbl LIKE '$col'")->fetch();
            if (!$chk) $db->exec($sql);
        } catch (Exception $e) {}
    }
}
try {
    $orCheck = $db->query("SHOW COLUMNS FROM submissions LIKE 'other_status_reason'")->fetch();
    if (!$orCheck) {
        $db->exec("ALTER TABLE submissions ADD COLUMN other_status_reason TEXT NULL AFTER status");
    }
} catch (Exception $e) {}
try {
    $bpCheck = $db->query("SHOW COLUMNS FROM submission_areas LIKE 'bath_light_h'")->fetch();
    if (!$bpCheck) {
        $db->exec("ALTER TABLE submission_areas
            ADD COLUMN bath_light_h DECIMAL(6,2) NULL,
            ADD COLUMN bath_high_h  DECIMAL(6,2) NULL,
            ADD COLUMN bath_dark_h  DECIMAL(6,2) NULL,
            ADD COLUMN bath_tile_size VARCHAR(30) NOT NULL DEFAULT '2|2|4',
            ADD COLUMN bath_wastage_pct TINYINT UNSIGNED NOT NULL DEFAULT 10");
    }
} catch (Exception $e) {}

/* ── Migrate area_type ENUM → VARCHAR ───────────────────────────────────── */
try {
    $col = $db->query("SHOW COLUMNS FROM submission_areas LIKE 'area_type'")->fetch();
    if ($col && stripos($col['Type'], 'enum') !== false)
        $db->exec("ALTER TABLE submission_areas MODIFY COLUMN area_type VARCHAR(120) NOT NULL DEFAULT ''");
} catch (Exception $e) {}

/* ── Zones ──────────────────────────────────────────────────────────────── */
$zonesQ = $db->query(
    "SELECT sd.*, d.code AS dir_code FROM sub_directions sd JOIN directions d ON sd.direction_id = d.id ORDER BY d.code, sd.code"
);
$allZones = $zonesQ->fetchAll();

/* ── Redirect helper ────────────────────────────────────────────────────── */
function redirectBack(string $msg, string $type = 'success'): never {
    header('Location: dashboard.php?flash=' . urlencode($msg) . '&flash_type=' . $type);
    exit;
}

/* ═══════════════════════════════════════════════════════════════════════════
   POST ACTIONS
═══════════════════════════════════════════════════════════════════════════ */
$msg      = '';
$msgType  = 'success';
$dupWarning           = null;
$submittedTimeDisplay = null;
$effectiveTime        = null;
$prefillRep           = '';
$prefillRepN          = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? 'quick_submit';

    /* ── Quick Submit ─────────────────────────────────────────────────── */
    if ($action === 'quick_submit') {
        $sdId      = (int)$_POST['sub_direction_id'];
        $owner     = trim($_POST['owner_name']);
        $ownerN    = trim($_POST['owner_number']);
        $rep       = trim($_POST['representative_name']);
        $repN      = trim($_POST['representative_number']);
        $village   = trim($_POST['village_city_town']);
        $street    = trim($_POST['street']);
        $rawStatus = $_POST['status'] ?? 'enquiry';
        $statusMap = [
            'Enquiry' => 'enquiry',
            'Billed' => 'billed',
            'Outside Purchase' => 'outside_purchase',
            'Other' => 'others',
        ];
        $status = $statusMap[$rawStatus] ?? $rawStatus;
        if (!in_array($status, ['enquiry','billed','outside_purchase','others'], true)) $status = 'enquiry';
        $statusReason = trim($_POST['status_reason'] ?? '');
        $force     = (int)($_POST['force_submit'] ?? 0);

        $nextVisitDate     = trim($_POST['next_visit_date'] ?? '') ?: null;
        $rawDeviceTime     = trim($_POST['device_submitted_at'] ?? '');
        $rawUnlockTime     = trim($_POST['unlock_time_iso'] ?? '');
        $deviceSubmittedAt = null;

        if ($rawDeviceTime) {
            $dt = DateTime::createFromFormat('Y-m-d\TH:i:s', $rawDeviceTime)
               ?: DateTime::createFromFormat('Y-m-d\TH:i', $rawDeviceTime);
            if ($dt) {
                $deviceSubmittedAt    = $dt->format('Y-m-d H:i:s');
                $submittedTimeDisplay = $dt->format('d M Y, h:i:s A');
            }
        }

        if ($rawUnlockTime && $rawDeviceTime) {
            $dtU = DateTime::createFromFormat('Y-m-d\TH:i:s', $rawUnlockTime)
                ?: DateTime::createFromFormat('Y-m-d\TH:i', $rawUnlockTime);
            $dtS = DateTime::createFromFormat('Y-m-d\TH:i:s', $rawDeviceTime)
                ?: DateTime::createFromFormat('Y-m-d\TH:i', $rawDeviceTime);
            if ($dtU && $dtS) {
                $diff = $dtS->getTimestamp() - $dtU->getTimestamp();
                if ($diff >= 0) {
                    $h = floor($diff / 3600); $m = floor(($diff % 3600) / 60); $s = $diff % 60;
                    $effectiveTime = $h > 0 ? "{$h}h {$m}m {$s}s" : ($m > 0 ? "{$m}m {$s}s" : "{$s}s");
                }
            }
        }

        if (!$force) {
            $dupStmt = $db->prepare(
                "SELECT s.id,s.owner_name,s.village_city_town,s.street,
                        s.submitted_at,s.device_submitted_at,
                        u.username, sd.code AS zone_code
                 FROM submissions s
                 JOIN users u  ON s.submitted_by=u.id
                 JOIN sub_directions sd ON s.sub_direction_id=sd.id
                 WHERE s.owner_number=? LIMIT 1"
            );
            $dupStmt->execute([$ownerN]);
            $dup = $dupStmt->fetch();
            if ($dup) { $dupWarning = $dup; goto render_page; }
        }

        $db->prepare("INSERT INTO submissions
            (submitted_by, sub_direction_id, owner_name, owner_number,
             representative_name, representative_number,
             village_city_town, street,
             status, other_status_reason, next_visit_date, device_submitted_at, unlock_time_iso)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([
               $user['id'], $sdId, $owner, $ownerN,
               $rep, $repN,
               $village, $street,
               $status, $statusReason ?: null, $nextVisitDate,
               $deviceSubmittedAt, $rawUnlockTime,
           ]);
        $submissionId = $db->lastInsertId();

        $areaTypes    = $_POST['area_type']    ?? [];
        $areaLengths  = $_POST['area_length']  ?? [];
        $areaBreadths = $_POST['area_breadth'] ?? [];
        $areaCounts   = $_POST['area_count']   ?? [];
        $areaStmt = $db->prepare(
            "INSERT INTO submission_areas
             (submission_id,area_type,area_length,area_breadth,area_sqft,total_sqft,area_count,sort_order)
             VALUES (?,?,?,?,?,?,?,?)"
        );
        foreach ($areaTypes as $i => $aType) {
            $aType = trim($aType); if ($aType === '') continue;
            $al  = ($areaLengths[$i]  ?? '') !== '' ? (float)$areaLengths[$i]  : null;
            $ab  = ($areaBreadths[$i] ?? '') !== '' ? (float)$areaBreadths[$i] : null;
            $ac  = ($areaCounts[$i]   ?? '') !== '' ? (int)$areaCounts[$i]     : null;
            $tot = ($al && $ab) ? round($al * $ab, 2) : null;
            $areaStmt->execute([$submissionId, $aType, $al, $ab, $tot, $tot, $ac, $i]);
        }

        logActivity($user['id'], 'oa_submit', "Submitted owner: $owner");
        $msg        = 'submission_success';
        $prefillRep  = $rep;
        $prefillRepN = $repN;
    }
}

/* ── Flash ──────────────────────────────────────────────────────────────── */
render_page:
$flashMsg  = '';
$flashType = 'success';
if (!empty($_GET['flash'])) {
    $flashMsg  = htmlspecialchars($_GET['flash']);
    $flashType = in_array($_GET['flash_type'] ?? '', ['success','error','warn','info']) ? $_GET['flash_type'] : 'success';
}

$todayISO    = date('Y-m-d');
$tomorrowISO = date('Y-m-d', strtotime('+1 day'));
$nextWeekISO = date('Y-m-d', strtotime('+7 days'));

$expStmt = $db->prepare("SELECT password_expires_at FROM users WHERE id=?");
$expStmt->execute([$user['id']]);
$expiry    = $expStmt->fetchColumn();
$expiresIn = $expiry ? max(0, strtotime($expiry) - time()) : 0;

pageHead('Dashboard — Office Admin');
topbar($user['username'], $user['role']);
?>
<style>
/* ══ GREETING ════════════════════════════════════════════════════════════ */
.greet-wrap {
  background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 60%, #1e40af 100%);
  border-radius: 16px;
  padding: 28px 30px;
  margin-bottom: 24px;
  position: relative;
  overflow: hidden;
  color: #fff;
}
.greet-wrap::before {
  content: '';
  position: absolute;
  top: -40px; right: -40px;
  width: 200px; height: 200px;
  border-radius: 50%;
  background: rgba(255,255,255,.06);
}
.greet-wrap::after {
  content: '';
  position: absolute;
  bottom: -60px; left: -30px;
  width: 250px; height: 250px;
  border-radius: 50%;
  background: rgba(255,255,255,.04);
}
.greet-name { font-size: 1.5rem; font-weight: 800; letter-spacing: -.01em; }
.greet-role { font-size: .85rem; opacity: .75; margin-top: 4px; }
.greet-time { font-size: .8rem; opacity: .65; margin-top: 2px; font-family: monospace; }

/* ══ FORM LAYOUT ═════════════════════════════════════════════════════════ */
.clock-bar {
  background: linear-gradient(135deg,#1e3a8a,#2563eb);
  color: #fff; border-radius: 10px; padding: 12px 16px;
  margin-bottom: 16px;
  display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;
}
.clock-time { font-size: 1.4rem; font-weight: 800; font-family: monospace; }
.clock-date { font-size: .76rem; color: #93c5fd; }

.form-section {
  background: var(--c-gray-50);
  border: 1.5px solid var(--c-gray-100);
  border-radius: 10px;
  padding: 16px;
  margin-bottom: 14px;
}
.sec-title {
  font-size: .68rem; font-weight: 700;
  letter-spacing: .08em; text-transform: uppercase;
  color: var(--c-gray-400); margin-bottom: 12px;
}
.cust-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 10px;
}
.field-card {
  background: #fff;
  border: 1px solid var(--c-gray-200);
  border-radius: 8px;
  padding: 10px 12px;
  transition: border-color .15s;
}
.field-card:focus-within { border-color: var(--c-primary); }
.field-card .fc-label { font-size: .68rem; color: var(--c-gray-400); font-weight: 600; margin-bottom: 3px; }
.field-card input,
.field-card select {
  width: 100%; border: none; outline: none;
  font-size: .86rem; background: transparent;
  color: var(--c-gray-800); padding: 0;
}

/* ══ STATUS DROPDOWN ═════════════════════════════════════════════════════ */
.status-section {
  background: var(--c-gray-50);
  border: 1.5px solid var(--c-gray-100);
  border-radius: 10px;
  padding: 14px 16px;
  margin-bottom: 14px;
}
.status-select-wrap {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.status-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin-top: 6px;
  padding: 4px 12px;
  border-radius: 20px;
  font-size: .75rem;
  font-weight: 700;
  display: none;
}
.status-badge.show { display: inline-flex; }
.status-badge.enquiry  { background: #dbeafe; color: #1d4ed8; }
.status-badge.billed   { background: #dcfce7; color: #15803d; }
.status-badge.outside  { background: #fef3c7; color: #b45309; }
.status-badge.other    { background: #f3e8ff; color: #7e22ce; }

.reason-box { display: none; margin-top: 8px; }
.reason-box.show { display: block; }
.reason-box textarea {
  width: 100%;
  border: 1.5px solid var(--c-gray-200);
  border-radius: 8px;
  padding: 9px 12px;
  font-size: .84rem;
  color: var(--c-gray-800);
  font-family: inherit;
  resize: vertical;
  min-height: 72px;
  outline: none;
  transition: border-color .15s;
  background: #fff;
  box-sizing: border-box;
}
.reason-box textarea:focus { border-color: var(--c-primary); }

/* ══ VISIT DATE ══════════════════════════════════════════════════════════ */
.visit-date-section {
  background: linear-gradient(135deg,#f0f9ff,#e0f2fe);
  border: 1.5px solid #7dd3fc;
  border-radius: 10px;
  padding: 14px;
  margin-top: 14px;
}
.visit-shortcuts { display: flex; gap: 5px; flex-wrap: wrap; margin-top: 7px; }
.visit-shortcut {
  padding: 3px 9px; border: 1px solid #7dd3fc; border-radius: 20px;
  background: #fff; cursor: pointer; font-size: .72rem;
  color: #0369a1; font-weight: 600; font-family: inherit;
  transition: all .12s;
}
.visit-shortcut:hover { background: #0284c7; color: #fff; border-color: #0284c7; }

/* ══ AREA ROWS ═══════════════════════════════════════════════════════════ */
.area-row-item {
  background: #fff;
  border: 1px solid var(--c-gray-200);
  border-radius: 10px;
  padding: 12px 14px;
  margin-bottom: 10px;
}
.area-fields {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
  gap: 8px;
}
.area-calc-box {
  background: linear-gradient(135deg,#f0fdf4,#fffbeb);
  border: 1.5px solid #bbf7d0;
  border-radius: 8px;
  padding: 8px 12px;
  margin-top: 8px;
  display: none;
}
.add-area-btn {
  display: flex; align-items: center; justify-content: center; gap: 6px;
  padding: 9px; border: 1.5px dashed var(--c-gray-300); border-radius: 8px;
  background: none; cursor: pointer; font-size: .8rem; color: var(--c-gray-500);
  width: 100%; transition: all .15s; margin-top: 4px;
}
.add-area-btn:hover { border-color: var(--c-primary); color: var(--c-primary); }

/* ══ SUCCESS / DUP ══════════════════════════════════════════════════════ */
.success-card {
  background: linear-gradient(135deg,#f0fdf4,#ecfdf5);
  border: 2px solid #4ade80; border-radius: 12px;
  padding: 18px; margin-bottom: 14px;
}
.dup-banner {
  background: #fffbeb; border: 1.5px solid #f59e0b;
  border-radius: 10px; padding: 14px; margin-bottom: 14px;
}

/* ══ TOAST ══════════════════════════════════════════════════════════════ */
#validationToast {
  position: fixed; top: 20px; left: 50%;
  transform: translateX(-50%) translateY(-80px);
  background: #dc2626; color: #fff;
  padding: 10px 22px; border-radius: 10px;
  font-size: .86rem; font-weight: 600;
  box-shadow: 0 8px 28px rgba(0,0,0,.2);
  z-index: 9999; transition: transform .35s, opacity .3s;
  opacity: 0; pointer-events: none;
  white-space: nowrap; max-width: 90vw; text-align: center;
}
#validationToast.show { transform: translateX(-50%) translateY(0); opacity: 1; }

@media(max-width:640px) { .greet-name { font-size: 1.2rem; } }
</style>

<!-- Validation Toast -->
<div id="validationToast"></div>

<div class="app-wrap">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="main-content">

    <?php if ($flashMsg): alert($flashMsg, $flashType); endif; ?>

    <?php if ($expiry && $expiresIn > 0 && $expiresIn < 3600): ?>
    <div class="alert alert-warn">Your password expires in <strong><?= round($expiresIn / 60) ?> minutes</strong>. Contact main admin.</div>
    <?php elseif ($expiry && strtotime($expiry) < time()): ?>
    <div class="alert alert-error">Your password has expired. Contact main admin to renew.</div>
    <?php endif; ?>

    <!-- ══ GREETING ═══════════════════════════════════════════════════════ -->
    <div class="greet-wrap">
      <div style="position:relative;z-index:1">
        <div class="greet-name">👋 Welcome back, <?= htmlspecialchars($user['username']) ?>!</div>
        <div class="greet-role">Sales Executive — Aandavar FieldApp</div>
        <div class="greet-time" id="greet-clock">Loading time…</div>
      </div>
    </div>

    <!-- ══ SUCCESS CARD ═══════════════════════════════════════════════════ -->
    <?php if ($msg === 'submission_success'): ?>
    <div class="success-card">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
        <div style="font-size:2rem">✅</div>
        <div>
          <div style="font-weight:700;color:#15803d">Submission Saved Successfully!</div>
          <div style="font-size:.82rem;color:#16a34a">Data has been recorded in the database.</div>
        </div>
      </div>
      <?php if ($submittedTimeDisplay): ?>
      <div style="background:#fff;border:1.5px solid #86efac;border-radius:8px;padding:10px 14px;margin-bottom:8px">
        <div style="font-size:.68rem;text-transform:uppercase;letter-spacing:.07em;color:#4ade80;font-weight:700;margin-bottom:2px">📅 Submission Time</div>
        <div style="font-weight:800;color:#15803d;font-family:monospace"><?= htmlspecialchars($submittedTimeDisplay) ?></div>
        <div style="font-size:.72rem;color:#86efac;margin-top:2px">Recorded in IST</div>
      </div>
      <?php endif; ?>
      <?php if ($effectiveTime): ?>
      <div style="background:#fff;border:1.5px solid #bfdbfe;border-radius:8px;padding:10px 14px;margin-bottom:8px">
        <div style="font-size:.68rem;text-transform:uppercase;letter-spacing:.07em;color:#3b82f6;font-weight:700;margin-bottom:2px">⏱ Effective Working Time</div>
        <div style="font-weight:800;color:#1e40af;font-family:monospace"><?= htmlspecialchars($effectiveTime) ?></div>
      </div>
      <?php endif; ?>
      <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">
        <button class="btn btn-primary" onclick="resetForm()">
          <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
          New Submission
        </button>
      </div>
    </div>
    <?php endif; ?>

    <!-- ══ DUPLICATE WARNING ═══════════════════════════════════════════════ -->
    <?php if ($dupWarning): ?>
    <div class="dup-banner">
      <div style="font-weight:700;color:#92400e;margin-bottom:6px">⚠️ Duplicate Phone Detected</div>
      <div style="font-size:.82rem;color:#78350f;margin-bottom:10px">
        <strong><?= htmlspecialchars($dupWarning['owner_name']) ?></strong>
        — <?= htmlspecialchars($dupWarning['village_city_town']) ?>, <?= htmlspecialchars($dupWarning['street']) ?><br>
        Submitted by <strong><?= htmlspecialchars($dupWarning['username']) ?></strong>
        on <?= date('d M Y', strtotime($dupWarning['device_submitted_at'] ?: $dupWarning['submitted_at'])) ?>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn btn-ghost btn-sm" onclick="resetForm()">← Go Back</button>
        <button class="btn btn-sm" style="background:#f59e0b;color:#fff;border:none" onclick="submitForce()">Submit Anyway</button>
      </div>
    </div>
    <?php endif; ?>

    <!-- ══ LIVE CLOCK ══════════════════════════════════════════════════════ -->
    <div class="clock-bar">
      <div>
        <div style="font-size:.65rem;text-transform:uppercase;letter-spacing:.1em;color:#bfdbfe;font-weight:600;margin-bottom:2px">Current Time (IST)</div>
        <div class="clock-time" id="live-clock">--:--:--</div>
        <div class="clock-date" id="live-date">Loading…</div>
      </div>
    </div>

    <!-- ══ SUBMISSION FORM ═════════════════════════════════════════════════ -->
    <div class="card">
      <form method="POST" id="mainForm">
        <input type="hidden" name="csrf"                value="<?= csrfToken() ?>">
        <input type="hidden" name="action"              value="quick_submit">
        <input type="hidden" name="device_submitted_at" id="device_submitted_at">
        <input type="hidden" name="unlock_time_iso"     id="unlock_time_iso">
        <input type="hidden" name="force_submit"        id="force_submit" value="0">
        <input type="hidden" name="owner_name"          id="h_owner_name"   value="<?= htmlspecialchars($_POST['owner_name'] ?? '') ?>">
        <input type="hidden" name="owner_number"        id="h_owner_number" value="<?= htmlspecialchars($_POST['owner_number'] ?? '') ?>">
        <input type="hidden" name="representative_name"   id="h_rep_name"   value="<?= htmlspecialchars($prefillRep ?: ($_POST['representative_name'] ?? '')) ?>">
        <input type="hidden" name="representative_number" id="h_rep_number" value="<?= htmlspecialchars($prefillRepN ?: ($_POST['representative_number'] ?? '')) ?>">
        <input type="hidden" name="village_city_town"   id="h_village"      value="<?= htmlspecialchars($_POST['village_city_town'] ?? '') ?>">
        <input type="hidden" name="street"              id="h_street"       value="<?= htmlspecialchars($_POST['street'] ?? '') ?>">

        <!-- Customer Details -->
        <div class="form-section">
          <div class="sec-title">Customer Details</div>
          <div class="cust-grid">
            <div class="field-card">
              <div class="fc-label">Owner Name <span style="color:#dc2626">*</span></div>
              <input type="text" id="g_owner_name" placeholder="Full name"
                     value="<?= htmlspecialchars($_POST['owner_name'] ?? '') ?>"
                     oninput="syncHidden('h_owner_name',this.value)">
            </div>
            <div class="field-card">
              <div class="fc-label">Owner Phone <span style="color:#dc2626">*</span></div>
              <input type="tel" id="g_owner_number" placeholder="+91 XXXXX XXXXX"
                     value="<?= htmlspecialchars($_POST['owner_number'] ?? '') ?>"
                     oninput="syncHidden('h_owner_number',this.value)">
            </div>
            <div class="field-card">
              <div class="fc-label">Influencer / Rep Name <span style="color:#dc2626">*</span></div>
              <input type="text" id="g_rep_name" placeholder="Representative name"
                     value="<?= htmlspecialchars($prefillRep ?: ($_POST['representative_name'] ?? '')) ?>"
                     oninput="syncHidden('h_rep_name',this.value)">
            </div>
            <div class="field-card">
              <div class="fc-label">Influencer Phone <span style="color:#dc2626">*</span></div>
              <input type="tel" id="g_rep_number" placeholder="+91 XXXXX XXXXX"
                     value="<?= htmlspecialchars($prefillRepN ?: ($_POST['representative_number'] ?? '')) ?>"
                     oninput="syncHidden('h_rep_number',this.value)">
            </div>
            <div class="field-card">
              <div class="fc-label">Village / City / Town <span style="color:#dc2626">*</span></div>
              <input type="text" id="g_village" placeholder="e.g. Gandhi Nagar"
                     value="<?= htmlspecialchars($_POST['village_city_town'] ?? '') ?>"
                     oninput="syncHidden('h_village',this.value)">
            </div>
            <div class="field-card">
              <div class="fc-label">Street <span style="color:#dc2626">*</span></div>
              <input type="text" id="g_street" placeholder="Street / Road name"
                     value="<?= htmlspecialchars($_POST['street'] ?? '') ?>"
                     oninput="syncHidden('h_street',this.value)">
            </div>
          </div>
        </div>

        <div class="divider"></div>

        <!-- Area Details -->
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <div style="font-size:.82rem;font-weight:600;color:var(--c-gray-600)">AREA DETAILS</div>
          <span style="font-size:.7rem;color:#dc2626;font-weight:600">* Required</span>
        </div>
        <div id="area-rows"></div>
        <button type="button" class="add-area-btn" onclick="addAreaRow()">
          <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
          Add Area
        </button>

        <div class="divider"></div>

        <!-- Status Dropdown -->
        <div class="status-section">
          <div class="sec-title" style="margin-bottom:10px">STATUS <span style="color:#dc2626">*</span></div>
          <div class="status-select-wrap">
            <div>
              <select class="form-control" name="status" id="status_select" onchange="handleStatusChange(this.value)" required>
                <option value="">— Select Status —</option>
                <option value="enquiry"          <?= (($_POST['status'] ?? '') === 'enquiry')          ? 'selected' : '' ?>>🔵 Enquiry</option>
                <option value="billed"           <?= (($_POST['status'] ?? '') === 'billed')           ? 'selected' : '' ?>>🟢 Billed</option>
                <option value="outside_purchase" <?= (($_POST['status'] ?? '') === 'outside_purchase') ? 'selected' : '' ?>>🟡 Outside Purchase</option>
                <option value="others"           <?= (($_POST['status'] ?? '') === 'others')           ? 'selected' : '' ?>>🟣 Other</option>
              </select>
              <div id="status_badge" class="status-badge"></div>
            </div>
            <div class="reason-box" id="reason_box">
              <label style="font-size:.72rem;font-weight:700;color:var(--c-gray-500);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:5px">
                Reason <span id="reason_required_mark" style="color:#dc2626;display:none">*</span>
                <span id="reason_hint" style="font-weight:400;text-transform:none;opacity:.7"></span>
              </label>
              <textarea name="status_reason" id="status_reason"
                        placeholder="Enter reason or additional notes…"><?= htmlspecialchars($_POST['status_reason'] ?? '') ?></textarea>
            </div>
          </div>
        </div>

        <div class="divider"></div>

        <!-- Zone -->
        <div class="form-group">
          <label class="form-label">Zone / Sub-direction <span style="color:#dc2626">*</span></label>
          <select class="form-control" name="sub_direction_id" required>
            <option value="">Select zone</option>
            <?php foreach ($allZones as $z): ?>
            <option value="<?= $z['id'] ?>"
              <?= (($_POST['sub_direction_id'] ?? '') == $z['id']) ? 'selected' : '' ?>>
              <?= $z['dir_code'] ?> › <?= htmlspecialchars($z['code']) ?>
              — <?= htmlspecialchars($z['from_location']) ?> → <?= htmlspecialchars($z['to_location']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="divider"></div>

        <!-- Next Visit Date -->
        <div class="visit-date-section">
          <div style="font-size:.68rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#0369a1;margin-bottom:10px">
            📅 Next Visit Date <span style="font-weight:400;text-transform:none;opacity:.7">(optional)</span>
          </div>
          <input type="date" class="form-control" name="next_visit_date" id="next_visit_date"
                 min="<?= $todayISO ?>"
                 value="<?= htmlspecialchars($_POST['next_visit_date'] ?? '') ?>"
                 onchange="updateVisitPreview()">
          <div class="visit-shortcuts">
            <button type="button" class="visit-shortcut" onclick="setVisitDate('<?= $tomorrowISO ?>')">Tomorrow</button>
            <button type="button" class="visit-shortcut" onclick="setVisitDate('<?= date('Y-m-d',strtotime('+3 days')) ?>')">+3 Days</button>
            <button type="button" class="visit-shortcut" onclick="setVisitDate('<?= $nextWeekISO ?>')">Next Week</button>
            <button type="button" class="visit-shortcut" onclick="setVisitDate('<?= date('Y-m-d',strtotime('+1 month')) ?>')">+1 Month</button>
            <button type="button" class="visit-shortcut"
                    style="border-color:#f87171;color:#dc2626"
                    onclick="document.getElementById('next_visit_date').value='';document.getElementById('visit_preview').innerHTML=''">✕ Clear</button>
          </div>
          <div id="visit_preview" style="font-size:.78rem;color:#0369a1;font-weight:600;margin-top:6px;min-height:18px"></div>
        </div>

        <!-- Action Buttons -->
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:14px">
          <button type="button" class="btn btn-ghost" onclick="resetForm()">Reset</button>
          <button type="submit" class="btn btn-primary" id="submitBtn">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
            Submit
          </button>
        </div>

      </form>
    </div>

  </div><!-- /main-content -->
</div><!-- /app-wrap -->

<nav class="mob-nav">
  <div class="mob-nav-items">
    <a href="dashboard.php" class="mob-nav-item active">
      <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>Home
    </a>
  </div>
</nav>

<?php pageFooter(); ?>

<script>
/* ── CLOCKS ──────────────────────────────────────────────────────────────── */
const DAYS   = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
const MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

function pad(n) { return String(n).padStart(2, '0'); }

function fmtFull(d) {
  const h = d.getHours() % 12 || 12;
  const ap = d.getHours() >= 12 ? 'PM' : 'AM';
  return pad(h) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds()) + ' ' + ap;
}

function istParts() {
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

function nowISO() {
  const n = istParts();
  return n.year + '-' + n.month + '-' + n.day
       + 'T' + n.hour + ':' + n.minute + ':' + n.second;
}

function nowISTDate() {
  const n = istParts();
  return new Date(Number(n.year), Number(n.month) - 1, Number(n.day), Number(n.hour), Number(n.minute), Number(n.second));
}

function updateClock() {
  const now = nowISTDate();
  const gc = document.getElementById('greet-clock');
  if (gc) gc.textContent = DAYS[now.getDay()] + ', ' + now.getDate() + ' ' + MONTHS[now.getMonth()] + ' ' + now.getFullYear() + ' — ' + fmtFull(now);
  const lc = document.getElementById('live-clock');
  if (lc) lc.textContent = fmtFull(now);
  const ld = document.getElementById('live-date');
  if (ld) ld.textContent = DAYS[now.getDay()] + ', ' + now.getDate() + ' ' + MONTHS[now.getMonth()] + ' ' + now.getFullYear();
}
updateClock();
setInterval(updateClock, 1000);

/* ── HIDDEN FIELD SYNC ───────────────────────────────────────────────────── */
function syncHidden(id, val) {
  const el = document.getElementById(id);
  if (el) el.value = val;
}

/* ── TOAST ───────────────────────────────────────────────────────────────── */
let toastTimer = null;
function showToast(msg) {
  const t = document.getElementById('validationToast');
  t.textContent = msg;
  t.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => t.classList.remove('show'), 3800);
}

/* ── STATUS DROPDOWN ─────────────────────────────────────────────────────── */
const STATUS_CONFIG = {
  'enquiry':          { cls: 'enquiry', emoji: '🔵', label: 'Enquiry',          hint: '(optional notes)',        required: false },
  'billed':           { cls: 'billed',  emoji: '🟢', label: 'Billed',           hint: '(optional notes)',        required: false },
  'outside_purchase': { cls: 'outside', emoji: '🟡', label: 'Outside Purchase', hint: '— reason required',       required: true  },
  'others':           { cls: 'other',   emoji: '🟣', label: 'Other',            hint: '— please specify reason', required: true  },
};

function handleStatusChange(val) {
  const badge     = document.getElementById('status_badge');
  const reasonBox = document.getElementById('reason_box');
  const reqMark   = document.getElementById('reason_required_mark');
  const hintEl    = document.getElementById('reason_hint');
  const textarea  = document.getElementById('status_reason');

  badge.className = 'status-badge';

  if (!val || !STATUS_CONFIG[val]) {
    badge.classList.remove('show');
    reasonBox.classList.remove('show');
    return;
  }

  const cfg = STATUS_CONFIG[val];
  badge.classList.add('show', cfg.cls);
  badge.innerHTML = cfg.emoji + ' ' + cfg.label;

  reasonBox.classList.add('show');
  reqMark.style.display = cfg.required ? 'inline' : 'none';
  hintEl.textContent    = cfg.hint;
  textarea.placeholder  = cfg.required ? 'Required — please enter reason…' : 'Optional notes…';
}

(function () {
  const sel = document.getElementById('status_select');
  if (sel && sel.value) handleStatusChange(sel.value);
})();

/* ── VISIT DATE ──────────────────────────────────────────────────────────── */
function setVisitDate(iso) {
  document.getElementById('next_visit_date').value = iso;
  updateVisitPreview();
}

function updateVisitPreview() {
  const val = document.getElementById('next_visit_date').value;
  const el  = document.getElementById('visit_preview');
  if (!el) return;
  if (!val) { el.innerHTML = ''; return; }
  const parts = val.split('-');
  const d     = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
  const today = new Date(); today.setHours(0, 0, 0, 0);
  const diff  = Math.round((d - today) / 86400000);
  const label = diff === 0 ? 'today'
              : diff === 1 ? 'tomorrow'
              : diff > 0  ? 'in ' + diff + ' days'
              : Math.abs(diff) + ' days ago';
  el.innerHTML = '📅 ' + DAYS[d.getDay()] + ', ' + d.getDate() + ' ' + MONTHS[d.getMonth()] + ' ' + d.getFullYear()
    + ' <span style="background:#0284c7;color:#fff;font-size:.7rem;padding:1px 8px;border-radius:10px;font-weight:700">' + label + '</span>';
}

/* ── AREA ROWS ───────────────────────────────────────────────────────────── */
const AREA_TYPES = [
  'Residential',
  'Commercial',
  'Apartment',
  'Govt Projects',
];

let areaIdx = 0;

function addAreaRow() {
  const idx  = areaIdx++;
  const opts = AREA_TYPES.map(t => `<option value="${t}">${t}</option>`).join('');
  const div  = document.createElement('div');
  div.className = 'area-row-item';
  div.id = 'ar-' + idx;
  div.innerHTML = `
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
      <span class="area-num-label" style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--c-primary);background:#eff6ff;padding:2px 8px;border-radius:4px">Area ${idx + 1}</span>
      <button type="button" onclick="this.closest('.area-row-item').remove();renumberAreas()"
              style="padding:3px 8px;border:1px solid var(--c-gray-200);border-radius:6px;background:none;cursor:pointer;font-size:.76rem;color:var(--c-gray-400)">
        ✕ Remove
      </button>
    </div>
    <div class="area-fields">
      <div class="form-group" style="margin:0">
        <label class="form-label" style="font-size:.72rem">Property Type <span style="color:#dc2626">*</span></label>
        <select class="form-control area-req" name="area_type[]">
          <option value="">Select…</option>${opts}
        </select>
      </div>
      <div class="form-group" style="margin:0">
        <label class="form-label" style="font-size:.72rem">Length (ft) <span style="color:#dc2626">*</span></label>
        <input class="form-control area-req" type="number" name="area_length[]"
               id="alen-${idx}" min="0" step="0.01" placeholder="e.g. 40"
               oninput="calcSqft(${idx})">
      </div>
      <div class="form-group" style="margin:0">
        <label class="form-label" style="font-size:.72rem">Breadth (ft) <span style="color:#dc2626">*</span></label>
        <input class="form-control area-req" type="number" name="area_breadth[]"
               id="ab-${idx}" min="0" step="0.01" placeholder="e.g. 60"
               oninput="calcSqft(${idx})">
      </div>
      <div class="form-group" style="margin:0">
        <label class="form-label" style="font-size:.72rem">Bathroom Count <span style="color:#dc2626">*</span></label>
        <input class="form-control area-req" type="number" name="area_count[]"
               min="0" step="1" placeholder="e.g. 2">
      </div>
    </div>
    <div class="area-calc-box" id="acalc-${idx}">
      <span style="font-weight:700;color:#16a34a;font-family:monospace" id="acalc-res-${idx}">0 sqft</span>
      <span style="font-size:.72rem;color:#4ade80;margin-left:10px" id="acalc-formula-${idx}"></span>
    </div>`;
  document.getElementById('area-rows').appendChild(div);
  renumberAreas();
}

function renumberAreas() {
  document.querySelectorAll('.area-row-item').forEach((el, i) => {
    const lbl = el.querySelector('.area-num-label');
    if (lbl) lbl.textContent = 'Area ' + (i + 1);
  });
}

function calcSqft(idx) {
  const l   = parseFloat(document.getElementById('alen-' + idx)?.value) || 0;
  const b   = parseFloat(document.getElementById('ab-'   + idx)?.value) || 0;
  const box = document.getElementById('acalc-' + idx);
  if (!box) return;
  if (l > 0 && b > 0) {
    const t = (l * b).toFixed(2);
    box.style.display = 'block';
    document.getElementById('acalc-res-'     + idx).textContent = parseFloat(t).toLocaleString() + ' sqft';
    document.getElementById('acalc-formula-' + idx).textContent = l + ' × ' + b + ' ft';
  } else {
    box.style.display = 'none';
  }
}

// Start with one area row
addAreaRow();

/* ── FORM SUBMIT ─────────────────────────────────────────────────────────── */
document.getElementById('mainForm').addEventListener('submit', function (e) {
  // Stamp device time
  document.getElementById('device_submitted_at').value = nowISO();

  // Sync visible → hidden
  document.getElementById('h_owner_name').value   = document.getElementById('g_owner_name').value.trim();
  document.getElementById('h_owner_number').value = document.getElementById('g_owner_number').value.trim();
  document.getElementById('h_rep_name').value     = document.getElementById('g_rep_name').value.trim();
  document.getElementById('h_rep_number').value   = document.getElementById('g_rep_number').value.trim();
  document.getElementById('h_village').value      = document.getElementById('g_village').value.trim();
  document.getElementById('h_street').value       = document.getElementById('g_street').value.trim();

  // Required field validation
  const missing = [];
  if (!document.getElementById('h_owner_name').value)   missing.push('Owner Name');
  if (!document.getElementById('h_owner_number').value) missing.push('Owner Phone');
  if (!document.getElementById('h_rep_name').value)     missing.push('Rep Name');
  if (!document.getElementById('h_rep_number').value)   missing.push('Rep Phone');
  if (!document.getElementById('h_village').value)      missing.push('Village/City');
  if (!document.getElementById('h_street').value)       missing.push('Street');

  const statusVal = document.getElementById('status_select').value;
  if (!statusVal) {
    missing.push('Status');
  } else if (['outside_purchase', 'others'].includes(statusVal)) {
    const reason = document.getElementById('status_reason').value.trim();
    if (!reason) missing.push('Reason for ' + statusVal);
  }

  let areaOk = true;
  document.querySelectorAll('.area-req').forEach(inp => {
    if (!(inp.value || '').trim()) {
      inp.style.borderColor = '#dc2626';
      areaOk = false;
    } else {
      inp.style.borderColor = '';
    }
  });
  if (!areaOk) missing.push('Area fields');

  if (missing.length) {
    e.preventDefault();
    showToast('⚠ Required: ' + missing[0]);
  }
});

function submitForce() {
  document.getElementById('force_submit').value        = 1;
  document.getElementById('device_submitted_at').value = nowISO();
  document.getElementById('mainForm').submit();
}

/* ── RESET ───────────────────────────────────────────────────────────────── */
function resetForm() {
  ['g_owner_name','g_owner_number','g_rep_name','g_rep_number','g_village','g_street',
   'h_owner_name','h_owner_number','h_rep_name','h_rep_number','h_village','h_street']
    .forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });

  document.getElementById('area-rows').innerHTML = '';
  areaIdx = 0;
  addAreaRow();

  document.getElementById('force_submit').value      = '0';
  document.getElementById('next_visit_date').value   = '';
  document.getElementById('visit_preview').innerHTML = '';

  document.getElementById('status_select').value = '';
  handleStatusChange('');
  document.getElementById('status_reason').value = '';
}
</script>
