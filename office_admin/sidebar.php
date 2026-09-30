<?php $cur = basename($_SERVER['PHP_SELF']); ?>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-section">Office Admin</div>
  <a href="subadmins.php" class="nav-link <?= $cur==='subadmins.php' ? 'active' : '' ?>">
    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
    Staff &amp; Data
  </a>
  <a href="dashboard.php" class="nav-link <?= $cur==='dashboard.php' ? 'active' : '' ?>">
    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
    Dashboard
  </a>
  <a href="reports.php" class="nav-link <?= $cur==='reports.php' ? 'active' : '' ?>">
    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
    Reports
  </a>
  <div class="divider"></div>
  <a href="<?= APP_URL ?>/logout.php" class="nav-link" style="color:var(--c-danger)">
    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
    Logout
  </a>
</aside>
