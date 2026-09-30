<?php
// ============================================================
// office_admin/reports.php — v5.6
//
// ESTIMATE PRICING MODEL (aligned with print_submission v7.1
// and estimate_modal v4):
//
//   MRP Total    = Boxes × MRP/box
//   MOP Total    = Boxes × MOP/box
//   Disc%        = 100 − ((MOP/box ÷ MRP/box) × 100)
//   After-Offer  = Total × (1 − Offer%/100)
//   Grand Disc%  = 100 − (Grand MOP / Grand MRP × 100)
//
// Box counts always derived from area dimensions + tile keys.
// Saved estimate rows matched by area_id + surface.
// ============================================================
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
requireLogin('office_admin');

$db   = getDB();
$user = currentUser();
$isMainAdmin = (($user['role'] ?? '') === 'main_admin');

try {
    $db->exec("CREATE TABLE IF NOT EXISTS office_admin_reports (
        id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
        office_admin_id INT UNSIGNED NULL,
        submission_id   INT UNSIGNED NOT NULL,
        report_type     ENUM('order_completed','status_update') NOT NULL DEFAULT 'status_update',
        report_data     TEXT NULL,
        sent_to_main    TINYINT(1) NOT NULL DEFAULT 1,
        created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_oar_office_admin_id (office_admin_id),
        KEY idx_oar_submission_id (submission_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}
foreach ([
    'office_admin_id' => "ALTER TABLE office_admin_reports ADD COLUMN office_admin_id INT UNSIGNED NULL AFTER id",
    'submission_id'   => "ALTER TABLE office_admin_reports ADD COLUMN submission_id INT UNSIGNED NOT NULL AFTER office_admin_id",
    'report_type'     => "ALTER TABLE office_admin_reports ADD COLUMN report_type ENUM('order_completed','status_update') NOT NULL DEFAULT 'status_update' AFTER submission_id",
    'report_data'     => "ALTER TABLE office_admin_reports ADD COLUMN report_data TEXT NULL AFTER report_type",
    'sent_to_main'    => "ALTER TABLE office_admin_reports ADD COLUMN sent_to_main TINYINT(1) NOT NULL DEFAULT 1 AFTER report_data",
    'created_at'      => "ALTER TABLE office_admin_reports ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER sent_to_main",
] as $col => $alterSql) {
    try {
        $chk = $db->query("SHOW COLUMNS FROM office_admin_reports LIKE '$col'")->fetch();
        if (!$chk) $db->exec($alterSql);
    } catch (Throwable $e) {}
}

$today    = date('Y-m-d');
$in10Days = date('Y-m-d', strtotime('+10 days'));

// ── Filters ──────────────────────────────────────────────────────────────────
$filterOA       = $isMainAdmin ? (int)($_GET['office_admin_id'] ?? 0) : (int)$user['id'];
$filterType     = trim($_GET['report_type'] ?? '');
$filterDateFrom = trim($_GET['date_from']    ?? '');
$filterDateTo   = trim($_GET['date_to']      ?? '');
$filterStatus   = trim($_GET['status']       ?? '');
$filterPhone    = trim($_GET['phone']        ?? '');

$where  = ['1=1'];
$params = [];
$hasAltMobile = columnExists('submissions', 'alt_mobile');
$altMobileSelect = $hasAltMobile ? 's.alt_mobile' : "'' AS alt_mobile";
if ($filterOA)       { $where[] = 'r.office_admin_id=?';   $params[] = $filterOA; }
if ($filterType)     { $where[] = 'r.report_type=?';        $params[] = $filterType; }
if ($filterDateFrom) { $where[] = 'DATE(r.created_at)>=?'; $params[] = $filterDateFrom; }
if ($filterDateTo)   { $where[] = 'DATE(r.created_at)<=?'; $params[] = $filterDateTo; }
if ($filterStatus)   { $where[] = 's.status=?';             $params[] = $filterStatus; }
if ($filterPhone !== '') {
    $where[]   = $hasAltMobile ? '(s.owner_number LIKE ? OR s.alt_mobile LIKE ?)' : '(s.owner_number LIKE ?)';
    $likePhone = '%' . $filterPhone . '%';
    $params[]  = $likePhone;
    if ($hasAltMobile) $params[] = $likePhone;
}

// ── Sales Executive report query.
// Sources:
//   1) office_admin/subadmins.php + update_status_modal.php -> office_admin_reports
//   2) office_admin/dashboard.php -> direct submissions by office_admin users
// Main-admin auto-linked Marketing Executive rows are hidden.
$baseSQL = "
    SELECT r.*,
           u.username  AS office_admin_name,
           s.owner_name, s.owner_number, $altMobileSelect,
           s.status AS current_status,
           s.purchase_date, s.order_completed, s.next_visit_date,
           s.village_city_town, s.street,
           s.representative_name, s.representative_number,
           s.latitude, s.longitude,
           s.landmark_picture, s.additional_picture,
           s.tile_image,
           s.device_submitted_at, s.submitted_at,
           s.unlock_time_iso,
           s.submitted_by AS sub_submitted_by,
           s.last_edited_by AS sub_last_edited_by,
           sub_user.username AS submitted_by_name,
           edit_user.username AS edited_by_name,
           sd.code AS zone_code,
           sd.from_location, sd.to_location,
           d.code  AS dir_code
    FROM (
        SELECT
            r.id,
            r.office_admin_id,
            r.submission_id,
            r.report_type,
            r.report_data,
            r.sent_to_main,
            r.created_at,
            'status_modal' AS report_source
        FROM office_admin_reports r
        JOIN (
            SELECT office_admin_id, submission_id, MAX(id) AS max_id
            FROM office_admin_reports
            WHERE office_admin_id IS NOT NULL
              AND COALESCE(report_data, '') NOT LIKE '%admin_subadmins%'
            GROUP BY office_admin_id, submission_id
        ) latest ON r.id = latest.max_id
        WHERE COALESCE(r.report_data, '') NOT LIKE '%admin_subadmins%'

        UNION ALL

        SELECT
            s.id AS id,
            s.submitted_by AS office_admin_id,
            s.id AS submission_id,
            CASE
                WHEN s.order_completed = 1 OR s.status = 'billed'
                THEN 'order_completed'
                ELSE 'status_update'
            END AS report_type,
            JSON_OBJECT(
                'source', 'dashboard',
                'new_status', s.status,
                'office_admin', ou.username
            ) AS report_data,
            1 AS sent_to_main,
            COALESCE(s.last_edited_at, s.device_submitted_at, s.submitted_at) AS created_at,
            'dashboard' AS report_source
        FROM submissions s
        JOIN users ou ON s.submitted_by = ou.id
        WHERE ou.role = 'office_admin'
          AND NOT EXISTS (
              SELECT 1
              FROM office_admin_reports existing
              WHERE existing.office_admin_id = s.submitted_by
                AND existing.submission_id = s.id
                AND COALESCE(existing.report_data, '') NOT LIKE '%admin_subadmins%'
          )
    ) r
    JOIN users u           ON r.office_admin_id = u.id
    JOIN submissions s     ON r.submission_id   = s.id
    JOIN sub_directions sd ON s.sub_direction_id = sd.id
    JOIN directions d      ON sd.direction_id    = d.id
    LEFT JOIN users sub_user  ON sub_user.id  = s.submitted_by
    LEFT JOIN users edit_user ON edit_user.id = s.last_edited_by
    WHERE u.role = 'office_admin'
      AND " . implode(' AND ', $where) . "
    ORDER BY r.created_at DESC";

/* ── PHP helpers ─────────────────────────────────────────────────────────── */
function oaImgUrl(string $f): string {
    if (!$f) return '';
    return rtrim(UPLOAD_URL, '/') . '/' . ltrim($f, '/');
}

/* ── Excel Export ─────────────────────────────────────────────────────────── */
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $stmt = $db->prepare($baseSQL);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $subIds = array_unique(array_column($rows, 'submission_id'));
    $detailsMap = [];
    if ($subIds) {
        $inList = implode(',', array_fill(0, count($subIds), '?'));
        $dStmt  = $db->prepare("SELECT * FROM submission_status_details WHERE submission_id IN ($inList)");
        $dStmt->execute($subIds);
        foreach ($dStmt->fetchAll() as $d) $detailsMap[$d['submission_id']] = $d;
    }
    $fname = 'office_admin_reports_' . date('Y-m-d_His') . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Cache-Control: max-age=0');
    echo "\xEF\xBB\xBF";
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head><meta charset="UTF-8">
<!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>Reports</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->
<style>body{font-family:Arial,sans-serif;font-size:11px}table{border-collapse:collapse;width:100%}th{background:#1d4ed8;color:#fff;font-weight:bold;padding:8px 10px;text-align:left;white-space:nowrap;border:1px solid #1e40af;font-size:11px}td{padding:6px 10px;border:1px solid #e5e7eb;vertical-align:top;font-size:11px}tr:nth-child(even) td{background:#f8fafc}.title-row td{background:#1e3a5f;color:#fff;font-size:14px;font-weight:bold;text-align:center;padding:12px}.meta-row td{background:#f1f5f9;color:#475569;font-size:10px;padding:4px 10px}.bold{font-weight:bold}</style>
</head><body><table>
<tr class="title-row"><td colspan="22">Sales Executive Activity Report — <?= date('d M Y, h:i A') ?></td></tr>
<tr class="meta-row"><td colspan="22">Total Records: <?= count($rows) ?></td></tr>
<tr><th>#</th><th>Date</th><th>Time</th><th>Sales Executive</th><th>Report Type</th><th>Owner Name</th><th>Owner Phone</th><th>Alt Mobile</th><th>Representative</th><th>Rep Phone</th><th>Village/City</th><th>Street</th><th>Zone</th><th>Current Status</th><th>Old Status</th><th>New Status</th><th>Order Completed</th><th>Purchase Date</th><th>Deal Result</th><th>Vendor</th><th>Notes</th><th>Next Visit Date</th></tr>
<?php foreach ($rows as $i => $r):
    $detail = $detailsMap[$r['submission_id']] ?? null;
    $rd = @json_decode($r['report_data'] ?? '', true) ?: [];
    $nvd = $r['next_visit_date'] ?? ($detail['next_visit_date'] ?? null);
    $visitLabel = $nvd ? date('d M Y', strtotime($nvd)) : '';
    if ($nvd && $nvd < $today) $visitLabel = 'OVERDUE — '.$visitLabel;
    elseif ($nvd && $nvd <= $in10Days) $visitLabel = 'UPCOMING — '.$visitLabel;
    $notes = [];
    if ($detail && $detail['detail_description']) $notes[] = $detail['detail_description'];
    if ($detail && $detail['other_reason'])       $notes[] = 'Note: '.$detail['other_reason'];
    if (isset($rd['purchase_notes']) && $rd['purchase_notes']) $notes[] = 'Purchase: '.$rd['purchase_notes'];
?>
<tr>
    <td><?= $i+1 ?></td><td><?= date('d M Y', strtotime($r['created_at'])) ?></td><td><?= date('h:i:s A', strtotime($r['created_at'])) ?></td>
    <td class="bold"><?= htmlspecialchars($r['office_admin_name']) ?></td>
    <td><?= $r['report_type']==='order_completed'?'Order Completed':'Status Update' ?></td>
    <td class="bold"><?= htmlspecialchars($r['owner_name']) ?></td>
    <td style="mso-number-format:'\@'"><?= htmlspecialchars($r['owner_number']) ?></td>
    <td style="mso-number-format:'\@'"><?= htmlspecialchars($r['alt_mobile'] ?? '') ?></td>
    <td><?= htmlspecialchars($r['representative_name'] ?? '') ?></td>
    <td style="mso-number-format:'\@'"><?= htmlspecialchars($r['representative_number'] ?? '') ?></td>
    <td><?= htmlspecialchars($r['village_city_town']) ?></td><td><?= htmlspecialchars($r['street']) ?></td>
    <td><?= htmlspecialchars($r['dir_code'].' › '.$r['zone_code']) ?></td>
    <td><?= strtoupper(htmlspecialchars($r['current_status'])) ?></td>
    <td><?= htmlspecialchars($rd['old_status'] ?? '') ?></td><td><?= htmlspecialchars($rd['new_status'] ?? '') ?></td>
    <td><?= $r['order_completed'] ? 'Yes' : 'No' ?></td>
    <td><?= $r['purchase_date'] ? date('d M Y', strtotime($r['purchase_date'])) : '' ?></td>
    <td><?= htmlspecialchars($detail['deal_result'] ?? ($rd['deal_result'] ?? '')) ?></td>
    <td><?= htmlspecialchars($detail['vendor_name'] ?? '') ?></td>
    <td><?= htmlspecialchars(implode(' | ', $notes)) ?></td>
    <td><?= htmlspecialchars($visitLabel) ?></td>
</tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="22" style="text-align:center;padding:20px;color:#9ca3af">No records found.</td></tr><?php endif; ?>
</table></body></html>
<?php exit; }

/* ── Main data fetch ─────────────────────────────────────────────────────── */
$reportLoadError = '';
try {
    $stmt = $db->prepare($baseSQL);
    $stmt->execute($params);
    $reports = $stmt->fetchAll();
} catch (Throwable $e) {
    $reports = [];
    $reportLoadError = $e->getMessage();
}

$subIds      = array_unique(array_column($reports, 'submission_id'));
$detailsMap  = [];
$areasMap    = [];
$areaImgsMap = [];
$snapsMap    = [];
$estimateMap = [];
$estRoomsMap = [];

if ($subIds) {
    $inList = implode(',', array_fill(0, count($subIds), '?'));

    try {
        $dStmt = $db->prepare("SELECT * FROM submission_status_details WHERE submission_id IN ($inList)");
        $dStmt->execute($subIds);
        foreach ($dStmt->fetchAll() as $d) $detailsMap[$d['submission_id']] = $d;
    } catch (Throwable $e) {}

    try {
        $aStmt = $db->prepare(
            "SELECT sa.* FROM submission_areas sa
             WHERE sa.submission_id IN ($inList)
             ORDER BY sa.submission_id, sa.sort_order, sa.id"
        );
        $aStmt->execute($subIds);
        foreach ($aStmt->fetchAll() as $a) $areasMap[$a['submission_id']][] = $a;
    } catch (Throwable $e) {}

    try {
        $aiStmt = $db->prepare(
            "SELECT sai.area_id, sai.image_filename, sa.submission_id
             FROM submission_area_images sai
             JOIN submission_areas sa ON sai.area_id = sa.id
             WHERE sa.submission_id IN ($inList)
             ORDER BY sai.id"
        );
        $aiStmt->execute($subIds);
        foreach ($aiStmt->fetchAll() as $img) $areaImgsMap[$img['submission_id']][$img['area_id']][] = $img['image_filename'];
    } catch (Throwable $e) {}

    try {
        $sStmt = $db->prepare(
            "SELECT id, submission_id, filename, owner_name, notes, created_at
             FROM visualizer_snapshots
             WHERE submission_id IN ($inList)
             ORDER BY id DESC"
        );
        $sStmt->execute($subIds);
        foreach ($sStmt->fetchAll() as $sn) $snapsMap[$sn['submission_id']][] = $sn;
    } catch (Throwable $e) {}

    try {
        $eStmt = $db->prepare(
            "SELECT se.*, u.username AS created_by_name
             FROM submission_estimates se
             LEFT JOIN users u ON se.created_by = u.id
             WHERE se.submission_id IN ($inList)"
        );
        $eStmt->execute($subIds);
        foreach ($eStmt->fetchAll() as $est) $estimateMap[$est['submission_id']] = $est;
    } catch (Throwable $e) {}

    if ($estimateMap) {
        $estIds    = array_column(array_values($estimateMap), 'id');
        $estInList = implode(',', array_fill(0, count($estIds), '?'));
        try {
            $erStmt = $db->prepare(
                "SELECT ser.* FROM submission_estimate_rooms ser
                 WHERE ser.estimate_id IN ($estInList)
                 ORDER BY ser.sort_order, ser.id"
            );
            $erStmt->execute($estIds);
            foreach ($erStmt->fetchAll() as $er) {
                $estRoomsMap[$er['submission_id']][] = $er;
            }
        } catch (Throwable $e) {}
    }
}

$totalOrders = 0; $totalStatus = 0;
foreach ($reports as $r) {
    if ($r['report_type'] === 'order_completed') $totalOrders++;
    else $totalStatus++;
}

if ($isMainAdmin) {
    $officeAdmins = $db->query(
        "SELECT id, username FROM users WHERE role='office_admin' AND is_active=1 ORDER BY username"
    )->fetchAll();
} else {
    $officeAdmins = [['id' => $user['id'], 'username' => $user['username']]];
}

$exportUrl = 'reports.php?export=excel'
    . ($filterOA       ? '&office_admin_id='.$filterOA                 : '')
    . ($filterType     ? '&report_type='.urlencode($filterType)         : '')
    . ($filterDateFrom ? '&date_from='.urlencode($filterDateFrom)       : '')
    . ($filterDateTo   ? '&date_to='.urlencode($filterDateTo)           : '')
    . ($filterStatus   ? '&status='.urlencode($filterStatus)            : '')
    . ($filterPhone    ? '&phone='.urlencode($filterPhone)              : '');

$estimateModalUrl = APP_URL . '/admin/estimate_modal.php';
$printBaseUrl     = APP_URL . '/office_admin/print_submission.php';

pageHead('Office Admin Reports');
topbar($user['username'], $user['role']);
?>
<style>
/* ── Summary cards ──────────────────────────────────────────────────────── */
.oa-strip{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px}
@media(max-width:600px){.oa-strip{grid-template-columns:repeat(2,1fr)}}
.oa-card{border-radius:12px;padding:16px;display:flex;flex-direction:column;gap:3px;box-shadow:0 2px 10px rgba(0,0,0,.07);position:relative;overflow:hidden}
.oa-card .tc-num{font-size:2.2rem;font-weight:800;line-height:1;letter-spacing:-.03em}
.oa-card .tc-label{font-size:.7rem;font-weight:700;letter-spacing:.09em;text-transform:uppercase;opacity:.65}
.oa-card .tc-bar{margin-top:10px;height:3px;border-radius:99px;background:rgba(255,255,255,.15);overflow:hidden}
.oa-card .tc-fill{height:100%;border-radius:99px}
.oc-total{background:linear-gradient(140deg,#1e1b4b,#3730a3);color:#e0e7ff;border:1px solid #4338ca}
.oc-total .tc-num{color:#a5b4fc}.oc-total .tc-fill{background:#a5b4fc}
.oc-order{background:linear-gradient(140deg,#052e16,#166534);color:#dcfce7;border:1px solid #15803d}
.oc-order .tc-num{color:#4ade80}.oc-order .tc-fill{background:#4ade80}
.oc-status{background:linear-gradient(140deg,#172554,#1e40af);color:#dbeafe;border:1px solid #2563eb}
.oc-status .tc-num{color:#93c5fd}.oc-status .tc-fill{background:#93c5fd}

/* ── Type badges ────────────────────────────────────────────────────────── */
.rtype-order{display:inline-flex;align-items:center;gap:4px;background:#dcfce7;color:#15803d;padding:3px 10px;border-radius:99px;font-size:.72rem;font-weight:700;white-space:nowrap}
.rtype-status{display:inline-flex;align-items:center;gap:4px;background:#eff6ff;color:#1d4ed8;padding:3px 10px;border-radius:99px;font-size:.72rem;font-weight:700;white-space:nowrap}
.rd-chip{display:inline-flex;align-items:center;gap:6px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:3px 8px;font-size:.72rem;color:#6b7280;font-family:monospace}
.dt-cell{white-space:nowrap}
.dt-cell .dt-date{font-weight:700;font-size:.82rem;color:#1f2937}
.dt-cell .dt-time{font-size:.75rem;color:#9ca3af;display:block;margin-top:1px}

/* ── Buttons ────────────────────────────────────────────────────────────── */
.btn-excel{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;background:#16a34a;color:#fff;border:none;border-radius:6px;font-size:.8rem;font-weight:600;cursor:pointer;text-decoration:none;transition:background .15s}
.btn-excel:hover{background:#15803d;color:#fff}
.btn-print-sm{display:inline-flex;align-items:center;gap:4px;background:#0369a1;color:#fff;border:none;border-radius:5px;padding:3px 9px;font-size:.7rem;font-weight:600;cursor:pointer;text-decoration:none;transition:background .15s;white-space:nowrap}
.btn-print-sm:hover{background:#075985;color:#fff;text-decoration:none}
.btn-estimate-sm{display:inline-flex;align-items:center;gap:4px;background:#7c3aed;color:#fff;border:none;border-radius:5px;padding:3px 9px;font-size:.7rem;font-weight:600;cursor:pointer;text-decoration:none;transition:background .15s;white-space:nowrap}
.btn-estimate-sm:hover{background:#6d28d9}

/* ── View Toggle ────────────────────────────────────────────────────────── */
.view-toggle{display:inline-flex;border:1.5px solid #e5e7eb;border-radius:8px;overflow:hidden;background:#f9fafb}
.view-toggle-btn{padding:6px 13px;border:none;background:transparent;cursor:pointer;color:#6b7280;display:flex;align-items:center;gap:5px;font-size:.78rem;font-weight:600;transition:all .15s;white-space:nowrap}
.view-toggle-btn.active{background:#1d4ed8;color:#fff}
.view-toggle-btn:hover:not(.active){background:#f3f4f6;color:#374151}

/* ── Phone filter ────────────────────────────────────────────────────────── */
.phone-filter-wrap{position:relative;flex:1;min-width:130px}
.phone-filter-wrap .phone-icon{position:absolute;left:9px;top:50%;transform:translateY(-50%);color:#9ca3af;pointer-events:none;line-height:1}
.phone-filter-wrap .form-control{padding-left:28px}
.phone-filter-wrap .form-control:focus{border-color:#7c3aed;box-shadow:0 0 0 3px rgba(124,58,237,.12)}
.phone-active-badge{display:inline-flex;align-items:center;gap:5px;background:#ede9fe;color:#5b21b6;border:1px solid #c4b5fd;border-radius:6px;padding:3px 9px;font-size:.7rem;font-weight:700;margin-left:4px}
.phone-active-badge button{background:none;border:none;cursor:pointer;color:#7c3aed;font-size:.85rem;padding:0;line-height:1;margin-left:2px}

/* ── Grid cards ─────────────────────────────────────────────────────────── */
.rpt-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:14px;margin-bottom:20px}
@media(max-width:640px){.rpt-grid{grid-template-columns:1fr}}
.rpt-card{background:#fff;border:1.5px solid #e5e7eb;border-radius:14px;padding:16px;display:flex;flex-direction:column;gap:10px;box-shadow:0 1px 6px rgba(0,0,0,.05);transition:box-shadow .18s,transform .18s,border-color .18s;position:relative;overflow:hidden}
.rpt-card:hover{box-shadow:0 6px 24px rgba(0,0,0,.1);transform:translateY(-2px);border-color:#c7d2fe}
.rpt-card-stripe{position:absolute;top:0;left:0;right:0;height:3px}
.rpt-card-stripe.order{background:linear-gradient(90deg,#4ade80,#16a34a)}
.rpt-card-stripe.status{background:linear-gradient(90deg,#93c5fd,#2563eb)}
.rpt-card-top{display:flex;align-items:flex-start;justify-content:space-between;gap:8px;margin-top:4px}
.rpt-card-name{font-size:.95rem;font-weight:800;color:#111827;line-height:1.2}
.rpt-card-phone{font-size:.74rem;color:#6b7280;margin-top:2px;font-family:monospace}
.rpt-card-meta{display:flex;flex-wrap:wrap;gap:5px;align-items:center}
.rpt-card-loc{font-size:.75rem;color:#6b7280;display:flex;align-items:center;gap:4px}
.rpt-card-date{font-size:.68rem;color:#9ca3af}
.rpt-card-exec{font-size:.72rem;font-weight:600;color:#374151}
.rpt-card-actions{display:flex;gap:6px;flex-wrap:wrap;margin-top:2px;padding-top:10px;border-top:1px solid #f3f4f6}
.rpt-card-visit{font-size:.7rem;padding:4px 9px;border-radius:6px;font-weight:600;line-height:1.3}
.rpt-card-visit.overdue{background:#fee2e2;color:#b91c1c}
.rpt-card-visit.upcoming{background:#fef9c3;color:#854d0e}
.rpt-card-visit.normal{background:#eff6ff;color:#1d4ed8}
.rpt-card-est-ribbon{position:absolute;top:10px;right:-1px;background:#7c3aed;color:#fff;font-size:.58rem;font-weight:700;padding:2px 8px 2px 10px;border-radius:3px 0 0 3px;letter-spacing:.05em}

/* ── Modal sections ─────────────────────────────────────────────────────── */
.modal-sec{font-size:.68rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#9ca3af;margin:14px 0 8px;padding-top:12px;border-top:1px solid #f3f4f6}
.modal-sec:first-of-type{margin-top:0;padding-top:0;border-top:none}
.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px 16px;margin-bottom:4px}
.dg-item .dl{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#9ca3af;margin-bottom:2px}
.dg-item .dv{font-size:.87rem;color:#1f2937;font-weight:500}
.dg-item.full{grid-column:1/-1}

/* ── Area panel ─────────────────────────────────────────────────────────── */
.ma-panel{background:#fff;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:8px;overflow:hidden}
.ma-panel-head{display:flex;align-items:center;gap:8px;padding:9px 13px;background:#f8fafc;border-bottom:1px solid #e5e7eb;cursor:pointer;user-select:none}
.ma-panel-num{width:22px;height:22px;border-radius:5px;background:#dbeafe;color:#2563eb;display:flex;align-items:center;justify-content:center;font-size:.69rem;font-weight:800;flex-shrink:0}
.ma-panel-type{flex:1;font-size:.86rem;font-weight:700;color:#111827}
.ma-panel-brand{font-size:.7rem;color:#d97706;max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ma-panel-body{padding:12px 14px;display:none}
.ma-det-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:5px 14px;margin-bottom:8px}
.ma-dk{font-size:.61rem;text-transform:uppercase;letter-spacing:.06em;font-weight:700;color:#9ca3af}
.ma-dv{font-size:.83rem;color:#111827;font-weight:500;font-family:monospace}
.calc-strip{background:#f8fafc;border:1px solid #e5e7eb;border-radius:7px;padding:10px 12px;margin-top:6px}
.calc-strip-title{font-size:.61rem;font-weight:800;text-transform:uppercase;letter-spacing:.1em;margin-bottom:7px}
.cs-floor{color:#16a34a}.cs-wall{color:#2563eb}.cs-bath{color:#7c3aed}
.chips{display:flex;gap:5px;flex-wrap:wrap}
.chip{background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:5px 9px;text-align:center;min-width:68px}
.chip .cv{font-size:.88rem;font-weight:800;font-variant-numeric:tabular-nums;color:#111827}
.chip .cl{font-size:.57rem;text-transform:uppercase;letter-spacing:.06em;color:#9ca3af;margin-top:1px}
.chip.fl .cv{color:#16a34a}.chip.wl .cv{color:#2563eb}.chip.bt .cv{color:#7c3aed}
.chip.bx{background:#fffbeb;border-color:#fde68a}.chip.bx .cv{color:#d97706;font-size:.96rem}
.bath-wrap{background:#f5f3ff;border:1px solid #ddd6fe;border-radius:7px;padding:10px 13px;margin-top:6px}
.bath-wrap-title{font-size:.62rem;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:#7c3aed;margin-bottom:8px}
.bath-parts{display:grid;grid-template-columns:repeat(3,1fr);gap:7px}
@media(max-width:560px){.bath-parts{grid-template-columns:1fr}}
.bath-part{background:#fff;border:1px solid #ddd6fe;border-radius:6px;padding:8px 9px}
.bath-part .bp-name{font-size:.62rem;font-weight:800;text-transform:uppercase;letter-spacing:.1em;margin-bottom:5px}
.bath-part.light .bp-name{color:#92400e}.bath-part.high .bp-name{color:#d97706}.bath-part.dark .bp-name{color:#374151}
.bp-row{display:flex;justify-content:space-between;font-size:.75rem;margin-bottom:2px}
.bp-k{color:#9ca3af}.bp-v{font-weight:600;font-family:monospace;color:#111827}
.bp-v.hi{color:#d97706;font-weight:700}
.area-photos{display:flex;gap:7px;flex-wrap:wrap;margin-top:7px}
.aphoto{width:62px;height:62px;border-radius:6px;object-fit:cover;border:1.5px solid #e5e7eb;cursor:pointer;transition:transform .15s,border-color .15s;display:block}
.aphoto:hover{transform:scale(1.07);border-color:#2563eb}
.viz-modal-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px;margin-top:8px}
.viz-modal-item{border-radius:7px;overflow:hidden;border:1.5px solid #c4b5fd;cursor:pointer;transition:transform .15s,border-color .15s}
.viz-modal-item:hover{transform:scale(1.02);border-color:#7c3aed}
.viz-modal-item img{width:100%;aspect-ratio:16/9;object-fit:cover;display:block}
.viz-modal-date{font-size:.61rem;color:#9ca3af;padding:3px 7px;text-align:center;background:#f5f3ff}
.deal-box{background:linear-gradient(135deg,#f0fdf4,#fffbeb);border:1px solid #bbf7d0;border-radius:8px;padding:12px;margin-top:6px}
.visit-box{background:linear-gradient(135deg,#f0f9ff,#e0f2fe);border:1.5px solid #7dd3fc;border-radius:8px;padding:10px 14px;margin-top:6px}
.surf-badge{display:inline-flex;align-items:center;border-radius:4px;padding:2px 7px;font-size:.67rem;font-weight:700}
.surf-floor{background:#f0fdf4;color:#15803d;border:1px solid #86efac}
.surf-wall{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe}
.surf-both{background:#fef3c7;color:#92400e;border:1px solid #fde68a}
.surf-bath{background:#ede9fe;color:#7c3aed;border:1px solid #d8b4fe}
.surf-step{background:#eef2ff;color:#4338ca;border:1px solid #c7d2fe}
.surf-riser{background:#f5f3ff;color:#6d28d9;border:1px solid #ddd6fe}
.surf-step_riser{background:#f3f4f6;color:#374151;border:1px solid #d1d5db}
.tile-brand-wrap{background:#fffbeb;border:1px solid #fde68a;border-radius:7px;padding:9px 11px;margin-bottom:9px;display:flex;align-items:flex-start;gap:9px}
.tile-thumb{width:48px;height:48px;border-radius:5px;object-fit:cover;border:1.5px solid #fbbf24;flex-shrink:0;cursor:pointer}
.tile-thumb-ph{width:48px;height:48px;border-radius:5px;border:1.5px dashed #e5e7eb;background:#f3f4f6;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:.5rem;color:#9ca3af;text-align:center}
.tile-company-badge{background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:4px;padding:2px 7px;font-size:.68rem;font-weight:700;display:inline-block;margin-bottom:2px}
.tile-design-badge{background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;border-radius:4px;padding:2px 7px;font-size:.68rem;font-weight:700;display:inline-block}
.maps-link{display:inline-flex;align-items:center;gap:4px;color:#2563eb;font-size:.85rem;text-decoration:none}
.maps-link:hover{text-decoration:underline}

/* ── ESTIMATE section (inside detail modal) ─────────────────────────────── */
.est-section{background:linear-gradient(135deg,#faf5ff,#eff6ff);border:2px solid #c4b5fd;border-radius:10px;padding:14px;margin-top:8px}
.est-section-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px}
.est-section-title{font-size:.78rem;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:#7c3aed;display:flex;align-items:center;gap:6px}
.est-badge-saved{background:#7c3aed;color:#fff;border-radius:5px;padding:2px 8px;font-size:.65rem;font-weight:700}
.est-badge-none{background:#e5e7eb;color:#9ca3af;border-radius:5px;padding:2px 8px;font-size:.65rem;font-weight:700}
.est-print-toggle{display:flex;align-items:center;gap:8px;background:#fff;border:1.5px solid #c4b5fd;border-radius:8px;padding:7px 12px;cursor:pointer;transition:all .2s;user-select:none}
.est-print-toggle:hover{background:#f5f3ff;border-color:#7c3aed}
.est-print-toggle input[type=checkbox]{width:16px;height:16px;accent-color:#7c3aed;cursor:pointer;flex-shrink:0}
.est-print-toggle span{font-size:.75rem;font-weight:600;color:#4c1d95}
.est-view-table{width:100%;border-collapse:collapse;font-size:.76rem;margin-top:10px}
.est-view-table th{padding:6px 8px;text-align:left;font-size:.63rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;border-bottom:2px solid #e5e7eb;white-space:nowrap}
.est-view-table td{padding:6px 8px;border-bottom:1px solid #f3f4f6;vertical-align:middle}
.est-view-table tr:hover td{background:#faf5ff}
.th-est-info{background:#f1f5f9;color:#374151}
.th-est-mrp{background:#dcfce7;color:#15803d}
.th-est-disc{background:#fef9c3;color:#92400e;text-align:center!important}
.th-est-mop{background:#dbeafe;color:#1d4ed8}
.th-est-offer{background:#f5f3ff;color:#7c3aed}
.ev-num{text-align:right;font-family:monospace;font-weight:700;white-space:nowrap}
.ev-mrp{color:#15803d}
.ev-mop{color:#1d4ed8}
.ev-disc{color:#92400e;text-align:center;font-family:monospace;font-weight:700}
.ev-offer{color:#7c3aed;font-size:.7rem}
.ev-area-img{width:36px;height:36px;border-radius:4px;object-fit:cover;border:1.5px solid #fbbf24;cursor:pointer;margin-right:3px;vertical-align:middle}
.ev-no-print{opacity:.45;text-decoration:line-through}
.est-totals-bar{display:flex;gap:8px;justify-content:flex-end;margin-top:12px;flex-wrap:wrap}
.est-total-pill{padding:8px 16px;border-radius:8px;text-align:center;min-width:130px}
.est-total-pill .ep-val{font-size:1rem;font-weight:800;font-family:monospace}
.est-total-pill .ep-lbl{font-size:.6rem;text-transform:uppercase;letter-spacing:.08em;margin-top:2px;opacity:.75}
.etp-mrp{background:#f0fdf4;border:1.5px solid #86efac;color:#15803d}
.etp-mop{background:#eff6ff;border:1.5px solid #bfdbfe;color:#1d4ed8}
.etp-save{background:#f5f3ff;border:1.5px solid #c4b5fd;color:#7c3aed}
.est-no-data{text-align:center;padding:20px;color:#9ca3af;font-size:.82rem;background:#fafafa;border-radius:8px;border:1.5px dashed #e5e7eb}
.est-noprice-warn{background:#fef9c3;border:1.5px solid #fde68a;border-radius:7px;padding:8px 12px;margin-bottom:10px;font-size:.72rem;color:#92400e;display:flex;align-items:center;gap:6px}
.src-area{font-size:.58rem;background:#f0fdf4;color:#15803d;border:1px solid #86efac;border-radius:3px;padding:1px 5px;font-weight:700;margin-left:4px;white-space:nowrap}

/* ── Estimate Edit Modal ────────────────────────────────────────────────── */
.est-room-table{width:100%;border-collapse:collapse;font-size:.8rem}
.est-room-table th{background:#f1f5f9;color:#374151;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;padding:8px 7px;text-align:left;border-bottom:2px solid #e5e7eb;white-space:nowrap}
.est-room-table td{padding:5px 5px;border-bottom:1px solid #f3f4f6;vertical-align:middle}
.est-room-table tr:hover td{background:#fafafa}
.th-mrp{background:#dcfce7!important;color:#15803d!important;border-bottom-color:#86efac!important}
.th-mop{background:#dbeafe!important;color:#1d4ed8!important;border-bottom-color:#93c5fd!important}
.th-disc{background:#fef9c3!important;color:#92400e!important;border-bottom-color:#fde68a!important}
.th-info{background:#f1f5f9!important;color:#374151!important}
.th-offer-col{background:#f5f3ff!important;color:#7c3aed!important;border-bottom-color:#c4b5fd!important}
.est-inp{width:100%;border:1px solid #e5e7eb;border-radius:5px;padding:5px 7px;font-size:.8rem;text-align:right;background:#fff;transition:border-color .15s;min-width:78px}
.est-inp:focus{outline:none;border-color:#7c3aed;box-shadow:0 0 0 2px rgba(124,58,237,.12)}
.est-inp.offer-inp{text-align:right;min-width:72px}
.est-calc{font-weight:700;font-family:monospace;font-size:.82rem;text-align:right;white-space:nowrap;padding:0 4px}
.est-calc.mop{color:#1d4ed8}
.est-calc.mrp{color:#15803d}
.est-calc.disc{color:#92400e;text-align:center}
.est-calc.offer-mrp{color:#7c3aed;font-size:.76rem}
.est-calc.offer-mop{color:#5b21b6;font-size:.76rem}
.est-info-cell{font-family:monospace;font-size:.78rem;color:#374151;text-align:right;white-space:nowrap;padding:0 4px}
.est-sqft-badge{display:inline-block;background:#f3f4f6;border-radius:4px;padding:1px 7px;font-size:.75rem;font-family:monospace;color:#374151;white-space:nowrap}
.est-total-bar{display:flex;gap:10px;justify-content:flex-end;margin-top:14px;flex-wrap:wrap}
.est-total-card{padding:10px 18px;border-radius:8px;text-align:center;min-width:140px}
.est-total-card .etv{font-size:1.1rem;font-weight:800}
.est-total-card .etl{font-size:.65rem;text-transform:uppercase;letter-spacing:.08em;margin-top:2px;opacity:.75}
.est-tc-mrp{background:#f0fdf4;border:1.5px solid #86efac;color:#15803d}
.est-tc-mop{background:#eff6ff;border:1.5px solid #bfdbfe;color:#1d4ed8}
.est-tc-save{background:#f5f3ff;border:1.5px solid #c4b5fd;color:#7c3aed}
.est-tc-offer-mrp{background:#fef3c7;border:1.5px solid #fde68a;color:#92400e}
.est-tc-offer-mop{background:#f5f3ff;border:1.5px solid #c4b5fd;color:#5b21b6}
.est-print-chk{width:16px;height:16px;cursor:pointer;accent-color:#7c3aed}
.est-loading{display:flex;align-items:center;justify-content:center;padding:40px;color:#9ca3af;gap:10px;font-size:.9rem}
@keyframes spin{to{transform:rotate(360deg)}}

/* ── Lightbox ───────────────────────────────────────────────────────────── */
#oa-lb{position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.93);display:none;align-items:center;justify-content:center;flex-direction:column;padding:16px;backdrop-filter:blur(5px)}
#oa-lb.open{display:flex}
#oa-lb-img{max-width:min(94vw,900px);max-height:82vh;object-fit:contain;border-radius:8px;box-shadow:0 8px 40px rgba(0,0,0,.6)}
#oa-lb-cap{margin-top:8px;color:rgba(255,255,255,.65);font-size:.8rem}
#oa-lb-close{position:fixed;top:13px;right:13px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.15);color:#fff;font-size:1.1rem;width:35px;height:35px;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center}
#oa-lb-close:hover{background:rgba(220,38,38,.5)}
</style>

<!-- Lightbox -->
<div id="oa-lb" onclick="if(event.target===this)oaCloseLb()">
  <button id="oa-lb-close" onclick="oaCloseLb()">✕</button>
  <img id="oa-lb-img" src="" alt="">
  <div id="oa-lb-cap"></div>
</div>

<div class="app-wrap">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="main-content">
    <?php if ($reportLoadError): ?>
      <div class="alert alert-error">
        Reports could not load: <?= htmlspecialchars($reportLoadError) ?>
      </div>
    <?php endif; ?>

    <div class="page-header">
      <div>
        <div class="page-title">Sales Executive Reports</div>
        <div class="page-sub">
          <?= count($reports) ?> unique submission<?= count($reports) !== 1 ? 's' : '' ?>
          <?php if ($filterPhone !== ''): ?>
            <span class="phone-active-badge">
              📞 "<?= htmlspecialchars($filterPhone) ?>"
              <button onclick="clearPhoneFilter()" title="Clear phone filter">✕</button>
            </span>
          <?php endif; ?>
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <div class="view-toggle" id="viewToggle">
          <button class="view-toggle-btn active" id="btn-list" onclick="setView('list')">
            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
            List
          </button>
          <button class="view-toggle-btn" id="btn-grid" onclick="setView('grid')">
            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
            Grid
          </button>
        </div>
        <a href="<?= htmlspecialchars($exportUrl) ?>" class="btn-excel">
          <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
          Export Excel
        </a>
      </div>
    </div>

    <!-- Summary Cards -->
    <div class="oa-strip">
      <div class="oa-card oc-total"><div class="tc-num"><?= count($reports) ?></div><div class="tc-label">Total Activities</div><div class="tc-bar"><div class="tc-fill" style="width:100%"></div></div></div>
      <div class="oa-card oc-order"><div class="tc-num"><?= $totalOrders ?></div><div class="tc-label">Orders Completed</div><div class="tc-bar"><div class="tc-fill" style="width:<?= count($reports) ? round($totalOrders/count($reports)*100) : 0 ?>%"></div></div></div>
      <div class="oa-card oc-status"><div class="tc-num"><?= $totalStatus ?></div><div class="tc-label">Status Updates</div><div class="tc-bar"><div class="tc-fill" style="width:<?= count($reports) ? round($totalStatus/count($reports)*100) : 0 ?>%"></div></div></div>
    </div>

    <!-- Filters -->
    <div class="card" style="padding:14px;margin-bottom:14px">
      <form method="GET" id="filterForm" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
        <div class="form-group" style="margin:0;flex:1.2;min-width:160px">
          <label class="form-label">Phone Number</label>
          <div class="phone-filter-wrap">
            <span class="phone-icon"><svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></span>
            <input class="form-control" type="tel" name="phone" id="phoneInput" placeholder="Search by phone…" value="<?= htmlspecialchars($filterPhone) ?>" autocomplete="off" inputmode="numeric" style="padding-left:30px">
          </div>
        </div>
        <div class="form-group" style="margin:0;flex:1;min-width:130px">
          <label class="form-label">Sales Executive</label>
          <select class="form-control" name="office_admin_id">
            <option value="">All</option>
            <?php foreach ($officeAdmins as $oa): ?>
            <option value="<?= $oa['id'] ?>" <?= $filterOA===(int)$oa['id']?'selected':''?>><?= htmlspecialchars($oa['username']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="margin:0;flex:1;min-width:130px">
          <label class="form-label">Report Type</label>
          <select class="form-control" name="report_type">
            <option value="">All</option>
            <option value="order_completed" <?= $filterType==='order_completed'?'selected':''?>>Order Completed</option>
            <option value="status_update"   <?= $filterType==='status_update'?'selected':''?>>Status Update</option>
          </select>
        </div>
        <div class="form-group" style="margin:0;flex:1;min-width:110px">
          <label class="form-label">Status</label>
          <select class="form-control" name="status">
            <option value="">All</option>
            <option value="hot"              <?= $filterStatus==='hot'?'selected':''?>>Hot</option>
            <option value="warm"             <?= $filterStatus==='warm'?'selected':''?>>Warm</option>
            <option value="cold"             <?= $filterStatus==='cold'?'selected':''?>>Cold</option>
            <option value="enquiry"          <?= $filterStatus==='enquiry'?'selected':''?>>Enquiry</option>
            <option value="billed"           <?= $filterStatus==='billed'?'selected':''?>>Billed</option>
            <option value="outside_purchase" <?= $filterStatus==='outside_purchase'?'selected':''?>>Outside Purchase</option>
            <option value="others"           <?= $filterStatus==='others'?'selected':''?>>Others</option>
          </select>
        </div>
        <div class="form-group" style="margin:0;flex:1;min-width:115px">
          <label class="form-label">Date From</label>
          <input class="form-control" type="date" name="date_from" value="<?= htmlspecialchars($filterDateFrom) ?>">
        </div>
        <div class="form-group" style="margin:0;flex:1;min-width:115px">
          <label class="form-label">Date To</label>
          <input class="form-control" type="date" name="date_to" value="<?= htmlspecialchars($filterDateTo) ?>">
        </div>
        <div style="display:flex;gap:6px;align-items:flex-end">
          <button type="submit" class="btn btn-primary"><svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg> Filter</button>
          <a href="reports.php" class="btn btn-ghost">Clear</a>
        </div>
      </form>
    </div>

    <!-- LIST VIEW -->
    <div id="rptListView">
      <div class="card">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>#</th><th>Date &amp; Time</th><th>Sales Exec</th><th>Type</th>
                <th>Owner</th><th>Phone</th><th>Location</th><th>Status Change</th>
                <th>Current Status</th><th>Zone</th><th>Estimate</th><th>Print</th><th>Detail</th>
              </tr>
            </thead>
            <tbody>
            <?php if ($reports): foreach ($reports as $i => $r):
              $rd     = @json_decode($r['report_data'] ?? '', true) ?: [];
              $hasEst = isset($estimateMap[$r['submission_id']]);
              $ownerNum = htmlspecialchars($r['owner_number']);
              $altNum   = htmlspecialchars($r['alt_mobile'] ?? '');
              if ($filterPhone !== '') {
                  $esc = preg_quote(htmlspecialchars($filterPhone), '/');
                  $ownerNum = preg_replace('/('.$esc.')/i', '<mark style="background:#fef9c3;border-radius:2px;padding:0 1px">$1</mark>', $ownerNum);
                  if ($altNum) $altNum = preg_replace('/('.$esc.')/i', '<mark style="background:#fef9c3;border-radius:2px;padding:0 1px">$1</mark>', $altNum);
              }
            ?>
            <tr>
              <td><?= $i+1 ?></td>
              <td class="dt-cell"><span class="dt-date"><?= date('d M Y', strtotime($r['created_at'])) ?></span><span class="dt-time"><?= date('h:i A', strtotime($r['created_at'])) ?></span></td>
              <td><strong><?= htmlspecialchars($r['office_admin_name']) ?></strong></td>
              <td><?= $r['report_type']==='order_completed' ? '<span class="rtype-order">✅ Order</span>' : '<span class="rtype-status">🔄 Update</span>' ?></td>
              <td><strong><?= htmlspecialchars($r['owner_name']) ?></strong><?= $r['order_completed'] ? '<br><span style="font-size:.64rem;background:#dcfce7;color:#15803d;padding:1px 6px;border-radius:10px;font-weight:700">✅ Done</span>' : '' ?></td>
              <td style="font-size:.82rem;font-family:monospace"><?= $ownerNum ?><?= $altNum ? '<br><span style="color:#6b7280;font-size:.72rem">'.$altNum.'</span>' : '' ?></td>
              <td style="font-size:.82rem"><?= htmlspecialchars($r['village_city_town']) ?></td>
              <td>
                <?php if (isset($rd['old_status'], $rd['new_status'])): ?>
                <span class="rd-chip"><span class="badge badge-<?= $rd['old_status'] ?>"><?= ucfirst($rd['old_status']) ?></span><span style="color:#d1d5db">→</span><span class="badge badge-<?= $rd['new_status'] ?>"><?= ucfirst($rd['new_status']) ?></span></span>
                <?php else: ?><span style="color:#9ca3af;font-size:.8rem">—</span><?php endif; ?>
              </td>
              <td><span class="badge badge-<?= htmlspecialchars($r['current_status']) ?>"><span class="badge-dot"></span><?= ucfirst($r['current_status']) ?></span></td>
              <td><span class="badge badge-blue"><?= htmlspecialchars($r['dir_code']) ?>·<?= htmlspecialchars($r['zone_code']) ?></span></td>
              <td>
                <button class="btn-estimate-sm" onclick="openEstimateModal(<?= (int)$r['submission_id'] ?>,'<?= htmlspecialchars(addslashes($r['owner_name']), ENT_QUOTES) ?>')">
                  <svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 7H6a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3"/><rect x="9" y="3" width="6" height="4" rx="1"/></svg>
                  <?= $hasEst ? '✅ Est' : 'Estimate' ?>
                </button>
              </td>
              <td>
                <a href="print_submission.php?id=<?= (int)$r['submission_id'] ?><?= $hasEst ? '&estimate=1' : '' ?>" class="btn-print-sm" data-print-sub-id="<?= (int)$r['submission_id'] ?>">
                  <svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                  Print
                </a>
              </td>
              <td>
                <button class="btn btn-ghost btn-sm" onclick="oaViewReport(
                  <?= htmlspecialchars(json_encode($r,  JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP), ENT_QUOTES) ?>,
                  <?= htmlspecialchars(json_encode($rd, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP), ENT_QUOTES) ?>,
                  <?= htmlspecialchars(json_encode($areasMap[$r['submission_id']]    ?? [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP), ENT_QUOTES) ?>,
                  <?= htmlspecialchars(json_encode($detailsMap[$r['submission_id']]  ?? null, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP), ENT_QUOTES) ?>,
                  <?= htmlspecialchars(json_encode($snapsMap[$r['submission_id']]    ?? [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP), ENT_QUOTES) ?>,
                  <?= htmlspecialchars(json_encode($areaImgsMap[$r['submission_id']] ?? [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP), ENT_QUOTES) ?>,
                  <?= htmlspecialchars(json_encode($estimateMap[$r['submission_id']] ?? null, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP), ENT_QUOTES) ?>,
                  <?= htmlspecialchars(json_encode($estRoomsMap[$r['submission_id']] ?? [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP), ENT_QUOTES) ?>
                )">View</button>
              </td>
            </tr>
            <?php endforeach; else: ?>
            <tr><td colspan="13"><div class="empty-state"><p>No report activity found.</p></div></td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- GRID VIEW -->
    <div id="rptGridView" class="rpt-grid" style="display:none">
      <?php if ($reports): foreach ($reports as $i => $r):
        $rd     = @json_decode($r['report_data'] ?? '', true) ?: [];
        $hasEst = isset($estimateMap[$r['submission_id']]);
        $detail = $detailsMap[$r['submission_id']] ?? null;
        $nvd    = $r['next_visit_date'] ?? ($detail['next_visit_date'] ?? null);
        $isOver = $nvd && $nvd < $today;
        $isUp   = $nvd && !$isOver && $nvd <= $in10Days;
        $isOrder= $r['report_type'] === 'order_completed';
        $gridPhone = htmlspecialchars($r['owner_number']);
        $gridAlt   = htmlspecialchars($r['alt_mobile'] ?? '');
        if ($filterPhone !== '') {
            $esc = preg_quote(htmlspecialchars($filterPhone), '/');
            $gridPhone = preg_replace('/('.$esc.')/i', '<mark style="background:#fef9c3;border-radius:2px;padding:0 1px">$1</mark>', $gridPhone);
            if ($gridAlt) $gridAlt = preg_replace('/('.$esc.')/i', '<mark style="background:#fef9c3;border-radius:2px;padding:0 1px">$1</mark>', $gridAlt);
        }
      ?>
      <div class="rpt-card">
        <div class="rpt-card-stripe <?= $isOrder ? 'order' : 'status' ?>"></div>
        <?php if ($hasEst): ?><div class="rpt-card-est-ribbon">📋 Estimate</div><?php endif; ?>
        <?php $isBilledCard = ($r['current_status'] === 'billed' || $r['order_completed']); ?>
        <div class="rpt-card-top">
          <div><div class="rpt-card-name"><?= htmlspecialchars($r['owner_name']) ?></div><div class="rpt-card-phone"><?= $gridPhone ?><?= $gridAlt ? ' · <span style="color:#9ca3af">'.$gridAlt.'</span>' : '' ?></div></div>
          <?php if ($isBilledCard): ?>
            <span class="badge badge-success" style="background:#dcfce7;color:#15803d;font-weight:700"><span class="badge-dot" style="background:#16a34a"></span>✅ Billed</span>
          <?php else: ?>
            <span class="badge badge-<?= htmlspecialchars($r['current_status']) ?>"><span class="badge-dot"></span><?= ucfirst($r['current_status']) ?></span>
          <?php endif; ?>
        </div>
        <div class="rpt-card-meta">
          <?= $isOrder ? '<span class="rtype-order">✅ Order</span>' : '<span class="rtype-status">🔄 Update</span>' ?>
          <span class="badge badge-blue" style="font-size:.65rem"><?= htmlspecialchars($r['dir_code']) ?>·<?= htmlspecialchars($r['zone_code']) ?></span>
          <?php if (!$isBilledCard && isset($rd['old_status'], $rd['new_status'])): ?>
            <span class="rd-chip"><span class="badge badge-<?= $rd['old_status'] ?>"><?= ucfirst($rd['old_status']) ?></span><span style="color:#d1d5db">→</span><span class="badge badge-<?= $rd['new_status'] ?>"><?= ucfirst($rd['new_status']) ?></span></span>
          <?php endif; ?>
        </div>
        <div class="rpt-card-loc">📍 <?= htmlspecialchars($r['village_city_town']) ?><?php if ($r['street']): ?> · <?= htmlspecialchars($r['street']) ?><?php endif; ?></div>
        <?php if ($nvd && !$isBilledCard): ?><div class="rpt-card-visit <?= $isOver ? 'overdue' : ($isUp ? 'upcoming' : 'normal') ?>"><?= $isOver ? '🔴 Overdue' : ($isUp ? '🟡 Upcoming' : '📅') ?> <?= date('d M Y', strtotime($nvd)) ?></div><?php endif; ?>
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:4px"><span class="rpt-card-exec">👤 <?= htmlspecialchars($r['office_admin_name']) ?></span><span class="rpt-card-date"><?= date('d M Y, h:i A', strtotime($r['created_at'])) ?></span></div>
        <div style="font-size:.72rem;color:var(--c-gray-400);margin-top:4px;line-height:1.6">
          📋 Submitted by: <strong><?= htmlspecialchars($r['submitted_by_name'] ?? $r['office_admin_name']) ?></strong><?php if (!empty($r['edited_by_name'])): ?> &nbsp;·&nbsp; ✏️ Edited by: <strong><?= htmlspecialchars($r['edited_by_name']) ?></strong><?php endif; ?>
        </div>
        <div class="rpt-card-actions">
          <button class="btn btn-ghost btn-sm" onclick="oaViewReport(
            <?= htmlspecialchars(json_encode($r,  JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP), ENT_QUOTES) ?>,
            <?= htmlspecialchars(json_encode($rd, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP), ENT_QUOTES) ?>,
            <?= htmlspecialchars(json_encode($areasMap[$r['submission_id']]    ?? [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP), ENT_QUOTES) ?>,
            <?= htmlspecialchars(json_encode($detailsMap[$r['submission_id']]  ?? null, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP), ENT_QUOTES) ?>,
            <?= htmlspecialchars(json_encode($snapsMap[$r['submission_id']]    ?? [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP), ENT_QUOTES) ?>,
            <?= htmlspecialchars(json_encode($areaImgsMap[$r['submission_id']] ?? [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP), ENT_QUOTES) ?>,
            <?= htmlspecialchars(json_encode($estimateMap[$r['submission_id']] ?? null, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP), ENT_QUOTES) ?>,
            <?= htmlspecialchars(json_encode($estRoomsMap[$r['submission_id']] ?? [], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP), ENT_QUOTES) ?>
          )">👁 View</button>
          <button class="btn-estimate-sm" onclick="openEstimateModal(<?= (int)$r['submission_id'] ?>,'<?= htmlspecialchars(addslashes($r['owner_name']), ENT_QUOTES) ?>')">📋 <?= $hasEst ? '✅ Est' : 'Estimate' ?></button>
          <a href="print_submission.php?id=<?= (int)$r['submission_id'] ?><?= $hasEst ? '&estimate=1' : '' ?>" class="btn-print-sm" data-print-sub-id="<?= (int)$r['submission_id'] ?>">🖨 Print</a>
        </div>
      </div>
      <?php endforeach; else: ?>
        <div class="empty-state" style="grid-column:1/-1"><p>No report activity found.</p></div>
      <?php endif; ?>
    </div>

  </div>
</div>

<!-- DETAIL MODAL -->
<div class="modal-overlay" id="oaViewModal">
  <div class="modal" style="max-width:760px;max-height:93vh;overflow-y:auto">
    <div class="modal-title" id="oa-modal-title">Report Detail</div>
    <div id="oaViewContent"></div>
    <div class="modal-footer" style="flex-wrap:wrap;gap:8px">
      <a id="oa-modal-print-btn" href="#" class="btn-print-sm" style="margin-right:auto">🖨 Print A4</a>
      <button class="btn btn-ghost" onclick="closeModal('oaViewModal')">Close</button>
    </div>
  </div>
</div>

<!-- ESTIMATE MODAL -->
<div class="modal-overlay" id="estModal">
  <div class="modal" style="max-width:1100px;max-height:94vh;overflow-y:auto">
    <div class="modal-title" id="est-modal-title">📋 Estimate</div>
    <div id="est-modal-body"><div class="est-loading"><svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="animation:spin 1s linear infinite"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>Loading…</div></div>
    <div class="modal-footer" style="flex-wrap:wrap;gap:8px">
      <a id="est-print-link" href="#" class="btn-print-sm" style="margin-right:auto">🖨 Print with Estimate</a>
      <button class="btn btn-primary btn-sm" id="est-save-btn" onclick="saveEstimate()">💾 Save Estimate</button>
      <button class="btn btn-ghost" onclick="closeModal('estModal')">Close</button>
    </div>
  </div>
</div>

<!-- Mobile Nav -->
<nav class="mob-nav">
  <div class="mob-nav-items">
    <a href="dashboard.php" class="mob-nav-item"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>Home</a>
    <a href="subadmins.php" class="mob-nav-item"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>Walk-In</a>
    <a href="reports.php" class="mob-nav-item active"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>Reports</a>
  </div>
</nav>

<?php pageFooter(); ?>

<script>
// ── Config ────────────────────────────────────────────────────────────────────
const ESTIMATE_MODAL_URL = <?= json_encode($estimateModalUrl) ?>;
const OA_UPLOAD_URL      = <?= json_encode(rtrim(UPLOAD_URL, '/') . '/') ?>;
const OA_TODAY           = <?= json_encode($today) ?>;
const OA_IN10            = <?= json_encode($in10Days) ?>;
const OA_PRINT_BASE      = <?= json_encode(rtrim(APP_URL,'/') . '/office_admin/print_submission.php') ?>;

// ── Global state ──────────────────────────────────────────────────────────────
let _currentSubId    = null;
let _currentAreaImgs = {};
let _currentAreaRows = [];

/* ═══════════════════════════════════════════════════════════════════
   CORE CALCULATION ENGINE
   Matches print_submission.php v7.1 and estimate_modal.php v4 exactly.

   MRP Total    = Boxes × MRP/box
   MOP Total    = Boxes × MOP/box
   Disc%        = 100 − ((MOP/box ÷ MRP/box) × 100)   ← savings %
   After-Offer  = Total × (1 − Offer%/100)
   Grand Disc%  = 100 − (Grand MOP ÷ Grand MRP × 100)
═══════════════════════════════════════════════════════════════════ */

function _totalBoxes(baseBoxes, extraBox) { return Math.max(0, (parseInt(baseBoxes||0,10)||0) + (parseInt(extraBox||0,10)||0)); }
function _mrpTotal(boxes, mrpPB)  { return Math.round(parseInt(boxes||0,10) * parseFloat(mrpPB||0) * 100) / 100; }
function _mopTotal(boxes, mopPB)  { return Math.round(parseInt(boxes||0,10) * parseFloat(mopPB||0) * 100) / 100; }
function _discPct(mrpPB, mopPB) {
    const mrp = parseFloat(mrpPB||0), mop = parseFloat(mopPB||0);
    if (mrp <= 0) return 0;
    return Math.max(0, 100 - (mop / mrp * 100));
}
function _afterOffer(total, offerPct) {
    const pct = parseFloat(offerPct||0);
    if (pct <= 0) return total;
    return Math.round(total * (1 - pct / 100) * 100) / 100;
}
function _savings(mrpTotal, mopTotal) {
    return Math.max(0, mrpTotal - mopTotal);
}

/* ── Tile calculation (mirrors print_submission.php calcBoxes) ─── */
function oaParseCustomTile(custom) {
    if (!custom) return null;
    if (typeof custom === 'string') {
        try { custom = JSON.parse(custom); } catch(e) { return null; }
    }
    if (typeof custom !== 'object') return null;
    const w = parseFloat(custom.w ?? custom.width ?? custom.custw ?? custom.custom_w ?? 0);
    const h = parseFloat(custom.h ?? custom.height ?? custom.custh ?? custom.custom_h ?? 0);
    const ppb = parseInt(custom.ppb ?? custom.pieces_per_box ?? custom.custppb ?? custom.custom_ppb ?? 0, 10);
    if (!w || !h || !ppb) return null;
    return { w, h, ppb, area: w * h };
}
function oaParseTileKey(key, custom) {
    const customTile = oaParseCustomTile(custom);
    if (customTile) return customTile;
    if (!key || key === 'custom') return null;
    const p = key.split('|');
    const w = parseFloat(p[0]||0), h = parseFloat(p[1]||0), ppb = parseInt(p[2]||1)||1;
    if (!w || !h) return null;
    return { w, h, ppb, area: w * h };
}
function oaTileSizeLabel(key, custom) {
    const customTile = oaParseCustomTile(custom);
    if (customTile) return `${customTile.w.toFixed(2)}×${customTile.h.toFixed(2)} ft·${customTile.ppb}/box`;
    if (!key) return '—';
    if (key === 'custom') return 'Custom';
    const labels = {
        '1.5|1|6':'1.50×1.00 ft·6/box','1|1|8':'1.00×1.00 ft·8/box',
        '2|1|5':'2.00×1.00 ft·5/box','2|2|4':'2.00×2.00 ft·4/box',
        '1.25|1.25|5':'1.25×1.25 ft·5/box','4|2|2':'4.00×2.00 ft·2/box',
        '2.75|5.5|2':'2.75×5.50 ft·2/box','6|4|2':'6.00×4.00 ft·2/box',
        '2.75|8|1':'2.75×8.00 ft·1/box'
    };
    if (labels[key]) return labels[key];
    const t = oaParseTileKey(key);
    return t ? `${t.w}×${t.h} ft·${t.ppb}/box` : key;
}
function oaCalcBoxes(sqft, key, wastePct, custom) {
    const t = oaParseTileKey(key, custom);
    if (!t || !sqft) return 0;
    const needed = Math.ceil(sqft / t.area);
    const extra  = Math.ceil(needed * (wastePct / 100));
    return Math.ceil((needed + extra) / t.ppb);
}
function oaCalcBoxesByItem(itemCount, key, wastePct, custom) {
    const t = oaParseTileKey(key, custom);
    if (!t || !itemCount) return 0;
    const needed = itemCount;
    const extra  = Math.ceil(needed * (wastePct / 100));
    return Math.ceil((needed + extra) / t.ppb);
}

/* ═══════════════════════════════════════════════════════════════════
   WASTAGE HELPER — mirrors PHP print_submission.php exactly:
     floor: (int)($a['floor_wastage_pct'] ?? $a['wastage_pct'] ?? 10)
     wall:  (int)($a['wall_wastage_pct']  ?? 10)
     bath:  (int)($a['bath_wastage_pct']  ?? 10)

   In JSON, PHP DECIMAL "0.00" becomes number 0.
   PHP ?? does NOT skip "0.00" (not null), so PHP uses 0.
   JS must also use 0 (not fall back to wastage_pct or 10).
   We check !== null && !== undefined to replicate PHP ?? exactly.
═══════════════════════════════════════════════════════════════════ */
function _getWaste(primary, fallback, def) {
    // primary: e.g. area.floor_wastage_pct (may be 0, null, or undefined)
    // fallback: e.g. area.wastage_pct
    // def: 10
    if (primary !== null && primary !== undefined) return parseFloat(primary);
    if (fallback !== null && fallback !== undefined) return parseFloat(fallback);
    return def;
}

/* ── Build unified rows from areas ──────────────────────────────── */
function oaDeriveRowsFromArea(area) {
    const rows = [];
    const surf = area.tile_surface || 'floor';
    const L    = parseFloat(area.area_length  || 0);
    const B    = parseFloat(area.area_breadth || 0);
    const H    = parseFloat(area.area_height  || 0);
    const sqft = parseFloat(area.floor_sqft || area.total_sqft || area.area_sqft || 0);
    const wallSqftSaved = parseFloat(area.wall_sqft || area.total_sqft || area.area_sqft || 0);
    const stepCount  = parseInt(area.step_count || 0, 10) || 0;
    const riserCount = (parseInt(area.riser_count || 0, 10) || 0)
                     + (parseInt(area.riser_landing_tiles || 0, 10) || 0);

    if (surf === 'floor' || surf === 'both') {
        const key   = area.floor_tile_size_key || area.tile_size_key || '2|2|4';
        const custom = area.floor_tile_custom || null;
        // Mirror PHP: (int)($a['floor_wastage_pct'] ?? $a['wastage_pct'] ?? 10)
        const waste = _getWaste(area.floor_wastage_pct, area.wastage_pct, 10);
        rows.push({
            area_id        : area.id,
            area_name      : (area.area_type||'Room') + (surf==='both'?' — Floor':''),
            surface        : 'floor',
            total_sqft     : Math.round(sqft*100)/100,
            tile_size_key  : key,
            tile_size_label: oaTileSizeLabel(key, custom),
            wastage_pct    : waste,
            box_count      : oaCalcBoxes(sqft, key, waste, custom),
        });
    }
    if (surf === 'wall' || surf === 'both') {
        const wSqft = wallSqftSaved;
        const key   = area.wall_tile_size_key || '1|1|8';
        const custom = area.wall_tile_custom || null;
        // Mirror PHP: (int)($a['wall_wastage_pct'] ?? 10)
        const waste = _getWaste(area.wall_wastage_pct, null, 10);
        rows.push({
            area_id        : area.id,
            area_name      : (area.area_type||'Room') + (surf==='both'?' — Wall':''),
            surface        : 'wall',
            total_sqft     : wSqft,
            tile_size_key  : key,
            tile_size_label: oaTileSizeLabel(key, custom),
            wastage_pct    : waste,
            box_count      : oaCalcBoxes(wSqft, key, waste, custom),
        });
    }
    if (parseInt(area.is_bathroom||0) === 1) {
        const perim = (L && B) ? 2*(L+B) : 0;
        const key   = area.bath_tile_size_key || area.bath_tile_size || '1|1|8';
        const custom = area.bath_tile_custom || null;
        // Mirror PHP: (int)($a['bath_wastage_pct'] ?? 10)
        const waste = _getWaste(area.bath_wastage_pct, null, 10);
        [['Light', area.bath_light_h],['Highlight', area.bath_high_h],['Dark', area.bath_dark_h]]
            .filter(([,h]) => parseFloat(h||0) > 0 && perim > 0)
            .forEach(([pName, pH]) => {
                const pSqft = Math.round(perim * parseFloat(pH) * 100) / 100;
                rows.push({
                    area_id        : area.id,
                    area_name      : (area.area_type||'Room') + ` — Bath ${pName}`,
                    surface        : 'bath',
                    total_sqft     : pSqft,
                    tile_size_key  : key,
                    tile_size_label: oaTileSizeLabel(key, custom),
                    wastage_pct    : waste,
                    box_count      : oaCalcBoxes(pSqft, key, waste, custom),
                });
            });
    }

    if (stepCount > 0) {
        const key   = area.step_tile_size_key || area.step_riser_tile_size_key || '3|1|1';
        const custom = area.step_tile_custom || area.step_riser_tile_custom || null;
        const waste = _getWaste(area.step_wastage_pct, area.step_riser_wastage_pct, 10);
        rows.push({
            area_id        : area.id,
            area_name      : (area.area_type||'Room') + ' — Steps',
            surface        : 'step',
            total_sqft     : parseFloat(area.riser_landing_sqft || 0),
            tile_size_key  : key,
            tile_size_label: oaTileSizeLabel(key, custom),
            wastage_pct    : waste,
            box_count      : oaCalcBoxesByItem(stepCount, key, waste, custom),
            item_count     : stepCount,
        });
    }
    if (riserCount > 0) {
        const key   = area.riser_tile_size_key || area.step_riser_tile_size_key || '3|0.8|1';
        const custom = area.riser_tile_custom || area.step_riser_tile_custom || null;
        const waste = _getWaste(area.riser_wastage_pct, area.step_riser_wastage_pct, 10);
        rows.push({
            area_id        : area.id,
            area_name      : (area.area_type||'Room') + ' — Risers',
            surface        : 'riser',
            total_sqft     : 0,
            tile_size_key  : key,
            tile_size_label: oaTileSizeLabel(key, custom),
            wastage_pct    : waste,
            box_count      : oaCalcBoxesByItem(riserCount, key, waste, custom),
            item_count     : riserCount,
        });
    }
    return rows;
}

/* ── Merge derived rows with saved estimate data ─────────────────── */
function oaBuildUnifiedRows(areas, savedRows) {
    const base = [];
    (areas||[]).forEach(a => oaDeriveRowsFromArea(a).forEach(r => base.push(r)));
    if (!base.length) return [];

    // Build lookups: id → row, area_id|surface → row, name|surface → row
    const byId = {};
    const byKey = {};
    const byName = {};
    (savedRows||[]).forEach(sr => {
      if (sr.id) byId[String(sr.id)] = sr;
      const k = (sr.area_id||'') + '|' + (sr.surface||'floor');
      byKey[k] = sr;
      const n = (sr.area_name||'').toString().trim().toLowerCase() + '|' + (sr.surface||'floor');
      byName[n] = sr;
    });

    return base.map(row => {
        const k     = (row.area_id||'') + '|' + (row.surface||'floor');
        const n     = (row.area_name||'').toString().trim().toLowerCase() + '|' + (row.surface||'floor');
        // Match priority: saved row id (if available), area_id|surface, area_name|surface
        const saved = (row.id && byId[String(row.id)])
              || byKey[k]
              || byName[n]
              || null;
        const offerRaw = saved ? (saved.offer||'') : '';

        return Object.assign({}, row, {
            // Preserve saved label unless it is an old plain "Custom" value.
            tile_size_label : saved && saved.tile_size_label
                                && !['custom','custom size'].includes(String(saved.tile_size_label).trim().toLowerCase())
                                ? saved.tile_size_label
                                : row.tile_size_label,
            box_count       : saved && parseInt(saved.box_count,10) > 0
                                ? parseInt(saved.box_count, 10)
                                : row.box_count,
            item_count      : saved && parseInt(saved.item_count,10) > 0
                                ? parseInt(saved.item_count, 10)
                                : (row.item_count || 0),
            total_sqft      : saved && parseFloat(saved.total_sqft) > 0
                                ? parseFloat(saved.total_sqft)
                                : row.total_sqft,
            extra_box       : saved ? (parseInt(saved.extra_box,10)||0) : 0,
            mrp_per_box     : saved ? parseFloat(saved.mrp_per_box||0) : 0,
            mop_per_box     : saved ? parseFloat(saved.mop_per_box||0) : 0,
            offer_pct       : parseFloat(offerRaw)||0,
            include_in_print: saved ? (parseInt(saved.include_in_print,10) !== 0) : true,
        });
    });
}

/* ═══════════════════════════════════════════════════════════════════
   HELPERS
═══════════════════════════════════════════════════════════════════ */
function fmtINR(n) {
    return '₹' + parseFloat(n||0).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2});
}
function oaEsc(s) {
    if (s == null) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
function oaFmtDateTime(dt) {
    if (!dt) return '—';
    const d = new Date(dt.replace(' ','T'));
    if (isNaN(d)) return dt;
    const mo=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    const h=d.getHours(),m=String(d.getMinutes()).padStart(2,'0'),ampm=h>=12?'PM':'AM',h12=String(h%12||12).padStart(2,'0');
    return `${String(d.getDate()).padStart(2,'0')} ${mo[d.getMonth()]} ${d.getFullYear()}, ${h12}:${m} ${ampm}`;
}
function oaFmtVisit(d) {
    if (!d) return null;
    const today=new Date(OA_TODAY+'T00:00:00'), vd=new Date(d+'T00:00:00');
    const diff=Math.round((vd-today)/86400000);
    const mo=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    const days=['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    const label=`${days[vd.getDay()]}, ${vd.getDate()} ${mo[vd.getMonth()]} ${vd.getFullYear()}`;
    if (diff<0)   return `<span style="display:inline-block;padding:4px 12px;border-radius:8px;background:#fee2e2;color:#b91c1c;font-weight:700;font-size:.85rem">🔴 Overdue — ${label} (${Math.abs(diff)}d ago)</span>`;
    if (diff===0) return `<span style="display:inline-block;padding:4px 12px;border-radius:8px;background:#fef9c3;color:#854d0e;font-weight:700;font-size:.85rem">🟡 TODAY — ${label}</span>`;
    if (diff<=10) return `<span style="display:inline-block;padding:4px 12px;border-radius:8px;background:#fef9c3;color:#854d0e;font-weight:700;font-size:.85rem">🟡 ${label} (in ${diff}d)</span>`;
    return `<span style="display:inline-block;padding:4px 12px;border-radius:8px;background:#eff6ff;color:#1d4ed8;font-weight:700;font-size:.85rem">📅 ${label} (in ${diff}d)</span>`;
}
function oaSurfLabel(s) {
    return s==='floor' ? '🪵 Floor'
         : s==='wall'  ? '🧱 Wall'
         : s==='both'  ? '🏠 Both'
         : s==='bath'  ? '🛁 Bath'
         : s==='step'  ? '🪜 Step'
         : s==='riser' ? '🪜 Riser'
         : s==='step_riser' ? '🪜 Step+Riser'
         : (s||'—');
}
function oaSurfClass(s) {
    return s==='floor' ? 'surf-floor'
         : s==='wall'  ? 'surf-wall'
         : s==='both'  ? 'surf-both'
         : s==='bath'  ? 'surf-bath'
         : s==='step'  ? 'surf-step'
         : s==='riser' ? 'surf-riser'
         : s==='step_riser' ? 'surf-step_riser'
         : 'surf-floor';
}

/* ── Print link updater ─────────────────────────────────────────── */
function updatePrintLink() {
    const chk = document.getElementById('est-in-print-chk');
    const btn = document.getElementById('oa-modal-print-btn');
    if (!btn || !_currentSubId) return;
    btn.href = OA_PRINT_BASE + '?id=' + _currentSubId + (chk && chk.checked ? '&estimate=1' : '');
}
function markEstimateSavedForPrint(subId) {
    const id = parseInt(subId || 0, 10);
    if (!id) return;
    document.querySelectorAll(`[data-print-sub-id="${id}"]`).forEach(a => {
        a.href = 'print_submission.php?id=' + id + '&estimate=1';
    });
    const estPrint = document.getElementById('est-print-link');
    if (estPrint) estPrint.href = OA_PRINT_BASE + '?id=' + id + '&estimate=1';
    if (_currentSubId && parseInt(_currentSubId, 10) === id) {
        const chk = document.getElementById('est-in-print-chk');
        if (chk) chk.checked = true;
        updatePrintLink();
    }
}

/* ═══════════════════════════════════════════════════════════════════
   ESTIMATE SECTION inside View Detail Modal
═══════════════════════════════════════════════════════════════════ */
function buildEstimateSection(estimate, unifiedRows, areaImgsByAreaId) {
    let html = `<div class="est-section" id="est-section-live">
        <div class="est-section-head">
            <div class="est-section-title">📋 Estimate Sheet`;
    if (estimate) {
        html += `<span class="est-badge-saved">✅ Saved #${estimate.id}</span>`;
        if (estimate.created_by_name) html += `<span style="font-size:.62rem;color:#6b7280;font-weight:400;margin-left:4px">by ${oaEsc(estimate.created_by_name)}</span>`;
    } else {
        html += `<span class="est-badge-none">Not saved yet</span>`;
    }
    html += `</div>
            <label class="est-print-toggle"><input type="checkbox" id="est-in-print-chk" onchange="updatePrintLink()" ${estimate?'checked':''}><span>🖨️ Include in Print</span></label>
        </div>`;

    if (!unifiedRows || !unifiedRows.length) {
        html += `<div class="est-no-data">No rooms found for this submission.<br><span style="font-size:.75rem">Add areas first, then click Estimate to price them.</span></div></div>`;
        return html;
    }

    const hasPricing = unifiedRows.some(r => parseFloat(r.mrp_per_box||0) > 0);
    if (!hasPricing) {
        html += `<div class="est-noprice-warn">ℹ️ ${unifiedRows.length} room(s) found — click <strong>Estimate</strong> to add pricing.</div>`;
    }

    html += `<div style="overflow-x:auto"><table class="est-view-table"><thead><tr>
        <th class="th-est-info">#</th>
        <th class="th-est-info">Room / Area</th>
        <th class="th-est-info">Surface</th>
        <th class="th-est-info">Tile Size</th>
        <th class="th-est-info" style="text-align:right">Sqft</th>
        <th class="th-est-info" style="text-align:right">Base</th>
        <th class="th-est-info" style="text-align:right">Extra</th>
        <th class="th-est-info" style="text-align:right">Total Boxes</th>
        <th class="th-est-mrp" style="text-align:right">MRP/box</th>
        <th class="th-est-mrp" style="text-align:right">MRP Total</th>
        <th class="th-est-disc" style="text-align:center">Disc%</th>
        <th class="th-est-mop" style="text-align:right">MOP/box</th>
        <th class="th-est-mop" style="text-align:right">MOP Total</th>
        <th class="th-est-mop" style="text-align:right">Savings</th>
        <th class="th-est-offer" style="text-align:center">Offer%</th>
        <th class="th-est-offer" style="text-align:right">After MRP</th>
        <th class="th-est-offer" style="text-align:right">After MOP</th>
    </tr></thead><tbody>`;

    let grandMRP=0, grandMOP=0, grandAfterMRP=0, grandAfterMOP=0;
    let printCount=0;

    unifiedRows.forEach((rm, i) => {
        const baseBoxes= parseInt(rm.box_count||0, 10);
        const extraBox = parseInt(rm.extra_box||0, 10) || 0;
        const boxes    = _totalBoxes(baseBoxes, extraBox);
        const mrpPB    = parseFloat(rm.mrp_per_box||0);
        const mopPB    = parseFloat(rm.mop_per_box||0);
        const offerPct = parseFloat(rm.offer_pct||0);
        const mrpT     = _mrpTotal(boxes, mrpPB);
        const mopT     = _mopTotal(boxes, mopPB);
        const disc     = _discPct(mrpPB, mopPB);
        const save     = _savings(mrpT, mopT);
        const afterMRP = _afterOffer(mrpT, offerPct);
        const afterMOP = _afterOffer(mopT, offerPct);
        const inPrint  = rm.include_in_print !== false && rm.include_in_print !== 0;

        if (inPrint) { grandMRP+=mrpT; grandMOP+=mopT; grandAfterMRP+=afterMRP; grandAfterMOP+=afterMOP; printCount++; }

        let imgHtml = '<span style="color:#d1d5db;font-size:.7rem">—</span>';
        if (rm.area_id && areaImgsByAreaId && areaImgsByAreaId[rm.area_id]) {
            const imgs = areaImgsByAreaId[rm.area_id];
            imgHtml = imgs.slice(0,3).map(f => {
                const u = OA_UPLOAD_URL + f;
                return `<img class="ev-area-img" src="${oaEsc(u)}" loading="lazy" onerror="this.style.display='none'" onclick="oaOpenLb('${oaEsc(u)}','${oaEsc(rm.area_name)}')">`;
            }).join('');
        }

        html += `<tr class="${inPrint?'':'ev-no-print'}">
          <td style="color:#9ca3af;font-size:.7rem">${i+1}</td>
          <td><strong style="font-size:.82rem">${oaEsc(rm.area_name)}</strong><span class="src-area">Calc</span>${imgHtml}${!inPrint?'<br><span style="font-size:.6rem;color:#9ca3af">(excluded)</span>':''}</td>
          <td><span class="surf-badge ${oaSurfClass(rm.surface)}">${oaSurfLabel(rm.surface)}</span></td>
          <td><span class="est-sqft-badge" style="font-size:.68rem">${oaEsc(rm.tile_size_label)||'—'}</span></td>
          <td class="ev-num" style="color:#6b7280">${parseFloat(rm.total_sqft||0).toFixed(2)}</td>
          <td class="ev-num"><strong style="color:#d97706">${baseBoxes}</strong></td>
          <td class="ev-num" style="color:${extraBox<0?'#dc2626':'#16a34a'}">${extraBox>0?'+':''}${extraBox}</td>
          <td class="ev-num"><strong style="color:#d97706">📦 ${boxes}</strong></td>
          <td class="ev-num ev-mrp">${mrpPB>0?fmtINR(mrpPB):'<span style="color:#d1d5db">—</span>'}</td>
          <td class="ev-num ev-mrp"><strong>${mrpT>0?fmtINR(mrpT):'<span style="color:#d1d5db">—</span>'}</strong></td>
          <td class="ev-disc">${disc>0?disc.toFixed(2)+'%':'<span style="color:#d1d5db">—</span>'}</td>
          <td class="ev-num ev-mop">${mopPB>0?fmtINR(mopPB):'<span style="color:#d1d5db">—</span>'}</td>
          <td class="ev-num ev-mop"><strong>${mopT>0?fmtINR(mopT):'<span style="color:#d1d5db">—</span>'}</strong></td>
          <td class="ev-num" style="color:#7c3aed"><strong>${save>0?fmtINR(save):'<span style="color:#d1d5db">—</span>'}</strong></td>
          <td class="ev-disc" style="color:#7c3aed">${offerPct>0?offerPct.toFixed(1)+'%':'<span style="color:#d1d5db">—</span>'}</td>
          <td class="ev-num" style="color:#7c3aed">${offerPct>0&&afterMRP>0?fmtINR(afterMRP):'<span style="color:#d1d5db">—</span>'}</td>
          <td class="ev-num" style="color:#5b21b6">${offerPct>0&&afterMOP>0?fmtINR(afterMOP):'<span style="color:#d1d5db">—</span>'}</td>
        </tr>`;
    });

    html += `</tbody></table></div>`;

    const grandSave    = _savings(grandMRP, grandMOP);
    const hasOffer     = grandAfterMRP > 0 && grandAfterMRP !== grandMRP;

    html += `<div class="est-totals-bar">
        <div class="est-total-pill etp-mrp"><div class="ep-val">${fmtINR(grandMRP)}</div><div class="ep-lbl">Grand MRP Total</div></div>
        <div class="est-total-pill etp-mop"><div class="ep-val">${fmtINR(grandMOP)}</div><div class="ep-lbl">Grand MOP Total</div></div>
        <div class="est-total-pill etp-save"><div class="ep-val">${fmtINR(grandSave)}</div><div class="ep-lbl">Saved Price</div></div>
        ${hasOffer?`
        <div class="est-total-pill" style="background:#fef3c7;border:1.5px solid #fde68a;color:#92400e"><div class="ep-val">${fmtINR(grandAfterMRP)}</div><div class="ep-lbl">After Offer MRP</div></div>
        <div class="est-total-pill" style="background:#ede9fe;border:1.5px solid #c4b5fd;color:#5b21b6"><div class="ep-val">${fmtINR(grandAfterMOP)}</div><div class="ep-lbl">After Offer MOP</div></div>`:''}
    </div></div>`;
    return html;
}

/* ── Refresh estimate after save ────────────────────────────────── */
function refreshEstimateInDetailModal() {
    if (!_currentSubId) return;
    fetch(ESTIMATE_MODAL_URL + '?submission_id=' + _currentSubId, {credentials:'same-origin'})
        .then(r=>r.text())
        .then(text => {
            let data; try { data = JSON.parse(text.trim().replace(/^\uFEFF/,'')); } catch(e){return;}
            if (!data || data.error) return;
            const unifiedRows = oaBuildUnifiedRows(_currentAreaRows, data.rooms||[]);
            const estimateObj = data.estimate_id ? {id:data.estimate_id, created_by_name:data.created_by_name||''} : null;
            const old = document.getElementById('est-section-live');
            if (old) {
                const tmp = document.createElement('div');
                tmp.innerHTML = buildEstimateSection(estimateObj, unifiedRows, _currentAreaImgs);
                old.replaceWith(tmp.firstElementChild);
                updatePrintLink();
            }
        }).catch(()=>{});
}

/* ── Area panel (View modal) ────────────────────────────────────── */
function oaBuildAreaPanel(a, areaIdx, areaImgsByAreaId) {
    const surf=a.tile_surface||'floor', sqft=parseFloat(a.floor_sqft||a.total_sqft||a.area_sqft||0);
    const L=parseFloat(a.area_length||0),B=parseFloat(a.area_breadth||0),H=parseFloat(a.area_height||0);
    const wallSqft=parseFloat(a.wall_sqft||a.total_sqft||a.area_sqft||0), perim=(L&&B)?2*(L+B):0;
    const fWaste=parseFloat(a.floor_wastage_pct||a.wastage_pct||0);
    const fCustom=a.floor_tile_custom||null;
    const fc=(surf==='floor'||surf==='both')?oaCalcBoxes(sqft,a.floor_tile_size_key||a.tile_size_key||'2|2|4',fWaste,fCustom):null;
    const wWaste=parseFloat(a.wall_wastage_pct||0);
    const wCustom=a.wall_tile_custom||null;
    const wc=(surf==='wall'||surf==='both')?oaCalcBoxes(wallSqft,a.wall_tile_size_key||'1|1|8',wWaste,wCustom):null;
    const isBath=parseInt(a.is_bathroom||0)===1;
    const bWaste=parseFloat(a.bath_wastage_pct||0);
    const tileImgUrl=a.tile_image_filename?OA_UPLOAD_URL+a.tile_image_filename:'';
    const brand=[a.tile_company,a.tile_design_name].filter(Boolean).join(' — ');
    const areaPhotos=(areaImgsByAreaId&&areaImgsByAreaId[a.id])?areaImgsByAreaId[a.id]:[];

    let html=`<div class="ma-panel">
      <div class="ma-panel-head" onclick="const b=this.nextElementSibling;b.style.display=b.style.display==='block'?'none':'block'">
        <div class="ma-panel-num">${areaIdx+1}</div>
        ${tileImgUrl?`<img src="${oaEsc(tileImgUrl)}" style="width:32px;height:32px;border-radius:4px;object-fit:cover;border:1.5px solid #fbbf24;flex-shrink:0;cursor:pointer" onclick="event.stopPropagation();oaOpenLb('${oaEsc(tileImgUrl)}','Tile — ${oaEsc(a.area_type)}')">` : ''}
        <div style="flex:1;min-width:0"><div class="ma-panel-type">${oaEsc(a.area_type)}</div>${brand?`<div class="ma-panel-brand">${oaEsc(brand)}</div>`:''}</div>
        <span class="${oaSurfClass(surf)} surf-badge">${oaSurfLabel(surf)}</span>
        ${sqft?`<span style="font-size:.7rem;color:#6b7280;margin-left:5px">${sqft.toFixed(2)} ft²</span>`:''}
        ${isBath?'<span style="font-size:.72rem;color:#7c3aed;margin-left:4px">🛁</span>':''}
        <span style="color:#9ca3af;font-size:.72rem;margin-left:5px">▾</span>
      </div>
      <div class="ma-panel-body">`;

    html+=`<div class="ma-det-grid" style="margin-bottom:9px">`;
    if(a.area_count) html+=`<div><div class="ma-dk">Count</div><div class="ma-dv">${oaEsc(a.area_count)}</div></div>`;
    if(L) html+=`<div><div class="ma-dk">Length</div><div class="ma-dv">${L} ft</div></div>`;
    if(B) html+=`<div><div class="ma-dk">Breadth</div><div class="ma-dv">${B} ft</div></div>`;
    if(H) html+=`<div><div class="ma-dk">Height</div><div class="ma-dv">${H} ft</div></div>`;
    if(sqft) html+=`<div><div class="ma-dk">Floor ft²</div><div class="ma-dv" style="color:#d97706">${sqft.toFixed(2)}</div></div>`;
    if(wallSqft) html+=`<div><div class="ma-dk">Wall ft²</div><div class="ma-dv" style="color:#2563eb">${wallSqft.toFixed(2)}</div></div>`;
    html+=`</div>`;

    if(a.tile_company||a.tile_design_name||tileImgUrl) {
        html+=`<div class="tile-brand-wrap">
            ${tileImgUrl?`<img src="${oaEsc(tileImgUrl)}" class="tile-thumb" onclick="oaOpenLb('${oaEsc(tileImgUrl)}','Tile — ${oaEsc(a.area_type)}')">`:`<div class="tile-thumb-ph">No Image</div>`}
            <div style="flex:1">
                <div style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#d97706;margin-bottom:4px">🏷️ Tile Brand &amp; Design</div>
                ${a.tile_company?`<div style="margin-bottom:2px"><span class="tile-company-badge">🏭 ${oaEsc(a.tile_company)}</span></div>`:''}
                ${a.tile_design_name?`<div><span class="tile-design-badge">✨ ${oaEsc(a.tile_design_name)}</span></div>`:''}
                ${(!a.tile_company&&!a.tile_design_name&&a.tile_chosen)?`<div style="font-size:.73rem;color:#374151">${oaEsc(a.tile_chosen)}</div>`:''}
            </div></div>`;
    }

    if(fc&&sqft>0) {
        const fKey=a.floor_tile_size_key||a.tile_size_key||'2|2|4';
        const fNeeded=Math.ceil(sqft/((oaParseTileKey(fKey,fCustom)||{area:1}).area));
        const fExtra=Math.ceil(fNeeded*(fWaste/100)), fTotal=fNeeded+fExtra;
        html+=`<div class="calc-strip"><div class="calc-strip-title cs-floor">🪵 Floor Tiles — ${oaTileSizeLabel(fKey,fCustom)}</div><div class="chips">
            <div class="chip fl"><div class="cv">${sqft.toFixed(2)}</div><div class="cl">ft²</div></div>
            <div class="chip fl"><div class="cv">${fNeeded}</div><div class="cl">Needed</div></div>
            <div class="chip fl"><div class="cv">${fExtra}<span style="font-size:.6rem"> (${fWaste}%)</span></div><div class="cl">Wastage</div></div>
            <div class="chip fl"><div class="cv">${fTotal}</div><div class="cl">Total</div></div>
            <div class="chip bx"><div class="cv">📦 ${fc}</div><div class="cl">Boxes</div></div>
        </div></div>`;
    }
    if(wc&&wallSqft>0) {
        const wKey=a.wall_tile_size_key||'1|1|8';
        const wNeeded=Math.ceil(wallSqft/((oaParseTileKey(wKey,wCustom)||{area:1}).area));
        const wExtra=Math.ceil(wNeeded*(wWaste/100)), wTotal=wNeeded+wExtra;
        html+=`<div class="calc-strip" style="margin-top:6px"><div class="calc-strip-title cs-wall">🧱 Wall Tiles — ${oaTileSizeLabel(wKey,wCustom)}</div><div class="chips">
            <div class="chip wl"><div class="cv">${wallSqft.toFixed(2)}</div><div class="cl">ft²</div></div>
            <div class="chip wl"><div class="cv">${wNeeded}</div><div class="cl">Needed</div></div>
            <div class="chip wl"><div class="cv">${wExtra}<span style="font-size:.6rem"> (${wWaste}%)</span></div><div class="cl">Wastage</div></div>
            <div class="chip wl"><div class="cv">${wTotal}</div><div class="cl">Total</div></div>
            <div class="chip bx"><div class="cv">📦 ${wc}</div><div class="cl">Boxes</div></div>
        </div></div>`;
    }

    if(isBath) {
        const bKey=a.bath_tile_size_key||a.bath_tile_size||'1|1|8';
        const bCustom=a.bath_tile_custom||null;
        const parts=[['Light',parseFloat(a.bath_light_h||0),'light'],['Highlight',parseFloat(a.bath_high_h||0),'high'],['Dark',parseFloat(a.bath_dark_h||0),'dark']].filter(([,h])=>h>0&&perim>0);
        if(parts.length) {
            html+=`<div class="bath-wrap"><div class="bath-wrap-title">🛁 Bathroom Partitions — ${oaTileSizeLabel(bKey,bCustom)}</div><div class="bath-parts">`;
            let bTotBoxes=0;
            parts.forEach(([pName,pH,pCls])=>{
                const pSqft=Math.round(perim*pH*100)/100;
                const bc=oaCalcBoxes(pSqft,bKey,bWaste,bCustom);
                bTotBoxes+=bc;
                const pNeeded=Math.ceil(pSqft/((oaParseTileKey(bKey)||{area:1}).area));
                const pExtra=Math.ceil(pNeeded*(bWaste/100));
                html+=`<div class="bath-part ${pCls}">
                    <div class="bp-name">${pName}</div>
                    <div class="bp-row"><span class="bp-k">Height</span><span class="bp-v">${pH.toFixed(2)} ft</span></div>
                    <div class="bp-row"><span class="bp-k">Area</span><span class="bp-v">${pSqft.toFixed(2)} ft²</span></div>
                    <div class="bp-row"><span class="bp-k">Tiles</span><span class="bp-v">${pNeeded}</span></div>
                    <div class="bp-row"><span class="bp-k">Extra</span><span class="bp-v">${pExtra}</span></div>
                    <div class="bp-row"><span class="bp-k">📦 Boxes</span><span class="bp-v hi">${bc}</span></div>
                </div>`;
            });
            html+=`</div><div class="chips" style="margin-top:8px"><div class="chip bx"><div class="cv">📦 ${bTotBoxes}</div><div class="cl">Bath Total</div></div></div></div>`;
        }
    }

    if(areaPhotos.length) {
        html+=`<div style="margin-top:10px"><div style="font-size:.61rem;color:#9ca3af;text-transform:uppercase;letter-spacing:.07em;font-weight:700;margin-bottom:5px">📷 Area Photos (${areaPhotos.length})</div><div class="area-photos">`;
        areaPhotos.forEach((fname,pi)=>{ const purl=OA_UPLOAD_URL+fname; html+=`<img class="aphoto" src="${oaEsc(purl)}" alt="Photo ${pi+1}" loading="lazy" onerror="this.style.display='none'" onclick="oaOpenLb('${oaEsc(purl)}','Photo ${pi+1}')">`;});
        html+=`</div></div>`;
    }
    html+=`</div></div>`;
    return html;
}

/* ═══════════════════════════════════════════════════════════════════
   DETAIL MODAL
═══════════════════════════════════════════════════════════════════ */
function oaViewReport(r, rd, areas, detail, snaps, areaImgsByAreaId, estimate, estRooms) {
    _currentSubId    = r.submission_id;
    _currentAreaImgs = areaImgsByAreaId || {};
    _currentAreaRows = areas || [];

    document.getElementById('oa-modal-print-btn').href = OA_PRINT_BASE + '?id=' + r.submission_id;
    document.getElementById('oa-modal-title').textContent = r.owner_name + ' — ' + r.owner_number;

    const typeLabel = r.report_type==='order_completed'
        ? '<span class="rtype-order">✅ Order Completed</span>'
        : '<span class="rtype-status">🔄 Status Update</span>';

    let statusChangeHtml='';
    if(rd.old_status&&rd.new_status) {
        statusChangeHtml=`<div style="display:inline-flex;align-items:center;gap:8px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;padding:8px 14px;margin-top:6px">
            <span class="badge badge-${rd.old_status}">${rd.old_status.toUpperCase()}</span>
            <span style="color:#94a3b8;font-size:1rem">→</span>
            <span class="badge badge-${rd.new_status}">${rd.new_status.toUpperCase()}</span>
        </div>`;
    }

    const gpsHtml=r.latitude&&r.longitude
        ?`<a class="maps-link" href="https://maps.google.com/?q=${r.latitude},${r.longitude}" target="_blank">📍 ${parseFloat(r.latitude).toFixed(5)}, ${parseFloat(r.longitude).toFixed(5)} — Open Maps</a>`
        :'<em style="color:#9ca3af;font-size:.82rem">No GPS recorded</em>';

    let areasHtml='<em style="color:#9ca3af;font-size:.82rem">No area details recorded</em>';
    if(areas&&areas.length) areasHtml=areas.map((a,ai)=>oaBuildAreaPanel(a,ai,areaImgsByAreaId)).join('');

    let dealHtml='';
    if(detail&&(detail.deal_result||detail.detail_description)) {
        const ico={'Order Completed':'✅','Not Completed':'❌','Purchased from Other Vendor':'🏪','Other Reason':'📝'}[detail.deal_result]||'';
        dealHtml=`<div class="modal-sec">Deal Info</div><div class="deal-box">
            ${detail.deal_result?`<div style="font-weight:700;font-size:.9rem;margin-bottom:6px">${ico} ${oaEsc(detail.deal_result)}</div>`:''}
            ${detail.vendor_name?`<div style="font-size:.82rem;margin-bottom:2px"><strong>Vendor:</strong> ${oaEsc(detail.vendor_name)}</div>`:''}
            ${detail.other_reason?`<div style="font-size:.82rem;margin-bottom:2px"><strong>Note:</strong> ${oaEsc(detail.other_reason)}</div>`:''}
            ${detail.detail_description?`<div style="font-size:.82rem;color:#6b7280;margin-top:4px">${oaEsc(detail.detail_description)}</div>`:''}
        </div>`;
    }

    const nvd=r.next_visit_date||(detail?detail.next_visit_date:null)||null;
    const visitHtml=nvd?`<div class="modal-sec">📅 Next Visit</div><div class="visit-box">${oaFmtVisit(nvd)||'—'}</div>`:'';

    let purchaseHtml='';
    if(r.purchase_date) {
        purchaseHtml=`<div class="modal-sec">Purchase</div><div class="detail-grid">
            <div class="dg-item"><div class="dl">Purchase Date</div><div class="dv" style="color:#15803d;font-weight:700">📅 ${oaEsc(r.purchase_date)}</div></div>
            ${rd.purchase_notes?`<div class="dg-item full"><div class="dl">Notes</div><div class="dv">${oaEsc(rd.purchase_notes)}</div></div>`:''}
        </div>`;
    }

    let photosHtml='';
    const subPhotos=[
        {url:r.landmark_picture?OA_UPLOAD_URL+r.landmark_picture:'',lbl:'Landmark'},
        {url:r.additional_picture?OA_UPLOAD_URL+r.additional_picture:'',lbl:'Additional'},
        {url:r.tile_image?OA_UPLOAD_URL+r.tile_image:'',lbl:'Tile'},
    ].filter(p=>p.url);
    if(subPhotos.length){
        photosHtml=`<div class="modal-sec">Photos</div><div class="area-photos">`;
        subPhotos.forEach(p=>{photosHtml+=`<div style="display:flex;flex-direction:column;align-items:center;gap:2px"><img class="aphoto" src="${oaEsc(p.url)}" alt="${oaEsc(p.lbl)}" loading="lazy" onerror="this.closest('div').style.display='none'" onclick="oaOpenLb('${oaEsc(p.url)}','${oaEsc(p.lbl)}')"><span style="font-size:.59rem;color:#9ca3af">${oaEsc(p.lbl)}</span></div>`;});
        photosHtml+=`</div>`;
    }

    let snapsHtml='';
    if(snaps&&snaps.length){
        snapsHtml=`<div class="modal-sec">🎨 3D Visualizer (${snaps.length})</div><div class="viz-modal-grid">`;
        snaps.forEach(sn=>{const vu=OA_UPLOAD_URL+sn.filename;snapsHtml+=`<div class="viz-modal-item" onclick="oaOpenLb('${oaEsc(vu)}','3D — ${oaEsc(sn.created_at)}')"><img src="${oaEsc(vu)}" alt="Snap" loading="lazy" onerror="this.onerror=null;this.style.opacity='.3'"><div class="viz-modal-date">${oaEsc(sn.created_at?sn.created_at.substring(0,16):'')}</div></div>`;});
        snapsHtml+=`</div>`;
    }

    const estimateObj  = estimate ? {id:estimate.id, created_by_name:estimate.created_by_name||''} : null;
    const unifiedRows  = oaBuildUnifiedRows(areas, estRooms);
    const estimateHtml = buildEstimateSection(estimateObj, unifiedRows, areaImgsByAreaId);

    document.getElementById('oaViewContent').innerHTML = `
        <div class="modal-sec">Report Info</div>
        <div class="detail-grid">
            <div class="dg-item"><div class="dl">Type</div><div class="dv">${typeLabel}</div></div>
            <div class="dg-item"><div class="dl">Sales Exec</div><div class="dv"><strong>${oaEsc(r.office_admin_name)}</strong></div></div>
            <div class="dg-item full"><div class="dl">Reported At</div><div class="dv">${oaFmtDateTime(r.created_at)}</div></div>
            ${statusChangeHtml?`<div class="dg-item full"><div class="dl">Status Change</div><div class="dv">${statusChangeHtml}</div></div>`:''}
        </div>
        <div class="modal-sec">Owner Details</div>
        <div class="detail-grid">
            <div class="dg-item"><div class="dl">Name</div><div class="dv"><strong>${oaEsc(r.owner_name)}</strong>${r.order_completed?'<br><span style="font-size:.65rem;background:#dcfce7;color:#15803d;padding:1px 6px;border-radius:10px;font-weight:700">✅ Order Done</span>':''}</div></div>
            <div class="dg-item"><div class="dl">Primary Phone</div><div class="dv">${oaEsc(r.owner_number)}</div></div>
            ${r.alt_mobile?`<div class="dg-item"><div class="dl">Alt Mobile</div><div class="dv" style="color:#16a34a">${oaEsc(r.alt_mobile)}</div></div>`:''}
            <div class="dg-item"><div class="dl">Village / City</div><div class="dv">${oaEsc(r.village_city_town)}</div></div>
            <div class="dg-item"><div class="dl">Street</div><div class="dv">${oaEsc(r.street)}</div></div>
            <div class="dg-item"><div class="dl">Zone</div><div class="dv" style="color:#2563eb;font-weight:700">${oaEsc(r.dir_code)} › ${oaEsc(r.zone_code)} — ${oaEsc(r.from_location)}→${oaEsc(r.to_location)}</div></div>
            <div class="dg-item full"><div class="dl">GPS</div><div class="dv">${gpsHtml}</div></div>
        </div>
        <div class="modal-sec">Representative</div>
        <div class="detail-grid">
            <div class="dg-item"><div class="dl">Name</div><div class="dv">${oaEsc(r.representative_name||'—')}</div></div>
            <div class="dg-item"><div class="dl">Phone</div><div class="dv">${oaEsc(r.representative_number||'—')}</div></div>
        </div>
        <div class="modal-sec">🏠 Areas / Rooms (${areas?areas.length:0})</div>
        ${areasHtml}
        <div class="modal-sec">📋 Estimate</div>
        ${estimateHtml}
        ${visitHtml}${dealHtml}${purchaseHtml}${photosHtml}${snapsHtml}
    `;

    openModal('oaViewModal');
    updatePrintLink();
}

/* ═══════════════════════════════════════════════════════════════════
   ESTIMATE EDIT MODAL
   
   FORMULA (single source of truth):
   • MRP Total   = Boxes × MRP/box
   • MOP Total   = Boxes × MOP/box
   • Disc%       = 100 − (MOP/box ÷ MRP/box × 100)
   • After-Offer = Total × (1 − Offer%/100)
═══════════════════════════════════════════════════════════════════ */
(function(){
    'use strict';
    let EST_SUB_ID = null;
    let EST_ROWS   = [];

    window.openEstimateModal = function(subId, ownerName) {
        EST_SUB_ID = parseInt(subId,10);
        EST_ROWS   = [];
        document.getElementById('est-modal-title').textContent = '📋 Estimate — ' + ownerName;
        document.getElementById('est-modal-body').innerHTML = `<div class="est-loading"><svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="animation:spin 1s linear infinite"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>Loading rooms…</div>`;
        document.getElementById('est-print-link').href = OA_PRINT_BASE + '?id=' + EST_SUB_ID + '&estimate=1';
        const saveBtn = document.getElementById('est-save-btn');
        saveBtn.disabled=false; saveBtn.textContent='💾 Save Estimate'; saveBtn.style.background='';
        openModal('estModal');

        fetch(ESTIMATE_MODAL_URL + '?submission_id=' + EST_SUB_ID, {credentials:'same-origin'})
            .then(r=>{ if(!r.ok) throw new Error('Server '+r.status); return r.text(); })
            .then(text=>{
                const clean=text.trim().replace(/^\uFEFF/,'');
                let data; try { data=JSON.parse(clean); } catch(e){ throw new Error('Invalid JSON: '+e.message); }
                if(data.error) throw new Error(data.error);

                // Build unified rows from areas + saved rooms
                const areaSource = (_currentSubId && parseInt(_currentSubId,10)===EST_SUB_ID && _currentAreaRows.length)
                    ? _currentAreaRows : (data.areas||[]);
                EST_ROWS = oaBuildUnifiedRows(areaSource, data.rooms||[]);
                renderEstimateTable(data, EST_ROWS);
            })
            .catch(err=>{
                document.getElementById('est-modal-body').innerHTML=`<div style="color:#b91c1c;padding:20px;text-align:center;font-size:.85rem">❌ Failed to load estimate<br><code style="display:block;margin-top:8px;background:#fef2f2;border-radius:4px;padding:8px;text-align:left;white-space:pre-wrap;font-size:.75rem">${oaEsc(err.message)}</code></div>`;
            });
    };

    function renderEstimateTable(data, rows) {
        if(!rows||!rows.length){
            document.getElementById('est-modal-body').innerHTML='<div class="empty-state" style="padding:30px;text-align:center;color:#9ca3af">No rooms / areas found for this submission.</div>';
            return;
        }

        let html=`
        <div style="font-size:.75rem;color:#6b7280;margin-bottom:8px;display:flex;align-items:center;flex-wrap:wrap;gap:6px">
            <strong>${oaEsc(data.submission?data.submission.owner_name:'')}</strong>
            <span>·</span><span>${oaEsc(data.submission?data.submission.location:'')}</span>
            <span>·</span><span>Zone: ${oaEsc(data.submission?data.submission.zone:'')}</span>
            ${data.estimate_id
                ?`<span style="display:inline-block;font-size:.65rem;background:#dbeafe;color:#1e40af;border-radius:4px;padding:1px 6px;font-weight:700;margin-left:6px">✅ Saved #${data.estimate_id}</span>`
                :`<span style="display:inline-block;font-size:.65rem;background:#fef9c3;color:#92400e;border-radius:4px;padding:1px 6px;font-weight:700;margin-left:6px">New</span>`}
        </div>
        <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:7px 12px;margin-bottom:10px;font-size:.72rem;color:#15803d">
            📐 <strong>Formula:</strong> MRP Total = Boxes × MRP/box &nbsp;|&nbsp; MOP Total = Boxes × MOP/box &nbsp;|&nbsp;
            Disc% = 100 − (MOP/box ÷ MRP/box × 100) &nbsp;|&nbsp; After-Offer = Total × (1 − Offer%/100)
        </div>
        <div style="overflow-x:auto">
        <table class="est-room-table" id="est-table">
          <thead><tr>
            <th class="th-info" style="width:24px" title="Include in print">✓</th>
            <th class="th-info">#</th>
            <th class="th-info">Room / Area</th>
            <th class="th-info">Surface</th>
            <th class="th-info" style="text-align:right">Tile Size</th>
            <th class="th-info" style="text-align:right">Sqft</th>
            <th class="th-info" style="text-align:right">Base</th>
            <th class="th-info" style="text-align:right">Extra</th>
            <th class="th-info" style="text-align:right">Total Boxes</th>
            <th class="th-mrp" style="text-align:right">MRP ₹/box</th>
            <th class="th-mrp" style="text-align:right">MRP Total</th>
            <th class="th-disc" style="text-align:center">Disc %</th>
            <th class="th-mop" style="text-align:right">MOP ₹/box</th>
            <th class="th-mop" style="text-align:right">MOP Total</th>
            <th class="th-mop" style="text-align:right">Savings</th>
            <th class="th-offer-col" style="text-align:right">Offer %</th>
            <th class="th-offer-col" style="text-align:right">After MRP</th>
            <th class="th-offer-col" style="text-align:right">After MOP</th>
          </tr></thead><tbody>`;

        rows.forEach((rm,i)=>{
            const baseBoxes=parseInt(rm.box_count||0,10);
            const extraBox=parseInt(rm.extra_box||0,10)||0;
            const boxes   =_totalBoxes(baseBoxes,extraBox);
            const mrpPB   =parseFloat(rm.mrp_per_box||0);
            const mopPB   =parseFloat(rm.mop_per_box||0);
            const offerPct=parseFloat(rm.offer_pct||0);
            const mrpT    =_mrpTotal(boxes,mrpPB);
            const mopT    =_mopTotal(boxes,mopPB);
            const disc    =_discPct(mrpPB,mopPB);
            const save    =_savings(mrpT,mopT);
            const afterMRP=_afterOffer(mrpT,offerPct);
            const afterMOP=_afterOffer(mopT,offerPct);
            const inPrint =rm.include_in_print!==false&&rm.include_in_print!==0;

            html+=`<tr data-idx="${i}">
              <td style="text-align:center"><input type="checkbox" class="est-print-chk" data-idx="${i}" ${inPrint?'checked':''} onchange="window._estSync()"></td>
              <td style="color:#9ca3af;font-size:.7rem">${i+1}</td>
              <td><strong style="font-size:.83rem">${oaEsc(rm.area_name)}</strong></td>
              <td><span class="surf-badge ${oaSurfClass(rm.surface)}" style="font-size:.65rem">${oaSurfLabel(rm.surface)}</span></td>
              <td style="text-align:right"><span class="est-sqft-badge" style="font-size:.68rem">${oaEsc(rm.tile_size_label)||'—'}</span></td>
              <td><span class="est-info-cell">${parseFloat(rm.total_sqft||0).toFixed(2)}</span></td>
              <td><span class="est-info-cell" style="font-weight:700;color:#d97706">${baseBoxes}</span></td>
              <td><input class="est-inp" type="number" step="1" placeholder="0" data-idx="${i}" data-field="extra_box" value="${extraBox?extraBox:''}" oninput="window._estSync()" title="Use negative values to subtract boxes"></td>
              <td><span class="est-info-cell" id="est-total-boxes-${i}" style="font-weight:700;color:#d97706">📦 ${boxes}</span></td>
              <td><input class="est-inp" type="number" min="0" step="0.01" placeholder="0.00" data-idx="${i}" data-field="mrp_per_box" value="${mrpPB>0?mrpPB:''}" oninput="window._estSync()"></td>
              <td><div class="est-calc mrp" id="mrp-t-${i}">${mrpT>0?fmtINR(mrpT):'—'}</div></td>
              <td><div class="est-calc disc" id="disc-${i}">${disc>0?disc.toFixed(2)+'%':'—'}</div></td>
              <td><input class="est-inp" type="number" min="0" step="0.01" placeholder="0.00" data-idx="${i}" data-field="mop_per_box" value="${mopPB>0?mopPB:''}" oninput="window._estSync()"></td>
              <td><div class="est-calc mop" id="mop-t-${i}">${mopT>0?fmtINR(mopT):'—'}</div></td>
              <td><div class="est-calc" id="save-${i}" style="color:#7c3aed">${save>0?fmtINR(save):'—'}</div></td>
              <td><input class="est-inp offer-inp" type="number" min="0" max="100" step="0.1" placeholder="0.0" data-idx="${i}" data-field="offer_pct" value="${offerPct>0?offerPct:''}" oninput="window._estSync()" title="Offer % applied to MRP &amp; MOP totals"></td>
              <td><div class="est-calc offer-mrp" id="after-mrp-${i}">${offerPct>0&&afterMRP>0?fmtINR(afterMRP):'—'}</div></td>
              <td><div class="est-calc offer-mop" id="after-mop-${i}">${offerPct>0&&afterMOP>0?fmtINR(afterMOP):'—'}</div></td>
            </tr>`;
        });

        html+=`</tbody></table></div>
        <div class="est-total-bar">
            <div class="est-total-card est-tc-mrp"><div class="etv" id="est-grand-mrp">—</div><div class="etl">Grand MRP Total</div></div>
            <div class="est-total-card est-tc-mop"><div class="etv" id="est-grand-mop">—</div><div class="etl">Grand MOP Total</div></div>
            <div class="est-total-card est-tc-save"><div class="etv" id="est-grand-save">—</div><div class="etl">Saved Price</div></div>
            <div class="est-total-card est-tc-offer-mrp" id="est-after-mrp-card" style="display:none"><div class="etv" id="est-grand-after-mrp">—</div><div class="etl">After-Offer MRP</div></div>
            <div class="est-total-card est-tc-offer-mop" id="est-after-mop-card" style="display:none"><div class="etv" id="est-grand-after-mop">—</div><div class="etl">After-Offer MOP</div></div>
        </div>`;

        document.getElementById('est-modal-body').innerHTML = html;
        window._estSync();
    }

    window._estSync = function() {
        const table = document.getElementById('est-table');
        if(!table || !EST_ROWS.length) return;

        // Read inputs back into EST_ROWS
        table.querySelectorAll('input[data-idx][data-field]').forEach(inp=>{
            const idx=parseInt(inp.dataset.idx,10);
            if(!EST_ROWS[idx]) return;
            EST_ROWS[idx][inp.dataset.field] = parseFloat(inp.value)||0;
        });
        table.querySelectorAll('.est-print-chk').forEach(chk=>{
            const idx=parseInt(chk.dataset.idx,10);
            if(EST_ROWS[idx]) EST_ROWS[idx].include_in_print=chk.checked;
        });

        let grandMRP=0, grandMOP=0, grandAfterMRP=0, grandAfterMOP=0, hasOffer=false;

        EST_ROWS.forEach((rm,i)=>{
            const baseBoxes=parseInt(rm.box_count||0,10);
            const extraBox=parseInt(rm.extra_box||0,10)||0;
            const boxes   =_totalBoxes(baseBoxes,extraBox);
            const mrpPB   =parseFloat(rm.mrp_per_box||0);
            const mopPB   =parseFloat(rm.mop_per_box||0);
            const offerPct=parseFloat(rm.offer_pct||0);
            const mrpT    =_mrpTotal(boxes,mrpPB);
            const mopT    =_mopTotal(boxes,mopPB);
            const disc    =_discPct(mrpPB,mopPB);
            const save    =_savings(mrpT,mopT);
            const afterMRP=_afterOffer(mrpT,offerPct);
            const afterMOP=_afterOffer(mopT,offerPct);

            const eM=document.getElementById('mrp-t-'+i);
            const eP=document.getElementById('mop-t-'+i);
            const eD=document.getElementById('disc-'+i);
            const eS=document.getElementById('save-'+i);
            const eAM=document.getElementById('after-mrp-'+i);
            const eAP=document.getElementById('after-mop-'+i);
            const eTB=document.getElementById('est-total-boxes-'+i);

            if(eM) eM.textContent=mrpT>0?fmtINR(mrpT):'—';
            if(eTB) eTB.textContent='📦 '+boxes;
            if(eP) eP.textContent=mopT>0?fmtINR(mopT):'—';
            if(eD){
                if(disc>0){eD.textContent=disc.toFixed(2)+'%';eD.style.color='#15803d';}
                else if(disc<0){eD.textContent=disc.toFixed(2)+'%';eD.style.color='#b91c1c';}
                else{eD.textContent='—';eD.style.color='#9ca3af';}
            }
            if(eS) eS.textContent=save>0?fmtINR(save):'—';
            if(eAM) eAM.textContent=(offerPct>0&&afterMRP>0)?fmtINR(afterMRP):'—';
            if(eAP) eAP.textContent=(offerPct>0&&afterMOP>0)?fmtINR(afterMOP):'—';

            if(rm.include_in_print){
                grandMRP+=mrpT; grandMOP+=mopT; grandAfterMRP+=afterMRP; grandAfterMOP+=afterMOP;
                if(offerPct>0) hasOffer=true;
            }
        });

        const grandSave=_savings(grandMRP,grandMOP);

        const eGM =document.getElementById('est-grand-mrp');
        const eGP =document.getElementById('est-grand-mop');
        const eGS =document.getElementById('est-grand-save');
        const eGAM=document.getElementById('est-grand-after-mrp');
        const eGAP=document.getElementById('est-grand-after-mop');
        const cAM =document.getElementById('est-after-mrp-card');
        const cAP =document.getElementById('est-after-mop-card');

        if(eGM) eGM.textContent=grandMRP>0?fmtINR(grandMRP):'—';
        if(eGP) eGP.textContent=grandMOP>0?fmtINR(grandMOP):'—';
        if(eGS) eGS.textContent=grandSave>0?fmtINR(grandSave):'—';
        if(eGAM) eGAM.textContent=(grandAfterMRP>0&&hasOffer)?fmtINR(grandAfterMRP):'—';
        if(eGAP) eGAP.textContent=(grandAfterMOP>0&&hasOffer)?fmtINR(grandAfterMOP):'—';
        if(cAM) cAM.style.display=hasOffer?'':'none';
        if(cAP) cAP.style.display=hasOffer?'':'none';
    };

    window.saveEstimate = function() {
        if(!EST_SUB_ID||!EST_ROWS.length) return;
        const btn=document.getElementById('est-save-btn');
        btn.disabled=true; btn.textContent='⏳ Saving…';

        const fd=new FormData();
        fd.append('submission_id', EST_SUB_ID);
        EST_ROWS.forEach((rm,i)=>{
            const boxes   =parseInt(rm.box_count||0,10);
            const mrpPB   =parseFloat(rm.mrp_per_box||0);
            const mopPB   =parseFloat(rm.mop_per_box||0);
            const offerPct=parseFloat(rm.offer_pct||0);
            fd.append(`rooms[${i}][area_id]`,        rm.area_id||'');
            fd.append(`rooms[${i}][area_name]`,       rm.area_name||'');
            fd.append(`rooms[${i}][surface]`,         rm.surface||'floor');
            fd.append(`rooms[${i}][total_sqft]`,      rm.total_sqft||0);
            fd.append(`rooms[${i}][tile_size_label]`, rm.tile_size_label||'');
            fd.append(`rooms[${i}][box_count]`,       boxes);
            fd.append(`rooms[${i}][extra_box]`,       parseInt(rm.extra_box||0,10)||0);
            fd.append(`rooms[${i}][mrp_per_box]`,     mrpPB);
            fd.append(`rooms[${i}][mop_per_box]`,     mopPB);
            // Offer stored as % number string
            fd.append(`rooms[${i}][offer]`,           offerPct>0?String(offerPct):'');
            fd.append(`rooms[${i}][include_in_print]`, rm.include_in_print?'1':'0');
        });

        fetch(ESTIMATE_MODAL_URL, {method:'POST', body:fd, credentials:'same-origin'})
            .then(r=>r.text())
            .then(text=>{
                let data; try{data=JSON.parse(text.trim());}catch{throw new Error('Bad JSON: '+text.substring(0,200));}
                if(data.success){
                    btn.textContent='✅ Saved!'; btn.style.background='#16a34a';
                    markEstimateSavedForPrint(EST_SUB_ID);
                    if(_currentSubId&&parseInt(_currentSubId,10)===EST_SUB_ID) refreshEstimateInDetailModal();
                    setTimeout(()=>{btn.disabled=false;btn.textContent='💾 Save Estimate';btn.style.background='';},2500);
                } else {
                    throw new Error(data.error||'Unknown error');
                }
            })
            .catch(err=>{
                alert('Save failed: '+err.message);
                btn.disabled=false; btn.textContent='💾 Save Estimate';
            });
    };
})();

/* ── Lightbox ──────────────────────────────────────────────────── */
function oaOpenLb(url,cap){document.getElementById('oa-lb-img').src=url;document.getElementById('oa-lb-cap').textContent=cap||'';document.getElementById('oa-lb').classList.add('open');document.body.style.overflow='hidden';}
function oaCloseLb(){document.getElementById('oa-lb').classList.remove('open');document.body.style.overflow='';}
document.addEventListener('keydown',e=>{if(e.key==='Escape')oaCloseLb();});

/* ── Phone filter ──────────────────────────────────────────────── */
function clearPhoneFilter(){const i=document.getElementById('phoneInput');if(i)i.value='';document.getElementById('filterForm').submit();}
document.getElementById('phoneInput')?.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();document.getElementById('filterForm').submit();}});

/* ── List/Grid toggle ──────────────────────────────────────────── */
function setView(v){
    const lw=document.getElementById('rptListView'),gw=document.getElementById('rptGridView');
    const bl=document.getElementById('btn-list'),bg=document.getElementById('btn-grid');
    if(v==='grid'){lw.style.display='none';gw.style.display='grid';bl.classList.remove('active');bg.classList.add('active');}
    else{lw.style.display='';gw.style.display='none';bl.classList.add('active');bg.classList.remove('active');}
    try{localStorage.setItem('rptView',v);}catch(e){}
}
(function(){let s='list';try{s=localStorage.getItem('rptView')||'list';}catch(e){}setView(s);})();
</script>
