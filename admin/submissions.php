<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
requireLogin('main_admin');

$db   = getDB();
$user = currentUser();

// ── Ensure soft-delete column exists ────────────────────────────────────────────
try { $db->exec("ALTER TABLE submissions ADD COLUMN deleted_at DATETIME DEFAULT NULL"); } catch (Throwable $e) {}

// ── Handle removal request approve / reject ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'approve_removal') {
        verifyCsrf();
        $reqId = (int)$_POST['request_id'];
        // Fetch the request
        $reqStmt = $db->prepare("SELECT * FROM submission_remove_requests WHERE id=? AND status='pending'");
        $reqStmt->execute([$reqId]);
        $req = $reqStmt->fetch();
        if ($req) {
            $subId = (int)$req['submission_id'];
            $db->beginTransaction();
            try {
                // Soft-delete the submission so office_reports still shows it
                $db->prepare("UPDATE submissions SET deleted_at = NOW() WHERE id=?")->execute([$subId]);
                // Mark related areas/details as hidden (keep for office_reports history)
                // Note: we do NOT delete submission_areas/status_details so office_reports shows full history
                // Mark the request as approved
                $db->prepare("UPDATE submission_remove_requests SET status='approved', reviewed_by=?, reviewed_at=NOW() WHERE id=?")
                   ->execute([$user['id'], $reqId]);
                $db->commit();
                logActivity($user['id'], 'approve_removal', "Approved removal of submission id=$subId (request id=$reqId)");
                $msg = 'Record deleted successfully.';
                $msgType = 'success';
            } catch (Exception $e) {
                $db->rollBack();
                $msg = 'Error deleting record: ' . $e->getMessage();
                $msgType = 'error';
            }
        } else {
            $msg = 'Request not found or already processed.';
            $msgType = 'error';
        }
    }

    if ($action === 'reject_removal') {
        verifyCsrf();
        $reqId       = (int)$_POST['request_id'];
        $adminNote   = trim($_POST['admin_note'] ?? '');
        $reqStmt = $db->prepare("SELECT * FROM submission_remove_requests WHERE id=? AND status='pending'");
        $reqStmt->execute([$reqId]);
        $req = $reqStmt->fetch();
        if ($req) {
            $db->prepare("UPDATE submission_remove_requests SET status='rejected', reviewed_by=?, reviewed_at=NOW(), admin_note=? WHERE id=?")
               ->execute([$user['id'], $adminNote, $reqId]);
            logActivity($user['id'], 'reject_removal', "Rejected removal request id=$reqId for submission id={$req['submission_id']}");
            $msg = 'Removal request rejected.';
            $msgType = 'success';
        } else {
            $msg = 'Request not found or already processed.';
            $msgType = 'error';
        }
    }

    if ($action === 'bulk_delete') {
        header('Content-Type: application/json');
        $ids = json_decode($_POST['ids'] ?? '[]', true);
        $ids = array_filter(array_map('intval', (array)$ids));
        if (empty($ids)) { echo json_encode(['success'=>false,'message'=>'No IDs provided']); exit; }
        try {
            $db->beginTransaction();
            $inList = implode(',', array_fill(0, count($ids), '?'));
            // Soft-delete: set deleted_at so office_reports still shows history
            $db->prepare("UPDATE submissions SET deleted_at = NOW() WHERE id IN ($inList) AND deleted_at IS NULL")->execute($ids);
            $db->commit();
            echo json_encode(['success'=>true,'deleted'=>count($ids)]);
        } catch (Exception $e) { $db->rollBack(); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }
}

// ── Excel Export handler ─────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $filterStatus   = $_GET['status']     ?? '';
    $filterUser     = (int)($_GET['user_id']    ?? 0);
    $filterZone     = (int)($_GET['zone_id']    ?? 0);
    $filterRep      = trim($_GET['rep_name']    ?? '');
    $filterRepN     = trim($_GET['rep_number']  ?? '');
    $filterDateFrom = trim($_GET['date_from']   ?? '');
    $filterDateTo   = trim($_GET['date_to']     ?? '');
    $filterVisit    = trim($_GET['visit']       ?? '');
    $today    = date('Y-m-d');
    $in10Days = date('Y-m-d', strtotime('+10 days'));

    $where  = ['1=1'];
    $params = [];
    if ($filterStatus)   { $where[] = 's.status=?';                    $params[] = $filterStatus; }
    if ($filterUser)     { $where[] = 's.submitted_by=?';              $params[] = $filterUser; }
    if ($filterZone)     { $where[] = 's.sub_direction_id=?';          $params[] = $filterZone; }
    if ($filterRep)      { $where[] = 's.representative_name LIKE ?';  $params[] = '%'.$filterRep.'%'; }
    if ($filterRepN)     { $where[] = 's.representative_number LIKE ?';$params[] = '%'.$filterRepN.'%'; }
    if ($filterDateFrom) { $where[] = "DATE(COALESCE(s.device_submitted_at,s.submitted_at)) >= ?"; $params[] = $filterDateFrom; }
    if ($filterDateTo)   { $where[] = "DATE(COALESCE(s.device_submitted_at,s.submitted_at)) <= ?"; $params[] = $filterDateTo; }
    if ($filterVisit === 'upcoming10') { $where[] = "s.next_visit_date IS NOT NULL AND s.next_visit_date >= ? AND s.next_visit_date <= ?"; $params[] = $today; $params[] = $in10Days; }
    elseif ($filterVisit === 'overdue') { $where[] = "s.next_visit_date IS NOT NULL AND s.next_visit_date < ? AND (s.order_completed=0 OR s.order_completed IS NULL)"; $params[] = $today; }
    elseif ($filterVisit === 'has_visit') { $where[] = "s.next_visit_date IS NOT NULL"; }

    $sql = "SELECT s.*, u.username, sd.code AS zone, d.code AS dir_code
            FROM submissions s
            JOIN users u            ON s.submitted_by     = u.id
            JOIN sub_directions sd  ON s.sub_direction_id = sd.id
            JOIN directions d       ON sd.direction_id    = d.id
            WHERE " . implode(' AND ', $where) . "
              AND s.deleted_at IS NULL
              AND s.id = (SELECT MAX(s2.id) FROM submissions s2 WHERE s2.owner_number=s.owner_number AND s2.sub_direction_id=s.sub_direction_id AND s2.deleted_at IS NULL)
            ORDER BY COALESCE(s.device_submitted_at,s.submitted_at) DESC";

    $stmt = $db->prepare($sql); $stmt->execute($params);
    $exportSubs = $stmt->fetchAll();

    $subIds = array_column($exportSubs, 'id');
    $areasMap = []; $detailsMap = [];
    if ($subIds) {
        $inList = implode(',', array_fill(0, count($subIds), '?'));
        $aStmt = $db->prepare("SELECT * FROM submission_areas WHERE submission_id IN ($inList) ORDER BY submission_id,sort_order"); $aStmt->execute($subIds);
        foreach ($aStmt->fetchAll() as $a) $areasMap[$a['submission_id']][] = $a;
        $dStmt = $db->prepare("SELECT * FROM submission_status_details WHERE submission_id IN ($inList)"); $dStmt->execute($subIds);
        foreach ($dStmt->fetchAll() as $d) $detailsMap[$d['submission_id']] = $d;
    }

    $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'] ?? '';
    $imgBase  = defined('UPLOAD_URL') ? UPLOAD_URL : ($scheme . '://' . $host . '/uploads/');
    $filename = 'submissions_' . date('Y-m-d_His') . '.xls';

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    echo "\xEF\xBB\xBF";
    ?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head><meta charset="UTF-8"><!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>Submissions</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->
<style>body{font-family:Arial,sans-serif;font-size:11px}table{border-collapse:collapse;width:100%}th{background-color:#1d4ed8;color:#fff;font-weight:bold;padding:8px 10px;text-align:left;white-space:nowrap;border:1px solid #1e40af;font-size:11px}td{padding:6px 10px;border:1px solid #e5e7eb;vertical-align:top;font-size:11px}tr:nth-child(even) td{background-color:#f8fafc}.badge-hot{background:#fee2e2;color:#b91c1c;padding:2px 8px;border-radius:4px;font-weight:700;font-size:10px}.badge-warm{background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:4px;font-weight:700;font-size:10px}.badge-cold{background:#eff6ff;color:#1d4ed8;padding:2px 8px;border-radius:4px;font-weight:700;font-size:10px}.overdue{background:#fee2e2;color:#b91c1c;padding:2px 6px;border-radius:4px;font-weight:700}.upcoming{background:#fef9c3;color:#854d0e;padding:2px 6px;border-radius:4px;font-weight:700}.future{background:#eff6ff;color:#1d4ed8;padding:2px 6px;border-radius:4px;font-weight:700}.sqft{color:#1d4ed8;font-weight:700}.total-sqft{color:#16a34a;font-weight:700}.img-link{color:#2563eb;text-decoration:underline;font-size:10px}.completed{background:#dcfce7;color:#15803d;padding:2px 6px;border-radius:4px;font-weight:700;font-size:10px}.title-row td{background:#1e3a5f;color:#fff;font-size:14px;font-weight:bold;text-align:center;padding:12px}.meta-row td{background:#f1f5f9;color:#475569;font-size:10px;padding:4px 10px}</style>
</head><body><table>
  <tr class="title-row"><td colspan="26">📋 Submissions Export — Generated <?= date('d M Y, h:i A') ?></td></tr>
  <tr class="meta-row"><td colspan="26">Total Records: <?= count($exportSubs) ?><?php if($filterStatus) echo ' | Status: '.ucfirst($filterStatus); if($filterRep) echo ' | Rep: '.htmlspecialchars($filterRep); if($filterDateFrom||$filterDateTo) echo ' | Date: '.($filterDateFrom?:'—').' to '.($filterDateTo?:'—'); if($filterVisit) echo ' | Visit Filter: '.ucfirst(str_replace('_',' ',$filterVisit)); ?></td></tr>
  <tr><th>#</th><th>Owner Name</th><th>Owner Phone</th><th>Status</th><th>Order Completed</th><th>Purchase Date</th><th>Representative Name</th><th>Representative Phone</th><th>Village / City / Town</th><th>Street</th><th>GPS Latitude</th><th>GPS Longitude</th><th>Google Maps Link</th><th>Areas &amp; Details</th><th>Total Sq Ft</th><th>Next Visit Date</th><th>Deal Result</th><th>Vendor Name</th><th>Deal Notes</th><th>Zone</th><th>Submitted By</th><th>Submitted Date</th><th>Submitted Time</th><th>Time Source</th><th>Landmark Photo</th><th>Additional Photo</th></tr>
  <?php if ($exportSubs): foreach ($exportSubs as $i => $s):
    $areas=$areasMap[$s['id']]??[]; $detail=$detailsMap[$s['id']]??null;
    $totalSqft=array_sum(array_filter(array_column($areas,'total_sqft')));
    $nvd=$s['next_visit_date']??($detail['next_visit_date']??null);
    $st=$s['device_submitted_at']?:$s['submitted_at'];
    $areaLines=[];
    foreach($areas as $a){$sqft=$a['total_sqft']??$a['area_sqft']??null;$lb=($a['area_length']&&$a['area_breadth'])?number_format($a['area_length'],1).'×'.number_format($a['area_breadth'],1).' ft':'';$line=$a['area_type'];if($sqft)$line.=' | '.number_format($sqft,0).' sqft';if($lb)$line.=' ('.$lb.')';if($a['area_count'])$line.=' · '.$a['area_count'].' units';$areaLines[]=$line;}
    $areasText=implode("\n",$areaLines);
    $visitClass='';$visitLabel='';
    $isCompleted = !empty($s['order_completed']);
    if($nvd){$visitLabel=date('d M Y',strtotime($nvd));if($nvd<$today && !$isCompleted){$visitClass='overdue';$visitLabel='🔴 Overdue — '.$visitLabel;}elseif($nvd<=$in10Days && !$isCompleted){$visitClass='upcoming';$visitLabel='🟡 '.$visitLabel;}else{$visitClass='future';$visitLabel='📅 '.$visitLabel;}}
    $landmarkUrl=$s['landmark_picture']?$imgBase.$s['landmark_picture']:'';
    $additionalUrl=$s['additional_picture']?$imgBase.$s['additional_picture']:'';
  ?>
  <tr>
    <td><?= $i+1 ?></td><td><strong><?= htmlspecialchars($s['owner_name']) ?></strong></td><td><?= htmlspecialchars($s['owner_number']) ?></td>
    <td><span class="badge-<?= $s['status'] ?>"><?= strtoupper($s['status']) ?></span></td>
    <td><?= $s['order_completed']?'<span class="completed">✅ Completed</span>':'' ?></td>
    <td><?= !empty($s['purchase_date'])?'<strong style="color:#15803d">📅 '.date('d M Y',strtotime($s['purchase_date'])).'</strong>':'' ?></td>
    <td><?= htmlspecialchars($s['representative_name']) ?></td><td><?= htmlspecialchars($s['representative_number']) ?></td>
    <td><?= htmlspecialchars($s['village_city_town']) ?></td><td><?= htmlspecialchars($s['street']) ?></td>
    <td><?= $s['latitude']?htmlspecialchars($s['latitude']):'' ?></td><td><?= $s['longitude']?htmlspecialchars($s['longitude']):'' ?></td>
    <td><?php if($s['latitude']&&$s['longitude']): ?><a class="img-link" href="https://maps.google.com/?q=<?= $s['latitude'] ?>,<?= $s['longitude'] ?>">📍 Open in Google Maps</a><?php endif; ?></td>
    <td style="white-space:pre-line;min-width:200px"><?= htmlspecialchars($areasText) ?></td>
    <td class="total-sqft"><?= $totalSqft>0?number_format($totalSqft,0):'' ?></td>
    <td><?= $nvd?'<span class="'.$visitClass.'">'.$visitLabel.'</span>':'' ?></td>
    <td><?= $detail&&$detail['deal_result']?htmlspecialchars($detail['deal_result']):'' ?></td>
    <td><?= $detail&&$detail['vendor_name']?htmlspecialchars($detail['vendor_name']):'' ?></td>
    <td><?php $notes=[];if($detail&&$detail['vendor_details'])$notes[]='Vendor Info: '.$detail['vendor_details'];if($detail&&$detail['other_reason'])$notes[]='Note: '.$detail['other_reason'];if($detail&&$detail['detail_description'])$notes[]=$detail['detail_description'];echo htmlspecialchars(implode(' | ',$notes)); ?></td>
    <td><?= htmlspecialchars($s['dir_code'].' › '.$s['zone']) ?></td>
    <td><?= htmlspecialchars($s['username']) ?></td>
    <td><?= $st?date('d M Y',strtotime($st)):'' ?></td><td><?= $st?date('h:i:s A',strtotime($st)):'' ?></td>
    <td><?= $s['device_submitted_at']?'✔ Device':'⚠ Server' ?></td>
    <td><?php if($landmarkUrl): ?><a class="img-link" href="<?= htmlspecialchars($landmarkUrl) ?>" target="_blank">📷 Download Landmark Photo</a><?php endif; ?></td>
    <td><?php if($additionalUrl): ?><a class="img-link" href="<?= htmlspecialchars($additionalUrl) ?>" target="_blank">📷 Download Additional Photo</a><?php endif; ?></td>
  </tr>
  <?php endforeach; else: ?>
  <tr><td colspan="26" style="text-align:center;padding:20px;color:#9ca3af">No records found.</td></tr>
  <?php endif; ?>
</table></body></html>
    <?php exit;
}

// ── TODAY'S STATS ─────────────────────────────────────────────────────────────
$today    = date('Y-m-d');
$in10Days = date('Y-m-d', strtotime('+10 days'));

$todayHotStmt = $db->prepare("SELECT COUNT(*) FROM submissions WHERE status='hot'  AND DATE(COALESCE(device_submitted_at,submitted_at))=?"); $todayHotStmt->execute([$today]); $todayHot=(int)$todayHotStmt->fetchColumn();
$todayWarmStmt= $db->prepare("SELECT COUNT(*) FROM submissions WHERE status='warm' AND DATE(COALESCE(device_submitted_at,submitted_at))=?"); $todayWarmStmt->execute([$today]); $todayWarm=(int)$todayWarmStmt->fetchColumn();
$todayColdStmt= $db->prepare("SELECT COUNT(*) FROM submissions WHERE status='cold' AND DATE(COALESCE(device_submitted_at,submitted_at))=?"); $todayColdStmt->execute([$today]); $todayCold=(int)$todayColdStmt->fetchColumn();
$todayTotal = $todayHot + $todayWarm + $todayCold;

// Last 30 days quick-nav
$recentDatesStmt = $db->query("
    SELECT DATE(COALESCE(device_submitted_at,submitted_at)) AS sub_date,
           SUM(status='hot')  AS hot,
           SUM(status='warm') AS warm,
           SUM(status='cold') AS cold,
           COUNT(*)           AS total
    FROM submissions
    WHERE DATE(COALESCE(device_submitted_at,submitted_at)) >= CURDATE() - INTERVAL 30 DAY
      AND DATE(COALESCE(device_submitted_at,submitted_at)) < CURDATE()
    GROUP BY sub_date
    ORDER BY sub_date DESC
    LIMIT 10
");
$recentDates = $recentDatesStmt->fetchAll();

// ── Pending removal requests ───────────────────────────────────────────────────
$pendingRemovalsCount = (int)$db->query("SELECT COUNT(*) FROM submission_remove_requests WHERE status='pending'")->fetchColumn();
$allRemovalRequests   = $db->query("
    SELECT srr.*, s.owner_name, s.owner_number, s.village_city_town, s.street, s.status AS sub_status,
           u.username AS requested_by_name,
           sd.code AS zone, d.code AS dir_code,
           rv.username AS reviewed_by_name
    FROM submission_remove_requests srr
    JOIN submissions s    ON srr.submission_id = s.id
    JOIN users u          ON srr.requested_by  = u.id
    JOIN sub_directions sd ON s.sub_direction_id = sd.id
    JOIN directions d      ON sd.direction_id    = d.id
    LEFT JOIN users rv     ON srr.reviewed_by   = rv.id
    ORDER BY FIELD(srr.status,'pending','rejected','approved'), srr.created_at DESC
")->fetchAll();

// ── Filters ──────────────────────────────────────────────────────────────────
$filterStatus     = $_GET['status']       ?? '';
$filterUser       = (int)($_GET['user_id']      ?? 0);
$filterZone       = (int)($_GET['zone_id']      ?? 0);
$filterOwnerPhone = trim($_GET['owner_phone']   ?? '');
$filterRepName    = trim($_GET['rep_name']      ?? '');
$filterRepPhone   = trim($_GET['rep_phone']     ?? '');
$filterDateFrom   = trim($_GET['date_from']     ?? '');
$filterDateTo     = trim($_GET['date_to']       ?? '');
$filterVisit      = trim($_GET['visit']         ?? '');
$sortBy           = trim($_GET['sort_by']       ?? 'date_desc');
$activeSection    = ($_GET['section'] ?? '') === 'removals' ? 'removals' : 'submissions';

$where  = ['1=1'];
$params = [];
if ($filterStatus)     { $where[] = 's.status=?';                      $params[] = $filterStatus; }
if ($filterUser)       { $where[] = 's.submitted_by=?';                $params[] = $filterUser; }
if ($filterZone)       { $where[] = 's.sub_direction_id=?';            $params[] = $filterZone; }
if ($filterOwnerPhone) { $where[] = 's.owner_number LIKE ?';           $params[] = '%'.$filterOwnerPhone.'%'; }
if ($filterRepName)    { $where[] = 's.representative_name LIKE ?';    $params[] = '%'.$filterRepName.'%'; }
if ($filterRepPhone)   { $where[] = 's.representative_number LIKE ?';  $params[] = '%'.$filterRepPhone.'%'; }

if ($filterDateFrom) { $where[] = "DATE(COALESCE(s.device_submitted_at,s.submitted_at)) >= ?"; $params[] = $filterDateFrom; }
if ($filterDateTo)   { $where[] = "DATE(COALESCE(s.device_submitted_at,s.submitted_at)) <= ?"; $params[] = $filterDateTo; }
if ($filterVisit === 'upcoming10') { $where[] = "s.next_visit_date IS NOT NULL AND s.next_visit_date >= ? AND s.next_visit_date <= ?"; $params[] = $today; $params[] = $in10Days; }
elseif ($filterVisit === 'overdue') { $where[] = "s.next_visit_date IS NOT NULL AND s.next_visit_date < ? AND (s.order_completed=0 OR s.order_completed IS NULL)"; $params[] = $today; }
elseif ($filterVisit === 'has_visit') { $where[] = "s.next_visit_date IS NOT NULL"; }

$orderClause = "COALESCE(s.device_submitted_at,s.submitted_at) DESC";
switch ($sortBy) {
    case 'date_asc':    $orderClause="COALESCE(s.device_submitted_at,s.submitted_at) ASC"; break;
    case 'time_asc':    $orderClause="TIME(COALESCE(s.device_submitted_at,s.submitted_at)) ASC"; break;
    case 'time_desc':   $orderClause="TIME(COALESCE(s.device_submitted_at,s.submitted_at)) DESC"; break;
    case 'name_asc':    $orderClause="s.owner_name ASC"; break;
    case 'name_desc':   $orderClause="s.owner_name DESC"; break;
    case 'visit_asc':   $orderClause="s.next_visit_date IS NULL, s.next_visit_date ASC"; break;
    case 'visit_desc':  $orderClause="s.next_visit_date IS NULL, s.next_visit_date DESC"; break;
    case 'status_hot':  $orderClause="CASE WHEN s.status='hot' THEN 0 WHEN s.status='warm' THEN 1 ELSE 2 END, COALESCE(s.device_submitted_at,s.submitted_at) DESC"; break;
    case 'status_warm': $orderClause="CASE WHEN s.status='warm' THEN 0 WHEN s.status='hot' THEN 1 ELSE 2 END, COALESCE(s.device_submitted_at,s.submitted_at) DESC"; break;
    case 'status_cold': $orderClause="CASE WHEN s.status='cold' THEN 0 WHEN s.status='warm' THEN 1 ELSE 2 END, COALESCE(s.device_submitted_at,s.submitted_at) DESC"; break;
}

$sql = "SELECT s.*, u.username, sd.code AS zone, d.code AS dir_code
        FROM submissions s
        JOIN users u            ON s.submitted_by     = u.id
        JOIN sub_directions sd  ON s.sub_direction_id = sd.id
        JOIN directions d       ON sd.direction_id    = d.id
        WHERE " . implode(' AND ', $where) . "
          AND s.deleted_at IS NULL
          AND s.id = (SELECT MAX(s2.id) FROM submissions s2 WHERE s2.owner_number=s.owner_number AND s2.sub_direction_id=s.sub_direction_id AND s2.deleted_at IS NULL)
        ORDER BY " . $orderClause;

$stmt = $db->prepare($sql); $stmt->execute($params);
$subs = $stmt->fetchAll();

$subIds = array_column($subs, 'id');
$areasMap = []; $detailsMap = [];
if ($subIds) {
    $inList = implode(',', array_fill(0, count($subIds), '?'));
    $aStmt  = $db->prepare("SELECT * FROM submission_areas WHERE submission_id IN ($inList) ORDER BY submission_id,sort_order"); $aStmt->execute($subIds);
    foreach ($aStmt->fetchAll() as $a) $areasMap[$a['submission_id']][] = $a;
    $dStmt  = $db->prepare("SELECT * FROM submission_status_details WHERE submission_id IN ($inList)"); $dStmt->execute($subIds);
    foreach ($dStmt->fetchAll() as $d) $detailsMap[$d['submission_id']] = $d;
}

$upcomingCount = 0; $overdueCount = 0;
foreach ($subs as $s) {
    if (!$s['next_visit_date']) continue;
    $isComp = !empty($s['order_completed']);
    if ($s['next_visit_date'] < $today && !$isComp) $overdueCount++;
    elseif ($s['next_visit_date'] <= $in10Days && !$isComp) $upcomingCount++;
}

$zones = $db->query("SELECT id,code FROM sub_directions ORDER BY code")->fetchAll();

function buildExportUrl($params) {
    $q = http_build_query(array_filter(['export'=>'excel','status'=>$params['status'],'zone_id'=>$params['zone_id']?:'','owner_phone'=>$params['owner_phone'],'rep_name'=>$params['rep_name'],'rep_phone'=>$params['rep_phone'],'date_from'=>$params['date_from'],'date_to'=>$params['date_to'],'visit'=>$params['visit']]));
    return 'submissions.php?'.$q;
}
$exportUrl = buildExportUrl(['status'=>$filterStatus,'zone_id'=>$filterZone,'owner_phone'=>$filterOwnerPhone,'rep_name'=>$filterRepName,'rep_phone'=>$filterRepPhone,'date_from'=>$filterDateFrom,'date_to'=>$filterDateTo,'visit'=>$filterVisit]);

// ── Executive Stats (for stats section with date filters) ─────────────────────
$statDateFrom = trim($_GET['stat_from'] ?? '');
$statDateTo   = trim($_GET['stat_to']   ?? '');
$statRole     = trim($_GET['stat_role'] ?? '');

$statWhere = [];
$statParams = [];
if ($statDateFrom) { $statWhere[] = "DATE(COALESCE(s.device_submitted_at,s.submitted_at)) >= ?"; $statParams[] = $statDateFrom; }
if ($statDateTo)   { $statWhere[] = "DATE(COALESCE(s.device_submitted_at,s.submitted_at)) <= ?"; $statParams[] = $statDateTo; }
$statWhereSQL = $statWhere ? ' AND ' . implode(' AND ', $statWhere) : '';

// Marketing Executives Summary
$mktgStmt = $db->prepare("
    SELECT COUNT(*) AS total,
           SUM(s.status='hot')  AS hot,
           SUM(s.status='warm') AS warm,
           SUM(s.status='cold') AS cold,
           SUM(s.order_completed=1) AS completed
    FROM submissions s
    JOIN users u ON s.submitted_by = u.id
    WHERE u.role='sub_admin' $statWhereSQL
");
$mktgStmt->execute($statParams);
$mktgStats = $mktgStmt->fetch();

// Sales Executives Summary
$salesStmt = $db->prepare("
    SELECT COUNT(*) AS total,
           SUM(s.status='hot')  AS hot,
           SUM(s.status='warm') AS warm,
           SUM(s.status='cold') AS cold,
           SUM(s.order_completed=1) AS completed
    FROM submissions s
    JOIN users u ON s.submitted_by = u.id
    WHERE u.role='office_admin' $statWhereSQL
");
$salesStmt->execute($statParams);
$salesStats = $salesStmt->fetch();

// Individual Executive Breakdown (Marketing & Sales Executives)
$execListStmt = $db->prepare("
    SELECT u.id, u.username, u.role,
           COUNT(s.id) AS total,
           SUM(s.status='hot')  AS hot,
           SUM(s.status='warm') AS warm,
           SUM(s.status='cold') AS cold,
           SUM(s.order_completed=1) AS completed
    FROM users u
    JOIN submissions s ON s.submitted_by = u.id $statWhereSQL
    WHERE u.role IN ('sub_admin','office_admin')
    GROUP BY u.id, u.username, u.role
    ORDER BY total DESC, u.username ASC
");
$execListStmt->execute($statParams);
$execList = $execListStmt->fetchAll();

// Daywise breakdown per executive
$daywiseStmt = $db->prepare("
    SELECT s.submitted_by AS user_id,
           DATE(COALESCE(s.device_submitted_at,s.submitted_at)) AS sub_date,
           SUM(s.status='hot')  AS hot,
           SUM(s.status='warm') AS warm,
           SUM(s.status='cold') AS cold,
           SUM(s.order_completed=1) AS completed,
           COUNT(*)           AS total
    FROM submissions s
    JOIN users u ON s.submitted_by = u.id
    WHERE u.role IN ('sub_admin','office_admin') $statWhereSQL
    GROUP BY s.submitted_by, sub_date
    ORDER BY sub_date DESC
");
$daywiseStmt->execute($statParams);
$daywiseRows = $daywiseStmt->fetchAll();

$daywiseByUser = [];
foreach ($daywiseRows as $dr) {
    $daywiseByUser[(int)$dr['user_id']][] = $dr;
}

function visitBadgeSub($d, $today, $in10, $orderCompleted = false) {
    if (!$d) return '<span style="color:var(--c-gray-300);font-size:.78rem">—</span>';
    $label = date('d M Y', strtotime($d));
    if ($d < $today && !$orderCompleted)   return "<span style='display:inline-block;padding:2px 8px;border-radius:12px;font-size:.72rem;font-weight:700;background:#fee2e2;color:#b91c1c;border:1px solid #fca5a5;white-space:nowrap'>🔴 Overdue<br>$label</span>";
    if ($d <= $in10 && !$orderCompleted)   return "<span style='display:inline-block;padding:2px 8px;border-radius:12px;font-size:.72rem;font-weight:700;background:#fef9c3;color:#854d0e;border:1px solid #fde68a;white-space:nowrap'>🟡 $label</span>";
    return "<span style='display:inline-block;padding:2px 8px;border-radius:12px;font-size:.72rem;font-weight:700;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;white-space:nowrap'>📅 $label</span>";
}

pageHead('Submissions');
topbar($user['username'], $user['role']);
?>
<style>
/* ── Section tabs ── */
.section-tabs {
  display: flex;
  gap: 0;
  margin-bottom: 20px;
  border: 1.5px solid var(--c-gray-200);
  border-radius: 10px;
  overflow: hidden;
  width: fit-content;
}
.section-tab {
  padding: 9px 20px;
  font-size: .83rem;
  font-weight: 700;
  cursor: pointer;
  border: none;
  background: #fff;
  color: var(--c-gray-500);
  transition: all .15s;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  border-right: 1.5px solid var(--c-gray-200);
}
.section-tab:last-child { border-right: none; }
.section-tab:hover { background: var(--c-gray-50); color: var(--c-gray-700); }
.section-tab.active {
  background: var(--c-primary);
  color: #fff;
}
.section-tab.tab-orange.active { background: #ea580c; }
.tab-badge {
  background: #ea580c;
  color: #fff;
  font-size: .62rem;
  font-weight: 800;
  padding: 1px 6px;
  border-radius: 99px;
  min-width: 16px;
  text-align: center;
}
.section-tab.active .tab-badge { background: rgba(255,255,255,.3); }

/* ── Removal requests table ── */
.removals-wrap {
  background: #fff;
  border: 1.5px solid var(--c-gray-200);
  border-radius: 12px;
  overflow: hidden;
}
.removals-header {
  background: linear-gradient(135deg, #fff7ed, #ffedd5);
  border-bottom: 1.5px solid #fed7aa;
  padding: 16px 20px;
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.removals-title {
  font-size: .95rem;
  font-weight: 700;
  color: #9a3412;
}
.removals-sub {
  font-size: .78rem;
  color: #c2410c;
  margin-top: 2px;
}
.rr-filter-pills {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
  padding: 12px 20px;
  border-bottom: 1px solid var(--c-gray-100);
  background: var(--c-gray-50);
}
.rr-pill {
  padding: 4px 12px;
  border-radius: 99px;
  font-size: .75rem;
  font-weight: 600;
  cursor: pointer;
  border: 1.5px solid var(--c-gray-200);
  background: #fff;
  color: var(--c-gray-600);
  text-decoration: none;
  transition: all .15s;
}
.rr-pill:hover { border-color: var(--c-primary); color: var(--c-primary); }
.rr-pill.active { background: var(--c-primary); color: #fff; border-color: var(--c-primary); }
.rr-pill.orange { border-color: #fed7aa; color: #ea580c; background: #fff7ed; }
.rr-pill.orange.active { background: #ea580c; border-color: #ea580c; color: #fff; }

/* Each removal request card */
.rr-card {
  border-bottom: 1px solid var(--c-gray-100);
  padding: 18px 20px;
  display: flex;
  gap: 16px;
  align-items: flex-start;
  transition: background .12s;
}
.rr-card:last-child { border-bottom: none; }
.rr-card:hover { background: #fffbf7; }
.rr-card.rr-rejected { opacity: .65; }
.rr-card.rr-approved { opacity: .5; background: #f0fdf4; }

.rr-num {
  width: 28px; height: 28px;
  border-radius: 8px;
  background: #fff7ed;
  color: #ea580c;
  border: 1px solid #fed7aa;
  display: flex; align-items: center; justify-content: center;
  font-size: .72rem; font-weight: 800;
  flex-shrink: 0;
}
.rr-body { flex: 1; min-width: 0; }
.rr-owner-row {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 4px;
}
.rr-owner-name { font-size: .92rem; font-weight: 700; color: var(--c-gray-800); }
.rr-phone      { font-size: .78rem; color: var(--c-gray-400); }
.rr-status-badge {
  font-size: .68rem; font-weight: 800;
  padding: 2px 8px; border-radius: 99px;
  display: inline-block;
}
.rr-status-badge.pending  { background: #fff7ed; color: #ea580c; border: 1px solid #fed7aa; }
.rr-status-badge.approved { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
.rr-status-badge.rejected { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }

.rr-sub-badge {
  font-size: .68rem; font-weight: 700;
  padding: 1px 7px; border-radius: 5px; display: inline-block;
}
.rr-sub-badge.hot  { background: #f0fdf4; color: #15803d; }
.rr-sub-badge.warm { background: #fffbeb; color: #92400e; }
.rr-sub-badge.cold { background: #fef2f2; color: #b91c1c; }

.rr-location-line {
  font-size: .78rem;
  color: var(--c-gray-500);
  margin-bottom: 6px;
}
.rr-reason-box {
  background: #fff7ed;
  border: 1px solid #fed7aa;
  border-left: 3px solid #ea580c;
  border-radius: 6px;
  padding: 7px 11px;
  font-size: .8rem;
  color: #9a3412;
  margin-bottom: 8px;
}
.rr-reason-label {
  font-size: .65rem;
  font-weight: 800;
  letter-spacing: .08em;
  text-transform: uppercase;
  color: #c2410c;
  margin-bottom: 3px;
}
.rr-meta-row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  font-size: .72rem;
  color: var(--c-gray-400);
}
.rr-meta-row strong { color: var(--c-gray-600); }

.rr-admin-note {
  background: #f0fdf4;
  border: 1px solid #86efac;
  border-left: 3px solid #16a34a;
  border-radius: 6px;
  padding: 6px 10px;
  font-size: .78rem;
  color: #15803d;
  margin-top: 6px;
}
.rr-admin-note-label {
  font-size: .65rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: .08em;
  color: #15803d;
  margin-bottom: 2px;
}
.rr-rejection-note {
  background: #fef2f2;
  border: 1px solid #fca5a5;
  border-left: 3px solid #dc2626;
  border-radius: 6px;
  padding: 6px 10px;
  font-size: .78rem;
  color: #b91c1c;
  margin-top: 6px;
}

.rr-actions { display: flex; flex-direction: column; gap: 6px; flex-shrink: 0; align-items: flex-end; }
.btn-approve {
  background: #16a34a; color: #fff; border: none;
  padding: 7px 14px; border-radius: 7px;
  font-size: .78rem; font-weight: 700; cursor: pointer;
  display: inline-flex; align-items: center; gap: 5px;
  transition: background .15s; white-space: nowrap;
}
.btn-approve:hover { background: #15803d; }
.btn-reject  {
  background: #fff; color: #dc2626;
  border: 1.5px solid #fca5a5;
  padding: 6px 14px; border-radius: 7px;
  font-size: .78rem; font-weight: 700; cursor: pointer;
  display: inline-flex; align-items: center; gap: 5px;
  transition: all .15s; white-space: nowrap;
}
.btn-reject:hover { background: #fef2f2; border-color: #dc2626; }
.rr-time-stamp { font-size: .68rem; color: var(--c-gray-400); text-align: right; }
.rr-zone-chip {
  font-size: .68rem; font-weight: 700;
  background: #eff6ff; color: #1d4ed8;
  padding: 1px 7px; border-radius: 5px;
  display: inline-block;
}

/* ── Rejection reason textarea modal ── */
.reject-inline {
  margin-top: 8px;
  display: none;
}
.reject-inline.open { display: block; }
.reject-inline textarea {
  width: 100%;
  border: 1.5px solid #fca5a5;
  border-radius: 7px;
  padding: 7px 10px;
  font-size: .8rem;
  resize: vertical;
  min-height: 60px;
  font-family: inherit;
  box-sizing: border-box;
}
.reject-inline textarea:focus { outline: none; border-color: #dc2626; }
.reject-confirm-row { display: flex; gap: 6px; margin-top: 6px; }
.btn-confirm-reject {
  background: #dc2626; color: #fff; border: none;
  padding: 5px 12px; border-radius: 6px;
  font-size: .75rem; font-weight: 700; cursor: pointer;
}
.btn-cancel-reject {
  background: #fff; color: var(--c-gray-600);
  border: 1.5px solid var(--c-gray-200);
  padding: 5px 12px; border-radius: 6px;
  font-size: .75rem; font-weight: 600; cursor: pointer;
}

/* ── Today stat cards ── */
.today-strip {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 12px;
  margin-bottom: 20px;
}
@media(max-width:640px){ .today-strip{grid-template-columns:repeat(2,1fr)} }

.tday-card {
  border-radius: 12px;
  padding: 16px 16px 13px;
  position: relative;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  gap: 3px;
  box-shadow: 0 2px 10px rgba(0,0,0,.07);
}
.tday-card .tc-num   { font-size: 2.2rem; font-weight: 800; line-height: 1; letter-spacing: -.03em; }
.tday-card .tc-label { font-size: .7rem; font-weight: 700; letter-spacing: .09em; text-transform: uppercase; opacity: .65; }
.tday-card .tc-date  { font-size: .7rem; opacity: .45; margin-top: 2px; }
.tday-card .tc-bar   { margin-top: 10px; height: 3px; border-radius: 99px; background: rgba(255,255,255,.15); overflow: hidden; }
.tday-card .tc-fill  { height: 100%; border-radius: 99px; }
.tc-total { background: linear-gradient(140deg,#1e1b4b,#3730a3); color:#e0e7ff; border:1px solid #4338ca; }
.tc-total .tc-num  { color: #a5b4fc; }
.tc-total .tc-fill { background: #a5b4fc; }
.tc-hot { background: linear-gradient(140deg,#052e16,#166534); color:#dcfce7; border:1px solid #15803d; }
.tc-hot .tc-num   { color: #4ade80; }
.tc-hot .tc-fill  { background: #4ade80; }
.tc-warm { background: linear-gradient(140deg,#431407,#9a3412); color:#ffedd5; border:1px solid #c2410c; }
.tc-warm .tc-num  { color: #fb923c; }
.tc-warm .tc-fill { background: #fb923c; }
.tc-cold { background: linear-gradient(140deg,#450a0a,#991b1b); color:#fee2e2; border:1px solid #b91c1c; }
.tc-cold .tc-num  { color: #f87171; }
.tc-cold .tc-fill { background: #f87171; }

/* ── Previous dates quick-nav ── */
.prev-dates-wrap {
  background: var(--c-bg, #fff);
  border: 1.5px solid var(--c-gray-200);
  border-radius: 12px;
  padding: 14px 16px;
  margin-bottom: 20px;
}
.prev-dates-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 10px;
  cursor: pointer;
  user-select: none;
}
.prev-dates-head .pdh-title {
  font-size: .78rem;
  font-weight: 700;
  letter-spacing: .07em;
  text-transform: uppercase;
  color: var(--c-gray-500);
  display: flex;
  align-items: center;
  gap: 7px;
}
#prev-dates-body { display: flex; flex-wrap: wrap; gap: 8px; }
.pdate-btn {
  display: inline-flex; flex-direction: column; align-items: flex-start;
  padding: 8px 12px; border-radius: 10px;
  border: 1.5px solid var(--c-gray-200);
  background: var(--c-gray-50, #f9fafb);
  cursor: pointer; text-decoration: none;
  transition: all .15s; min-width: 110px;
}
.pdate-btn:hover { border-color: var(--c-primary); background: var(--c-primary-light, #eff6ff); }
.pdate-btn.active-date { border-color: var(--c-primary); background: var(--c-primary-light, #eff6ff); box-shadow: 0 0 0 2px var(--c-primary); }
.pdate-btn .pd-day  { font-size: .82rem; font-weight: 700; color: var(--c-gray-800); }
.pdate-btn .pd-date { font-size: .7rem; color: var(--c-gray-500); margin-bottom: 5px; }
.pdate-btn .pd-pills{ display: flex; gap: 4px; flex-wrap: wrap; }
.pd-pill { font-size: .65rem; font-weight: 700; padding: 1px 6px; border-radius: 99px; }
.pd-pill.hot  { background: #dcfce7; color: #15803d; }
.pd-pill.warm { background: #fef9c3; color: #92400e; }
.pd-pill.cold { background: #fee2e2; color: #991b1b; }
.pd-total     { font-size: .65rem; color: var(--c-gray-400); font-weight: 600; }

/* rest */
.view-toggle{display:flex;gap:6px}
.view-toggle button{display:flex;align-items:center;gap:5px;padding:5px 11px;border:1.5px solid var(--c-gray-200);border-radius:6px;background:none;cursor:pointer;font-size:.78rem;color:var(--c-gray-500);transition:all .15s}
.view-toggle button.active{border-color:var(--c-primary);background:var(--c-primary-light,#eff6ff);color:var(--c-primary)}
#bulk-bar{display:none;align-items:center;gap:10px;background:#fff7ed;border:1.5px solid #fed7aa;border-radius:8px;padding:8px 14px;margin-bottom:10px;animation:slideDown .18s ease}
@keyframes slideDown{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}
#bulk-bar.visible{display:flex}
#bulk-count{font-size:.85rem;font-weight:600;color:#9a3412}
.btn-danger{background:#ef4444;color:#fff;border:none;padding:6px 14px;border-radius:6px;font-size:.8rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px;transition:background .15s}
.btn-danger:hover{background:#dc2626}
.btn-danger:disabled{background:#fca5a5;cursor:not-allowed}
.btn-deselect{background:none;border:1.5px solid #fb923c;color:#c2410c;padding:5px 11px;border-radius:6px;font-size:.8rem;font-weight:600;cursor:pointer;transition:all .15s}
.btn-deselect:hover{background:#fff7ed}
.cb-col{width:36px;text-align:center}
input[type=checkbox].row-cb{width:16px;height:16px;cursor:pointer;accent-color:var(--c-primary)}
tr.selected-row{background:#eff6ff!important}
.sort-group{display:flex;align-items:center;gap:8px}
.sort-label{font-size:.82rem;color:var(--c-gray-600);font-weight:600}
.sort-select{padding:6px 10px;border:1.5px solid var(--c-gray-200);border-radius:6px;font-size:.8rem;color:var(--c-gray-700);background:#fff;cursor:pointer;transition:all .15s}
.sort-select:focus{outline:none;border-color:var(--c-primary)}
#subs-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px;padding:4px 0}
.sub-card{background:var(--c-bg,#fff);border:1.5px solid var(--c-gray-100);border-radius:12px;padding:16px;transition:box-shadow .15s,border-color .15s;position:relative;overflow:hidden;cursor:default}
.sub-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.07)}
.sub-card.card-selected{border-color:var(--c-primary);background:#eff6ff}
.sub-card .card-cb{position:absolute;top:12px;right:12px}
.sub-card .card-top{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px;padding-right:28px}
.sub-card .card-name{font-weight:700;font-size:.92rem;color:var(--c-gray-800)}
.sub-card .card-phone{font-size:.78rem;color:var(--c-gray-500);margin-top:1px}
.sub-card .card-datetime{display:inline-flex;align-items:center;gap:5px;background:var(--c-gray-50,#f9fafb);border:1px solid var(--c-gray-100);border-radius:6px;padding:4px 9px;margin-bottom:10px;font-size:.72rem;color:var(--c-gray-600);font-weight:500}
.sub-card .card-datetime .cdt-date{font-weight:700;color:var(--c-gray-800)}
.sub-card .card-datetime .cdt-src{font-size:.65rem;color:#d97706}
.sub-card .card-section{font-size:.68rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--c-gray-400);margin:10px 0 4px}
.sub-card .card-row{display:flex;justify-content:space-between;font-size:.82rem;margin-bottom:3px}
.sub-card .card-row .ck{color:var(--c-gray-500)}
.sub-card .card-row .cv{color:var(--c-gray-800);font-weight:500;text-align:right;max-width:60%}
.sub-card .card-areas{display:flex;flex-wrap:wrap;gap:5px;margin-top:6px}
.area-pill{display:inline-flex;flex-direction:column;background:var(--c-gray-50,#f9fafb);border:1px solid var(--c-gray-100);border-radius:6px;padding:4px 8px;font-size:.75rem}
.area-pill .ap-type{font-weight:600;color:var(--c-gray-700)}
.area-pill .ap-meta{color:var(--c-gray-500);font-size:.7rem}
.area-pill .ap-sqft{color:var(--c-primary);font-size:.72rem;font-weight:700}
.sub-card .card-footer{display:flex;justify-content:space-between;align-items:center;margin-top:12px;padding-top:10px;border-top:1px solid var(--c-gray-100)}
.maps-link{display:inline-flex;align-items:center;gap:4px;color:var(--c-primary);font-size:.78rem;text-decoration:none}
.maps-link:hover{text-decoration:underline}
.modal-sec{font-size:.68rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--c-gray-400);margin:14px 0 8px;padding-top:12px;border-top:1px solid var(--c-gray-100)}
.modal-sec:first-of-type{margin-top:0;padding-top:0;border-top:none}
.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px 16px;margin-bottom:4px}
.dg-item .dl{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--c-gray-400);margin-bottom:2px}
.dg-item .dv{font-size:.87rem;color:var(--c-gray-800);font-weight:500}
.dg-item.full{grid-column:1/-1}
.areas-table{width:100%;border-collapse:collapse;font-size:.82rem;margin-top:6px}
.areas-table th{text-align:left;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--c-gray-400);padding:4px 8px 6px;border-bottom:1px solid var(--c-gray-100)}
.areas-table td{padding:7px 8px;border-bottom:1px solid var(--c-gray-50)}
.areas-table tr:last-child td{border-bottom:none}
.areas-table .at-type{font-weight:600;color:var(--c-gray-800)}
.sqft-badge{display:inline-block;background:linear-gradient(135deg,#eff6ff,#dbeafe);color:#1d4ed8;font-weight:700;font-size:.75rem;padding:2px 8px;border-radius:4px;font-family:monospace}
.rep-panel{background:var(--c-gray-50,#f9fafb);border:1px solid var(--c-gray-100);border-radius:8px;padding:12px;margin-top:6px}
.deal-box{background:linear-gradient(135deg,#f0fdf4,#fffbeb);border:1px solid #bbf7d0;border-radius:8px;padding:12px;margin-top:6px}
.visit-box{background:linear-gradient(135deg,#f0f9ff,#e0f2fe);border:1.5px solid #7dd3fc;border-radius:8px;padding:10px 14px;margin-top:6px}
.vfp-row{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px}
.vfp{padding:5px 14px;border-radius:20px;font-size:.78rem;font-weight:600;cursor:pointer;border:1.5px solid var(--c-gray-200);background:#fff;color:var(--c-gray-600);text-decoration:none;transition:all .15s;display:inline-flex;align-items:center;gap:5px}
.vfp:hover{border-color:var(--c-primary);color:var(--c-primary)}
.vfp.active{background:var(--c-primary);color:#fff;border-color:var(--c-primary)}
.vfp.vfp-red{border-color:#fca5a5;color:#b91c1c;background:#fff0f0}
.vfp.vfp-red.active{background:#dc2626;border-color:#dc2626;color:#fff}
.vfp.vfp-yellow{border-color:#fde68a;color:#92400e;background:#fffbeb}
.vfp.vfp-yellow.active{background:#d97706;border-color:#d97706;color:#fff}
.nbadge{background:currentColor;border-radius:10px;padding:0 6px;font-size:.65rem;font-weight:700;display:inline-block}
.nbadge span{color:#fff}
.visit-alert{border-radius:10px;padding:11px 16px;margin-bottom:12px;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.visit-alert.red{background:#fee2e2;border:1.5px solid #fca5a5;color:#991b1b}
.visit-alert.yellow{background:#fef9c3;border:1.5px solid #fde68a;color:#78350f}
.btn-excel{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;background:#16a34a;color:#fff;border:none;border-radius:6px;font-size:.8rem;font-weight:600;cursor:pointer;text-decoration:none;transition:background .15s}
.btn-excel:hover{background:#15803d;color:#fff}
.dt-cell{white-space:nowrap}
.dt-cell .dt-date{font-weight:700;font-size:.82rem;color:var(--c-gray-800)}
.dt-cell .dt-time{font-size:.75rem;color:var(--c-gray-400);display:block;margin-top:1px}
.dt-cell .dt-src{font-size:.65rem;color:#d97706}

/* empty removals state */
.removals-empty {
  text-align: center;
  padding: 50px 20px;
  color: var(--c-gray-400);
}
.removals-empty .re-icon { font-size: 3rem; margin-bottom: 12px; }
.removals-empty .re-title { font-size: .95rem; font-weight: 700; color: var(--c-gray-600); margin-bottom: 6px; }
.removals-empty .re-sub   { font-size: .82rem; }

/* ── Executive Stats Section ── */
.exec-stats-wrap {
  background: var(--c-bg, #fff);
  border: 1.5px solid var(--c-gray-200);
  border-radius: 12px;
  padding: 16px;
  margin-bottom: 20px;
}
.exec-stats-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 14px;
  cursor: pointer;
  user-select: none;
}
.exec-stats-title {
  font-size: .78rem;
  font-weight: 700;
  letter-spacing: .07em;
  text-transform: uppercase;
  color: var(--c-gray-500);
  display: flex;
  align-items: center;
  gap: 7px;
}
.exec-stats-date-form {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.exec-stats-date-form input[type=date] {
  padding: 5px 8px;
  border: 1.5px solid var(--c-gray-200);
  border-radius: 6px;
  font-size: .78rem;
  color: var(--c-gray-700);
  background: #fff;
}
.exec-stats-date-form input[type=date]:focus {
  outline: none;
  border-color: var(--c-primary);
}
.exec-stats-date-form .btn-stat-filter {
  padding: 5px 12px;
  border: none;
  border-radius: 6px;
  font-size: .75rem;
  font-weight: 700;
  cursor: pointer;
  background: var(--c-primary);
  color: #fff;
  transition: background .15s;
}
.exec-stats-date-form .btn-stat-filter:hover { background: #1e40af; }
.exec-stats-date-form .btn-stat-clear {
  padding: 5px 10px;
  border: 1.5px solid var(--c-gray-200);
  border-radius: 6px;
  font-size: .75rem;
  font-weight: 600;
  cursor: pointer;
  background: #fff;
  color: var(--c-gray-500);
  transition: all .15s;
}
.exec-stats-date-form .btn-stat-clear:hover { border-color: var(--c-primary); color: var(--c-primary); }
.exec-stats-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 14px;
}
@media(max-width:640px){ .exec-stats-grid { grid-template-columns: 1fr; } }
.exec-stat-card {
  border-radius: 12px;
  padding: 18px;
  position: relative;
  overflow: hidden;
}
.exec-stat-card .esc-icon {
  font-size: 1.8rem;
  margin-bottom: 6px;
}
.exec-stat-card .esc-title {
  font-size: .72rem;
  font-weight: 800;
  letter-spacing: .08em;
  text-transform: uppercase;
  margin-bottom: 4px;
}
.exec-stat-card .esc-total {
  font-size: 2rem;
  font-weight: 800;
  line-height: 1;
  letter-spacing: -.03em;
  margin-bottom: 10px;
}
.exec-stat-card .esc-pills {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}
.exec-stat-card .esc-pill {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 3px 10px;
  border-radius: 99px;
  font-size: .7rem;
  font-weight: 700;
}
.esc-marketing {
  background: linear-gradient(140deg, #0c4a6e, #0284c7);
  color: #e0f2fe;
  border: 1px solid #0369a1;
  box-shadow: 0 4px 16px rgba(3,105,161,.18);
}
.esc-marketing .esc-total { color: #7dd3fc; }
.esc-marketing .esc-pill { background: rgba(255,255,255,.15); color: #e0f2fe; }
.esc-sales {
  background: linear-gradient(140deg, #312e81, #6366f1);
  color: #e0e7ff;
  border: 1px solid #4338ca;
  box-shadow: 0 4px 16px rgba(99,102,241,.18);
}
.esc-sales .esc-total { color: #a5b4fc; }
.esc-sales .esc-pill { background: rgba(255,255,255,.15); color: #e0e7ff; }
.exec-stat-card .esc-date-range {
  font-size: .68rem;
  opacity: .55;
  margin-top: 8px;
}

/* ── Individual Executive Cards & Daywise Breakdown ── */
.exec-sec-title {
  font-size: .82rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: .06em;
  color: var(--c-gray-700);
  margin: 18px 0 10px;
  display: flex;
  align-items: center;
  gap: 8px;
}
.exec-cards-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(330px, 1fr));
  gap: 14px;
  margin-bottom: 14px;
}
.indiv-exec-card {
  background: #fff;
  border: 1.5px solid var(--c-gray-200);
  border-radius: 12px;
  padding: 16px;
  box-shadow: 0 2px 8px rgba(0,0,0,.03);
  transition: all .15s;
}
.indiv-exec-card:hover {
  border-color: var(--c-primary);
  box-shadow: 0 4px 14px rgba(0,0,0,.07);
}
.iec-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 10px;
  padding-bottom: 8px;
  border-bottom: 1px solid var(--c-gray-100);
}
.iec-name {
  font-weight: 700;
  font-size: .92rem;
  color: var(--c-gray-800);
  display: flex;
  align-items: center;
  gap: 6px;
}
.iec-role-badge {
  font-size: .65rem;
  font-weight: 700;
  padding: 2px 7px;
  border-radius: 99px;
  text-transform: uppercase;
}
.iec-role-badge.marketing { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
.iec-role-badge.sales { background: #e0e7ff; color: #4338ca; border: 1px solid #c7d2fe; }

.iec-metrics {
  display: flex;
  gap: 5px;
  flex-wrap: wrap;
  margin-bottom: 10px;
}
.iec-pill {
  font-size: .7rem;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 6px;
}
.iec-pill.total { background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; }
.iec-pill.hot { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
.iec-pill.warm { background: #fef9c3; color: #854d0e; border: 1px solid #fde68a; }
.iec-pill.cold { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
.iec-pill.completed { background: #dcfce7; color: #166534; border: 1px solid #4ade80; }

.iec-daywise-box {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 8px 10px;
  max-height: 180px;
  overflow-y: auto;
}
.iec-dw-head {
  font-size: .68rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: .06em;
  color: var(--c-gray-400);
  margin-bottom: 6px;
  display: flex;
  justify-content: space-between;
}
.iec-dw-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: .74rem;
  padding: 4px 0;
  border-bottom: 1px dashed #e2e8f0;
}
.iec-dw-row:last-child { border-bottom: none; }
.iec-dw-date { font-weight: 600; color: var(--c-gray-700); }
.iec-dw-pills { display: flex; gap: 4px; align-items: center; }
.iec-dw-badge { font-size: .65rem; font-weight: 700; padding: 1px 5px; border-radius: 4px; }
.iec-dw-badge.hot { background: #dcfce7; color: #15803d; }
.iec-dw-badge.warm { background: #fef9c3; color: #854d0e; }
.iec-dw-badge.cold { background: #fee2e2; color: #b91c1c; }
.iec-dw-badge.completed { background: #dcfce7; color: #166534; }
.iec-dw-link { font-size: .68rem; color: var(--c-primary); text-decoration: none; font-weight: 700; margin-left: 6px; }
.iec-dw-link:hover { text-decoration: underline; }
</style>

<div class="app-wrap">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="main-content">
    <div class="page-header">
      <div>
        <div class="page-title">All Submissions</div>
        <div class="page-sub"><?= count($subs) ?> unique owner records</div>
      </div>
      <a href="<?= htmlspecialchars($exportUrl) ?>" class="btn-excel" title="Export current filtered view to Excel">
        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
        Export to Excel
      </a>
    </div>

    <?php if (!empty($msg)): ?>
      <?php alert(htmlspecialchars($msg), $msgType ?? 'success') ?>
    <?php endif; ?>

    <!-- ══ SECTION TABS ══ -->
    <div class="section-tabs">
      <a href="submissions.php" class="section-tab <?= $activeSection === 'submissions' ? 'active' : '' ?>">
        📋 Submissions
      </a>
      <a href="submissions.php?section=removals" class="section-tab tab-orange <?= $activeSection === 'removals' ? 'active' : '' ?>">
        🗑️ Removal Requests
        <?php if ($pendingRemovalsCount > 0): ?>
          <span class="tab-badge"><?= $pendingRemovalsCount ?></span>
        <?php endif; ?>
      </a>
    </div>

    <?php if ($activeSection === 'removals'): ?>
    <!-- ════════════════════════════════════════════════════════════════════
         REMOVAL REQUESTS SECTION
    ═════════════════════════════════════════════════════════════════════ -->
    <div class="removals-wrap">
      <div class="removals-header">
        <div>
          <div class="removals-title">🗑️ Record Removal Requests</div>
          <div class="removals-sub">Review and approve or reject deletion requests from marketing executives.</div>
        </div>
        <?php if ($pendingRemovalsCount > 0): ?>
        <span style="margin-left:auto;background:#ea580c;color:#fff;font-size:.75rem;font-weight:800;padding:4px 12px;border-radius:99px">
          <?= $pendingRemovalsCount ?> pending
        </span>
        <?php endif; ?>
      </div>

      <!-- Filter pills for removal status -->
      <?php
      $rrFilter    = $_GET['rr_status'] ?? 'pending';
      $filteredReqs = array_filter($allRemovalRequests, fn($r) => $rrFilter === 'all' || $r['status'] === $rrFilter);
      $countPending  = count(array_filter($allRemovalRequests, fn($r) => $r['status'] === 'pending'));
      $countApproved = count(array_filter($allRemovalRequests, fn($r) => $r['status'] === 'approved'));
      $countRejected = count(array_filter($allRemovalRequests, fn($r) => $r['status'] === 'rejected'));
      ?>
      <div class="rr-filter-pills">
        <a href="submissions.php?section=removals&rr_status=pending"
           class="rr-pill orange <?= $rrFilter === 'pending' ? 'active' : '' ?>">
          ⏳ Pending (<?= $countPending ?>)
        </a>
        <a href="submissions.php?section=removals&rr_status=approved"
           class="rr-pill <?= $rrFilter === 'approved' ? 'active' : '' ?>">
          ✅ Approved (<?= $countApproved ?>)
        </a>
        <a href="submissions.php?section=removals&rr_status=rejected"
           class="rr-pill <?= $rrFilter === 'rejected' ? 'active' : '' ?>">
          ❌ Rejected (<?= $countRejected ?>)
        </a>
        <a href="submissions.php?section=removals&rr_status=all"
           class="rr-pill <?= $rrFilter === 'all' ? 'active' : '' ?>">
          All (<?= count($allRemovalRequests) ?>)
        </a>
      </div>

      <?php if (empty($filteredReqs)): ?>
      <div class="removals-empty">
        <div class="re-icon"><?= $rrFilter === 'pending' ? '✅' : '📭' ?></div>
        <div class="re-title">
          <?= $rrFilter === 'pending' ? 'No pending removal requests!' : 'No requests found.' ?>
        </div>
        <div class="re-sub">
          <?= $rrFilter === 'pending'
            ? 'All removal requests have been processed.'
            : 'Try switching the filter above.' ?>
        </div>
      </div>
      <?php else: ?>
        <?php $rnum = 0; foreach ($filteredReqs as $rr): $rnum++; ?>
        <div class="rr-card rr-<?= $rr['status'] ?>" id="rr-card-<?= $rr['id'] ?>">
          <div class="rr-num"><?= $rnum ?></div>
          <div class="rr-body">
            <div class="rr-owner-row">
              <span class="rr-owner-name"><?= htmlspecialchars($rr['owner_name']) ?></span>
              <span class="rr-phone"><?= htmlspecialchars($rr['owner_number']) ?></span>
              <span class="rr-status-badge <?= $rr['status'] ?>">
                <?= ['pending'=>'⏳ Pending','approved'=>'✅ Approved','rejected'=>'❌ Rejected'][$rr['status']] ?? ucfirst($rr['status']) ?>
              </span>
              <span class="rr-sub-badge <?= $rr['sub_status'] ?>"><?= ucfirst($rr['sub_status']) ?></span>
              <span class="rr-zone-chip"><?= htmlspecialchars($rr['dir_code']) ?>·<?= htmlspecialchars($rr['zone']) ?></span>
            </div>
            <div class="rr-location-line">
              📍 <?= htmlspecialchars($rr['village_city_town']) ?>, <?= htmlspecialchars($rr['street']) ?>
            </div>
            <?php if ($rr['reason']): ?>
            <div class="rr-reason-box">
              <div class="rr-reason-label">Reason for removal</div>
              <?= htmlspecialchars($rr['reason']) ?>
            </div>
            <?php endif; ?>
            <div class="rr-meta-row">
              <span>Requested by <strong><?= htmlspecialchars($rr['requested_by_name']) ?></strong></span>
              <span>·</span>
              <span><?= date('d M Y, H:i', strtotime($rr['created_at'])) ?></span>
              <?php if ($rr['reviewed_by_name'] && $rr['status'] !== 'pending'): ?>
              <span>·</span>
              <span>Reviewed by <strong><?= htmlspecialchars($rr['reviewed_by_name']) ?></strong>
                on <?= date('d M Y, H:i', strtotime($rr['reviewed_at'])) ?>
              </span>
              <?php endif; ?>
            </div>

            <?php if ($rr['status'] === 'rejected' && !empty($rr['admin_note'])): ?>
            <div class="rr-rejection-note">
              <div style="font-size:.65rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;margin-bottom:2px">Admin note</div>
              <?= htmlspecialchars($rr['admin_note']) ?>
            </div>
            <?php endif; ?>

            <?php if ($rr['status'] === 'approved'): ?>
            <div class="rr-admin-note">
              <div class="rr-admin-note-label">✅ Record deleted</div>
              Removal was approved and the record has been permanently deleted.
            </div>
            <?php endif; ?>

            <!-- Inline rejection form (hidden initially) -->
            <?php if ($rr['status'] === 'pending'): ?>
            <div class="reject-inline" id="reject-form-<?= $rr['id'] ?>">
              <form method="POST">
                <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="reject_removal">
                <input type="hidden" name="request_id" value="<?= $rr['id'] ?>">
                <textarea name="admin_note" placeholder="Optional: explain why the request is rejected…"></textarea>
                <div class="reject-confirm-row">
                  <button type="submit" class="btn-confirm-reject">Confirm Reject</button>
                  <button type="button" class="btn-cancel-reject" onclick="closeRejectForm(<?= $rr['id'] ?>)">Cancel</button>
                </div>
              </form>
            </div>
            <?php endif; ?>
          </div>

          <?php if ($rr['status'] === 'pending'): ?>
          <div class="rr-actions">
            <!-- Approve form -->
            <form method="POST" onsubmit="return confirmApprove(<?= $rr['id'] ?>, '<?= addslashes($rr['owner_name']) ?>')">
              <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
              <input type="hidden" name="action" value="approve_removal">
              <input type="hidden" name="request_id" value="<?= $rr['id'] ?>">
              <button type="submit" class="btn-approve">
                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                Approve &amp; Delete
              </button>
            </form>
            <!-- Reject button (opens inline form) -->
            <button class="btn-reject" onclick="openRejectForm(<?= $rr['id'] ?>)">
              <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
              Reject
            </button>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <?php else: ?>
    <!-- ════════════════════════════════════════════════════════════════════
         SUBMISSIONS SECTION (original content)
    ═════════════════════════════════════════════════════════════════════ -->

    <!-- TODAY'S STATS -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
      <span style="font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--c-gray-400)">Today's Submissions</span>
      <a href="submissions.php?date_from=<?= $today ?>&date_to=<?= $today ?>" style="font-size:.75rem;color:var(--c-primary);text-decoration:none;font-weight:600">View today's records →</a>
    </div>
    <?php
    $todayHotPct  = $todayTotal > 0 ? round($todayHot  / $todayTotal * 100) : 0;
    $todayWarmPct = $todayTotal > 0 ? round($todayWarm / $todayTotal * 100) : 0;
    $todayColdPct = $todayTotal > 0 ? 100 - $todayHotPct - $todayWarmPct   : 0;
    ?>
    <div class="today-strip">
      <div class="tday-card tc-total" style="cursor:pointer" onclick="window.location='submissions.php?date_from=<?= $today ?>&date_to=<?= $today ?>'">
        <div class="tc-num"><?= $todayTotal ?></div>
        <div class="tc-label">Total Today</div>
        <div class="tc-date"><?= date('l, d M Y') ?></div>
        <div class="tc-bar"><div class="tc-fill" style="width:100%"></div></div>
      </div>
      <div class="tday-card tc-hot" style="cursor:pointer" onclick="window.location='submissions.php?date_from=<?= $today ?>&date_to=<?= $today ?>&status=hot'">
        <div class="tc-num"><?= $todayHot ?></div>
        <div class="tc-label">Hot Today</div>
        <div class="tc-date"><?= $todayHotPct ?>% of today</div>
        <div class="tc-bar"><div class="tc-fill" style="width:<?= $todayHotPct ?>%"></div></div>
      </div>
      <div class="tday-card tc-warm" style="cursor:pointer" onclick="window.location='submissions.php?date_from=<?= $today ?>&date_to=<?= $today ?>&status=warm'">
        <div class="tc-num"><?= $todayWarm ?></div>
        <div class="tc-label">Warm Today</div>
        <div class="tc-date"><?= $todayWarmPct ?>% of today</div>
        <div class="tc-bar"><div class="tc-fill" style="width:<?= $todayWarmPct ?>%"></div></div>
      </div>
      <div class="tday-card tc-cold" style="cursor:pointer" onclick="window.location='submissions.php?date_from=<?= $today ?>&date_to=<?= $today ?>&status=cold'">
        <div class="tc-num"><?= $todayCold ?></div>
        <div class="tc-label">Cold Today</div>
        <div class="tc-date"><?= $todayColdPct ?>% of today</div>
        <div class="tc-bar"><div class="tc-fill" style="width:<?= $todayColdPct ?>%"></div></div>
      </div>
    </div>

    <!-- EXECUTIVE STATS -->
    <div class="exec-stats-wrap">
      <div class="exec-stats-header" onclick="document.getElementById('exec-stats-body').style.display=document.getElementById('exec-stats-body').style.display==='none'?'block':'none';document.getElementById('esh-arrow').textContent=document.getElementById('exec-stats-body').style.display==='none'?'▼':'▲'">
        <span class="exec-stats-title">
          <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
          Executive Submission Stats &amp; Daywise Reports
        </span>
        <span id="esh-arrow">▲</span>
      </div>
      <div id="exec-stats-body">
        <form method="GET" class="exec-stats-date-form" style="margin-bottom:16px">
          <!-- Preserve existing main filters -->
          <?php if($filterStatus): ?><input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>"><?php endif; ?>
          <?php if($filterUser): ?><input type="hidden" name="user_id" value="<?= $filterUser ?>"><?php endif; ?>
          <?php if($filterZone): ?><input type="hidden" name="zone_id" value="<?= $filterZone ?>"><<?php endif; ?>
          <?php if($filterOwnerPhone): ?><input type="hidden" name="owner_phone" value="<?= htmlspecialchars($filterOwnerPhone) ?>"><<?php endif; ?>
          <?php if($filterRepName): ?><input type="hidden" name="rep_name" value="<?= htmlspecialchars($filterRepName) ?>"><<?php endif; ?>
          <?php if($filterRepPhone): ?><input type="hidden" name="rep_phone" value="<?= htmlspecialchars($filterRepPhone) ?>"><<?php endif; ?>
          <?php if($filterDateFrom): ?><input type="hidden" name="date_from" value="<?= htmlspecialchars($filterDateFrom) ?>"><?php endif; ?>
          <?php if($filterDateTo): ?><input type="hidden" name="date_to" value="<?= htmlspecialchars($filterDateTo) ?>"><?php endif; ?>
          <?php if($filterVisit): ?><input type="hidden" name="visit" value="<?= htmlspecialchars($filterVisit) ?>"><?php endif; ?>
          <?php if($sortBy && $sortBy !== 'date_desc'): ?><input type="hidden" name="sort_by" value="<?= htmlspecialchars($sortBy) ?>"><?php endif; ?>
          
          <label style="font-size:.75rem;font-weight:700;color:var(--c-gray-600)">Role Filter:</label>
          <select name="stat_role" class="form-control" style="width:auto;padding:4px 8px;font-size:.78rem">
            <option value="">All Roles</option>
            <option value="sub_admin" <?= $statRole==='sub_admin'?'selected':'' ?>>Marketing Executives</option>
            <option value="office_admin" <?= $statRole==='office_admin'?'selected':'' ?>>Sales Executives</option>
          </select>

          <label style="font-size:.75rem;font-weight:700;color:var(--c-gray-600)">Date From:</label>
          <input type="date" name="stat_from" value="<?= htmlspecialchars($statDateFrom) ?>">
          <label style="font-size:.75rem;font-weight:700;color:var(--c-gray-600)">Date To:</label>
          <input type="date" name="stat_to" value="<?= htmlspecialchars($statDateTo) ?>">
          
          <button type="submit" class="btn-stat-filter">Filter Stats</button>
          <?php if($statDateFrom || $statDateTo || $statRole): ?>
          <a href="<?= 'submissions.php?' . http_build_query(array_filter(['status'=>$filterStatus,'user_id'=>$filterUser?:'','zone_id'=>$filterZone?:'','owner_phone'=>$filterOwnerPhone,'rep_name'=>$filterRepName,'rep_phone'=>$filterRepPhone,'date_from'=>$filterDateFrom,'date_to'=>$filterDateTo,'visit'=>$filterVisit,'sort_by'=>$sortBy!=='date_desc'?$sortBy:''])) ?>" class="btn-stat-clear">✕ Clear Date Filter</a>
          <?php endif; ?>
        </form>

        <!-- TOP SUMMARY CARDS -->
        <div class="exec-stats-grid">
          <?php if(!$statRole || $statRole==='sub_admin'): ?>
          <div class="exec-stat-card esc-marketing">
            <div class="esc-icon">👤</div>
            <div class="esc-title">Marketing Executives Total</div>
            <div class="esc-total"><?= (int)$mktgStats['total'] ?></div>
            <div class="esc-pills">
              <span class="esc-pill">🟢 Hot: <?= (int)$mktgStats['hot'] ?></span>
              <span class="esc-pill">🟡 Warm: <?= (int)$mktgStats['warm'] ?></span>
              <span class="esc-pill">🔴 Cold: <?= (int)$mktgStats['cold'] ?></span>
              <span class="esc-pill">✅ Done: <?= (int)$mktgStats['completed'] ?></span>
            </div>
            <div class="esc-date-range"><?= ($statDateFrom||$statDateTo) ? '📅 '.($statDateFrom?:'—').' to '.($statDateTo?:'—') : 'All time stats' ?></div>
          </div>
          <?php endif; ?>

          <?php if(!$statRole || $statRole==='office_admin'): ?>
          <div class="exec-stat-card esc-sales">
            <div class="esc-icon">💼</div>
            <div class="esc-title">Sales Executives Total</div>
            <div class="esc-total"><?= (int)$salesStats['total'] ?></div>
            <div class="esc-pills">
              <span class="esc-pill">🟢 Hot: <?= (int)$salesStats['hot'] ?></span>
              <span class="esc-pill">🟡 Warm: <?= (int)$salesStats['warm'] ?></span>
              <span class="esc-pill">🔴 Cold: <?= (int)$salesStats['cold'] ?></span>
              <span class="esc-pill">✅ Done: <?= (int)$salesStats['completed'] ?></span>
            </div>
            <div class="esc-date-range"><?= ($statDateFrom||$statDateTo) ? '📅 '.($statDateFrom?:'—').' to '.($statDateTo?:'—') : 'All time stats' ?></div>
          </div>
          <?php endif; ?>
        </div>

        <!-- INDIVIDUAL EXECUTIVE CARDS & DAYWISE REPORTS -->
        <?php
        $mktgList  = array_filter($execList, fn($e) => $e['role'] === 'sub_admin');
        $salesList = array_filter($execList, fn($e) => $e['role'] === 'office_admin');
        ?>

        <!-- Marketing Executives Cards Section -->
        <?php if((!$statRole || $statRole==='sub_admin')): ?>
        <div class="exec-sec-title">👤 Marketing Executives Daywise Reports (Sub Admins)</div>
        <?php if($mktgList): ?>
        <div class="exec-cards-grid">
          <?php foreach($mktgList as $ex):
            $uId = (int)$ex['id'];
            $userDays = $daywiseByUser[$uId] ?? [];
          ?>
          <div class="indiv-exec-card">
            <div class="iec-head">
              <span class="iec-name">👤 <?= htmlspecialchars($ex['username']) ?></span>
              <span class="iec-role-badge marketing">Marketing</span>
            </div>
            <div class="iec-metrics">
              <span class="iec-pill total">Total: <?= (int)$ex['total'] ?></span>
              <span class="iec-pill hot">🟢 <?= (int)$ex['hot'] ?></span>
              <span class="iec-pill warm">🟡 <?= (int)$ex['warm'] ?></span>
              <span class="iec-pill cold">🔴 <?= (int)$ex['cold'] ?></span>
              <span class="iec-pill completed">✅ <?= (int)$ex['completed'] ?></span>
            </div>

            <!-- Daywise list -->
            <div class="iec-daywise-box">
              <div class="iec-dw-head">
                <span>Daywise History</span>
                <span><?= count($userDays) ?> active day<?= count($userDays)!==1?'s':'' ?></span>
              </div>
              <?php if($userDays): foreach($userDays as $ud): ?>
              <div class="iec-dw-row">
                <span class="iec-dw-date">📅 <?= date('d M Y', strtotime($ud['sub_date'])) ?></span>
                <div class="iec-dw-pills">
                  <span class="iec-dw-badge" style="background:#f1f5f9;color:#334155"><?= (int)$ud['total'] ?> total</span>
                  <?php if($ud['hot']): ?><span class="iec-dw-badge hot">🟢 <?= (int)$ud['hot'] ?></span><?php endif; ?>
                  <?php if($ud['warm']): ?><span class="iec-dw-badge warm">🟡 <?= (int)$ud['warm'] ?></span><?php endif; ?>
                  <?php if($ud['cold']): ?><span class="iec-dw-badge cold">🔴 <?= (int)$ud['cold'] ?></span><?php endif; ?>
                  <?php if($ud['completed']): ?><span class="iec-dw-badge completed">✅ <?= (int)$ud['completed'] ?></span><?php endif; ?>
                  <a class="iec-dw-link" href="submissions.php?user_id=<?= $uId ?>&date_from=<?= $ud['sub_date'] ?>&date_to=<?= $ud['sub_date'] ?>" title="Filter submissions for <?= htmlspecialchars($ex['username']) ?> on <?= $ud['sub_date'] ?>">View →</a>
                </div>
              </div>
              <?php endforeach; else: ?>
              <div style="font-size:.72rem;color:var(--c-gray-400);text-align:center;padding:6px">No daily records found.</div>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div style="font-size:.8rem;color:var(--c-gray-400);padding:10px;background:#f8fafc;border-radius:8px;margin-bottom:14px">No Marketing Executive records in selected date range.</div>
        <?php endif; ?>
        <?php endif; ?>

        <!-- Sales Executives Cards Section -->
        <?php if((!$statRole || $statRole==='office_admin')): ?>
        <div class="exec-sec-title">💼 Sales Executives Daywise Reports (Office Admins)</div>
        <?php if($salesList): ?>
        <div class="exec-cards-grid">
          <?php foreach($salesList as $ex):
            $uId = (int)$ex['id'];
            $userDays = $daywiseByUser[$uId] ?? [];
          ?>
          <div class="indiv-exec-card">
            <div class="iec-head">
              <span class="iec-name">💼 <?= htmlspecialchars($ex['username']) ?></span>
              <span class="iec-role-badge sales">Sales</span>
            </div>
            <div class="iec-metrics">
              <span class="iec-pill total">Total: <?= (int)$ex['total'] ?></span>
              <span class="iec-pill hot">🟢 <?= (int)$ex['hot'] ?></span>
              <span class="iec-pill warm">🟡 <?= (int)$ex['warm'] ?></span>
              <span class="iec-pill cold">🔴 <?= (int)$ex['cold'] ?></span>
              <span class="iec-pill completed">✅ <?= (int)$ex['completed'] ?></span>
            </div>

            <!-- Daywise list -->
            <div class="iec-daywise-box">
              <div class="iec-dw-head">
                <span>Daywise History</span>
                <span><?= count($userDays) ?> active day<?= count($userDays)!==1?'s':'' ?></span>
              </div>
              <?php if($userDays): foreach($userDays as $ud): ?>
              <div class="iec-dw-row">
                <span class="iec-dw-date">📅 <?= date('d M Y', strtotime($ud['sub_date'])) ?></span>
                <div class="iec-dw-pills">
                  <span class="iec-dw-badge" style="background:#f1f5f9;color:#334155"><?= (int)$ud['total'] ?> total</span>
                  <?php if($ud['hot']): ?><span class="iec-dw-badge hot">🟢 <?= (int)$ud['hot'] ?></span><?php endif; ?>
                  <?php if($ud['warm']): ?><span class="iec-dw-badge warm">🟡 <?= (int)$ud['warm'] ?></span><?php endif; ?>
                  <?php if($ud['cold']): ?><span class="iec-dw-badge cold">🔴 <?= (int)$ud['cold'] ?></span><?php endif; ?>
                  <?php if($ud['completed']): ?><span class="iec-dw-badge completed">✅ <?= (int)$ud['completed'] ?></span><?php endif; ?>
                  <a class="iec-dw-link" href="submissions.php?user_id=<?= $uId ?>&date_from=<?= $ud['sub_date'] ?>&date_to=<?= $ud['sub_date'] ?>" title="Filter submissions for <?= htmlspecialchars($ex['username']) ?> on <?= $ud['sub_date'] ?>">View →</a>
                </div>
              </div>
              <?php endforeach; else: ?>
              <div style="font-size:.72rem;color:var(--c-gray-400);text-align:center;padding:6px">No daily records found.</div>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div style="font-size:.8rem;color:var(--c-gray-400);padding:10px;background:#f8fafc;border-radius:8px;margin-bottom:14px">No Sales Executive records in selected date range.</div>
        <?php endif; ?>
        <?php endif; ?>

      </div>
    </div>

    <!-- PREVIOUS DATES QUICK NAV -->
    <?php if ($recentDates): ?>
    <div class="prev-dates-wrap">
      <div class="prev-dates-head" onclick="togglePrevDates()">
        <span class="pdh-title">
          <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          Previous Days
          <span style="font-size:.68rem;background:var(--c-gray-100);color:var(--c-gray-500);border-radius:99px;padding:1px 8px;font-weight:600"><?= count($recentDates) ?> days</span>
        </span>
        <span id="pdh-arrow">▲</span>
      </div>
      <div id="prev-dates-body">
        <?php foreach ($recentDates as $rd):
          $rdDate   = $rd['sub_date'];
          $isActive = ($filterDateFrom === $rdDate && $filterDateTo === $rdDate);
        ?>
        <a href="submissions.php?date_from=<?= $rdDate ?>&date_to=<?= $rdDate ?>"
           class="pdate-btn <?= $isActive ? 'active-date' : '' ?>">
          <span class="pd-day"><?= date('D', strtotime($rdDate)) ?></span>
          <span class="pd-date"><?= date('d M', strtotime($rdDate)) ?></span>
          <span class="pd-pills">
            <?php if($rd['hot']):  ?><span class="pd-pill hot">🟢 <?= $rd['hot'] ?></span><?php endif; ?>
            <?php if($rd['warm']): ?><span class="pd-pill warm">🟡 <?= $rd['warm'] ?></span><?php endif; ?>
            <?php if($rd['cold']): ?><span class="pd-pill cold">🔴 <?= $rd['cold'] ?></span><?php endif; ?>
          </span>
          <span class="pd-total"><?= $rd['total'] ?> total</span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="card" style="padding:14px">
      <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
        <div class="form-group" style="margin:0;flex:1;min-width:110px"><label class="form-label">Status</label><select class="form-control" name="status"><option value="">All</option><option value="hot" <?= $filterStatus==='hot'?'selected':''?>>Hot</option><option value="warm" <?= $filterStatus==='warm'?'selected':''?>>Warm</option><option value="cold" <?= $filterStatus==='cold'?'selected':''?>>Cold</option></select></div>
        <div class="form-group" style="margin:0;flex:1;min-width:110px"><label class="form-label">Zone</label><select class="form-control" name="zone_id"><option value="">All Zones</option><?php foreach($zones as $z): ?><option value="<?= $z['id'] ?>" <?= $filterZone===$z['id']?'selected':''?>><?= $z['code'] ?></option><?php endforeach; ?></select></div>
        <div class="form-group" style="margin:0;flex:1;min-width:130px"><label class="form-label">Owner Phone</label><input class="form-control" type="tel" name="owner_phone" value="<?= htmlspecialchars($filterOwnerPhone) ?>" placeholder="Search owner phone…" autocomplete="off" inputmode="numeric"></div>
        <div class="form-group" style="margin:0;flex:1;min-width:120px"><label class="form-label">Rep Name</label><input class="form-control" type="text" name="rep_name" value="<?= htmlspecialchars($filterRepName) ?>" placeholder="Rep name…"></div>
        <div class="form-group" style="margin:0;flex:1;min-width:130px"><label class="form-label">Rep Phone</label><input class="form-control" type="tel" name="rep_phone" value="<?= htmlspecialchars($filterRepPhone) ?>" placeholder="Rep phone…" autocomplete="off" inputmode="numeric"></div>
        <div class="form-group" style="margin:0;flex:1;min-width:115px"><label class="form-label">Date From</label><input class="form-control" type="date" name="date_from" value="<?= htmlspecialchars($filterDateFrom) ?>"></div>
        <div class="form-group" style="margin:0;flex:1;min-width:115px"><label class="form-label">Date To</label><input class="form-control" type="date" name="date_to" value="<?= htmlspecialchars($filterDateTo) ?>"></div>
        <div style="display:flex;gap:6px;align-items:flex-end"><button type="submit" class="btn btn-primary">Filter</button><a href="submissions.php" class="btn btn-ghost">Clear</a></div>
      </form>
    </div>

    <!-- VISIT DATE FILTER PILLS -->
    <?php $burl="submissions.php?status=".urlencode($filterStatus)."&zone_id=$filterZone&owner_phone=".urlencode($filterOwnerPhone)."&rep_name=".urlencode($filterRepName)."&rep_phone=".urlencode($filterRepPhone)."&date_from=".urlencode($filterDateFrom)."&date_to=".urlencode($filterDateTo)."&sort_by=".urlencode($sortBy); ?>
    <div class="vfp-row">
      <a href="<?= $burl ?>" class="vfp <?= !$filterVisit?'active':''?>">All Records</a>
      <a href="<?= $burl ?>&visit=upcoming10" class="vfp vfp-yellow <?= $filterVisit==='upcoming10'?'active':''?>">
        🟡 Upcoming Visits (10 days)<?php if($upcomingCount): ?> <span class="nbadge"><span><?= $upcomingCount ?></span></span><?php endif; ?>
      </a>
      <a href="<?= $burl ?>&visit=overdue" class="vfp vfp-red <?= $filterVisit==='overdue'?'active':''?>">
        🔴 Overdue Visits<?php if($overdueCount): ?> <span class="nbadge"><span><?= $overdueCount ?></span></span><?php endif; ?>
      </a>
      <a href="<?= $burl ?>&visit=has_visit" class="vfp <?= $filterVisit==='has_visit'?'active':''?>">📅 Has Visit Date</a>
    </div>

    <!-- ALERT BANNERS -->
    <?php if(!$filterVisit&&$overdueCount>0): ?>
    <div class="visit-alert red"><span style="font-size:1.2rem">🔴</span><div><div style="font-weight:700"><?= $overdueCount ?> overdue visit<?= $overdueCount!==1?'s':''?>!</div><div style="font-size:.8rem;margin-top:2px">Scheduled visits past their date. <a href="<?= $burl ?>&visit=overdue" style="color:inherit;font-weight:700;text-decoration:underline">View overdue →</a></div></div></div>
    <?php endif; ?>
    <?php if(!$filterVisit&&$upcomingCount>0): ?>
    <div class="visit-alert yellow"><span style="font-size:1.2rem">🟡</span><div><div style="font-weight:700"><?= $upcomingCount ?> visit<?= $upcomingCount!==1?'s':''?> in the next 10 days</div><div style="font-size:.8rem;margin-top:2px">Follow up before the visit date. <a href="<?= $burl ?>&visit=upcoming10" style="color:inherit;font-weight:700;text-decoration:underline">View upcoming →</a></div></div></div>
    <?php endif; ?>

    <!-- Bulk action bar -->
    <div id="bulk-bar">
      <span id="bulk-count">0 selected</span>
      <button class="btn-danger" id="bulk-delete-btn" onclick="confirmBulkDelete()">
        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>Delete Selected
      </button>
      <button class="btn-deselect" onclick="deselectAll()">✕ Deselect All</button>
    </div>

    <!-- Toolbar -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;flex-wrap:wrap;gap:10px">
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <div style="font-size:.82rem;color:var(--c-gray-500)"><?= count($subs) ?> records</div>
        <?php if($subs): ?><label style="display:flex;align-items:center;gap:5px;font-size:.8rem;color:var(--c-gray-500);cursor:pointer"><input type="checkbox" id="select-all-cb" style="width:15px;height:15px;accent-color:var(--c-primary)" onchange="toggleSelectAll(this.checked)">Select all</label><?php endif; ?>
        <div class="sort-group">
          <label class="sort-label">Sort:</label>
          <select class="sort-select" id="sort-select" onchange="applySort(this.value)">
            <option value="date_desc"  <?= $sortBy==='date_desc'?'selected':''?>>Date ↓</option>
            <option value="date_asc"   <?= $sortBy==='date_asc'?'selected':''?>>Date ↑</option>
            <option value="visit_asc"  <?= $sortBy==='visit_asc'?'selected':''?>>Visit Date ↑</option>
            <option value="visit_desc" <?= $sortBy==='visit_desc'?'selected':''?>>Visit Date ↓</option>
            <option value="time_desc"  <?= $sortBy==='time_desc'?'selected':''?>>Time ↓</option>
            <option value="time_asc"   <?= $sortBy==='time_asc'?'selected':''?>>Time ↑</option>
            <option value="name_asc"   <?= $sortBy==='name_asc'?'selected':''?>>Name A-Z</option>
            <option value="name_desc"  <?= $sortBy==='name_desc'?'selected':''?>>Name Z-A</option>
            <option value="status_hot"  <?= $sortBy==='status_hot'?'selected':''?>>Hot First</option>
            <option value="status_warm" <?= $sortBy==='status_warm'?'selected':''?>>Warm First</option>
            <option value="status_cold" <?= $sortBy==='status_cold'?'selected':''?>>Cold First</option>
          </select>
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:10px">
        <a href="<?= htmlspecialchars($exportUrl) ?>" class="btn-excel" style="font-size:.75rem;padding:5px 11px">
          <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          Excel
        </a>
        <div class="view-toggle">
          <button type="button" id="sv-btn-list" onclick="setSubView('list')"><svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/></svg>Table</button>
          <button type="button" id="sv-btn-grid" onclick="setSubView('grid')"><svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>Cards</button>
        </div>
      </div>
    </div>

    <!-- TABLE VIEW -->
    <div id="subs-list" class="card">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th class="cb-col"><input type="checkbox" id="th-cb" style="width:15px;height:15px;accent-color:var(--c-primary)" onchange="toggleSelectAll(this.checked)"></th>
              <th>#</th>
              <th>Date &amp; Time</th>
              <th>Owner</th><th>Phone</th><th>Representative</th><th>Rep Phone</th><th>Address</th><th>Areas &amp; Sq Ft</th><th>📅 Next Visit</th><th>GPS</th><th>Status</th><th>Zone</th><th>By</th><th>Detail</th>
            </tr>
          </thead>
          <tbody>
          <?php if($subs): foreach($subs as $i=>$s):
            $areas=$areasMap[$s['id']]??[]; $detail=$detailsMap[$s['id']]??null;
            $totalSqft=array_sum(array_filter(array_column($areas,'total_sqft')));
            $nvd = $s['next_visit_date'] ?: ($detail['next_visit_date'] ?? null);
            $st  = $s['device_submitted_at'] ?: $s['submitted_at'];
          ?>
          <tr data-id="<?= $s['id'] ?>">
            <td class="cb-col"><input type="checkbox" class="row-cb" value="<?= $s['id'] ?>" onchange="onRowCbChange(this)"></td>
            <td><?= $i+1 ?></td>
            <td class="dt-cell">
              <span class="dt-date"><?= $st ? date('d M Y', strtotime($st)) : '—' ?></span>
              <span class="dt-time"><?= $st ? date('h:i A', strtotime($st)) : '' ?>
                <?php if(!$s['device_submitted_at']): ?><span class="dt-src" title="Server time fallback"> *</span><?php endif; ?>
              </span>
            </td>
            <td>
              <strong><?= htmlspecialchars($s['owner_name']) ?></strong>
              <?php if($s['order_completed']): ?><br><span style="font-size:.65rem;background:#dcfce7;color:#15803d;padding:1px 6px;border-radius:10px;font-weight:700">✅ Completed</span><?php endif; ?>
            </td>
            <td style="font-size:.82rem"><?= htmlspecialchars($s['owner_number']) ?></td>
            <td><?= htmlspecialchars($s['representative_name']) ?></td>
            <td style="font-size:.82rem"><?= htmlspecialchars($s['representative_number']) ?></td>
            <td style="font-size:.82rem"><?= htmlspecialchars($s['village_city_town']) ?>, <?= htmlspecialchars($s['street']) ?></td>
            <td>
              <?php if($areas): foreach($areas as $a): $sqft=$a['total_sqft']??$a['area_sqft']??null; $lb=($a['area_length']&&$a['area_breadth'])?number_format($a['area_length'],1).'×'.number_format($a['area_breadth'],1):''; ?>
              <div style="font-size:.78rem;white-space:nowrap;margin-bottom:2px">
                <strong><?= htmlspecialchars($a['area_type']) ?></strong>
                <?php if($sqft): ?><span class="sqft-badge"><?= number_format($sqft,0) ?> sqft</span><?php endif; ?>
                <?php if($lb): ?><span style="color:var(--c-gray-400);font-size:.72rem"> <?= $lb ?> ft</span><?php endif; ?>
                <?php if($a['area_count']): ?><span style="color:var(--c-gray-400)"> · <?= $a['area_count'] ?> units</span><?php endif; ?>
              </div>
              <?php endforeach; ?>
              <?php if($totalSqft>0): ?><div style="margin-top:4px;font-size:.75rem;font-weight:700;color:#16a34a">Total: <?= number_format($totalSqft,0) ?> sqft</div><?php endif; ?>
              <?php else: ?><span style="color:var(--c-gray-300)">—</span><?php endif; ?>
            </td>
            <td><?= visitBadgeSub($nvd, $today, $in10Days, !empty($s['order_completed'])) ?></td>
            <td>
              <?php if($s['latitude']&&$s['longitude']): ?><a class="maps-link" href="https://maps.google.com/?q=<?= $s['latitude'] ?>,<?= $s['longitude'] ?>" target="_blank"><svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>Map</a><?php else: ?><span style="color:var(--c-gray-300);font-size:.8rem">—</span><?php endif; ?>
            </td>
            <td><span class="badge badge-<?= $s['status'] ?>"><span class="badge-dot"></span><?= ucfirst($s['status']) ?></span></td>
            <td><span class="badge badge-blue"><?= htmlspecialchars($s['dir_code']) ?>·<?= htmlspecialchars($s['zone']) ?></span></td>
            <td style="font-size:.8rem"><?= htmlspecialchars($s['username']) ?></td>
            <td><button class="btn btn-ghost btn-sm" onclick='viewSub(<?= json_encode($s) ?>,<?= json_encode($areas) ?>,<?= json_encode($detail) ?>)'>View</button></td>
          </tr>
          <?php endforeach; else: ?><tr><td colspan="15"><div class="empty-state"><p>No submissions match filters.</p></div></td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- CARD VIEW -->
    <div id="subs-grid" style="display:none">
      <?php if($subs): foreach($subs as $s):
        $areas=$areasMap[$s['id']]??[]; $detail=$detailsMap[$s['id']]??null;
        $totalSqft=array_sum(array_filter(array_column($areas,'total_sqft')));
        $statusDot=['hot'=>'🟢','warm'=>'🟡','cold'=>'🔴'][$s['status']]??'';
        $nvd=$s['next_visit_date']?:($detail['next_visit_date']??null);
        $st=$s['device_submitted_at']?:$s['submitted_at'];
      ?>
      <div class="sub-card" id="card-<?= $s['id'] ?>">
        <div class="card-cb"><input type="checkbox" class="row-cb" value="<?= $s['id'] ?>" style="width:16px;height:16px;accent-color:var(--c-primary);cursor:pointer" onchange="onRowCbChange(this)"></div>
        <div class="card-datetime">
          <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          <span class="cdt-date"><?= $st ? date('d M Y', strtotime($st)) : '—' ?></span>
          <span style="color:var(--c-gray-300)">·</span>
          <span><?= $st ? date('h:i A', strtotime($st)) : '' ?></span>
          <?php if(!$s['device_submitted_at']): ?><span class="cdt-src" title="Server time fallback">*</span><?php endif; ?>
        </div>
        <div class="card-top">
          <div>
            <div class="card-name"><?= htmlspecialchars($s['owner_name']) ?></div>
            <div class="card-phone"><?= htmlspecialchars($s['owner_number']) ?></div>
            <?php if($s['order_completed']): ?><span style="font-size:.65rem;background:#dcfce7;color:#15803d;padding:1px 6px;border-radius:10px;font-weight:700;margin-top:3px;display:inline-block">✅ Completed</span><?php endif; ?>
          </div>
          <span class="badge badge-<?= $s['status'] ?>"><?= $statusDot ?> <?= ucfirst($s['status']) ?></span>
        </div>
        <?php if($nvd): ?><div style="margin-bottom:8px"><?= visitBadgeSub($nvd,$today,$in10Days, !empty($s['order_completed'])) ?></div><?php endif; ?>
        <div class="card-section">Representative</div>
        <div class="card-row"><span class="ck">Name</span><span class="cv"><?= htmlspecialchars($s['representative_name']) ?></span></div>
        <div class="card-row"><span class="ck">Phone</span><span class="cv"><?= htmlspecialchars($s['representative_number']) ?></span></div>
        <div class="card-section">Address</div>
        <div class="card-row"><span class="ck">Location</span><span class="cv"><?= htmlspecialchars($s['village_city_town']) ?></span></div>
        <div class="card-row"><span class="ck">Street</span><span class="cv"><?= htmlspecialchars($s['street']) ?></span></div>
        <?php if($areas): ?>
        <div class="card-section">Areas<?= $totalSqft>0?' — <span style="color:#16a34a">'.number_format($totalSqft,0).' sqft total</span>':'' ?></div>
        <div class="card-areas">
          <?php foreach($areas as $a): $sqft=$a['total_sqft']??$a['area_sqft']??null; $lb=($a['area_length']&&$a['area_breadth'])?number_format($a['area_length'],1).'×'.number_format($a['area_breadth'],1).' ft':''; ?>
          <div class="area-pill">
            <span class="ap-type"><?= htmlspecialchars($a['area_type']) ?></span>
            <?php if($sqft): ?><span class="ap-sqft"><?= number_format($sqft,0) ?> sqft</span><?php endif; ?>
            <?php if($lb||$a['area_count']): ?><span class="ap-meta"><?= $lb ?><?= ($lb&&$a['area_count'])?' · ':''?><?= $a['area_count']?$a['area_count'].' units':'' ?></span><?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="card-footer">
          <div style="display:flex;align-items:center;gap:8px">
            <span style="font-size:.75rem;color:var(--c-gray-400)"><?= htmlspecialchars($s['dir_code']) ?>·<?= htmlspecialchars($s['zone']) ?></span>
          </div>
          <button class="btn btn-ghost btn-sm" onclick='viewSub(<?= json_encode($s) ?>,<?= json_encode($areas) ?>,<?= json_encode($detail) ?>)'>Detail</button>
        </div>
      </div>
      <?php endforeach; else: ?><div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--c-gray-400)">No submissions match filters.</div><?php endif; ?>
    </div>

    <?php endif; // end submissions section ?>

  </div>
</div>

<!-- Detail Modal -->
<div class="modal-overlay" id="viewModal">
  <div class="modal" style="max-width:660px;max-height:90vh;overflow-y:auto">
    <div class="modal-title" id="modal-owner-title">Submission Detail</div>
    <div id="viewContent"></div>
    <div class="modal-footer"><button class="btn btn-ghost" onclick="closeModal('viewModal')">Close</button></div>
  </div>
</div>

<!-- Confirm Bulk Delete Modal -->
<div class="modal-overlay" id="confirmModal">
  <div class="modal" style="max-width:420px">
    <div style="font-size:2.5rem;text-align:center;margin-bottom:12px">🗑️</div>
    <div style="text-align:center;font-size:.9rem;color:var(--c-gray-600);margin-bottom:20px;line-height:1.5">Are you sure you want to delete <strong id="confirm-count">0</strong> submission(s)?<br><span style="color:#ef4444;font-size:.82rem">This action cannot be undone.</span></div>
    <div style="display:flex;gap:10px;justify-content:center">
      <button class="btn btn-ghost" onclick="closeModal('confirmModal')">Cancel</button>
      <button class="btn-danger" id="confirm-delete-btn" onclick="executeBulkDelete()">
        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>Yes, Delete
      </button>
    </div>
  </div>
</div>

<nav class="mob-nav">
  <div class="mob-nav-items">
    <a href="dashboard.php"   class="mob-nav-item"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>Home</a>
    <a href="subadmins.php"   class="mob-nav-item"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>Admins</a>
    <a href="submissions.php" class="mob-nav-item active"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>Data</a>
    <a href="locations.php"   class="mob-nav-item"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>Zones</a>
    <a href="reports.php"     class="mob-nav-item"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>Reports</a>
  </div>
</nav>
<?php pageFooter(); ?>
<script>
const UPLOAD_URL='<?= UPLOAD_URL ?>';

/* ── Removal request helpers ─────────────────────────────────────── */
function openRejectForm(id) {
  document.querySelectorAll('.reject-inline').forEach(el => el.classList.remove('open'));
  const el = document.getElementById('reject-form-' + id);
  if (el) el.classList.add('open');
}
function closeRejectForm(id) {
  const el = document.getElementById('reject-form-' + id);
  if (el) el.classList.remove('open');
}
function confirmApprove(id, name) {
  return confirm('⚠️ Approve removal of "' + name + '"?\n\nThis will PERMANENTLY DELETE the record and cannot be undone.');
}

/* ── Submissions view ────────────────────────────────────────────── */
let subView=localStorage.getItem('sub_view')||'list';
function setSubView(v){
  subView=v;
  const l=document.getElementById('subs-list'), g=document.getElementById('subs-grid');
  if(l) l.style.display=v==='list'?'':'none';
  if(g) g.style.display=v==='grid'?'':'none';
  document.getElementById('sv-btn-list')?.classList.toggle('active',v==='list');
  document.getElementById('sv-btn-grid')?.classList.toggle('active',v==='grid');
  localStorage.setItem('sub_view',v);
}
if(document.getElementById('subs-list')) setSubView(subView);

function applySort(v){const u=new URL(window.location.href);u.searchParams.set('sort_by',v);window.location.href=u.toString();}

function togglePrevDates(){
  const body=document.getElementById('prev-dates-body');
  const arrow=document.getElementById('pdh-arrow');
  if (!body) return;
  const hidden=body.style.display==='none';
  body.style.display=hidden?'flex':'none';
  if(arrow) arrow.textContent=hidden?'▲':'▼';
}

/* ── Bulk select ─────────────────────────────────────────────────── */
const selectedIds=new Set();
function onRowCbChange(cb){
  const id=parseInt(cb.value);
  if(cb.checked){selectedIds.add(id);cb.closest('tr')?.classList.add('selected-row');document.getElementById('card-'+id)?.classList.add('card-selected');}
  else{selectedIds.delete(id);cb.closest('tr')?.classList.remove('selected-row');document.getElementById('card-'+id)?.classList.remove('card-selected');}
  syncSelectAllCheckbox();updateBulkBar();
}
function toggleSelectAll(checked){
  ['th-cb','select-all-cb'].forEach(id=>{const el=document.getElementById(id);if(el)el.checked=checked;});
  document.querySelectorAll('.row-cb').forEach(cb=>{
    cb.checked=checked;
    const id=parseInt(cb.value);
    if(checked){selectedIds.add(id);cb.closest('tr')?.classList.add('selected-row');document.getElementById('card-'+id)?.classList.add('card-selected');}
    else{selectedIds.delete(id);cb.closest('tr')?.classList.remove('selected-row');document.getElementById('card-'+id)?.classList.remove('card-selected');}
  });
  updateBulkBar();
}
function deselectAll(){toggleSelectAll(false);}
function syncSelectAllCheckbox(){
  const all=document.querySelectorAll('.row-cb'),checked=document.querySelectorAll('.row-cb:checked');
  const ac=all.length>0&&checked.length===all.length;
  ['th-cb','select-all-cb'].forEach(id=>{const el=document.getElementById(id);if(el)el.checked=ac;});
}
function updateBulkBar(){
  const bar=document.getElementById('bulk-bar');
  if(!bar) return;
  const cnt=selectedIds.size;
  document.getElementById('bulk-count').textContent=cnt+' selected';
  if(cnt>0)bar.classList.add('visible');else bar.classList.remove('visible');
}
function confirmBulkDelete(){if(!selectedIds.size)return;document.getElementById('confirm-count').textContent=selectedIds.size;openModal('confirmModal');}
function executeBulkDelete(){
  const btn=document.getElementById('confirm-delete-btn');btn.disabled=true;btn.innerHTML='Deleting…';
  const fd=new FormData();fd.append('action','bulk_delete');fd.append('ids',JSON.stringify([...selectedIds]));
  fetch('submissions.php',{method:'POST',body:fd}).then(r=>r.json()).then(res=>{
    if(res.success){
      closeModal('confirmModal');
      selectedIds.forEach(id=>{document.querySelector(`tr[data-id="${id}"]`)?.remove();document.getElementById('card-'+id)?.remove();});
      selectedIds.clear();updateBulkBar();syncSelectAllCheckbox();
      showToast('✅ '+res.deleted+' submission(s) deleted.','success');
    }else showToast('❌ Error: '+(res.message||'Delete failed'),'error');
  }).catch(()=>showToast('❌ Network error.','error'))
  .finally(()=>{btn.disabled=false;btn.innerHTML='<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>Yes, Delete';});
}
function showToast(msg,type){
  document.getElementById('toast-msg')?.remove();
  const t=document.createElement('div');t.id='toast-msg';t.textContent=msg;
  Object.assign(t.style,{position:'fixed',bottom:'24px',right:'24px',zIndex:'9999',padding:'10px 18px',borderRadius:'8px',fontWeight:'600',fontSize:'.85rem',background:type==='success'?'#16a34a':'#dc2626',color:'#fff',boxShadow:'0 4px 16px rgba(0,0,0,.18)'});
  document.body.appendChild(t);setTimeout(()=>t.remove(),3500);
}

/* ── Detail modal ────────────────────────────────────────────────── */
function fmtDateTime(dt){if(!dt)return '—';const d=new Date(dt.replace(' ','T'));if(isNaN(d))return dt;const months=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];const h=d.getHours(),m=String(d.getMinutes()).padStart(2,'0'),s=String(d.getSeconds()).padStart(2,'0'),ampm=h>=12?'PM':'AM',h12=String(h%12||12).padStart(2,'0');return `${String(d.getDate()).padStart(2,'0')} ${months[d.getMonth()]} ${d.getFullYear()}, ${h12}:${m}:${s} ${ampm}`;}
function fmtVisitDate(d, isCompleted){if(!d)return null;const today=new Date();today.setHours(0,0,0,0);const vd=new Date(d+'T00:00:00');const diff=Math.round((vd-today)/86400000);const label=date=>{const months=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];const days=['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];return days[date.getDay()]+', '+date.getDate()+' '+months[date.getMonth()]+' '+date.getFullYear();};
if(diff<0 && !isCompleted)return `<span style="display:inline-block;padding:4px 12px;border-radius:8px;background:#fee2e2;color:#b91c1c;font-weight:700;font-size:.85rem">🔴 Overdue — ${label(vd)} (${Math.abs(diff)} day${Math.abs(diff)!==1?'s':''} ago)</span>`;
if(diff===0 && !isCompleted)return `<span style="display:inline-block;padding:4px 12px;border-radius:8px;background:#fef9c3;color:#854d0e;font-weight:700;font-size:.85rem">🟡 TODAY — ${label(vd)}</span>`;
if(diff<=10 && !isCompleted)return `<span style="display:inline-block;padding:4px 12px;border-radius:8px;background:#fef9c3;color:#854d0e;font-weight:700;font-size:.85rem">🟡 ${label(vd)} (in ${diff} day${diff!==1?'s':''})</span>`;
return `<span style="display:inline-block;padding:4px 12px;border-radius:8px;background:#eff6ff;color:#1d4ed8;font-weight:700;font-size:.85rem">📅 ${label(vd)}${diff>10?' (in '+diff+' day'+(diff!==1?'s':'')+')':''}</span>`;}

function viewSub(s,areas,deal){
  document.getElementById('modal-owner-title').textContent=s.owner_name+' — '+s.owner_number;
  const st=s.device_submitted_at||s.submitted_at;
  const dtSrc=s.device_submitted_at?'<span style="color:#16a34a;font-size:.75rem;font-weight:600">✔ Device time</span>':'<span style="color:#d97706;font-size:.75rem;font-weight:600">⚠ Server time</span>';
  const dtBanner=st?`<div style="display:inline-flex;align-items:center;gap:8px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;padding:8px 14px;margin-bottom:16px;flex-wrap:wrap"><svg width="13" height="13" fill="none" stroke="#64748b" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg><span style="font-weight:700;font-size:.88rem;color:#1e293b">${fmtDateTime(st)}</span>${dtSrc}</div>`:''
  let gpsHtml=s.latitude&&s.longitude?`<a class="maps-link" href="https://maps.google.com/?q=${s.latitude},${s.longitude}" target="_blank" rel="noopener" style="font-size:.85rem">📍 ${parseFloat(s.latitude).toFixed(5)}, ${parseFloat(s.longitude).toFixed(5)} — Open in Google Maps</a>`:'<em style="color:var(--c-gray-400);font-size:.82rem">No GPS recorded</em>';
  let totalSqft=0,areasHtml='<em style="color:var(--c-gray-400);font-size:.82rem">No area details recorded</em>';
  if(areas&&areas.length){const rows=areas.map(a=>{const sqft=parseFloat(a.total_sqft||a.area_sqft||0);if(sqft)totalSqft+=sqft;const sqftStr=sqft?`<span class="sqft-badge">${sqft.toLocaleString()} sqft</span>`:'—';const lbStr=(a.area_length&&a.area_breadth)?`<span style="color:var(--c-gray-500);font-size:.78rem">${parseFloat(a.area_length).toFixed(1)} ft × ${parseFloat(a.area_breadth).toFixed(1)} ft</span>`:'—';const cntStr=a.area_count?a.area_count+' unit'+(a.area_count>1?'s':''):'—';return `<tr><td class="at-type">${a.area_type}</td><td>${lbStr}</td><td>${sqftStr}</td><td>${cntStr}</td></tr>`;}).join('');const totalRow=totalSqft>0?`<tr style="background:#f0fdf4"><td colspan="2" style="font-weight:700;color:#15803d">Total</td><td><span class="sqft-badge" style="background:#dcfce7;color:#15803d">${totalSqft.toLocaleString(undefined,{maximumFractionDigits:0})} sqft</span></td><td></td></tr>`:'';areasHtml=`<table class="areas-table"><thead><tr><th>Type</th><th>Length × Breadth</th><th>Total Sq Ft</th><th>Units</th></tr></thead><tbody>${rows}${totalRow}</tbody></table>`;}
  let dealHtml='';
  if(deal&&(deal.deal_result||deal.detail_description)){const re={'Order Completed':'✅','Not Completed':'❌','Purchased from Other Vendor':'🏪','Other Reason':'📝'}[deal.deal_result]||'';dealHtml=`<div class="deal-box" style="margin-top:0">${deal.deal_result?`<div style="font-weight:700;font-size:.9rem;margin-bottom:6px">${re} ${deal.deal_result}</div>`:''} ${deal.vendor_name?`<div style="font-size:.82rem;margin-bottom:2px"><strong>Vendor:</strong> ${deal.vendor_name}</div>`:''} ${deal.vendor_details?`<div style="font-size:.82rem;margin-bottom:2px"><strong>Vendor Info:</strong> ${deal.vendor_details}</div>`:''} ${deal.other_reason?`<div style="font-size:.82rem;margin-bottom:2px"><strong>Close Note:</strong> ${deal.other_reason}</div>`:''} ${deal.detail_description?`<div style="font-size:.82rem;color:var(--c-gray-600);margin-top:4px">${deal.detail_description}</div>`:''}</div>`;}
  let photos='';
  if(s.landmark_picture)photos+=`<img src="${UPLOAD_URL}${s.landmark_picture}" style="max-width:100%;border-radius:8px;margin-bottom:8px;display:block">`;
  if(s.additional_picture)photos+=`<img src="${UPLOAD_URL}${s.additional_picture}" style="max-width:100%;border-radius:8px;display:block">`;
  const sc={hot:'badge-hot',warm:'badge-warm',cold:'badge-cold'};
  const nvd=s.next_visit_date||(deal?deal.next_visit_date:null)||null;
  const isOrderCompleted = parseInt(s.order_completed) === 1;
  const visitHtml=nvd?`<div class="modal-sec">📅 Next Visit Date</div><div class="visit-box">${fmtVisitDate(nvd, isOrderCompleted)||'—'}</div>`:'';
  const lockedHtml=s.order_completed?'<span style="display:inline-block;background:#dcfce7;color:#15803d;padding:3px 12px;border-radius:12px;font-size:.78rem;font-weight:700;margin-top:4px">✅ Order Completed — Record Locked</span>':'';
  document.getElementById('viewContent').innerHTML=`
    ${dtBanner}
    <div class="modal-sec">Owner Details</div>
    <div class="detail-grid">
      <div class="dg-item"><div class="dl">Full Name</div><div class="dv">${s.owner_name}${lockedHtml}</div></div>
      <div class="dg-item"><div class="dl">Phone Number</div><div class="dv">${s.owner_number}</div></div>
    </div>
    <div class="modal-sec">Representative Details</div>
    <div class="rep-panel"><div class="detail-grid" style="margin-bottom:0"><div class="dg-item"><div class="dl">Name</div><div class="dv">${s.representative_name}</div></div><div class="dg-item"><div class="dl">Phone</div><div class="dv">${s.representative_number}</div></div></div></div>
    <div class="modal-sec">Business Address</div>
    <div class="detail-grid">
      <div class="dg-item"><div class="dl">Village / City / Town</div><div class="dv">${s.village_city_town}</div></div>
      <div class="dg-item"><div class="dl">Street</div><div class="dv">${s.street}</div></div>
      <div class="dg-item full"><div class="dl">GPS Location</div><div class="dv">${gpsHtml}</div></div>
    </div>
    <div class="modal-sec">Area Details</div>${areasHtml}
    ${visitHtml}
    ${dealHtml?`<div class="modal-sec">Deal Info</div>${dealHtml}`:''}
    <div class="modal-sec">Submission Info</div>
    <div class="detail-grid">
      <div class="dg-item"><div class="dl">Status</div><div class="dv"><span class="badge ${sc[s.status]}">${s.status.toUpperCase()}</span></div></div>
      <div class="dg-item"><div class="dl">Zone</div><div class="dv">${s.dir_code} › ${s.zone}</div></div>
      <div class="dg-item"><div class="dl">Submitted by</div><div class="dv">${s.username}</div></div>
      <div class="dg-item full"><div class="dl">Submitted at</div><div class="dv">${fmtDateTime(st)}</div></div>
      <div class="dg-item"><div class="dl">Time Source</div><div class="dv">${s.device_submitted_at?'<span style="color:#16a34a;font-size:.78rem">✔ Device time</span>':'<span style="color:#d97706;font-size:.78rem">⚠ Server time</span>'}</div></div>
    </div>
    ${photos?`<div class="modal-sec">Photos</div>${photos}`:''}`;
  openModal('viewModal');
}

/* ── Auto-scroll to removals section if redirected from dashboard ── */
<?php if ($activeSection === 'removals'): ?>
window.scrollTo({top:0,behavior:'smooth'});
<?php endif; ?>
</script>