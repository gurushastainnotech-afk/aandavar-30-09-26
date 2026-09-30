<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
requireLogin('office_admin');

$db   = getDB();
$user = currentUser();
$isMainAdmin = (($user['role'] ?? '') === 'main_admin');
$msg  = ''; $msgType = 'success';

function syncOfficeAdminReportLinks(PDO $db, ?int $subAdminId = null): int {
    $scopeSql = $subAdminId ? ' AND sa.id = ?' : '';
    $scopeParams = $subAdminId ? [$subAdminId] : [];

    try {
        $clearSql = "
            DELETE r
            FROM office_admin_reports r
            LEFT JOIN users oa ON oa.id = r.office_admin_id AND oa.role = 'office_admin'
            LEFT JOIN submissions s ON s.id = r.submission_id
            LEFT JOIN users sa ON sa.id = s.submitted_by AND sa.role = 'sub_admin'
            WHERE (oa.id IS NULL OR s.id IS NULL OR sa.id IS NULL)
              $scopeSql
        ";
        $clear = $db->prepare($clearSql);
        $clear->execute($scopeParams);

        $updateSql = "
            UPDATE office_admin_reports r
            JOIN users oa ON oa.id = r.office_admin_id AND oa.role = 'office_admin'
            JOIN submissions s ON s.id = r.submission_id
            JOIN users sa ON sa.id = s.submitted_by AND sa.role = 'sub_admin'
            SET r.report_type = CASE WHEN s.order_completed = 1 THEN 'order_completed' ELSE 'status_update' END,
                r.report_data = JSON_OBJECT(
                    'linked_from', 'admin_subadmins',
                    'sub_admin_id', sa.id,
                    'sub_admin', sa.username,
                    'status', s.status,
                    'visible_to', 'all_office_admins'
                ),
                r.created_at = COALESCE(s.last_edited_at, s.submitted_at, r.created_at)
            WHERE 1=1
              $scopeSql
        ";
        $upd = $db->prepare($updateSql);
        $upd->execute($scopeParams);

        $insertSql = "
            INSERT INTO office_admin_reports
                (office_admin_id, submission_id, report_type, report_data, sent_to_main, created_at)
            SELECT
                oa.id,
                s.id,
                CASE WHEN s.order_completed = 1 THEN 'order_completed' ELSE 'status_update' END,
                JSON_OBJECT(
                    'linked_from', 'admin_subadmins',
                    'sub_admin_id', sa.id,
                    'sub_admin', sa.username,
                    'status', s.status,
                    'visible_to', 'all_office_admins'
                ),
                1,
                COALESCE(s.last_edited_at, s.submitted_at, NOW())
            FROM users oa
            CROSS JOIN submissions s
            JOIN users sa ON sa.id = s.submitted_by AND sa.role = 'sub_admin'
            WHERE oa.role = 'office_admin'
              $scopeSql
              AND NOT EXISTS (
                  SELECT 1
                  FROM office_admin_reports existing
                  WHERE existing.submission_id = s.id
                    AND existing.office_admin_id = oa.id
              )
        ";
        $ins = $db->prepare($insertSql);
        $ins->execute($scopeParams);

        return (int)$clear->rowCount() + (int)$upd->rowCount() + (int)$ins->rowCount();
    } catch (Throwable $e) {
        return 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (!$isMainAdmin) {
        $msg = 'View only: office admins can access all data here, but manage options are disabled.';
        $msgType = 'error';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'add_sub') {
            $uname   = trim($_POST['username']);
            $pass    = $_POST['password'];
            $hash    = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
            
            $tz = new DateTimeZone('Asia/Kolkata');
            $nowIST = new DateTime('now', $tz);
            $nowStr = $nowIST->format('Y-m-d H:i:s');
            $nowIST->modify('+24 hours');
            $exp = $nowIST->format('Y-m-d H:i:s');

            try {
                $db->prepare("INSERT INTO users (username,password,role,password_expires_at,password_changed_at,created_by,office_admin_id) VALUES (?,?,'sub_admin',?,?,?,?)")
                   ->execute([$uname, $hash, $exp, $nowStr, $user['id'], null]);
                $newSubId = (int)$db->lastInsertId();
                syncOfficeAdminReportLinks($db, $newSubId);
                logActivity($user['id'], 'add_sub_admin', "Added sub-admin: $uname (auto-synced to all office admins)");
                $msg = "Sub-admin '$uname' created successfully.";
            } catch (PDOException $e) {
                $msg = 'Username already exists.'; $msgType = 'error';
            }
        }

        if ($action === 'add_office') {
            $uname = trim($_POST['oa_username']);
            $pass  = $_POST['oa_password'];
            $hash  = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
            
            $tz = new DateTimeZone('Asia/Kolkata');
            $nowIST = new DateTime('now', $tz);
            $nowStr = $nowIST->format('Y-m-d H:i:s');
            
            try {
                $db->prepare("INSERT INTO users (username,password,role,is_active,password_changed_at,created_by) VALUES (?,?,'office_admin',1,?,?)")
                   ->execute([$uname, $hash, $nowStr, $user['id']]);
                syncOfficeAdminReportLinks($db);
                logActivity($user['id'], 'add_office_admin', "Added office-admin: $uname");
                $msg = "Office Admin '$uname' created successfully and synced with all sub-admin data.";
            } catch (PDOException $e) {
                $msg = 'Username already exists.'; $msgType = 'error';
            }
        }

        if ($action === 'edit') {
            $id    = (int)$_POST['edit_id'];
            $uname = trim($_POST['edit_username']);
            $lock  = isset($_POST['edit_locked']) ? 1 : 0;

            $prevRow = $db->prepare("SELECT is_locked, role FROM users WHERE id=?");
            $prevRow->execute([$id]);
            $prevRow = $prevRow->fetch();
            $wasLocked = (int)($prevRow['is_locked'] ?? 0);

            $db->prepare("UPDATE users SET username=?, is_locked=? WHERE id=?")
               ->execute([$uname, $lock, $id]);
            if (($prevRow['role'] ?? '') === 'sub_admin') {
                $linkedCount = syncOfficeAdminReportLinks($db, $id);
                if ($linkedCount > 0) {
                    logActivity($user['id'], 'sync_office_admin_data', "Connected $linkedCount records for sub-admin id=$id");
                }
            }

            if ($lock === 1 && $wasLocked === 0) {
                forceLogoutUser($id);
                logActivity($user['id'], 'lock_user', "Locked user id=$id ($uname)");
            }
            if ($lock === 0 && $wasLocked === 1) {
                logActivity($user['id'], 'unlock_user', "Unlocked user id=$id ($uname)");
            }

            $passwordChanged = false;
            if (!empty($_POST['edit_password'])) {
                $hash = password_hash($_POST['edit_password'], PASSWORD_BCRYPT, ['cost' => 12]);
                
                // Ensure password_changed_at column exists safely
                try { 
                    $colCheck = $db->query("SHOW COLUMNS FROM users LIKE 'password_changed_at'");
                    if ($colCheck->rowCount() === 0) {
                        $db->exec("ALTER TABLE users ADD COLUMN password_changed_at DATETIME NULL");
                    }
                } catch (Exception $ex) {}
                
                $tz = new DateTimeZone('Asia/Kolkata');
                $nowIST = new DateTime('now', $tz);
                $nowStr = $nowIST->format('Y-m-d H:i:s');
                $nowIST->modify('+24 hours');
                $newExpiry = $nowIST->format('Y-m-d H:i:s');

                if (in_array($prevRow['role'], ['sub_admin', 'office_admin'])) {
                    $db->prepare("UPDATE users SET password=?, password_expires_at=?, password_changed_at=? WHERE id=?")
                       ->execute([$hash, $newExpiry, $nowStr, $id]);
                } else {
                    $db->prepare("UPDATE users SET password=?, password_expires_at=NULL, password_changed_at=? WHERE id=?")
                       ->execute([$hash, $nowStr, $id]);
                }
                try { forceLogoutUser($id); } catch (Exception $ex) {}
                $passwordChanged = true;
                logActivity($user['id'], 'edit_password', "Changed password for user id=$id ($uname)");
            }

            logActivity($user['id'], 'edit_user', "Edited user id=$id");

            if ($passwordChanged) {
                $msg = "Password updated for '$uname'. Their active sessions have been terminated — they must log in again.";
            } elseif ($lock === 1 && $wasLocked === 0) {
                $msg = "Account '$uname' locked and sessions terminated.";
            } elseif ($lock === 0 && $wasLocked === 1) {
                $msg = "Account '$uname' unlocked.";
            } else {
                $msg = 'Account updated.';
            }
        }

        if ($action === 'remove') {
            $id = (int)$_POST['del_id'];
            try {
                $db->beginTransaction();
                $nameStmt = $db->prepare("SELECT username FROM users WHERE id=?");
                $nameStmt->execute([$id]);
                $deletedName = $nameStmt->fetchColumn() ?: "ID $id";
                try { forceLogoutUser($id); } catch (Exception $ex) {}
                $db->prepare("UPDATE user_sessions SET is_active=0, logged_out_at=NOW() WHERE user_id=?")->execute([$id]);
                $db->prepare("DELETE FROM sub_admin_locations WHERE sub_admin_id=?")->execute([$id]);
                $stmt = $db->prepare("DELETE FROM users WHERE id=? AND role IN ('sub_admin','office_admin')");
                $stmt->execute([$id]);
                if ($stmt->rowCount() === 0) {
                    $db->rollBack();
                    $msg = 'User not found or not allowed to delete.'; $msgType = 'error';
                } else {
                    $db->commit();
                    logActivity($user['id'], 'remove_user', "Removed user id=$id ($deletedName)");
                    $msg = "✅ Account '$deletedName' has been permanently removed.";
                }
            } catch (PDOException $e) {
                if ($db->inTransaction()) $db->rollBack();
                $msg = 'Failed to remove account: ' . htmlspecialchars($e->getMessage()); $msgType = 'error';
            }
        }

        if ($action === 'force_logout') {
            $id = (int)$_POST['logout_id'];
            forceLogoutUser($id);
            logActivity($user['id'], 'force_logout_user', "Force-logged-out user id=$id");
            $msg = 'All sessions terminated.';
        }

        if ($action === 'quick_lock') {
            $id = (int)$_POST['lock_id']; $toLock = (int)$_POST['to_lock'];
            $saName = $db->prepare("SELECT username FROM users WHERE id=?");
            $saName->execute([$id]); $saName = $saName->fetchColumn();
            $db->prepare("UPDATE users SET is_locked=? WHERE id=?")->execute([$toLock, $id]);
            if ($toLock === 1) { forceLogoutUser($id); logActivity($user['id'], 'lock_user', "Locked user id=$id ($saName)"); $msg = "Account '$saName' locked."; }
            else { logActivity($user['id'], 'unlock_user', "Unlocked user id=$id ($saName)"); $msg = "Account '$saName' unlocked."; }
        }

        if ($action === 'assign_location') {
            $subId = (int)$_POST['assign_sub_id']; $sdIds = $_POST['sub_dirs'] ?? [];
            $db->prepare("DELETE FROM sub_admin_locations WHERE sub_admin_id=?")->execute([$subId]);
            foreach ($sdIds as $sdId) {
                $db->prepare("INSERT IGNORE INTO sub_admin_locations (sub_admin_id,sub_direction_id) VALUES (?,?)")->execute([$subId, (int)$sdId]);
            }
            $msg = 'Locations assigned.';
        }

        if ($action === 'connect_subadmins') {
            $linkedCount = syncOfficeAdminReportLinks($db);
            logActivity($user['id'], 'sync_all_office_admin_data', "Synced $linkedCount report rows to all office admins");
            $msg = 'All sub-admin data synced to all office admins.';
        }
    }
}

syncOfficeAdminReportLinks($db);

$subAdmins    = $db->query("SELECT u.*, 'All Office Admins' AS office_admin_name,
    (SELECT COUNT(*) FROM user_sessions WHERE user_id=u.id AND is_active=1) AS active_sessions,
    (SELECT COUNT(*) FROM submissions WHERE submitted_by=u.id) AS total_submissions,
    (SELECT COUNT(*) FROM submissions WHERE submitted_by=u.id AND status='billed') AS billed_submissions,
    (SELECT COUNT(*) FROM submissions WHERE submitted_by=u.id AND order_completed=1) AS completed_submissions
    FROM users u
    WHERE u.role='sub_admin' ORDER BY u.created_at DESC")->fetchAll();
$officeAdmins = $db->query("SELECT u.*, (SELECT COUNT(*) FROM user_sessions WHERE user_id=u.id AND is_active=1) AS active_sessions,
    (SELECT COUNT(*) FROM users sa WHERE sa.role='sub_admin') AS sub_count,
    (SELECT COUNT(*) FROM submissions s JOIN users sa ON s.submitted_by=sa.id WHERE sa.role='sub_admin') AS connected_data_count
    FROM users u WHERE u.role='office_admin' ORDER BY u.created_at DESC")->fetchAll();
$allSubDirs   = $db->query("SELECT sd.*, d.code AS dir_code FROM sub_directions sd JOIN directions d ON sd.direction_id = d.id ORDER BY d.code, sd.code")->fetchAll();
$zoneRows = $db->query("SELECT sal.sub_admin_id, sd.code FROM sub_admin_locations sal JOIN sub_directions sd ON sal.sub_direction_id=sd.id ORDER BY sd.code")->fetchAll();
$zonesBySub = [];
foreach ($zoneRows as $zr) $zonesBySub[(int)$zr['sub_admin_id']][] = $zr['code'];

pageHead('User Management');
topbar($user['username'], $user['role']);
?>
<style>
.lock-btn{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:6px;border:none;cursor:pointer;font-size:.78rem;font-weight:600;transition:opacity .15s;}
.lock-btn:hover{opacity:.85}.lock-btn.do-lock{background:#fef3c7;color:#92400e;}.lock-btn.do-unlock{background:#d1fae5;color:#065f46;}
.status-locked{background:#fee2e2;color:#dc2626;border:1px solid #fecaca;padding:3px 10px;border-radius:20px;font-size:.76rem;font-weight:700}
.status-active{background:#d1fae5;color:#065f46;border:1px solid #6ee7b7;padding:3px 10px;border-radius:20px;font-size:.76rem;font-weight:700}
.status-inactive{background:#f3f4f6;color:#6b7280;border:1px solid #e5e7eb;padding:3px 10px;border-radius:20px;font-size:.76rem;font-weight:700}
.tab-btn{padding:8px 20px;border:none;background:var(--c-gray-100);color:var(--c-gray-600);cursor:pointer;border-radius:6px;font-size:.9rem;font-weight:500;transition:.15s;}
.tab-btn.active{background:var(--c-primary);color:#fff;}
.tab-panel{display:none;}.tab-panel.active{display:block;}
.filter-bar{background:#fff;border:1px solid var(--c-gray-200);border-radius:10px;padding:12px;margin-bottom:14px;display:grid;grid-template-columns:minmax(180px,1fr) 170px 170px;gap:10px;box-shadow:var(--shadow)}
.filter-bar.two{grid-template-columns:minmax(180px,1fr) 170px}
.user-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px}
.user-card{background:#fff;border:1px solid var(--c-gray-200);border-radius:10px;box-shadow:var(--shadow);padding:16px;display:flex;flex-direction:column;gap:12px;min-width:0}
.user-card.hide{display:none}
.user-card.locked{opacity:.75;background:#fff7f7;border-color:#fecaca}
.user-card-head{display:flex;align-items:flex-start;gap:12px}
.user-avatar{width:42px;height:42px;border-radius:10px;background:var(--c-primary-light);color:var(--c-primary);display:flex;align-items:center;justify-content:center;font-weight:800;flex-shrink:0}
.user-title{font-size:.98rem;font-weight:800;color:var(--c-gray-900);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.user-sub{font-size:.74rem;color:var(--c-gray-500);margin-top:2px}
.metric-row{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
.metric{background:var(--c-gray-50);border:1px solid var(--c-gray-100);border-radius:8px;padding:8px;min-width:0}
.metric-val{font-size:1.05rem;font-weight:800;color:var(--c-gray-900);line-height:1}
.metric-label{font-size:.66rem;color:var(--c-gray-400);margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.chip-row{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.card-actions{display:flex;gap:6px;flex-wrap:wrap;align-items:center;padding-top:10px;border-top:1px solid var(--c-gray-100)}
.no-match{display:none;text-align:center;padding:30px 18px;border:1px dashed var(--c-gray-200);border-radius:10px;color:var(--c-gray-500);background:#fff;font-size:.88rem}
.auto-note{background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;border-radius:8px;padding:10px 12px;font-size:.8rem;margin-bottom:14px}
.connect-list{display:flex;flex-direction:column;gap:8px;max-height:340px;overflow:auto;border:1px solid var(--c-gray-200);border-radius:10px;padding:10px;background:#fafafa}
.connect-row{display:flex;align-items:center;justify-content:space-between;gap:10px;background:#fff;border:1px solid var(--c-gray-100);border-radius:8px;padding:10px;cursor:pointer}
.connect-row:hover{border-color:#bfdbfe;background:#eff6ff}
.connect-row-main{display:flex;align-items:center;gap:9px;min-width:0}
.connect-row-name{font-weight:700;color:var(--c-gray-800);font-size:.88rem}
.connect-row-meta{font-size:.72rem;color:var(--c-gray-400);margin-top:2px}
@media(max-width:700px){.filter-bar,.filter-bar.two{grid-template-columns:1fr}.user-grid{grid-template-columns:1fr}.metric-row{grid-template-columns:repeat(3,minmax(0,1fr))}}
#deleteConfirmModal .modal{max-width:420px;text-align:center;padding:32px 28px;}
.del-icon{font-size:3.2rem;margin-bottom:10px;line-height:1;}
.del-title{font-size:1.2rem;font-weight:700;color:#dc2626;margin-bottom:8px;}
.del-body{font-size:.9rem;color:var(--c-gray-600);margin-bottom:22px;line-height:1.65;}
.del-username{font-weight:700;color:var(--c-gray-800);font-size:1rem;}
.del-role{display:inline-block;background:#f3f4f6;color:#6b7280;border-radius:4px;font-size:.76rem;padding:1px 7px;margin-left:4px;vertical-align:middle;}
.del-warn{color:#dc2626;font-size:.8rem;background:#fff5f5;border:1px solid #fecaca;border-radius:6px;padding:8px 12px;margin-top:10px;display:inline-block;}
.del-footer{display:flex;gap:10px;justify-content:center;margin-top:4px;}
.del-footer .btn{min-width:120px;}
#successToast{position:fixed;bottom:28px;left:50%;transform:translateX(-50%) translateY(90px);background:#065f46;color:#fff;padding:14px 28px;border-radius:12px;font-size:.92rem;font-weight:600;box-shadow:0 8px 28px rgba(0,0,0,.18);z-index:9999;transition:transform .38s cubic-bezier(.34,1.56,.64,1),opacity .3s;opacity:0;pointer-events:none;white-space:nowrap;}
#successToast.show{transform:translateX(-50%) translateY(0);opacity:1;}
</style>

<div class="app-wrap">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="main-content">
    <div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px">
      <div>
        <div class="page-title">User Management</div>
        <div class="page-sub"><?= $isMainAdmin ? 'Manage users with automatic sync to all Sales Executives' : 'View all Sales and Marketing Executives data' ?></div>
      </div>
      <?php if ($isMainAdmin): ?>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn btn-success" onclick="openModal('addOfficeModal')">+ Add Sales Executive</button>
        <button class="btn btn-primary" onclick="openModal('addModal')">+ Add Marketing Executive</button>
      </div>
      <?php endif; ?>
    </div>

    <?php if ($msg && $msgType === 'error'): alert(htmlspecialchars($msg), $msgType); endif; ?>
    <?php if (!$isMainAdmin): ?>
    <div class="alert alert-info">Office admins can view all data on this page. All manage options are hidden.</div>
    <?php endif; ?>

    <div style="display:flex;gap:8px;margin-bottom:16px">
      <button class="tab-btn active" onclick="switchTab('oa')">🏢 Sales Executive (<?= count($officeAdmins) ?>)</button>
      <button class="tab-btn" onclick="switchTab('sa')">👤 Marketing Executive (<?= count($subAdmins) ?>)</button>
    </div>

    <!-- OFFICE ADMINS -->
    <div class="tab-panel active" id="tab-oa">
      <div class="filter-bar two">
        <input class="form-control" type="search" id="oaSearch" placeholder="Filter office admins..." oninput="filterCards('oa')">
        <select class="form-control" id="oaStatus" onchange="filterCards('oa')">
          <option value="">All status</option>
          <option value="active">Active</option>
          <option value="locked">Locked</option>
          <option value="online">Online</option>
          <option value="offline">Offline</option>
        </select>
      </div>
      <?php if ($officeAdmins): ?>
      <div class="user-grid" id="oaGrid">
        <?php foreach ($officeAdmins as $oa):
          $isLocked=(int)$oa['is_locked']===1;
          $online=(int)$oa['active_sessions']>0;
        ?>
        <div class="user-card <?= $isLocked?'locked':'' ?>" data-kind="oa" data-name="<?= htmlspecialchars(strtolower($oa['username']), ENT_QUOTES) ?>" data-locked="<?= $isLocked ? '1' : '0' ?>" data-online="<?= $online ? '1' : '0' ?>">
          <div class="user-card-head">
            <div class="user-avatar"><?= strtoupper(substr($oa['username'],0,1)) ?></div>
            <div style="flex:1;min-width:0">
              <div class="user-title"><?= htmlspecialchars($oa['username']) ?></div>
              <div class="user-sub">Office Admin</div>
            </div>
            <?= $isLocked ? '<span class="status-locked">Locked</span>' : '<span class="status-active">Active</span>' ?>
          </div>
          <div class="metric-row">
            <div class="metric"><div class="metric-val"><?= (int)$oa['sub_count'] ?></div><div class="metric-label">Sub Admins</div></div>
            <div class="metric"><div class="metric-val"><?= (int)$oa['connected_data_count'] ?></div><div class="metric-label">Connected Data</div></div>
            <div class="metric"><div class="metric-val"><?= (int)$oa['active_sessions'] ?></div><div class="metric-label">Sessions</div></div>
          </div>
          <div class="auto-note">This office admin automatically receives all sub-admin data and reports.</div>
          <?php if ($isMainAdmin): ?>
          <div class="card-actions">
            <button class="btn btn-ghost btn-sm" onclick='editModal(<?= json_encode($oa) ?>)'>✏️ Edit</button>
            <button class="btn btn-ghost btn-sm" style="color:#d97706" onclick='resetPasswordModal(<?= json_encode($oa) ?>)'>🔑 Reset Password</button>
            <?php if ($isLocked): ?>
            <form method="POST" style="display:inline"><input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="quick_lock"><input type="hidden" name="lock_id" value="<?= $oa['id'] ?>"><input type="hidden" name="to_lock" value="0"><button type="submit" class="lock-btn do-unlock">🔓 Unlock</button></form>
            <?php else: ?>
            <form method="POST" style="display:inline" onsubmit="return confirm('Lock this office admin?')"><input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="quick_lock"><input type="hidden" name="lock_id" value="<?= $oa['id'] ?>"><input type="hidden" name="to_lock" value="1"><button type="submit" class="lock-btn do-lock">🔒 Lock</button></form>
            <?php endif; ?>
            <button type="button" class="btn btn-danger btn-sm" onclick="confirmDelete(<?= $oa['id'] ?>,'<?= htmlspecialchars($oa['username'],ENT_QUOTES) ?>','Office Admin')">🗑 Remove</button>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="no-match" id="oaNoMatch">No office admins match this filter.</div>
      <?php else: ?><div class="empty-state"><p>No office admins yet. Click "Add Office Admin" to create one.</p></div><?php endif; ?>
    </div>

    <!-- SUB ADMINS -->
    <div class="tab-panel" id="tab-sa">
      <div class="filter-bar two">
        <input class="form-control" type="search" id="saSearch" placeholder="Filter sub admins..." oninput="filterCards('sa')">
        <select class="form-control" id="saStatus" onchange="filterCards('sa')">
          <option value="">All status</option>
          <option value="active">Active</option>
          <option value="locked">Locked</option>
          <option value="online">Online</option>
          <option value="offline">Offline</option>
        </select>
      </div>
      <?php if ($subAdmins): ?>
      <div class="user-grid" id="saGrid">
        <?php foreach ($subAdmins as $sa):
          $zoneCodes=$zonesBySub[(int)$sa['id']] ?? [];
          $expired=$sa['password_expires_at']&&strtotime($sa['password_expires_at'])<time();
          $isLocked=(int)$sa['is_locked']===1;
          $online=(int)$sa['active_sessions']>0;
        ?>
        <div class="user-card <?= $isLocked?'locked':'' ?>" data-kind="sa" data-name="<?= htmlspecialchars(strtolower($sa['username'].' '.$sa['office_admin_name']), ENT_QUOTES) ?>" data-locked="<?= $isLocked ? '1' : '0' ?>" data-online="<?= $online ? '1' : '0' ?>" data-office="all">
          <div class="user-card-head">
            <div class="user-avatar"><?= strtoupper(substr($sa['username'],0,1)) ?></div>
            <div style="flex:1;min-width:0">
              <div class="user-title"><?= htmlspecialchars($sa['username']) ?></div>
              <div class="user-sub">Visible to all office admins</div>
            </div>
            <?= $isLocked?'<span class="status-locked">Locked</span>':(!$sa['is_active']?'<span class="status-inactive">Inactive</span>':'<span class="status-active">Active</span>') ?>
          </div>
          <div class="metric-row">
            <div class="metric"><div class="metric-val"><?= (int)$sa['total_submissions'] ?></div><div class="metric-label">Data</div></div>
            <div class="metric"><div class="metric-val"><?= (int)$sa['billed_submissions'] ?></div><div class="metric-label">Billed</div></div>
            <div class="metric"><div class="metric-val"><?= (int)$sa['active_sessions'] ?></div><div class="metric-label">Sessions</div></div>
          </div>
          <div class="chip-row">
            <?= $expired?'<span class="badge badge-cold">Password expired</span>':'<span class="badge badge-gray">'.($sa['password_expires_at']?date('d M H:i',strtotime($sa['password_expires_at'])):'No expiry').'</span>' ?>
            <?php foreach($zoneCodes as $z): ?><span class="badge badge-blue"><?= htmlspecialchars($z) ?></span><?php endforeach; ?>
            <?php if(!$zoneCodes): ?><span class="badge badge-gray">No zones</span><?php endif; ?>
          </div>
          <div class="auto-note">All submissions from this sub-admin are automatically synced to every office admin.</div>
          <?php if ($isMainAdmin): ?>
          <div class="card-actions">
            <button class="btn btn-ghost btn-sm" onclick='editModal(<?= json_encode($sa) ?>)'>✏️ Edit</button>
            <button class="btn btn-ghost btn-sm" style="color:#d97706" onclick='resetPasswordModal(<?= json_encode($sa) ?>)'>🔑 Reset Password</button>
            <button class="btn btn-ghost btn-sm" onclick='assignModal(<?= $sa["id"] ?>,"<?= htmlspecialchars($sa["username"],ENT_QUOTES) ?>",<?= json_encode($zoneCodes) ?>)'>📍 Zones</button>
            <?php if ($isLocked): ?>
            <form method="POST" style="display:inline"><input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="quick_lock"><input type="hidden" name="lock_id" value="<?= $sa['id'] ?>"><input type="hidden" name="to_lock" value="0"><button type="submit" class="lock-btn do-unlock">🔓</button></form>
            <?php else: ?>
            <form method="POST" style="display:inline" onsubmit="return confirm('Lock this sub-admin?')"><input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="quick_lock"><input type="hidden" name="lock_id" value="<?= $sa['id'] ?>"><input type="hidden" name="to_lock" value="1"><button type="submit" class="lock-btn do-lock">🔒</button></form>
            <?php endif; ?>
            <?php if($sa['active_sessions']>0&&!$isLocked): ?>
            <form method="POST" style="display:inline" onsubmit="return confirm('Force logout all sessions?')"><input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="force_logout"><input type="hidden" name="logout_id" value="<?= $sa['id'] ?>"><button class="btn btn-danger btn-sm" type="submit">⏏</button></form>
            <?php endif; ?>
            <button type="button" class="btn btn-danger btn-sm" onclick="confirmDelete(<?= $sa['id'] ?>,'<?= htmlspecialchars($sa['username'],ENT_QUOTES) ?>','Sub Admin')">🗑</button>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="no-match" id="saNoMatch">No sub-admins match this filter.</div>
      <?php else: ?><div class="empty-state"><p>No sub-admins yet.</p></div><?php endif; ?>
    </div>
  </div>
</div>

<?php if ($isMainAdmin): ?>
<!-- DELETE MODAL -->
<div class="modal-overlay" id="deleteConfirmModal">
  <div class="modal">
    <div class="del-icon">🗑️</div>
    <div class="del-title">Remove Account?</div>
    <div class="del-body">
      You are about to permanently remove<br><br>
      <span class="del-username" id="del_display_name"></span>
      <span class="del-role" id="del_display_role"></span>
      <br><br>
      <span class="del-warn">⚠️ This cannot be undone.<br>All active sessions will be terminated.</span>
    </div>
    <form method="POST" id="deleteForm">
      <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="remove">
      <input type="hidden" name="del_id" id="del_id_input">
      <div class="del-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('deleteConfirmModal')">Cancel</button>
        <button type="submit" class="btn btn-danger" id="confirmDelBtn">Yes, Remove</button>
      </div>
    </form>
  </div>
</div>

<!-- ADD OFFICE ADMIN MODAL -->
<div class="modal-overlay" id="addOfficeModal"><div class="modal">
  <div class="modal-title">🏢 Add Sales Executive (Office Admin)</div>
  <form method="POST"><input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="add_office">
    <div class="form-group"><label class="form-label">Username *</label><input class="form-control" type="text" name="oa_username" required placeholder="e.g. sales_exec_1"></div>
    <div class="form-group"><label class="form-label">Password *</label>
      <div style="display:flex;gap:6px;align-items:center">
        <input class="form-control" type="text" name="oa_password" id="oa_password" required placeholder="Strong password" style="flex:1">
        <button type="button" class="btn btn-ghost btn-sm" title="Copy password" onclick="copyPw('oa_password')" style="padding:7px 10px">📋</button>
      </div>
    </div>
    <div class="alert alert-info" style="font-size:.82rem">Sales Executive can view all submissions, update purchase dates, and generate reports.</div>
    <div class="modal-footer"><button type="button" class="btn btn-ghost" onclick="closeModal('addOfficeModal')">Cancel</button><button type="submit" class="btn btn-success">Create</button></div>
  </form>
</div></div>

<!-- ADD SUB ADMIN MODAL -->
<div class="modal-overlay" id="addModal"><div class="modal">
  <div class="modal-title">Add Marketing Executive (Sub Admin)</div>
  <form method="POST"><input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="add_sub">
    <div class="form-group"><label class="form-label">Username *</label><input class="form-control" type="text" name="username" required placeholder="e.g. marketing_exec_1"></div>
    <div class="form-group"><label class="form-label">Password *</label>
      <div style="display:flex;gap:6px;align-items:center">
        <input class="form-control" type="text" name="password" id="add_sub_password" required placeholder="Temporary (24-hr expiry)" style="flex:1">
        <button type="button" class="btn btn-ghost btn-sm" title="Copy password" onclick="copyPw('add_sub_password')" style="padding:7px 10px">📋</button>
      </div>
    </div>
    <div class="alert alert-info" style="font-size:.82rem">Password auto-expires in 24 hours. Device limit: 1. New sub-admin data is auto-synced to all office admins.</div>
    <div class="modal-footer"><button type="button" class="btn btn-ghost" onclick="closeModal('addModal')">Cancel</button><button type="submit" class="btn btn-primary">Create</button></div>
  </form>
</div></div>

<!-- EDIT MODAL -->
<div class="modal-overlay" id="editModal"><div class="modal">
  <div class="modal-title">Edit Account</div>
  <form method="POST"><input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="edit"><input type="hidden" name="edit_id" id="edit_id">
    <div class="form-group"><label class="form-label">Username</label><input class="form-control" type="text" name="edit_username" id="edit_username" required></div>
    <div class="form-group">
      <label class="form-label">New Password <small style="color:var(--c-gray-400)">(blank = no change)</small></label>
      <div style="display:flex;gap:6px;align-items:center">
        <input class="form-control" type="password" name="edit_password" id="edit_password" placeholder="Enter new password to change" style="flex:1">
        <button type="button" class="btn btn-ghost btn-sm" title="Show/Hide" onclick="togglePwVisibility('edit_password', this)" style="padding:7px 10px;font-size:1rem">👁️</button>
        <button type="button" class="btn btn-ghost btn-sm" title="Copy password" onclick="copyPw('edit_password')" style="padding:7px 10px">📋</button>
      </div>
      <div style="font-size:.73rem;color:var(--c-gray-400);margin-top:4px">Leave blank to keep current password. Session will be terminated after reset.</div>
    </div>
    <div class="form-group"><label style="display:flex;align-items:center;gap:10px;cursor:pointer"><label class="toggle"><input type="checkbox" name="edit_locked" id="edit_locked"><span class="toggle-slider"></span></label><span style="font-size:.9rem;color:var(--c-gray-700)">🔒 Lock account</span></label></div>
    <div class="modal-footer"><button type="button" class="btn btn-ghost" onclick="closeModal('editModal')">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div>
  </form>
</div></div>

<!-- ZONES MODAL -->
<div class="modal-overlay" id="assignModal"><div class="modal">
  <div class="modal-title">📍 Zones — <span id="assign_name"></span></div>
  <form method="POST"><input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="assign_location"><input type="hidden" name="assign_sub_id" id="assign_sub_id">
    <div class="form-group"><label class="form-label">Select sub-directions</label>
      <div style="display:flex;flex-direction:column;gap:8px;max-height:300px;overflow-y:auto;border:1px solid var(--c-gray-200);border-radius:var(--radius-sm);padding:10px">
        <?php foreach ($allSubDirs as $sd): ?>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.9rem;color:var(--c-gray-700)">
          <input type="checkbox" name="sub_dirs[]" value="<?= $sd['id'] ?>" class="sd-check" data-code="<?= htmlspecialchars($sd['code']) ?>">
          <span class="badge badge-blue"><?= htmlspecialchars($sd['dir_code']) ?></span>
          <?= htmlspecialchars($sd['code']) ?> — <?= htmlspecialchars($sd['from_location']) ?> → <?= htmlspecialchars($sd['to_location']) ?>
        </label>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-ghost" onclick="closeModal('assignModal')">Cancel</button><button type="submit" class="btn btn-primary">Save Zones</button></div>
  </form>
</div></div>
<?php endif; ?>

<div id="successToast"></div>

<nav class="mob-nav"><div class="mob-nav-items">
  <a href="dashboard.php" class="mob-nav-item"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>Home</a>
  <a href="subadmins.php" class="mob-nav-item active"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>Admins</a>
  <a href="submissions.php" class="mob-nav-item"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg>Data</a>
  <a href="reports.php" class="mob-nav-item"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>Reports</a>
</div></nav>

<?php pageFooter(); ?>
<script>
function switchTab(which){
  document.querySelectorAll('.tab-btn').forEach((b,i)=>b.classList.toggle('active',(i===0&&which==='oa')||(i===1&&which==='sa')));
  document.getElementById('tab-oa').classList.toggle('active',which==='oa');
  document.getElementById('tab-sa').classList.toggle('active',which==='sa');
}
function filterCards(kind){
  const q = (document.getElementById(kind + 'Search')?.value || '').trim().toLowerCase();
  const status = document.getElementById(kind + 'Status')?.value || '';
  let visible = 0;
  document.querySelectorAll(`[data-kind="${kind}"]`).forEach(card => {
    const locked = card.dataset.locked === '1';
    const online = card.dataset.online === '1';
    let okStatus = true;
    if (status === 'active') okStatus = !locked;
    if (status === 'locked') okStatus = locked;
    if (status === 'online') okStatus = online;
    if (status === 'offline') okStatus = !online;
    const okName = !q || (card.dataset.name || '').includes(q);
    const show = okName && okStatus;
    card.classList.toggle('hide', !show);
    if (show) visible++;
  });
  const empty = document.getElementById(kind + 'NoMatch');
  if (empty) empty.style.display = visible ? 'none' : 'block';
}
const CAN_MANAGE_USERS = <?= $isMainAdmin ? 'true' : 'false' ?>;
function editModal(sa){
  if(!CAN_MANAGE_USERS) return;
  document.getElementById('edit_id').value=sa.id;
  document.getElementById('edit_username').value=sa.username;
  document.getElementById('edit_locked').checked=sa.is_locked==1;
  document.getElementById('edit_password').value=''; // Always clear password field on open
  openModal('editModal');
}
function resetPasswordModal(sa) {
  editModal(sa);
  setTimeout(() => {
    const pwInput = document.getElementById('edit_password');
    if (pwInput) pwInput.focus();
  }, 100);
}
function assignModal(id,name,currentCodes){
  if(!CAN_MANAGE_USERS) return;
  document.getElementById('assign_sub_id').value=id;
  document.getElementById('assign_name').textContent=name;
  document.querySelectorAll('.sd-check').forEach(cb=>{cb.checked=currentCodes.includes(cb.dataset.code);});
  openModal('assignModal');
}
function confirmDelete(id,username,role){
  if(!CAN_MANAGE_USERS) return;
  document.getElementById('del_id_input').value=id;
  document.getElementById('del_display_name').textContent=username;
  document.getElementById('del_display_role').textContent=role;
  const btn=document.getElementById('confirmDelBtn');
  btn.disabled=false; btn.textContent='Yes, Remove';
  openModal('deleteConfirmModal');
}
document.getElementById('deleteForm')?.addEventListener('submit',function(){
  const btn=document.getElementById('confirmDelBtn');
  if(!btn) return;
  btn.disabled=true; btn.textContent='Removing…';
});
function showToast(msg){
  const t=document.getElementById('successToast');
  t.textContent=msg; t.classList.add('show');
  setTimeout(()=>t.classList.remove('show'),3800);
}
<?php if ($msg && $msgType === 'success'): ?>
window.addEventListener('DOMContentLoaded',()=>showToast(<?= json_encode($msg) ?>));
<?php endif; ?>

function togglePwVisibility(fieldId, btn) {
  const inp = document.getElementById(fieldId);
  if (!inp) return;
  if (inp.type === 'password') {
    inp.type = 'text';
    btn.title = 'Hide';
    btn.textContent = '🙈';
  } else {
    inp.type = 'password';
    btn.title = 'Show';
    btn.textContent = '👁️';
  }
}
function copyPw(fieldId) {
  const inp = document.getElementById(fieldId);
  if (!inp || !inp.value) return;
  navigator.clipboard.writeText(inp.value).then(function() {
    const orig = event.target.textContent;
    event.target.textContent = '✅';
    setTimeout(() => event.target.textContent = orig, 1500);
  }).catch(function() {
    // Fallback for older browsers
    inp.select();
    document.execCommand('copy');
  });
}
</script>
