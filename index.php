<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

// Redirect if already logged in
if (isLoggedIn()) {
    $role = $_SESSION['role'] ?? '';
    if($role === 'main_admin') header('Location: ' . APP_URL . '/admin/dashboard.php'); elseif($role === 'office_admin') header('Location: ' . APP_URL . '/office_admin/subadmins.php'); else header('Location: ' . APP_URL . '/subadmin/dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = loginUser(trim($_POST['username'] ?? ''), $_POST['password'] ?? '');

    if ($result['ok']) {
        $rawDeviceIso = trim($_POST['device_login_iso'] ?? '');
        if ($rawDeviceIso && isset($_SESSION['token'])) {
            $dtDevice = DateTime::createFromFormat('Y-m-d\TH:i:s', $rawDeviceIso);
            if (!$dtDevice) $dtDevice = DateTime::createFromFormat('Y-m-d\TH:i', $rawDeviceIso);
            if ($dtDevice) {
                $deviceLoginTime = $dtDevice->format('Y-m-d H:i:s');
                try {
                    $db = getDB();
                    $db->prepare("
                        UPDATE user_sessions
                        SET device_login_time = ?
                        WHERE session_token = ?
                    ")->execute([$deviceLoginTime, $_SESSION['token']]);
                } catch (Throwable $e) {}
            }
        }

        if ($result['role'] === 'main_admin') {
            header('Location: ' . APP_URL . '/admin/dashboard.php');
        } elseif ($result['role'] === 'office_admin') {
            header('Location: ' . APP_URL . '/office_admin/subadmins.php');
        } else {
            header('Location: ' . APP_URL . '/subadmin/dashboard.php');
        }
        exit;
    }
    $error = $result['error'];
}

$loginReason = $_GET['reason'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Login — Aandavar FieldApp</title>

<!-- ═══════════════════════════════════════════════════════════
     PWA — Web App Manifest
     ════════════════════════════════════════════════════════ -->
<link rel="manifest" href="<?= APP_URL ?>/manifest.json">

<!-- ── Theme color (browser UI bar color) ── -->
<meta name="theme-color" content="#2563eb">
<meta name="mobile-web-app-capable" content="yes">

<!-- ── iOS / Safari PWA support ── -->
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="FieldApp">

<!-- ── iOS icons (Apple does not use manifest icons) ── -->
<link rel="apple-touch-icon" href="<?= APP_URL ?>/assets/pwa/icon-152x152.png">
<link rel="apple-touch-icon" sizes="72x72"   href="<?= APP_URL ?>/assets/pwa/icon-72x72.png">
<link rel="apple-touch-icon" sizes="96x96"   href="<?= APP_URL ?>/assets/pwa/icon-96x96.png">
<link rel="apple-touch-icon" sizes="128x128" href="<?= APP_URL ?>/assets/pwa/icon-128x128.png">
<link rel="apple-touch-icon" sizes="144x144" href="<?= APP_URL ?>/assets/pwa/icon-144x144.png">
<link rel="apple-touch-icon" sizes="152x152" href="<?= APP_URL ?>/assets/pwa/icon-152x152.png">
<link rel="apple-touch-icon" sizes="192x192" href="<?= APP_URL ?>/assets/pwa/icon-192x192.png">
<link rel="apple-touch-icon" sizes="512x512" href="<?= APP_URL ?>/assets/pwa/icon-512x512.png">

<!-- ── iOS splash screens ── -->
<!-- iPhone SE / 6 / 7 / 8 -->
<link rel="apple-touch-startup-image" media="(device-width: 375px) and (device-height: 667px) and (-webkit-device-pixel-ratio: 2)"
      href="<?= APP_URL ?>/assets/pwa/splash-750x1334.png">
<!-- iPhone X / XS / 11 Pro -->
<link rel="apple-touch-startup-image" media="(device-width: 375px) and (device-height: 812px) and (-webkit-device-pixel-ratio: 3)"
      href="<?= APP_URL ?>/assets/pwa/splash-1125x2436.png">
<!-- iPhone 12 / 13 / 14 -->
<link rel="apple-touch-startup-image" media="(device-width: 390px) and (device-height: 844px) and (-webkit-device-pixel-ratio: 3)"
      href="<?= APP_URL ?>/assets/pwa/splash-1170x2532.png">
<!-- iPhone 14 Plus / 15 Plus -->
<link rel="apple-touch-startup-image" media="(device-width: 430px) and (device-height: 932px) and (-webkit-device-pixel-ratio: 3)"
      href="<?= APP_URL ?>/assets/pwa/splash-1290x2796.png">

<!-- ── Favicon ── -->
<link rel="icon" type="image/png" sizes="192x192" href="<?= APP_URL ?>/assets/pwa/icon-192x192.png">
<link rel="shortcut icon" href="<?= APP_URL ?>/assets/pwa/icon-96x96.png">

<!-- ── SEO / description ── -->
<meta name="description" content="Aandavar Field Data Collection System — secure login for field agents and administrators.">
<meta name="application-name" content="Aandavar FieldApp">

<!-- ═══════════════════════════════════════════════════════════
     END PWA tags — everything below is unchanged from original
     ════════════════════════════════════════════════════════ -->

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--c-primary:#2563eb;--c-primary-d:#1d4ed8;--c-gray-50:#f9fafb;--c-gray-100:#f3f4f6;--c-gray-200:#e5e7eb;--c-gray-300:#d1d5db;--c-gray-500:#6b7280;--c-gray-700:#374151;--c-gray-800:#1f2937;--c-gray-900:#111827;--c-danger:#dc2626;--c-danger-light:#fef2f2;--radius:10px;--radius-sm:6px}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:Inter,system-ui,sans-serif;background:linear-gradient(135deg,#1e3a5f 0%,#2563eb 50%,#1e40af 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:16px}
/* Safe area padding for iPhone notch/home bar */
body { padding-bottom: max(16px, env(safe-area-inset-bottom)); padding-top: max(16px, env(safe-area-inset-top)); }
.login-wrap{width:100%;max-width:400px}
.login-logo{text-align:center;margin-bottom:28px;color:#fff}
.login-logo-icon{width:60px;height:60px;background:rgba(255,255,255,.15);border-radius:14px;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;backdrop-filter:blur(8px)}
.login-logo h1{font-size:1.5rem;font-weight:700}
.login-logo p{font-size:.88rem;opacity:.8;margin-top:4px}
.login-card{background:#fff;border-radius:var(--radius);padding:28px 24px;box-shadow:0 20px 50px rgba(0,0,0,.25)}
.card-title{font-size:1.1rem;font-weight:600;color:var(--c-gray-900);margin-bottom:20px}
.form-group{margin-bottom:14px}
.form-label{display:block;font-size:.85rem;font-weight:500;color:var(--c-gray-700);margin-bottom:5px}
.input-wrap{position:relative}
.form-control{width:100%;padding:10px 12px;border:1px solid var(--c-gray-300);border-radius:var(--radius-sm);font-size:.9rem;font-family:inherit;outline:none;transition:border .15s,box-shadow .15s}
.form-control:focus{border-color:var(--c-primary);box-shadow:0 0 0 3px rgba(37,99,235,.15)}
.eye-btn{position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--c-gray-500);padding:2px}
.btn-submit{width:100%;padding:11px;background:var(--c-primary);color:#fff;border:none;border-radius:var(--radius-sm);font-size:.95rem;font-weight:600;font-family:inherit;cursor:pointer;transition:background .15s;margin-top:6px}
.btn-submit:hover{background:var(--c-primary-d)}
.alert-error{background:var(--c-danger-light);color:#991b1b;border:1px solid #fca5a5;border-radius:var(--radius-sm);padding:10px 14px;font-size:.88rem;margin-bottom:14px}
.notice{border-radius:8px;padding:14px 18px;margin-bottom:18px;display:flex;align-items:flex-start;gap:10px;font-size:.9rem}
.notice-warn{background:#fef3c7;color:#92400e;border:1px solid #fde68a}
.notice-red{background:#fef2f2;color:#991b1b;border:1px solid #fca5a5}
.notice-icon{font-size:1.2rem;line-height:1;flex-shrink:0}

/* PWA install banner */
#pwa-banner{display:none;background:#1e40af;color:#fff;padding:10px 16px;border-radius:10px;margin-bottom:16px;align-items:center;gap:12px;font-size:.88rem;box-shadow:0 4px 12px rgba(0,0,0,.3)}
#pwa-banner.show{display:flex}
#pwa-install-btn{background:#fff;color:#1e40af;border:none;border-radius:6px;padding:6px 14px;font-weight:600;font-size:.82rem;cursor:pointer;white-space:nowrap;font-family:inherit}
#pwa-dismiss{background:none;border:none;color:rgba(255,255,255,.7);cursor:pointer;font-size:1.1rem;margin-left:auto;padding:2px 6px}
</style>
</head>
<body>
<div class="login-wrap">

  <!-- ── PWA Install Banner (shown automatically when installable) ── -->
  <div id="pwa-banner">
    <span>📲</span>
    <span>Install <strong>FieldApp</strong> on your device for quick access.</span>
    <button id="pwa-install-btn">Install</button>
    <button id="pwa-dismiss" aria-label="Dismiss">✕</button>
  </div>

  <div class="login-logo">
    <div class="login-logo-icon">
      <img src="<?= APP_URL ?>/assets/logo.png" alt="Logo" style="width:100%;height:100%;object-fit:fill">
    </div>
    <h1>Aandavar FieldApp</h1>
    <p>Field Data Collection System</p>
  </div>

  <?php if ($loginReason === 'locked'): ?>
  <div class="notice notice-red">
    <span class="notice-icon">🔒</span>
    <div><strong>Your account has been locked.</strong><br>Please contact your main administrator to regain access.</div>
  </div>
  <?php endif; ?>

  <?php if ($loginReason === 'expired'): ?>
  <div class="notice notice-warn">
    <span class="notice-icon">⚠️</span>
    <div><strong>Your session has expired.</strong> Please log in again.</div>
  </div>
  <?php endif; ?>

  <?php if ($loginReason === 'timeout'): ?>
  <div class="notice notice-warn">
    <span class="notice-icon">⏱️</span>
    <div><strong>Session timed out due to 15 minutes of inactivity.</strong><br>Please log in again to continue.</div>
  </div>
  <?php endif; ?>

  <?php if ($loginReason === 'terminated'): ?>
  <div class="notice notice-red">
    <span class="notice-icon">🔴</span>
    <div><strong>Session terminated successfully.</strong><br>You have been logged out. Log in again when ready.</div>
  </div>
  <?php endif; ?>

  <div class="login-card">
    <div class="card-title">Sign in to your account</div>
    <?php if ($error): ?>
      <div class="alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="POST" autocomplete="off" id="login-form">
      <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
      <input type="hidden" name="device_login_iso" id="device_login_iso" value="">
      <div class="form-group">
        <label class="form-label" for="username">Username</label>
        <input class="form-control" type="text" id="username" name="username"
               autocomplete="username" required placeholder="Enter username"
               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label class="form-label" for="password">Password</label>
        <div class="input-wrap">
          <input class="form-control" type="password" id="password" name="password"
                 autocomplete="current-password" required placeholder="Enter password"
                 style="padding-right:38px">
          <button type="button" class="eye-btn" onclick="togglePwd()" aria-label="Show/hide">
            <svg id="eye-icon" width="18" height="18" fill="none" stroke="currentColor"
                 stroke-width="2" viewBox="0 0 24 24">
              <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
              <circle cx="12" cy="12" r="3"/>
            </svg>
          </button>
        </div>
      </div>
      <button type="submit" class="btn-submit">Sign In</button>
    </form>
  </div>
</div>

<script>
// ── Original functions (unchanged) ───────────────────────────
function togglePwd() {
    const p = document.getElementById('password');
    p.type = p.type === 'password' ? 'text' : 'password';
}
function pad(n) { return String(n).padStart(2, '0'); }
function nowISO() {
    const n = new Date();
    return n.getFullYear() + '-' + pad(n.getMonth() + 1) + '-' + pad(n.getDate()) +
           'T' + pad(n.getHours()) + ':' + pad(n.getMinutes()) + ':' + pad(n.getSeconds());
}
document.getElementById('login-form').addEventListener('submit', function () {
    document.getElementById('device_login_iso').value = nowISO();
});

// ═══════════════════════════════════════════════════════════
// PWA: Service Worker Registration
// ═══════════════════════════════════════════════════════════
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/aandavar_fixed/sw.js', { scope: '/aandavar_fixed/' })
            .then(reg => console.log('[SW] Registered, scope:', reg.scope))
            .catch(err => console.warn('[SW] Registration failed:', err));
    });
}

// ═══════════════════════════════════════════════════════════
// PWA: Install Banner (Android / Chrome / Edge)
// iOS Safari shows "Add to Home Screen" in the Share menu — no
// beforeinstallprompt fires on iOS, so the banner is hidden there.
// ═══════════════════════════════════════════════════════════
let _deferredInstallPrompt = null;
const banner     = document.getElementById('pwa-banner');
const installBtn = document.getElementById('pwa-install-btn');
const dismissBtn = document.getElementById('pwa-dismiss');

window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    _deferredInstallPrompt = e;

    // Don't show banner if user already dismissed it this session
    if (!sessionStorage.getItem('pwa-banner-dismissed')) {
        banner.classList.add('show');
    }
});

installBtn.addEventListener('click', async () => {
    if (!_deferredInstallPrompt) return;
    _deferredInstallPrompt.prompt();
    const { outcome } = await _deferredInstallPrompt.userChoice;
    console.log('[PWA] Install outcome:', outcome);
    _deferredInstallPrompt = null;
    banner.classList.remove('show');
});

dismissBtn.addEventListener('click', () => {
    banner.classList.remove('show');
    sessionStorage.setItem('pwa-banner-dismissed', '1');
});

// Hide banner once app is installed
window.addEventListener('appinstalled', () => {
    banner.classList.remove('show');
    console.log('[PWA] App installed successfully.');
});
</script>
</body>
</html>
