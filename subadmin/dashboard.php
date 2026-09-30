<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

requireLogin('sub_admin');

// Auto-add device_submitted_at column if not yet migrated
ensureDeviceTimeColumn();

$db   = getDB();
$user = currentUser();

// Lock check
$lockCheck = $db->prepare("SELECT is_locked FROM users WHERE id = ? AND role = 'sub_admin'");
$lockCheck->execute([$user['id']]);
$latestLock = $lockCheck->fetchColumn();
if ($latestLock === false || (int)$latestLock === 1) {
    logActivity($user['id'], 'auto_logout_locked', "Auto-logged out locked sub-admin id={$user['id']}");
    if (function_exists('forceLogoutUser')) forceLogoutUser($user['id']);
    session_unset(); session_destroy();
    header('Location: ' . APP_URL . '/index.php?reason=locked'); exit;
}

// Determine if device_submitted_at column exists
$hasDeviceTime = columnExists('submissions', 'device_submitted_at');
$timeExpr      = $hasDeviceTime ? "COALESCE(s.device_submitted_at, s.submitted_at)" : "s.submitted_at";

// Overall counts
$myTotal = $db->prepare("SELECT COUNT(*) FROM submissions WHERE submitted_by=?");
$myTotal->execute([$user['id']]); $myTotal = $myTotal->fetchColumn();
$myHot = $db->prepare("SELECT COUNT(*) FROM submissions WHERE submitted_by=? AND status='hot'");
$myHot->execute([$user['id']]); $myHot = $myHot->fetchColumn();
$myWarm = $db->prepare("SELECT COUNT(*) FROM submissions WHERE submitted_by=? AND status='warm'");
$myWarm->execute([$user['id']]); $myWarm = $myWarm->fetchColumn();
$myCold = $db->prepare("SELECT COUNT(*) FROM submissions WHERE submitted_by=? AND status='cold'");
$myCold->execute([$user['id']]); $myCold = $myCold->fetchColumn();

// Assigned zones with village breakdown
$zonesStmt = $db->prepare("
    SELECT sd.id, sd.code, sd.from_location, sd.to_location,
           d.code AS dir_code, d.label AS dir_label
    FROM   sub_admin_locations sal
    JOIN   sub_directions sd ON sal.sub_direction_id = sd.id
    JOIN   directions d      ON sd.direction_id = d.id
    WHERE  sal.sub_admin_id = ?
    ORDER  BY d.code, sd.code
");
$zonesStmt->execute([$user['id']]);
$assignedZones = $zonesStmt->fetchAll();

$zoneData = [];
foreach ($assignedZones as $z) {
    $stopsStmt = $db->prepare("SELECT place_name FROM sub_direction_stops WHERE sub_direction_id=? ORDER BY sort_order");
    $stopsStmt->execute([$z['id']]);
    $stops = $stopsStmt->fetchAll(PDO::FETCH_COLUMN);

    $villageStmt = $db->prepare("
        SELECT village_city_town,
               SUM(status='hot')  AS hot,
               SUM(status='warm') AS warm,
               SUM(status='cold') AS cold,
               COUNT(*)           AS total
        FROM   submissions
        WHERE  submitted_by=? AND sub_direction_id=?
        GROUP  BY village_city_town
        ORDER  BY hot DESC, warm DESC, cold ASC
    ");
    $villageStmt->execute([$user['id'], $z['id']]);
    $villages = $villageStmt->fetchAll();

    $zTot = $db->prepare("SELECT COUNT(*), SUM(status='hot'), SUM(status='warm'), SUM(status='cold') FROM submissions WHERE submitted_by=? AND sub_direction_id=?");
    $zTot->execute([$user['id'], $z['id']]);
    $row = $zTot->fetch(PDO::FETCH_NUM);

    $zoneData[] = ['zone' => $z, 'stops' => $stops, 'villages' => $villages,
                   'total' => (int)($row[0]??0), 'hot' => (int)($row[1]??0),
                   'warm'  => (int)($row[2]??0), 'cold' => (int)($row[3]??0)];
}

// Recent submissions
$recent = $db->query("
    SELECT s.owner_name, s.owner_number, s.village_city_town, s.status, s.submitted_at,
           " . ($hasDeviceTime ? "s.device_submitted_at," : "") . "
           sd.code as zone
    FROM   submissions s
    JOIN   sub_directions sd ON s.sub_direction_id = sd.id
    WHERE  s.submitted_by = {$user['id']}
    ORDER  BY $timeExpr DESC LIMIT 5
")->fetchAll();

// Pending removal requests
$pendingRemove = $db->prepare("SELECT COUNT(*) FROM submission_remove_requests srr JOIN submissions s ON srr.submission_id=s.id WHERE s.submitted_by=? AND srr.status='pending'");
$pendingRemove->execute([$user['id']]); $pendingRemove = $pendingRemove->fetchColumn();

// Password expiry
$expStmt = $db->prepare("SELECT password_expires_at FROM users WHERE id=?");
$expStmt->execute([$user['id']]);
$expiry    = $expStmt->fetchColumn();
$expiresIn = $expiry ? max(0, strtotime($expiry) - time()) : 0;

pageHead('My Dashboard');
topbar($user['username'], $user['role']);
?>
<div class="app-wrap">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="main-content">
    <div class="page-header">
      <div class="page-title">My Dashboard</div>
      <div class="page-sub">Hello, <?= htmlspecialchars($user['username']) ?> 👋</div>
    </div>

    <?php if ($expiresIn > 0 && $expiresIn < 3600): ?>
    <div class="alert alert-warn">⚠ Your password expires in <strong><?= round($expiresIn/60) ?> minutes</strong>. Contact main admin.</div>
    <?php elseif (!$expiry || strtotime($expiry) < time()): ?>
    <div class="alert alert-error">⛔ Your password has expired. Contact main admin to renew.</div>
    <?php endif; ?>

    <?php if ($pendingRemove > 0): ?>
    <div class="alert" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:8px;padding:12px 16px;margin-bottom:14px;display:flex;align-items:center;justify-content:space-between;gap:12px">
      <span>⏳ <strong><?= $pendingRemove ?></strong> removal request<?= $pendingRemove > 1 ? 's' : '' ?> awaiting admin approval.</span>
      <a href="my_data.php" class="btn btn-sm" style="background:#f59e0b;color:#fff;border:none">View</a>
    </div>
    <?php endif; ?>

    <div class="stat-grid">
      <div class="stat-card blue"><div class="stat-val"><?= $myTotal ?></div><div class="stat-label">Total Submitted</div></div>
      <div class="stat-card green"><div class="stat-val"><?= $myHot ?></div><div class="stat-label">🟢 Hot Leads</div></div>
      <div class="stat-card warn"><div class="stat-val"><?= $myWarm ?></div><div class="stat-label">🟡 Warm Leads</div></div>
      <div class="stat-card red"><div class="stat-val"><?= $myCold ?></div><div class="stat-label">🔴 Cold Leads</div></div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:20px">
      <a href="submit.php" class="card" style="text-decoration:none;display:flex;align-items:center;gap:14px;cursor:pointer;transition:box-shadow .15s;border:1.5px solid var(--c-primary)">
        <div style="width:48px;height:48px;background:var(--c-primary-light);border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <svg width="22" height="22" fill="none" stroke="var(--c-primary)" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        </div>
        <div><div style="font-weight:600;color:var(--c-gray-900)">New Submission</div><div style="font-size:.82rem;color:var(--c-gray-500)">Submit field data</div></div>
      </a>
      <a href="my_data.php" class="card" style="text-decoration:none;display:flex;align-items:center;gap:14px;cursor:pointer;transition:box-shadow .15s">
        <div style="width:48px;height:48px;background:var(--c-gray-100);border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <svg width="22" height="22" fill="none" stroke="var(--c-gray-600)" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        </div>
        <div><div style="font-weight:600;color:var(--c-gray-900)">My Data</div><div style="font-size:.82rem;color:var(--c-gray-500)">View &amp; edit entries</div></div>
      </a>
    </div>

    <!-- Zone breakdown -->
    <?php if ($zoneData): ?>
    <div class="card" style="margin-bottom:20px">
      <div class="card-title">
        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
        My Assigned Zones — Area Breakdown
      </div>
      <?php foreach ($zoneData as $zd): ?>
      <div style="border:1px solid var(--c-gray-200);border-radius:8px;margin-bottom:14px;overflow:hidden">
        <div style="background:var(--c-primary-light);padding:12px 16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
          <div style="display:flex;align-items:center;gap:10px">
            <span class="badge badge-blue" style="font-size:.9rem;padding:4px 12px"><?= htmlspecialchars($zd['zone']['dir_code']) ?> › <?= htmlspecialchars($zd['zone']['code']) ?></span>
            <span style="font-size:.85rem;color:var(--c-gray-700)"><?= htmlspecialchars($zd['zone']['from_location']) ?> → <?= htmlspecialchars($zd['zone']['to_location']) ?></span>
          </div>
          <div style="display:flex;gap:8px">
            <span style="font-size:.78rem;font-weight:600;color:var(--c-success)">🟢 <?= $zd['hot'] ?></span>
            <span style="font-size:.78rem;font-weight:600;color:var(--c-warn)">🟡 <?= $zd['warm'] ?></span>
            <span style="font-size:.78rem;font-weight:600;color:var(--c-danger)">🔴 <?= $zd['cold'] ?></span>
            <span class="badge badge-gray"><?= $zd['total'] ?> total</span>
          </div>
        </div>
        <?php if ($zd['villages']): ?>
        <?php foreach ($zd['villages'] as $vi):
          $vHot = (int)$vi['hot']; $vWarm = (int)$vi['warm']; $vCold = (int)$vi['cold'];
          if ($vHot >= $vWarm && $vHot >= $vCold) $dotColor = 'var(--c-success)';
          elseif ($vWarm >= $vHot && $vWarm >= $vCold) $dotColor = 'var(--c-warn)';
          else $dotColor = 'var(--c-danger)';
        ?>
        <div style="display:flex;align-items:center;padding:10px 16px;border-bottom:1px solid var(--c-gray-100);gap:12px">
          <div style="width:9px;height:9px;border-radius:50%;background:<?= $dotColor ?>;flex-shrink:0"></div>
          <div style="flex:1;font-size:.88rem;font-weight:500;color:var(--c-gray-800)"><?= htmlspecialchars($vi['village_city_town']) ?></div>
          <div style="display:flex;gap:8px">
            <?php if ($vHot > 0): ?><span style="font-size:.77rem;font-weight:600;color:var(--c-success);background:#f0fdf4;padding:2px 8px;border-radius:99px">🟢 <?= $vHot ?></span><?php endif; ?>
            <?php if ($vWarm > 0): ?><span style="font-size:.77rem;font-weight:600;color:var(--c-warn);background:#fffbeb;padding:2px 8px;border-radius:99px">🟡 <?= $vWarm ?></span><?php endif; ?>
            <?php if ($vCold > 0): ?><span style="font-size:.77rem;font-weight:600;color:var(--c-danger);background:#fef2f2;padding:2px 8px;border-radius:99px">🔴 <?= $vCold ?></span><?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
        <?php else: ?>
        <div style="padding:14px 16px;text-align:center;color:var(--c-gray-400);font-size:.85rem">No submissions yet. <a href="submit.php">Add one →</a></div>
        <?php endif; ?>
        <?php if ($zd['stops']): ?>
        <div style="padding:8px 16px;background:var(--c-gray-50);display:flex;flex-wrap:wrap;gap:5px;border-top:1px solid var(--c-gray-100)">
          <span style="font-size:.72rem;color:var(--c-gray-400);margin-right:4px">Stops:</span>
          <?php foreach ($zd['stops'] as $stop): ?>
          <span style="font-size:.72rem;background:#fff;border:1px solid var(--c-gray-200);color:var(--c-gray-600);padding:2px 7px;border-radius:4px"><?= htmlspecialchars($stop) ?></span>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="alert alert-warn" style="margin-bottom:20px">No zones assigned yet. Contact main admin.</div>
    <?php endif; ?>

    <!-- Recent submissions -->
    <div class="card">
      <div class="card-title">Recent Submissions</div>
      <?php if ($recent): ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Owner</th><th>Village / City</th><th>Status</th><th>Zone</th><th>Date &amp; Time</th></tr></thead>
          <tbody>
          <?php foreach ($recent as $r):
            $dt = $hasDeviceTime && !empty($r['device_submitted_at']) ? $r['device_submitted_at'] : $r['submitted_at'];
          ?>
          <tr>
            <td><strong><?= htmlspecialchars($r['owner_name']) ?></strong><div style="font-size:.78rem;color:var(--c-gray-500)"><?= htmlspecialchars($r['owner_number']) ?></div></td>
            <td><?= htmlspecialchars($r['village_city_town']) ?></td>
            <td><span class="badge badge-<?= $r['status'] ?>"><span class="badge-dot"></span> <?= ucfirst($r['status']) ?></span></td>
            <td><span class="badge badge-blue"><?= htmlspecialchars($r['zone']) ?></span></td>
            <td style="font-size:.8rem;white-space:nowrap">
              <span class="js-dt-date" data-ts="<?= strtotime($dt) ?>"></span><br>
              <span class="js-dt-time" data-ts="<?= strtotime($dt) ?>" style="color:var(--c-gray-400)"></span>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div style="margin-top:12px"><a href="my_data.php" class="btn btn-ghost btn-sm">View all →</a></div>
      <?php else: ?>
      <div class="empty-state"><p>No submissions yet. <a href="submit.php">Submit your first record.</a></p></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<nav class="mob-nav">
  <div class="mob-nav-items">
    <a href="dashboard.php" class="mob-nav-item active"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>Home</a>
    <a href="submit.php" class="mob-nav-item"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>Submit</a>
    <a href="my_data.php" class="mob-nav-item"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>My Data</a>
    <a href="locations.php" class="mob-nav-item"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>Zones</a>
  </div>
</nav>

<script>
// Render submission times using device local timezone
const MONTHS_D = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
function pad2(n){ return String(n).padStart(2,'0'); }
document.querySelectorAll('.js-dt-date[data-ts]').forEach(el => {
  const d = new Date(parseInt(el.dataset.ts,10)*1000);
  el.textContent = pad2(d.getDate())+' '+MONTHS_D[d.getMonth()]+' '+d.getFullYear();
});
document.querySelectorAll('.js-dt-time[data-ts]').forEach(el => {
  const d = new Date(parseInt(el.dataset.ts,10)*1000);
  const h12 = d.getHours()%12||12, ampm = d.getHours()>=12?'PM':'AM';
  el.textContent = pad2(h12)+':'+pad2(d.getMinutes())+' '+ampm;
});
</script>
<?php pageFooter(); ?>