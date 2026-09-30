<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
requireLogin('office_admin');

$db   = getDB();
$user = currentUser();

header('Content-Type: application/json');

$phone  = trim($_POST['phone'] ?? '');
$name   = trim($_POST['name']  ?? '');

if (!$phone) {
    echo json_encode(['found' => false, 'error' => 'Phone required']);
    exit;
}

$stmt = $db->prepare(
    "SELECT s.*, u.username AS sub_name, sd.code AS zone, d.code AS dir_code
     FROM submissions s
     JOIN users u           ON s.submitted_by = u.id
     JOIN sub_directions sd ON s.sub_direction_id = sd.id
     JOIN directions d      ON sd.direction_id = d.id
     WHERE s.owner_number = ?
     LIMIT 1"
);
$stmt->execute([$phone]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row) {
    // Also fetch status details
    $dq = $db->prepare("SELECT * FROM submission_status_details WHERE submission_id = ?");
    $dq->execute([$row['id']]);
    $detail = $dq->fetch(PDO::FETCH_ASSOC) ?: null;

    // Fetch areas
    $aq = $db->prepare("SELECT * FROM submission_areas WHERE submission_id = ? ORDER BY sort_order");
    $aq->execute([$row['id']]);
    $areas = $aq->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'found'   => true,
        'record'  => $row,
        'detail'  => $detail,
        'areas'   => $areas,
    ]);
} else {
    echo json_encode([
        'found'      => false,
        'prefill'    => [
            'owner_name'   => $name,
            'owner_number' => $phone,
        ],
    ]);
}
exit;