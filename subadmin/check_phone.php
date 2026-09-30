<?php
/**
 * check_phone.php
 * AJAX endpoint — checks if an owner phone number already exists globally.
 * Returns JSON: {"exists": true/false}
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin('sub_admin');

header('Content-Type: application/json');

$phone = trim($_GET['phone'] ?? '');
if (!$phone) {
    echo json_encode(['exists' => false]);
    exit;
}

$db   = getDB();
$stmt = $db->prepare("SELECT COUNT(*) FROM submissions WHERE owner_number = ?");
$stmt->execute([$phone]);
$count = (int)$stmt->fetchColumn();

echo json_encode(['exists' => $count > 0]);
