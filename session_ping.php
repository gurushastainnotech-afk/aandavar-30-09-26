<?php
// ============================================================
// session_ping.php — AJAX endpoint for admin idle timeout
//
// GET  ?action=check  → returns seconds left (JSON)
// POST ?action=ping   → refreshes last_active, returns seconds left
// POST ?action=logout → logs out and returns redirect URL
// ============================================================
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$action = $_REQUEST['action'] ?? 'check';

// Must be logged in as an application user. The shared layout loads this
// endpoint for main_admin, office_admin, and sub_admin pages.
$role = $_SESSION['role'] ?? '';
if (!isLoggedIn() || !in_array($role, ['main_admin', 'office_admin', 'sub_admin'], true)) {
    echo json_encode(['ok' => false, 'redirect' => APP_URL . '/index.php?reason=timeout']);
    exit;
}

$timeout = _sessionTimeoutForRole($role);

if ($action === 'logout') {
    logoutUser();
    echo json_encode(['ok' => true, 'redirect' => APP_URL . '/index.php?reason=timeout']);
    exit;
}

if ($action === 'ping') {
    // Refresh both the PHP session clock and the canonical DB session clock.
    refreshCurrentSessionActivity(getDB());
    echo json_encode(['ok' => true, 'secondsLeft' => $timeout]);
    exit;
}

// Default: check only (don't refresh timer)
$secondsLeft = adminIdleSecondsLeft();
echo json_encode(['ok' => true, 'secondsLeft' => $secondsLeft]);
