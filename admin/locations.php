<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
requireLogin('main_admin');
$db   = getDB();
$user = currentUser();
$msg  = ''; $msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_subdir') {
        try {
            $dirId = (int)$_POST['direction_id'];
            $code  = trim($_POST['code']);
            $from  = trim($_POST['from_location']);
            $to    = trim($_POST['to_location']);
            $stops = array_filter(array_map('trim', explode(',', $_POST['stops'] ?? '')));

            if ($dirId <= 0) throw new Exception("Please select a valid direction.");
            if ($code === '') throw new Exception("Code is required.");
            if ($from === '') throw new Exception("From Location is required.");
            if ($to === '')   throw new Exception("To Location is required.");

            // Prevent duplicate code under same direction
            $exists = $db->prepare("SELECT id FROM sub_directions WHERE direction_id = ? AND code = ?");
            $exists->execute([$dirId, $code]);
            if ($exists->fetch()) {
                throw new Exception("Sub-direction code '$code' already exists in this direction.");
            }

            $db->prepare("INSERT INTO sub_directions (direction_id,code,from_location,to_location) VALUES (?,?,?,?)")
               ->execute([$dirId, $code, $from, $to]);
            $sdId = $db->lastInsertId();
            foreach ($stops as $i => $stop) {
                $db->prepare("INSERT INTO sub_direction_stops (sub_direction_id,place_name,sort_order) VALUES (?,?,?)")
                   ->execute([$sdId, $stop, $i + 1]);
            }

            // PRG: redirect after success to prevent resubmission on refresh
            header("Location: locations.php?msg=" . urlencode("Sub-direction $code added successfully.") . "&type=success");
            exit;

        } catch (Exception $e) {
            $msg     = "Error: " . $e->getMessage();
            $msgType = 'danger';
        }
    }

    if ($action === 'edit_subdir') {
        try {
            $id    = (int)$_POST['sd_id'];
            $code  = trim($_POST['code']);
            $from  = trim($_POST['from_location']);
            $to    = trim($_POST['to_location']);
            $stops = array_filter(array_map('trim', explode(',', $_POST['stops'] ?? '')));

            if ($id <= 0)     throw new Exception("Invalid sub-direction ID.");
            if ($code === '') throw new Exception("Code is required.");
            if ($from === '') throw new Exception("From Location is required.");
            if ($to === '')   throw new Exception("To Location is required.");

            // Prevent duplicate code (exclude self)
            $existing = $db->prepare("SELECT id FROM sub_directions WHERE code = ? AND id != ?");
            $existing->execute([$code, $id]);
            if ($existing->fetch()) {
                throw new Exception("Sub-direction code '$code' already exists.");
            }

            $db->prepare("UPDATE sub_directions SET code=?, from_location=?, to_location=? WHERE id=?")
               ->execute([$code, $from, $to, $id]);

            // Replace stops: delete old, insert new
            $db->prepare("DELETE FROM sub_direction_stops WHERE sub_direction_id = ?")->execute([$id]);
            foreach ($stops as $i => $stop) {
                $db->prepare("INSERT INTO sub_direction_stops (sub_direction_id,place_name,sort_order) VALUES (?,?,?)")
                   ->execute([$id, $stop, $i + 1]);
            }

            // PRG: redirect after success
            header("Location: locations.php?msg=" . urlencode("Zone $code updated successfully.") . "&type=success");
            exit;

        } catch (Exception $e) {
            $msg     = "Error: " . $e->getMessage();
            $msgType = 'danger';
        }
    }

    if ($action === 'delete_subdir') {
        try {
            $id = (int)$_POST['sd_id'];
            if ($id <= 0) throw new Exception("Invalid sub-direction ID.");

            // Fetch code for message before deleting
            $row = $db->prepare("SELECT code FROM sub_directions WHERE id = ?");
            $row->execute([$id]);
            $sd = $row->fetch();
            if (!$sd) throw new Exception("Sub-direction not found.");

            // Delete stops first (FK), then the sub-direction
            $db->prepare("DELETE FROM sub_direction_stops WHERE sub_direction_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM sub_directions WHERE id = ?")->execute([$id]);

            // PRG: redirect after success
            header("Location: locations.php?msg=" . urlencode("Sub-direction {$sd['code']} deleted successfully.") . "&type=success");
            exit;

        } catch (Exception $e) {
            $msg     = "Error: " . $e->getMessage();
            $msgType = 'danger';
        }
    }
}

// Read flash message from PRG redirect
if (empty($msg) && isset($_GET['msg'])) {
    $msg     = htmlspecialchars($_GET['msg']);
    $msgType = (isset($_GET['type']) && $_GET['type'] === 'danger') ? 'danger' : 'success';
}

$dirs    = $db->query("SELECT * FROM directions ORDER BY code")->fetchAll();
$subdirs = $db->query("
    SELECT sd.*, d.code AS dir_code, d.label AS dir_label
    FROM sub_directions sd
    JOIN directions d ON sd.direction_id = d.id
    ORDER BY d.code, sd.code
")->fetchAll();

$stopsBySubdir = [];
if ($subdirs) {
    $ids = array_column($subdirs, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $stopsStmt = $db->prepare("
        SELECT sub_direction_id, place_name
        FROM sub_direction_stops
        WHERE sub_direction_id IN ($in)
        ORDER BY sub_direction_id, sort_order
    ");
    $stopsStmt->execute($ids);
    foreach ($stopsStmt->fetchAll() as $stop) {
        $stopsBySubdir[(int)$stop['sub_direction_id']][] = $stop['place_name'];
    }
}

$subdirsByDir = [];
$totalStops = 0;
foreach ($subdirs as $sd) {
    $subdirsByDir[$sd['dir_code']][] = $sd;
    $totalStops += count($stopsBySubdir[(int)$sd['id']] ?? []);
}
$totalDirections = count($dirs);
$totalZones = count($subdirs);
$avgStops = $totalZones ? round($totalStops / $totalZones, 1) : 0;

pageHead('Locations');
topbar($user['username'], $user['role']);
?>
<style>
.loc-hero{
  display:grid;
  grid-template-columns:minmax(0,1fr) auto;
  gap:16px;
  align-items:start;
  background:#fff;
  border:1px solid var(--c-gray-200);
  border-radius:14px;
  padding:20px;
  margin-bottom:16px;
  box-shadow:var(--shadow);
  position:relative;
  overflow:hidden;
}
.loc-hero::before{content:'';position:absolute;left:0;top:0;bottom:0;width:5px;background:linear-gradient(180deg,#2563eb,#16a34a)}
.loc-kicker{font-size:.68rem;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:var(--c-gray-400);margin-bottom:5px}
.loc-title{font-size:1.45rem;font-weight:900;color:var(--c-gray-900);letter-spacing:-.03em;line-height:1.15}
.loc-sub{font-size:.86rem;color:var(--c-gray-500);margin-top:6px;max-width:720px}
.loc-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
.loc-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:16px}
.loc-stat{
  background:#fff;border:1px solid var(--c-gray-200);border-radius:12px;
  padding:14px;box-shadow:var(--shadow);position:relative;overflow:hidden;
}
.loc-stat::before{content:'';position:absolute;left:0;right:0;top:0;height:3px;background:var(--accent,#2563eb)}
.loc-stat.blue{--accent:#2563eb}.loc-stat.green{--accent:#16a34a}.loc-stat.amber{--accent:#d97706}.loc-stat.slate{--accent:#475569}
.loc-stat-val{font-size:1.9rem;font-weight:900;line-height:1;color:var(--c-gray-900);font-variant-numeric:tabular-nums}
.loc-stat.blue .loc-stat-val{color:#1d4ed8}.loc-stat.green .loc-stat-val{color:#15803d}.loc-stat.amber .loc-stat-val{color:#b45309}
.loc-stat-label{font-size:.66rem;font-weight:900;letter-spacing:.09em;text-transform:uppercase;color:var(--c-gray-400);margin-top:7px}
.loc-tools{
  display:grid;grid-template-columns:minmax(180px,1fr) 180px auto;gap:10px;
  background:#fff;border:1px solid var(--c-gray-200);border-radius:12px;
  padding:12px;margin-bottom:16px;box-shadow:var(--shadow);
}
.loc-tools .btn{justify-content:center}
.dir-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:14px}
.dir-card{
  background:#fff;border:1px solid var(--c-gray-200);border-radius:14px;
  box-shadow:var(--shadow);overflow:hidden;min-width:0;
}
.dir-head{
  display:flex;align-items:flex-start;justify-content:space-between;gap:10px;
  padding:14px 16px;background:#f8fafc;border-bottom:1px solid var(--c-gray-200);
}
.dir-code{display:inline-flex;align-items:center;justify-content:center;min-width:46px;height:34px;border-radius:8px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;font-weight:900;letter-spacing:.04em}
.dir-name{font-size:.96rem;font-weight:800;color:var(--c-gray-900);line-height:1.25}
.dir-meta{font-size:.72rem;color:var(--c-gray-500);margin-top:2px}
.dir-count{font-size:.7rem;font-weight:800;color:#15803d;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:999px;padding:3px 8px;white-space:nowrap}
.zone-list{display:flex;flex-direction:column;gap:9px;padding:12px}
.zone-card{
  border:1px solid var(--c-gray-200);border-radius:10px;padding:11px;background:#fff;
  transition:border-color .15s,box-shadow .15s,transform .15s;
}
.zone-card:hover{border-color:#bfdbfe;box-shadow:var(--shadow-md);transform:translateY(-1px)}
.zone-top{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px}
.zone-code{font-size:.76rem;font-weight:900;color:#1d4ed8;background:#eff6ff;border:1px solid #bfdbfe;border-radius:7px;padding:3px 8px;letter-spacing:.04em}
.zone-actions{display:flex;gap:5px}
.zone-route{display:grid;grid-template-columns:minmax(0,1fr) auto minmax(0,1fr);align-items:center;gap:8px;margin-bottom:8px}
.route-point{min-width:0}
.route-label{font-size:.58rem;font-weight:900;text-transform:uppercase;letter-spacing:.1em;color:var(--c-gray-400)}
.route-value{font-size:.83rem;font-weight:800;color:var(--c-gray-800);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.route-arrow{color:var(--c-gray-400);font-weight:900}
.stop-row{display:flex;flex-wrap:wrap;gap:5px;margin-top:8px}
.stop-chip{font-size:.68rem;font-weight:700;background:#f3f4f6;color:#4b5563;border:1px solid #e5e7eb;border-radius:999px;padding:2px 7px}
.stop-more{background:#fffbeb;color:#92400e;border-color:#fde68a}
.no-zones{padding:22px;text-align:center;color:var(--c-gray-400);font-size:.84rem;border:1px dashed var(--c-gray-200);border-radius:10px;background:#fafafa}
.loc-empty{display:none;grid-column:1/-1;background:#fff;border:1px dashed var(--c-gray-300);border-radius:12px;padding:28px;text-align:center;color:var(--c-gray-500)}
.danger-title{color:#dc2626!important}
.delete-copy{margin:10px 0 18px;color:var(--c-gray-600);line-height:1.55}

@media(max-width:820px){
  .loc-hero{grid-template-columns:1fr}
  .loc-actions{justify-content:flex-start}
  .loc-stats{grid-template-columns:repeat(2,1fr)}
  .loc-tools{grid-template-columns:1fr}
}
@media(max-width:480px){
  .loc-stats,.dir-grid{grid-template-columns:1fr}
  .zone-route{grid-template-columns:1fr;gap:4px}
  .route-arrow{display:none}
}
</style>
<div class="app-wrap">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="main-content">
    <?php if ($msg): alert(htmlspecialchars($msg), $msgType); endif; ?>

    <div class="loc-hero">
      <div>
        <div class="loc-kicker">Location Management</div>
        <div class="loc-title">Zones, routes and stops</div>
        <div class="loc-sub">Manage direction routes used by submissions, reports and office workflows. Search quickly, filter by direction, and update route stops from one clear screen.</div>
      </div>
      <div class="loc-actions">
        <button class="btn btn-primary" onclick="openModal('addSdModal')">
          <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Add Zone
        </button>
      </div>
    </div>

    <div class="loc-stats">
      <div class="loc-stat blue"><div class="loc-stat-val"><?= number_format($totalDirections) ?></div><div class="loc-stat-label">Directions</div></div>
      <div class="loc-stat green"><div class="loc-stat-val"><?= number_format($totalZones) ?></div><div class="loc-stat-label">Zones</div></div>
      <div class="loc-stat amber"><div class="loc-stat-val"><?= number_format($totalStops) ?></div><div class="loc-stat-label">Stops / Villages</div></div>
      <div class="loc-stat slate"><div class="loc-stat-val"><?= number_format($avgStops, 1) ?></div><div class="loc-stat-label">Avg Stops / Zone</div></div>
    </div>

    <div class="loc-tools">
      <input class="form-control" type="search" id="locSearch" placeholder="Search zone, route, stop or direction..." oninput="filterLocations()">
      <select class="form-control" id="dirFilter" onchange="filterLocations()">
        <option value="">All directions</option>
        <?php foreach ($dirs as $dir): ?>
        <option value="<?= htmlspecialchars(strtolower($dir['code']), ENT_QUOTES) ?>"><?= htmlspecialchars($dir['code']) ?> - <?= htmlspecialchars($dir['label']) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="button" class="btn btn-ghost" onclick="clearLocationFilters()">Clear</button>
    </div>

    <div class="dir-grid" id="dirGrid">
      <?php foreach ($dirs as $dir):
        $subs = $subdirsByDir[$dir['code']] ?? [];
        $dirSearchParts = [$dir['code'], $dir['label']];
        foreach ($subs as $sdRow) {
            $dirSearchParts[] = $sdRow['code'];
            $dirSearchParts[] = $sdRow['from_location'];
            $dirSearchParts[] = $sdRow['to_location'];
            $dirSearchParts[] = implode(' ', $stopsBySubdir[(int)$sdRow['id']] ?? []);
        }
        $dirSearch = strtolower(implode(' ', $dirSearchParts));
      ?>
      <div class="dir-card" data-dir="<?= htmlspecialchars(strtolower($dir['code']), ENT_QUOTES) ?>" data-search="<?= htmlspecialchars($dirSearch, ENT_QUOTES) ?>">
        <div class="dir-head">
          <div style="display:flex;gap:10px;align-items:flex-start;min-width:0">
            <div class="dir-code"><?= htmlspecialchars($dir['code']) ?></div>
            <div style="min-width:0">
              <div class="dir-name"><?= htmlspecialchars($dir['label']) ?></div>
              <div class="dir-meta"><?= count($subs) ?> zone<?= count($subs) === 1 ? '' : 's' ?> configured</div>
            </div>
          </div>
          <div class="dir-count"><?= count($subs) ?> routes</div>
        </div>
        <?php if ($subs): ?>
        <div class="zone-list">
          <?php foreach ($subs as $sd):
            $stopNames = $stopsBySubdir[(int)$sd['id']] ?? [];
            $stopsStr  = implode(', ', $stopNames);
            $shownStops = array_slice($stopNames, 0, 5);
            $hiddenStops = max(0, count($stopNames) - count($shownStops));
            $zoneSearch = strtolower(implode(' ', [
                $sd['code'],
                $sd['from_location'],
                $sd['to_location'],
                $stopsStr,
            ]));
          ?>
          <div class="zone-card" data-zone-search="<?= htmlspecialchars($zoneSearch, ENT_QUOTES) ?>">
            <div class="zone-top">
              <span class="zone-code"><?= htmlspecialchars($sd['code']) ?></span>
              <div class="zone-actions">
                <button
                  class="btn-icon"
                  type="button"
                  title="Edit"
                  style="color:var(--c-primary)"
                  onclick="openEditModal(<?= $sd['id'] ?>, <?= htmlspecialchars(json_encode($sd['code'])) ?>, <?= htmlspecialchars(json_encode($sd['from_location'])) ?>, <?= htmlspecialchars(json_encode($sd['to_location'])) ?>, <?= htmlspecialchars(json_encode($stopsStr)) ?>)"
                >
                  <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </button>
                <button
                  class="btn-icon"
                  type="button"
                  title="Delete"
                  style="color:#e53e3e"
                  onclick="confirmDelete(<?= $sd['id'] ?>, <?= htmlspecialchars(json_encode($sd['code'])) ?>)"
                >
                  <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                </button>
              </div>
            </div>

            <div class="zone-route">
              <div class="route-point">
                <div class="route-label">From</div>
                <div class="route-value"><?= htmlspecialchars($sd['from_location']) ?></div>
              </div>
              <div class="route-arrow">→</div>
              <div class="route-point">
                <div class="route-label">To</div>
                <div class="route-value"><?= htmlspecialchars($sd['to_location']) ?></div>
              </div>
            </div>
            <?php if ($stopNames): ?>
            <div class="stop-row">
              <?php foreach ($shownStops as $s): ?>
                <span class="stop-chip"><?= htmlspecialchars($s) ?></span>
              <?php endforeach; ?>
              <?php if ($hiddenStops > 0): ?><span class="stop-chip stop-more">+<?= $hiddenStops ?> more</span><?php endif; ?>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
          <div class="zone-list"><div class="no-zones">No zones configured for this direction yet.</div></div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      <div class="loc-empty" id="locEmpty">No directions or zones match your filter.</div>
    </div>
  </div>
</div>

<!-- Add Sub-Direction Modal -->
<div class="modal-overlay" id="addSdModal">
  <div class="modal">
    <div class="modal-title">Add Zone Route</div>
    <form method="POST">
      <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="add_subdir">
      <div class="form-group">
        <label class="form-label">Direction <span class="req">*</span></label>
        <select class="form-control" name="direction_id" required>
          <option value="">Select direction</option>
          <?php foreach ($dirs as $d): ?>
          <option value="<?= (int)$d['id'] ?>"><?= htmlspecialchars($d['code']) ?> — <?= htmlspecialchars($d['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Zone Code <span class="req">*</span></label>
          <input class="form-control" type="text" name="code" required placeholder="e.g. NE3">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">From Location <span class="req">*</span></label>
          <input class="form-control" type="text" name="from_location" required placeholder="Starting point">
        </div>
        <div class="form-group">
          <label class="form-label">To Location <span class="req">*</span></label>
          <input class="form-control" type="text" name="to_location" required placeholder="End point">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Stops / Villages <small style="color:var(--c-gray-400)">(comma separated)</small></label>
        <input class="form-control" type="text" name="stops" placeholder="Gandhi Nagar, Anna Street, Nehru Colony">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('addSdModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Add Zone</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Sub-Direction Modal -->
<div class="modal-overlay" id="editSdModal">
  <div class="modal">
    <div class="modal-title">Edit Zone Route</div>
    <form method="POST">
      <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="edit_subdir">
      <input type="hidden" name="sd_id" id="edit_sd_id">
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Zone Code <span class="req">*</span></label>
          <input class="form-control" type="text" name="code" id="edit_code" required placeholder="e.g. NE3">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">From Location <span class="req">*</span></label>
          <input class="form-control" type="text" name="from_location" id="edit_from" required placeholder="Starting point">
        </div>
        <div class="form-group">
          <label class="form-label">To Location <span class="req">*</span></label>
          <input class="form-control" type="text" name="to_location" id="edit_to" required placeholder="End point">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Stops / Villages <small style="color:var(--c-gray-400)">(comma separated)</small></label>
        <input class="form-control" type="text" name="stops" id="edit_stops" placeholder="Gandhi Nagar, Anna Street, Nehru Colony">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('editSdModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- Delete Confirm Modal -->
<div class="modal-overlay" id="deleteSdModal">
  <div class="modal" style="max-width:400px">
    <div class="modal-title danger-title">Delete Zone Route</div>
    <p class="delete-copy">
      Are you sure you want to delete <strong id="delete_label"></strong>? This will also remove all its stops and cannot be undone.
    </p>
    <form method="POST">
      <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="delete_subdir">
      <input type="hidden" name="sd_id" id="delete_sd_id">
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('deleteSdModal')">Cancel</button>
        <button type="submit" class="btn btn-danger">Delete</button>
      </div>
    </form>
  </div>
</div>

<nav class="mob-nav">
  <div class="mob-nav-items">
    <a href="dashboard.php" class="mob-nav-item"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>Home</a>
    <a href="subadmins.php" class="mob-nav-item"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>Admins</a>
    <a href="submissions.php" class="mob-nav-item"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>Data</a>
    <a href="locations.php" class="mob-nav-item active"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>Zones</a>
    <a href="reports.php" class="mob-nav-item"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>Reports</a>
  </div>
</nav>

<script>
function openEditModal(id, code, from, to, stops) {
    document.getElementById('edit_sd_id').value = id;
    document.getElementById('edit_code').value  = code;
    document.getElementById('edit_from').value  = from;
    document.getElementById('edit_to').value    = to;
    document.getElementById('edit_stops').value = stops;
    openModal('editSdModal');
}

function confirmDelete(id, code) {
    document.getElementById('delete_sd_id').value    = id;
    document.getElementById('delete_label').textContent = code;
    openModal('deleteSdModal');
}

function filterLocations() {
    const q = (document.getElementById('locSearch')?.value || '').trim().toLowerCase();
    const dir = document.getElementById('dirFilter')?.value || '';
    let visible = 0;

    document.querySelectorAll('.dir-card').forEach(card => {
        const okDir = !dir || card.dataset.dir === dir;
        const dirMatches = !q || (card.dataset.search || '').includes(q);
        let zoneMatches = 0;

        card.querySelectorAll('.zone-card').forEach(zone => {
            const zoneOk = !q || (zone.dataset.zoneSearch || '').includes(q);
            zone.style.display = zoneOk ? '' : 'none';
            if (zoneOk) zoneMatches++;
        });

        if (q && dirMatches && zoneMatches === 0) {
            card.querySelectorAll('.zone-card').forEach(zone => zone.style.display = '');
            zoneMatches = card.querySelectorAll('.zone-card').length;
        }

        const show = okDir && (!q || dirMatches || zoneMatches > 0);
        card.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    const empty = document.getElementById('locEmpty');
    if (empty) empty.style.display = visible ? 'none' : 'block';
}

function clearLocationFilters() {
    const search = document.getElementById('locSearch');
    const dir = document.getElementById('dirFilter');
    if (search) search.value = '';
    if (dir) dir.value = '';
    filterLocations();
}
</script>

<?php pageFooter(); ?>
