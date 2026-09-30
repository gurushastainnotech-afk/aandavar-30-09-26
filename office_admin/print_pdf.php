<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin('office_admin');

$db   = getDB();
$user = currentUser();
$id   = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: subadmins.php'); exit; }

/* ── Security: submission must belong to one of this office_admin's sub_admins ── */
$mySubsQ = $db->prepare("SELECT id FROM users WHERE office_admin_id=? AND role='sub_admin'");
$mySubsQ->execute([$user['id']]);
$mySubIds = array_column($mySubsQ->fetchAll(), 'id');

if (empty($mySubIds)) {
    die('<p style="font-family:sans-serif;color:red;padding:20px">No sub-admins assigned to you.</p>');
}
$in   = implode(',', array_fill(0, count($mySubIds), '?'));
$stmt = $db->prepare("SELECT * FROM submissions WHERE id=? AND submitted_by IN ($in)");
$stmt->execute(array_merge([$id], $mySubIds));
$s = $stmt->fetch();

if (!$s) { die('<p style="font-family:sans-serif;color:red;padding:20px">Record not found or access denied.</p>'); }
if (!$s['order_completed']) { die('<p style="font-family:sans-serif;color:red;padding:20px">❌ PDF is only available for completed orders.</p>'); }

/* ── Load related data ─────────────────────────────────────────────── */
$zoneQ = $db->prepare("SELECT sd.code AS zone, sd.from_location, sd.to_location, d.code AS dir_code, d.label AS dir_label FROM sub_directions sd JOIN directions d ON sd.direction_id=d.id WHERE sd.id=?");
$zoneQ->execute([$s['sub_direction_id']]); $zone = $zoneQ->fetch();

$areasQ = $db->prepare("SELECT * FROM submission_areas WHERE submission_id=? ORDER BY sort_order");
$areasQ->execute([$id]); $areas = $areasQ->fetchAll();

$detailQ = $db->prepare("SELECT * FROM submission_status_details WHERE submission_id=?");
$detailQ->execute([$id]); $detail = $detailQ->fetch();

$subUserQ = $db->prepare("SELECT username FROM users WHERE id=?");
$subUserQ->execute([$s['submitted_by']]); $subUser = $subUserQ->fetch();

$allAreaIds = array_column($areas, 'id');
$areaImagesMap = [];
if ($allAreaIds) {
    $ina = implode(',', array_fill(0, count($allAreaIds), '?'));
    $iq  = $db->prepare("SELECT * FROM submission_area_images WHERE area_id IN ($ina) ORDER BY area_id, sort_order");
    $iq->execute($allAreaIds);
    foreach ($iq->fetchAll() as $img) $areaImagesMap[$img['area_id']][] = $img;
}

$printDate = date('d M Y, H:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Customer Report — <?= htmlspecialchars($s['owner_name']) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Arial,sans-serif;font-size:12px;color:#1a1a1a;background:#fff;padding:20px}
.print-btn{position:fixed;top:12px;right:12px;background:#2563eb;color:#fff;border:none;padding:10px 22px;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600;z-index:999;box-shadow:0 2px 8px rgba(37,99,235,.4)}
@media print{.print-btn{display:none!important}*{-webkit-print-color-adjust:exact;print-color-adjust:exact}}
.page{max-width:800px;margin:0 auto;background:#fff}
.header{background:linear-gradient(135deg,#1e3a5f,#2563eb);color:#fff;padding:22px 24px;border-radius:8px 8px 0 0;display:flex;justify-content:space-between;align-items:center}
.header h1{font-size:18px;font-weight:700;margin-bottom:4px}
.header p{font-size:11px;opacity:.85}
.header-right{text-align:right;font-size:11px;opacity:.85}
.comp-banner{background:#16a34a;color:#fff;text-align:center;padding:10px;font-size:13px;font-weight:700;letter-spacing:.05em}
.section{border:1px solid #e5e7eb;margin-top:-1px}
.section-title{background:#f3f4f6;padding:9px 16px;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#374151;border-bottom:1px solid #e5e7eb}
.section-body{padding:14px 16px}
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px 20px}
.info-item label{font-size:10px;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;display:block;margin-bottom:2px}
.info-item span{font-size:12px;font-weight:600;color:#111827}
.status-hot{background:#d1fae5;color:#065f46;padding:3px 10px;border-radius:99px;font-weight:700;font-size:11px}
.status-warm{background:#fef3c7;color:#92400e;padding:3px 10px;border-radius:99px;font-weight:700;font-size:11px}
.status-cold{background:#fee2e2;color:#991b1b;padding:3px 10px;border-radius:99px;font-weight:700;font-size:11px}
.area-row{border:1px solid #e5e7eb;border-radius:6px;padding:12px;margin-bottom:8px}
.area-tag{background:#eff6ff;color:#1d4ed8;padding:3px 10px;border-radius:4px;font-size:11px;font-weight:600}
.img-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin-top:8px}
.img-grid img{width:100%;aspect-ratio:1;object-fit:cover;border-radius:4px;border:1px solid #e5e7eb}
.tile-wrap{text-align:center;padding:14px;background:#f9fafb;border-radius:6px}
.tile-wrap img{max-width:100%;max-height:200px;object-fit:contain;border:2px solid #16a34a;border-radius:8px}
.deal-box{background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:12px}
.purchase-banner{background:#16a34a;color:#fff;text-align:center;padding:10px;border-radius:6px;font-size:14px;font-weight:700;margin-bottom:10px}
.prop-photos{display:flex;gap:10px;flex-wrap:wrap}
.prop-photos img{height:130px;width:130px;object-fit:cover;border-radius:6px;border:1px solid #e5e7eb}
.footer{text-align:center;font-size:10px;color:#9ca3af;padding:16px;border-top:1px solid #e5e7eb;margin-top:16px}
</style>
</head>
<body>
<button class="print-btn" onclick="window.print()">🖨 Print / Save PDF</button>
<div class="page">

  <div class="header">
    <div>
      <h1>Aandavar FieldApp</h1>
      <p>Customer Data Report</p>
    </div>
    <div class="header-right">
      <div>Printed by: <?= htmlspecialchars($user['username']) ?> (Office Admin)</div>
      <div>Sub Admin: <?= htmlspecialchars($subUser['username'] ?? '—') ?></div>
      <div><?= $printDate ?></div>
      <div>Report #<?= str_pad($s['id'], 5, '0', STR_PAD_LEFT) ?></div>
    </div>
  </div>

  <div class="comp-banner">✅ ORDER COMPLETED<?= $s['purchase_date'] ? ' — Purchase Date: '.date('d M Y', strtotime($s['purchase_date'])) : '' ?></div>

  <!-- Customer Info -->
  <div class="section">
    <div class="section-title">👤 Customer Information</div>
    <div class="section-body">
      <div class="info-grid">
        <div class="info-item"><label>Owner Name</label><span><?= htmlspecialchars($s['owner_name']) ?></span></div>
        <div class="info-item"><label>Owner Phone</label><span><?= htmlspecialchars($s['owner_number']) ?></span></div>
        <div class="info-item"><label>Representative</label><span><?= htmlspecialchars($s['representative_name']) ?></span></div>
        <div class="info-item"><label>Rep Phone</label><span><?= htmlspecialchars($s['representative_number']) ?></span></div>
        <div class="info-item"><label>Village / Town</label><span><?= htmlspecialchars($s['village_city_town']) ?></span></div>
        <div class="info-item"><label>Street / Address</label><span><?= htmlspecialchars($s['street']) ?></span></div>
        <div class="info-item"><label>Zone</label><span><?= htmlspecialchars(($zone['dir_code']??'').' › '.($zone['zone']??'')) ?></span></div>
        <div class="info-item"><label>Status</label><span class="status-<?= $s['status'] ?>"><?= ucfirst($s['status']) ?></span></div>
        <?php if ($s['next_visit_date']): ?>
        <div class="info-item"><label>Next Visit</label><span><?= date('d M Y (D)', strtotime($s['next_visit_date'])) ?></span></div>
        <?php endif; ?>
        <div class="info-item"><label>Submitted</label><span><?= date('d M Y H:i', strtotime($s['device_submitted_at']?:$s['submitted_at'])) ?></span></div>
      </div>
    </div>
  </div>

  <!-- Deal Info -->
  <?php if ($detail || $s['purchase_date'] || $s['purchase_notes']): ?>
  <div class="section">
    <div class="section-title">💼 Deal Information</div>
    <div class="section-body">
      <?php if ($s['purchase_date']): ?>
      <div class="purchase-banner">📅 Purchase Date: <?= date('d M Y (D)', strtotime($s['purchase_date'])) ?></div>
      <?php endif; ?>
      <div class="deal-box">
        <?php if ($detail && $detail['deal_result']): ?><div style="font-size:13px;font-weight:700;margin-bottom:8px"><?= htmlspecialchars($detail['deal_result']) ?></div><?php endif; ?>
        <?php if ($detail && $detail['vendor_name']): ?><div style="margin-bottom:4px"><strong>Vendor:</strong> <?= htmlspecialchars($detail['vendor_name']) ?></div><?php endif; ?>
        <?php if ($detail && $detail['vendor_details']): ?><div style="margin-bottom:4px"><strong>Vendor Contact:</strong> <?= htmlspecialchars($detail['vendor_details']) ?></div><?php endif; ?>
        <?php if ($s['purchase_notes']): ?><div style="margin-bottom:4px"><strong>Purchase Notes:</strong> <?= htmlspecialchars($s['purchase_notes']) ?></div><?php endif; ?>
        <?php if ($detail && $detail['detail_description']): ?><div><strong>Notes:</strong> <?= htmlspecialchars($detail['detail_description']) ?></div><?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Tile Image -->
  <?php if ($s['tile_image']): ?>
  <div class="section">
    <div class="section-title">🪟 Tile Image</div>
    <div class="section-body">
      <div class="tile-wrap">
        <img src="<?= htmlspecialchars(rtrim(UPLOAD_URL,'/') . '/' . ltrim($s['tile_image'],'/')) ?>" alt="Tile Image" onerror="this.style.display='none'">
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Property Photos -->
  <?php if ($s['landmark_picture'] || $s['additional_picture']): ?>
  <div class="section">
    <div class="section-title">🏠 Property Photos</div>
    <div class="section-body">
      <div class="prop-photos">
        <?php if ($s['landmark_picture']): ?>
        <img src="<?= htmlspecialchars(rtrim(UPLOAD_URL,'/').'/'.ltrim($s['landmark_picture'],'/')) ?>" alt="Landmark" onerror="this.style.display='none'">
        <?php endif; ?>
        <?php if ($s['additional_picture']): ?>
        <img src="<?= htmlspecialchars(rtrim(UPLOAD_URL,'/').'/'.ltrim($s['additional_picture'],'/')) ?>" alt="Additional" onerror="this.style.display='none'">
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Areas & Images -->
  <?php if ($areas): ?>
  <div class="section">
    <div class="section-title">📐 Areas / Rooms</div>
    <div class="section-body">
      <?php foreach ($areas as $a):
        $aImgs = $areaImagesMap[$a['id']] ?? [];
        $sqft  = $a['total_sqft'] ?? $a['area_sqft'] ?? null;
      ?>
      <div class="area-row">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;flex-wrap:wrap">
          <span class="area-tag"><?= htmlspecialchars($a['area_type']) ?></span>
          <span style="font-size:11px;color:#6b7280">
            <?php if ($sqft): ?>Sqft: <?= number_format((float)$sqft) ?><?php endif; ?>
            <?php if ($a['area_length'] && $a['area_breadth']): ?> | L×B: <?= $a['area_length'] ?>×<?= $a['area_breadth'] ?> ft<?php endif; ?>
            <?php if ($a['area_count']): ?> | Units: <?= (int)$a['area_count'] ?><?php endif; ?>
          </span>
        </div>
        <?php if ($aImgs): ?>
        <div class="img-grid">
          <?php foreach ($aImgs as $img): ?>
          <img src="<?= htmlspecialchars(rtrim(UPLOAD_URL,'/').'/'.ltrim($img['image_filename'],'/')) ?>"
               alt="<?= htmlspecialchars($a['area_type']) ?>"
               onerror="this.style.display='none'">
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- GPS -->
  <?php if (!empty($s['latitude']) && !empty($s['longitude'])): ?>
  <div class="section">
    <div class="section-title">📍 GPS Location</div>
    <div class="section-body">
      <span style="font-family:monospace"><?= $s['latitude'] ?>, <?= $s['longitude'] ?></span>
      &nbsp;&nbsp;
      <a href="https://maps.google.com/?q=<?= $s['latitude'] ?>,<?= $s['longitude'] ?>" target="_blank" style="color:#2563eb">View on Google Maps →</a>
    </div>
  </div>
  <?php endif; ?>

  <div class="footer">
    Generated by Aandavar FieldApp · <?= $printDate ?> · Office Admin: <?= htmlspecialchars($user['username']) ?> · This is an official customer data record.
  </div>
</div>
</body>
</html>
