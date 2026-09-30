<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
requireLogin('main_admin');

date_default_timezone_set('Asia/Kolkata');
$db   = getDB();
$db->exec("SET time_zone = '+05:30'");
$user = currentUser();
$msg  = '';

// ── Ensure columns exist ──────────────────────────────────────────────────
try { $db->exec("ALTER TABLE user_sessions ADD COLUMN device_login_time DATETIME DEFAULT NULL"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE session_log   ADD COLUMN device_login_time DATETIME DEFAULT NULL"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE users ADD COLUMN is_locked TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}

// ── Ensure session_log table exists with full ENUM including 'auto' ───────
$db->exec("CREATE TABLE IF NOT EXISTS session_log (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT          NOT NULL,
    username      VARCHAR(100) NOT NULL,
    role          VARCHAR(50)  NOT NULL,
    ip_address    VARCHAR(45)  DEFAULT NULL,
    device_info   TEXT         DEFAULT NULL,
    logged_in_at  DATETIME     NOT NULL,
    logged_out_at DATETIME     DEFAULT NULL,
    logout_type   ENUM('normal','force','force_all','auto') DEFAULT NULL,
    duration_secs INT UNSIGNED  DEFAULT NULL,
    session_token VARCHAR(255) DEFAULT NULL UNIQUE,
    device_login_time DATETIME DEFAULT NULL,
    INDEX idx_user  (user_id),
    INDEX idx_login (logged_in_at),
    INDEX idx_token (session_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Ensure 'auto' exists in the ENUM for existing tables ─────────────────
try {
    $db->exec("ALTER TABLE session_log MODIFY COLUMN logout_type
               ENUM('normal','force','force_all','auto') DEFAULT NULL");
} catch (Throwable $e) {}

$autoExpiredCount = expireStaleSessionsForAllUsers($_SESSION['token'] ?? '');
if ($autoExpiredCount > 0) {
    $msg = $autoExpiredCount . ' inactive session' . ($autoExpiredCount !== 1 ? 's' : '') . ' auto-terminated after 15 minutes and moved to Reports.';
}

// ── Write / update session_log row ───────────────────────────────────────
// logout_type: 'normal' | 'force' | 'force_all' | 'auto'
function writeSessionLog(PDO $db, array $sess, string $logoutType): void {
    $token   = $sess['session_token'];
    $loginDt = $sess['logged_in_at'];

    $durRow = $db->prepare("SELECT TIMESTAMPDIFF(SECOND, ?, NOW()) AS secs");
    $durRow->execute([$loginDt]);
    $durSecs = max(0, (int)($durRow->fetchColumn() ?? 0));

    $existing = $db->prepare("SELECT id FROM session_log WHERE session_token = ? LIMIT 1");
    $existing->execute([$token]);

    if ($existing->fetchColumn()) {
        $db->prepare("
            UPDATE session_log
            SET logged_out_at = NOW(),
                logout_type   = ?,
                duration_secs = ?
            WHERE session_token = ?
        ")->execute([$logoutType, $durSecs, $token]);
    } else {
        $db->prepare("
            INSERT INTO session_log
                (user_id, username, role, ip_address, device_info,
                 logged_in_at, logged_out_at, logout_type, duration_secs,
                 session_token, device_login_time)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?)
        ")->execute([
            $sess['user_id'],
            $sess['username']          ?? '',
            $sess['role']              ?? '',
            $sess['ip_address'],
            $sess['device_info'],
            $loginDt,
            $logoutType,
            $durSecs,
            $token,
            $sess['device_login_time'] ?? null,
        ]);
    }
}

// ── POST actions ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'logout_current') {
        $url = terminateSelf();
        header('Location: ' . $url);
        exit;
    }

    if ($action === 'logout_token') {
        $token = trim($_POST['token'] ?? '');
        if ($token !== '') {
            $stmt = $db->prepare("
                SELECT us.*, u.username, u.role
                FROM user_sessions us
                JOIN users u ON us.user_id = u.id
                WHERE us.session_token = ? AND us.is_active = 1
            ");
            $stmt->execute([$token]);
            $sess = $stmt->fetch();
            if ($sess) {
                $db->prepare("
                    UPDATE user_sessions
                    SET is_active = 0, logged_out_at = NOW()
                    WHERE session_token = ?
                ")->execute([$token]);
                writeSessionLog($db, $sess, 'force');
                logActivity($user['id'], 'force_logout',
                    "Force-terminated session for {$sess['username']} (user_id={$sess['user_id']})");
            }
        }
        $msg = 'Session terminated.';
    }

    if ($action === 'logout_all_user') {
        $uid = (int)($_POST['uid'] ?? 0);
        if ($uid > 0) {
            $stmt = $db->prepare("
                SELECT us.*, u.username, u.role
                FROM user_sessions us
                JOIN users u ON us.user_id = u.id
                WHERE us.user_id = ? AND us.is_active = 1
            ");
            $stmt->execute([$uid]);
            $activeSessions = $stmt->fetchAll();

            $db->prepare("
                UPDATE user_sessions
                SET is_active = 0, logged_out_at = NOW()
                WHERE user_id = ? AND is_active = 1
            ")->execute([$uid]);

            foreach ($activeSessions as $sess) {
                writeSessionLog($db, $sess, 'force_all');
            }
            logActivity($user['id'], 'force_logout_all',
                "Force-terminated all sessions for user_id=$uid");
        }
        $msg = 'All sessions for that user terminated.';
    }

    // ── 9 PM Auto-lock all executives (called by JS at 9 PM IST) ──────────────
    if ($action === 'auto_lock_9pm') {
        header('Content-Type: application/json');
        $istHour = (int)date('G'); // 0-23 in IST (timezone set at top)
        // Only allow between 20:55 and 23:59 to prevent abuse
        if ($istHour >= 20) {
            // Lock all non-admin users
            $db->exec("UPDATE users SET is_locked = 1 WHERE role IN ('sub_admin','office_admin')");
            // Terminate all their active sessions
            $lockedSessions = $db->query("
                SELECT us.*, u.username, u.role
                FROM user_sessions us
                JOIN users u ON us.user_id = u.id
                WHERE us.is_active = 1 AND u.role IN ('sub_admin','office_admin')
            ")->fetchAll();
            $db->exec("UPDATE user_sessions SET is_active = 0, logged_out_at = NOW()
                       WHERE user_id IN (SELECT id FROM users WHERE role IN ('sub_admin','office_admin'))
                         AND is_active = 1");
            foreach ($lockedSessions as $ls) {
                writeSessionLog($db, $ls, 'auto');
            }
            logActivity($user['id'], 'auto_lock_9pm',
                "9 PM auto-lock: locked " . count($lockedSessions) . " executive sessions");
            echo json_encode(['success' => true, 'locked' => count($lockedSessions)]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Too early to lock']);
        }
        exit;
    }

    // ── Unlock a specific user (admin manual unlock) ────────────────────────────
    if ($action === 'unlock_user') {
        header('Content-Type: application/json');
        $uid = (int)($_POST['uid'] ?? 0);
        if ($uid > 0) {
            $db->prepare("UPDATE users SET is_locked = 0 WHERE id = ? AND role IN ('sub_admin','office_admin')")
               ->execute([$uid]);
            logActivity($user['id'], 'unlock_user', "Admin manually unlocked user_id=$uid");
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid user']);
        }
        exit;
    }
}

// ── Fetch active sessions ─────────────────────────────────────────────────
$sessions = $db->query("
    SELECT us.*,
           DATE_FORMAT(us.logged_in_at,     '%Y-%m-%dT%H:%i:%s') AS login_ist_str,
           DATE_FORMAT(us.device_login_time,'%Y-%m-%dT%H:%i:%s') AS device_login_iso,
           u.username, u.role, u.is_locked
    FROM user_sessions us
    JOIN users u ON us.user_id = u.id
    WHERE us.is_active = 1
    ORDER BY us.logged_in_at DESC
")->fetchAll();

// Also get locked executives with no active session
$lockedUsers = $db->query("
    SELECT u.id, u.username, u.role, 1 AS is_locked,
           NULL AS session_token, NULL AS logged_in_at, NULL AS ip_address
    FROM users u
    WHERE u.is_locked = 1 AND u.role IN ('sub_admin','office_admin')
      AND u.id NOT IN (
          SELECT us2.user_id FROM user_sessions us2 WHERE us2.is_active = 1
      )
")->fetchAll();

// ── Bulk upsert into session_log ──────────────────────────────────────────
if (!empty($sessions)) {
    $tokens = array_column($sessions, 'session_token');
    $in     = implode(',', array_fill(0, count($tokens), '?'));
    $chkStmt = $db->prepare("SELECT session_token FROM session_log WHERE session_token IN ($in)");
    $chkStmt->execute($tokens);
    $existingTokens = array_flip($chkStmt->fetchAll(PDO::FETCH_COLUMN));

    $insStmt = $db->prepare("
        INSERT IGNORE INTO session_log
            (user_id, username, role, ip_address, device_info,
             logged_in_at, session_token, device_login_time)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($sessions as $sess) {
        if (!isset($existingTokens[$sess['session_token']])) {
            $insStmt->execute([
                $sess['user_id'],
                $sess['username'],
                $sess['role'],
                $sess['ip_address'],
                $sess['device_info'],
                $sess['logged_in_at'],
                $sess['session_token'],
                $sess['device_login_time'] ?? null,
            ]);
        }
    }

    $syncStmt = $db->prepare("
        UPDATE session_log sl
        JOIN user_sessions us ON us.session_token = sl.session_token
        SET sl.device_login_time = us.device_login_time
        WHERE sl.device_login_time IS NULL
          AND us.device_login_time IS NOT NULL
          AND sl.session_token IN ($in)
    ");
    $syncStmt->execute($tokens);
}

$recentTerminated = $db->query("
    SELECT username, role, ip_address, device_info, logged_in_at, logged_out_at,
           logout_type, duration_secs
    FROM session_log
    WHERE logged_out_at IS NOT NULL
    ORDER BY logged_out_at DESC
    LIMIT 10
")->fetchAll();

// ── Parse timestamps in PHP ───────────────────────────────────────────────
$sessionData = [];
foreach ($sessions as $s) {
    $loginTs = (new DateTime($s['login_ist_str'], new DateTimeZone('Asia/Kolkata')))->getTimestamp();

    $deviceDisplay = null;
    if (!empty($s['device_login_iso'])) {
        $dtDev = DateTime::createFromFormat('Y-m-d\TH:i:s', $s['device_login_iso']);
        if (!$dtDev) $dtDev = DateTime::createFromFormat('Y-m-d\TH:i', $s['device_login_iso']);
        if ($dtDev) {
            $deviceDisplay = $dtDev->format('d M Y, h:i:s A');
        }
    }

    $sessionData[] = array_merge($s, [
        'login_ts'       => $loginTs,
        'device_display' => $deviceDisplay,
    ]);
}

$phpNowUnix = time();

pageHead('Active Sessions');
topbar($user['username'], $user['role']);
?>
<style>
.clock-header{background:linear-gradient(135deg,#1e3a8a,#2563eb);color:#fff;border-radius:10px;padding:12px 18px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px}
.ch-time{font-size:1.7rem;font-weight:800;font-family:monospace;letter-spacing:.04em;color:#fff}
.ch-date{font-size:.76rem;color:#93c5fd;margin-top:2px}
.ch-label{font-size:.68rem;text-transform:uppercase;letter-spacing:.1em;color:#bfdbfe;font-weight:600;margin-bottom:2px}
.ch-badge{background:rgba(255,255,255,.18);border-radius:6px;padding:4px 10px;font-size:.72rem;color:#dbeafe;font-weight:600;letter-spacing:.05em}
.dur-live{display:inline-block;background:#fefce8;color:#a16207;border:1px solid #fde68a;border-radius:5px;font-size:.77rem;font-weight:700;padding:2px 8px;font-family:monospace;min-width:80px;text-align:center}
.online-dot{display:inline-block;width:7px;height:7px;background:#16a34a;border-radius:50%;margin-right:4px;animation:blink 1.2s infinite;vertical-align:middle}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.3}}
.dev-time-val{display:inline-block;background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0;border-radius:5px;font-size:.76rem;font-weight:600;padding:2px 8px;font-family:monospace}
.dev-time-none{font-size:.75rem;color:var(--c-gray-400);font-style:italic}
.locked-row td{background:#fef2f2!important}
.lock-badge{display:inline-flex;align-items:center;gap:4px;background:#fee2e2;color:#b91c1c;border:1px solid #fca5a5;border-radius:5px;font-size:.73rem;font-weight:700;padding:2px 8px}
.btn-unlock{background:#16a34a;color:#fff;border:none;padding:4px 11px;border-radius:5px;font-size:.75rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:4px;transition:background .15s}
.btn-unlock:hover{background:#15803d}
.lock-countdown-bar{background:linear-gradient(135deg,#7c2d12,#ea580c);color:#fff;border-radius:8px;padding:9px 16px;margin-bottom:12px;display:flex;align-items:center;gap:10px;font-size:.83rem;font-weight:600}
.lock-countdown-bar.already-locked{background:linear-gradient(135deg,#991b1b,#dc2626)}

/* Logout type badges */
.lt-badge{display:inline-block;font-size:.7rem;font-weight:700;padding:2px 8px;border-radius:6px;white-space:nowrap}
.lt-normal   {background:#f0fdf4;color:#15803d;border:1px solid #86efac}
.lt-force    {background:#fef2f2;color:#dc2626;border:1px solid #fecaca}
.lt-force-all{background:#f5f3ff;color:#7c3aed;border:1px solid #ddd6fe}
.lt-auto     {background:#fefce8;color:#a16207;border:1px solid #fde68a}
.lt-active   {background:#dcfce7;color:#15803d;border:1px solid #86efac}
.recent-title{font-size:.9rem;font-weight:800;color:var(--c-gray-800);margin-bottom:10px}
.muted-mini{font-size:.75rem;color:var(--c-gray-400)}
</style>

<div class="app-wrap">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="main-content">

    <div class="page-header">
      <div class="page-title">Active Sessions</div>
      <div class="page-sub">Monitor who is currently logged in · All sessions are permanently recorded in Reports</div>
    </div>

    <?php alert(htmlspecialchars($msg), 'success') ?>

    <!-- 9 PM Lock Countdown Bar -->
    <div id="lock-countdown-bar" class="lock-countdown-bar" style="display:none">
      <span style="font-size:1.1rem">🔒</span>
      <div>
        <div id="lock-bar-title">Auto-Lock in: <strong id="lock-countdown-text">--:--</strong></div>
        <div style="font-size:.73rem;opacity:.85">All executives will be automatically locked at 9:00 PM IST</div>
      </div>
    </div>

    <div class="clock-header">
      <div>
        <div class="ch-label">🕐 Current Time (IST)</div>
        <div class="ch-time" id="act-clock">--:--:--</div>
        <div class="ch-date" id="act-date">Loading…</div>
      </div>
      <div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px">
        <div class="ch-badge">IST (Asia/Kolkata)</div>
        <div class="ch-badge" style="background:rgba(255,255,255,.12);font-size:.68rem">
          <?= count($sessionData) ?> user<?= count($sessionData) != 1 ? 's' : '' ?> online
        </div>
      </div>
    </div>

    <div class="card">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>User</th>
              <th>Role</th>
              <th>Lock Status</th>
              <th>IP Address</th>
              <th>Session Duration</th>
              <th>Device Login Time</th>
              <th>Device</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
          <?php if ($sessionData): foreach ($sessionData as $s): ?>
          <tr class="<?= $s['is_locked'] ? 'locked-row' : '' ?>">
            <td>
              <div style="display:flex;align-items:center;gap:6px">
                <span class="online-dot"></span>
                <strong><?= htmlspecialchars($s['username']) ?></strong>
              </div>
            </td>
            <td>
              <span class="badge <?= $s['role'] === 'main_admin' ? 'badge-blue' : ($s['role'] === 'office_admin' ? 'badge-hot' : 'badge-warm') ?>">
                <?= $s['role'] === 'main_admin' ? 'Main Admin' : ($s['role'] === 'office_admin' ? 'Sales Executive' : 'Marketing Executive') ?>
              </span>
            </td>
            <td>
              <?php if ($s['is_locked'] && in_array($s['role'], ['sub_admin','office_admin'])): ?>
                <span class="lock-badge">🔒 Locked</span>
                <button class="btn-unlock" style="margin-top:4px" onclick="unlockUser(<?= (int)$s['user_id'] ?>, '<?= htmlspecialchars($s['username'], ENT_QUOTES) ?>')">
                  🔓 Unlock
                </button>
              <?php else: ?>
                <span class="lt-badge lt-active"><?= $s['role'] === 'main_admin' ? 'Admin' : '✅ Active' ?></span>
              <?php endif; ?>
            </td>
            <td><code style="font-size:.8rem"><?= htmlspecialchars($s['ip_address']) ?></code></td>
            <td>
              <span class="dur-live" data-login-unix="<?= $s['login_ts'] ?>">⏳ --</span>
            </td>
            <td>
              <?php if ($s['device_display']): ?>
                <span class="dev-time-val"><?= htmlspecialchars($s['device_display']) ?></span>
              <?php else: ?>
                <span class="dev-time-none">Not captured</span>
              <?php endif; ?>
            </td>
            <td style="font-size:.78rem;color:var(--c-gray-500);max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"
                title="<?= htmlspecialchars($s['device_info']) ?>">
              <?= htmlspecialchars(mb_substr($s['device_info'], 0, 60)) ?>…
            </td>
            <td>
              <?php if ($s['session_token'] !== ($_SESSION['token'] ?? '')): ?>
              <div style="display:flex;flex-direction:column;gap:6px">
                <form method="POST" onsubmit="return confirm('Terminate this session for <?= htmlspecialchars($s['username'], ENT_QUOTES) ?>?\nThey will be logged out immediately.')">
                  <input type="hidden" name="csrf"   value="<?= csrfToken() ?>">
                  <input type="hidden" name="action" value="logout_token">
                  <input type="hidden" name="token"  value="<?= htmlspecialchars($s['session_token']) ?>">
                  <button class="btn btn-sm" style="background:#dc2626;color:#fff;border:none;font-weight:700;display:flex;align-items:center;gap:4px;white-space:nowrap">
                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    Terminate
                  </button>
                </form>
                <form method="POST" onsubmit="return confirm('Terminate ALL sessions for <?= htmlspecialchars($s['username'], ENT_QUOTES) ?>?')">
                  <input type="hidden" name="csrf"   value="<?= csrfToken() ?>">
                  <input type="hidden" name="action" value="logout_all_user">
                  <input type="hidden" name="uid"    value="<?= (int)$s['user_id'] ?>">
                  <button class="btn btn-sm" style="background:#7c3aed;color:#fff;border:none;font-size:.72rem;white-space:nowrap">🚧 All Sessions</button>
                </form>
              </div>
              <?php else: ?>
                <form method="POST" onsubmit="return confirm('Terminate your current admin session? You will be sent to login immediately.')">
                  <input type="hidden" name="csrf"   value="<?= csrfToken() ?>">
                  <input type="hidden" name="action" value="logout_current">
                  <button class="btn btn-sm" style="background:#dc2626;color:#fff;border:none;font-weight:700;display:flex;align-items:center;gap:4px;white-space:nowrap">
                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M10 17l5-5-5-5"/><path d="M15 12H3"/><path d="M21 3v18"/></svg>
                    Terminate My Session
                  </button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>

          <?php /* Locked users with no active sessions */ foreach ($lockedUsers as $lu): ?>
          <tr class="locked-row">
            <td><strong><?= htmlspecialchars($lu['username']) ?></strong></td>
            <td><span class="badge <?= $lu['role'] === 'office_admin' ? 'badge-hot' : 'badge-warm' ?>"><?= $lu['role'] === 'office_admin' ? 'Sales Executive' : 'Marketing Executive' ?></span></td>
            <td>
              <span class="lock-badge">🔒 Locked (Offline)</span>
              <button class="btn-unlock" style="margin-top:4px" onclick="unlockUser(<?= (int)$lu['id'] ?>, '<?= htmlspecialchars($lu['username'], ENT_QUOTES) ?>')">
                🔓 Unlock
              </button>
            </td>
            <td colspan="5" style="color:var(--c-gray-400);font-style:italic;font-size:.82rem">No active session — executive is locked out</td>
          </tr>
          <?php endforeach; ?>

          <?php if (empty($sessionData) && empty($lockedUsers)): ?>
          <tr>
            <td colspan="8"><div class="empty-state"><p>No active sessions</p></div></td>
          </tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card" style="margin-top:14px">
      <div class="recent-title">Recently Terminated Sessions</div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>User</th>
              <th>Role</th>
              <th>Logout Type</th>
              <th>Login Time</th>
              <th>Terminated At</th>
              <th>Duration</th>
              <th>IP / Device</th>
            </tr>
          </thead>
          <tbody>
          <?php if ($recentTerminated): foreach ($recentTerminated as $rt):
              $lt = $rt['logout_type'] ?: 'normal';
              $dur = (int)($rt['duration_secs'] ?? 0);
              $dh = intdiv($dur, 3600);
              $dm = intdiv($dur % 3600, 60);
              $ds = $dur % 60;
              $durText = $dh ? "{$dh}h {$dm}m {$ds}s" : ($dm ? "{$dm}m {$ds}s" : "{$ds}s");
          ?>
          <tr>
            <td><strong><?= htmlspecialchars($rt['username']) ?></strong></td>
            <td><?= htmlspecialchars($rt['role']) ?></td>
            <td><span class="lt-badge lt-<?= htmlspecialchars(str_replace('_', '-', $lt)) ?>"><?= htmlspecialchars($lt) ?></span></td>
            <td><?= $rt['logged_in_at'] ? date('d M Y, h:i:s A', strtotime($rt['logged_in_at'])) : '—' ?></td>
            <td><?= $rt['logged_out_at'] ? date('d M Y, h:i:s A', strtotime($rt['logged_out_at'])) : '—' ?></td>
            <td><span class="dur-live"><?= htmlspecialchars($durText) ?></span></td>
            <td>
              <code style="font-size:.75rem"><?= htmlspecialchars($rt['ip_address'] ?? '') ?></code>
              <div class="muted-mini" title="<?= htmlspecialchars($rt['device_info'] ?? '') ?>"><?= htmlspecialchars(mb_substr($rt['device_info'] ?? '', 0, 70)) ?><?= mb_strlen($rt['device_info'] ?? '') > 70 ? '…' : '' ?></div>
            </td>
          </tr>
          <?php endforeach; else: ?>
          <tr>
            <td colspan="7"><div class="empty-state"><p>No terminated sessions yet</p></div></td>
          </tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div style="margin-top:10px;font-size:.76rem;color:var(--c-gray-400);text-align:right">
      ✅ All sessions (active &amp; terminated) are permanently stored and visible in
      <a href="reports.php?tab=login" style="color:var(--c-primary)">Reports → Login Sessions</a>
    </div>

  </div>
</div>

<nav class="mob-nav">
  <div class="mob-nav-items">
    <a href="dashboard.php"   class="mob-nav-item"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>Home</a>
    <a href="subadmins.php"   class="mob-nav-item"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>Admins</a>
    <a href="submissions.php" class="mob-nav-item"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>Data</a>
    <a href="locations.php"   class="mob-nav-item"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>Zones</a>
    <a href="reports.php"     class="mob-nav-item active"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>Reports</a>
  </div>
</nav>

<?php pageFooter(); ?>

<script>
const IST_TZ      = 'Asia/Kolkata';
const phpNowUnix  = <?= $phpNowUnix ?>;
const deviceNowMs = Date.now();
const clockOffset = phpNowUnix - Math.floor(deviceNowMs / 1000);

function serverNow() {
    return Math.floor(Date.now() / 1000) + clockOffset;
}
function pad(n) { return String(n).padStart(2, '0'); }
function liveDuration(loginUnix) {
    const diff = serverNow() - loginUnix;
    if (diff <= 0) return '0s';
    const h = Math.floor(diff / 3600);
    const m = Math.floor((diff % 3600) / 60);
    const s = diff % 60;
    if (h > 0) return h + 'h ' + pad(m) + 'm ' + pad(s) + 's';
    if (m > 0) return m + 'm ' + pad(s) + 's';
    return s + 's';
}
function fmtClock(d) {
    const h = d.getHours() % 12 || 12;
    const ampm = d.getHours() >= 12 ? 'PM' : 'AM';
    return pad(h) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds()) + ' ' + ampm;
}
function fmtFullDate(d) {
    const DAYS = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    const MON  = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return DAYS[d.getDay()] + ', ' + pad(d.getDate()) + ' ' + MON[d.getMonth()] + ' ' + d.getFullYear();
}

// ── 9 PM Auto-lock logic ───────────────────────────────────────────────────────
let _lockTriggered = false;
const LOCK_HOUR_IST = 21; // 9 PM IST = hour 21 in 24h

function getISTNow() {
    return new Date(new Date(serverNow() * 1000).toLocaleString('en-US', { timeZone: IST_TZ }));
}

function secsUntilLock() {
    const now = getISTNow();
    const h = now.getHours(), m = now.getMinutes(), s = now.getSeconds();
    // Already past 9 PM today?
    if (h >= LOCK_HOUR_IST) return 0;
    const secsNow = h * 3600 + m * 60 + s;
    const secsLock = LOCK_HOUR_IST * 3600;
    return secsLock - secsNow;
}

function updateLockBar() {
    const bar  = document.getElementById('lock-countdown-bar');
    const txt  = document.getElementById('lock-countdown-text');
    const title = document.getElementById('lock-bar-title');
    if (!bar) return;
    const secs = secsUntilLock();
    if (secs <= 0) {
        // Already 9 PM — show locked state
        bar.style.display = 'flex';
        bar.className = 'lock-countdown-bar already-locked';
        if (title) title.innerHTML = '\ud83d\udd12 <strong>9 PM Lock Active</strong>';
        if (txt)   txt.textContent = '';
        triggerAutoLock();
    } else if (secs <= 7200) { // Show bar within 2 hours of lock
        bar.style.display = 'flex';
        bar.className = 'lock-countdown-bar';
        const hh = Math.floor(secs / 3600);
        const mm = Math.floor((secs % 3600) / 60);
        const ss = secs % 60;
        const countdown = hh > 0 ? hh + 'h ' + pad(mm) + 'm ' + pad(ss) + 's'
                                 : mm + 'm ' + pad(ss) + 's';
        if (title) title.innerHTML = 'Auto-Lock in: <strong id="lock-countdown-text">' + countdown + '</strong>';
    } else {
        bar.style.display = 'none';
    }
}

function triggerAutoLock() {
    if (_lockTriggered) return;
    _lockTriggered = true;
    const formData = new FormData();
    formData.append('csrf', document.querySelector('input[name="csrf"]') ? document.querySelector('input[name="csrf"]').value : '');
    formData.append('action', 'auto_lock_9pm');
    fetch('active_sessions.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                // Refresh page to show updated state
                setTimeout(() => window.location.reload(), 1500);
            }
        })
        .catch(() => {});
}

function unlockUser(uid, name) {
    if (!confirm('Unlock executive "' + name + '"? They will be able to log in again immediately.')) return;
    const csrf = document.querySelector('input[name="csrf"]') ? document.querySelector('input[name="csrf"]').value : '';
    const formData = new FormData();
    formData.append('action', 'unlock_user');
    formData.append('uid', uid);
    formData.append('csrf', csrf);
    fetch('active_sessions.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                window.location.reload();
            } else {
                alert('Unlock failed: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(() => alert('Network error. Please try again.'));
}

function tick() {
    const istNow = getISTNow();
    const clkEl  = document.getElementById('act-clock');
    const dateEl = document.getElementById('act-date');
    if (clkEl)  clkEl.textContent  = fmtClock(istNow);
    if (dateEl) dateEl.textContent = fmtFullDate(istNow);
    document.querySelectorAll('.dur-live[data-login-unix]').forEach(el => {
        const loginUnix = parseInt(el.dataset.loginUnix, 10);
        if (loginUnix) el.textContent = '⏳ ' + liveDuration(loginUnix);
    });
}
tick();
setInterval(tick, 1000);
updateLockBar();
setInterval(updateLockBar, 30000); // Check lock status every 30 seconds

// Auto-refresh the page every 30 seconds to show latest sessions
var _autoRefreshTimer = setTimeout(function() {
  window.location.reload();
}, 30000);
// Cancel auto-refresh if user is interacting (e.g. about to click terminate)
document.addEventListener('click', function() {
  clearTimeout(_autoRefreshTimer);
});
</script>
