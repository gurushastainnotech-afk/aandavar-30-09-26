<?php
$cur = basename($_SERVER['PHP_SELF']);
?>
<div class="sidebar" id="sidebar">
  <div class="sidebar-section">Main</div>
  <a href="dashboard.php" class="nav-link <?= $cur==='dashboard.php'?'active':'' ?>">
    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
    Dashboard
  </a>
  <div class="sidebar-section">Management</div>
  <a href="subadmins.php" class="nav-link <?= $cur==='subadmins.php'?'active':'' ?>">
    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
    Executives
  </a>
  <a href="active_sessions.php" class="nav-link <?= $cur==='active_sessions.php'?'active':'' ?>">
    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
    Active Sessions
  </a>
  <a href="locations.php" class="nav-link <?= $cur==='locations.php'?'active':'' ?>">
    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
    Locations
  </a>
  <div class="sidebar-section">Data</div>
  <a href="submissions.php" class="nav-link <?= $cur==='submissions.php'?'active':'' ?>">
    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
    All Submissions
  </a>
  <a href="reports.php" class="nav-link <?= $cur==='reports.php'?'active':'' ?>">
    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
    Marketing Exec Reports
  </a>
  <a href="office_reports.php" class="nav-link <?= $cur==='office_reports.php'?'active':'' ?>">
    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
    Sales Executive Reports
  </a>
  <div class="sidebar-section" style="color:var(--c-danger);margin-top:8px">Danger Zone</div>
  <a href="terminate.php" class="nav-link <?= $cur==='terminate.php'?'active':'' ?>" style="color:var(--c-danger)">
    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
    Terminate Session
  </a>
</div>
