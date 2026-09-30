<?php
/**
 * print_submission.php — v7.1
 *
 * • ?estimate=1 → shows estimate pricing INSIDE each room card
 *   - Purple block: MRP/box | MRP Total | Disc% | MOP/box | MOP Total | Offer
 *   - Matched by area_id from submission_estimate_rooms
 * • Visualizer snapshots on Sheet 2 (new page) as before
 * • All v5.0 tile calc / bath partition / photos unchanged
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin('office_admin');

$db   = getDB();
$user = currentUser();

$subId        = (int)($_GET['id']       ?? 0);
$phone        = trim($_GET['phone']     ?? '');
$showEstimate = isset($_GET['estimate']) && $_GET['estimate'] === '1';

if (!$subId) { http_response_code(400); die('Missing submission ID.'); }

// ── Submission ──────────────────────────────────────────────────────────────
$stmt = $db->prepare(
    "SELECT s.*, u.username AS sub_name, sd.code AS zone, d.code AS dir_code
     FROM submissions s
     JOIN users u           ON s.submitted_by     = u.id
     JOIN sub_directions sd ON s.sub_direction_id = sd.id
     JOIN directions d      ON sd.direction_id    = d.id
     WHERE s.id = ?"
);
$stmt->execute([$subId]);
$s = $stmt->fetch();
if (!$s) { http_response_code(404); die('Submission not found.'); }

// ── Areas ───────────────────────────────────────────────────────────────────
$aq = $db->prepare("SELECT * FROM submission_areas WHERE submission_id = ? ORDER BY sort_order");
$aq->execute([$subId]);
$areas = $aq->fetchAll();

// ── Area images ─────────────────────────────────────────────────────────────
$areaImgs = [];
if ($areas) {
    $aIds = array_column($areas, 'id');
    $in   = implode(',', array_fill(0, count($aIds), '?'));
    $iq   = $db->prepare("SELECT * FROM submission_area_images WHERE area_id IN ($in) ORDER BY area_id, sort_order");
    $iq->execute($aIds);
    foreach ($iq->fetchAll() as $img) {
        $areaImgs[(int)$img['area_id']][] = $img;
    }
}

// ── Status details ──────────────────────────────────────────────────────────
$dq = $db->prepare("SELECT * FROM submission_status_details WHERE submission_id = ?");
$dq->execute([$subId]);
$detail = $dq->fetch() ?: null;

// ── Visualizer snapshots ────────────────────────────────────────────────────
$vsQ = $db->prepare("SELECT id, submission_id, filename, owner_name, created_at FROM visualizer_snapshots WHERE submission_id = ? ORDER BY id ASC");
$vsQ->execute([$subId]);
$vizSnaps = $vsQ->fetchAll();

// ── Estimate data ───────────────────────────────────────────────────────────
$estimateRow    = null;
$estRoomsByArea = [];   // area_id => estimate room rows

if ($showEstimate) {
    try {
        $eStmt = $db->prepare(
            "SELECT se.*, u.username AS created_by_name
             FROM submission_estimates se
             LEFT JOIN users u ON se.created_by = u.id
             WHERE se.submission_id = ? LIMIT 1"
        );
        $eStmt->execute([$subId]);
        $estimateRow = $eStmt->fetch() ?: null;

        if ($estimateRow) {
            $erStmt = $db->prepare(
                "SELECT * FROM submission_estimate_rooms
                 WHERE estimate_id = ?
                 ORDER BY sort_order, id"
            );
            $erStmt->execute([$estimateRow['id']]);
            foreach ($erStmt->fetchAll() as $er) {
                $key = $er['area_id'] ? (int)$er['area_id'] : 'row_'.$er['id'];
                $estRoomsByArea[$key] ??= [];
                $estRoomsByArea[$key][] = $er;
            }
        }
    } catch (Throwable $e) {
        $estimateRow    = null;
        $estRoomsByArea = [];
    }
}

/* ════════════════════════════════════════════════════════════════════
   HELPERS
════════════════════════════════════════════════════════════════════ */
function imgUrl(string $f): string {
    if ($f === '') return '';
    return rtrim(UPLOAD_URL, '/') . '/' . ltrim($f, '/');
}
function vizSnapSrc(array $vs): string {
    return !empty($vs['filename']) ? imgUrl($vs['filename']) : '';
}
function h(mixed $v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}
function statusLabel(string $s): string {
    return [
        'hot'              => '🟢 Hot',
        'warm'             => '🟡 Warm',
        'cold'             => '🔴 Cold',
        'enquiry'          => '🔵 Enquiry',
        'billed'           => '✅ Billed',
        'outside_purchase' => '🏪 Outside Purchase',
        'others'           => '📝 Others',
    ][$s] ?? ucfirst($s);
}
function dealLabel(?string $d): string {
    if (!$d) return '';
    return [
        'Order Completed'             => '✅ Order Completed',
        'Not Completed'               => '❌ Not Completed',
        'Purchased from Other Vendor' => '🏪 Purchased from Other Vendor',
        'Other Reason'                => '📝 Other Reason',
    ][$d] ?? h($d);
}
function tileSizeLabel(string $key): string {
    return [
        '1|1|9'       => '300×300 mm — 9/box',
        '1|1|10'      => '300×300 mm — 10/box',
        '1.32|1.32|5' => '400×400 mm — 5/box',
        '2|2|4'       => '600×600 mm — 4/box',
        '2|2|3'       => '600×600 mm — 3/box',
        '2.63|2.63|3' => '800×800 mm — 3/box',
        '2.63|4|2'    => '800×1200 mm — 2/box',
        '2.63|5.25|2' => '800×1600 mm — 2/box',
        '4|6|2'       => '1200×1800 mm — 2/box',
        '1|1.5|6'     => '300×450 mm — 6/box',
        '1|1.5|5'     => '300×450 mm — 5/box',
        '2|1|5'       => '600×300 mm — 5/box',
        '2|1|6'       => '600×300 mm — 6/box',
        '2|3|1'       => '600×900 mm — 1/box',
        '1|1|8'       => '300×300 mm — 8/box',
        '4|2|2'       => '1200×600 mm — 2/box',
        '2.63|8|1'    => '800×2400 mm — 1/box',
        '1.5|1|6'     => '450×300 mm — 6/box',
        '1.25|1.25|5' => '380×380 mm — 5/box',
        '2.75|5.5|2'  => '800×1600 mm — 2/box',
        '6|4|2'       => '1800×1200 mm — 2/box',
        '2.75|8|1'    => '800×2400 mm — 1/box',
        'custom'      => 'Custom Size',
    ][$key] ?? $key;
}
function customTileSizeLabel(?string $key, $custom = null): string {
    if (is_string($custom) && $custom !== '') {
        $decoded = json_decode($custom, true);
        if (json_last_error() === JSON_ERROR_NONE) $custom = $decoded;
    }
    if (is_array($custom)) {
        $w = (float)($custom['w'] ?? $custom['width'] ?? $custom['custw'] ?? $custom['custom_w'] ?? 0);
        $h = (float)($custom['h'] ?? $custom['height'] ?? $custom['custh'] ?? $custom['custom_h'] ?? 0);
        $ppb = (int)($custom['ppb'] ?? $custom['pieces_per_box'] ?? $custom['custppb'] ?? $custom['custom_ppb'] ?? 0);
        if ($w > 0 && $h > 0 && $ppb > 0) {
            return fmtNum($w, 2) . ' × ' . fmtNum($h, 2) . ' ft · ' . $ppb . '/box';
        }
    }
    return tileSizeLabel($key ?: '');
}
function estimateTileLabelForArea(array $estRow, array $area): string {
    $saved = trim((string)($estRow['tile_size_label'] ?? ''));
    if ($saved !== '' && !in_array(strtolower($saved), ['custom', 'custom size'], true)) {
        return $saved;
    }
    $surface = (string)($estRow['surface'] ?? 'floor');
    if ($surface === 'floor') {
        return customTileSizeLabel($area['floor_tile_size_key'] ?? $area['tile_size_key'] ?? '2|2|4', $area['floor_tile_custom'] ?? null);
    }
    if ($surface === 'wall') {
        return customTileSizeLabel($area['wall_tile_size_key'] ?? '1|1|8', $area['wall_tile_custom'] ?? null);
    }
    if ($surface === 'bath') {
        return customTileSizeLabel($area['bath_tile_size_key'] ?? $area['bath_tile_size'] ?? '1|1|8', $area['bath_tile_custom'] ?? null);
    }
    if ($surface === 'step') {
        return customTileSizeLabel($area['step_tile_size_key'] ?? $area['step_riser_tile_size_key'] ?? '3|1|1', $area['step_tile_custom'] ?? $area['step_riser_tile_custom'] ?? null);
    }
    if ($surface === 'riser') {
        return customTileSizeLabel($area['riser_tile_size_key'] ?? $area['step_riser_tile_size_key'] ?? '3|0.8|1', $area['riser_tile_custom'] ?? $area['step_riser_tile_custom'] ?? null);
    }
    return $saved !== '' ? $saved : '—';
}
function parseTileOpts(array $a): array {
    static $PRE = [
        'AGL Tiles','Arna Tiles','Asian Granito','Caliber Tiles',
        'Cera Sanitaryware','Classic Marble','Duragres','Euro Ceramics',
        'Gani Tiles','Granitex','H&R Johnson','Kajaria Ceramics',
        'Lavish Tiles','Mulia Tiles','Nitco Tiles','Orient Bell',
        'Porcelain Craft','RAK Ceramics','RoyalCera','Simpolo Tiles',
        'Somany Ceramics','Sunhearrt','Varmora Tiles','Vitero Tiles',
    ];
    $rc = trim($a['tile_company']     ?? '');
    $d  = trim($a['tile_design_name'] ?? '');
    if ($rc === '' && $d === '' && !empty($a['tile_chosen'])) {
        $p  = explode(' — ', $a['tile_chosen'], 2);
        $rc = trim($p[0] ?? '');
        $d  = trim($p[1] ?? '');
    }
    if (str_starts_with($rc, 'OTHER:')) $rc = trim(substr($rc, 6));
    if ($rc === 'OTHER') $rc = '';
    return ['company' => $rc, 'design' => $d, 'brand_full' => implode(' — ', array_filter([$rc, $d]))];
}
function calcBoxes(float $sqft, ?string $tileKey, int $wastePct, $custom = null): ?array {
  if ($sqft <= 0) return null;
  $w = $ht = $ppb = 0;
  // Try standard tile key first
  if (!empty($tileKey) && $tileKey !== 'custom') {
    $p = explode('|', $tileKey);
    $w = (float)($p[0] ?? 0); $ht = (float)($p[1] ?? 0); $ppb = (float)($p[2] ?? 1);
  }
  // If tileKey is custom or parsing failed, try $custom (JSON string or array)
  if ((!$w || !$ht || !$ppb) && !empty($custom)) {
    if (is_string($custom)) {
      $cj = json_decode($custom, true);
      if (json_last_error() === JSON_ERROR_NONE) $custom = $cj;
    }
    if (is_array($custom)) {
      // support several possible key names
      $w   = (float)($custom['w'] ?? $custom['width'] ?? $custom['custw'] ?? $custom['custom_w'] ?? 0);
      $ht  = (float)($custom['h'] ?? $custom['height'] ?? $custom['custh'] ?? $custom['custom_h'] ?? 0);
      $ppb = (float)($custom['ppb'] ?? $custom['pieces_per_box'] ?? $custom['custppb'] ?? $custom['custom_ppb'] ?? 0);
    }
  }
  if (!$w || !$ht || !$ppb) return null;
  $tiles = (int)ceil($sqft / ($w * $ht));
  $extra = (int)ceil($tiles * ($wastePct / 100));
  $total = $tiles + $extra;
  return ['tiles' => $tiles, 'extra' => $extra, 'total' => $total, 'boxes' => (int)ceil($total / $ppb)];
}
function fmtNum(mixed $v, int $dec = 0): string {
    if ($v === null || $v === '') return '—';
    $f = (float)$v;
    return is_finite($f) ? number_format($f, $dec) : '—';
}
function fmtINR(mixed $v): string {
    return '₹' . number_format((float)($v ?? 0), 2, '.', ',');
}
function estTotalBoxes(mixed $baseBoxes, mixed $extraBox): int {
    return max(0, (int)$baseBoxes + (int)$extraBox);
}
function estDiscPct(mixed $mrpPerBox, mixed $mopPerBox): float {
    $mrp = (float)$mrpPerBox;
    $mop = (float)$mopPerBox;
    if ($mrp <= 0) return 0.0;
    return round(max(0, 100 - (($mop / $mrp) * 100)), 2);
}
function estAfterOffer(mixed $total, mixed $offerPct): float {
    $total = (float)$total;
    $pct = (float)$offerPct;
    if ($pct <= 0) return $total;
    return round($total * (1 - ($pct / 100)), 2);
}
function surfaceLabel(string $surface): string {
    return [
        'floor'      => 'Floor',
        'wall'       => 'Wall',
        'both'       => 'Floor + Wall',
        'bath'       => 'Bath',
        'step'       => 'Step',
        'riser'      => 'Riser',
        'step_riser' => 'Step + Riser',
    ][$surface] ?? ucfirst($surface);
}

/* ── Derived ─────────────────────────────────────────────────────── */
$dispTime        = $s['device_submitted_at'] ?: $s['submitted_at'];
$printDate       = date('d M Y, h:i A');
$backUrl         = (($user['role'] ?? '') === 'main_admin')
    ? APP_URL . '/admin/office_reports.php'
    : 'subadmins.php' . ($phone ? '?phone_lookup='.urlencode($phone) : '');
$renderableSnaps = array_filter($vizSnaps, fn($v) => vizSnapSrc($v) !== '');
$propertyImageCount = count(array_filter([
    $s['landmark_picture'] ?? '',
    $s['additional_picture'] ?? '',
    $s['tile_image'] ?? '',
]));
$totalAreaRecords = count($areas);
$totalFloorSqftAll = 0.0;
$totalWallSqftAll  = 0.0;
$totalBathAreas    = 0;
$totalStepAreas    = 0;
$totalAreaPhotos   = 0;
foreach ($areas as $areaRow) {
    $surfaceRow = (string)($areaRow['tile_surface'] ?? 'floor');
    $totalFloorSqftAll += (float)($areaRow['floor_sqft'] ?? $areaRow['total_sqft'] ?? 0);
    $totalWallSqftAll  += (float)($areaRow['wall_sqft'] ?? 0);
    if (!empty($areaRow['is_bathroom'])) $totalBathAreas++;
    if (in_array($surfaceRow, ['step', 'riser', 'step_riser'], true)) $totalStepAreas++;
    $totalAreaPhotos += count($areaImgs[(int)($areaRow['id'] ?? 0)] ?? []);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Print — <?= h($s['owner_name']) ?> — Aandavar Enterprises</title>
<style>
/* ── Reset ── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}

/* ── Variables ── */
:root{
  --amber:#b45309;  --amber-d:#92400e; --amber-lt:#fffbeb; --amber-bd:#fcd34d;
  --floor:#15803d;  --floor-lt:#dcfce7;
  --wall:#1e40af;   --wall-lt:#dbeafe;
  --bath:#6d28d9;   --bath-lt:#f5f3ff;
  --green:#15803d;  --green-lt:#dcfce7; --green-bd:#86efac;
  --red:#b91c1c;    --red-lt:#fee2e2;
  --blue:#1e40af;   --blue-lt:#dbeafe;  --blue-bd:#93c5fd;
  --brand:#92400e;  --brand-lt:#fef3c7; --brand-bd:#fcd34d;
  --mrp:#15803d;    --mrp-lt:#f0fdf4;   --mrp-bd:#86efac;
  --mop:#1e40af;    --mop-lt:#eff6ff;   --mop-bd:#93c5fd;
  --disc:#92400e;   --disc-lt:#fef9c3;  --disc-bd:#fde68a;
  --save:#7c3aed;   --save-lt:#f5f3ff;  --save-bd:#c4b5fd;
  --purple:#7c3aed; --purple-lt:#f5f3ff;--purple-bd:#c4b5fd;
  --ink:#0f172a;    --ink2:#1e293b;
  --g50:#f8fafc;    --g100:#f1f5f9; --g200:#e2e8f0;
  --g300:#cbd5e1;   --g400:#94a3b8; --g500:#64748b;
  --g600:#475569;   --g700:#334155; --g800:#1e293b;
}

/* ── Base ── */
html,body{
  font-family:'Segoe UI',system-ui,sans-serif;
  font-size:13px;color:var(--ink);background:#fff;
  -webkit-print-color-adjust:exact;print-color-adjust:exact;
}

/* ── Print A4 ── */
@media print{
  @page{size:210mm 297mm;margin:10mm}
  body{background:#fff;padding:0;margin:0}
  .toolbar{display:none!important}
  .sheet{
    width:auto;min-height:auto;margin:0;padding:0 0 12mm;
    background:#fff;box-shadow:none;border-radius:0;position:relative;outline:none;
  }
  .sheet.new-page{break-before:page;page-break-before:always}
  .page-no::after{content:"Page " counter(page);font-variant-numeric:tabular-nums}
  .preview-note{margin:0 0 6px}
  .lh,.mini-lh,.doc-title-row,.print-footer{display:table;width:100%}
  .lh-brand,.lh-meta,.mini-lh-left,.mini-date{display:table-cell;vertical-align:top}
  .lh-meta,.mini-date{text-align:right}
  .summary-strip{
    display:table;width:100%;table-layout:fixed;border-collapse:collapse;margin-bottom:6px;
  }
  .sum-cell{
    display:table-cell;vertical-align:top;min-width:0;width:16.66%;
    padding:5px 6px;border-right:1px solid var(--g200);border-bottom:none;
  }
  .sum-cell:last-child{border-right:none}
  .data-block{margin-bottom:6px}
  .data-flex{display:block}
  .data-row{
    display:table;width:100%;min-width:0;page-break-inside:avoid;padding:3px 0;
  }
  .data-key,.data-val{display:table-cell;vertical-align:top}
  .data-key{width:30mm;min-width:30mm}
  .mini-grid{
    display:table;width:100%;table-layout:fixed;border-collapse:separate;border-spacing:4px 0;
  }
  .mini-box{
    display:table-cell;vertical-align:top;min-width:0;width:25%;
  }
  .image-strip{display:block}
  .image-tile{display:inline-block;vertical-align:top;margin:0 6px 6px 0}
  .sec{margin-bottom:6px}
  .sec-body{padding:5px 0 0}
  .room-list{display:block}
  .room-card{
    display:block;width:100%;margin:0 0 4mm;page-break-inside:avoid;
  }
  .rc-head{display:table;width:100%}
  .rc-head-left,.rc-head-right{display:table-cell;vertical-align:top}
  .rc-head-right{text-align:right}
  .rc-grid{display:block}
  .rc-main,.rc-side{display:block;width:100%;min-width:0}
  .detail-panel{margin-bottom:4px}
  .facts-grid,.surface-metrics,.bath-zone-metrics,.calc-microgrid,.bath-card-grid{
    display:block;
  }
  .fact,.surface-metric,.bath-zone-metric,.calc-micro,.bath-micro{
    display:inline-block;vertical-align:top;width:24%;min-width:0;margin:0 1% 4px 0;
  }
  .surface-card-grid,.bath-zone-grid{display:block}
  .surface-card,.bath-zone-card{
    display:block;width:100%;margin-bottom:4px;page-break-inside:avoid;
  }
  .surface-head,.bath-zone-top{display:table;width:100%}
  .surface-head > div,.bath-zone-top > div{display:table-cell;vertical-align:top}
  .surface-box,.bath-zone-box{text-align:right}
  .bath-zone-metric[style]{grid-column:auto!important}
  .sig-strip{
    display:table;width:100%;table-layout:fixed;margin-top:12px;
  }
  .sig-box{display:table-cell;vertical-align:top}
  .print-footer{
    position:relative;margin-top:6px;padding-top:4px;
  }
  .page-no{
    position:absolute;right:0;bottom:0;
  }
}

/* ── Screen ── */
@media screen{
  body{background:#8a9099;padding-top:54px;padding-bottom:48px}
  .sheet{
    width:210mm;min-height:297mm;padding:10mm 10mm 16mm;margin:14px auto;
    background:#fff;box-shadow:0 6px 30px rgba(0,0,0,.25);
    border-radius:2px;outline:1px solid rgba(0,0,0,.08);position:relative;
  }
  .sheet.new-page::before{
    content:'── continued on new page ──';
    display:block;text-align:center;font-size:9px;color:#9ca3af;
    letter-spacing:.12em;border-top:2px dashed #d1d5db;
    padding-top:10px;margin-bottom:10px;
  }
}

/* ── Toolbar ── */
.toolbar{
  position:fixed;top:0;left:0;right:0;z-index:999;
  background:var(--ink2);color:#fff;
  padding:8px 16px;display:flex;align-items:center;gap:10px;
  box-shadow:0 2px 14px rgba(0,0,0,.35);
}
.toolbar h1{flex:1;font-size:.8rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tb-btn{display:inline-flex;align-items:center;gap:5px;padding:6px 14px;border-radius:5px;border:none;font-size:.75rem;font-weight:700;cursor:pointer;text-decoration:none;white-space:nowrap}
.tb-btn.primary{background:var(--amber);color:#fff}
.tb-btn.ghost{background:rgba(255,255,255,.13);color:#fff}
.no-break{break-inside:avoid;page-break-inside:avoid}

/* ── Letterhead ── */
.lh{display:flex;align-items:center;justify-content:space-between;padding-bottom:8px;border-bottom:2.5px solid var(--ink);margin-bottom:9px;gap:10px}
.lh-brand{display:flex;align-items:center;gap:8px}
.lh-logo{width:34px;height:34px;flex-shrink:0;background:var(--amber);border-radius:3px;overflow:hidden}
.lh-logo img{width:100%;height:100%;object-fit:fill;display:block}
.lh-company{font-size:.92rem;font-weight:800;color:var(--ink)}
.lh-tagline{font-size:.58rem;color:var(--g500);margin-top:1px;font-weight:500;letter-spacing:.05em;text-transform:uppercase}
.lh-meta{text-align:right;font-size:.62rem;color:var(--g500);line-height:1.75}
.lh-meta strong{color:var(--ink);font-weight:600}
.mini-lh{display:flex;align-items:center;justify-content:space-between;padding-bottom:6px;border-bottom:2px solid var(--ink);margin-bottom:9px}
.mini-lh-left{display:flex;align-items:center;gap:6px}
.mini-logo{width:20px;height:20px;background:var(--amber);border-radius:2px;overflow:hidden}
.mini-logo img{width:100%;height:100%;object-fit:fill}
.mini-name{font-size:.72rem;font-weight:700}
.mini-sub{font-size:.57rem;color:var(--g500)}
.mini-date{font-size:.58rem;color:var(--g500)}

/* ── Doc title ── */
.doc-title-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:9px;flex-wrap:wrap;gap:5px}
.doc-title{font-size:.88rem;font-weight:800;color:var(--ink)}
.doc-id{font-size:.62rem;color:var(--g400);margin-top:1px}
.status-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:.66rem;font-weight:700;color:#fff}
.status-badge.hot,.status-badge.billed{background:var(--green)}
.status-badge.warm,.status-badge.outside_purchase,.status-badge.others{background:var(--amber)}
.status-badge.cold,.status-badge.enquiry{background:var(--blue)}
.completed-banner{background:var(--green);color:#fff;text-align:center;font-size:.68rem;font-weight:700;padding:4px 10px;border-radius:4px;margin-bottom:8px;letter-spacing:.04em}

/* ── Section ── */
.sec{margin-bottom:9px}
.sec-head{background:var(--ink2);color:#fff;font-size:.58rem;font-weight:700;letter-spacing:.13em;text-transform:uppercase;padding:3.5px 8px;border-radius:4px 4px 0 0;display:flex;align-items:center;gap:5px}
.sec-body{border:1.5px solid var(--g200);border-top:none;border-radius:0 0 4px 4px;padding:7px 9px}

/* ── Document summary ── */
.preview-note{
  margin:-2px 0 8px;font-size:.56rem;letter-spacing:.08em;text-transform:uppercase;color:var(--g500);
}
.summary-strip{
  display:flex;flex-wrap:wrap;gap:0;border:1.5px solid var(--g300);margin-bottom:8px;
}
.sum-cell{
  flex:1 1 16%;min-width:110px;padding:6px 8px;border-right:1px solid var(--g200);border-bottom:1px solid var(--g200);
}
.sum-cell:last-child{border-right:none}
.sum-k{font-size:.5rem;letter-spacing:.14em;text-transform:uppercase;color:var(--g500);font-weight:800}
.sum-v{font-size:.78rem;font-weight:800;color:var(--ink);margin-top:4px;line-height:1.25}
.sum-s{font-size:.58rem;color:var(--g500);margin-top:2px}
.data-block{margin-bottom:8px;border:1.5px solid var(--g300);break-inside:avoid;page-break-inside:avoid}
.data-block-head{
  background:var(--g50);border-bottom:1px solid var(--g200);padding:5px 8px;
  font-size:.56rem;letter-spacing:.14em;text-transform:uppercase;font-weight:800;color:var(--ink2);
}
.data-block-body{padding:6px 8px}
.data-flex{display:flex;flex-wrap:wrap;gap:0}
.data-row{
  display:flex;align-items:flex-start;gap:6px;padding:4px 0;border-bottom:1px solid var(--g100);
  flex:1 1 50%;min-width:240px;
}
.data-row.full{flex-basis:100%;min-width:100%}
.data-row:last-child{border-bottom:none}
.data-key{
  min-width:96px;font-size:.58rem;letter-spacing:.06em;text-transform:uppercase;color:var(--g500);font-weight:800;
}
.data-val{
  flex:1;font-size:.7rem;color:var(--ink);font-weight:700;line-height:1.35;word-break:break-word;
}
.data-val.muted{color:var(--g500);font-weight:600}
.mini-grid{display:flex;flex-wrap:wrap;gap:6px}
.mini-box{
  flex:1 1 22%;min-width:130px;border:1px solid var(--g200);padding:6px;background:#fff;
}
.mini-box .k{font-size:.48rem;letter-spacing:.12em;text-transform:uppercase;color:var(--g500);font-weight:800}
.mini-box .v{font-size:.74rem;font-weight:800;color:var(--ink);margin-top:4px}
.mini-box .s{font-size:.56rem;color:var(--g500);margin-top:2px}
.image-strip{display:flex;flex-wrap:wrap;gap:12px;padding:8px 0}
.image-tile{text-align:center;border:1px solid var(--g200);padding:4px;border-radius:6px;background:#fff;}
.image-tile img{width:110px;height:110px;object-fit:cover;border-radius:4px;display:block}
.image-cap{font-size:.65rem;font-weight:600;color:var(--g600);margin-top:6px}
.customer-brief{
  display:flex;flex-wrap:wrap;gap:0;border:1.5px solid var(--g300);margin-bottom:8px;
}
.cust-cell{
  flex:1 1 25%;min-width:140px;padding:6px 8px;border-right:1px solid var(--g200);border-bottom:1px solid var(--g200);background:#fff;
}
.cust-cell:last-child{border-right:none}
.cust-k{font-size:.5rem;letter-spacing:.14em;text-transform:uppercase;color:var(--g500);font-weight:800}
.cust-v{font-size:.76rem;font-weight:800;color:var(--ink);margin-top:4px;line-height:1.25}
.cust-s{font-size:.56rem;color:var(--g500);margin-top:2px}

/* ── Info grid ── */
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:0 12px}
.info-row{display:flex;gap:5px;align-items:baseline;padding:2.5px 0;border-bottom:1px solid var(--g100);font-size:.7rem}
.info-row:last-child{border-bottom:none}
.lbl{color:var(--g500);font-size:.61rem;font-weight:500;min-width:90px;flex-shrink:0}
.val{color:var(--g800);font-weight:600;flex:1;word-break:break-word}
.val.green{color:var(--green)}.val.amber{color:var(--amber-d)}
.compact-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
.compact-chip{
  border:1px solid var(--g200);border-radius:8px;background:var(--g50);padding:8px;
}
.compact-chip .k{font-size:.53rem;letter-spacing:.12em;text-transform:uppercase;color:var(--g500);font-weight:800}
.compact-chip .v{font-size:.82rem;font-weight:800;color:var(--ink);margin-top:4px}

/* ── Property images ── */
.prop-imgs{display:flex;gap:7px;flex-wrap:wrap;padding-top:3px}
.prop-img-wrap{text-align:center}
.prop-img-wrap img{width:92px;height:92px;object-fit:cover;border-radius:8px;border:1.5px solid var(--g200);display:block}
.prop-img-cap{font-size:.56rem;color:var(--g500);margin-top:2px}

/* ── Deal box ── */
.deal-box{border-radius:4px;padding:7px 9px}
.deal-ok{background:var(--green-lt);border:1.5px solid var(--green-bd)}
.deal-bad{background:var(--red-lt);border:1.5px solid #fca5a5}
.deal-warn{background:var(--amber-lt);border:1.5px solid var(--amber-bd)}

/* ════════════════════════════════════════════════════
   ROOM CARDS
════════════════════════════════════════════════════ */
.room-list{display:flex;flex-direction:column;gap:6px}
.room-card{width:100%;border:1px solid var(--g300);border-radius:0;overflow:hidden;break-inside:avoid;page-break-inside:avoid;background:#fff}
.room-card.wide{grid-column:1/-1}

/* Card header */
.rc-head{background:#fff;color:var(--ink);padding:5px 8px;display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;border-bottom:2px solid var(--ink)}
.rc-head-left{display:flex;align-items:center;gap:7px;flex:1;min-width:0}
.rc-num{width:20px;height:20px;border-radius:0;background:var(--ink);color:#fff;display:flex;align-items:center;justify-content:center;font-size:.58rem;font-weight:800;flex-shrink:0}
.rc-name{font-size:.76rem;font-weight:800;color:var(--ink)}
.rc-brand{font-size:.58rem;color:var(--g500);font-weight:700;margin-top:1px}
.rc-head-right{display:flex;gap:4px;flex-wrap:wrap;align-items:center;flex-shrink:0}

/* Pills */
.surf-pill{font-size:.56rem;font-weight:800;padding:2px 7px;border-radius:0;color:var(--ink);border:1px solid var(--g300);background:#fff}
.surf-pill.floor{background:#ecfdf5;border-color:#86efac;color:#166534}.surf-pill.wall{background:#eff6ff;border-color:#93c5fd;color:#1d4ed8}
.surf-pill.both{background:#f5f3ff;border-color:#c4b5fd;color:#6d28d9}.surf-pill.bath{background:#faf5ff;border-color:#d8b4fe;color:#7c3aed}
.surf-pill.sqft{background:var(--g50)}
.surf-pill.qty{background:#fff7ed;border-color:#fed7aa;color:#9a3412}
.surf-pill.est{background:#f5f3ff;border-color:#c4b5fd;color:#6d28d9}

/* Card body */
.rc-body{padding:6px;background:#fff}
.rc-grid{display:flex;flex-wrap:wrap;gap:6px;align-items:flex-start}
.rc-main,.rc-side{display:flex;flex-direction:column;gap:6px}
.rc-main{flex:1 1 58%;min-width:0}
.rc-side{flex:1 1 34%;min-width:240px}
.detail-panel{
  border:1px solid var(--g200);border-radius:0;background:#fff;padding:6px;
}
.panel-title{font-size:.52rem;letter-spacing:.16em;text-transform:uppercase;font-weight:800;color:var(--g600);margin-bottom:6px;padding-bottom:4px;border-bottom:1px solid var(--g200)}
.facts-grid{display:flex;flex-wrap:wrap;gap:4px}
.fact{
  background:var(--g50);border:1px solid var(--g200);border-radius:0;padding:6px;flex:1 1 calc(33.333% - 4px);min-width:110px;
}
.fact .fk{font-size:.5rem;letter-spacing:.1em;text-transform:uppercase;color:var(--g500);font-weight:800}
.fact .fv{font-size:.68rem;color:var(--ink);font-weight:800;margin-top:3px;line-height:1.3}

/* Tile brand box */
.tile-brand-box{background:#fff;border:1px solid var(--brand-bd);border-radius:0;padding:6px 8px;margin-bottom:6px;display:flex;flex-wrap:wrap;align-items:center;gap:6px}
.tb-label{font-size:.57rem;text-transform:uppercase;letter-spacing:.1em;font-weight:700;color:var(--amber-d);flex-basis:100%;margin-bottom:2px}
.tb-company-badge{display:inline-flex;align-items:center;gap:3px;background:#fff7ed;color:#9a3412;padding:2px 8px;border:1px solid #fed7aa;border-radius:0;font-size:.62rem;font-weight:800}
.tb-design-badge{display:inline-flex;align-items:center;gap:3px;background:var(--g50);color:var(--ink2);padding:2px 8px;border:1px solid var(--g200);border-radius:0;font-size:.62rem;font-weight:700}
.tb-sep{font-size:.65rem;color:var(--amber-d);font-weight:700}

/* Dimension strip */
.dim-strip{display:flex;gap:6px;align-items:center;flex-wrap:wrap;background:#fff;border:1px solid var(--g200);border-radius:0;padding:4px 8px;margin-bottom:6px;font-size:.62rem;color:var(--g600)}
.dim-strip span{display:flex;align-items:center;gap:3px}
.dim-val{font-weight:700;color:var(--ink);font-size:.68rem}
.dim-sep{color:var(--g300)}

/* Tile calc table */
.calc-tbl{width:100%;border-collapse:collapse;font-size:.68rem;margin-bottom:5px}
.calc-tbl .surf-row td{padding:3.5px 7px;font-size:.61rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#fff}
.calc-tbl .surf-row.floor-row td{background:var(--floor)}
.calc-tbl .surf-row.wall-row td{background:var(--wall)}
.calc-tbl .lh-row td{padding:2.5px 7px;font-size:.58rem;font-weight:600;letter-spacing:.07em;text-transform:uppercase;color:var(--g400);background:var(--g100)}
.calc-tbl .data-row td{padding:4px 7px;border-bottom:1px solid var(--g100);color:var(--g700)}
.calc-tbl .data-row:last-child td{border-bottom:none}
.calc-tbl .data-row:nth-child(even) td{background:var(--g50)}
.calc-tbl tfoot td{padding:4px 7px;font-weight:700;background:var(--amber-d);color:#fff;font-size:.68rem}
.tnum{text-align:right;font-weight:700;font-size:.7rem;color:var(--ink)}
.tbox{text-align:right;font-weight:800;font-size:.72rem}
.tbox-floor{color:var(--floor)}.tbox-wall{color:var(--wall)}
.calc-microgrid{display:flex;flex-wrap:wrap;gap:4px;margin-top:6px}
.calc-micro{
  background:#fff;border:1px solid var(--g200);border-radius:0;padding:6px;text-align:left;flex:1 1 calc(25% - 4px);min-width:110px;
}
.calc-micro .mk{font-size:.5rem;letter-spacing:.1em;text-transform:uppercase;color:var(--g500);font-weight:800}
.calc-micro .mv{font-size:.74rem;font-weight:800;color:var(--ink);margin-top:4px}
.surface-card-grid{display:flex;flex-direction:column;gap:4px}
.surface-card{
  border:1px solid var(--g200);border-radius:0;background:#fff;overflow:hidden;
}
.surface-card.floor{border-color:#86efac}
.surface-card.wall{border-color:#93c5fd}
.surface-head{
  padding:5px 8px;color:var(--ink);display:flex;justify-content:space-between;align-items:flex-start;gap:8px;background:var(--g50);border-bottom:1px solid var(--g200)
}
.surface-card.floor .surface-head{background:#ecfdf5}
.surface-card.wall .surface-head{background:#eff6ff}
.surface-title{font-size:.64rem;font-weight:800;letter-spacing:.05em}
.surface-sub{font-size:.56rem;color:var(--g600);margin-top:2px;line-height:1.35}
.surface-box{
  background:#fff;border:1px solid var(--g300);border-radius:0;
  padding:3px 8px;font-size:.6rem;font-weight:800;white-space:nowrap
}
.surface-body{padding:6px}
.surface-metrics{display:flex;flex-wrap:wrap;gap:4px}
.surface-metric{
  border:1px solid var(--g200);border-radius:0;background:#fff;padding:5px;flex:1 1 calc(16.666% - 4px);min-width:92px
}
.surface-metric .k{font-size:.49rem;letter-spacing:.1em;text-transform:uppercase;color:var(--g500);font-weight:800}
.surface-metric .v{font-size:.7rem;font-weight:800;color:var(--ink);margin-top:4px}
.surface-formula{
  margin-top:5px;border-top:1px dashed var(--g300);padding:5px 0 0;
  font-size:.58rem;color:var(--g600);background:#fff
}

/* Bath table */
.bath-tbl{width:100%;border-collapse:collapse;font-size:.65rem;margin-top:6px}
.bath-tbl th{background:var(--bath);color:#fff;padding:3.5px 7px;font-size:.58rem;letter-spacing:.07em;text-transform:uppercase;font-weight:600}
.bath-tbl td{padding:3.5px 7px;border-bottom:1px solid var(--g100);color:var(--g700)}
.bath-tbl tbody tr:nth-child(even) td{background:var(--bath-lt)}
.bath-tbl .r{text-align:right;font-weight:600}
.bath-tbl .bx{text-align:right;font-weight:800;color:var(--bath)}
.bath-tbl tfoot td{font-weight:700;background:var(--bath);color:#fff;padding:3.5px 7px}
.part-chip{display:inline-block;padding:1px 6px;border-radius:3px;font-size:.59rem;font-weight:700;color:#fff}
.part-chip.light{background:#78716c}.part-chip.high{background:var(--amber)}.part-chip.dark{background:#44403c}
.bath-card-grid{display:flex;flex-wrap:wrap;gap:4px;margin-top:6px}
.bath-micro{
  border:1px solid #ddd6fe;border-radius:0;background:#fff;padding:6px;flex:1 1 calc(33.333% - 4px);min-width:110px;
}
.bath-micro .mk{font-size:.5rem;letter-spacing:.12em;text-transform:uppercase;color:#7c3aed;font-weight:800}
.bath-micro .mv{font-size:.72rem;font-weight:800;color:var(--ink);margin-top:4px}
.bath-zone-grid{display:flex;flex-wrap:wrap;gap:4px}
.bath-zone-card{
  border:1px solid #ddd6fe;border-radius:0;background:#fff;padding:6px;flex:1 1 calc(33.333% - 4px);min-width:135px
}
.bath-zone-top{display:flex;align-items:center;justify-content:space-between;gap:6px;margin-bottom:7px}
.bath-zone-name{font-size:.62rem;font-weight:800;color:#5b21b6;letter-spacing:.04em}
.bath-zone-box{font-size:.56rem;font-weight:800;color:#7c3aed;background:#fff;border:1px solid #ddd6fe;border-radius:0;padding:2px 7px}
.bath-zone-metrics{display:flex;flex-wrap:wrap;gap:4px}
.bath-zone-metric{background:#fff;border:1px solid #e9d5ff;border-radius:0;padding:5px;flex:1 1 calc(50% - 4px);min-width:78px}
.bath-zone-metric .k{font-size:.47rem;letter-spacing:.1em;text-transform:uppercase;color:#7c3aed;font-weight:800}
.bath-zone-metric .v{font-size:.68rem;font-weight:800;color:var(--ink);margin-top:4px}

/* Area images */
.area-img-lbl{font-size:.57rem;color:#fff;letter-spacing:.06em;text-transform:uppercase;font-weight:700;background:var(--g600);padding:2px 7px;display:inline-block;border-radius:3px;margin-top:7px;margin-bottom:3px}
.area-img-row{display:flex;gap:5px;flex-wrap:wrap}
.area-img-row img{width:64px;height:64px;object-fit:cover;border-radius:0;border:1px solid var(--g200);display:block}

/* ════════════════════════════════════════════════════
   ESTIMATE PRICING BLOCK — inside each room card
════════════════════════════════════════════════════ */
.est-room-block{
  margin-top:8px;
  border:2px solid var(--purple-bd);
  border-radius:6px;
  overflow:hidden;
}
/* Purple header bar */
.erb-head{
  background:var(--purple);color:#fff;
  padding:5px 10px;
  display:flex;align-items:center;justify-content:space-between;gap:8px;
  font-size:.6rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;
}
.erb-title{display:flex;align-items:center;gap:5px}
.erb-tsize{
  font-size:.58rem;background:rgba(255,255,255,.18);
  border-radius:4px;padding:1px 6px;font-weight:600;
  letter-spacing:0;text-transform:none;
}
.erb-meta-row{
  display:flex;flex-wrap:wrap;gap:10px;padding:8px 10px;
  background:rgba(167,139,250,.12);color:#2f2d41;font-size:.72rem;
}
.erb-meta-label{font-weight:700;color:#6d28d9}
.erb-status-ok{background:rgba(255,255,255,.2);border-radius:3px;padding:1px 6px;font-size:.58rem}
.erb-status-ex{background:rgba(0,0,0,.3);border-radius:3px;padding:1px 6px;font-size:.58rem}

/* Estimate price grid */
.est-price-grid{
  display:grid;
  grid-template-columns:.9fr 1fr 1fr .75fr 1fr 1fr;
  border-top:none;
}
.epc{
  padding:7px 8px;text-align:center;
  border-right:1px solid var(--purple-bd);
}
.epc:last-child{border-right:none}
.epc.mrp-bg{background:var(--mrp-lt)}
.epc.mop-bg{background:var(--mop-lt)}
.epc.disc-bg{background:var(--disc-lt)}
.epc-lbl{
  font-size:.54rem;text-transform:uppercase;letter-spacing:.07em;
  font-weight:700;margin-bottom:3px;
}
.epc-lbl.mrp-l{color:var(--mrp)}
.epc-lbl.mop-l{color:var(--mop)}
.epc-lbl.disc-l{color:var(--disc)}
.epc-val{
  font-size:.8rem;font-weight:800;
  font-family:'Courier New',monospace;
  line-height:1;
  /* IMPORTANT: no text-decoration here */
  text-decoration:none !important;
}
.epc-val.mrp-v{color:var(--mrp)}
.epc-val.mop-v{color:var(--mop)}
.epc-val.disc-v{color:var(--disc)}

/* Offer row */
.est-offer-row{
  padding:5px 10px;
  background:var(--purple-lt);
  border-top:1px solid var(--purple-bd);
  display:flex;align-items:center;gap:7px;
  font-size:.68rem;
}
.est-offer-lbl{font-size:.57rem;text-transform:uppercase;letter-spacing:.07em;font-weight:700;color:var(--purple);flex-shrink:0}
.est-offer-val{color:var(--purple);font-weight:600}

/* No price placeholder */
.est-no-price{
  padding:7px 10px;font-size:.67rem;color:var(--g400);
  background:var(--purple-lt);text-align:center;font-style:italic;
}

/* ════════════════════════════════════════════════════
   GRAND ESTIMATE TOTALS STRIP (after all rooms)
   — 3 cells: Grand MRP | Grand MOP | Saved Price
   — text-decoration:none enforced everywhere
════════════════════════════════════════════════════ */
.grand-estimate-strip{
  margin-top:10px;
  border:2.5px solid var(--purple);
  border-radius:8px;
  overflow:hidden;
  break-inside:avoid;page-break-inside:avoid;
  /* Explicitly kill any inherited strikethrough */
  text-decoration:none !important;
}
.grand-estimate-head{
  background:var(--purple);color:#fff;padding:6px 10px;
  font-size:.62rem;font-weight:800;letter-spacing:.09em;text-transform:uppercase;
}
.grand-estimate-grid{display:grid;grid-template-columns:repeat(5,1fr);background:#fff}
.grand-estimate-cell{padding:8px 10px;border-right:1px solid var(--purple-bd);text-align:center}
.grand-estimate-cell:last-child{border-right:none}
.grand-estimate-cell .gev{font-family:'Courier New',monospace;font-size:.82rem;font-weight:900;text-decoration:none!important}
.grand-estimate-cell .gel{font-size:.53rem;letter-spacing:.08em;text-transform:uppercase;font-weight:800;margin-top:3px;color:var(--g500)}
.grand-estimate-cell.mrp .gev{color:var(--mrp)}
.grand-estimate-cell.mop .gev{color:var(--mop)}
.grand-estimate-cell.save .gev{color:var(--save)}
.grand-estimate-cell.offer .gev{color:var(--purple)}
/* ════════════════════════════════════════════════════
   VISUALIZER SNAPSHOTS
════════════════════════════════════════════════════ */
.viz-head{background:var(--bath);color:#fff;padding:5px 11px;border-radius:4px 4px 0 0;display:flex;align-items:center;justify-content:space-between}
.viz-head-title{font-size:.68rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase}
.viz-head-badge{font-size:.61rem;color:rgba(255,255,255,.85);background:rgba(255,255,255,.16);border-radius:8px;padding:2px 8px;font-weight:600}
.viz-body{border:1.5px solid var(--bath);border-top:none;border-radius:0 0 4px 4px;padding:8px}
.viz-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.viz-item{display:flex;flex-direction:column;break-inside:avoid;page-break-inside:avoid;border:1.5px solid var(--g200);border-radius:4px;overflow:hidden}
.viz-img-wrap{width:100%;aspect-ratio:4/3;background:var(--g100);overflow:hidden}
.viz-img-wrap img{width:100%;height:100%;object-fit:cover;display:block}
.viz-meta{display:flex;justify-content:space-between;align-items:center;padding:4px 7px;background:var(--ink2);color:#fff}
.viz-meta-date{font-size:.6rem;color:rgba(255,255,255,.65)}
.viz-meta-owner{font-size:.61rem;color:var(--amber-bd);font-weight:700}
.viz-badge{font-size:.58rem;font-weight:800;color:#fff;background:var(--bath);width:17px;height:17px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0}
.viz-item.full-width{grid-column:1/-1}
.viz-item.full-width .viz-img-wrap{aspect-ratio:16/7}

/* Signature + footer */
.sig-strip{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:16px;padding-top:8px;border-top:1.5px dashed var(--g300);break-inside:avoid;page-break-inside:avoid}
.sig-box{text-align:center}
.sig-line{height:1px;background:var(--g400);margin-bottom:4px}
.sig-lbl{font-size:.58rem;color:var(--g500)}
.print-footer{margin-top:8px;padding-top:5px;border-top:1px solid var(--g200);display:flex;justify-content:space-between;align-items:center;font-size:.57rem;color:var(--g400)}
.page-no{
  position:absolute;right:8mm;bottom:5mm;font-size:.58rem;color:var(--g500);font-weight:700;letter-spacing:.08em;text-transform:uppercase;
}
.page-no::after{content:attr(data-page-label);font-variant-numeric:tabular-nums}

/* Simple calc cards */
.calc-card-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(255px,1fr));gap:15px;padding:5px}
.calc-card{
  border:2px solid var(--g300);border-radius:12px;background:#fff;overflow:hidden;
  box-shadow:0 4px 12px rgba(15,23,42,.08);
  break-inside:avoid;page-break-inside:avoid;margin-bottom:12px;
}
.calc-card-top{
  display:flex;align-items:stretch;gap:0;border-bottom:2px solid var(--g200);
  background:linear-gradient(180deg,#fff,var(--g50));
}
.calc-card-image{
  width:90px;min-width:90px;min-height:90px;background:var(--g50);border-right:2px solid var(--g200);
}
.calc-card-image img{width:100%;height:100%;object-fit:cover;display:block}
.calc-card-main{flex:1;padding:12px 14px}
.calc-card-name{font-size:.9rem;font-weight:900;color:var(--ink);line-height:1.2;margin-bottom:4px}
.calc-card-brand{font-size:.7rem;color:var(--g600);line-height:1.35}
.calc-card-pills{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.calc-pill{
  display:inline-flex;align-items:center;padding:3px 9px;border:1px solid var(--g300);
  font-size:.65rem;font-weight:800;color:var(--ink);background:#fff;border-radius:999px;
}
.calc-card-body{padding:8px 9px 9px}
.tile-lines{border-top:1px solid var(--g200);padding:10px 14px;display:flex;justify-content:flex-start;gap:30px;background:var(--g50)}
.tile-line{display:flex;flex-direction:column;align-items:flex-start;gap:3px;font-size:.7rem;line-height:1.3}
.tile-line .lk{color:var(--g500);font-weight:700;text-transform:uppercase;letter-spacing:.08em;font-size:.55rem}
.tile-line .lv{color:var(--ink);font-weight:800;text-align:left;font-size:.85rem}
.area-img-grid{
  display:flex;flex-wrap:wrap;gap:12px;padding:14px;justify-content:flex-start;background:#fff;border-top:2px solid var(--g200);
}
.area-img-item{
  position:relative;width:115px;height:115px;border-radius:8px;overflow:hidden;border:2px solid var(--g300);background:#fff;box-shadow:0 3px 8px rgba(0,0,0,0.1);
}
.area-img-item img{width:100%;height:100%;object-fit:cover;display:block}
.area-img-overlay{
  position:absolute;left:0;right:0;bottom:0;padding:6px 8px;
  display:flex;justify-content:center;align-items:center;
  background:linear-gradient(180deg,transparent,rgba(15,23,42,.85));
  color:#fff;font-size:.65rem;font-weight:800;letter-spacing:1px;
}

@media print{
  .room-list{display:flex}
  .customer-brief{display:table;width:100%;table-layout:fixed;border-collapse:collapse}
  .cust-cell{display:table-cell;vertical-align:top;min-width:0;width:25%}
  .calc-card-grid{display:block}
  .calc-card{display:block;width:100%;min-width:0;margin:0 0 4mm;border-radius:10px}
  .calc-card-top{display:table;width:100%}
  .calc-card-image,.calc-card-main{display:table-cell;vertical-align:top}
  .calc-card-main{width:auto}
  .grand-estimate-grid{display:table;width:100%;table-layout:fixed}
  .grand-estimate-cell{display:table-cell;vertical-align:top;width:20%}
}
@media screen and (max-width:980px), print and (max-width:760px){
  .compact-grid{grid-template-columns:1fr}
  .data-flex,.mini-grid,.rc-grid,.facts-grid,.surface-metrics,.bath-zone-metrics,.bath-zone-grid,.calc-microgrid,.bath-card-grid{display:flex;flex-direction:column}
  .surface-card-grid,.room-list{display:flex;flex-direction:column}
  .calc-card-grid{grid-template-columns:1fr}
  .rc-main,.rc-side,.fact,.surface-metric,.bath-zone-card,.bath-zone-metric,.calc-micro,.bath-micro,.data-row,.mini-box,.sum-cell,.cust-cell,.calc-card{flex-basis:100%;min-width:0}
}

/* WYSIWYG print: keep the printed sheet aligned with the on-screen A4 preview. */
@media print{
  @page{size:210mm 297mm;margin:0}
  html,body{width:210mm;background:#fff!important;margin:0!important;padding:0!important}
  body{font-size:13px}
  .toolbar{display:none!important}
  .sheet{
    width:210mm!important;
    min-height:297mm!important;
    margin:0!important;
    padding:10mm 10mm 16mm!important;
    background:#fff!important;
    box-shadow:none!important;
    border-radius:0!important;
    outline:none!important;
    position:relative!important;
  }
  .sheet.new-page{break-before:page;page-break-before:always}
  .sheet.new-page::before{display:none!important}
  .lh{display:flex!important;align-items:center!important;justify-content:space-between!important}
  .lh-brand{display:flex!important;align-items:center!important}
  .lh-meta{text-align:right!important;display:block!important}
  .mini-lh{display:flex!important;align-items:center!important;justify-content:space-between!important}
  .mini-lh-left{display:flex!important;align-items:center!important}
  .doc-title-row,.print-footer{display:flex!important;align-items:center!important;justify-content:space-between!important}
  .customer-brief,.summary-strip{display:flex!important;flex-wrap:wrap!important}
  .cust-cell{display:block!important;flex:1 1 25%!important;min-width:140px!important;width:auto!important}
  .data-flex{display:flex!important;flex-wrap:wrap!important}
  .data-row{display:flex!important;flex:1 1 50%!important;width:auto!important;min-width:240px!important}
  .data-row.full{flex-basis:100%!important;min-width:100%!important}
  .data-key,.data-val{display:block!important;width:auto!important;min-width:0!important}
  .image-strip,.area-img-grid{display:flex!important;flex-wrap:wrap!important}
  .calc-card-grid{display:grid!important;grid-template-columns:repeat(auto-fit,minmax(255px,1fr))!important;gap:15px!important;padding:5px!important}
  .calc-card{display:block!important;width:auto!important;margin-bottom:12px!important;border-radius:12px!important}
  .calc-card-top{display:flex!important;align-items:stretch!important;width:auto!important}
  .calc-card-image,.calc-card-main{display:block!important}
  .tile-lines{display:flex!important;justify-content:flex-start!important;gap:30px!important}
  .est-price-grid{display:grid!important;grid-template-columns:.9fr 1fr 1fr .75fr 1fr 1fr!important}
  .grand-estimate-grid{display:grid!important;grid-template-columns:repeat(5,1fr)!important}
  .grand-estimate-cell{display:block!important;width:auto!important}
  .sig-strip{display:grid!important;grid-template-columns:repeat(3,1fr)!important}
  .sig-box{display:block!important}
  .viz-grid{display:grid!important;grid-template-columns:1fr 1fr!important}
  .viz-item.full-width{grid-column:1/-1!important}
  .page-no{right:8mm!important;bottom:5mm!important}
}
</style>
</head>
<body>

<!-- TOOLBAR -->
<div class="toolbar">
  <h1>🖨 Print — <?= h($s['owner_name']) ?> · #<?= $subId ?><?= $showEstimate ? ' · 📋 With Estimate' : '' ?></h1>
  <a href="<?= h($backUrl) ?>" class="tb-btn ghost">← Back</a>
  <button class="tb-btn primary" onclick="window.print()">
    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
      <polyline points="6 9 6 2 18 2 18 9"/>
      <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
      <rect x="6" y="14" width="12" height="8"/>
    </svg>
    Print / Save PDF
  </button>
</div>

<!-- ════════════════════════════════════════════════════
     SHEET 1
════════════════════════════════════════════════════ -->
<div class="sheet">

  <!-- LETTERHEAD -->
  <div class="lh no-break">
    <div class="lh-brand">
      <div class="lh-logo"><img src="<?= APP_URL ?>/assets/logo.png" alt="Logo"></div>
      <div>
        <div class="lh-company">Aandavar Enterprises</div>
        <div class="lh-tagline">The Tiles Expert</div>
      </div>
    </div>
    <div class="lh-meta">
      <strong>Printed:</strong> <?= $printDate ?><br>
      <strong>Exec:</strong> <?= h($s['sub_name']) ?><br>
      <strong>Zone:</strong> <?= h($s['dir_code']) ?> › <?= h($s['zone']) ?>
      <?php if ($showEstimate && $estimateRow): ?>
      <br><span style="background:var(--purple);color:#fff;border-radius:3px;padding:1px 6px;font-size:.58rem;font-weight:700">📋 Estimate #<?= (int)$estimateRow['id'] ?></span>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($s['order_completed']): ?>
  <div class="completed-banner">✅ ORDER COMPLETED<?= $s['purchase_date'] ? ' — '.date('d M Y', strtotime($s['purchase_date'])) : '' ?></div>
  <?php endif; ?>

  <div class="doc-title-row">
    <div>
      <div class="doc-title">Customer Submission Report<?= $showEstimate ? ' &amp; Estimate' : '' ?></div>
      <div class="doc-id">Submission #<?= $subId ?> · <?= $dispTime ? date('d M Y', strtotime($dispTime)) : '—' ?></div>
    </div>
    <span class="status-badge <?= h($s['status']) ?>"><?= statusLabel($s['status']) ?></span>
  </div>

  <div class="customer-brief no-break">
    <div class="cust-cell"><div class="cust-k">Customer</div><div class="cust-v"><?= h($s['owner_name']) ?></div><div class="cust-s"><?= h($s['owner_number']) ?></div></div>
    <div class="cust-cell"><div class="cust-k">Location</div><div class="cust-v"><?= h($s['village_city_town'] ?: '—') ?></div><div class="cust-s"><?= h($s['street'] ?: 'No street added') ?></div></div>
    <div class="cust-cell"><div class="cust-k">Purchase Date</div><div class="cust-v"><?= !empty($s['purchase_date']) ? date('d M Y', strtotime($s['purchase_date'])) : '—' ?></div><div class="cust-s"><?= statusLabel($s['status']) ?></div></div>
    <div class="cust-cell"><div class="cust-k">Executive</div><div class="cust-v"><?= h($s['sub_name']) ?></div><div class="cust-s"><?= $dispTime ? date('d M Y, h:i A', strtotime($dispTime)) : '—' ?></div></div>
  </div>

  <?php if (!empty($s['landmark_picture']) || !empty($s['additional_picture']) || !empty($s['tile_image'])): ?>
  <div class="data-block no-break">
    <div class="data-block-head">Property Images</div>
    <div class="data-block-body">
      <div class="image-strip">
        <?php if (!empty($s['landmark_picture'])): ?><div class="image-tile"><img src="<?= h(imgUrl($s['landmark_picture'])) ?>" alt="Landmark" onerror="this.style.display='none'"><div class="image-cap">Landmark</div></div><?php endif; ?>
        <?php if (!empty($s['additional_picture'])): ?><div class="image-tile"><img src="<?= h(imgUrl($s['additional_picture'])) ?>" alt="Additional" onerror="this.style.display='none'"><div class="image-cap">Additional</div></div><?php endif; ?>
        <?php if (!empty($s['tile_image'])): ?><div class="image-tile"><img src="<?= h(imgUrl($s['tile_image'])) ?>" alt="Tile" onerror="this.style.display='none'"><div class="image-cap">Tile</div></div><?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($detail && ($detail['deal_result'] || $detail['detail_description'] || $detail['vendor_name'] || $detail['vendor_details'] || $detail['other_reason'])): ?>
  <div class="data-block no-break">
    <div class="data-block-head">Deal Information</div>
    <div class="data-block-body">
      <div class="data-flex">
        <?php if ($detail['deal_result']): ?><div class="data-row"><div class="data-key">Result</div><div class="data-val"><?= dealLabel($detail['deal_result']) ?></div></div><?php endif; ?>
        <?php if (!empty($detail['vendor_name'])): ?><div class="data-row"><div class="data-key">Vendor</div><div class="data-val"><?= h($detail['vendor_name']) ?></div></div><?php endif; ?>
        <?php if (!empty($detail['vendor_details'])): ?><div class="data-row full"><div class="data-key">Vendor Details</div><div class="data-val"><?= h($detail['vendor_details']) ?></div></div><?php endif; ?>
        <?php if (!empty($detail['other_reason'])): ?><div class="data-row full"><div class="data-key">Other Reason</div><div class="data-val"><?= h($detail['other_reason']) ?></div></div><?php endif; ?>
        <?php if (!empty($detail['detail_description'])): ?><div class="data-row full"><div class="data-key">Notes</div><div class="data-val"><?= nl2br(h($detail['detail_description'])) ?></div></div><?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- 4. AREAS + TILE CALC + ESTIMATE PRICING (inline per room) -->
  <?php if ($areas):
  ?>
  <div class="sec">
    <div class="sec-head">
      📐 Areas &amp; Tile Calculations<?= $showEstimate ? ' &nbsp;·&nbsp; 📋 Estimate Pricing' : '' ?>
    </div>
    <div class="sec-body" style="padding:7px 0 0;border-top:none">
      <div class="calc-card-grid">
      <?php
        $printGrandMRP = 0.0;
        $printGrandMOP = 0.0;
        $printGrandAfterMRP = 0.0;
        $printGrandAfterMOP = 0.0;
        $printEstimateCount = 0;
        $printHasOffer = false;
      ?>

      <?php foreach ($areas as $aIdx => $a):
        $L       = (float)($a['area_length']  ?? 0);
        $B       = (float)($a['area_breadth'] ?? 0);
        $H       = (float)($a['area_height']  ?? 0);
        $sqft    = (float)($a['floor_sqft'] ?? $a['total_sqft'] ?? $a['area_sqft'] ?? 0);
        $surface = $a['tile_surface'] ?? 'floor';
        $isBath  = !empty($a['is_bathroom']);
        $aImgs   = $areaImgs[(int)$a['id']] ?? [];
        $coverImg = $aImgs[0]['image_filename'] ?? ($s['tile_image'] ?? '');
        $aCount  = (int)($a['area_count'] ?? 1);
        $td       = parseTileOpts($a);
        $company  = $td['company'];
        $design   = $td['design'];
        $brand    = $td['brand_full'];

        $fKey   = $a['floor_tile_size_key'] ?? $a['tile_size_key'] ?? '2|2|4';
        $fWaste = (int)($a['floor_wastage_pct'] ?? $a['wastage_pct'] ?? 10);
        $wKey   = $a['wall_tile_size_key']  ?? '1|1|8';
        $wWaste = (int)($a['wall_wastage_pct']  ?? 10);
        $bKey   = $a['bath_tile_size_key']  ?? $a['bath_tile_size'] ?? '1|1|8';
        $bWaste = (int)($a['bath_wastage_pct']  ?? 10);
        $stepCount = (int)($a['step_count'] ?? 0);
        $riserCount = (int)($a['riser_count'] ?? 0);
        $landingL = (float)($a['riser_landing_length'] ?? 0);
        $landingB = (float)($a['riser_landing_breadth'] ?? 0);
        $landingSqft = (float)($a['riser_landing_sqft'] ?? 0);
        $landingTiles = (int)($a['riser_landing_tiles'] ?? 0);
        // Tile calc summary for this room
        $totBoxes = 0;
        if ($surface === 'floor' || $surface === 'both') {
          $fCustom = $a['floor_tile_custom'] ?? (
            isset($a['floor_tile_custom_w']) ? ['w'=>$a['floor_tile_custom_w'],'h'=>$a['floor_tile_custom_h'],'ppb'=>$a['floor_tile_custom_ppb']] : null
          );
          $fRes = $sqft > 0 ? calcBoxes($sqft, $fKey, $fWaste, $fCustom) : null;
            if ($fRes) { $totBoxes += $fRes['boxes']; }
        }
        if ($surface === 'wall' || $surface === 'both') {
            $wSqft = (float)($a['wall_sqft'] ?? $a['total_sqft'] ?? $a['area_sqft'] ?? 0);
          $wCustom = $a['wall_tile_custom'] ?? (
            isset($a['wall_tile_custom_w']) ? ['w'=>$a['wall_tile_custom_w'],'h'=>$a['wall_tile_custom_h'],'ppb'=>$a['wall_tile_custom_ppb']] : null
          );
          $wRes  = $wSqft > 0 ? calcBoxes($wSqft, $wKey, $wWaste, $wCustom) : null;
            if ($wRes) { $totBoxes += $wRes['boxes']; }
        }
        // Bath partitions
        $bTotBoxes = 0;
        if ($isBath) {
            foreach ([
                'Light'     => ['h' => (float)($a['bath_light_h'] ?? 0), 'cls' => 'light'],
                'Highlight' => ['h' => (float)($a['bath_high_h']  ?? 0), 'cls' => 'high'],
                'Dark'      => ['h' => (float)($a['bath_dark_h']  ?? 0), 'cls' => 'dark'],
            ] as $pN => $pD) {
                if (!$pD['h']) continue;
                $pArea = ($L && $B) ? round(2 * ($L + $B) * $pD['h'], 2) : 0;
                $pCustom = $a['bath_tile_custom'] ?? null;
                $pRes  = $pArea > 0 ? calcBoxes($pArea, $bKey, $bWaste, $pCustom) : null;
                if ($pRes) { $bTotBoxes += $pRes['boxes']; }
            }
        }

        $surfLabel = match($surface) {
            'wall' => 'Wall Only',
            'both' => 'Floor + Wall',
            'step' => 'Step',
            'riser' => 'Riser',
            'step_riser' => 'Step + Riser',
            default => 'Floor Only'
        };
        $surfCls   = match($surface) {
            'wall' => 'wall',
            'both' => 'both',
            'step' => 'floor',
            'riser' => 'wall',
            'step_riser' => 'both',
            default => 'floor'
        };
      ?>

        <div class="calc-card no-break">
          <div class="calc-card-top">
            <div class="calc-card-main">
              <div class="calc-card-name"><?= h($a['area_type'] ?: 'Room') ?></div>
              <div class="calc-card-brand"><?= $brand ? h($brand) : 'Brand not added' ?></div>
              <div class="calc-card-pills">
                <span class="calc-pill"><?= h($surfLabel) ?></span>
                <?php if ($aCount > 1): ?><span class="calc-pill">×<?= $aCount ?></span><?php endif; ?>
                <?php if ($isBath): ?><span class="calc-pill">Bath</span><?php endif; ?>
              </div>
            </div>
          </div>
            <div class="tile-lines">
              <div class="tile-line"><span class="lk">Sqft</span><span class="lv"><?= $sqft > 0 ? fmtNum($sqft,2).' ft²' : '—' ?></span></div>
              <div class="tile-line"><span class="lk">Tile Size</span><span class="lv"><?= h(customTileSizeLabel($fKey, $a['floor_tile_custom'] ?? null)) ?></span></div>
              <div class="tile-line"><span class="lk">Box Count</span><span class="lv"><?= fmtNum($totBoxes + $bTotBoxes) ?></span></div>
            </div>

            <?php if ($aImgs): ?>
            <div class="area-img-grid">
              <?php foreach ($aImgs as $aIdx => $img): ?>
              <div class="area-img-item">
                <img src="<?= h(imgUrl($img['image_filename'])) ?>" alt="Area image <?= $aIdx + 1 ?>" onerror="this.style.display='none'">
                <div class="area-img-overlay">
                  <span><?= $aIdx + 1 ?>/<?= count($aImgs) ?></span>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if ($showEstimate && $estimateRow):
              $areaEstimateRows = $estRoomsByArea[(int)$a['id']] ?? [];
              $areaEstimateRows = array_values(array_filter($areaEstimateRows, function($er) {
                  return (int)($er['include_in_print'] ?? 1) !== 0;
              }));
            ?>
              <?php foreach ($areaEstimateRows as $er):
                $baseBoxes = (int)($er['box_count'] ?? 0);
                $extraBox  = (int)($er['extra_box'] ?? 0);
                $boxes     = estTotalBoxes($baseBoxes, $extraBox);
                $mrpPB     = (float)($er['mrp_per_box'] ?? 0);
                $mopPB     = (float)($er['mop_per_box'] ?? 0);
                $mrpTotal  = round($boxes * $mrpPB, 2);
                $mopTotal  = round($boxes * $mopPB, 2);
                $discPct   = isset($er['discount_pct']) ? (float)$er['discount_pct'] : estDiscPct($mrpPB, $mopPB);
                $savings   = max(0, $mrpTotal - $mopTotal);
                $offerPct  = is_numeric($er['offer'] ?? null) ? (float)$er['offer'] : 0.0;
                $afterMRP  = estAfterOffer($mrpTotal, $offerPct);
                $afterMOP  = estAfterOffer($mopTotal, $offerPct);
                $tileLabel = estimateTileLabelForArea($er, $a);

                $printGrandMRP += $mrpTotal;
                $printGrandMOP += $mopTotal;
                $printGrandAfterMRP += $afterMRP;
                $printGrandAfterMOP += $afterMOP;
                $printEstimateCount++;
                if ($offerPct > 0) $printHasOffer = true;
              ?>
              <div class="est-room-block">
                <div class="erb-head">
                  <span class="erb-title">Estimate Pricing</span>
                  <span class="erb-tsize"><?= h(surfaceLabel((string)($er['surface'] ?? 'floor'))) ?> · <?= h($tileLabel) ?></span>
                </div>
                <div class="erb-meta-row">
                  <span><span class="erb-meta-label">Base:</span> <?= $baseBoxes ?> boxes</span>
                  <span><span class="erb-meta-label">Extra:</span> <?= $extraBox > 0 ? '+' : '' ?><?= $extraBox ?></span>
                  <span><span class="erb-meta-label">Total:</span> <?= $boxes ?> boxes</span>
                  <span><span class="erb-meta-label">Sqft:</span> <?= fmtNum($er['total_sqft'] ?? 0, 2) ?></span>
                </div>
                <div class="est-price-grid">
                  <div class="epc mrp-bg"><div class="epc-lbl mrp-l">MRP / Box</div><div class="epc-val mrp-v"><?= $mrpPB > 0 ? fmtINR($mrpPB) : '—' ?></div></div>
                  <div class="epc mrp-bg"><div class="epc-lbl mrp-l">MRP Total</div><div class="epc-val mrp-v"><?= $mrpTotal > 0 ? fmtINR($mrpTotal) : '—' ?></div></div>
                  <div class="epc disc-bg"><div class="epc-lbl disc-l">Disc %</div><div class="epc-val disc-v"><?= $discPct > 0 ? fmtNum($discPct, 2) . '%' : '—' ?></div></div>
                  <div class="epc mop-bg"><div class="epc-lbl mop-l">MOP / Box</div><div class="epc-val mop-v"><?= $mopPB > 0 ? fmtINR($mopPB) : '—' ?></div></div>
                  <div class="epc mop-bg"><div class="epc-lbl mop-l">MOP Total</div><div class="epc-val mop-v"><?= $mopTotal > 0 ? fmtINR($mopTotal) : '—' ?></div></div>
                  <div class="epc"><div class="epc-lbl" style="color:var(--save)">Savings</div><div class="epc-val" style="color:var(--save)"><?= $savings > 0 ? fmtINR($savings) : '—' ?></div></div>
                </div>
                <?php if ($offerPct > 0): ?>
                <div class="est-offer-row">
                  <span class="est-offer-lbl">Offer <?= fmtNum($offerPct, 1) ?>%</span>
                  <span class="est-offer-val">After MRP: <?= fmtINR($afterMRP) ?> · After MOP: <?= fmtINR($afterMOP) ?></span>
                </div>
                <?php endif; ?>
              </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>

      <?php endforeach; // areas ?>
      </div><!-- /.calc-card-grid -->

      <?php if ($showEstimate && !$estimateRow): ?>
      <div class="est-no-price">No saved estimate found for this submission.</div>
      <?php endif; ?>

      <?php if ($showEstimate && $estimateRow && $printEstimateCount > 0): ?>
      <div class="grand-estimate-strip">
        <div class="grand-estimate-head">Grand Estimate Totals · <?= $printEstimateCount ?> priced row<?= $printEstimateCount !== 1 ? 's' : '' ?></div>
        <div class="grand-estimate-grid">
          <div class="grand-estimate-cell mrp"><div class="gev"><?= fmtINR($printGrandMRP) ?></div><div class="gel">Grand MRP</div></div>
          <div class="grand-estimate-cell mop"><div class="gev"><?= fmtINR($printGrandMOP) ?></div><div class="gel">Grand MOP</div></div>
          <div class="grand-estimate-cell save"><div class="gev"><?= fmtINR(max(0, $printGrandMRP - $printGrandMOP)) ?></div><div class="gel">Saved Price</div></div>
          <div class="grand-estimate-cell offer"><div class="gev"><?= $printHasOffer ? fmtINR($printGrandAfterMRP) : '—' ?></div><div class="gel">After Offer MRP</div></div>
          <div class="grand-estimate-cell offer"><div class="gev"><?= $printHasOffer ? fmtINR($printGrandAfterMOP) : '—' ?></div><div class="gel">After Offer MOP</div></div>
        </div>
      </div>
      <?php endif; ?>

    </div><!-- /.sec-body -->
  </div><!-- /.sec areas -->
  <?php endif; // areas ?>

  <!-- Signature + footer (no viz sheet) -->
  <?php if (!$renderableSnaps): ?>
  <div class="sig-strip">
    <div class="sig-box"><div class="sig-line"></div><div class="sig-lbl">Customer Signature</div></div>
    <div class="sig-box"><div class="sig-line"></div><div class="sig-lbl">Sales Executive: <?= h($s['sub_name']) ?></div></div>
    <div class="sig-box"><div class="sig-line"></div><div class="sig-lbl">Admin</div></div>
  </div>
  <div class="print-footer">
    <span>Aandavar Enterprises — The Tiles Expert<?= $showEstimate ? ' · Computer-generated estimate' : '' ?></span>
    <span>Printed: <?= $printDate ?></span>
  </div>
  <?php endif; ?>

  <div class="page-no" data-page-label="Page 1"></div>
</div><!-- /.sheet 1 -->

<!-- ════════════════════════════════════════════════════
     SHEET 2 — Visualizer Snapshots (new page)
════════════════════════════════════════════════════ -->
<?php if ($renderableSnaps): ?>
<div class="sheet new-page">

  <div class="mini-lh no-break">
    <div class="mini-lh-left">
      <div class="mini-logo"><img src="<?= APP_URL ?>/assets/logo.png" alt="Logo"></div>
      <div>
        <div class="mini-name">Aandavar Enterprises</div>
        <div class="mini-sub"><?= h($s['owner_name']) ?> · #<?= $subId ?> — Visualizer Snapshots</div>
      </div>
    </div>
    <div class="mini-date"><?= $printDate ?></div>
  </div>

  <div class="viz-head">
    <div class="viz-head-title">🎨 3D Visualizer Snapshots</div>
    <div class="viz-head-badge"><?= count($renderableSnaps) ?> snapshot<?= count($renderableSnaps)!==1?'s':'' ?></div>
  </div>
  <div class="viz-body">
    <?php $snapList = array_values($renderableSnaps); $total = count($snapList); ?>
    <div class="viz-grid">
    <?php foreach ($snapList as $idx => $vs):
      $src = vizSnapSrc($vs);
      if ($src === '') continue;
      $snapDate  = date('d M Y', strtotime($vs['created_at']));
      $snapTime  = date('h:i A',  strtotime($vs['created_at']));
      $snapOwner = trim($vs['owner_name'] ?? '');
      $fullWidth = ($total % 2 !== 0 && $idx === $total - 1);
    ?>
    <div class="viz-item no-break<?= $fullWidth ? ' full-width' : '' ?>">
      <div class="viz-img-wrap">
        <img src="<?= h($src) ?>" alt="Snapshot <?= $idx+1 ?>" loading="eager"
             onerror="this.closest('.viz-item').style.display='none'">
      </div>
      <div class="viz-meta">
        <div style="display:flex;align-items:center;gap:5px">
          <span class="viz-badge"><?= $idx+1 ?></span>
          <span class="viz-meta-date"><?= h($snapDate) ?>, <?= h($snapTime) ?></span>
        </div>
        <?php if ($snapOwner): ?><span class="viz-meta-owner">📸 <?= h($snapOwner) ?></span><?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    </div>
  </div>

  <div class="sig-strip">
    <div class="sig-box"><div class="sig-line"></div><div class="sig-lbl">Customer Signature</div></div>
    <div class="sig-box"><div class="sig-line"></div><div class="sig-lbl">Sub-Admin: <?= h($s['sub_name']) ?></div></div>
    <div class="sig-box"><div class="sig-line"></div><div class="sig-lbl">Office Admin</div></div>
  </div>
  <div class="print-footer">
    <span>Aandavar Enterprises — Tiles &amp; Flooring Solutions</span>
    <span>Printed: <?= $printDate ?></span>
  </div>

  <div class="page-no" data-page-label="Page 2"></div>
</div><!-- /.sheet 2 viz -->
<?php endif; ?>

<script>
<?php if (!empty($_GET['autoprint'])): ?>
window.addEventListener('load', () => setTimeout(() => window.print(), 600));
<?php endif; ?>
</script>
</body>
</html>
