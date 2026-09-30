<?php
/**
 * save_snip.php
 * Accepts a base64 PNG from the 3D Visualizer, saves it to disk and DB.
 *
 * POST params:
 *   submission_id  (int)    — the submission this snapshot belongs to
 *   owner_name     (string) — customer display name
 *   image_data     (string) — full data-URI, e.g. "data:image/png;base64,..."
 *
 * Returns JSON: { ok: true, filename, url, id } on success
 *               { ok: false, error: "..." }     on failure
 */

/* ── Allow the visualizer page (same origin or same-site) to POST here.
   If your visualizer runs on a different sub-domain, adjust the origin. ── */
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
    header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

/* Handle pre-flight */
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json');

/* ── Auth check ── */
$role = $_SESSION['role'] ?? '';
if (!isLoggedIn() || !in_array($role, ['office_admin', 'main_admin'], true)) {
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}
if (!checkAdminIdleTimeout()) {
    echo json_encode(['ok' => false, 'error' => 'Session timed out']);
    exit;
}
$user = currentUser();
if (!$user) {
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

/* ── Validate POST data ── */
$submissionId = (int)($_POST['submission_id'] ?? 0);
$ownerName    = trim($_POST['owner_name']    ?? '');
$imageData    = $_POST['image_data']         ?? '';

if (!$submissionId) {
    echo json_encode(['ok' => false, 'error' => 'Missing or zero submission_id']);
    exit;
}
if (empty($imageData)) {
    echo json_encode(['ok' => false, 'error' => 'Missing image_data']);
    exit;
}

/* ── Decode base64 image ── */
// Strip any data-URI prefix: "data:image/png;base64," etc.
$stripped = preg_replace('/^data:image\/\w+;base64,/', '', $imageData);
$decoded  = base64_decode($stripped, true);

if ($decoded === false || strlen($decoded) < 100) {
    echo json_encode(['ok' => false, 'error' => 'Base64 decode failed or image too small']);
    exit;
}

/* ── DB connection ── */
try {
    $db = getDB();
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => 'DB connection failed: ' . $e->getMessage()]);
    exit;
}

/* ── Ensure table exists ── */
try {
    $db->exec("CREATE TABLE IF NOT EXISTS visualizer_snapshots (
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
        submission_id INT UNSIGNED NOT NULL,
        filename      VARCHAR(255) NOT NULL,
        owner_name    VARCHAR(255) NOT NULL DEFAULT '',
        created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_vs_sub (submission_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {
    error_log('save_snip.php: table create warning: ' . $e->getMessage());
    /* Non-fatal — table may already exist */
}

/* ── Verify submission exists ── */
$chk = $db->prepare("SELECT id FROM submissions WHERE id = ? LIMIT 1");
$chk->execute([$submissionId]);
if (!$chk->fetchColumn()) {
    echo json_encode(['ok' => false, 'error' => 'Submission not found: ' . $submissionId]);
    exit;
}

/* ── Ensure upload directory exists and is writable ── */
if (!defined('UPLOAD_DIR')) {
    echo json_encode(['ok' => false, 'error' => 'UPLOAD_DIR constant not defined']);
    exit;
}

if (!is_dir(UPLOAD_DIR)) {
    if (!@mkdir(UPLOAD_DIR, 0775, true)) {
        echo json_encode(['ok' => false, 'error' => 'Cannot create upload directory: ' . UPLOAD_DIR]);
        exit;
    }
}

if (!is_writable(UPLOAD_DIR)) {
    echo json_encode(['ok' => false, 'error' => 'Upload directory not writable: ' . UPLOAD_DIR]);
    exit;
}

/* ── Write file to disk ── */
$filename = 'viz_snip_' . $submissionId . '_' . uniqid() . '.png';
$filepath = UPLOAD_DIR . $filename;

$written = file_put_contents($filepath, $decoded);
if ($written === false) {
    echo json_encode(['ok' => false, 'error' => 'file_put_contents failed for: ' . $filepath]);
    exit;
}

/* ── Insert record into DB ── */
try {
    $ins = $db->prepare(
        "INSERT INTO visualizer_snapshots (submission_id, filename, owner_name)
         VALUES (?, ?, ?)"
    );
    $ins->execute([$submissionId, $filename, $ownerName]);
    $newId = (int)$db->lastInsertId();
} catch (Exception $e) {
    /* File is already written; try to clean up */
    @unlink($filepath);
    echo json_encode(['ok' => false, 'error' => 'DB insert failed: ' . $e->getMessage()]);
    exit;
}

/* ── Build public URL ── */
if (!defined('UPLOAD_URL')) {
    echo json_encode(['ok' => false, 'error' => 'UPLOAD_URL constant not defined']);
    exit;
}
$publicUrl = rtrim(UPLOAD_URL, '/') . '/' . $filename;

/* ── Success ── */
echo json_encode([
    'ok'       => true,
    'filename' => $filename,
    'url'      => $publicUrl,
    'id'       => $newId,
]);
