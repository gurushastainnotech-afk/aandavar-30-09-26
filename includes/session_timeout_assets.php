<?php
$__sessionTimeoutRole = $sessionTimeoutRole ?? ($_SESSION['role'] ?? '');
$__sessionTimeoutIdle = adminIdleSecondsLeft();

if ($__sessionTimeoutRole === '' || $__sessionTimeoutIdle <= 0) {
    return;
}

$__sessionTimeoutLimit = _sessionTimeoutForRole($__sessionTimeoutRole);
?>
<script>
window.__adminIdleSecondsLeft = <?= (int)$__sessionTimeoutIdle ?>;
window.__adminIdleTimeout = <?= (int)$__sessionTimeoutLimit ?>;
window.__appUrl = '<?= addslashes(APP_URL) ?>';
</script>
<style>
#session-timeout-overlay{
  position:fixed;inset:0;background:rgba(0,0,0,.55);
  display:none;align-items:center;justify-content:center;
  z-index:9999;padding:16px;backdrop-filter:blur(3px);
}
#session-timeout-overlay.show{display:flex}
#session-timeout-box{
  background:#fff;border-radius:14px;padding:32px 28px;
  width:100%;max-width:360px;text-align:center;
  box-shadow:0 8px 24px rgba(0,0,0,.1),0 4px 8px rgba(0,0,0,.06);
}
.sto-icon{font-size:2.5rem;margin-bottom:12px}
#session-timeout-box h3{font-size:1.1rem;font-weight:700;color:#111827;margin-bottom:8px}
#session-timeout-box p{font-size:.875rem;color:#6b7280;margin-bottom:16px}
.sto-count{font-size:2.8rem;font-weight:800;color:#dc2626;letter-spacing:-.04em;margin-bottom:20px;font-variant-numeric:tabular-nums}
.sto-btns{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}
.sto-stay{background:#2563eb;color:#fff;border:none;padding:10px 20px;border-radius:6px;font-size:.875rem;font-weight:600;cursor:pointer;transition:background .15s}
.sto-stay:hover{background:#1d4ed8}
.sto-out{background:#f3f4f6;color:#374151;border:1px solid #e5e7eb;padding:10px 20px;border-radius:6px;font-size:.875rem;font-weight:600;cursor:pointer;transition:background .15s}
.sto-out:hover{background:#e5e7eb}
</style>
<div id="session-timeout-overlay" role="dialog" aria-modal="true" aria-labelledby="sto-title">
  <div id="session-timeout-box">
    <div class="sto-icon">⏱️</div>
    <h3 id="sto-title">Session Expiring Soon</h3>
    <p>You have been inactive. Choose an option or your session will automatically end in:</p>
    <div class="sto-count" id="sto-countdown">120</div>
    <div class="sto-btns">
      <button class="sto-stay" onclick="sessionStay()">Stay Logged In</button>
      <button class="sto-out" onclick="sessionLogout()">Logout Now</button>
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
  var PING_EVERY   = 10;
  var KEEPALIVE_EVERY = 60;
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
  var activitySinceLastPing = false;
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
  function noteActivity() {
    var wasModalShown = modalShown;
    lastLocalActivity = Date.now();
    markPresence();
    activitySinceLastPing = true;
    pingServer(false);
    if (modalShown) hideModal();
    if (wasModalShown) pingServer(true);
  }

  ['click','keydown','input','change','touchstart','pointerdown','focusin','submit','paste','compositionend','dragenter','drop'].forEach(function(evt) {
    document.addEventListener(evt, noteActivity, { passive: true });
  });
  ['mousemove','touchmove','pointermove','wheel','scroll'].forEach(function(evt) {
    document.addEventListener(evt, noteMovement, { passive: true });
  });
  document.addEventListener('visibilitychange', function() {
    if (!document.hidden) {
      markPresence();
      fetch(PING_URL + '?action=check', { method: 'GET', credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(d) {
          if (!d.ok) { confirmTimeout(); return; }
          var srvLeft = d.secondsLeft || 0;
          if (srvLeft <= 0) { confirmTimeout(); return; }
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
