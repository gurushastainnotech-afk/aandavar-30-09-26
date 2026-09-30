<?php
/**
 * save_gps.php — validate and persist a GPS capture for the logged-in subadmin.
 */

header('Permissions-Policy: geolocation=(self)');
header('Feature-Policy: geolocation \'self\'');
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST, OPTIONS');

// Prevent caching
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

// Must be logged in as subadmin
try {
    requireLogin('sub_admin');
    $user = currentUser();
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'msg' => 'Unauthorized']);
    exit;
}

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    echo json_encode(['success' => false, 'msg' => 'POST only']);
    exit;
}

try {
    verifyCsrf();
} catch (Throwable $e) {
    http_response_code(403);
    echo json_encode(['success' => false, 'msg' => 'Security token expired. Refresh the page and try again.']);
    exit;
}

function gpsNumberFromPost(string $key): ?float {
    if (!isset($_POST[$key])) return null;
    $raw = trim((string)$_POST[$key]);
    if ($raw === '' || !is_numeric($raw)) return null;
    return (float)$raw;
}

$lat = gpsNumberFromPost('lat');
$lng = gpsNumberFromPost('lng');
$accuracy = gpsNumberFromPost('accuracy');
$source = strtolower(trim((string)($_POST['source'] ?? 'auto')));
$source = in_array($source, ['auto', 'manual'], true) ? $source : 'auto';

// Validate coordinates
if ($lat === null || $lng === null || ($lat === 0.0 && $lng === 0.0)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'msg' => 'Invalid coordinates']);
    exit;
}

// Sanity check: latitude ±90, longitude ±180
if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    http_response_code(400);
    echo json_encode(['success' => false, 'msg' => 'Coordinates out of range']);
    exit;
}

// Format to 7 decimal places (precision ~1 cm)
$lat = round($lat, 7);
$lng = round($lng, 7);
$accuracy = $accuracy !== null && $accuracy >= 0 ? round($accuracy, 2) : null;

try {
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS subadmin_gps_captures (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        sub_admin_id INT UNSIGNED NOT NULL,
        latitude DECIMAL(10,7) NOT NULL,
        longitude DECIMAL(10,7) NOT NULL,
        submission_id INT UNSIGNED NULL,
        accuracy_m DECIMAL(10,2) NULL,
        source VARCHAR(20) NOT NULL DEFAULT 'auto',
        user_agent VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        used_at DATETIME NULL,
        KEY idx_gps_user_created (sub_admin_id, created_at),
        KEY idx_gps_submission (submission_id),
        CONSTRAINT fk_gps_capture_user
            FOREIGN KEY (sub_admin_id) REFERENCES users(id)
            ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $stmt = $db->prepare("INSERT INTO subadmin_gps_captures
        (sub_admin_id, latitude, longitude, accuracy_m, source, user_agent)
        VALUES (?,?,?,?,?,?)");
    $stmt->execute([$user['id'], $lat, $lng, $accuracy, $source, $ua]);
    $captureId = (int)$db->lastInsertId();
} catch (Throwable $e) {
    error_log('[GPS] Save failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'msg' => 'Could not save GPS coordinates. Try again.']);
    exit;
}

http_response_code(200);
echo json_encode([
    'success' => true,
    'capture_id' => $captureId,
    'lat' => $lat,
    'lng' => $lng,
    'accuracy' => $accuracy,
    'source' => $source,
    'msg' => 'GPS coordinates saved'
]);
exit;
