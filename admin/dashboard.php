<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
requireLogin('main_admin');

$db   = getDB();
$user = currentUser();

// ── Overall stats ──────────────────────────────────────────────────────────────
$totalSubs  = $db->query("SELECT COUNT(*) FROM users WHERE role='sub_admin'")->fetchColumn();
$activeSubs = $db->query("SELECT COUNT(DISTINCT user_id) FROM user_sessions WHERE is_active=1")->fetchColumn();
$totalData  = $db->query("SELECT COUNT(*) FROM submissions")->fetchColumn();
$alerts     = $db->query("SELECT COUNT(*) FROM area_alerts WHERE is_read=0")->fetchColumn();
$hotCount   = $db->query("SELECT COUNT(*) FROM submissions WHERE status='hot'")->fetchColumn();
$warmCount  = $db->query("SELECT COUNT(*) FROM submissions WHERE status='warm'")->fetchColumn();
$coldCount  = $db->query("SELECT COUNT(*) FROM submissions WHERE status='cold'")->fetchColumn();
$officeAdmins = $db->query("SELECT COUNT(*) FROM users WHERE role='office_admin'")->fetchColumn();
$activeUsers  = $db->query("SELECT COUNT(DISTINCT user_id) FROM user_sessions WHERE is_active=1")->fetchColumn();

$today = date('Y-m-d');
$in10Days = date('Y-m-d', strtotime('+10 days'));

$todayTotalStmt = $db->prepare("SELECT COUNT(*) FROM submissions WHERE DATE(COALESCE(device_submitted_at, submitted_at)) = ?");
$todayTotalStmt->execute([$today]); $todayTotal = (int)$todayTotalStmt->fetchColumn();

$todayHotStmt = $db->prepare("SELECT COUNT(*) FROM submissions WHERE status='hot' AND DATE(COALESCE(device_submitted_at, submitted_at)) = ?");
$todayHotStmt->execute([$today]); $todayHot = (int)$todayHotStmt->fetchColumn();

$todayWarmStmt = $db->prepare("SELECT COUNT(*) FROM submissions WHERE status='warm' AND DATE(COALESCE(device_submitted_at, submitted_at)) = ?");
$todayWarmStmt->execute([$today]); $todayWarm = (int)$todayWarmStmt->fetchColumn();

$todayColdStmt = $db->prepare("SELECT COUNT(*) FROM submissions WHERE status='cold' AND DATE(COALESCE(device_submitted_at, submitted_at)) = ?");
$todayColdStmt->execute([$today]); $todayCold = (int)$todayColdStmt->fetchColumn();

$completedOrders = (int)$db->query("SELECT COUNT(*) FROM submissions WHERE order_completed=1 OR status='billed'")->fetchColumn();
$visitStmt = $db->prepare("SELECT COUNT(*) FROM submissions WHERE next_visit_date IS NOT NULL AND next_visit_date >= ? AND next_visit_date <= ?");
$visitStmt->execute([$today, $in10Days]); $upcomingVisits = (int)$visitStmt->fetchColumn();
$overdueStmt = $db->prepare("SELECT COUNT(*) FROM submissions WHERE next_visit_date IS NOT NULL AND next_visit_date < ? AND (order_completed=0 OR order_completed IS NULL)");
$overdueStmt->execute([$today]); $overdueVisits = (int)$overdueStmt->fetchColumn();

$estimateCount = 0;
try {
    $estimateCount = (int)$db->query("SELECT COUNT(*) FROM submission_estimates")->fetchColumn();
} catch (Throwable $e) {}

$officeReportCount = 0;
try {
    $officeReportCount = (int)$db->query("SELECT COUNT(*) FROM office_admin_reports WHERE COALESCE(report_data, '') NOT LIKE '%admin_subadmins%'")->fetchColumn();
} catch (Throwable $e) {}

// ── Yesterday's counts ─────────────────────────────────────────────────────────
$yesterday = date('Y-m-d', strtotime('-1 day'));

$ydayHotStmt = $db->prepare("SELECT COUNT(*) FROM submissions WHERE status='hot'  AND DATE(COALESCE(device_submitted_at, submitted_at)) = ?");
$ydayHotStmt->execute([$yesterday]); $ydayHot = (int)$ydayHotStmt->fetchColumn();

$ydayWarmStmt = $db->prepare("SELECT COUNT(*) FROM submissions WHERE status='warm' AND DATE(COALESCE(device_submitted_at, submitted_at)) = ?");
$ydayWarmStmt->execute([$yesterday]); $ydayWarm = (int)$ydayWarmStmt->fetchColumn();

$ydayColdStmt = $db->prepare("SELECT COUNT(*) FROM submissions WHERE status='cold' AND DATE(COALESCE(device_submitted_at, submitted_at)) = ?");
$ydayColdStmt->execute([$yesterday]); $ydayCold = (int)$ydayColdStmt->fetchColumn();

$ydayTotal = $ydayHot + $ydayWarm + $ydayCold;

// ── Zone-wise breakdown ────────────────────────────────────────────────────────
$zoneBreakdownStmt = $db->query("
    SELECT d.code AS dir_code, d.label AS dir_label,
           sd.id AS zone_id, sd.code AS zone_code,
           sd.from_location, sd.to_location,
           s.village_city_town,
           MAX(s.latitude) AS latitude,
           MAX(s.longitude) AS longitude,
           SUM(s.status='hot')  AS hot,
           SUM(s.status='warm') AS warm,
           SUM(s.status='cold') AS cold,
           COUNT(*) AS total
    FROM   submissions s
    JOIN   sub_directions sd ON s.sub_direction_id = sd.id
    JOIN   directions d      ON sd.direction_id    = d.id
    GROUP  BY d.code, d.label, sd.id, sd.code, sd.from_location, sd.to_location, s.village_city_town
    ORDER  BY d.code, sd.code, hot DESC, warm DESC
");
$rawRows = $zoneBreakdownStmt->fetchAll();

$dirMap = [];
$areaRows = [];
$topHot = $topWarm = $topCool = null;
foreach ($rawRows as $r) {
    $dk = $r['dir_code'];
    $zk = $r['zone_id'];
    if (!isset($dirMap[$dk])) $dirMap[$dk] = ['label' => $r['dir_label'], 'zones' => []];
    if (!isset($dirMap[$dk]['zones'][$zk])) {
        $dirMap[$dk]['zones'][$zk] = [
            'code' => $r['zone_code'], 'from_location' => $r['from_location'],
            'to_location' => $r['to_location'], 'villages' => [],
            'hot' => 0, 'warm' => 0, 'cold' => 0, 'total' => 0,
        ];
    }
    $dirMap[$dk]['zones'][$zk]['villages'][] = [
        'name' => $r['village_city_town'], 'hot' => (int)$r['hot'],
        'warm' => (int)$r['warm'], 'cold' => (int)$r['cold'], 'total' => (int)$r['total'],
        'latitude' => $r['latitude'], 'longitude' => $r['longitude'],
    ];
    $dirMap[$dk]['zones'][$zk]['hot']   += (int)$r['hot'];
    $dirMap[$dk]['zones'][$zk]['warm']  += (int)$r['warm'];
    $dirMap[$dk]['zones'][$zk]['cold']  += (int)$r['cold'];
    $dirMap[$dk]['zones'][$zk]['total'] += (int)$r['total'];

    $hot = (int)$r['hot'];
    $warm = (int)$r['warm'];
    $cool = (int)$r['cold'];
    $dominant = ($hot >= $warm && $hot >= $cool) ? 'hot' : (($warm >= $cool) ? 'warm' : 'cool');
    $areaRows[] = [
        'dir_code' => $r['dir_code'],
        'dir_label' => $r['dir_label'],
        'zone_code' => $r['zone_code'],
        'route' => trim(($r['from_location'] ?? '') . ' → ' . ($r['to_location'] ?? '')),
        'area' => $r['village_city_town'] ?: 'Unknown area',
        'hot' => $hot,
        'warm' => $warm,
        'cool' => $cool,
        'total' => (int)$r['total'],
        'dominant' => $dominant,
        'latitude' => $r['latitude'],
        'longitude' => $r['longitude'],
    ];

    if (!$topHot || $hot > $topHot['hot']) $topHot = end($areaRows);
    if (!$topWarm || $warm > $topWarm['warm']) $topWarm = end($areaRows);
    if (!$topCool || $cool > $topCool['cool']) $topCool = end($areaRows);
}
usort($areaRows, fn($a, $b) => [$b['hot'], $b['warm'], $b['cool'], $b['total']] <=> [$a['hot'], $a['warm'], $a['cool'], $a['total']]);

// ── Recent activity & submissions ──────────────────────────────────────────────
$recent = $db->query("SELECT al.*, u.username FROM activity_log al JOIN users u ON al.user_id=u.id ORDER BY al.created_at DESC LIMIT 8")->fetchAll();
$recentSubs = $db->query("
    SELECT s.*, u.username, sd.code as zone,
           COALESCE(s.device_submitted_at, s.submitted_at) AS display_time
    FROM submissions s
    JOIN users u ON s.submitted_by=u.id
    JOIN sub_directions sd ON s.sub_direction_id=sd.id
    ORDER BY display_time DESC LIMIT 6
")->fetchAll();

pageHead('Dashboard');
topbar($user['username'], $user['role']);
?>
<style>
/* ══════════════════════════════════════════════════════
   DASHBOARD GRID SYSTEM
══════════════════════════════════════════════════════ */
:root {
  --dash-radius: 16px;
  --dash-shadow: 0 1px 4px rgba(0,0,0,.06), 0 4px 16px rgba(0,0,0,.06);
  --dash-shadow-md: 0 4px 20px rgba(0,0,0,.1), 0 1px 4px rgba(0,0,0,.06);
  --ease: cubic-bezier(.4,0,.2,1);
  --green-dark: #052e16; --green-mid: #166534; --green-accent: #4ade80; --green-soft: #f0fdf4;
  --amber-dark: #431407; --amber-mid: #9a3412; --amber-accent: #fb923c; --amber-soft: #fff7ed;
  --red-dark:   #450a0a; --red-mid:   #991b1b; --red-accent:   #f87171; --red-soft:   #fff5f5;
  --blue-accent: #3b82f6; --blue-soft: #eff6ff;
}

/* ══ Command center ══ */
.dash-hero{
  display:grid;
  grid-template-columns:minmax(0,1.45fr) minmax(280px,.85fr);
  gap:16px;
  margin-bottom:18px;
}
.hero-panel,.access-panel,.metric-card,.work-panel{
  background:#fff;
  border:1px solid var(--c-gray-200);
  border-radius:14px;
  box-shadow:var(--dash-shadow);
}
.hero-panel{
  padding:20px;
  display:grid;
  grid-template-columns:minmax(0,1fr) auto;
  gap:16px;
  align-items:start;
  overflow:hidden;
  position:relative;
}
.hero-panel::before{
  content:'';
  position:absolute;
  left:0;top:0;bottom:0;width:5px;
  background:linear-gradient(180deg,#2563eb,#16a34a,#d97706);
}
.dash-eyebrow{font-size:.68rem;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:var(--c-gray-400);margin-bottom:5px}
.dash-title{font-size:1.55rem;font-weight:900;color:var(--c-gray-900);letter-spacing:-.03em;line-height:1.12}
.dash-subtitle{font-size:.86rem;color:var(--c-gray-500);margin-top:6px}
.hero-date{font-size:.76rem;font-weight:800;color:#1d4ed8;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:6px 10px;white-space:nowrap}
.today-strip{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:18px}
.today-chip{border:1px solid var(--c-gray-200);border-radius:10px;padding:10px;background:#f8fafc;min-width:0}
.today-chip .tv{font-size:1.35rem;font-weight:900;color:var(--c-gray-900);line-height:1;font-variant-numeric:tabular-nums}
.today-chip .tl{font-size:.65rem;text-transform:uppercase;letter-spacing:.08em;color:var(--c-gray-400);font-weight:800;margin-top:4px}
.today-chip.hot{background:#f0fdf4;border-color:#bbf7d0}.today-chip.hot .tv{color:#15803d}
.today-chip.warm{background:#fffbeb;border-color:#fde68a}.today-chip.warm .tv{color:#b45309}
.today-chip.cold{background:#fef2f2;border-color:#fecaca}.today-chip.cold .tv{color:#b91c1c}
.access-panel{padding:14px}
.access-title{font-size:.75rem;font-weight:900;text-transform:uppercase;letter-spacing:.1em;color:var(--c-gray-400);margin-bottom:10px}
.quick-actions{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.quick-link{
  display:flex;align-items:center;gap:9px;
  padding:10px;border:1px solid var(--c-gray-200);border-radius:10px;
  background:#fff;color:var(--c-gray-800);font-size:.78rem;font-weight:800;
  min-height:48px;transition:background .15s,border-color .15s,transform .15s;
}
.quick-link:hover{background:#f8fafc;border-color:#bfdbfe;transform:translateY(-1px);color:#1d4ed8}
.quick-ico{width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;background:#eff6ff;color:#1d4ed8;flex-shrink:0}
.quick-ico svg{width:15px;height:15px}
.metric-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;margin-bottom:18px}
.metric-card{display:flex;flex-direction:column;padding:14px;position:relative;overflow:hidden;min-height:116px;color:inherit;text-decoration:none}
.metric-card:hover{transform:translateY(-1px);box-shadow:var(--dash-shadow-md);color:inherit}
.metric-card::before{content:'';position:absolute;left:0;right:0;top:0;height:3px;background:var(--accent,#2563eb)}
.metric-label{font-size:.64rem;font-weight:900;letter-spacing:.1em;text-transform:uppercase;color:var(--c-gray-400)}
.metric-value{font-size:2rem;font-weight:900;letter-spacing:-.05em;line-height:1;color:var(--c-gray-900);margin-top:9px;font-variant-numeric:tabular-nums}
.metric-sub{font-size:.72rem;color:var(--c-gray-500);margin-top:5px;line-height:1.35}
.metric-card.blue{--accent:#2563eb}.metric-card.green{--accent:#16a34a}.metric-card.amber{--accent:#d97706}.metric-card.red{--accent:#dc2626}.metric-card.purple{--accent:#7c3aed}.metric-card.slate{--accent:#475569}
.metric-card.green .metric-value{color:#15803d}.metric-card.amber .metric-value{color:#b45309}.metric-card.red .metric-value{color:#b91c1c}.metric-card.purple .metric-value{color:#7c3aed}.metric-card.blue .metric-value{color:#1d4ed8}
.work-grid{display:grid;grid-template-columns:1.1fr .9fr;gap:16px;margin-bottom:22px}
.work-panel{padding:16px}
.work-title{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px}
.work-title strong{font-size:.94rem;color:var(--c-gray-900)}
.work-title a{font-size:.74rem;font-weight:800;color:#2563eb;background:#eff6ff;border:1px solid #bfdbfe;border-radius:7px;padding:4px 9px}
.status-list{display:flex;flex-direction:column;gap:9px}
.status-row{display:grid;grid-template-columns:70px minmax(0,1fr) 48px;gap:10px;align-items:center}
.status-name{font-size:.75rem;font-weight:900;color:var(--c-gray-700)}
.status-track{height:8px;border-radius:99px;background:#f3f4f6;overflow:hidden}
.status-fill{height:100%;border-radius:99px;background:var(--fill,#2563eb)}
.status-count{font-size:.82rem;font-weight:900;color:var(--c-gray-800);text-align:right;font-variant-numeric:tabular-nums}
.status-row.hot{--fill:#16a34a}.status-row.warm{--fill:#d97706}.status-row.cold{--fill:#dc2626}
.focus-grid{display:grid;grid-template-columns:1fr 1fr;gap:9px}
.focus-card{border:1px solid var(--c-gray-200);border-radius:10px;padding:11px;background:#f8fafc}
.focus-card .fv{font-size:1.55rem;font-weight:900;line-height:1;color:var(--c-gray-900)}
.focus-card .fl{font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--c-gray-400);margin-top:5px}
.focus-card.danger{background:#fef2f2;border-color:#fecaca}.focus-card.danger .fv{color:#b91c1c}
.focus-card.warn{background:#fffbeb;border-color:#fde68a}.focus-card.warn .fv{color:#b45309}
.focus-card.good{background:#f0fdf4;border-color:#bbf7d0}.focus-card.good .fv{color:#15803d}
.focus-card.purple{background:#faf5ff;border-color:#ddd6fe}.focus-card.purple .fv{color:#7c3aed}

@media(max-width:1100px){
  .dash-hero{grid-template-columns:1fr}
  .metric-grid{grid-template-columns:repeat(3,1fr)}
  .work-grid{grid-template-columns:1fr}
}
@media(max-width:700px){
  .hero-panel{grid-template-columns:1fr}
  .today-strip{grid-template-columns:repeat(2,1fr)}
  .metric-grid{grid-template-columns:repeat(2,1fr)}
  .quick-actions{grid-template-columns:1fr}
  .focus-grid{grid-template-columns:1fr 1fr}
}
@media(max-width:420px){
  .metric-grid,.today-strip,.focus-grid{grid-template-columns:1fr}
}

/* ── Section label ── */
.dash-section-label {
  font-size: .68rem;
  font-weight: 800;
  letter-spacing: .1em;
  text-transform: uppercase;
  color: var(--c-gray-400, #9ca3af);
  margin-bottom: 10px;
  display: flex;
  align-items: center;
  gap: 8px;
}
.dash-section-label::after {
  content: '';
  flex: 1;
  height: 1px;
  background: var(--c-gray-100, #f3f4f6);
}

/* ══ KPI GRID ══ */
.kpi-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 14px;
  margin-bottom: 28px;
}
@media (max-width: 900px) { .kpi-grid { grid-template-columns: repeat(2,1fr); } }
@media (max-width: 480px) { .kpi-grid { grid-template-columns: 1fr 1fr; } }

.kpi-card {
  background: #fff;
  border-radius: var(--dash-radius);
  box-shadow: var(--dash-shadow);
  padding: 20px 20px 16px;
  display: flex;
  flex-direction: column;
  gap: 6px;
  border: 1.5px solid var(--c-gray-100, #f3f4f6);
  position: relative;
  overflow: hidden;
  transition: box-shadow .2s var(--ease), transform .2s var(--ease);
  animation: fadeUp .35s var(--ease) both;
}
.kpi-card:hover { box-shadow: var(--dash-shadow-md); transform: translateY(-2px); }
.kpi-card:nth-child(1) { animation-delay: .04s; }
.kpi-card:nth-child(2) { animation-delay: .08s; }
.kpi-card:nth-child(3) { animation-delay: .12s; }
.kpi-card:nth-child(4) { animation-delay: .16s; }

.kpi-card::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 3px;
  border-radius: var(--dash-radius) var(--dash-radius) 0 0;
}
.kpi-card.blue::before  { background: linear-gradient(90deg, #3b82f6, #60a5fa); }
.kpi-card.green::before { background: linear-gradient(90deg, #16a34a, #4ade80); }
.kpi-card.amber::before { background: linear-gradient(90deg, #d97706, #fb923c); }
.kpi-card.red::before   { background: linear-gradient(90deg, #dc2626, #f87171); }

.kpi-icon {
  width: 38px; height: 38px;
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.1rem;
  margin-bottom: 4px;
}
.kpi-card.blue  .kpi-icon { background: #eff6ff; }
.kpi-card.green .kpi-icon { background: #f0fdf4; }
.kpi-card.amber .kpi-icon { background: #fffbeb; }
.kpi-card.red   .kpi-icon { background: #fff5f5; }

.kpi-val {
  font-size: 2.2rem;
  font-weight: 800;
  line-height: 1;
  letter-spacing: -.04em;
  color: var(--c-gray-800, #1f2937);
}
.kpi-label {
  font-size: .75rem;
  font-weight: 600;
  color: var(--c-gray-400, #9ca3af);
  letter-spacing: .02em;
}

@keyframes fadeUp {
  from { opacity: 0; transform: translateY(12px); }
  to   { opacity: 1; transform: translateY(0); }
}

/* ══ STATUS ROW ══ */
.status-trio {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 14px;
  margin-bottom: 28px;
}
@media (max-width: 480px) { .status-trio { grid-template-columns: 1fr; } }

.status-card {
  border-radius: var(--dash-radius);
  padding: 22px 18px 18px;
  position: relative;
  overflow: hidden;
  box-shadow: 0 4px 20px rgba(0,0,0,.14);
  display: flex;
  flex-direction: column;
  gap: 4px;
  animation: fadeUp .4s var(--ease) both;
  transition: transform .2s var(--ease), box-shadow .2s var(--ease);
}
.status-card:hover { transform: translateY(-3px); box-shadow: 0 8px 28px rgba(0,0,0,.2); }

.status-card::after {
  content: '';
  position: absolute;
  right: -24px; top: -24px;
  width: 100px; height: 100px;
  border-radius: 50%;
  opacity: .12;
  filter: blur(4px);
}

.status-card.hot  { background: linear-gradient(145deg, var(--green-dark) 0%, var(--green-mid) 100%); border: 1px solid #15803d; color: #dcfce7; animation-delay:.05s; }
.status-card.warm { background: linear-gradient(145deg, var(--amber-dark) 0%, var(--amber-mid) 100%); border: 1px solid #c2410c; color: #ffedd5; animation-delay:.10s; }
.status-card.cold { background: linear-gradient(145deg, var(--red-dark)   0%, var(--red-mid)   100%); border: 1px solid #b91c1c; color: #fee2e2; animation-delay:.15s; }

.status-card.hot  .s-num { color: var(--green-accent); }
.status-card.warm .s-num { color: var(--amber-accent); }
.status-card.cold .s-num { color: var(--red-accent);   }
.status-card.hot::after  { background: var(--green-accent); }
.status-card.warm::after { background: var(--amber-accent); }
.status-card.cold::after { background: var(--red-accent);   }

.s-emoji { font-size: 1.5rem; line-height: 1; margin-bottom: 6px; }
.s-num   { font-size: 3rem; font-weight: 800; line-height: 1; letter-spacing: -.05em; }
.s-label { font-size: .7rem; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; opacity: .65; }
.s-sub   { font-size: .72rem; opacity: .45; margin-top: 2px; }

.s-bar-wrap { margin-top: 14px; background: rgba(255,255,255,.12); border-radius: 99px; height: 4px; overflow: hidden; }
.s-bar-fill { height: 100%; border-radius: 99px; }
.status-card.hot  .s-bar-fill { background: var(--green-accent); }
.status-card.warm .s-bar-fill { background: var(--amber-accent); }
.status-card.cold .s-bar-fill { background: var(--red-accent); }

/* ══ YESTERDAY PANEL ══ */
.yday-panel {
  background: #fff;
  border-radius: var(--dash-radius);
  box-shadow: var(--dash-shadow);
  border: 1.5px solid var(--c-gray-100, #f3f4f6);
  padding: 20px;
  margin-bottom: 28px;
  animation: fadeUp .4s var(--ease) .18s both;
}
.yday-header {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 16px;
  flex-wrap: wrap;
}
.yday-title { font-size: .95rem; font-weight: 700; color: var(--c-gray-800); }
.yday-date  { font-size: .75rem; background: var(--c-gray-100); border-radius: 99px; padding: 3px 10px; color: var(--c-gray-500); }
.yday-total { font-size: .75rem; background: #eff6ff; color: #1d4ed8; border-radius: 99px; padding: 3px 10px; font-weight: 700; margin-left: auto; }

.yday-row {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 10px;
}
@media (max-width: 480px) { .yday-row { grid-template-columns: 1fr; } }

.yday-mini {
  border-radius: 12px;
  padding: 14px 14px 12px;
  display: flex;
  flex-direction: column;
  gap: 3px;
  position: relative;
  overflow: hidden;
}
.yday-mini.hot  { background: linear-gradient(135deg, var(--green-dark), var(--green-mid)); border: 1px solid #15803d; }
.yday-mini.warm { background: linear-gradient(135deg, var(--amber-dark), var(--amber-mid)); border: 1px solid #c2410c; }
.yday-mini.cold { background: linear-gradient(135deg, var(--red-dark),   var(--red-mid));   border: 1px solid #b91c1c; }

.yday-mini .ym-num   { font-size: 2rem; font-weight: 800; letter-spacing: -.04em; line-height: 1; }
.yday-mini .ym-label { font-size: .68rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; opacity: .6; }
.yday-mini .ym-pct   { font-size: .68rem; opacity: .4; margin-top: 1px; }
.yday-mini .ym-bar   { margin-top: 10px; height: 3px; border-radius: 99px; background: rgba(255,255,255,.12); overflow: hidden; }
.yday-mini .ym-fill  { height: 100%; border-radius: 99px; }

.yday-mini.hot  .ym-num, .yday-mini.hot  .ym-fill { color: var(--green-accent); background: var(--green-accent); }
.yday-mini.warm .ym-num, .yday-mini.warm .ym-fill { color: var(--amber-accent); background: var(--amber-accent); }
.yday-mini.cold .ym-num, .yday-mini.cold .ym-fill { color: var(--red-accent);   background: var(--red-accent); }

/* ══ ZONE BREAKDOWN ══ */
.zone-section {
  background: #fff;
  border-radius: var(--dash-radius);
  box-shadow: var(--dash-shadow);
  border: 1.5px solid var(--c-gray-100, #f3f4f6);
  padding: 20px;
  margin-bottom: 28px;
  animation: fadeUp .4s var(--ease) .22s both;
}
.zone-section-title {
  font-size: 1rem;
  font-weight: 700;
  color: var(--c-gray-800);
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 18px;
}
.zone-section-title span {
  font-size: .75rem;
  font-weight: 400;
  color: var(--c-gray-400);
  margin-left: auto;
}

.break-filter {
  display: grid;
  grid-template-columns: minmax(180px, 1fr) 150px 160px;
  gap: 8px;
  margin-bottom: 16px;
}
.rank-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 12px;
  margin-bottom: 18px;
}
.rank-card {
  border: 1.5px solid var(--c-gray-100);
  border-radius: 12px;
  padding: 14px;
  background: #fff;
  min-width: 0;
}
.rank-card.hot { border-left: 4px solid var(--green-accent); }
.rank-card.warm { border-left: 4px solid var(--amber-accent); }
.rank-card.cool { border-left: 4px solid var(--red-accent); }
.rank-label { font-size: .68rem; text-transform: uppercase; letter-spacing: .09em; color: var(--c-gray-400); font-weight: 800; margin-bottom: 6px; }
.rank-area { font-size: .95rem; font-weight: 800; color: var(--c-gray-900); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.rank-meta { font-size: .75rem; color: var(--c-gray-500); margin-top: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.rank-count { font-size: 1.9rem; font-weight: 900; line-height: 1; margin-top: 12px; }
.rank-card.hot .rank-count { color: var(--green-mid); }
.rank-card.warm .rank-count { color: var(--amber-mid); }
.rank-card.cool .rank-count { color: var(--red-mid); }
.area-table-wrap { border: 1px solid var(--c-gray-200); border-radius: 12px; overflow: auto; margin-bottom: 18px; }
.area-rank-table { min-width: 760px; }
.area-rank-table td, .area-rank-table th { vertical-align: middle; }
.area-name-main { font-weight: 800; color: var(--c-gray-900); }
.area-name-sub { font-size: .72rem; color: var(--c-gray-400); margin-top: 2px; }
.dom-pill { display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; border-radius: 99px; font-size: .72rem; font-weight: 800; }
.dom-pill.hot { background: var(--green-soft); color: var(--green-mid); }
.dom-pill.warm { background: var(--amber-soft); color: var(--amber-mid); }
.dom-pill.cool { background: var(--red-soft); color: var(--red-mid); }
.map-link { display: inline-flex; align-items: center; gap: 4px; padding: 5px 9px; border-radius: 7px; background: var(--blue-soft); color: #1d4ed8; font-size: .72rem; font-weight: 800; }
.map-link.disabled { background: var(--c-gray-100); color: var(--c-gray-400); pointer-events: none; }
.break-empty { display: none; text-align: center; padding: 22px; border: 1px dashed var(--c-gray-200); border-radius: 10px; color: var(--c-gray-500); font-size: .86rem; margin-bottom: 18px; }

/* Direction block */
.dir-block { margin-bottom: 22px; }
.dir-block:last-child { margin-bottom: 0; }
.dir-header {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 12px;
}
.dir-badge {
  font-size: .78rem;
  font-weight: 800;
  letter-spacing: .04em;
  background: linear-gradient(135deg, #1d4ed8, #3b82f6);
  color: #fff;
  padding: 4px 12px;
  border-radius: 8px;
}
.dir-label { font-size: .88rem; color: var(--c-gray-600); font-weight: 500; }

/* Zones card grid */
.zones-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 12px;
  margin-left: 16px;
}

.zone-card {
  border: 1.5px solid var(--c-gray-100, #f3f4f6);
  border-radius: 12px;
  overflow: hidden;
  transition: box-shadow .2s var(--ease);
}
.zone-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.08); }

.zone-card-head {
  background: #f8fafc;
  padding: 10px 14px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  border-bottom: 1px solid var(--c-gray-100);
}
.zone-code-chip {
  font-size: .72rem;
  font-weight: 800;
  background: #eff6ff;
  color: #1d4ed8;
  border: 1px solid #bfdbfe;
  padding: 2px 8px;
  border-radius: 6px;
  letter-spacing: .04em;
}
.zone-route { font-size: .78rem; color: var(--c-gray-600); font-weight: 500; flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.zone-totals { display: flex; gap: 6px; align-items: center; flex-shrink: 0; }
.zt { font-size: .7rem; font-weight: 700; padding: 2px 6px; border-radius: 5px; }
.zt.h { background: var(--green-soft); color: var(--green-mid); }
.zt.w { background: var(--amber-soft); color: var(--amber-mid); }
.zt.c { background: var(--red-soft);   color: var(--red-mid); }

.zone-card-body { padding: 8px 0; }

.village-row {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 7px 14px;
  border-bottom: 1px solid #f9fafb;
  transition: background .15s;
}
.village-row:last-child { border-bottom: none; }
.village-row:hover { background: #fafbff; }

.vr-dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }
.vr-dot.hot  { background: var(--green-accent); box-shadow: 0 0 0 2px rgba(74,222,128,.2); }
.vr-dot.warm { background: var(--amber-accent); box-shadow: 0 0 0 2px rgba(251,146,60,.2); }
.vr-dot.cold { background: var(--red-accent);   box-shadow: 0 0 0 2px rgba(248,113,113,.2); }

.vr-name { flex: 1; font-size: .82rem; font-weight: 500; color: var(--c-gray-700); min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.vr-chips { display: flex; gap: 4px; align-items: center; flex-shrink: 0; }
.vr-chip { font-size: .68rem; font-weight: 700; padding: 1px 6px; border-radius: 4px; }
.vr-chip.h { background: var(--green-soft); color: #15803d; }
.vr-chip.w { background: var(--amber-soft); color: #92400e; }
.vr-chip.c { background: var(--red-soft);   color: #991b1b; }
.vr-total { font-size: .68rem; color: var(--c-gray-400); min-width: 28px; text-align: right; }

/* ══ BOTTOM 2-COL GRID ══ */
.dash-bottom {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
  margin-bottom: 28px;
}
@media (max-width: 700px) { .dash-bottom { grid-template-columns: 1fr; } }

.dash-panel {
  background: #fff;
  border-radius: var(--dash-radius);
  box-shadow: var(--dash-shadow);
  border: 1.5px solid var(--c-gray-100, #f3f4f6);
  padding: 20px;
  animation: fadeUp .4s var(--ease) .26s both;
}
.dash-panel-title {
  font-size: .92rem;
  font-weight: 700;
  color: var(--c-gray-800);
  margin-bottom: 16px;
  padding-bottom: 12px;
  border-bottom: 1px solid var(--c-gray-100);
  display: flex;
  align-items: center;
  gap: 8px;
}

/* Submissions mini-list */
.sub-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 0;
  border-bottom: 1px solid #f9fafb;
}
.sub-item:last-child { border-bottom: none; }
.sub-avatar {
  width: 34px; height: 34px;
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: .78rem; font-weight: 800;
  flex-shrink: 0;
}
.sub-avatar.hot  { background: var(--green-soft); color: var(--green-mid); }
.sub-avatar.warm { background: var(--amber-soft); color: var(--amber-mid); }
.sub-avatar.cold { background: var(--red-soft);   color: var(--red-mid); }

.sub-meta { flex: 1; min-width: 0; }
.sub-owner { font-size: .84rem; font-weight: 600; color: var(--c-gray-800); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sub-by    { font-size: .72rem; color: var(--c-gray-400); }
.sub-right { text-align: right; flex-shrink: 0; }
.sub-zone  { font-size: .7rem; font-weight: 700; background: #eff6ff; color: #1d4ed8; padding: 2px 7px; border-radius: 5px; }
.sub-time  { font-size: .68rem; color: var(--c-gray-400); margin-top: 3px; }

.s-pill {
  display: inline-flex; align-items: center; gap: 4px;
  font-size: .7rem; font-weight: 700; padding: 2px 8px; border-radius: 5px;
}
.s-pill.hot  { background: var(--green-soft); color: #15803d; }
.s-pill.warm { background: var(--amber-soft); color: #92400e; }
.s-pill.cold { background: var(--red-soft);   color: #991b1b; }

/* Activity mini-list */
.act-item {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 9px 0;
  border-bottom: 1px solid #f9fafb;
}
.act-item:last-child { border-bottom: none; }
.act-avatar {
  width: 32px; height: 32px;
  border-radius: 50%;
  background: linear-gradient(135deg, #dbeafe, #bfdbfe);
  color: #1d4ed8;
  display: flex; align-items: center; justify-content: center;
  font-size: .72rem; font-weight: 700; flex-shrink: 0;
}
.act-user   { font-size: .84rem; font-weight: 600; color: var(--c-gray-800); }
.act-action { font-size: .78rem; color: var(--c-gray-500); font-weight: 400; }
.act-time   { font-size: .7rem; color: var(--c-gray-400); margin-top: 2px; }

/* Empty states */
.dash-empty {
  text-align: center;
  padding: 32px 20px;
  color: var(--c-gray-400);
  font-size: .85rem;
}
.dash-empty-icon { font-size: 2.5rem; margin-bottom: 8px; }

/* View all link */
.view-all {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: .78rem;
  font-weight: 600;
  color: var(--blue-accent);
  text-decoration: none;
  margin-top: 14px;
  padding: 6px 12px;
  border-radius: 8px;
  border: 1.5px solid #bfdbfe;
  background: #eff6ff;
  transition: background .15s;
}
.view-all:hover { background: #dbeafe; }

/* ── Zone expand toggle ── */
.zone-card-body.collapsed { display: none; }
.zone-toggle { cursor: pointer; font-size: .68rem; color: var(--c-gray-400); padding: 2px 6px; border-radius: 4px; border: 1px solid var(--c-gray-200); background: none; transition: .15s; }
.zone-toggle:hover { background: var(--c-gray-100); }

@media (max-width: 640px) {
  .zones-grid { grid-template-columns: 1fr; margin-left: 0; }
  .break-filter { grid-template-columns: 1fr; }
  .rank-grid { grid-template-columns: 1fr; }
}
</style>

<div class="app-wrap">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="main-content">

    <?php
      $grandTotal = $hotCount + $warmCount + $coldCount;
      $hotPctAll  = $grandTotal ? round($hotCount / $grandTotal * 100) : 0;
      $warmPctAll = $grandTotal ? round($warmCount / $grandTotal * 100) : 0;
      $coldPctAll = 100 - $hotPctAll - $warmPctAll;
      $todayHotPct  = $todayTotal ? round($todayHot / $todayTotal * 100) : 0;
      $todayWarmPct = $todayTotal ? round($todayWarm / $todayTotal * 100) : 0;
      $todayColdPct = $todayTotal ? max(0, 100 - $todayHotPct - $todayWarmPct) : 0;
    ?>

    <div class="dash-hero">
      <div class="hero-panel">
        <div>
          <div class="dash-eyebrow">Main Admin Dashboard</div>
          <div class="dash-title">Clear control center</div>
          <div class="dash-subtitle">Welcome back, <strong><?= htmlspecialchars($user['username']) ?></strong>. Today has <?= number_format($todayTotal) ?> submission<?= $todayTotal === 1 ? '' : 's' ?> and <?= number_format($activeUsers) ?> active user<?= $activeUsers === 1 ? '' : 's' ?>.</div>
          <div class="today-strip">
            <div class="today-chip"><div class="tv"><?= number_format($todayTotal) ?></div><div class="tl">Today Total</div></div>
            <div class="today-chip hot"><div class="tv"><?= number_format($todayHot) ?></div><div class="tl">Hot</div></div>
            <div class="today-chip warm"><div class="tv"><?= number_format($todayWarm) ?></div><div class="tl">Warm</div></div>
            <div class="today-chip cold"><div class="tv"><?= number_format($todayCold) ?></div><div class="tl">Cool</div></div>
          </div>
        </div>
        <div class="hero-date"><?= date('D, d M Y') ?></div>
      </div>

      <div class="access-panel">
        <div class="access-title">Easy Access</div>
        <div class="quick-actions">
          <a class="quick-link" href="subadmins.php"><span class="quick-ico"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></span>Admins</a>
          <a class="quick-link" href="active_sessions.php"><span class="quick-ico"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 11h-6"/><path d="M19 8v6"/></svg></span>Sessions</a>
          <a class="quick-link" href="office_reports.php"><span class="quick-ico"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg></span>Sales Reports</a>
          <a class="quick-link" href="reports.php"><span class="quick-ico"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></span>All Data</a>
          <a class="quick-link" href="locations.php"><span class="quick-ico"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg></span>Zones</a>
          <a class="quick-link" href="terminate.php"><span class="quick-ico"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg></span>Terminate</a>
        </div>
      </div>
    </div>

    <div class="metric-grid">
      <a class="metric-card blue" href="reports.php">
        <div class="metric-label">Total Submissions</div>
        <div class="metric-value"><?= number_format($totalData) ?></div>
        <div class="metric-sub">All customer records</div>
      </a>
      <a class="metric-card green" href="office_reports.php?report_type=order_completed">
        <div class="metric-label">Orders Completed</div>
        <div class="metric-value"><?= number_format($completedOrders) ?></div>
        <div class="metric-sub">Billed or completed</div>
      </a>
      <a class="metric-card amber" href="reports.php?visit=upcoming10">
        <div class="metric-label">Upcoming Visits</div>
        <div class="metric-value"><?= number_format($upcomingVisits) ?></div>
        <div class="metric-sub">Next 10 days</div>
      </a>
      <a class="metric-card red" href="reports.php?visit=overdue">
        <div class="metric-label">Overdue Visits</div>
        <div class="metric-value"><?= number_format($overdueVisits) ?></div>
        <div class="metric-sub">Needs follow-up</div>
      </a>
      <a class="metric-card purple" href="office_reports.php">
        <div class="metric-label">Sales Updates</div>
        <div class="metric-value"><?= number_format($officeReportCount) ?></div>
        <div class="metric-sub">Office reports</div>
      </a>
      <a class="metric-card slate" href="subadmins.php">
        <div class="metric-label">Executives</div>
        <div class="metric-value"><?= number_format((int)$totalSubs + (int)$officeAdmins) ?></div>
        <div class="metric-sub"><?= number_format($officeAdmins) ?> sales, <?= number_format($totalSubs) ?> marketing</div>
      </a>
    </div>

    <div class="work-grid">
      <div class="work-panel">
        <div class="work-title"><strong>All-time Status</strong><a href="reports.php">Open data</a></div>
        <div class="status-list">
          <div class="status-row hot"><div class="status-name">Hot</div><div class="status-track"><div class="status-fill" style="width:<?= $hotPctAll ?>%"></div></div><div class="status-count"><?= number_format($hotCount) ?></div></div>
          <div class="status-row warm"><div class="status-name">Warm</div><div class="status-track"><div class="status-fill" style="width:<?= $warmPctAll ?>%"></div></div><div class="status-count"><?= number_format($warmCount) ?></div></div>
          <div class="status-row cold"><div class="status-name">Cool</div><div class="status-track"><div class="status-fill" style="width:<?= $coldPctAll ?>%"></div></div><div class="status-count"><?= number_format($coldCount) ?></div></div>
        </div>
      </div>
      <div class="work-panel">
        <div class="work-title"><strong>Today &amp; Follow-up</strong><a href="active_sessions.php">Sessions</a></div>
        <div class="focus-grid">
          <div class="focus-card good"><div class="fv"><?= number_format($todayHotPct) ?>%</div><div class="fl">Today Hot</div></div>
          <div class="focus-card warn"><div class="fv"><?= number_format($todayWarmPct) ?>%</div><div class="fl">Today Warm</div></div>
          <div class="focus-card danger"><div class="fv"><?= number_format($alerts) ?></div><div class="fl">Unread Alerts</div></div>
          <div class="focus-card purple"><div class="fv"><?= number_format($estimateCount) ?></div><div class="fl">Estimates</div></div>
        </div>
      </div>
    </div>

    <!-- ══ ZONE & AREA BREAKDOWN ══ -->
    <?php if ($dirMap): ?>
    <div class="dash-section-label">Zone &amp; Area Breakdown</div>
    <div class="zone-section">
      <div class="zone-section-title">
        📍 Ordered Area Breakdown
        <span>Highest Hot, Warm and Cool areas with map links</span>
      </div>

      <div class="break-filter">
        <input class="form-control" type="search" id="areaSearch" placeholder="Filter area, zone or route..." oninput="filterAreaBreakdown()">
        <select class="form-control" id="areaStatusFilter" onchange="filterAreaBreakdown()">
          <option value="">All statuses</option>
          <option value="hot">Highest Hot</option>
          <option value="warm">Highest Warm</option>
          <option value="cool">Highest Cool</option>
        </select>
        <select class="form-control" id="areaDirFilter" onchange="filterAreaBreakdown()">
          <option value="">All directions</option>
          <?php foreach ($dirMap as $dirCode => $dirInfo): ?>
          <option value="<?= htmlspecialchars(strtolower($dirCode), ENT_QUOTES) ?>"><?= htmlspecialchars($dirCode) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="rank-grid">
        <?php
          $topCards = [
            ['class' => 'hot', 'label' => 'Highest Hot', 'metric' => 'hot', 'row' => $topHot],
            ['class' => 'warm', 'label' => 'Highest Warm', 'metric' => 'warm', 'row' => $topWarm],
            ['class' => 'cool', 'label' => 'Highest Cool', 'metric' => 'cool', 'row' => $topCool],
          ];
        ?>
        <?php foreach ($topCards as $tc): $tr = $tc['row']; ?>
        <div class="rank-card <?= $tc['class'] ?>">
          <div class="rank-label"><?= $tc['label'] ?></div>
          <div class="rank-area"><?= $tr ? htmlspecialchars($tr['area']) : 'No data' ?></div>
          <div class="rank-meta"><?= $tr ? htmlspecialchars($tr['dir_code'] . ' · ' . $tr['zone_code'] . ' · ' . $tr['route']) : 'No submissions found' ?></div>
          <div class="rank-count"><?= $tr ? number_format($tr[$tc['metric']]) : 0 ?></div>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="area-table-wrap">
        <table class="area-rank-table">
          <thead>
            <tr>
              <th>Order</th>
              <th>Area</th>
              <th>Zone Route</th>
              <th>Dominant</th>
              <th>Hot</th>
              <th>Warm</th>
              <th>Cool</th>
              <th>Total</th>
              <th>Map</th>
            </tr>
          </thead>
          <tbody id="areaBreakRows">
            <?php foreach ($areaRows as $i => $ar):
              $mapOk = !empty($ar['latitude']) && !empty($ar['longitude']);
              $mapUrl = $mapOk ? 'https://maps.google.com/?q=' . urlencode($ar['latitude'] . ',' . $ar['longitude']) : '';
              $searchText = strtolower($ar['area'] . ' ' . $ar['zone_code'] . ' ' . $ar['dir_code'] . ' ' . $ar['route']);
            ?>
            <tr class="area-break-row"
                data-search="<?= htmlspecialchars($searchText, ENT_QUOTES) ?>"
                data-status="<?= htmlspecialchars($ar['dominant'], ENT_QUOTES) ?>"
                data-dir="<?= htmlspecialchars(strtolower($ar['dir_code']), ENT_QUOTES) ?>">
              <td><?= $i + 1 ?></td>
              <td>
                <div class="area-name-main"><?= htmlspecialchars($ar['area']) ?></div>
                <div class="area-name-sub"><?= htmlspecialchars($ar['dir_label']) ?></div>
              </td>
              <td>
                <span class="zone-code-chip"><?= htmlspecialchars($ar['dir_code']) ?>·<?= htmlspecialchars($ar['zone_code']) ?></span>
                <div class="area-name-sub"><?= htmlspecialchars($ar['route']) ?></div>
              </td>
              <td><span class="dom-pill <?= $ar['dominant'] ?>"><?= ucfirst($ar['dominant']) ?></span></td>
              <td><span class="vr-chip h"><?= number_format($ar['hot']) ?></span></td>
              <td><span class="vr-chip w"><?= number_format($ar['warm']) ?></span></td>
              <td><span class="vr-chip c"><?= number_format($ar['cool']) ?></span></td>
              <td><?= number_format($ar['total']) ?></td>
              <td>
                <?php if ($mapOk): ?>
                <a class="map-link" href="<?= htmlspecialchars($mapUrl) ?>" target="_blank" rel="noopener">Map</a>
                <?php else: ?>
                <span class="map-link disabled">No GPS</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="break-empty" id="areaBreakEmpty">No area breakdown matches this filter.</div>

      <div class="zone-section-title" style="margin-top:6px">
        Zone Route Details
        <span>Grouped by direction and zone</span>
      </div>

      <?php foreach ($dirMap as $dirCode => $dirInfo): ?>
      <div class="dir-block">
        <div class="dir-header">
          <div class="dir-badge"><?= htmlspecialchars($dirCode) ?></div>
          <div class="dir-label"><?= htmlspecialchars($dirInfo['label']) ?></div>
        </div>

        <div class="zones-grid">
          <?php foreach ($dirInfo['zones'] as $zoneId => $zone):
            $villageCount = count($zone['villages']);
            $collapsed = $villageCount > 5;
          ?>
          <div class="zone-card">
            <div class="zone-card-head">
              <div class="zone-code-chip"><?= htmlspecialchars($zone['code']) ?></div>
              <div class="zone-route"><?= htmlspecialchars($zone['from_location']) ?> → <?= htmlspecialchars($zone['to_location']) ?></div>
              <div class="zone-totals">
                <?php if ($zone['hot']  > 0): ?><span class="zt h">🟢<?= $zone['hot'] ?></span><?php endif; ?>
                <?php if ($zone['warm'] > 0): ?><span class="zt w">🟡<?= $zone['warm'] ?></span><?php endif; ?>
                <?php if ($zone['cold'] > 0): ?><span class="zt c">🔴<?= $zone['cold'] ?></span><?php endif; ?>
                <?php if ($collapsed): ?><button class="zone-toggle" onclick="toggleZone(this)">▼ <?= $villageCount ?></button><?php endif; ?>
              </div>
            </div>
            <div class="zone-card-body <?= $collapsed ? 'collapsed' : '' ?>">
              <?php foreach ($zone['villages'] as $vi):
                $dotClass = ($vi['hot'] >= $vi['warm'] && $vi['hot'] >= $vi['cold']) ? 'hot' : (($vi['warm'] >= $vi['cold']) ? 'warm' : 'cold');
              ?>
              <div class="village-row">
                <div class="vr-dot <?= $dotClass ?>"></div>
                <div class="vr-name"><?= htmlspecialchars($vi['name']) ?></div>
                <div class="vr-chips">
                  <?php if ($vi['hot']  > 0): ?><span class="vr-chip h">🟢<?= $vi['hot'] ?></span><?php endif; ?>
                  <?php if ($vi['warm'] > 0): ?><span class="vr-chip w">🟡<?= $vi['warm'] ?></span><?php endif; ?>
                  <?php if ($vi['cold'] > 0): ?><span class="vr-chip c">🔴<?= $vi['cold'] ?></span><?php endif; ?>
                </div>
                <div class="vr-total"><?= $vi['total'] ?></div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ══ BOTTOM 2-COL: SUBMISSIONS + ACTIVITY ══ -->
    <div class="dash-section-label">Recent</div>
    <div class="dash-bottom">

      <!-- Recent submissions -->
      <div class="dash-panel">
        <div class="dash-panel-title">📋 Recent Submissions</div>
        <?php if ($recentSubs): ?>
          <?php foreach ($recentSubs as $r): ?>
          <div class="sub-item">
            <div class="sub-avatar <?= $r['status'] ?>"><?= strtoupper(substr($r['owner_name'], 0, 2)) ?></div>
            <div class="sub-meta">
              <div class="sub-owner"><?= htmlspecialchars($r['owner_name']) ?></div>
              <div class="sub-by">by <?= htmlspecialchars($r['username']) ?> &nbsp;<span class="s-pill <?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span></div>
            </div>
            <div class="sub-right">
              <div class="sub-zone"><?= htmlspecialchars($r['zone']) ?></div>
              <div class="sub-time"><?= date('d M, h:i A', strtotime($r['display_time'])) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
          <a href="<?= APP_URL ?>/admin/submissions.php" class="view-all">View all submissions →</a>
        <?php else: ?>
          <div class="dash-empty"><div class="dash-empty-icon">📋</div>No submissions yet</div>
        <?php endif; ?>
      </div>

      <!-- Recent activity -->
      <div class="dash-panel">
        <div class="dash-panel-title">⚡ Recent Activity</div>
        <?php if ($recent): ?>
          <?php foreach ($recent as $a): ?>
          <div class="act-item">
            <div class="act-avatar"><?= strtoupper(substr($a['username'], 0, 1)) ?></div>
            <div>
              <div><span class="act-user"><?= htmlspecialchars($a['username']) ?></span> <span class="act-action"><?= htmlspecialchars($a['action']) ?></span></div>
              <div class="act-time"><?= date('d M Y, H:i', strtotime($a['created_at'])) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="dash-empty"><div class="dash-empty-icon">⚡</div>No activity yet</div>
        <?php endif; ?>
      </div>

    </div>

  </div><!-- /main-content -->
</div><!-- /app-wrap -->

<!-- Mobile nav -->
<nav class="mob-nav"><div class="mob-nav-items">
  <a href="dashboard.php" class="mob-nav-item active"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>Home</a>
  <a href="subadmins.php" class="mob-nav-item"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>Admins</a>
  <a href="submissions.php" class="mob-nav-item"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>Data</a>
  <a href="locations.php" class="mob-nav-item"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>Zones</a>
  <a href="reports.php" class="mob-nav-item"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>Reports</a>
</div></nav>

<?php pageFooter(); ?>
<script>
function filterAreaBreakdown() {
  const q = (document.getElementById('areaSearch')?.value || '').trim().toLowerCase();
  const status = document.getElementById('areaStatusFilter')?.value || '';
  const dir = document.getElementById('areaDirFilter')?.value || '';
  let visible = 0;
  document.querySelectorAll('.area-break-row').forEach(row => {
    const okSearch = !q || (row.dataset.search || '').includes(q);
    const okStatus = !status || row.dataset.status === status;
    const okDir = !dir || row.dataset.dir === dir;
    const show = okSearch && okStatus && okDir;
    row.style.display = show ? '' : 'none';
    if (show) visible++;
  });
  const empty = document.getElementById('areaBreakEmpty');
  if (empty) empty.style.display = visible ? 'none' : 'block';
}

/* Zone collapse toggle */
function toggleZone(btn) {
  const body = btn.closest('.zone-card').querySelector('.zone-card-body');
  const open = !body.classList.contains('collapsed');
  body.classList.toggle('collapsed', open);
  btn.textContent = open ? '▼ ' + btn.textContent.replace(/[▲▼]\s*/,'') : '▲ ' + btn.textContent.replace(/[▲▼]\s*/,'');
}
</script>
