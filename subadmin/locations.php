<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
requireLogin('sub_admin');
$db   = getDB();
$user = currentUser();

$zones = $db->prepare("SELECT sd.*, d.code as dir_code, d.label as dir_label FROM sub_admin_locations sal JOIN sub_directions sd ON sal.sub_direction_id=sd.id JOIN directions d ON sd.direction_id=d.id WHERE sal.sub_admin_id=? ORDER BY d.code, sd.code");
$zones->execute([$user['id']]);
$zones = $zones->fetchAll();

pageHead('My Zones');
topbar($user['username'], $user['role']);
?>
<div class="app-wrap">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="main-content">
    <div class="page-header">
      <div class="page-title">My Assigned Zones</div>
      <div class="page-sub"><?= count($zones) ?> zone(s) assigned to you</div>
    </div>

    <?php if ($zones): ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px">
      <?php foreach ($zones as $z):
        $stops = $db->prepare("SELECT place_name FROM sub_direction_stops WHERE sub_direction_id=? ORDER BY sort_order");
        $stops->execute([$z['id']]);
        $stopNames = $stops->fetchAll(PDO::FETCH_COLUMN);

        $myCount = $db->prepare("SELECT COUNT(*) FROM submissions WHERE submitted_by=? AND sub_direction_id=?");
        $myCount->execute([$user['id'], $z['id']]);
        $myCount = $myCount->fetchColumn();

        // Status breakdown
        $breakdown = $db->prepare("SELECT status, COUNT(*) as cnt FROM submissions WHERE submitted_by=? AND sub_direction_id=? GROUP BY status");
        $breakdown->execute([$user['id'], $z['id']]);
        $bk = ['hot'=>0,'warm'=>0,'cold'=>0,'enquiry'=>0,'billed'=>0,'outside_purchase'=>0,'others'=>0];
        foreach ($breakdown->fetchAll() as $b) {
            $st = (string)($b['status'] ?? '');
            if (!array_key_exists($st, $bk)) $bk[$st] = 0;
            $bk[$st] = (int)$b['cnt'];
        }
      ?>
      <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
          <span class="badge badge-blue" style="font-size:.9rem;padding:4px 10px"><?= $z['dir_code'] ?> › <?= htmlspecialchars($z['code']) ?></span>
          <span class="badge badge-gray"><?= $myCount ?> entries</span>
        </div>
        <div style="font-size:.82rem;color:var(--c-gray-600);margin-bottom:8px">
          <strong style="color:var(--c-gray-700)">From:</strong> <?= htmlspecialchars($z['from_location']) ?><br>
          <strong style="color:var(--c-gray-700)">To:</strong> <?= htmlspecialchars($z['to_location']) ?>
        </div>
        <?php if ($myCount > 0): ?>
        <div style="display:flex;gap:6px;margin-bottom:8px;flex-wrap:wrap">
          <?php if ($bk['hot']): ?><span class="badge badge-hot" style="font-size:.75rem"><span class="badge-dot"></span> <?= $bk['hot'] ?> Hot</span><?php endif; ?>
          <?php if ($bk['warm']): ?><span class="badge badge-warm" style="font-size:.75rem"><span class="badge-dot"></span> <?= $bk['warm'] ?> Warm</span><?php endif; ?>
          <?php if ($bk['cold']): ?><span class="badge badge-cold" style="font-size:.75rem"><span class="badge-dot"></span> <?= $bk['cold'] ?> Cold</span><?php endif; ?>
          <?php if ($bk['enquiry']): ?><span class="badge badge-gray" style="font-size:.75rem"><?= $bk['enquiry'] ?> Enquiry</span><?php endif; ?>
          <?php if ($bk['billed']): ?><span class="badge badge-hot" style="font-size:.75rem"><?= $bk['billed'] ?> Billed</span><?php endif; ?>
          <?php if ($bk['outside_purchase']): ?><span class="badge badge-gray" style="font-size:.75rem"><?= $bk['outside_purchase'] ?> Outside Purchase</span><?php endif; ?>
          <?php if ($bk['others']): ?><span class="badge badge-gray" style="font-size:.75rem"><?= $bk['others'] ?> Others</span><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if ($stopNames): ?>
        <div style="font-size:.75rem;color:var(--c-gray-500);margin-bottom:8px">Stops:</div>
        <div style="display:flex;flex-wrap:wrap;gap:4px;margin-bottom:10px">
          <?php foreach ($stopNames as $s): ?>
            <span style="font-size:.72rem;background:var(--c-gray-100);color:var(--c-gray-600);padding:2px 7px;border-radius:4px"><?= htmlspecialchars($s) ?></span>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div style="display:flex;gap:6px">
          <a href="submit.php" class="btn btn-primary btn-sm" style="flex:1;justify-content:center">+ Add Submission</a>
          <a href="my_data.php?zone=<?= $z['id'] ?>" class="btn btn-ghost btn-sm" style="flex:1;justify-content:center">View Data</a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
      <div class="alert alert-warn">No zones assigned to you yet. Contact main admin.</div>
    <?php endif; ?>
  </div>
</div>

<nav class="mob-nav">
  <div class="mob-nav-items">
    <a href="dashboard.php" class="mob-nav-item"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>Home</a>
    <a href="submit.php" class="mob-nav-item"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>Submit</a>
    <a href="my_data.php" class="mob-nav-item"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>My Data</a>
    <a href="locations.php" class="mob-nav-item active"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>Zones</a>
  </div>
</nav>
<?php pageFooter(); ?>
