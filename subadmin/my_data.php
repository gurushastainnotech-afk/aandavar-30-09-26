<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

requireLogin('sub_admin');

$db   = getDB();
$user = currentUser();
$msg  = ''; $msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'edit_status') {
        $id = (int)$_POST['submission_id'];
        $check = $db->prepare("SELECT s.* FROM submissions s JOIN sub_admin_locations sal ON sal.sub_direction_id = s.sub_direction_id WHERE s.id=? AND sal.sub_admin_id=?");
        $check->execute([$id, $user['id']]);
        $orig = $check->fetch();
        if (!$orig) {
            $msg = 'Record not found.'; $msgType = 'error';
        } elseif ($orig['order_completed']) {
            $msg = 'This record is locked — order already completed.'; $msgType = 'error';
        } else {
            $newStatus    = trim($_POST['status'] ?? '');
            $newVisitDate = trim($_POST['next_visit_date'] ?? '') ?: null;
            $dealResult   = trim($_POST['deal_result'] ?? '');
            $orderDone    = ($dealResult === 'Order Completed') ? 1 : 0;

            if ($newStatus !== '' && $newStatus !== $orig['status']) {
                $db->prepare("INSERT INTO submission_edits (submission_id,edited_by,field_name,old_value,new_value) VALUES (?,?,?,?,?)")
                   ->execute([$id, $user['id'], 'status', $orig['status'], $newStatus]);
                $db->prepare("UPDATE submissions SET status=?, last_edited_at=NOW() WHERE id=?")->execute([$newStatus, $id]);
            }
            $db->prepare("UPDATE submissions SET next_visit_date=?, order_completed=?, last_edited_at=NOW() WHERE id=?")
               ->execute([$newVisitDate, $orderDone, $id]);

            $det = [trim($_POST['detail_description']??''),$dealResult,trim($_POST['vendor_name']??''),trim($_POST['vendor_details']??''),trim($_POST['other_reason']??''),$newVisitDate,$newStatus?:$orig['status'],$id];
            $existing = $db->prepare("SELECT id FROM submission_status_details WHERE submission_id=?");
            $existing->execute([$id]);
            if ($existing->fetchColumn()) {
                $db->prepare("UPDATE submission_status_details SET detail_description=?,deal_result=?,vendor_name=?,vendor_details=?,other_reason=?,next_visit_date=?,status=?,updated_at=NOW() WHERE submission_id=?")->execute($det);
            } else {
                $db->prepare("INSERT INTO submission_status_details (detail_description,deal_result,vendor_name,vendor_details,other_reason,next_visit_date,status,submission_id) VALUES (?,?,?,?,?,?,?,?)")->execute($det);
            }
            logActivity($user['id'], 'edit_submission', "Edited status/deal for submission id=$id");
            $msg = $orderDone ? 'Order marked as completed — record is now locked.' : 'Record updated successfully.';
        }
    }

    if ($action === 'request_remove') {
        $id = (int)$_POST['submission_id'];
        $reason = trim($_POST['remove_reason'] ?? '');
        $check = $db->prepare("SELECT s.id FROM submissions s JOIN sub_admin_locations sal ON sal.sub_direction_id = s.sub_direction_id WHERE s.id=? AND sal.sub_admin_id=?");
        $check->execute([$id, $user['id']]);
        if (!$check->fetch()) { $msg = 'Record not found.'; $msgType = 'error'; }
        else {
            $dup = $db->prepare("SELECT id FROM submission_remove_requests WHERE submission_id=? AND status='pending'");
            $dup->execute([$id]);
            if ($dup->fetchColumn()) { $msg = 'A removal request is already pending for this record.'; $msgType = 'error'; }
            else {
                $db->prepare("INSERT INTO submission_remove_requests (submission_id, requested_by, reason) VALUES (?,?,?)")->execute([$id, $user['id'], $reason]);
                logActivity($user['id'], 'remove_request', "Requested removal of submission id=$id");
                $msg = 'Removal request sent to admin.';
            }
        }
    }
}

$myZoneRows = $db->prepare("SELECT sd.id, sd.code, d.code as dir_code FROM sub_admin_locations sal JOIN sub_directions sd ON sal.sub_direction_id = sd.id JOIN directions d ON sd.direction_id = d.id WHERE sal.sub_admin_id=? ORDER BY d.code, sd.code");
$myZoneRows->execute([$user['id']]);
$myZones   = $myZoneRows->fetchAll();
$myZoneIds = array_column($myZones, 'id');

$filterZone    = isset($_GET['zone'])   ? (int)$_GET['zone']  : 0;
$filterRep     = trim($_GET['rep']     ?? '');
$filterOwner   = trim($_GET['owner']   ?? '');
$filterPhone   = trim($_GET['phone']   ?? '');
$filterVillage = trim($_GET['village'] ?? '');
$filterVisit   = trim($_GET['visit']   ?? '');
$viewMode      = in_array($_GET['view'] ?? '', ['list','map']) ? $_GET['view'] : 'grid';

$subs = [];
$today    = date('Y-m-d');
$in10Days = date('Y-m-d', strtotime('+10 days'));

if (!empty($myZoneIds)) {
    $inPlaces = implode(',', array_fill(0, count($myZoneIds), '?'));
    $where  = ["s.sub_direction_id IN ($inPlaces)"];
    $params = $myZoneIds;
    if ($filterZone && in_array($filterZone, $myZoneIds)) { $where[] = "s.sub_direction_id = ?"; $params[] = $filterZone; }
    if ($filterRep)     { $where[] = "s.representative_name LIKE ?"; $params[] = '%'.$filterRep.'%'; }
    if ($filterOwner)   { $where[] = "s.owner_name LIKE ?"; $params[] = '%'.$filterOwner.'%'; }
    if ($filterPhone)   { $where[] = "(s.owner_number LIKE ? OR s.representative_number LIKE ?)"; $params[] = '%'.$filterPhone.'%'; $params[] = '%'.$filterPhone.'%'; }
    if ($filterVillage) { $where[] = "s.village_city_town = ?"; $params[] = $filterVillage; }
    if ($filterVisit === 'upcoming10') { $where[] = "s.next_visit_date IS NOT NULL AND s.next_visit_date >= ? AND s.next_visit_date <= ?"; $params[] = $today; $params[] = $in10Days; }
    elseif ($filterVisit === 'overdue') { $where[] = "s.next_visit_date IS NOT NULL AND s.next_visit_date < ?"; $params[] = $today; }
    elseif ($filterVisit === 'has_visit') { $where[] = "s.next_visit_date IS NOT NULL"; }

    $sql = "SELECT s.*, sd.code as zone, d.code as dir_code, u.username as submitted_by_name
            FROM submissions s
            JOIN sub_directions sd ON s.sub_direction_id = sd.id
            JOIN directions d ON sd.direction_id = d.id
            LEFT JOIN users u ON u.id = s.submitted_by
            WHERE " . implode(' AND ', $where) . "
            ORDER BY COALESCE(s.device_submitted_at, s.submitted_at) DESC, s.id DESC";
    $q = $db->prepare($sql); $q->execute($params); $subs = $q->fetchAll();
}

$groupedSubs = ['hot'=>[],'warm'=>[],'cold'=>[]];
foreach ($subs as $s) { $st=$s['status']; if(isset($groupedSubs[$st])) $groupedSubs[$st][]=$s; else $groupedSubs['cold'][]=$s; }
$latestSubmissionTime = $subs ? ($subs[0]['device_submitted_at'] ?: $subs[0]['submitted_at']) : null;
$hasActiveFilters = (bool)($filterZone || $filterRep || $filterOwner || $filterPhone || $filterVillage || $filterVisit);

$subIds = array_column($subs,'id');
$areasMap=[]; $detailsMap=[]; $removeMap=[]; $areaImagesMap=[];
if ($subIds) {
    $in = implode(',', array_fill(0, count($subIds), '?'));
    $aq = $db->prepare("SELECT * FROM submission_areas WHERE submission_id IN ($in) ORDER BY sort_order"); $aq->execute($subIds);
    foreach ($aq->fetchAll() as $a) $areasMap[$a['submission_id']][]=$a;
    $dq = $db->prepare("SELECT * FROM submission_status_details WHERE submission_id IN ($in)"); $dq->execute($subIds);
    foreach ($dq->fetchAll() as $d) $detailsMap[$d['submission_id']]=$d;
    $rq = $db->prepare("SELECT * FROM submission_remove_requests WHERE submission_id IN ($in) AND status='pending'"); $rq->execute($subIds);
    foreach ($rq->fetchAll() as $r) $removeMap[$r['submission_id']]=$r;
    // Load area images
    $allAreaIds = [];
    foreach ($areasMap as $areas) foreach ($areas as $a) $allAreaIds[] = $a['id'];
    if ($allAreaIds) {
        $in2 = implode(',', array_fill(0,count($allAreaIds),'?'));
        $iq = $db->prepare("SELECT * FROM submission_area_images WHERE area_id IN ($in2) ORDER BY sort_order");
        $iq->execute($allAreaIds);
        foreach ($iq->fetchAll() as $img) $areaImagesMap[$img['area_id']][] = $img;
    }
}

$upcomingCount=0; $overdueCount=0;
foreach ($subs as $s) {
    if (!$s['next_visit_date']) continue;
    if ($s['next_visit_date'] < $today) $overdueCount++;
    elseif ($s['next_visit_date'] <= $in10Days) $upcomingCount++;
}

function statusLabel($s){ return ['hot'=>'🟢 Hot','warm'=>'🟡 Warm','cold'=>'🔴 Cold'][$s]??ucfirst($s); }
function dealIcon($d){ return (['Order Completed'=>'✅','Not Completed'=>'❌','Purchased from Other Vendor'=>'🏪','Other Reason'=>'📝'][$d]??'').' '.htmlspecialchars($d); }
function visitBadge($d,$today,$in10){ if(!$d) return ''; $label=date('d M Y (D)',strtotime($d)); if($d<$today) return "<span class='vbadge vb-over'>🔴 Overdue: $label</span>"; elseif($d<=$in10) return "<span class='vbadge vb-soon'>🟡 Visit: $label</span>"; return "<span class='vbadge vb-ok'>📅 Visit: $label</span>"; }

$mapMarkers=[];
foreach ($subs as $s) { if(!empty($s['latitude'])&&!empty($s['longitude'])) $mapMarkers[]=['id'=>$s['id'],'lat'=>(float)$s['latitude'],'lng'=>(float)$s['longitude'],'owner'=>$s['owner_name'],'phone'=>$s['owner_number'],'village'=>$s['village_city_town'],'street'=>$s['street'],'status'=>$s['status'],'zone'=>$s['dir_code'].' › '.$s['zone'],'time'=>date('d M Y H:i',strtotime($s['device_submitted_at']?:$s['submitted_at'])),'submitter'=>$s['submitted_by_name']??'']; }

pageHead('My Data');
topbar($user['username'], $user['role']);

function renderCard($s,$areasMap,$detailsMap,$removeMap,$user,$today,$in10,$areaImagesMap=[]){
    $areas=$areasMap[$s['id']]??[]; $detail=$detailsMap[$s['id']]??null;
    $hasPR=isset($removeMap[$s['id']]); $isOwn=((int)$s['submitted_by']===(int)$user['id']);
    $locked=(bool)$s['order_completed'];
    $vd = $s['next_visit_date'] ?: ($detail['next_visit_date'] ?? null);
    $submittedAt = $s['device_submitted_at'] ?: $s['submitted_at'];
    ob_start();
?>
<div class="sub-card" data-status="<?= $s['status'] ?>" data-time="<?= htmlspecialchars($submittedAt) ?>"<?= $locked?' data-locked="1"':'' ?>>
  <?php if($hasPR): ?><div class="pb">⏳ Pending Removal</div><?php elseif($locked): ?><div class="pb" style="background:#16a34a">✅ Completed</div><?php endif; ?>
  <div class="sub-card-head">
    <div class="card-main-row">
      <div class="owner-block">
        <div class="owner-name"><?= htmlspecialchars($s['owner_name']) ?></div>
        <div class="owner-phone"><?= htmlspecialchars($s['owner_number']) ?></div>
        <?php if(!$isOwn&&!empty($s['submitted_by_name'])): ?><span class="submitter-badge">👤 <?= htmlspecialchars($s['submitted_by_name']) ?></span><?php endif; ?>
      </div>
      <div class="card-badges">
        <span class="badge badge-<?= $s['status'] ?>"><span class="badge-dot"></span><?= statusLabel($s['status']) ?></span>
        <span class="badge badge-blue zone-badge"><?= htmlspecialchars($s['dir_code']) ?> › <?= htmlspecialchars($s['zone']) ?></span>
      </div>
    </div>
    <div class="card-meta-row">
      <span>Submitted <?= date('d M Y, h:i A',strtotime($submittedAt)) ?></span>
      <?php if(!empty($s['last_edited_at'])): ?><span>Edited <?= date('d M, h:i A',strtotime($s['last_edited_at'])) ?></span><?php endif; ?>
    </div>
  </div>
  <div class="sub-card-body">
    <div class="info-row"><span class="info-label">Representative</span><span class="info-val"><?= htmlspecialchars($s['representative_name']) ?></span></div>
    <div class="info-row"><span class="info-label">Rep Phone</span><span class="info-val"><?= htmlspecialchars($s['representative_number']) ?></span></div>
    <div class="info-row"><span class="info-label">Village / Town</span><span class="info-val"><?= htmlspecialchars($s['village_city_town']) ?></span></div>
    <div class="info-row"><span class="info-label">Street</span><span class="info-val"><?= htmlspecialchars($s['street']) ?></span></div>
    <?php if($vd): ?>
    <div class="info-row" style="margin-top:6px"><span class="info-label">Next Visit</span><span class="info-val"><?= visitBadge($vd,$today,$in10) ?></span></div>
    <?php endif; ?>
    <?php if(!empty($s['purchase_date'])): ?>
    <div class="info-row"><span class="info-label">Purchase Date</span><span class="info-val" style="color:#15803d;font-weight:700">📅 <?= date('d M Y',strtotime($s['purchase_date'])) ?></span></div>
    <?php endif; ?>
    <?php if(!empty($s['purchase_notes'])): ?>
    <div class="info-row"><span class="info-label">Purchase Notes</span><span class="info-val"><?= htmlspecialchars($s['purchase_notes']) ?></span></div>
    <?php endif; ?>
    <?php if(!empty($s['latitude'])): ?>
    <div class="info-row"><span class="info-label">GPS</span><span class="info-val" style="font-size:.78rem"><a href="https://maps.google.com/?q=<?= $s['latitude'] ?>,<?= $s['longitude'] ?>" target="_blank" style="color:var(--c-primary);text-decoration:none">📍 <?= htmlspecialchars($s['latitude']) ?>, <?= htmlspecialchars($s['longitude']) ?></a></span></div>
    <?php endif; ?>
    <?php if(!empty($s['tile_image'])): ?>
    <div style="margin-top:8px;padding-top:8px;border-top:1px dashed var(--c-gray-200)">
      <div style="font-size:.72rem;color:var(--c-gray-400);text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px">🪟 Tile Image</div>
      <a href="<?= UPLOAD_URL . $s['tile_image'] ?>" target="_blank"><img src="<?= UPLOAD_URL . $s['tile_image'] ?>" style="max-height:80px;max-width:120px;object-fit:cover;border-radius:6px;border:2px solid #16a34a" loading="lazy"></a>
    </div>
    <?php endif; ?>
    <?php if($areas): ?>
    <div style="margin-top:9px;padding-top:9px;border-top:1px dashed var(--c-gray-200)">
      <div style="font-size:.72rem;color:var(--c-gray-400);text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px">Areas</div>
      <?php foreach($areas as $a):
        $aImgs = $areaImagesMap[$a['id']] ?? [];
      ?>
      <div style="border:1px solid var(--c-gray-200);border-radius:6px;padding:8px;margin-bottom:6px">
        <div style="display:flex;align-items:center;gap:8px;font-size:.8rem;margin-bottom:4px">
          <span style="background:var(--c-gray-100);color:var(--c-gray-700);padding:2px 8px;border-radius:4px;font-weight:600"><?= htmlspecialchars($a['area_type']) ?></span>
          <?php if($a['total_sqft']??$a['area_sqft']): ?><span style="color:var(--c-gray-600)"><?= number_format($a['total_sqft']??$a['area_sqft']) ?> sqft</span><?php endif; ?>
          <?php if($a['area_length']??null): ?><span style="color:var(--c-gray-500);font-size:.74rem"><?= $a['area_length'] ?>×<?= $a['area_breadth'] ?> ft</span><?php endif; ?>
          <?php if($a['area_count']): ?><span style="color:var(--c-gray-600)"><?= $a['area_count'] ?> units</span><?php endif; ?>
          <?php if($aImgs): ?><span style="font-size:.72rem;color:var(--c-primary)">📷 <?= count($aImgs) ?> photo(s)</span><?php endif; ?>
        </div>
        <?php if($aImgs): ?>
        <div style="display:flex;gap:5px;flex-wrap:wrap;margin-top:4px">
          <?php foreach($aImgs as $img): ?>
          <a href="<?= UPLOAD_URL . $img['image_filename'] ?>" target="_blank">
            <img src="<?= UPLOAD_URL . $img['image_filename'] ?>" style="height:55px;width:55px;object-fit:cover;border-radius:4px;border:1px solid var(--c-gray-200)" loading="lazy">
          </a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if($detail&&($detail['deal_result']||$detail['detail_description'])): ?>
    <div style="margin-top:9px;padding-top:9px;border-top:1px dashed var(--c-gray-200)">
      <div style="font-size:.72rem;color:var(--c-gray-400);text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px">Deal Info</div>
      <?php if($detail['deal_result']): ?><div class="info-row"><span class="info-label">Result</span><span class="info-val"><?= dealIcon($detail['deal_result']) ?></span></div><?php endif; ?>
      <?php if($detail['vendor_name']): ?><div class="info-row"><span class="info-label">Vendor</span><span class="info-val"><?= htmlspecialchars($detail['vendor_name']) ?></span></div><?php endif; ?>
      <?php if($detail['vendor_details']): ?><div class="info-row"><span class="info-label">Vendor Info</span><span class="info-val"><?= htmlspecialchars($detail['vendor_details']) ?></span></div><?php endif; ?>
      <?php if($detail['other_reason']): ?><div class="info-row"><span class="info-label">Close Note</span><span class="info-val"><?= htmlspecialchars($detail['other_reason']) ?></span></div><?php endif; ?>
      <?php if($detail['detail_description']): ?><div class="info-row"><span class="info-label">Notes</span><span class="info-val"><?= htmlspecialchars($detail['detail_description']) ?></span></div><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
  <div class="sub-card-foot">
    <?php if(!empty($s['latitude'])): ?><a href="https://maps.google.com/?q=<?= $s['latitude'] ?>,<?= $s['longitude'] ?>" target="_blank" class="btn btn-ghost btn-sm">🗺 Map</a><?php endif; ?>
    <?php if($locked): ?>
      <span style="font-size:.78rem;color:#15803d;font-weight:600">✅ Order completed — locked</span>
      <a href="print_pdf.php?id=<?= $s['id'] ?>" target="_blank" class="btn btn-sm" style="background:#16a34a;color:#fff;border:none;cursor:pointer">🖨 Print PDF</a>
    <?php elseif(!$hasPR): ?>
      <button class="btn btn-ghost btn-sm" onclick='editModal(<?= json_encode(array_merge((array)$s,['detail'=>$detail])) ?>)'>✏️ Edit Status</button>
      <button class="btn btn-sm" style="background:#fee2e2;color:#dc2626;border:none;cursor:pointer" onclick='removeModal(<?= $s["id"] ?>,<?= json_encode($s["owner_name"]) ?>)'>🗑 Request Remove</button>
    <?php else: ?>
      <span style="font-size:.78rem;color:#d97706;font-weight:500">⏳ Removal request pending admin approval</span>
    <?php endif; ?>
  </div>
</div>
<?php return ob_get_clean(); }
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<style>
.sub-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px}
.sub-grid.view-list{grid-template-columns:1fr}
.sub-card{background:#fff;border:1px solid var(--c-gray-200);border-radius:10px;overflow:hidden;transition:box-shadow .15s;position:relative}
.sub-card:hover{box-shadow:0 4px 18px rgba(0,0,0,.1)}
.sub-card[data-locked="1"]{border-color:#86efac}
.sub-card-head{padding:14px 16px 10px}
.sub-card-body{padding:12px 16px}
.sub-card-foot{padding:10px 16px;background:var(--c-gray-50);border-top:1px solid var(--c-gray-100);display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.info-row{display:flex;gap:6px;font-size:.8rem;margin-bottom:5px;align-items:flex-start}
.info-label{color:var(--c-gray-500);min-width:95px;flex-shrink:0;padding-top:1px}
.info-val{color:var(--c-gray-800);font-weight:500;word-break:break-word}
.sub-card[data-status=hot]  .sub-card-head{border-left:4px solid #16a34a;background:linear-gradient(135deg,#f0fdf4,#fff)}
.sub-card[data-status=warm] .sub-card-head{border-left:4px solid #d97706;background:linear-gradient(135deg,#fffbeb,#fff)}
.sub-card[data-status=cold] .sub-card-head{border-left:4px solid #dc2626;background:linear-gradient(135deg,#fef2f2,#fff)}
.sub-card[data-locked="1"]  .sub-card-head{border-left:4px solid #16a34a!important}
.pb{position:absolute;top:8px;right:8px;background:#f59e0b;color:#fff;font-size:.68rem;padding:2px 8px;border-radius:20px;font-weight:700;z-index:2}
.submitter-badge{display:inline-block;background:#e0f2fe;color:#0369a1;font-size:.68rem;padding:2px 7px;border-radius:10px;font-weight:600;margin-top:3px}
.view-toggle button{padding:5px 12px;border:1px solid var(--c-gray-200);background:#fff;cursor:pointer;font-size:.82rem;transition:background .12s}
.view-toggle button:first-child{border-radius:6px 0 0 6px;border-right:none}
.view-toggle button:nth-child(2){border-right:none}
.view-toggle button:last-child{border-radius:0 6px 6px 0}
.view-toggle button.active{background:var(--c-primary);color:#fff;border-color:var(--c-primary)}
.stat-card{flex:1;min-width:90px;padding:10px 14px;border-radius:8px;border:1px solid var(--c-gray-200);background:#fff}
#mapView{display:none;border-radius:12px;overflow:hidden;border:1px solid var(--c-gray-200)}
#mapContainer{height:520px;width:100%}
#mapNoGps{background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:14px 16px;font-size:.85rem;color:#92400e;margin-bottom:12px;display:none}
.map-legend{position:absolute;bottom:24px;right:10px;background:#fff;border:1px solid var(--c-gray-200);border-radius:8px;padding:8px 12px;font-size:.76rem;z-index:999;box-shadow:0 2px 8px rgba(0,0,0,.12)}
.map-legend-item{display:flex;align-items:center;gap:6px;margin-bottom:4px}
.map-legend-dot{width:12px;height:12px;border-radius:50%;flex-shrink:0}
.status-group{margin-bottom:24px}
.status-group-header{display:flex;align-items:center;gap:10px;margin-bottom:12px;padding:10px 14px;border-radius:8px;font-weight:700;font-size:.9rem}
.status-group-header.hot{background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d}
.status-group-header.warm{background:#fffbeb;border:1px solid #fde68a;color:#b45309}
.status-group-header.cold{background:#fef2f2;border:1px solid #fecaca;color:#b91c1c}
.status-group-header .count-pill{background:#fff;border-radius:20px;padding:2px 10px;font-size:.78rem;border:1px solid currentColor;opacity:.8}
.village-group{margin-bottom:18px}
.village-group-header{display:flex;align-items:center;gap:8px;margin-bottom:8px;font-size:.8rem;font-weight:600;color:var(--c-gray-600);text-transform:uppercase;letter-spacing:.05em}
.village-group-header::after{content:'';flex:1;height:1px;background:var(--c-gray-200)}
.village-tag{background:var(--c-gray-100);color:var(--c-gray-700);padding:2px 10px;border-radius:20px;font-size:.75rem;font-weight:700;text-transform:none;letter-spacing:0}
/* Visit badges */
.vbadge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:700}
.vb-over{background:#fee2e2;color:#b91c1c;border:1px solid #fca5a5;animation:pulse-r 1.5s infinite}
.vb-soon{background:#fef9c3;color:#854d0e;border:1px solid #fde68a}
.vb-ok  {background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe}
@keyframes pulse-r{0%,100%{opacity:1}50%{opacity:.6}}
/* Alert bars */
.alert-bar{border-radius:10px;padding:12px 16px;margin-bottom:12px;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.alert-bar.red   {background:#fee2e2;border:1.5px solid #fca5a5;color:#991b1b}
.alert-bar.yellow{background:#fef9c3;border:1.5px solid #fde68a;color:#78350f}
/* Visit filter pills */
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
/* Modal visit shortcuts */
.vs-btn{padding:3px 10px;border:1px solid #7dd3fc;border-radius:20px;background:#fff;cursor:pointer;font-size:.74rem;color:#0369a1;font-weight:600;font-family:inherit}
.vs-btn.red{border-color:#fca5a5;color:#dc2626}
.data-hero{background:#fff;border:1px solid var(--c-gray-200);border-radius:12px;padding:18px 20px;margin-bottom:14px;box-shadow:var(--shadow);display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap}
.data-hero-title{font-size:1.45rem;font-weight:800;color:var(--c-gray-900);letter-spacing:0;line-height:1.2}
.data-hero-sub{font-size:.86rem;color:var(--c-gray-500);margin-top:5px}
.data-hero-meta{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
.quick-pill{display:inline-flex;align-items:center;gap:6px;padding:7px 10px;border:1px solid var(--c-gray-200);border-radius:8px;background:var(--c-gray-50);font-size:.78rem;font-weight:700;color:var(--c-gray-700)}
.quick-pill strong{color:var(--c-gray-900)}
.view-toggle{display:inline-flex;border:1px solid var(--c-gray-200);border-radius:8px;overflow:hidden;background:#fff}
.view-toggle button{min-height:34px;padding:7px 12px;border:0;border-right:1px solid var(--c-gray-200);background:#fff;cursor:pointer;font-size:.82rem;font-weight:700;color:var(--c-gray-600);transition:background .12s,color .12s}
.view-toggle button:first-child,.view-toggle button:last-child{border-radius:0}
.view-toggle button:last-child{border-right:0}
.view-toggle button.active{background:var(--c-primary);color:#fff;border-color:var(--c-primary)}
.filter-card{padding:14px 16px;margin-bottom:12px}
.filter-grid{display:grid;grid-template-columns:1.1fr 1.25fr 1fr 1fr auto;gap:10px;align-items:end}
.filter-card .form-group{margin:0}
.feed-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:18px 0 12px;flex-wrap:wrap}
.feed-title{font-size:1rem;font-weight:800;color:var(--c-gray-900)}
.feed-sub{font-size:.8rem;color:var(--c-gray-500);margin-top:2px}
.sort-pill{display:inline-flex;align-items:center;gap:6px;background:#ecfeff;border:1px solid #bae6fd;color:#0369a1;border-radius:8px;padding:7px 11px;font-size:.78rem;font-weight:800}
.sub-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:14px}
.sub-grid.view-list{grid-template-columns:1fr}
.sub-card{background:#fff;border:1px solid var(--c-gray-200);border-radius:12px;overflow:hidden;transition:box-shadow .15s,transform .15s,border-color .15s;position:relative;box-shadow:var(--shadow)}
.sub-card:hover{box-shadow:var(--shadow-md);transform:translateY(-1px);border-color:#cbd5e1}
.sub-card[data-locked="1"]{border-color:#86efac}
.sub-card-head{padding:14px 16px 11px;border-bottom:1px solid var(--c-gray-100)}
.card-main-row{display:flex;justify-content:space-between;align-items:flex-start;gap:10px}
.owner-block{min-width:0}
.owner-name{font-weight:800;font-size:1rem;color:var(--c-gray-900);line-height:1.25;word-break:break-word}
.owner-phone{font-size:.79rem;color:var(--c-gray-500);margin-top:2px}
.card-badges{display:flex;flex-direction:column;align-items:flex-end;gap:5px;flex-shrink:0}
.zone-badge{font-size:.7rem}
.card-meta-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;font-size:.73rem;color:var(--c-gray-500)}
.card-meta-row span{background:var(--c-gray-50);border:1px solid var(--c-gray-200);border-radius:7px;padding:3px 7px}
.sub-card-body{padding:13px 16px}
.sub-card-foot{padding:11px 16px;background:#fbfdff;border-top:1px solid var(--c-gray-100);display:flex;gap:7px;flex-wrap:wrap;align-items:center}
.sub-card[data-status=hot]  .sub-card-head{border-left:4px solid #16a34a;background:linear-gradient(90deg,#f0fdf4,#fff)}
.sub-card[data-status=warm] .sub-card-head{border-left:4px solid #d97706;background:linear-gradient(90deg,#fffbeb,#fff)}
.sub-card[data-status=cold] .sub-card-head{border-left:4px solid #dc2626;background:linear-gradient(90deg,#fef2f2,#fff)}
@media (max-width:900px){.filter-grid{grid-template-columns:1fr 1fr}.filter-actions{grid-column:1/-1}.data-hero-meta{justify-content:flex-start}}
@media (max-width:620px){.data-hero{padding:15px}.data-hero-title{font-size:1.2rem}.filter-grid{grid-template-columns:1fr}.sub-grid{grid-template-columns:1fr}.card-main-row{flex-direction:column}.card-badges{align-items:flex-start;flex-direction:row;flex-wrap:wrap}}
</style>

<div class="app-wrap">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="main-content">

    <div class="data-hero">
      <div>
        <div class="data-hero-title">My Submissions</div>
        <div class="data-hero-sub">
          <?= count($subs) ?> record<?= count($subs)!==1?'s':'' ?> in your assigned zones · newest submission first
          <?php if($latestSubmissionTime): ?> · latest <?= date('d M Y, h:i A', strtotime($latestSubmissionTime)) ?><?php endif; ?>
        </div>
      </div>
      <div class="data-hero-meta">
        <span class="quick-pill">Sort <strong>Recent First</strong></span>
        <?php if($hasActiveFilters): ?><span class="quick-pill">Filtered <strong>On</strong></span><?php endif; ?>
        <div class="view-toggle">
          <button onclick="setView('grid')" id="vbtn-grid" class="<?= $viewMode==='grid'?'active':'' ?>">⊞ Grid</button>
          <button onclick="setView('list')"  id="vbtn-list"  class="<?= $viewMode==='list'?'active':'' ?>">☰ List</button>
          <button onclick="setView('map')"   id="vbtn-map"   class="<?= $viewMode==='map'?'active':'' ?>">🗺 Map</button>
        </div>
        <a href="submit.php" class="btn btn-primary btn-sm">+ New</a>
      </div>
    </div>

    <?php alert(htmlspecialchars($msg), $msgType) ?>

    <!-- FILTER BAR -->
    <div class="card filter-card">
      <form method="GET" class="filter-grid">
        <input type="hidden" name="view" id="fv" value="<?= $viewMode ?>">
        <div class="form-group">
          <label class="form-label" style="margin-bottom:4px">Zone</label>
          <select class="form-control" name="zone">
            <option value="">All My Zones</option>
            <?php foreach($myZones as $z): ?><option value="<?= $z['id'] ?>" <?= $filterZone==$z['id']?'selected':'' ?>><?= htmlspecialchars($z['dir_code'].' › '.$z['code']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label" style="margin-bottom:4px">Village / City / Town</label>
          <select class="form-control" name="village">
            <option value="">All Villages</option>
            <?php
            $vbs=['hot'=>[],'warm'=>[],'cold'=>[],'other'=>[]];
            foreach($subs as $vs){ $vn=$vs['village_city_town']; if(!$vn) continue; $st=$vs['status']; if(isset($vbs[$st])&&!in_array($vn,$vbs[$st])) $vbs[$st][]=$vn; elseif(!isset($vbs[$st])&&!in_array($vn,$vbs['other'])) $vbs['other'][]=$vn; }
            $sld=['hot'=>'🟢 Hot','warm'=>'🟡 Warm','cold'=>'🔴 Cold','other'=>'Other'];
            foreach($vbs as $st=>$vs){ if(empty($vs)) continue; sort($vs); echo '<optgroup label="'.htmlspecialchars($sld[$st]).'">'; foreach($vs as $v){ $sel=($filterVillage===$v)?'selected':''; echo '<option value="'.htmlspecialchars($v).'" '.$sel.'>'.htmlspecialchars($v).'</option>'; } echo '</optgroup>'; }
            ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label" style="margin-bottom:4px">Owner</label>
          <input class="form-control" type="text" name="owner" value="<?= htmlspecialchars($filterOwner) ?>" placeholder="Search owner...">
        </div>
        <div class="form-group">
          <label class="form-label" style="margin-bottom:4px">Representative</label>
          <input class="form-control" type="text" name="rep" value="<?= htmlspecialchars($filterRep) ?>" placeholder="Search rep...">
        </div>
        <div class="form-group">
          <label class="form-label" style="margin-bottom:4px">Phone Number</label>
          <input class="form-control" type="tel" name="phone" value="<?= htmlspecialchars($filterPhone) ?>" placeholder="Search owner / rep phone...">
        </div>
        <div class="filter-actions" style="display:flex;gap:6px">
          <button type="submit" class="btn btn-primary btn-sm">Filter</button>
          <a href="my_data.php?view=<?= $viewMode ?>" class="btn btn-ghost btn-sm">Clear</a>
        </div>
      </form>
    </div>

    <!-- VISIT DATE FILTER PILLS -->
    <?php
    $baseUrl = "my_data.php?view={$viewMode}".($filterZone?"&zone=$filterZone":'').($filterVillage?'&village='.urlencode($filterVillage):'').($filterOwner?'&owner='.urlencode($filterOwner):'').($filterRep?'&rep='.urlencode($filterRep):'').($filterPhone?'&phone='.urlencode($filterPhone):'');
    ?>
    <div class="vfp-row">
      <a href="<?= $baseUrl ?>" class="vfp <?= !$filterVisit?'active':'' ?>">All Records</a>
      <a href="<?= $baseUrl ?>&visit=upcoming10" class="vfp vfp-yellow <?= $filterVisit==='upcoming10'?'active':'' ?>">
        🟡 Upcoming (10 days)<?php if($upcomingCount): ?> <span class="nbadge"><span><?= $upcomingCount ?></span></span><?php endif; ?>
      </a>
      <a href="<?= $baseUrl ?>&visit=overdue" class="vfp vfp-red <?= $filterVisit==='overdue'?'active':'' ?>">
        🔴 Overdue<?php if($overdueCount): ?> <span class="nbadge"><span><?= $overdueCount ?></span></span><?php endif; ?>
      </a>
      <a href="<?= $baseUrl ?>&visit=has_visit" class="vfp <?= $filterVisit==='has_visit'?'active':'' ?>">📅 Has Visit Date</a>
    </div>

    <!-- ALERT BANNERS (only on all-records view) -->
    <?php if(!$filterVisit&&$overdueCount>0): ?>
    <div class="alert-bar red">
      <span style="font-size:1.2rem">🔴</span>
      <div><div style="font-weight:700"><?= $overdueCount ?> overdue visit<?= $overdueCount!==1?'s':''?>!</div><div style="font-size:.8rem;margin-top:2px">Visits past their scheduled date. <a href="<?= $baseUrl ?>&visit=overdue" style="color:inherit;font-weight:700;text-decoration:underline">View overdue →</a></div></div>
    </div>
    <?php endif; ?>
    <?php if(!$filterVisit&&$upcomingCount>0): ?>
    <div class="alert-bar yellow">
      <span style="font-size:1.2rem">🟡</span>
      <div><div style="font-weight:700"><?= $upcomingCount ?> visit<?= $upcomingCount!==1?'s':''?> in the next 10 days</div><div style="font-size:.8rem;margin-top:2px">Follow up before the visit date. <a href="<?= $baseUrl ?>&visit=upcoming10" style="color:inherit;font-weight:700;text-decoration:underline">View upcoming →</a></div></div>
    </div>
    <?php endif; ?>

    <!-- STATS -->
    <?php $totHot=count($groupedSubs['hot']); $totWarm=count($groupedSubs['warm']); $totCold=count($groupedSubs['cold']); $totComp=count(array_filter($subs,fn($s)=>$s['order_completed'])); ?>
    <div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap">
      <div class="stat-card" style="border-left:4px solid #16a34a"><div style="font-size:1.5rem;font-weight:700;color:#16a34a"><?= $totHot ?></div><div style="font-size:.76rem;color:var(--c-gray-500)">🟢 Hot</div></div>
      <div class="stat-card" style="border-left:4px solid #d97706"><div style="font-size:1.5rem;font-weight:700;color:#d97706"><?= $totWarm ?></div><div style="font-size:.76rem;color:var(--c-gray-500)">🟡 Warm</div></div>
      <div class="stat-card" style="border-left:4px solid #dc2626"><div style="font-size:1.5rem;font-weight:700;color:#dc2626"><?= $totCold ?></div><div style="font-size:.76rem;color:var(--c-gray-500)">🔴 Cold</div></div>
      <div class="stat-card" style="border-left:4px solid #15803d"><div style="font-size:1.5rem;font-weight:700;color:#15803d"><?= $totComp ?></div><div style="font-size:.76rem;color:var(--c-gray-500)">✅ Completed</div></div>
      <div class="stat-card" style="border-left:4px solid var(--c-primary)"><div style="font-size:1.5rem;font-weight:700;color:var(--c-primary)"><?= count($removeMap) ?></div><div style="font-size:.76rem;color:var(--c-gray-500)">⏳ Pending Remove</div></div>
      <div class="stat-card" style="border-left:4px solid #0ea5e9;cursor:pointer" onclick="setView('map')"><div style="font-size:1.5rem;font-weight:700;color:#0ea5e9"><?= count($mapMarkers) ?></div><div style="font-size:.76rem;color:var(--c-gray-500)">🗺 GPS Mapped</div></div>
    </div>

    <?php if($filterVillage): ?>
    <div style="margin-bottom:12px;display:flex;align-items:center;gap:8px;font-size:.84rem;color:var(--c-gray-600)">
      <span>📍 Filtered by village:</span><strong style="color:var(--c-gray-800)"><?= htmlspecialchars($filterVillage) ?></strong>
      <a href="my_data.php?view=<?= $viewMode ?><?= $filterZone?"&zone=$filterZone":''?><?= $filterOwner?"&owner=".urlencode($filterOwner):''?><?= $filterRep?"&rep=".urlencode($filterRep):''?><?= $filterPhone?"&phone=".urlencode($filterPhone):''?>" style="color:var(--c-danger);text-decoration:none;font-size:.78rem">✕ Clear</a>
    </div>
    <?php endif; ?>

    <!-- MAP VIEW -->
    <div id="mapView">
      <div id="mapNoGps">⚠️ None of the submissions in your zones have GPS coordinates.</div>
      <div style="position:relative">
        <div id="mapContainer"></div>
        <div class="map-legend">
          <div class="map-legend-item"><div class="map-legend-dot" style="background:#16a34a"></div>Hot</div>
          <div class="map-legend-item"><div class="map-legend-dot" style="background:#d97706"></div>Warm</div>
          <div class="map-legend-item"><div class="map-legend-dot" style="background:#dc2626"></div>Cold</div>
        </div>
      </div>
    </div>

    <!-- CARDS / LIST -->
    <div class="feed-head">
      <div>
        <div class="feed-title">Submission Feed</div>
        <div class="feed-sub">Records are shown by submitted time, newest to oldest.</div>
      </div>
      <span class="sort-pill">↓ Recent submission first</span>
    </div>
    <div id="subGrid">
      <?php if(!$subs): ?>
        <div class="card"><div class="empty-state"><p>No submissions found in your assigned zones. <a href="submit.php">Add one.</a></p></div></div>
      <?php else: ?>
      <div class="sub-grid <?= $viewMode==='list'?'view-list':'' ?>">
        <?php foreach($subs as $s): ?>
          <?= renderCard($s,$areasMap,$detailsMap,$removeMap,$user,$today,$in10Days,$areaImagesMap) ?>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

  </div>
</div>

<!-- EDIT MODAL -->
<div class="modal-overlay" id="editModal">
  <div class="modal" style="max-width:540px">
    <div class="modal-title">Edit Status &amp; Deal Info</div>
    <form method="POST">
      <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="edit_status">
      <input type="hidden" name="submission_id" id="em_id">

      <div class="form-group"><label class="form-label">Status</label><select class="form-control" name="status" id="em_status"><option value="hot">🟢 Hot — Active interest</option><option value="warm">🟡 Warm — Follow-up needed</option><option value="cold">🔴 Cold — Closed / inactive</option></select></div>

      <!-- NEXT VISIT DATE -->
      <div class="form-group">
        <label class="form-label">📅 Next Visit Date <span style="font-size:.75rem;color:var(--c-gray-400)">(optional)</span></label>
        <input type="date" class="form-control" name="next_visit_date" id="em_visit" min="<?= date('Y-m-d') ?>" onchange="updateVP()">
        <div id="em_vp" style="font-size:.78rem;color:#0369a1;margin-top:4px;min-height:18px"></div>
        <div style="display:flex;gap:5px;margin-top:6px;flex-wrap:wrap">
          <button type="button" class="vs-btn" onclick="sv('<?= date('Y-m-d',strtotime('+1 day')) ?>')">Tomorrow</button>
          <button type="button" class="vs-btn" onclick="sv('<?= date('Y-m-d',strtotime('+3 days')) ?>')">+3 Days</button>
          <button type="button" class="vs-btn" onclick="sv('<?= date('Y-m-d',strtotime('+7 days')) ?>')">+1 Week</button>
          <button type="button" class="vs-btn" onclick="sv('<?= date('Y-m-d',strtotime('+1 month')) ?>')">+1 Month</button>
          <button type="button" class="vs-btn red" onclick="document.getElementById('em_visit').value='';updateVP()">✕ Clear</button>
        </div>
      </div>

      <div class="form-group"><label class="form-label">Notes</label><textarea class="form-control" name="detail_description" id="em_desc" rows="2" placeholder="Any notes about this lead..."></textarea></div>
      <div class="form-group"><label class="form-label">Deal Result</label><select class="form-control" name="deal_result" id="em_deal" onchange="handleDealChange(this.value)"><option value="">— Select result —</option><option value="Order Completed">✅ Order Completed</option><option value="Not Completed">❌ Not Completed</option><option value="Purchased from Other Vendor">🏪 Purchased from Other Vendor</option><option value="Other Reason">📝 Other Reason</option></select></div>

      <div id="vendorSection" style="display:none">
        <div class="form-row"><div class="form-group"><label class="form-label">Vendor Name</label><input class="form-control" type="text" name="vendor_name" id="em_vname" placeholder="Vendor / company name"></div><div class="form-group"><label class="form-label">Vendor Details</label><input class="form-control" type="text" name="vendor_details" id="em_vdetails" placeholder="Phone / address / website"></div></div>
        <div class="form-group"><label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.84rem"><input type="checkbox" id="vendorUnknown" onchange="toggleVendorUnknown(this)"> Vendor details not available — use Other Reason instead</label></div>
      </div>
      <div id="otherSection" style="display:none"><div class="form-group"><label class="form-label">Close Note / Other Reason</label><textarea class="form-control" name="other_reason" id="em_other" rows="2" placeholder="Describe why the deal is closed..."></textarea></div></div>

      <div id="ocWarn" style="display:none;background:#fef9c3;border:1.5px solid #fde68a;border-radius:8px;padding:10px 14px;font-size:.83rem;color:#78350f;margin-bottom:12px">
        ⚠️ <strong>Selecting "Order Completed" will permanently lock this record.</strong> The edit button will be disabled after saving.
      </div>

      <div class="alert alert-info" style="font-size:.82rem">All changes are logged and visible to main admin.</div>
      <div class="modal-footer"><button type="button" class="btn btn-ghost" onclick="closeModal('editModal')">Cancel</button><button type="submit" class="btn btn-primary" id="em_sb">Save Changes</button></div>
    </form>
  </div>
</div>

<!-- REMOVE MODAL -->
<div class="modal-overlay" id="removeModal">
  <div class="modal" style="max-width:440px">
    <div class="modal-title">🗑 Request Record Removal</div>
    <p style="font-size:.87rem;color:var(--c-gray-600);margin-bottom:14px">Record deletion requires <strong>admin approval</strong>. The record will remain visible until the admin accepts your request.</p>
    <form method="POST">
      <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="request_remove">
      <input type="hidden" name="submission_id" id="rm_id">
      <div class="form-group"><label class="form-label">Record</label><input class="form-control" type="text" id="rm_name" disabled></div>
      <div class="form-group"><label class="form-label">Reason for Removal <span style="color:var(--c-danger)">*</span></label><textarea class="form-control" name="remove_reason" rows="3" required placeholder="Explain why this record should be removed..."></textarea></div>
      <div class="alert" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;font-size:.82rem;border-radius:6px;padding:10px 12px">⚠️ This sends a request to the main admin. You cannot cancel this request once sent.</div>
      <div class="modal-footer"><button type="button" class="btn btn-ghost" onclick="closeModal('removeModal')">Cancel</button><button type="submit" class="btn" style="background:#dc2626;color:#fff;border:none">Send Request</button></div>
    </form>
  </div>
</div>

<nav class="mob-nav">
  <div class="mob-nav-items">
    <a href="dashboard.php" class="mob-nav-item"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>Home</a>
    <a href="submit.php" class="mob-nav-item"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>Submit</a>
    <a href="my_data.php" class="mob-nav-item active"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>My Data</a>
    <a href="locations.php" class="mob-nav-item"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>Zones</a>
  </div>
</nav>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const MAP_MARKERS=<?= json_encode($mapMarkers) ?>;
const STATUS_COLORS={hot:'#16a34a',warm:'#d97706',cold:'#dc2626'};
let mapInitialized=false;
function setView(v){
    ['grid','list','map'].forEach(id=>document.getElementById('vbtn-'+id)?.classList.toggle('active',id===v));
    const grid=document.getElementById('subGrid'),mapView=document.getElementById('mapView');
    if(v==='map'){if(grid)grid.style.display='none';if(mapView)mapView.style.display='block';initMap();}
    else{if(mapView)mapView.style.display='none';if(grid){grid.style.display='';grid.querySelectorAll('.sub-grid').forEach(g=>g.classList.toggle('view-list',v==='list'));}}
    document.getElementById('fv').value=v;
    const u=new URL(window.location);u.searchParams.set('view',v);history.replaceState({},'' ,u);
}
function initMap(){
    if(mapInitialized)return;mapInitialized=true;
    if(MAP_MARKERS.length===0){document.getElementById('mapContainer').style.display='none';document.getElementById('mapNoGps').style.display='block';return;}
    const map=L.map('mapContainer').setView([MAP_MARKERS[0].lat,MAP_MARKERS[0].lng],13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© OpenStreetMap contributors',maxZoom:19}).addTo(map);
    MAP_MARKERS.forEach(m=>{
        const color=STATUS_COLORS[m.status]||'#6b7280';
        const icon=L.divIcon({className:'',html:`<div style="width:16px;height:16px;border-radius:50%;background:${color};border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.4)"></div>`,iconSize:[16,16],iconAnchor:[8,8],popupAnchor:[0,-10]});
        const emoji={hot:'🟢',warm:'🟡',cold:'🔴'}[m.status]||'';
        L.marker([m.lat,m.lng],{icon}).addTo(map).bindPopup(`<b>${m.owner}</b><br>📞 ${m.phone}<br>📍 ${m.village}, ${m.street}<br>🏷 ${m.zone}<br>${emoji} ${m.status}<br>🕐 ${m.time}`);
    });
    if(MAP_MARKERS.length>1)map.fitBounds(L.latLngBounds(MAP_MARKERS.map(m=>[m.lat,m.lng])),{padding:[30,30]});
}
(function(){const v='<?= $viewMode ?>';if(v==='map'){document.getElementById('subGrid').style.display='none';document.getElementById('mapView').style.display='block';initMap();}else if(v==='list'){document.querySelectorAll('.sub-grid').forEach(g=>g.classList.add('view-list'));}})();

const DAYS=['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
const MONS=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
function sv(iso){document.getElementById('em_visit').value=iso;updateVP();}
function updateVP(){
    const val=document.getElementById('em_visit').value,el=document.getElementById('em_vp');
    if(!val){el.innerHTML='';return;}
    const p=val.split('-'),d=new Date(+p[0],+p[1]-1,+p[2]);
    const today=new Date();today.setHours(0,0,0,0);
    const diff=Math.round((d-today)/86400000);
    const lbl=diff===0?'today':diff===1?'tomorrow':diff>0?'in '+diff+' days':Math.abs(diff)+' days ago';
    const col=diff<0?'#dc2626':diff<=10?'#d97706':'#0369a1';
    el.innerHTML='📅 '+DAYS[d.getDay()]+', '+d.getDate()+' '+MONS[d.getMonth()]+' '+d.getFullYear()+' <strong style="color:'+col+';">('+lbl+')</strong>';
}
function editModal(s){
    const d=s.detail||{};
    document.getElementById('em_id').value      =s.id;
    document.getElementById('em_status').value  =s.status;
    document.getElementById('em_desc').value    =d.detail_description||'';
    document.getElementById('em_deal').value    =d.deal_result||'';
    document.getElementById('em_vname').value   =d.vendor_name||'';
    document.getElementById('em_vdetails').value=d.vendor_details||'';
    document.getElementById('em_other').value   =d.other_reason||'';
    document.getElementById('em_visit').value   =s.next_visit_date||d.next_visit_date||'';
    document.getElementById('vendorUnknown').checked=false;
    handleDealChange(d.deal_result||'');
    updateVP();
    openModal('editModal');
}
function handleDealChange(v){
    document.getElementById('vendorSection').style.display=v==='Purchased from Other Vendor'?'block':'none';
    document.getElementById('otherSection').style.display=(v==='Other Reason'||v==='Not Completed')?'block':'none';
    document.getElementById('ocWarn').style.display=v==='Order Completed'?'block':'none';
    const btn=document.getElementById('em_sb');
    if(v==='Order Completed'){btn.textContent='✅ Complete Order & Lock';btn.style.background='#16a34a';}
    else{btn.textContent='Save Changes';btn.style.background='';}
}
function toggleVendorUnknown(cb){
    ['em_vname','em_vdetails'].forEach(id=>{const el=document.getElementById(id);el.disabled=cb.checked;if(cb.checked)el.value='';});
    document.getElementById('otherSection').style.display=cb.checked?'block':'none';
}
function removeModal(id,name){document.getElementById('rm_id').value=id;document.getElementById('rm_name').value=name;openModal('removeModal');}
</script>
<?php pageFooter(); ?>
