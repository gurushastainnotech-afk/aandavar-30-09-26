<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';

$vizAction = $_REQUEST['action'] ?? '';
$vizNeedsSession = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['image']))
    || in_array($vizAction, ['save_snip', 'delete_snip', 'list_snips'], true);

if ($vizNeedsSession) {
    $vizRole = $_SESSION['role'] ?? '';
    if (!isLoggedIn() || !in_array($vizRole, ['office_admin', 'main_admin'], true)) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
        exit;
    }
    if (!checkAdminIdleTimeout()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(440);
        echo json_encode(['ok' => false, 'error' => 'Session timed out']);
        exit;
    }
} else {
    requireLogin('office_admin');
}

/* ═══════════════════════════════════════════════════════════════
   3D TILE VISUALIZER — Aandavar Enterprises
   Updated: Light/Dark mode, disabled span, improved spacing & text sizes
═══════════════════════════════════════════════════════════════ */

/* ── Handle image save (from snipping.php) ──────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['image'])) {
    $image = $_POST['image'];
    $image = str_replace('data:image/png;base64,', '', $image);
    $image = base64_decode($image);
    if (!file_exists("uploads")) mkdir("uploads", 0777, true);
    $fileName = 'uploads/snip_' . time() . '.png';
    file_put_contents($fileName, $image);
    echo $fileName;
    exit;
}

/* ── Configuration ─────────────────────────────────────────── */
define('MAX_UPLOAD_BYTES',   8 * 1024 * 1024);
define('MAX_NAME_CHARS',     120);
define('SNIP_LIST_LIMIT',    300);
define('TOKEN_TTL_SECONDS',  86400 * 7);
define('ALLOWED_MIME_TYPES', [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP]);

/* ── Paths ──────────────────────────────────────────────────── */
$dataDir = __DIR__ . '/../data';
$snipDir = $dataDir . '/viz_snips';
$dbFile  = $dataDir . '/viz_gallery.db';

$hmacSecret = defined('VIZ_HMAC_SECRET')
    ? VIZ_HMAC_SECRET
    : (getenv('VIZ_HMAC_SECRET') ?: 'change-me-in-production-' . php_uname('n'));

if (!is_dir($dataDir)) mkdir($dataDir, 0750, true);
if (!is_dir($snipDir)) mkdir($snipDir, 0750, true);

/* ── Token helpers ──────────────────────────────────────────── */
function makeToken(int $subId, string $secret): string {
    $payload = $subId . ':' . (time() + TOKEN_TTL_SECONDS);
    $sig     = hash_hmac('sha256', $payload, $secret);
    return base64_encode($payload . ':' . $sig);
}

function verifyToken(string $token, string $secret): int|false {
    $raw = base64_decode($token, true);
    if ($raw === false) return false;
    $parts = explode(':', $raw, 3);
    if (count($parts) !== 3) return false;
    [$subId, $expires, $sig] = $parts;
    if (!ctype_digit($subId) || !ctype_digit($expires)) return false;
    if ((int)$expires < time()) return false;
    $expected = hash_hmac('sha256', $subId . ':' . $expires, $secret);
    if (!hash_equals($expected, $sig)) return false;
    return (int)$subId;
}

/* ── Database ───────────────────────────────────────────────── */
function getVizDB(string $dbFile): PDO {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("PRAGMA journal_mode=WAL");
    $pdo->exec("CREATE TABLE IF NOT EXISTS viz_snips (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        submission_id INTEGER NOT NULL DEFAULT 0,
        customer_name TEXT    NOT NULL DEFAULT '',
        filename      TEXT    NOT NULL,
        width         INTEGER NOT NULL DEFAULT 0,
        height        INTEGER NOT NULL DEFAULT 0,
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    return $pdo;
}

/* ── File-serving controller ────────────────────────────────── */
if (($_GET['action'] ?? '') === 'serve_snip') {
    $token    = $_GET['token']    ?? '';
    $filename = $_GET['filename'] ?? '';
    $subId = verifyToken($token, $hmacSecret);
    if ($subId === false) { http_response_code(403); exit('Forbidden'); }
    if (!preg_match('/^snip_\d+_[0-9a-f]+\.(png|jpg|webp)$/i', $filename)) {
        http_response_code(400); exit('Bad filename');
    }
    try {
        $pdo  = getVizDB($dbFile);
        $stmt = $pdo->prepare("SELECT filename FROM viz_snips WHERE filename=? AND submission_id=? LIMIT 1");
        $stmt->execute([$filename, $subId]);
        $row  = $stmt->fetch();
    } catch (Exception $e) { http_response_code(500); exit('DB error'); }
    if (!$row) { http_response_code(404); exit('Not found'); }
    $fp = $snipDir . '/' . $filename;
    if (!file_exists($fp)) { http_response_code(404); exit('File missing'); }
    $ext  = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $mime = match($ext) { 'png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp', default => 'application/octet-stream' };
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($fp));
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    readfile($fp);
    exit;
}

/* ── JSON helper ────────────────────────────────────────────── */
function jsonOut(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function snipServeUrl(string $filename, int $subId, string $secret): string {
    $token = makeToken($subId, $secret);
    return $_SERVER['PHP_SELF'] . '?action=serve_snip&filename=' . rawurlencode($filename) . '&token=' . rawurlencode($token);
}

/* ── save_snip ──────────────────────────────────────────────── */
if (($_POST['action'] ?? '') === 'save_snip') {
    $token = $_POST['access_token'] ?? '';
    $subId = verifyToken($token, $hmacSecret);
    if ($subId === false) jsonOut(['ok' => false, 'error' => 'Invalid or expired access token'], 403);
    try {
        $imgData = $_POST['image_data'] ?? '';
        if (empty($imgData)) jsonOut(['ok' => false, 'error' => 'No image data'], 400);
        if (!preg_match('/^data:image\/(png|jpeg|jpg|webp);base64,/i', $imgData))
            jsonOut(['ok' => false, 'error' => 'Invalid data URI'], 400);
        $b64 = substr($imgData, strpos($imgData, ',') + 1);
        if (strlen($b64) > ceil(MAX_UPLOAD_BYTES * 4 / 3) + 4)
            jsonOut(['ok' => false, 'error' => 'Image exceeds 8 MB limit'], 413);
        $decoded = base64_decode($b64, true);
        if ($decoded === false || strlen($decoded) < 8)
            jsonOut(['ok' => false, 'error' => 'Base64 decode failed'], 400);
        $info = @getimagesizefromstring($decoded);
        if (!$info) jsonOut(['ok' => false, 'error' => 'Not a valid image'], 400);
        if (!in_array($info[2], ALLOWED_MIME_TYPES, true))
            jsonOut(['ok' => false, 'error' => 'Only PNG, JPEG, WebP allowed'], 415);
        $ext = match($info[2]) { IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp' };
        $custName = mb_substr(trim($_POST['customer_name'] ?? 'Customer'), 0, MAX_NAME_CHARS);
        if ($custName === '') $custName = 'Customer';
        $filename = 'snip_' . $subId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $filepath = $snipDir . '/' . $filename;
        if (file_put_contents($filepath, $decoded) === false)
            jsonOut(['ok' => false, 'error' => 'File write failed'], 500);
        chmod($filepath, 0640);
        $pdo  = getVizDB($dbFile);
        $stmt = $pdo->prepare("INSERT INTO viz_snips (submission_id, customer_name, filename, width, height) VALUES (?,?,?,?,?)");
        $stmt->execute([$subId, $custName, $filename, $info[0], $info[1]]);
        $newId = (int)$pdo->lastInsertId();
        jsonOut(['ok' => true, 'id' => $newId, 'url' => snipServeUrl($filename, $subId, $hmacSecret), 'filename' => $filename, 'width' => $info[0], 'height' => $info[1]]);
    } catch (Exception $e) { jsonOut(['ok' => false, 'error' => $e->getMessage()], 500); }
}

/* ── delete_snip ────────────────────────────────────────────── */
if (($_POST['action'] ?? '') === 'delete_snip') {
    $token = $_POST['access_token'] ?? '';
    $subId = verifyToken($token, $hmacSecret);
    if ($subId === false) jsonOut(['ok' => false, 'error' => 'Invalid or expired access token'], 403);
    try {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) jsonOut(['ok' => false, 'error' => 'Invalid id'], 400);
        $pdo  = getVizDB($dbFile);
        $stmt = $pdo->prepare("SELECT filename FROM viz_snips WHERE id=? AND submission_id=? LIMIT 1");
        $stmt->execute([$id, $subId]);
        $row  = $stmt->fetch();
        if (!$row) jsonOut(['ok' => false, 'error' => 'Not found or access denied'], 404);
        $fp = $snipDir . '/' . $row['filename'];
        if (file_exists($fp)) unlink($fp);
        $del = $pdo->prepare("DELETE FROM viz_snips WHERE id=? AND submission_id=?");
        $del->execute([$id, $subId]);
        jsonOut(['ok' => true]);
    } catch (Exception $e) { jsonOut(['ok' => false, 'error' => $e->getMessage()], 500); }
}

/* ── list_snips ─────────────────────────────────────────────── */
if (in_array(($_GET['action'] ?? $_POST['action'] ?? ''), ['list_snips'], true)) {
    $token = $_GET['access_token'] ?? $_POST['access_token'] ?? '';
    $subId = verifyToken($token, $hmacSecret);
    if ($subId === false) jsonOut(['ok' => false, 'error' => 'Invalid or expired access token', 'snips' => []], 403);
    try {
        $pdo  = getVizDB($dbFile);
        $stmt = $pdo->prepare("SELECT * FROM viz_snips WHERE submission_id=? ORDER BY created_at DESC LIMIT ?");
        $stmt->execute([$subId, SNIP_LIST_LIMIT]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) { $r['url'] = snipServeUrl($r['filename'], $subId, $hmacSecret); }
        jsonOut(['ok' => true, 'snips' => $rows]);
    } catch (Exception $e) { jsonOut(['ok' => false, 'error' => $e->getMessage(), 'snips' => []], 500); }
}

/* ── Page render ────────────────────────────────────────────── */
$submissionId = (int)($_GET['submission_id'] ?? 0);
try { getVizDB($dbFile); } catch (Exception $e) { }
$accessToken = makeToken($submissionId, $hmacSecret);
$jsSubId = json_encode($submissionId);
$jsSelf  = json_encode($_SERVER['PHP_SELF']);
$jsToken = json_encode($accessToken);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>3D Tile Visualizer — Aandavar Enterprises</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
/* ══════════════════════════════════════════
   CSS VARIABLES FOR THEMING
══════════════════════════════════════════ */
:root {
  --bg-primary: #0d1117;
  --bg-secondary: #111827;
  --bg-tertiary: #1e293b;
  --bg-accent: #0a0e15;
  --bg-gradient-start: #0e1a2e;
  --bg-gradient-end: #0d1117;
  
  --text-primary: #e2e8f0;
  --text-secondary: #94a3b8;
  --text-tertiary: #64748b;
  --text-muted: #475569;
  --text-accent: #f5e6c8;
  
  --border-color: rgba(255,255,255,.1);
  --border-light: rgba(255,255,255,.07);
  --border-lighter: rgba(255,255,255,.05);
  
  --accent-orange: #f5a623;
  --accent-orange-dim: rgba(245,166,35,.5);
  --accent-orange-bg: rgba(245,166,35,.12);
  --accent-orange-border: rgba(245,166,35,.35);
  
  --accent-cyan: #0e7490;
  --accent-cyan-light: #67e8f9;
  --accent-purple: #7c3aed;
  --accent-purple-light: #c4b5fd;
  
  --cell-bg: #1a2235;
  --grout-bg: #2a3347;
  --grid-line: rgba(245,166,35,.025);
  
  --success-bg: #065f46;
  --success-text: #6ee7b7;
  --error-bg: #450a0a;
  --error-text: #fca5a5;
  --error-border: #991b1b;
}

[data-theme="light"] {
  --bg-primary: #f8fafc;
  --bg-secondary: #ffffff;
  --bg-tertiary: #f1f5f9;
  --bg-accent: #e2e8f0;
  --bg-gradient-start: #e0e7f0;
  --bg-gradient-end: #f8fafc;
  
  --text-primary: #0f172a;
  --text-secondary: #475569;
  --text-tertiary: #64748b;
  --text-muted: #94a3b8;
  --text-accent: #92400e;
  
  --border-color: rgba(0,0,0,.15);
  --border-light: rgba(0,0,0,.1);
  --border-lighter: rgba(0,0,0,.05);
  
  --accent-orange: #d97706;
  --accent-orange-dim: rgba(217,119,6,.6);
  --accent-orange-bg: rgba(217,119,6,.1);
  --accent-orange-border: rgba(217,119,6,.3);
  
  --accent-cyan: #0891b2;
  --accent-cyan-light: #06b6d4;
  --accent-purple: #7c3aed;
  --accent-purple-light: #8b5cf6;
  
  --cell-bg: #e0e7ff;
  --grout-bg: #c7d2fe;
  --grid-line: rgba(217,119,6,.08);
  
  --success-bg: #d1fae5;
  --success-text: #065f46;
  --error-bg: #fee2e2;
  --error-text: #991b1b;
  --error-border: #dc2626;
}

/* ── Reset & Base ── */
*{box-sizing:border-box;margin:0;padding:0;}
html,body{height:100%;font-family:'Syne',Arial,sans-serif;background:var(--bg-primary);color:var(--text-primary);overflow:hidden;transition:background .3s, color .3s;}
body{display:flex;flex-direction:column;height:100%;}

/* ── Main viewer fills everything above bottom bar ── */
#viewer{
  flex:1 1 auto;position:relative;
  background:radial-gradient(ellipse at 30% 40%,var(--bg-gradient-start) 0%,var(--bg-gradient-end) 70%);
  display:flex;justify-content:center;align-items:center;overflow:hidden;
}
#viewer::before{
  content:'';position:absolute;inset:0;
  background-image:linear-gradient(var(--grid-line) 1px,transparent 1px),
                   linear-gradient(90deg,var(--grid-line) 1px,transparent 1px);
  background-size:48px 48px;pointer-events:none;z-index:0;
}

/* ── Standard icon toolbar ── */
#toolDock{
  position:absolute;top:12px;left:50%;transform:translateX(-50%);
  z-index:140;display:flex;align-items:center;justify-content:center;gap:8px;
  flex-wrap:wrap;
  width:min(1060px,calc(100vw - 18px));
  max-height:96px;overflow:visible;
  pointer-events:none;
}
.td-group{
  display:flex;align-items:center;gap:4px;
  background:rgba(13,17,23,.88);
  border:1px solid var(--border-color);
  border-radius:12px;
  padding:5px;
  box-shadow:0 8px 30px rgba(0,0,0,.42);
  backdrop-filter:blur(12px);
  pointer-events:auto;
}
[data-theme="light"] .td-group{background:rgba(255,255,255,.94);}
.td-sep{width:1px;height:28px;background:var(--border-light);margin:0 3px;}
.td-btn,.tool-btn,.surface-btn{
  height:36px;min-width:36px;padding:0 10px;
  display:inline-flex;align-items:center;justify-content:center;gap:6px;
  border:1px solid transparent;border-radius:8px;
  background:transparent;color:var(--text-secondary);
  font-family:'DM Mono',monospace;font-size:12px;font-weight:800;
  line-height:1;text-decoration:none;cursor:pointer;
  transition:background .16s,color .16s,border-color .16s,transform .16s;
  white-space:nowrap;
}
.td-btn:hover,.tool-btn:hover,.surface-btn:hover{
  background:var(--bg-tertiary);color:var(--text-primary);
  border-color:var(--border-light);transform:translateY(-1px);
}
.td-btn:disabled{opacity:.35;cursor:not-allowed;transform:none;}
.td-btn.menu{background:var(--accent-orange-bg);color:var(--accent-orange);border-color:var(--accent-orange-border);}
.td-btn.menu.active{background:var(--accent-orange);color:#fff;border-color:var(--accent-orange);}
.td-btn.capture{background:var(--accent-orange);color:#fff;border-color:var(--accent-orange);}
.td-btn.capture:hover{filter:brightness(1.08);}
.tool-btn.active{
  background:var(--accent-cyan);color:#fff;border-color:var(--accent-cyan);
  box-shadow:0 0 0 3px rgba(14,116,144,.22);
}
.surface-btn.active{
  background:var(--accent-orange-bg);color:var(--accent-orange);
  border-color:var(--accent-orange-border);
  box-shadow:0 0 0 3px var(--accent-orange-bg);
}
.td-icon{font-size:16px;line-height:1;}
.td-label{font-size:11px;letter-spacing:.04em;}
#undoCount{font-size:10px;font-family:'DM Mono',monospace;color:var(--text-muted);min-width:14px;text-align:center;}
#captureHint{
  position:fixed;left:50%;bottom:18px;transform:translateX(-50%);
  z-index:10001;display:none;
  background:rgba(13,17,23,.92);color:#fff;
  border:1px solid var(--accent-orange-border);
  border-radius:10px;padding:10px 14px;
  font:700 12px 'DM Mono',monospace;letter-spacing:.05em;
  box-shadow:0 10px 36px rgba(0,0,0,.5);
  pointer-events:none;text-align:center;
}
body.snip-selecting #captureHint{display:block;}
body.clean-capture #toolDock,
body.clean-capture #bottomBar,
body.clean-capture #kbHint,
body.clean-capture #toast,
body.clean-capture #result,
body.clean-capture #rowWiseBar{
  opacity:0!important;visibility:hidden!important;pointer-events:none!important;
}
body.clean-capture #viewer::before{opacity:.35;}
body.clean-capture #customerBanner{
  opacity:1!important;visibility:visible!important;
  display:flex!important;top:16px;z-index:20;
  background:rgba(13,17,23,.72);
  pointer-events:none!important;
}
[data-theme="light"] body.clean-capture #customerBanner{background:rgba(255,255,255,.86);}

/* ── Theme Toggle ── */
#navBack{
  position:absolute;top:14px;left:14px;z-index:26;
  background:rgba(13,17,23,.9);
  border:1px solid var(--border-color);
  border-radius:10px;padding:8px 12px;
  backdrop-filter:blur(8px);
  display:flex;align-items:center;gap:8px;
  cursor:pointer;
  transition:all .2s;
  box-shadow:0 4px 16px rgba(0,0,0,.3);
  color:var(--text-primary);
  text-decoration:none;
  font-size:11px;
  font-family:'DM Mono',monospace;
  letter-spacing:.08em;
  text-transform:uppercase;
  font-weight:700;
}
[data-theme="light"] #navBack{
  background:rgba(255,255,255,.95);
}
#navBack:hover{
  border-color:var(--accent-orange-border);
  transform:translateY(-1px);
}
#themeToggle{
  position:absolute;top:14px;left:96px;z-index:25;
  background:rgba(13,17,23,.9);
  border:1px solid var(--border-color);
  border-radius:10px;padding:8px 12px;
  backdrop-filter:blur(8px);
  display:flex;align-items:center;gap:8px;
  cursor:pointer;
  transition:all .2s;
  box-shadow:0 4px 16px rgba(0,0,0,.3);
}
[data-theme="light"] #themeToggle{
  background:rgba(255,255,255,.95);
}
#themeToggle:hover{
  border-color:var(--accent-orange-border);
  transform:translateY(-1px);
}
#themeToggle .theme-icon{
  font-size:18px;
  transition:transform .3s;
}
#themeToggle .theme-label{
  font-size:11px;
  font-family:'DM Mono',monospace;
  letter-spacing:.08em;
  text-transform:uppercase;
  color:var(--text-secondary);
  font-weight:600;
}

/* ── Customer Banner ── */
#customerBanner{
  position:absolute;top:118px;left:50%;transform:translateX(-50%);z-index:15;
  background:var(--bg-secondary);
  border:1px solid var(--accent-orange-border);
  border-radius:10px;padding:10px 16px;backdrop-filter:blur(8px);
  display:none;align-items:center;gap:12px;
  box-shadow:0 4px 24px rgba(0,0,0,.4);
  max-width:min(420px,calc(100vw - 24px));
  min-width:0;
}
#customerBanner .cb-icon{font-size:20px;}
#customerBanner .cb-label{
  font-size:10px;
  font-family:'DM Mono',monospace;
  letter-spacing:.16em;
  text-transform:uppercase;
  color:var(--accent-orange-dim);
  font-weight:600;
}
#customerBanner .cb-name{
  font-size:15px;
  font-weight:800;
  color:var(--text-accent);
  margin-top:2px;
  max-width:300px;
  overflow:hidden;
  text-overflow:ellipsis;
  white-space:nowrap;
}

/* ── Snipping Overlay ── */
#overlay{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.15);display:none;cursor:crosshair;z-index:9999;}
#selection{position:absolute;border:2px dashed var(--accent-orange);background:var(--accent-orange-bg);}
#result{
  position:fixed;bottom:90px;left:50%;transform:translateX(-50%);z-index:10000;
  background:var(--bg-secondary);
  border:1px solid var(--accent-orange-border);
  border-radius:10px;padding:12px 20px;
  font-size:13px;
  font-family:'DM Mono',monospace;
  color:var(--success-text);
  display:none;max-width:90vw;text-align:center;
  box-shadow:0 4px 24px rgba(0,0,0,.6);
}

/* ── Undo/redo bar — top right ── */
#undoBar{
  position:absolute;top:14px;right:14px;z-index:20;
  display:flex;gap:8px;align-items:center;
}
.undo-btn{
  padding:8px 14px;
  background:var(--bg-secondary);
  border:1px solid var(--border-color);
  border-radius:8px;
  color:var(--text-secondary);
  cursor:pointer;
  font-size:11px;
  font-family:'DM Mono',monospace;
  backdrop-filter:blur(8px);
  transition:all .2s;
  letter-spacing:.06em;
  font-weight:600;
}
.undo-btn:hover:not(:disabled){
  background:var(--bg-tertiary);
  color:var(--text-primary);
  border-color:var(--border-color);
  transform:translateY(-1px);
}
.undo-btn:disabled{opacity:.3;cursor:not-allowed;}
#undoCount{
  font-size:10px;
  font-family:'DM Mono',monospace;
  color:var(--text-muted);
  letter-spacing:.08em;
}

/* ═══════════════════════════════════════
   FLOATING LEFT TOOLBAR — TOOLS
═══════════════════════════════════════ */
#floatTools{
  position:absolute;left:14px;top:50%;transform:translateY(-50%);
  z-index:30;display:flex;flex-direction:column;gap:8px;
  background:var(--bg-secondary);
  border:1px solid var(--border-color);
  border-radius:16px;padding:12px 10px;backdrop-filter:blur(12px);
  box-shadow:0 8px 32px rgba(0,0,0,.5);
}
#floatTools .ft-label{
  font-size:9px;
  font-family:'DM Mono',monospace;
  color:var(--accent-orange-dim);
  letter-spacing:.16em;
  text-transform:uppercase;
  text-align:center;
  margin-bottom:4px;
  font-weight:700;
}
.ft-btn{
  display:flex;flex-direction:column;align-items:center;gap:4px;
  padding:10px 8px;
  border:1px solid var(--border-light);
  border-radius:10px;
  background:var(--bg-primary);
  color:var(--text-secondary);
  cursor:pointer;
  font-size:11px;
  font-family:'DM Mono',monospace;
  transition:all .2s;
  min-width:64px;
  text-align:center;
  letter-spacing:.04em;
}
.ft-btn:hover{
  background:var(--bg-tertiary);
  color:var(--text-primary);
  border-color:var(--border-color);
  transform:translateY(-1px);
}
.ft-btn.active{
  background:var(--accent-cyan);
  color:var(--accent-cyan-light);
  border-color:var(--accent-cyan);
  box-shadow:0 0 0 3px rgba(14,116,144,.25);
}
[data-theme="light"] .ft-btn.active{
  background:var(--accent-cyan);
  color:#fff;
}
.ft-btn .ft-icon{font-size:18px;line-height:1;}
.ft-btn .ft-name{font-size:10px;font-weight:600;margin-top:2px;}
.ft-btn .ft-key{
  font-size:9px;
  background:var(--bg-tertiary);
  border-radius:4px;
  padding:2px 6px;
  margin-top:2px;
  color:var(--text-tertiary);
  font-family:'DM Mono',monospace;
}
.ft-btn.active .ft-key{
  background:rgba(103,232,249,.25);
  color:var(--accent-cyan-light);
}
[data-theme="light"] .ft-btn.active .ft-key{
  background:rgba(255,255,255,.3);
  color:#fff;
}
.ft-sep{height:1px;background:var(--border-light);margin:4px 0;}

/* ═══════════════════════════════════════
   FLOATING RIGHT PANEL — PAINT SURFACE
═══════════════════════════════════════ */
#floatPaint{
  position:absolute;right:14px;top:50%;transform:translateY(-50%);
  z-index:30;display:flex;flex-direction:column;gap:6px;
  background:var(--bg-secondary);
  border:1px solid var(--border-color);
  border-radius:16px;padding:12px 10px;backdrop-filter:blur(12px);
  box-shadow:0 8px 32px rgba(0,0,0,.5);min-width:80px;
}
#floatPaint .fp-label{
  font-size:9px;
  font-family:'DM Mono',monospace;
  color:var(--accent-orange-dim);
  letter-spacing:.16em;
  text-transform:uppercase;
  text-align:center;
  margin-bottom:4px;
  font-weight:700;
}
.fp-btn{
  display:flex;flex-direction:column;align-items:center;gap:4px;
  padding:10px 8px;
  border:1px solid var(--border-light);
  border-radius:10px;
  background:var(--bg-primary);
  color:var(--text-secondary);
  cursor:pointer;
  font-size:11px;
  font-family:'DM Mono',monospace;
  transition:all .2s;
  min-width:68px;
  text-align:center;
}
.fp-btn:hover{
  background:var(--bg-tertiary);
  color:var(--text-primary);
  border-color:var(--border-color);
  transform:translateY(-1px);
}
.fp-btn.active{
  background:var(--accent-orange-bg);
  color:var(--accent-orange);
  border-color:var(--accent-orange-border);
  box-shadow:0 0 0 3px var(--accent-orange-bg);
}
.fp-btn .fp-icon{font-size:16px;line-height:1;}
.fp-btn .fp-name{font-size:10px;font-weight:700;margin-top:2px;}
.fp-btn .fp-key{
  font-size:9px;
  background:var(--bg-tertiary);
  border-radius:4px;
  padding:2px 6px;
  margin-top:2px;
  color:var(--text-tertiary);
  font-family:'DM Mono',monospace;
}
.fp-btn.active .fp-key{
  background:rgba(245,166,35,.3);
  color:var(--accent-orange);
}
.fp-sep{height:1px;background:var(--border-light);margin:4px 0;}

/* action buttons inside paint panel */
.fp-act{
  display:flex;flex-direction:column;align-items:center;gap:3px;
  padding:7px 6px;
  border:1px solid var(--border-light);
  border-radius:8px;
  background:var(--bg-primary);
  color:var(--text-tertiary);
  cursor:pointer;
  font-size:10px;
  font-family:'DM Mono',monospace;
  transition:all .2s;
  text-align:center;
  width:100%;
  font-weight:600;
}
.fp-act:hover{
  background:var(--bg-tertiary);
  color:var(--text-primary);
  transform:translateY(-1px);
}
.fp-act.fill{
  border-color:var(--accent-orange-border);
  color:var(--accent-orange);
}
.fp-act.fill:hover{
  background:var(--accent-orange);
  color:#fff;
  border-color:var(--accent-orange);
}
.fp-act.clear{
  border-color:var(--error-border);
  color:var(--error-text);
}
.fp-act.clear:hover{
  background:var(--error-bg);
}
.fp-act .fa-key{
  font-size:8px;
  background:var(--bg-tertiary);
  border-radius:3px;
  padding:2px 5px;
  margin-top:1px;
  display:inline-block;
}

/* Row-wise controls */
#rowWiseBar{
  display:none;position:absolute;left:50%;transform:translateX(-50%);
  bottom:90px;z-index:50;
  background:var(--bg-secondary);
  border:1px solid var(--accent-orange-border);
  border-radius:12px;padding:10px 14px;backdrop-filter:blur(12px);
  box-shadow:0 4px 24px rgba(0,0,0,.6);
  flex-direction:column;gap:8px;min-width:280px;
}
#rowWiseBar.show{display:flex;}
#rowWiseBar .rw-title{
  font-size:10px;
  font-family:'DM Mono',monospace;
  color:var(--accent-orange-dim);
  text-transform:uppercase;
  letter-spacing:.14em;
  margin-bottom:2px;
  display:flex;
  justify-content:space-between;
  align-items:center;
  font-weight:700;
}
#rowWiseBar .rw-btns{display:flex;flex-wrap:wrap;gap:5px;}
.rw-row-btn{
  padding:6px 12px;
  border-radius:6px;
  border:1px solid var(--border-color);
  background:var(--bg-primary);
  color:var(--text-tertiary);
  cursor:pointer;
  font-size:11px;
  font-family:'DM Mono',monospace;
  transition:all .2s;
  font-weight:600;
}
.rw-row-btn:hover{
  background:var(--accent-orange);
  color:#fff;
  border-color:var(--accent-orange);
  transform:translateY(-1px);
}
.rw-cancel{
  padding:5px 12px;
  border-radius:6px;
  border:1px solid var(--error-border);
  background:transparent;
  color:var(--error-text);
  cursor:pointer;
  font-size:10px;
  font-family:'DM Mono',monospace;
  transition:all .2s;
  font-weight:600;
}
.rw-cancel:hover{
  background:var(--error-bg);
}

/* ═══════════════════════════════════════
   BOTTOM CONTROL BAR
═══════════════════════════════════════ */
#bottomBar{
  flex:0 0 auto;
  background:var(--bg-accent);
  border-top:1px solid var(--border-light);
  display:flex;align-items:stretch;gap:0;overflow-x:auto;
  scrollbar-width:thin;z-index:100;
  padding:10px 0;
}
#bottomBar::-webkit-scrollbar{height:6px;}
#bottomBar::-webkit-scrollbar-track{background:var(--bg-primary);}
#bottomBar::-webkit-scrollbar-thumb{background:var(--border-color);border-radius:3px;}
.bb-section{
  display:flex;flex-direction:column;justify-content:center;
  padding:8px 14px;
  border-right:1px solid var(--border-lighter);
  flex-shrink:0;min-width:0;
}
.bb-section:last-child{border-right:none;}
.bb-head{
  font-size:9px;
  font-family:'DM Mono',monospace;
  color:var(--accent-orange-dim);
  text-transform:uppercase;
  letter-spacing:.15em;
  margin-bottom:6px;
  display:flex;
  align-items:center;
  gap:5px;
  white-space:nowrap;
  font-weight:700;
}
.bb-head::before{
  content:'';
  width:3px;
  height:10px;
  background:var(--accent-orange);
  border-radius:2px;
  display:inline-block;
  flex-shrink:0;
}
.bb-row{display:flex;align-items:center;gap:6px;flex-wrap:nowrap;}

/* brand */
.bb-gem{
  width:30px;height:30px;
  background:var(--accent-orange);
  clip-path:polygon(50% 0%,100% 25%,100% 75%,50% 100%,0% 75%,0% 25%);
  display:flex;align-items:center;justify-content:center;
  font-size:8px;font-weight:800;
  color:var(--bg-primary);
  flex-shrink:0;
}
.bb-brand-name{
  font-size:12px;
  font-weight:800;
  color:var(--text-accent);
  line-height:1.3;
  white-space:nowrap;
}
.bb-brand-sub{
  font-size:9px;
  letter-spacing:.16em;
  text-transform:uppercase;
  color:var(--accent-orange-dim);
  font-family:'DM Mono',monospace;
  font-weight:600;
}

/* small range row */
.bb-range-row{
  display:flex;align-items:center;gap:5px;margin-bottom:4px;
}
.bb-range-row:last-child{margin-bottom:0;}
.bb-lbl{
  font-size:10px;
  color:var(--text-tertiary);
  font-family:'DM Mono',monospace;
  min-width:48px;
  white-space:nowrap;
  font-weight:600;
}
input[type=range]{
  accent-color:var(--accent-orange);
  width:90px;height:16px;
}
.bb-val{
  font-size:10px;
  color:var(--accent-orange);
  min-width:35px;
  text-align:right;
  font-family:'DM Mono',monospace;
  font-weight:600;
}

/* grid inputs */
input[type=number]{
  width:50px;height:28px;
  text-align:center;
  background:var(--bg-primary);
  border:1px solid var(--border-color);
  color:var(--text-primary);
  border-radius:5px;
  font-size:12px;
  font-family:'DM Mono',monospace;
  font-weight:600;
}
input[type=number]:focus{
  outline:none;
  border-color:var(--accent-orange);
  box-shadow:0 0 0 2px var(--accent-orange-bg);
}

/* buttons */
.bb-btn{
  padding:7px 12px;
  border:1px solid var(--border-color);
  border-radius:7px;
  background:var(--bg-primary);
  color:var(--text-tertiary);
  cursor:pointer;
  font-size:11px;
  font-family:'DM Mono',monospace;
  transition:all .2s;
  white-space:nowrap;
  font-weight:600;
}
.bb-btn:hover{
  background:var(--accent-orange);
  color:#fff;
  border-color:var(--accent-orange);
  transform:translateY(-1px);
}
.bb-btn.primary{
  background:var(--accent-orange);
  color:#fff;
  border-color:var(--accent-orange);
}
.bb-btn.primary:hover{
  background:var(--accent-orange-dim);
  filter:brightness(1.1);
}
.bb-btn.danger{
  background:var(--error-bg);
  color:var(--error-text);
  border-color:var(--error-border);
}
.bb-btn.danger:hover{
  background:var(--error-border);
  color:#fff;
}
.bb-btn.snip{
  background:var(--accent-orange-bg);
  color:var(--accent-orange);
  border-color:var(--accent-orange-border);
}
.bb-btn.snip:hover{
  background:var(--accent-orange);
  color:#fff;
}

/* visibility toggles */
.bb-tog-row{display:flex;gap:5px;flex-wrap:nowrap;}
.bb-tog{display:flex;flex-direction:column;align-items:center;gap:3px;cursor:pointer;}
.bb-tog input{display:none;}
.bb-tog-tr{
  width:32px;height:16px;
  background:var(--bg-tertiary);
  border-radius:8px;
  position:relative;
  transition:.25s;
}
.bb-tog-th{
  width:12px;height:12px;
  background:var(--text-muted);
  border-radius:50%;
  position:absolute;
  top:2px;left:2px;
  transition:.25s;
}
.bb-tog input:checked~.bb-tog-tr{
  background:var(--accent-cyan);
}
.bb-tog input:checked~.bb-tog-tr .bb-tog-th{
  left:18px;
  background:#fff;
}
.bb-tog-tx{
  font-size:9px;
  color:var(--text-tertiary);
  font-family:'DM Mono',monospace;
  white-space:nowrap;
  font-weight:600;
}

/* adjust surface */
.adj-mini{display:flex;flex-direction:column;gap:4px;}
.adj-mini-surf{display:flex;gap:4px;margin-bottom:4px;flex-wrap:nowrap;}
.adj-mini-tab{
  padding:4px 9px;
  border:1px solid var(--border-light);
  border-radius:5px;
  background:var(--bg-primary);
  color:var(--text-tertiary);
  cursor:pointer;
  font-size:10px;
  font-family:'DM Mono',monospace;
  transition:all .2s;
  white-space:nowrap;
  font-weight:600;
}
.adj-mini-tab:hover{
  background:var(--bg-tertiary);
  color:var(--text-primary);
}
.adj-mini-tab.active{
  background:var(--accent-orange);
  color:#fff;
  border-color:var(--accent-orange);
  font-weight:700;
}
.adj-row2{display:flex;align-items:center;gap:4px;}
.adj-lbl2{
  font-size:9px;
  color:var(--text-muted);
  font-family:'DM Mono',monospace;
  min-width:36px;
  font-weight:600;
}
.adj-btns2{display:flex;gap:3px;}
.adj-btns2 button{
  width:24px;height:22px;
  border:1px solid var(--border-light);
  border-radius:4px;
  background:var(--bg-primary);
  color:var(--text-tertiary);
  cursor:pointer;
  font-size:11px;
  line-height:1;
  transition:all .2s;
  font-weight:600;
}
.adj-btns2 button:hover:not(:disabled){
  background:var(--accent-orange);
  color:#fff;
  border-color:var(--accent-orange);
  transform:translateY(-1px);
}
.adj-btns2 button:disabled{opacity:.3;cursor:not-allowed;}
.adj-val2{
  font-size:9px;
  color:var(--accent-orange);
  min-width:36px;
  text-align:center;
  background:var(--bg-primary);
  border-radius:4px;
  padding:3px 4px;
  border:1px solid var(--accent-orange-border);
  font-family:'DM Mono',monospace;
  font-weight:600;
}
.lock-mini{
  display:flex;align-items:center;gap:6px;margin-bottom:5px;
}
.lock-mini span{
  font-size:10px;
  color:var(--text-tertiary);
  font-family:'DM Mono',monospace;
  font-weight:600;
}
.lock-btn-mini{
  padding:4px 10px;
  border:none;
  border-radius:5px;
  cursor:pointer;
  font-size:10px;
  font-weight:700;
  transition:all .2s;
}
.lock-btn-mini.unlocked{
  background:var(--success-bg);
  color:var(--success-text);
}
.lock-btn-mini.locked{
  background:var(--error-bg);
  color:var(--error-text);
}

/* spacer */
.spacer-row{display:flex;align-items:center;gap:6px;}

/* tiles section */
#carousel{
  display:flex;gap:6px;overflow-x:auto;
  scrollbar-width:thin;padding:2px 0;
}
#carousel img{
  height:46px;min-width:46px;
  object-fit:cover;border-radius:6px;
  cursor:pointer;
  border:2px solid var(--border-light);
  transition:all .2s;
}
#carousel img:hover{
  border-color:var(--accent-orange);
  transform:translateY(-2px);
}
#carousel img.sel{
  border-color:var(--accent-orange);
  box-shadow:0 0 0 3px var(--accent-orange-bg);
}
#logoSlot{
  width:50px;height:46px;
  border:2px dashed var(--border-color);
  border-radius:7px;
  display:flex;align-items:center;justify-content:center;
  cursor:pointer;position:relative;overflow:hidden;
  background:var(--bg-primary);
  transition:all .2s;flex-shrink:0;
}
#logoSlot:hover{
  border-color:var(--accent-orange);
  transform:translateY(-2px);
}
#logoSlot img{width:100%;height:100%;object-fit:contain;}
#logoSlot .placeholder{font-size:20px;pointer-events:none;}
#logoInput{display:none;}
#bbStatus{
  font-size:10px;
  color:var(--accent-orange);
  font-family:'DM Mono',monospace;
  letter-spacing:.05em;
  padding:3px 0;
  white-space:nowrap;
  max-width:180px;
  overflow:hidden;
  text-overflow:ellipsis;
  font-weight:600;
}

/* submission tiles in bottom bar */
#subTilesSection{display:none;}
#subTileGrid{display:flex;gap:6px;overflow-x:auto;scrollbar-width:thin;}
.sti{
  display:flex;flex-direction:column;align-items:center;
  gap:3px;cursor:pointer;flex-shrink:0;
  transition:all .2s;
}
.sti img{
  width:46px;height:46px;object-fit:cover;
  border-radius:6px;
  border:2px solid rgba(167,139,250,.25);
  transition:all .2s;
}
.sti:hover img{
  border-color:var(--accent-purple-light);
  transform:translateY(-2px);
}
.sti.active img{
  border-color:var(--accent-orange);
  box-shadow:0 0 0 3px var(--accent-orange-bg);
}
.sti-name{
  font-size:9px;
  color:var(--accent-purple-light);
  font-family:'DM Mono',monospace;
  text-align:center;width:46px;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
  font-weight:600;
}
.sti.active .sti-name{color:var(--accent-orange);}
.area-tag-inline{
  font-size:9px;
  font-family:'DM Mono',monospace;
  padding:2px 6px;
  border-radius:4px;
  background:rgba(124,58,237,.2);
  color:var(--accent-purple-light);
  white-space:nowrap;
  align-self:center;flex-shrink:0;
  font-weight:600;
}
.bb-btn.fill-all{
  background:rgba(124,58,237,.15);
  color:var(--accent-purple-light);
  border-color:rgba(124,58,237,.3);
}
.bb-btn.fill-all:hover{
  background:var(--accent-purple);
  color:#fff;
}

/* ── Toast ── */
#toast{
  position:fixed;bottom:90px;left:50%;transform:translateX(-50%);
  z-index:10000;border-radius:10px;
  padding:12px 24px;
  font-size:13px;
  font-family:'DM Mono',monospace;
  letter-spacing:.08em;
  font-weight:700;
  display:none;
  box-shadow:0 4px 24px rgba(0,0,0,.5);
  white-space:nowrap;
}
.toast-ok{
  background:var(--success-bg);
  color:var(--success-text);
  border:1px solid var(--success-text);
}
.toast-err{
  background:var(--error-bg);
  color:var(--error-text);
  border:1px solid var(--error-border);
}

/* ── Custom Confirm Modal ── */
#confirmModal{
  position:fixed;inset:0;z-index:500;
  background:rgba(13,17,23,.88);
  backdrop-filter:blur(6px);
  display:none;align-items:center;justify-content:center;
}
#confirmModal.show{display:flex;}
.conf-box{
  background:var(--bg-secondary);
  border:1px solid var(--error-border);
  border-radius:14px;padding:24px;
  width:min(380px,94vw);
  box-shadow:0 20px 60px rgba(0,0,0,.85);
}
.conf-icon{font-size:32px;text-align:center;margin-bottom:12px;}
.conf-msg{
  font-size:14px;
  color:var(--text-primary);
  text-align:center;
  margin-bottom:20px;
  line-height:1.6;
}
.conf-btns{display:flex;gap:10px;}
.conf-btn{
  flex:1;padding:11px;border-radius:8px;
  cursor:pointer;
  font-size:12px;
  font-family:'DM Mono',monospace;
  font-weight:700;
  letter-spacing:.06em;
  border:1px solid transparent;
  transition:all .2s;
}
.conf-btn.cancel{
  background:var(--bg-tertiary);
  color:var(--text-tertiary);
  border-color:var(--border-light);
}
.conf-btn.cancel:hover{
  background:var(--bg-primary);
  color:var(--text-primary);
}
.conf-btn.confirm{
  background:var(--error-bg);
  color:var(--error-text);
  border-color:var(--error-border);
}
.conf-btn.confirm:hover{
  background:var(--error-border);
  color:#fff;
}

/* ── Keyboard shortcut hint overlay ── */
#kbHint{
  position:fixed;bottom:90px;right:14px;z-index:50;
  background:var(--bg-secondary);
  border:1px solid var(--border-color);
  border-radius:10px;padding:12px 16px;
  font-size:10px;
  font-family:'DM Mono',monospace;
  color:var(--text-muted);
  backdrop-filter:blur(8px);
  line-height:2.2;
  display:none;
  box-shadow:0 4px 16px rgba(0,0,0,.4);
}
#kbHint.show{display:block;}
#kbHint b{
  color:var(--accent-orange);
  margin-right:5px;
  font-weight:700;
}

/* 3D scene */
#sceneStage{
  perspective:900px;
  perspective-origin:50% 38%;
  display:flex;justify-content:center;align-items:center;
  width:min(560px,calc(100vw - 220px));
  height:min(420px,calc(100vh - 170px));
  min-width:300px;min-height:280px;
}
.cell.sel-cell{
  outline:2px solid var(--accent-orange);
  outline-offset:-1px;
}

@media(max-width:1024px){
  #toolDock{align-items:flex-start;justify-content:flex-start;flex-wrap:nowrap;overflow-x:auto;scrollbar-width:none;padding-bottom:4px;max-height:none;}
  #toolDock::-webkit-scrollbar{display:none;}
  .td-group{flex-shrink:0;}
  #customerBanner{top:62px;}
  #sceneStage{width:calc(100vw - 70px);}
}

@media(max-width:768px){
  #toolDock{top:8px;left:8px;right:8px;transform:none;width:auto;}
  .td-group{padding:4px;border-radius:10px;gap:3px;}
  .td-btn,.tool-btn,.surface-btn{height:34px;min-width:34px;padding:0 9px;font-size:11px;}
  .td-label{display:none;}
  #customerBanner{top:52px;padding:8px 12px;}
  #customerBanner .cb-name{max-width:220px;font-size:14px;}
  #sceneStage{width:calc(100vw - 24px);height:calc(100vh - 200px);}
  #bottomBar{
    position:fixed;left:8px;right:8px;bottom:8px;z-index:130;
    max-height:52vh;border:1px solid var(--border-color);border-radius:14px;
    box-shadow:0 14px 45px rgba(0,0,0,.55);
    transition:transform .22s ease,opacity .22s ease;
  }
  body.controls-collapsed #bottomBar{transform:translateY(calc(100% + 16px));opacity:0;pointer-events:none;}
  .bb-section{padding:6px 10px;}
}

@media(max-width:480px){
  #toolDock{gap:6px;}
  .td-group{gap:2px;}
  .td-btn,.tool-btn,.surface-btn{height:32px;min-width:32px;padding:0 8px;}
  .td-icon{font-size:15px;}
  #customerBanner{top:48px;padding:7px 10px;}
  #customerBanner .cb-icon{font-size:16px;}
  #customerBanner .cb-label{font-size:8px;}
  #customerBanner .cb-name{font-size:13px;max-width:180px;}
  .bb-head{font-size:8px;}
  .bb-lbl{font-size:9px;min-width:42px;}
  .bb-val{font-size:9px;}
  input[type=range]{width:70px;}
  input[type=number]{width:44px;height:26px;font-size:11px;}
  .bb-btn{padding:6px 10px;font-size:10px;}
  #sceneStage{width:calc(100vw - 16px);height:calc(100vh - 220px);}
}
</style>
</head>
<body>

<!-- Toast -->
<div id="toast"></div>

<!-- Custom confirm modal -->
<div id="confirmModal">
  <div class="conf-box">
    <div class="conf-icon" id="confIcon">🗑</div>
    <div class="conf-msg" id="confMsg">Are you sure?</div>
    <div class="conf-btns">
      <button class="conf-btn cancel" id="confCancelBtn">Cancel</button>
      <button class="conf-btn confirm" id="confConfirmBtn">Delete</button>
    </div>
  </div>
</div>

<!-- SNIPPING OVERLAY -->
<div id="overlay"></div>
<div id="captureHint">Drag over the room area. Toolbars and options will stay hidden in the saved image.</div>
<div id="result"></div>

<!-- ROW-WISE FILL BAR -->
<div id="rowWiseBar">
  <div class="rw-title">
    <span>Fill Row — <span id="rwSurfLabel">back</span></span>
    <button class="rw-cancel" onclick="cancelRowWise()">✕ Cancel [Esc]</button>
  </div>
  <div class="rw-btns" id="rwBtns"></div>
</div>

<!-- Keyboard hint -->
<div id="kbHint">
  <b>O</b>Orbit &nbsp;<b>D</b>Draw &nbsp;<b>E</b>Erase<br>
  <b>1</b>Back &nbsp;<b>2</b>Left &nbsp;<b>3</b>Right &nbsp;<b>4</b>Floor<br>
  <b>F</b>Fill surf &nbsp;<b>C</b>Clear surf &nbsp;<b>R</b>Row-wise<br>
  <b>Z</b>Undo &nbsp;<b>Y</b>Redo &nbsp;<b>?</b>Toggle hints
</div>

<!-- MAIN VIEWER (fills space above bottom bar) -->
<div id="viewer">
  <!-- Customer banner -->
  <div id="customerBanner">
    <div class="cb-icon">🏠</div>
    <div>
      <div class="cb-label">Customer</div>
      <div class="cb-name" id="customerName">—</div>
    </div>
  </div>

  <!-- Standard toolbar -->
  <div id="toolDock" class="capture-hide" role="toolbar" aria-label="Visualizer tools">
    <div class="td-group" aria-label="Menu and navigation">
      <button class="td-btn menu" id="panelToggle" onclick="toggleOptionsPanel()" title="Menu / Options">
        <span class="td-icon">☰</span><span class="td-label">Menu</span>
      </button>
      <a href="../subadmins.php" class="td-btn" title="Back to Subadmins">
        <span class="td-icon">←</span><span class="td-label">Back</span>
      </a>
      <button class="td-btn" onclick="toggleTheme()" title="Theme">
        <span class="td-icon" id="themeIcon">🌙</span><span class="td-label" id="themeLabel">Dark</span>
      </button>
    </div>

    <div class="td-group" aria-label="History">
      <button class="td-btn" id="undoBtn" onclick="undoAction()" disabled title="Undo (Ctrl+Z)">↩</button>
      <button class="td-btn" id="redoBtn" onclick="redoAction()" disabled title="Redo (Ctrl+Y)">↪</button>
      <span id="undoCount"></span>
      <button class="td-btn" onclick="toggleKbHint()" title="Keyboard shortcuts (?)">⌨</button>
    </div>

    <div class="td-group" aria-label="Tools">
      <button class="tool-btn active" id="tOrbit" onclick="setTool('orbit')" title="Orbit [O]">
        <span class="td-icon">⟳</span><span class="td-label">Orbit</span>
      </button>
      <button class="tool-btn" id="tDraw" onclick="setTool('draw')" title="Draw [D]">
        <span class="td-icon">✎</span><span class="td-label">Draw</span>
      </button>
      <button class="tool-btn" id="tErase" onclick="setTool('erase')" title="Erase [E]">
        <span class="td-icon">⌫</span><span class="td-label">Erase</span>
      </button>
    </div>

    <div class="td-group" aria-label="Surface">
      <button class="surface-btn active" id="ps-back" onclick="sel('back')" title="Back wall [1]">Back</button>
      <button class="surface-btn" id="ps-left" onclick="sel('left')" title="Left wall [2]">Left</button>
      <button class="surface-btn" id="ps-right" onclick="sel('right')" title="Right wall [3]">Right</button>
      <button class="surface-btn" id="ps-floor" onclick="sel('floor')" title="Floor [4]">Floor</button>
    </div>

    <div class="td-group" aria-label="Paint actions">
      <button class="td-btn" onclick="fillSurf()" title="Fill surface [F]"><span class="td-icon">▣</span><span class="td-label">Fill</span></button>
      <button class="td-btn" onclick="showRowWise()" title="Row-wise fill [R]"><span class="td-icon">▤</span><span class="td-label">Row</span></button>
      <button class="td-btn" onclick="clearSurf()" title="Clear surface [C]"><span class="td-icon">×</span><span class="td-label">Clear</span></button>
      <button class="td-btn capture" onclick="startCapture()" title="Clean capture [P]"><span class="td-icon">⛶</span><span class="td-label">Capture</span></button>
    </div>
  </div>

  <!-- 3D SCENE -->
  <div id="sceneWrap" style="transform-style:preserve-3d;position:relative;z-index:1;">
    <div id="sceneStage">
      <div class="room" id="room" style="transform-style:preserve-3d;"></div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════
     BOTTOM CONTROL BAR
═══════════════════════════════════════════ -->
<div id="bottomBar">

  <!-- Brand -->
  <div class="bb-section" style="padding:8px 16px;">
    <div class="bb-row" style="gap:12px;">
      <div class="bb-gem">AE</div>
      <div>
        <div class="bb-brand-name">3D Tile Visualizer</div>
        <div class="bb-brand-sub">Aandavar Enterprises</div>
      </div>
    </div>
  </div>

  <!-- Submission tiles -->
  <div class="bb-section" id="subTilesSection" style="max-width:300px;">
    <div class="bb-head">Submission Tiles <span id="subTileCount" style="margin-left:5px;font-size:8px;background:rgba(124,58,237,.25);color:var(--accent-purple-light);border-radius:8px;padding:1px 6px;">0</span></div>
    <div style="display:flex;align-items:center;gap:6px;">
      <div id="subTileGrid"></div>
      <button class="bb-btn fill-all" id="fillAllSubBtn" onclick="fillAllSubTiles()" style="display:none;font-size:10px;padding:5px 10px;">⬡ All</button>
    </div>
  </div>

  <!-- View controls -->
  <div class="bb-section">
    <div class="bb-head">View</div>
    <div class="bb-range-row"><span class="bb-lbl">Zoom</span><input type="range" id="zoomR" min="30" max="200" value="100"><span class="bb-val" id="zoomV">100%</span></div>
    <div class="bb-range-row"><span class="bb-lbl">Rot Y</span><input type="range" id="rotYR" min="-60" max="60" value="25"><span class="bb-val" id="rotYV">25°</span></div>
    <div class="bb-range-row"><span class="bb-lbl">Rot X</span><input type="range" id="rotXR" min="-60" max="0" value="-20"><span class="bb-val" id="rotXV">-20°</span></div>
    <div style="margin-top:5px;"><button class="bb-btn" onclick="resetView()" style="font-size:10px;padding:4px 10px;">Reset View</button></div>
  </div>

  <!-- Adjust surface -->
  <div class="bb-section">
    <div class="bb-head">Adjust Surface</div>
    <div class="lock-mini">
      <span id="lockLabel">Unlocked</span>
      <button class="lock-btn-mini unlocked" id="lockBtn" onclick="toggleLock()">🔓 Unlock</button>
    </div>
    <div class="adj-mini-surf">
      <button class="adj-mini-tab active" onclick="selectAdjSurf('back',this)">Back</button>
      <button class="adj-mini-tab" onclick="selectAdjSurf('left',this)">Left</button>
      <button class="adj-mini-tab" onclick="selectAdjSurf('right',this)">Right</button>
      <button class="adj-mini-tab" onclick="selectAdjSurf('floor',this)">Floor</button>
    </div>
    <div class="adj-mini">
      <div class="adj-row2"><span class="adj-lbl2">X</span><div class="adj-btns2"><button id="adj-tx-m" onclick="nudge('tx',-5)">←</button><button id="adj-tx-p" onclick="nudge('tx',5)">→</button></div><span class="adj-val2" id="av-tx">0px</span></div>
      <div class="adj-row2"><span class="adj-lbl2">Y</span><div class="adj-btns2"><button id="adj-ty-m" onclick="nudge('ty',-5)">↑</button><button id="adj-ty-p" onclick="nudge('ty',5)">↓</button></div><span class="adj-val2" id="av-ty">0px</span></div>
      <div class="adj-row2"><span class="adj-lbl2">Z</span><div class="adj-btns2"><button id="adj-tz-m" onclick="nudge('tz',-5)">↙</button><button id="adj-tz-p" onclick="nudge('tz',5)">↗</button></div><span class="adj-val2" id="av-tz">0px</span></div>
      <div class="adj-row2"><span class="adj-lbl2">Rx</span><div class="adj-btns2"><button id="adj-rx-m" onclick="nudge('rx',-5)">-5°</button><button id="adj-rx-p" onclick="nudge('rx',5)">+5°</button></div><span class="adj-val2" id="av-rx">0°</span></div>
      <div class="adj-row2"><span class="adj-lbl2">Ry</span><div class="adj-btns2"><button id="adj-ry-m" onclick="nudge('ry',-5)">-5°</button><button id="adj-ry-p" onclick="nudge('ry',5)">+5°</button></div><span class="adj-val2" id="av-ry">0°</span></div>
    </div>
    <div style="display:flex;gap:5px;margin-top:5px;">
      <button class="bb-btn" id="resetSurfBtn" onclick="resetSurf()" style="font-size:9px;padding:4px 8px;">Reset</button>
      <button class="bb-btn" id="resetAllBtn" onclick="resetAllSurfs()" style="font-size:9px;padding:4px 8px;">Reset All</button>
    </div>
  </div>

  <!-- Tile grid -->
  <div class="bb-section">
    <div class="bb-head">Tile Grid</div>
    <div class="bb-row" style="gap:6px;margin-bottom:5px;">
      <span class="bb-lbl">Wall</span>
      <span style="font-size:10px;color:var(--text-muted);font-family:'DM Mono',monospace;font-weight:600;">R</span><input type="number" id="wR" value="4" min="1" max="20">
      <span style="font-size:10px;color:var(--text-muted);font-family:'DM Mono',monospace;font-weight:600;">C</span><input type="number" id="wC" value="6" min="1" max="40">
    </div>
    <div class="bb-row" style="gap:6px;margin-bottom:7px;">
      <span class="bb-lbl">Floor</span>
      <span style="font-size:10px;color:var(--text-muted);font-family:'DM Mono',monospace;font-weight:600;">R</span><input type="number" id="fR" value="4" min="1" max="20">
      <span style="font-size:10px;color:var(--text-muted);font-family:'DM Mono',monospace;font-weight:600;">C</span><input type="number" id="fC" value="6" min="1" max="40">
    </div>
    <button class="bb-btn primary" onclick="buildRoom()">Apply</button>
  </div>

  <!-- Spacer/grout -->
  <div class="bb-section">
    <div class="bb-head">Grout</div>
    <div class="spacer-row">
      <input type="range" id="spacer" min="0" max="12" value="2" style="width:80px;">
      <span class="bb-val" id="spacerV">2px</span>
    </div>
  </div>

  <!-- Visibility -->
  <div class="bb-section">
    <div class="bb-head">Visibility</div>
    <div class="bb-tog-row">
      <label class="bb-tog"><input type="checkbox" id="vBack" checked onchange="toggleVis('back',this.checked)"><div class="bb-tog-tr"><div class="bb-tog-th"></div></div><span class="bb-tog-tx" id="lblBack">Back</span></label>
      <label class="bb-tog"><input type="checkbox" id="vLeft" checked onchange="toggleVis('left',this.checked)"><div class="bb-tog-tr"><div class="bb-tog-th"></div></div><span class="bb-tog-tx" id="lblLeft">Left</span></label>
      <label class="bb-tog"><input type="checkbox" id="vRight" checked onchange="toggleVis('right',this.checked)"><div class="bb-tog-tr"><div class="bb-tog-th"></div></div><span class="bb-tog-tx" id="lblRight">Right</span></label>
      <label class="bb-tog"><input type="checkbox" id="vFloor" checked onchange="toggleVis('floor',this.checked)"><div class="bb-tog-tr"><div class="bb-tog-th"></div></div><span class="bb-tog-tx" id="lblFloor">Floor</span></label>
    </div>
  </div>

  <!-- Manual tiles -->
  <div class="bb-section" style="max-width:240px;">
    <div class="bb-head">Tiles</div>
    <div class="bb-row" style="gap:7px;">
      <div id="logoSlot" onclick="handleLogoClick(event)" title="Upload logo tile">
        <span class="placeholder" id="logoPlaceholder">+</span>
        <img id="logoImg" style="display:none;">
      </div>
      <input type="file" id="logoInput" accept="image/*" onchange="loadLogo(event)">
      <label style="display:flex;align-items:center;justify-content:center;padding:0 12px;height:46px;background:var(--bg-tertiary);border:1px solid var(--border-light);border-radius:7px;cursor:pointer;font-size:11px;color:var(--text-tertiary);transition:all .2s;white-space:nowrap;font-weight:600;"
        onmouseenter="this.style.background='var(--accent-orange)';this.style.color='#fff';this.style.borderColor='var(--accent-orange)'"
        onmouseleave="this.style.background='var(--bg-tertiary)';this.style.color='var(--text-tertiary)';this.style.borderColor='var(--border-light)'">
        + Add<input type="file" multiple accept="image/*" onchange="loadImgs(event)" style="display:none;">
      </label>
      <div id="carousel" style="flex:1;"></div>
    </div>
    <div id="bbStatus">Orbit — drag to rotate</div>
  </div>

</div><!-- /bottomBar -->

<script>
/* ══════════════════════════════════════════
   PHP → JS bridge
══════════════════════════════════════════ */
const _SUB_ID       = <?= (int)$jsSubId ?>;
const _SELF         = <?= $jsSelf ?>;
const _ACCESS_TOKEN = <?= $jsToken ?>;

/* ══════════════════════════════════════════
   THEME MANAGEMENT
══════════════════════════════════════════ */
function toggleTheme(){
  const html = document.documentElement;
  const current = html.getAttribute('data-theme') || 'dark';
  const next = current === 'dark' ? 'light' : 'dark';
  html.setAttribute('data-theme', next);
  localStorage.setItem('ae_viz_theme', next);
  updateThemeUI(next);
}

function updateThemeUI(theme){
  const icon = document.getElementById('themeIcon');
  const label = document.getElementById('themeLabel');
  if(theme === 'light'){
    icon.textContent = '☀️';
    label.textContent = 'Light';
  }else{
    icon.textContent = '🌙';
    label.textContent = 'Dark';
  }
}

function setOptionsPanel(open){
  document.body.classList.toggle('controls-collapsed', !open);
  const btn = document.getElementById('panelToggle');
  if(btn){
    btn.classList.toggle('active', open);
    btn.title = open ? 'Hide options panel' : 'Show options panel';
  }
}
function toggleOptionsPanel(){
  setOptionsPanel(document.body.classList.contains('controls-collapsed'));
}
function initOptionsPanel(){
  const mq = window.matchMedia('(max-width: 768px)');
  setOptionsPanel(!mq.matches);
  mq.addEventListener?.('change', e => setOptionsPanel(!e.matches));
}

function initTheme(){
  const saved = localStorage.getItem('ae_viz_theme') || 'dark';
  document.documentElement.setAttribute('data-theme', saved);
  updateThemeUI(saved);
}

/* ══════════════════════════════════════════
   CONSTANTS & STATE
══════════════════════════════════════════ */
const RW = 320, RH = 220, RD = 260;
const $e = id => document.getElementById(id);
const roomEl    = $e('room');
const sceneWrap = $e('sceneWrap');
const viewerEl  = $e('viewer');

let selImg = null, selImgB64 = null;
let activeSurf = 'back', tool = 'orbit';
let rotX = -20, rotY = 25, zoom = 1.0;
let surfs = {}, cellStore = {}, spanStore = {};
let painting = false;
let subTiles = [], subTileIdx = -1;
let customerNameStr = '';
const adjOffsets = {
  back:  {tx:0,ty:0,tz:0,rx:0,ry:0},
  left:  {tx:0,ty:0,tz:0,rx:0,ry:0},
  right: {tx:260,ty:0,tz:0,rx:0,ry:0},
  floor: {tx:0,ty:0,tz:0,rx:0,ry:0},
};
let adjSurfName = 'back', adjLocked = false;
const b64Cache = {};
let isCapturingSnip = false;

/* ══════════════════════════════════════════
   UNDO / REDO
══════════════════════════════════════════ */
const MAX_UNDO = 60;
let undoStack = [], redoStack = [];
function pushUndo(cmd){ undoStack.push(cmd); if(undoStack.length>MAX_UNDO) undoStack.shift(); redoStack=[]; updateUndoUI(); }
function updateUndoUI(){ $e('undoBtn').disabled=!undoStack.length; $e('redoBtn').disabled=!redoStack.length; $e('undoCount').textContent=undoStack.length>0?undoStack.length+(undoStack.length===MAX_UNDO?' (max)':''):''; }
function undoAction(){ const cmd=undoStack.pop(); if(!cmd)return; applyCmd(cmd,'undo'); redoStack.push(cmd); updateUndoUI(); }
function redoAction(){ const cmd=redoStack.pop(); if(!cmd)return; applyCmd(cmd,'redo'); undoStack.push(cmd); updateUndoUI(); }
function applyCmd(cmd,dir){ if(cmd.type==='cell'){ const val=dir==='undo'?cmd.prev:cmd.next; const cell=surfs[cmd.surf]?.querySelector(`[data-r="${cmd.r}"][data-c="${cmd.c}"]`); if(!cell)return; if(val){cell.style.background='';cell.style.backgroundImage=val;cell.style.backgroundSize='cover';cell.style.backgroundPosition='center';if(!cellStore[cmd.surf])cellStore[cmd.surf]={};cellStore[cmd.surf][cmd.r+'_'+cmd.c]=val;}else{cell.style.backgroundImage='';cell.style.background='var(--cell-bg)';if(cellStore[cmd.surf])delete cellStore[cmd.surf][cmd.r+'_'+cmd.c];}}}

/* ══════════════════════════════════════════
   KEYBOARD SHORTCUTS
══════════════════════════════════════════ */
document.addEventListener('keydown', e => {
  if(e.target.tagName==='INPUT'||e.target.tagName==='TEXTAREA') return;
  const key = e.key.toUpperCase();
  if((e.ctrlKey||e.metaKey)&&e.key==='z'&&!e.shiftKey){e.preventDefault();undoAction();return;}
  if((e.ctrlKey||e.metaKey)&&(e.key==='y'||(e.key==='z'&&e.shiftKey))){e.preventDefault();redoAction();return;}
  if(e.key==='Escape'){ if(isCapturingSnip){cancelCapture();return;} cancelRowWise();return; }
  if(e.ctrlKey||e.metaKey||e.altKey) return;
  switch(key){
    case 'O': setTool('orbit'); break;
    case 'D': setTool('draw');  break;
    case 'E': setTool('erase'); break;
    case 'P': startCapture();   break;
    case '1': sel('back');  break;
    case '2': sel('left');  break;
    case '3': sel('right'); break;
    case '4': sel('floor'); break;
    case 'F': fillSurf();     break;
    case 'C': clearSurf();    break;
    case 'R': showRowWise();  break;
    case '?': toggleKbHint(); break;
    case 'Z': if(!e.ctrlKey&&!e.metaKey) undoAction(); break;
    case 'Y': if(!e.ctrlKey&&!e.metaKey) redoAction(); break;
  }
});

function toggleKbHint(){ $e('kbHint').classList.toggle('show'); }

/* ══════════════════════════════════════════
   UTILITIES
══════════════════════════════════════════ */
function gv(id){ return Math.max(1,parseInt($e(id).value)||1); }
function esc(s){ return s?String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'):''; }
function showToast(msg,type='ok',ms=3500){ const t=$e('toast'); t.textContent=msg; t.className=type==='ok'?'toast-ok':'toast-err'; t.style.display='block'; clearTimeout(t._tid); t._tid=setTimeout(()=>t.style.display='none',ms); }
function showConfirm(msg,icon='🗑'){ return new Promise(resolve=>{ $e('confMsg').textContent=msg; $e('confIcon').textContent=icon; $e('confirmModal').classList.add('show'); const yes=()=>{cleanup();resolve(true);}; const no=()=>{cleanup();resolve(false);}; function cleanup(){$e('confirmModal').classList.remove('show');$e('confConfirmBtn').removeEventListener('click',yes);$e('confCancelBtn').removeEventListener('click',no);} $e('confConfirmBtn').addEventListener('click',yes); $e('confCancelBtn').addEventListener('click',no); }); }
function setStatus(msg){ $e('bbStatus').textContent=msg; }

/* ══════════════════════════════════════════
   ROOM / 3D
══════════════════════════════════════════ */
function baseTx(name){ switch(name){case 'back':return 'translateZ(0px)';case 'left':return 'rotateY(90deg) scaleX(-1)';case 'right':return `translateX(${RW}px) rotateY(90deg) translateZ(${-RD}px) scaleX(-1)`;case 'floor':return `translateY(${RH}px) rotateX(90deg)`;} }
function composeTx(name){ const o=adjOffsets[name]; return `translate3d(${o.tx}px,${o.ty}px,${o.tz}px) rotateX(${o.rx}deg) rotateY(${o.ry}deg) ${baseTx(name)}`; }
function applyTx(name){ if(surfs[name]) surfs[name].style.transform=composeTx(name); }

function buildRoom(){
  const wR=gv('wR'),wC=gv('wC'),fR=gv('fR'),fC=gv('fC');
  cellStore={}; spanStore={}; undoStack=[]; redoStack=[]; updateUndoUI();
  roomEl.innerHTML=''; surfs={};
  roomEl.style.cssText=`position:relative;width:${RW}px;height:${RH}px;transform-style:preserve-3d;`;
  function addSurf(name,rows,cols,w,h){ const el=document.createElement('div'); el.className='surf'; el.id='surf-'+name; el.style.cssText=`position:absolute;top:0;left:0;width:${w}px;height:${h}px;transform-origin:top left;transform:${composeTx(name)};transform-style:preserve-3d;`; makeGrid(el,rows,cols,w,h,name); roomEl.appendChild(el); surfs[name]=el; }
  addSurf('back',wR,wC,RW,RH); addSurf('left',wR,wC,RD,RH); addSurf('right',wR,wC,RD,RH); addSurf('floor',fR,fC,RW,RD);
  ['back','left','right','floor'].forEach(n=>{ const cb=$e('v'+n[0].toUpperCase()+n.slice(1)); if(cb) setVis(n,cb.checked,false); });
  applySpacerCol(); highlightSurf(activeSurf); updateView(); updateAdjUI();
}

function makeGrid(el,rows,cols,W,H,sName){ el.innerHTML=''; const g=spacerPx(); el.style.display='grid'; el.style.gridTemplateRows=`repeat(${rows},1fr)`; el.style.gridTemplateColumns=`repeat(${cols},1fr)`; el.style.gap=g+'px'; el.style.background=g>0?'var(--grout-bg)':'var(--cell-bg)'; cellStore[sName]={}; spanStore[sName]=[]; for(let r=0;r<rows;r++) for(let c=0;c<cols;c++){ const d=document.createElement('div'); d.className='cell'; d.dataset.r=r; d.dataset.c=c; d.dataset.s=sName; d.style.cssText='background:var(--cell-bg);background-size:cover;background-position:center;cursor:inherit;position:relative;'; el.appendChild(d); } }

function selectAdjSurf(name,btn){ adjSurfName=name; document.querySelectorAll('.adj-mini-tab').forEach(b=>b.classList.remove('active')); if(btn) btn.classList.add('active'); updateAdjUI(); }
function nudge(ax,d){ if(adjLocked)return; adjOffsets[adjSurfName][ax]=(adjOffsets[adjSurfName][ax]||0)+d; applyTx(adjSurfName); updateAdjUI(); }
function updateAdjUI(){ const o=adjOffsets[adjSurfName]; $e('av-tx').textContent=o.tx+'px'; $e('av-ty').textContent=o.ty+'px'; $e('av-tz').textContent=o.tz+'px'; $e('av-rx').textContent=o.rx+'°'; $e('av-ry').textContent=o.ry+'°'; ['tx','ty','tz','rx','ry'].forEach(k=>{const bm=$e('adj-'+k+'-m'),bp=$e('adj-'+k+'-p'); if(bm) bm.disabled=adjLocked; if(bp) bp.disabled=adjLocked;}); $e('resetSurfBtn').disabled=adjLocked; $e('resetAllBtn').disabled=adjLocked; }
function resetSurf(){ if(adjLocked)return; adjOffsets[adjSurfName]={tx:0,ty:0,tz:0,rx:0,ry:0}; applyTx(adjSurfName); updateAdjUI(); }
function resetAllSurfs(){ if(adjLocked)return; ['back','left','right','floor'].forEach(n=>{adjOffsets[n]={tx:0,ty:0,tz:0,rx:0,ry:0}; applyTx(n);}); updateAdjUI(); }
function toggleLock(){ adjLocked=!adjLocked; $e('lockBtn').textContent=adjLocked?'🔒 Locked':'🔓 Unlock'; $e('lockBtn').className='lock-btn-mini '+(adjLocked?'locked':'unlocked'); $e('lockLabel').textContent=adjLocked?'Locked':'Unlocked'; updateAdjUI(); }

function updateView(){ roomEl.style.transform=`rotateX(${rotX}deg) rotateY(${rotY}deg)`; sceneWrap.style.transform=`scale(${zoom})`; }
$e('zoomR').addEventListener('input',()=>{ zoom=parseInt($e('zoomR').value)/100; $e('zoomV').textContent=Math.round(zoom*100)+'%'; updateView(); });
$e('rotYR').addEventListener('input',()=>{ rotY=parseInt($e('rotYR').value); $e('rotYV').textContent=rotY+'°'; updateView(); });
$e('rotXR').addEventListener('input',()=>{ rotX=parseInt($e('rotXR').value); $e('rotXV').textContent=rotX+'°'; updateView(); });
viewerEl.addEventListener('wheel',e=>{ e.preventDefault(); zoom=Math.min(2,Math.max(.3,zoom-e.deltaY*.001)); $e('zoomR').value=Math.round(zoom*100); $e('zoomV').textContent=Math.round(zoom*100)+'%'; updateView(); },{passive:false});
function resetView(){ rotX=-20; rotY=25; zoom=1; $e('rotXR').value=rotX; $e('rotXV').textContent=rotX+'°'; $e('rotYR').value=rotY; $e('rotYV').textContent=rotY+'°'; $e('zoomR').value=100; $e('zoomV').textContent='100%'; updateView(); }

let drag=false,lx=0,ly=0;
viewerEl.addEventListener('mousedown',e=>{ if(tool!=='orbit')return; drag=true; lx=e.clientX; ly=e.clientY; });
document.addEventListener('mouseup',()=>{ drag=false; painting=false; });
document.addEventListener('mousemove',e=>{ if(drag&&tool==='orbit'){ rotY=Math.max(-60,Math.min(60,rotY+(e.clientX-lx)*.4)); rotX=Math.max(-60,Math.min(0,rotX+(e.clientY-ly)*.3)); $e('rotYR').value=Math.round(rotY); $e('rotYV').textContent=Math.round(rotY)+'°'; $e('rotXR').value=Math.round(rotX); $e('rotXV').textContent=Math.round(rotX)+'°'; lx=e.clientX; ly=e.clientY; updateView(); } });

function setTool(t){
  tool=t;
  ['orbit','draw','erase'].forEach(n=>{ const b=$e('t'+n[0].toUpperCase()+n.slice(1)); if(b) b.classList.toggle('active',n===t); });
  viewerEl.style.cursor=t==='draw'?'crosshair':t==='erase'?'cell':'grab';
  const msgs={orbit:'Orbit — drag to rotate',draw:'Draw — click/drag cells',erase:'Erase — click/drag to clear'};
  setStatus(msgs[t]);
}

document.addEventListener('pointerdown',e=>{ if((tool==='draw'||tool==='erase')&&e.target.classList.contains('cell')){ painting=true; actOnCell(e.target); } });
document.addEventListener('pointermove',e=>{ if(!painting)return; if(tool==='draw'||tool==='erase'){ const el=document.elementFromPoint(e.clientX,e.clientY); if(el&&el.classList.contains('cell')) actOnCell(el); } });
document.addEventListener('pointerup',()=>{ painting=false; });
function actOnCell(cell){ if(tool==='draw'){ if(!selImg){setStatus('Pick a tile first!');return;} applyImg(cell); }else if(tool==='erase') eraseCell(cell); }

function highlightSurf(n){ document.querySelectorAll('.surf').forEach(s=>s.style.boxShadow=''); if(surfs[n]) surfs[n].style.boxShadow='inset 0 0 0 3px var(--accent-orange)'; }

function sel(n){
  activeSurf=n;
  highlightSurf(n);
  ['back','left','right','floor'].forEach(s=>{
    const b=$e('ps-'+s); if(b) b.classList.toggle('active',s===n);
  });
  setStatus('Active: '+n+(tool==='orbit'?' (switch to Draw)':''));
}

function fillSurf(){ if(!selImg){setStatus('Pick a tile first!');return;} const el=surfs[activeSurf]; if(!el)return; el.querySelectorAll('.cell').forEach(c=>applyImg(c)); }
function clearSurf(){ const el=surfs[activeSurf]; if(!el)return; el.querySelectorAll('.cell').forEach(c=>eraseCell(c)); spanStore[activeSurf]=[]; }

/* ── ROW-WISE FILL ── */
function showRowWise(){
  const el=surfs[activeSurf]; if(!el) return;
  const rows=parseInt((el.style.gridTemplateRows.match(/\d+/)||[1])[0]);
  $e('rwSurfLabel').textContent=activeSurf;
  const btns=$e('rwBtns');
  btns.innerHTML='';
  for(let r=0;r<rows;r++){
    const b=document.createElement('button');
    b.className='rw-row-btn';
    b.textContent='Row '+(r+1);
    b.dataset.row=r;
    b.onclick=()=>fillRow(activeSurf,r);
    btns.appendChild(b);
  }
  $e('rowWiseBar').classList.add('show');
}
function cancelRowWise(){ $e('rowWiseBar').classList.remove('show'); }
function fillRow(sName,rowIdx){
  if(!selImg){setStatus('Pick a tile first!');return;}
  const el=surfs[sName]; if(!el)return;
  el.querySelectorAll(`.cell[data-r="${rowIdx}"]`).forEach(c=>applyImg(c));
  setStatus(`Row ${rowIdx+1} filled on ${sName}`);
}

function applyImg(cell){ const s=cell.dataset.s,r=parseInt(cell.dataset.r),c=parseInt(cell.dataset.c); const url=selImgB64||selImg; const prev=(cellStore[s]||{})[r+'_'+c]||null; cell.style.background=''; cell.style.backgroundImage=`url(${url})`; cell.style.backgroundSize='cover'; cell.style.backgroundPosition='center'; if(!cellStore[s]) cellStore[s]={}; const next=cell.style.backgroundImage; cellStore[s][r+'_'+c]=next; pushUndo({type:'cell',surf:s,r,c,prev,next}); }
function eraseCell(cell){ const s=cell.dataset.s,r=parseInt(cell.dataset.r),c=parseInt(cell.dataset.c); const prev=(cellStore[s]||{})[r+'_'+c]||null; cell.style.backgroundImage=''; cell.style.background='var(--cell-bg)'; if(cellStore[s]) delete cellStore[s][r+'_'+c]; pushUndo({type:'cell',surf:s,r,c,prev,next:null}); }

function spacerPx(){ return parseInt($e('spacer').value)||0; }
function applySpacerCol(){ const g=spacerPx(),col=g>0?'var(--grout-bg)':'var(--cell-bg)'; Object.values(surfs).forEach(s=>{s.style.gap=g+'px';s.style.background=col;}); }
$e('spacer').addEventListener('input',()=>{ $e('spacerV').textContent=$e('spacer').value+'px'; applySpacerCol(); });

function setVis(name,show,lbl=true){ const el=surfs[name]; if(!el)return; el.style.visibility=show?'visible':'hidden'; }
function toggleVis(name,checked){ setVis(name,checked); }

function toBase64(src){ return new Promise((resolve,reject)=>{ if(!src){resolve(null);return;} if(src.startsWith('data:')){resolve(src);return;} if(b64Cache[src]){resolve(b64Cache[src]);return;} const img=new Image(); img.crossOrigin='anonymous'; img.onload=()=>{ try{const c=document.createElement('canvas');c.width=img.naturalWidth;c.height=img.naturalHeight;c.getContext('2d').drawImage(img,0,0);const d=c.toDataURL('image/png');b64Cache[src]=d;resolve(d);}catch(e){reject(new Error('CORS error'));} }; img.onerror=()=>reject(new Error('Image load failed')); img.src=src; }); }
async function setSelImg(src){ selImg=src; selImgB64=null; try{selImgB64=await toBase64(src);}catch(e){showToast('⚠ '+e.message,'err',7000);} }

/* ══════════════════════════════════════════
   SNIPPING TOOL
══════════════════════════════════════════ */
let startX, startY, selection;

function enterCaptureMode(){
  isCapturingSnip = true;
  document.body.classList.add('clean-capture','snip-selecting');
  $e('kbHint').classList.remove('show');
  const overlay = $e('overlay');
  overlay.style.display = 'block';
}

function leaveCaptureMode(){
  isCapturingSnip = false;
  document.body.classList.remove('clean-capture','snip-selecting');
  const overlay = $e('overlay');
  overlay.style.display = 'none';
  overlay.innerHTML = '';
  overlay.onmousedown = overlay.onmousemove = overlay.onmouseup = null;
}

function cancelCapture(){
  leaveCaptureMode();
  showToast('Capture cancelled', 'err', 1800);
}

function startCapture() {
  if(isCapturingSnip) return;
  enterCaptureMode();
  const overlay = document.getElementById('overlay');

  overlay.onmousedown = function(e) {
    startX = e.clientX;
    startY = e.clientY;

    selection = document.createElement('div');
    selection.id = 'selection';
    overlay.appendChild(selection);

    overlay.onmousemove = function(e) {
      let width  = e.clientX - startX;
      let height = e.clientY - startY;
      selection.style.left   = (width  < 0 ? e.clientX   : startX) + 'px';
      selection.style.top    = (height < 0 ? e.clientY   : startY) + 'px';
      selection.style.width  = Math.abs(width)  + 'px';
      selection.style.height = Math.abs(height) + 'px';
    };

    overlay.onmouseup = function(e) {
      const x2 = e.clientX, y2 = e.clientY;
      const rx1 = Math.min(startX, x2), ry1 = Math.min(startY, y2);
      const rx2 = Math.max(startX, x2), ry2 = Math.max(startY, y2);
      if(Math.abs(rx2 - rx1) < 8 || Math.abs(ry2 - ry1) < 8){
        cancelCapture();
        return;
      }
      overlay.style.display = 'none';
      overlay.innerHTML = '';
      document.body.classList.remove('snip-selecting');
      const frozenRoomTx   = roomEl.style.transform;
      const frozenWrapTx   = sceneWrap.style.transform;
      requestAnimationFrame(() => {
        requestAnimationFrame(() => {
          setTimeout(() => captureArea(rx1, ry1, rx2, ry2, frozenRoomTx, frozenWrapTx), 90);
        });
      });
    };
  };
}

function captureArea(x1, y1, x2, y2, frozenRoomTx, frozenWrapTx) {
  const constraints = {
    video: {
      displaySurface: 'browser',
      cursor: 'never',
      frameRate: 1
    },
    audio: false,
    preferCurrentTab: true
  };

  navigator.mediaDevices.getDisplayMedia(constraints)
  .then(function(stream) {
    const video = document.createElement('video');
    video.srcObject   = stream;
    video.muted       = true;
    video.playsInline = true;
    video.style.cssText = 'position:fixed;opacity:0;width:1px;height:1px;top:-9999px;left:-9999px;pointer-events:none;';
    document.body.appendChild(video);

    video.onloadedmetadata = function() {
      roomEl.style.transform   = frozenRoomTx;
      sceneWrap.style.transform = frozenWrapTx;
      video.play();

      requestAnimationFrame(() => {
        setTimeout(() => {
          const vW = video.videoWidth;
          const vH = video.videoHeight;
          const cssW = window.innerWidth;
          const cssH = window.innerHeight;
          const scaleX = vW / cssW;
          const scaleY = vH / cssH;

          const fullCanvas = document.createElement('canvas');
          fullCanvas.width  = vW;
          fullCanvas.height = vH;
          fullCanvas.getContext('2d').drawImage(video, 0, 0);

          stream.getTracks().forEach(t => t.stop());
          document.body.removeChild(video);
          leaveCaptureMode();

          const cx = Math.round(x1 * scaleX);
          const cy = Math.round(y1 * scaleY);
          const cw = Math.max(1, Math.round((x2 - x1) * scaleX));
          const ch = Math.max(1, Math.round((y2 - y1) * scaleY));

          const crop = document.createElement('canvas');
          crop.width  = cw;
          crop.height = ch;
          crop.getContext('2d').drawImage(fullCanvas, cx, cy, cw, ch, 0, 0, cw, ch);

          let imgData = crop.toDataURL('image/png');
          let safeName = (customerNameStr || 'snapshot').replace(/[^a-zA-Z0-9]/g, '-').toLowerCase();
          let date     = new Date().toISOString().slice(0, 10);
          let link     = document.createElement('a');
          link.download = safeName + '_' + date + '.png';
          link.href     = imgData;
          link.click();

          let formData = new FormData();
          formData.append('image', imgData);
          fetch('', { method: 'POST', body: formData })
          .then(res => res.text())
          .then(file => {
            const result = document.getElementById('result');
            result.style.display = 'block';
            result.innerHTML = '✅ Saved: ' + file;
            setTimeout(() => result.style.display = 'none', 4000);
            showToast('✅ Snapshot saved!');
          })
          .catch(() => showToast('⚠ Download ok, server save failed', 'err'));

        }, 80);
      });
    };

    video.onerror = function() {
      stream.getTracks().forEach(t => t.stop());
      if(document.body.contains(video)) document.body.removeChild(video);
      leaveCaptureMode();
      showToast('❌ Video error during capture', 'err');
    };

    video.load();
  })
  .catch(function(err) {
    leaveCaptureMode();
    const result = document.getElementById('result');
    result.style.display = 'block';
    result.innerHTML = '❌ ' + (err.name === 'NotAllowedError'
      ? 'Screen share denied. Please allow and try again.'
      : 'Screen share failed: ' + err.message);
    setTimeout(() => result.style.display = 'none', 5000);
    showToast('❌ Snip failed: ' + err.name, 'err');
  });
}

/* ══════════════════════════════════════════
   SUBMISSION TILES
══════════════════════════════════════════ */
function initSubmissionTiles(){
  const params=new URLSearchParams(window.location.search);
  if(!params.has('from_sub'))return;
  let tiles=[],owner='';
  try{
    const raw=localStorage.getItem('ae_viz_tiles');
    if(raw){try{tiles=JSON.parse(raw);}catch(e){showToast('⚠ Tile data corrupted','err',6000);tiles=[];}}
    owner=localStorage.getItem('ae_viz_owner')||'';
    localStorage.removeItem('ae_viz_tiles');
    localStorage.removeItem('ae_viz_owner');
  }catch(e){showToast('⚠ Storage error: '+e.message,'err',6000);}
  if(!Array.isArray(tiles)||!tiles.length)return;
  subTiles=tiles;
  if(owner){customerNameStr=owner;$e('customerName').textContent=owner;$e('customerBanner').style.display='flex';}
  $e('subTilesSection').style.display='flex';
  $e('subTileCount').textContent=tiles.length;
  renderSubTileGrid(tiles);
  if(tiles.length) $e('fillAllSubBtn').style.display='block';
  selectSubTile(0);
  setStatus(tiles.length+' tile(s) loaded — click a tile, then paint');
}

function renderSubTileGrid(tiles){
  const grid=$e('subTileGrid');
  if(!tiles.length){grid.innerHTML='<span style="font-size:10px;color:#4c1d95;font-family:DM Mono,monospace;">No tiles.</span>';return;}
  let lastArea=null;
  grid.innerHTML='';
  tiles.forEach((t,i)=>{
    if(t.area!==lastArea){
      const tag=document.createElement('span');
      tag.className='area-tag-inline';
      tag.textContent='📍 '+t.area;
      grid.appendChild(tag);
      lastArea=t.area;
    }
    const wrap=document.createElement('div');
    wrap.className='sti';
    wrap.id='sti_'+i;
    wrap.onclick=()=>selectSubTile(i);
    wrap.title=t.name;
    wrap.innerHTML=`<img src="${esc(t.url)}" alt="" loading="lazy" crossorigin="anonymous"><div class="sti-name">${esc(t.name)}</div>`;
    grid.appendChild(wrap);
  });
}

function selectSubTile(idx){
  if(!subTiles[idx])return;
  subTileIdx=idx;
  document.querySelectorAll('.sti').forEach((el,i)=>el.classList.toggle('active',i===idx));
  document.querySelectorAll('#carousel img').forEach(i=>i.classList.remove('sel'));
  setStatus('Converting: '+subTiles[idx].name+'…');
  setSelImg(subTiles[idx].url).then(()=>{
    setStatus('Selected: '+subTiles[idx].name);
    if(tool==='orbit') setTool('draw');
  });
}

function fillAllSubTiles(){
  if(!selImg){setStatus('Pick a tile first!');return;}
  ['back','left','right','floor'].forEach(n=>{ const el=surfs[n]; if(!el)return; el.querySelectorAll('.cell').forEach(c=>applyImg(c)); });
  setStatus('All surfaces filled!');
}

/* ══════════════════════════════════════════
   LOGO / MANUAL TILES
══════════════════════════════════════════ */
function handleLogoClick(e){ if($e('logoImg').style.display==='block'){const src=$e('logoImg').src;selImg=src;selImgB64=b64Cache[src]||null;if(!selImgB64)setSelImg(src);document.querySelectorAll('#carousel img').forEach(i=>i.classList.remove('sel'));document.querySelectorAll('.sti').forEach(i=>i.classList.remove('active'));setStatus('Logo tile selected');if(tool==='orbit')setTool('draw');e.stopPropagation();}else $e('logoInput').click(); }
function loadLogo(e){ const file=e.target.files[0];if(!file)return;const url=URL.createObjectURL(file);$e('logoImg').src=url;$e('logoImg').style.display='block';$e('logoPlaceholder').style.display='none';setSelImg(url).then(()=>{setStatus('Logo: '+file.name.slice(0,20));if(tool==='orbit')setTool('draw');}); }
function loadImgs(e){ [...e.target.files].forEach(file=>{const blobUrl=URL.createObjectURL(file);const img=document.createElement('img');img.src=blobUrl;img.title=file.name;img.onload=()=>toBase64(blobUrl).then(b64=>{img.dataset.b64=b64;}).catch(()=>{});img.onclick=()=>{selImg=blobUrl;selImgB64=img.dataset.b64||null;document.querySelectorAll('#carousel img').forEach(i=>i.classList.remove('sel'));document.querySelectorAll('.sti').forEach(i=>i.classList.remove('active'));img.classList.add('sel');setStatus('Tile: '+file.name.slice(0,22));if(!selImgB64)toBase64(blobUrl).then(b64=>{selImgB64=b64;img.dataset.b64=b64;}).catch(()=>{});if(tool==='orbit')setTool('draw');};$e('carousel').appendChild(img);}); }

/* ══════════════════════════════════════════
   INIT
══════════════════════════════════════════ */
initTheme();
initOptionsPanel();
buildRoom();
initSubmissionTiles();
sel('back');
</script>
<?php
$sessionTimeoutRole = $_SESSION['role'] ?? '';
require __DIR__ . '/../../includes/session_timeout_assets.php';
?>
</body>
</html>
