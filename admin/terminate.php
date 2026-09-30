<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
requireLogin('main_admin');

$db   = getDB();
$user = currentUser();

// ── ALL POST ACTIONS handled before ANY output ────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'terminate_self') {
        $url = terminateSelf();
        header('Location: ' . $url);
        exit;
    }
    if ($action === 'kill_all') {
        $url = terminateAllSelf();
        header('Location: ' . $url);
        exit;
    }
    if ($action === 'kill_token') {
        $token = trim($_POST['token'] ?? '');
        if ($token) {
            $db->prepare("UPDATE user_sessions SET is_active=0, logged_out_at=NOW() WHERE session_token=? AND user_id=?")
               ->execute([$token, $user['id']]);
            logActivity($user['id'], 'kill_token', 'Killed a specific session token');
        }
        header('Location: ' . APP_URL . '/admin/terminate.php?msg=killed');
        exit;
    }
}

$killed = (($_GET['msg'] ?? '') === 'killed');

$sesStmt = $db->prepare("
    SELECT id, session_token, device_info, ip_address, logged_in_at, last_active,
           (session_token = ?) AS is_current
    FROM   user_sessions
    WHERE  user_id = ? AND is_active = 1
    ORDER  BY is_current DESC, logged_in_at DESC
");
$sesStmt->execute([$_SESSION['token'] ?? '', $user['id']]);
$sessions     = $sesStmt->fetchAll();
$sessionCount = count($sessions);

pageHead('Terminate Session');
topbar($user['username'], $user['role']);
?>
<style>
.term-hero{background:linear-gradient(135deg,#1e1e2e 0%,#2d1b1b 100%);border-radius:12px;padding:32px 28px;color:#fff;margin-bottom:20px;text-align:center;position:relative;overflow:hidden}
.term-hero::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse at center top,rgba(220,38,38,.25) 0%,transparent 70%);pointer-events:none}
.term-hero .hero-icon{width:72px;height:72px;background:rgba(220,38,38,.2);border:2px solid rgba(220,38,38,.5);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:2rem}
.term-hero h1{font-size:1.4rem;font-weight:800;margin-bottom:8px}
.term-hero p{font-size:.88rem;color:rgba(255,255,255,.65);max-width:380px;margin:0 auto;line-height:1.6}
.who-badge{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);border-radius:99px;padding:5px 14px;font-size:.82rem;margin-top:14px;color:rgba(255,255,255,.85)}
.big-btn{display:flex;align-items:center;justify-content:center;gap:12px;width:100%;padding:18px 24px;border:none;border-radius:10px;font-size:1.05rem;font-weight:700;font-family:inherit;cursor:pointer;transition:background .15s,transform .1s;margin-bottom:10px}
.big-btn:active{transform:scale(.97)}
.btn-terminate{background:#dc2626;color:#fff;box-shadow:0 4px 14px rgba(220,38,38,.4)}
.btn-terminate:hover{background:#b91c1c}
.btn-terminate:disabled{background:#9ca3af;cursor:not-allowed;box-shadow:none}
.btn-kill-all{background:#7f1d1d;color:#fca5a5;border:1.5px solid #991b1b;font-size:.95rem}
.btn-kill-all:hover{background:#991b1b;color:#fff}
.session-row{display:flex;align-items:center;gap:14px;padding:13px 16px;border-bottom:1px solid var(--c-gray-100)}
.session-row:last-child{border-bottom:none}
.s-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0;animation:pdot 2s infinite}
.s-dot.live{background:#16a34a;box-shadow:0 0 0 3px rgba(22,163,74,.2)}
.s-dot.curr{background:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.2)}
@keyframes pdot{0%,100%{opacity:1}50%{opacity:.4}}
.s-meta{flex:1;min-width:0}
.s-meta .dev{font-size:.84rem;font-weight:500;color:var(--c-gray-800);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.s-meta .det{font-size:.76rem;color:var(--c-gray-400);margin-top:2px}
.kill-btn{padding:7px 14px;background:#fef2f2;color:#dc2626;border:1px solid #fca5a5;border-radius:6px;font-size:.78rem;font-weight:600;font-family:inherit;cursor:pointer;transition:all .15s;white-space:nowrap;flex-shrink:0}
.kill-btn:hover{background:#dc2626;color:#fff;border-color:#dc2626}
.kill-btn:disabled{opacity:.4;cursor:default;background:#f3f4f6;color:#9ca3af;border-color:#e5e7eb}
.info-strip{background:var(--c-primary-light);border:1px solid #bfdbfe;border-radius:8px;padding:12px 16px;font-size:.82rem;color:#1e40af;display:flex;align-items:flex-start;gap:10px;margin-bottom:20px}
</style>

<div class="app-wrap">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="main-content">

    <div class="term-hero">
      <div class="hero-icon">🔴</div>
      <h1>Emergency Session Terminator</h1>
      <p>Instantly kill your active admin session and return to the login page.</p>
      <div class="who-badge">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        Logged in as: <strong><?= htmlspecialchars($user['username']) ?></strong>
      </div>
    </div>

    <?php if ($killed): ?>
    <div class="alert alert-success" style="margin-bottom:16px">✓ Session killed successfully.</div>
    <?php endif; ?>

    <div class="info-strip">
      <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="flex-shrink:0;margin-top:1px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <div>You have <strong><?= $sessionCount ?> active session<?= $sessionCount !== 1 ? 's' : '' ?></strong>. Click Terminate — you will be logged out immediately.</div>
    </div>

    <!-- Terminate current session -->
    <form method="POST" id="selfForm">
      <input type="hidden" name="csrf"   value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="terminate_self">
      <button type="submit" class="big-btn btn-terminate" id="termBtn"
              onclick="this.disabled=true;this.innerHTML='⏳ Terminating…';this.closest('form').submit();">
        <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
          <circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>
        </svg>
        Terminate My Current Session
      </button>
    </form>

    <!-- Kill ALL -->
    <?php if ($sessionCount > 1): ?>
    <form method="POST" id="killAllForm">
      <input type="hidden" name="csrf"   value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="kill_all">
      <button type="button" class="big-btn btn-kill-all" onclick="if(confirm('Kill ALL <?= $sessionCount ?> sessions across all devices?')){this.disabled=true;this.innerHTML='⏳ Killing all…';document.getElementById(\'killAllForm\').submit();}">
        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6m4-6v6"/>
        </svg>
        Kill ALL <?= $sessionCount ?> Sessions (all devices)
      </button>
    </form>
    <?php endif; ?>

    <!-- Session list -->
    <div class="card">
      <div class="card-title">
        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
        Your Active Sessions
      </div>
      <?php if ($sessions): ?>
      <div>
        <?php foreach ($sessions as $s):
          $isCurrent = (bool)$s['is_current'];
          $ua = $s['device_info'] ?? '';
          preg_match('/(Chrome|Firefox|Safari|Edge|Opera)[\/\s]([\d.]+)/i', $ua, $bm);
          preg_match('/(Windows|Mac OS|Linux|Android|iPhone|iPad)/i', $ua, $om);
          $lbl = trim(($bm ? $bm[1].' '.substr($bm[2],0,5) : 'Browser') . ($om ? ' · '.$om[1] : ''));
        ?>
        <div class="session-row">
          <div class="s-dot <?= $isCurrent ? 'curr' : 'live' ?>"></div>
          <div class="s-meta">
            <div class="dev"><?= $isCurrent ? '⭐ This session — ' : '' ?><?= htmlspecialchars($lbl) ?></div>
            <div class="det">IP <?= htmlspecialchars($s['ip_address']) ?> · Login: <?= date('d M Y, H:i', strtotime($s['logged_in_at'])) ?> · Active: <?= date('H:i', strtotime($s['last_active'])) ?></div>
          </div>
          <?php if ($isCurrent): ?>
            <button class="kill-btn" disabled>Current</button>
          <?php else: ?>
            <form method="POST" style="margin:0">
              <input type="hidden" name="csrf"   value="<?= csrfToken() ?>">
              <input type="hidden" name="action" value="kill_token">
              <input type="hidden" name="token"  value="<?= htmlspecialchars($s['session_token']) ?>">
              <button class="kill-btn" type="submit">Kill</button>
            </form>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="empty-state"><p>No active sessions.</p></div>
      <?php endif; ?>
    </div>

  </div>
</div>

<nav class="mob-nav">
  <div class="mob-nav-items">
    <a href="dashboard.php" class="mob-nav-item"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>Home</a>
    <a href="subadmins.php" class="mob-nav-item"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>Admins</a>
    <a href="submissions.php" class="mob-nav-item"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>Data</a>
    <a href="terminate.php" class="mob-nav-item active"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>Terminate</a>
  </div>
</nav>
<?php pageFooter(); ?>