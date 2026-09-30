<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
logoutUser();
header('Location: ' . APP_URL . '/index.php');
exit;
