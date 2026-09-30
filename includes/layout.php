<?php
// ============================================================
// layout.php — Shared HTML helpers (Updated UI)
// ============================================================
function pageHead(string $title, string $extra = ''): void { ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<!-- ── Permissions Policy: allow geolocation, camera & microphone from same origin ── -->
<meta http-equiv="Permissions-Policy" content="geolocation=(self), camera=(self), microphone=(self)">
<title><?= htmlspecialchars($title) ?> — Aandavar FieldApp</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
/* ── Reset & Tokens ── */
:root {
  --c-primary:#2563eb;--c-primary-d:#1d4ed8;--c-primary-light:#eff6ff;
  --c-success:#16a34a;--c-success-light:#f0fdf4;
  --c-warn:#d97706;--c-warn-light:#fffbeb;
  --c-danger:#dc2626;--c-danger-light:#fef2f2;
  --c-gray-50:#f9fafb;--c-gray-100:#f3f4f6;--c-gray-200:#e5e7eb;
  --c-gray-300:#d1d5db;--c-gray-400:#9ca3af;--c-gray-500:#6b7280;
  --c-gray-600:#4b5563;--c-gray-700:#374151;--c-gray-800:#1f2937;--c-gray-900:#111827;
  --c-hot:#16a34a;--c-warm:#d97706;--c-cold:#dc2626;
  --radius:10px;--radius-sm:6px;--radius-lg:14px;
  --shadow:0 1px 3px rgba(0,0,0,.08),0 1px 2px rgba(0,0,0,.05);
  --shadow-md:0 4px 12px rgba(0,0,0,.08),0 2px 4px rgba(0,0,0,.05);
  --shadow-lg:0 8px 24px rgba(0,0,0,.1),0 4px 8px rgba(0,0,0,.06);
  --font:'Plus Jakarta Sans',system-ui,sans-serif;
  --topbar-h:60px;
  --footer-h:44px;
  --sidebar-w:248px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{font-size:16px;scroll-behavior:smooth}
body{font-family:var(--font);background:var(--c-gray-50);color:var(--c-gray-800);line-height:1.5}
a{color:var(--c-primary);text-decoration:none}

/* ── Topbar ── */
.topbar{
  background:#fff;
  border-bottom:1px solid var(--c-gray-200);
  padding:0 20px;
  height:var(--topbar-h);
  display:flex;
  align-items:center;
  justify-content:space-between;
  position:sticky;top:0;z-index:100;
  box-shadow:var(--shadow);
}
.topbar-left{display:flex;align-items:center;gap:12px}
.topbar-brand{display:flex;align-items:center;gap:10px;text-decoration:none}
.topbar-logo{
  width:40px;height:40px;
  background:linear-gradient(135deg,var(--c-primary) 0%,#1e40af 100%);
  border-radius:10px;
  display:flex;align-items:center;justify-content:center;
  box-shadow:0 2px 8px rgba(37,99,235,.35);
  flex-shrink:0;
}
.topbar-logo svg{width:22px;height:22px;color:#fff;stroke:#fff}
.topbar-brand-name{
  font-size:1rem;font-weight:800;
  background:linear-gradient(90deg,var(--c-primary),#1e40af);
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;
  line-height:1.1;letter-spacing:-.02em;
}
.topbar-brand-sub{font-size:.65rem;color:var(--c-gray-400);font-weight:500;letter-spacing:.04em;text-transform:uppercase;line-height:1}
.topbar-right{display:flex;align-items:center;gap:10px}
.topbar-user{font-size:.82rem;color:var(--c-gray-600)}
.topbar-user strong{color:var(--c-gray-800);font-weight:600}

/* ── Layout: sidebar + content sit side-by-side ── */
.app-wrap{display:flex;min-height:calc(100vh - var(--topbar-h));}

/* Sidebar */
.sidebar{
  width:var(--sidebar-w);
  flex-shrink:0;
  background:#fff;
  border-right:1px solid var(--c-gray-200);
  padding:12px 0;
  position:sticky;
  top:var(--topbar-h);
  height:calc(100vh - var(--topbar-h));
  overflow-y:auto;
}
.sidebar-section{
  padding:12px 18px 5px;
  font-size:.67rem;font-weight:700;
  text-transform:uppercase;letter-spacing:.08em;
  color:var(--c-gray-400);
}
.nav-link{
  display:flex;align-items:center;gap:10px;
  padding:9px 18px;
  font-size:.875rem;color:var(--c-gray-600);
  border-radius:0;transition:background .15s,color .15s;cursor:pointer;
  position:relative;
}
.nav-link:hover{background:var(--c-gray-50);color:var(--c-gray-900)}
.nav-link.active{background:var(--c-primary-light);color:var(--c-primary);font-weight:600;}
.nav-link.active::before{
  content:'';position:absolute;left:0;top:4px;bottom:4px;
  width:3px;background:var(--c-primary);border-radius:0 3px 3px 0;
}
.nav-link svg{flex-shrink:0;opacity:.65;width:18px;height:18px}
.nav-link.active svg{opacity:1}

/* Main content */
.main-content{flex:1;padding:24px;min-width:0;}

/* ── Footer ── */
.app-footer{
  background:#fff;
  border-top:1px solid var(--c-gray-200);
  padding:0 20px;
  height:var(--footer-h);
  display:flex;align-items:center;justify-content:center;
}
.footer-inner{
  display:flex;align-items:center;gap:8px;
  font-size:.78rem;color:var(--c-gray-400);
  flex-wrap:wrap;justify-content:center;
}
.footer-brand{
  display:flex;align-items:center;gap:6px;
  font-weight:700;color:var(--c-gray-700);
  font-size:.82rem;
}
.footer-logo-mark{
  width:22px;height:22px;
  background:linear-gradient(135deg,#2563eb,#7c3aed);
  border-radius:5px;
  display:flex;align-items:center;justify-content:center;
}
.footer-logo-mark svg{width:13px;height:13px;stroke:#fff}
.footer-divider{width:1px;height:14px;background:var(--c-gray-200)}

/* ── Cards ── */
.card{background:#fff;border:1px solid var(--c-gray-200);border-radius:var(--radius);padding:20px;margin-bottom:16px;box-shadow:var(--shadow)}
.card-title{font-size:1rem;font-weight:700;color:var(--c-gray-800);margin-bottom:16px;display:flex;align-items:center;gap:8px}

/* ── Stats ── */
.stat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(168px,1fr));gap:14px;margin-bottom:20px}
.stat-card{background:#fff;border:1px solid var(--c-gray-200);border-radius:var(--radius);padding:18px;box-shadow:var(--shadow);transition:box-shadow .2s,transform .2s}
.stat-card:hover{box-shadow:var(--shadow-md);transform:translateY(-1px)}
.stat-val{font-size:1.85rem;font-weight:800;color:var(--c-gray-900);letter-spacing:-.03em}
.stat-label{font-size:.78rem;color:var(--c-gray-500);margin-top:3px;font-weight:500}
.stat-card.blue{border-left:4px solid var(--c-primary)}
.stat-card.green{border-left:4px solid var(--c-success)}
.stat-card.warn{border-left:4px solid var(--c-warn)}
.stat-card.red{border-left:4px solid var(--c-danger)}

/* ── Forms ── */
.form-group{margin-bottom:15px}
.form-label{display:block;font-size:.84rem;font-weight:600;color:var(--c-gray-700);margin-bottom:5px}
.form-label .req{color:var(--c-danger)}
.form-control{width:100%;padding:9px 12px;border:1px solid var(--c-gray-300);border-radius:var(--radius-sm);font-size:.875rem;font-family:var(--font);background:#fff;transition:border .15s,box-shadow .15s;outline:none;color:var(--c-gray-800)}
.form-control:focus{border-color:var(--c-primary);box-shadow:0 0 0 3px rgba(37,99,235,.12)}
.form-control::placeholder{color:var(--c-gray-400)}
select.form-control{cursor:pointer}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.form-hint{font-size:.75rem;color:var(--c-gray-400);margin-top:4px}

/* ── Buttons ── */
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border:none;border-radius:var(--radius-sm);font-size:.875rem;font-weight:600;font-family:var(--font);cursor:pointer;transition:background .15s,transform .1s,box-shadow .15s;text-decoration:none;white-space:nowrap}
.btn:active{transform:scale(.97)}
.btn-primary{background:var(--c-primary);color:#fff;box-shadow:0 1px 4px rgba(37,99,235,.3)}.btn-primary:hover{background:var(--c-primary-d);box-shadow:0 2px 8px rgba(37,99,235,.4)}
.btn-success{background:var(--c-success);color:#fff}.btn-success:hover{background:#15803d}
.btn-danger{background:var(--c-danger);color:#fff}.btn-danger:hover{background:#b91c1c}
.btn-ghost{background:var(--c-gray-100);color:var(--c-gray-700);border:1px solid var(--c-gray-200)}.btn-ghost:hover{background:var(--c-gray-200)}
.btn-outline{background:transparent;color:var(--c-primary);border:1.5px solid var(--c-primary)}.btn-outline:hover{background:var(--c-primary-light)}
.btn-sm{padding:6px 12px;font-size:.8rem}
.btn-icon{padding:7px;border-radius:var(--radius-sm);background:transparent;border:none;cursor:pointer;color:var(--c-gray-500);display:inline-flex;align-items:center;transition:background .15s,color .15s}
.btn-icon:hover{background:var(--c-gray-100);color:var(--c-gray-800)}

/* ── Tables ── */
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;border-radius:var(--radius);border:1px solid var(--c-gray-200)}
table{width:100%;border-collapse:collapse;font-size:.875rem}
thead tr{background:var(--c-gray-50)}
th{padding:11px 14px;text-align:left;font-weight:700;color:var(--c-gray-600);border-bottom:2px solid var(--c-gray-200);white-space:nowrap;font-size:.8rem;letter-spacing:.02em}
td{padding:11px 14px;border-bottom:1px solid var(--c-gray-100);vertical-align:middle}
tr:last-child td{border-bottom:none}
tr:hover td{background:var(--c-gray-50)}

/* ── Badges ── */
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:99px;font-size:.73rem;font-weight:600}
.badge-hot{background:var(--c-success-light);color:var(--c-success)}
.badge-warm{background:var(--c-warn-light);color:var(--c-warn)}
.badge-cold{background:var(--c-danger-light);color:var(--c-danger)}
.badge-blue{background:var(--c-primary-light);color:var(--c-primary)}
.badge-gray{background:var(--c-gray-100);color:var(--c-gray-600)}
.badge-dot{width:6px;height:6px;border-radius:50%;background:currentColor;display:inline-block}

/* ── Alerts ── */
.alert{padding:12px 16px;border-radius:var(--radius-sm);font-size:.875rem;margin-bottom:14px;border-left:4px solid;display:flex;align-items:flex-start;gap:10px}
.alert-success{background:var(--c-success-light);color:#166534;border-color:var(--c-success)}
.alert-error{background:var(--c-danger-light);color:#991b1b;border-color:var(--c-danger)}
.alert-warn{background:var(--c-warn-light);color:#92400e;border-color:var(--c-warn)}
.alert-info{background:var(--c-primary-light);color:#1e40af;border-color:var(--c-primary)}

/* ── Modal ── */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;display:none;align-items:center;justify-content:center;padding:16px;backdrop-filter:blur(2px)}
.modal-overlay.open{display:flex}
.modal{background:#fff;border-radius:var(--radius-lg);padding:24px;width:100%;max-width:500px;max-height:90vh;overflow-y:auto;box-shadow:var(--shadow-lg);animation:modal-in .2s ease}
@keyframes modal-in{from{opacity:0;transform:scale(.95) translateY(8px)}to{opacity:1;transform:none}}
.modal-title{font-size:1.05rem;font-weight:700;margin-bottom:16px;color:var(--c-gray-900)}
.modal-footer{display:flex;gap:10px;justify-content:flex-end;margin-top:16px;padding-top:14px;border-top:1px solid var(--c-gray-200)}

/* ── Page header ── */
.page-header{margin-bottom:22px;display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px}
.page-header-left{}
.page-title{font-size:1.35rem;font-weight:800;color:var(--c-gray-900);letter-spacing:-.02em}
.page-sub{font-size:.85rem;color:var(--c-gray-500);margin-top:3px}
.page-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}

/* ── Upload preview ── */
.img-preview{margin-top:8px;max-width:120px;max-height:100px;border-radius:var(--radius-sm);border:1px solid var(--c-gray-200);object-fit:cover;display:none}

/* ── Divider ── */
.divider{height:1px;background:var(--c-gray-200);margin:16px 0}

/* ── Coord display ── */
.coord-box{background:var(--c-gray-50);border:1px solid var(--c-gray-200);padding:8px 12px;border-radius:var(--radius-sm);font-size:.82rem;color:var(--c-gray-600);font-family:monospace}

/* ── Empty state ── */
.empty-state{text-align:center;padding:56px 24px;color:var(--c-gray-400)}
.empty-state svg{margin-bottom:12px;opacity:.4}
.empty-state p{font-size:.9rem;line-height:1.6}

/* ── Toggle ── */
.toggle-wrap{display:flex;align-items:center;gap:8px}
.toggle{position:relative;width:40px;height:22px;cursor:pointer}
.toggle input{opacity:0;width:0;height:0}
.toggle-slider{position:absolute;inset:0;background:var(--c-gray-300);border-radius:99px;transition:.2s}
.toggle-slider::before{content:"";position:absolute;width:18px;height:18px;left:2px;top:2px;background:#fff;border-radius:50%;transition:.2s;box-shadow:0 1px 3px rgba(0,0,0,.2)}
.toggle input:checked + .toggle-slider{background:var(--c-primary)}
.toggle input:checked + .toggle-slider::before{transform:translateX(18px)}

/* ── Session Timeout Modal ── */
#session-timeout-overlay{
  position:fixed;inset:0;background:rgba(0,0,0,.55);
  display:none;align-items:center;justify-content:center;
  z-index:9999;padding:16px;backdrop-filter:blur(3px);
}
#session-timeout-overlay.show{display:flex}
#session-timeout-box{
  background:#fff;border-radius:var(--radius-lg);padding:32px 28px;
  width:100%;max-width:360px;text-align:center;box-shadow:var(--shadow-lg);
}
.sto-icon{font-size:2.5rem;margin-bottom:12px}
#session-timeout-box h3{font-size:1.1rem;font-weight:700;color:var(--c-gray-900);margin-bottom:8px}
#session-timeout-box p{font-size:.875rem;color:var(--c-gray-500);margin-bottom:16px}
.sto-count{font-size:2.8rem;font-weight:800;color:var(--c-danger);letter-spacing:-.04em;margin-bottom:20px;font-variant-numeric:tabular-nums}
.sto-btns{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}
.sto-stay{background:var(--c-primary);color:#fff;border:none;padding:10px 20px;border-radius:var(--radius-sm);font-size:.875rem;font-weight:600;font-family:var(--font);cursor:pointer;transition:background .15s}
.sto-stay:hover{background:var(--c-primary-d)}
.sto-out{background:var(--c-gray-100);color:var(--c-gray-700);border:1px solid var(--c-gray-200);padding:10px 20px;border-radius:var(--radius-sm);font-size:.875rem;font-weight:600;font-family:var(--font);cursor:pointer;transition:background .15s}
.sto-out:hover{background:var(--c-gray-200)}

/* ── Mobile nav ── */
.mob-nav{display:none;position:fixed;bottom:0;left:0;right:0;background:#fff;border-top:1px solid var(--c-gray-200);z-index:100;padding:6px 0 env(safe-area-inset-bottom)}
.mob-nav-items{display:flex;justify-content:space-around}
.mob-nav-item{display:flex;flex-direction:column;align-items:center;gap:3px;padding:5px 10px;font-size:.63rem;color:var(--c-gray-500);cursor:pointer;border-radius:var(--radius-sm);transition:color .15s;font-weight:500}
.mob-nav-item.active{color:var(--c-primary)}
.mob-nav-item svg{width:22px;height:22px}

/* ── Hamburger ── */
.ham-btn{display:none;background:none;border:none;cursor:pointer;padding:6px;color:var(--c-gray-700);border-radius:var(--radius-sm)}
.ham-btn:hover{background:var(--c-gray-100)}

/* ── Sidebar backdrop (mobile only) ── */
.sidebar-backdrop{
  display:none;position:fixed;inset:0;
  background:rgba(0,0,0,.4);z-index:149;
}
.sidebar-backdrop.show{display:block}

/* ── Responsive ── */
@media(max-width:1024px){
  :root{--sidebar-w:220px}
}

@media(max-width:768px){
  .ham-btn{display:flex;align-items:center;justify-content:center}
  .mob-nav{display:block}

  .sidebar{
    display:none;
    position:fixed;
    top:var(--topbar-h);left:0;bottom:0;
    width:270px;
    height:auto;
    z-index:150;
    box-shadow:var(--shadow-lg);
    overflow-y:auto;
  }
  .sidebar.mob-open{
    display:block;
    animation:sidebar-slide-in .22s ease;
  }
  @keyframes sidebar-slide-in{
    from{transform:translateX(-100%);opacity:.5}
    to{transform:translateX(0);opacity:1}
  }

  .app-wrap{display:block}
  .main-content{padding:16px 14px 80px;width:100%}

  .form-row{grid-template-columns:1fr}
  .stat-grid{grid-template-columns:1fr 1fr}
  .modal{padding:20px;border-radius:var(--radius)}
  .page-header{flex-direction:column;align-items:flex-start}
  .topbar-brand-sub{display:none}
  .topbar-user strong{display:none}
  .app-footer{padding-bottom:4px}
}

@media(max-width:480px){
  .stat-grid{grid-template-columns:1fr}
  .topbar{padding:0 14px}
  .main-content{padding:14px 12px 80px}
  .btn-sm-hide{display:none}
}

@media(max-width:360px){
  .topbar-logo{width:34px;height:34px}
  .topbar-brand-name{font-size:.9rem}
}
</style>
<?= $extra ?>
</head>
<body>
<?php }

function topbar(string $username, string $role): void { ?>
<header class="topbar">
  <div class="topbar-left">
    <button class="ham-btn" onclick="toggleSidebar()" aria-label="Open menu">
      <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>
    <a href="<?= APP_URL ?>" class="topbar-brand">
      <div class="topbar-logo">
        <img src="<?= APP_URL ?>/assets/logo.png" alt="Logo" style="width:100%;height:100%;object-fit:fill">
      </div>
      <div>
        <div class="topbar-brand-name">Aandavar FieldApp</div>
        <div class="topbar-brand-sub">Field Management System</div>
      </div>
    </a>
  </div>
  <div class="topbar-right">
    <span class="topbar-user">
      <strong><?= htmlspecialchars($username) ?></strong>
      <span class="badge badge-blue" style="vertical-align:middle;margin-left:4px">
        <?= $role === 'main_admin' ? 'Main Admin' : ($role === 'office_admin' ? 'Sales Executive' : 'Marketing Executive') ?>
      </span>
    </span>
    <a href="<?= APP_URL ?>/logout.php" class="btn btn-ghost btn-sm">
      <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      <span class="btn-sm-hide">Logout</span>
    </a>
  </div>
</header>
<!-- Mobile sidebar backdrop -->
<div class="sidebar-backdrop" id="sidebar-backdrop" onclick="toggleSidebar()"></div>
<?php
$_idleSec = adminIdleSecondsLeft();
if ($_idleSec > 0):
$__idleTimeout = _sessionTimeoutForRole($role);
?>
<script>
window.__adminIdleSecondsLeft = <?= (int)$_idleSec ?>;
window.__adminIdleTimeout = <?= (int)$__idleTimeout ?>;
window.__appUrl = '<?= addslashes(APP_URL) ?>';
</script>
<?php endif; ?>
<?php }

function alert(string $msg, string $type = 'info'): void {
    if ($msg) echo "<div class='alert alert-$type'>$msg</div>";
}

function pageFooter(): void { ?>
<script>
function toggleSidebar(){
  const s = document.querySelector('.sidebar');
  const b = document.getElementById('sidebar-backdrop');
  const open = s.classList.toggle('mob-open');
  if(b) b.classList.toggle('show', open);
  document.body.style.overflow = open ? 'hidden' : '';
}
// Close sidebar on outside click (non-backdrop)
document.addEventListener('click', e => {
  const s = document.querySelector('.sidebar');
  const b = document.getElementById('sidebar-backdrop');
  if(s && s.classList.contains('mob-open') && !s.contains(e.target) && !e.target.closest('.ham-btn') && !e.target.closest('#sidebar-backdrop')) {
    s.classList.remove('mob-open');
    if(b) b.classList.remove('show');
    document.body.style.overflow = '';
  }
});
function openModal(id){document.getElementById(id).classList.add('open')}
function closeModal(id){document.getElementById(id).classList.remove('open')}
function previewImg(input, previewId){
  const file = input.files[0];
  if(!file) return;
  const r = new FileReader();
  r.onload = e => {
    const img = document.getElementById(previewId);
    img.src = e.target.result;
    img.style.display = 'block';
  };
  r.readAsDataURL(file);
}
</script>

<!-- ── Footer ── -->
<footer class="app-footer">
  <div class="footer-inner">
    <span>Powered by</span>
    <div class="footer-brand">
      <div class="footer-logo-mark">
        <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
          <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
        </svg>
      </div>
      Gurushasta Innotech Private Limited
    </div>
    <div class="footer-divider"></div>
    <span><?= date('Y') ?> &copy; All rights reserved</span>
  </div>
</footer>

<!-- ── Admin Session Timeout Modal ── -->
<div id="session-timeout-overlay" role="dialog" aria-modal="true" aria-labelledby="sto-title">
  <div id="session-timeout-box">
    <div class="sto-icon">⏱️</div>
    <h3 id="sto-title">Session Expiring Soon</h3>
    <p>You have been inactive. Choose an option or your session will automatically end in:</p>
    <div class="sto-count" id="sto-countdown">120</div>
    <div class="sto-btns">
      <button class="sto-stay" onclick="sessionStay()">Stay Logged In</button>
      <button class="sto-out"  onclick="sessionLogout()">Logout Now</button>
    </div>
  </div>
</div>

<script>
(function() {
  var IDLE_SECONDS_LEFT = window.__adminIdleSecondsLeft || 0;
  if (!IDLE_SECONDS_LEFT) return;

  var PING_URL     = (window.__appUrl || '') + '/session_ping.php';
  var IDLE_TIMEOUT = window.__adminIdleTimeout || IDLE_SECONDS_LEFT;
  var WARNING_AT   = 120;
  var CHECK_EVERY  = 10;
  var PING_EVERY   = 10; // refresh server promptly while user is active
  var KEEPALIVE_EVERY = 60; // backstop for focused tabs when some UI actions don't emit regular DOM events
  var PRESENCE_GRACE  = Math.min(IDLE_TIMEOUT, 600);
  var overlay      = document.getElementById('session-timeout-overlay');
  var countEl      = document.getElementById('sto-countdown');
  var countdownInt = null;
  var checkInt     = null;
  var secondsLeft  = IDLE_SECONDS_LEFT;
  var modalShown   = false;
  var lastActivityPing = 0;
  var lastKeepalivePing = 0;
  var lastLocalActivity = Date.now();
  var pingInFlight = false;
  var activitySinceLastPing = false; // track if real activity happened
  var logoutCheckInFlight = false;
  var presenceUntil = Date.now() + (PRESENCE_GRACE * 1000);

  function fmt(s) { return String(Math.max(0, s)); }
  function localSecondsLeft() {
    return Math.max(0, IDLE_TIMEOUT - Math.floor((Date.now() - lastLocalActivity) / 1000));
  }
  function markPresence() {
    presenceUntil = Date.now() + (PRESENCE_GRACE * 1000);
  }
  function hasInteractiveFocus() {
    var el = document.activeElement;
    if (!el) return false;
    if (el.isContentEditable) return true;
    if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT' || el.tagName === 'BUTTON') {
      return !el.disabled;
    }
    return false;
  }
  function shouldSendPresenceKeepalive(now) {
    return !document.hidden &&
      typeof document.hasFocus === 'function' &&
      document.hasFocus() &&
      now < presenceUntil &&
      (hasInteractiveFocus() || modalShown);
  }

  function showModal(s) {
    secondsLeft = s;
    countEl.textContent = fmt(secondsLeft);
    overlay.classList.add('show');
    modalShown = true;
    clearInterval(countdownInt);
    countdownInt = setInterval(function() {
      secondsLeft--;
      countEl.textContent = fmt(secondsLeft);
      if (secondsLeft <= 0) { clearInterval(countdownInt); confirmTimeout(); }
    }, 1000);
  }

  function hideModal() {
    overlay.classList.remove('show');
    modalShown = false;
    clearInterval(countdownInt);
  }

  function syncFromServer(seconds) {
    secondsLeft = seconds || IDLE_TIMEOUT;
    lastLocalActivity = Date.now() - Math.max(0, IDLE_TIMEOUT - secondsLeft) * 1000;
    markPresence();
    if (secondsLeft > WARNING_AT && modalShown) hideModal();
  }

  function pingServer(force) {
    var now = Date.now();
    // Only ping if: forced, or real activity happened AND enough time has passed
    if (pingInFlight) return;
    if (!force && (!activitySinceLastPing || now - lastActivityPing < PING_EVERY * 1000)) return;
    lastActivityPing = now;
    activitySinceLastPing = false;
    pingInFlight = true;
    fetch(PING_URL + '?action=ping', { method: 'POST', credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(d) {
        if (!d.ok) { confirmTimeout(); return; }
        syncFromServer(d.secondsLeft || IDLE_TIMEOUT);
        pingInFlight = false;
      })
      .catch(function() {
        activitySinceLastPing = true;
        pingInFlight = false;
      });
  }

  function confirmTimeout() {
    if (logoutCheckInFlight) return;
    logoutCheckInFlight = true;
    fetch(PING_URL + '?action=check', { method: 'GET', credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(d) {
        logoutCheckInFlight = false;
        if (d.ok && (d.secondsLeft || 0) > 0) {
          syncFromServer(d.secondsLeft);
          return;
        }
        doLogout();
      })
      .catch(function() {
        logoutCheckInFlight = false;
        if (activitySinceLastPing) {
          pingServer(true);
          return;
        }
        doLogout();
      });
  }

  function doLogout() {
    clearInterval(checkInt);
    clearInterval(countdownInt);
    fetch(PING_URL + '?action=logout', { method: 'POST', credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(d) { window.location.href = d.redirect || PING_URL; })
      .catch(function()  { window.location.reload(); });
  }

  window.sessionStay = function() {
    lastLocalActivity = Date.now();
    activitySinceLastPing = true;
    fetch(PING_URL + '?action=ping', { method: 'POST', credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(d) {
        if (!d.ok) { window.location.reload(); return; }
        syncFromServer(d.secondsLeft || IDLE_TIMEOUT);
        hideModal();
      })
      .catch(function() { hideModal(); });
  };

  window.sessionLogout = function() { doLogout(); };

  // Debounce for high-frequency events like mousemove/scroll
  var moveDebounce = null;
  function noteMovement() {
    if (moveDebounce) return;
    moveDebounce = setTimeout(function() {
      moveDebounce = null;
      lastLocalActivity = Date.now();
      markPresence();
      activitySinceLastPing = true;
      pingServer(false);
      if (modalShown) { hideModal(); pingServer(true); }
    }, 500);
  }

  // Immediate activity for meaningful interactions
  function noteActivity() {
    var wasModalShown = modalShown;
    lastLocalActivity = Date.now();
    markPresence();
    activitySinceLastPing = true;
    pingServer(false);
    if (modalShown) hideModal();
    if (wasModalShown) pingServer(true);
  }

  // Meaningful interactions — immediate response
  ['click','keydown','input','change','touchstart','pointerdown','focusin','submit','paste','compositionend','dragenter','drop'].forEach(function(evt) {
    document.addEventListener(evt, noteActivity, { passive: true });
  });

  // High-frequency events — debounced
  ['mousemove','touchmove','pointermove','wheel','scroll'].forEach(function(evt) {
    document.addEventListener(evt, noteMovement, { passive: true });
  });

  // Visibility change: do NOT reset timer on tab focus (user might have been away)
  // Only use it to check server state when they come back
  document.addEventListener('visibilitychange', function() {
    if (!document.hidden) {
      markPresence();
      // Tab became visible — check server for current session state without resetting local timer
      fetch(PING_URL + '?action=check', { method: 'GET', credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(d) {
          if (!d.ok) { confirmTimeout(); return; }
          var srvLeft = d.secondsLeft || 0;
          if (srvLeft <= 0) { confirmTimeout(); return; }
          // Sync local timer with server value (server is authoritative)
          syncFromServer(srvLeft);
          if (!modalShown && srvLeft <= WARNING_AT) showModal(srvLeft);
        })
        .catch(function() {});
    }
  });

  checkInt = setInterval(function() {
    var localLeft = localSecondsLeft();
    var now = Date.now();
    secondsLeft = localLeft;

    if (localLeft <= 0) { confirmTimeout(); return; }
    if (localLeft <= WARNING_AT && !modalShown) showModal(localLeft);
    if (localLeft > WARNING_AT && modalShown) hideModal();
    if (modalShown) countEl.textContent = fmt(localLeft);

    // Only ping if there was real activity
    if (activitySinceLastPing) pingServer(false);
    if (!activitySinceLastPing && now - lastKeepalivePing >= KEEPALIVE_EVERY * 1000 && shouldSendPresenceKeepalive(now)) {
      lastKeepalivePing = now;
      pingServer(true);
    }
  }, CHECK_EVERY * 1000);

  pingServer(true);
  if (secondsLeft <= WARNING_AT) showModal(secondsLeft);
})();
</script>
</body></html>
<?php }
