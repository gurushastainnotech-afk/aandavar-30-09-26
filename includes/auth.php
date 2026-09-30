<?php
// ============================================================
// auth.php — Authentication & session helpers
// ============================================================
require_once __DIR__ . '/config.php';

if (!defined('ADMIN_IDLE_TIMEOUT')) {
    define('ADMIN_IDLE_TIMEOUT', 900); // 15 minutes - main_admin
}
if (!defined('OFFICE_ADMIN_IDLE_TIMEOUT')) {
    define('OFFICE_ADMIN_IDLE_TIMEOUT', 900); // 15 minutes - office_admin
}
if (!defined('SUBADMIN_IDLE_TIMEOUT')) {
    define('SUBADMIN_IDLE_TIMEOUT', 900); // 15 minutes - sub_admin
}

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/* ── Session activity helpers ─────────────────────────────────────────────── */
function _sessionTimeoutForRole(string $role): int {
    if ($role === 'sub_admin') return SUBADMIN_IDLE_TIMEOUT;
    if ($role === 'office_admin') return OFFICE_ADMIN_IDLE_TIMEOUT;
    return ADMIN_IDLE_TIMEOUT;
}

function _touchSessionActivity(PDO $db, string $token): void {
    if (!$token) return;
    try {
        $db->prepare("UPDATE user_sessions SET last_active=NOW() WHERE session_token=? AND is_active=1")
           ->execute([$token]);
    } catch (Throwable $e) {}
}

function _sessionLastActiveTimestamp(PDO $db, string $token): int {
    if (!$token) return 0;
    try {
        $stmt = $db->prepare("
            SELECT UNIX_TIMESTAMP(last_active)
            FROM user_sessions
            WHERE session_token = ? AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$token]);
        return max(0, (int)($stmt->fetchColumn() ?? 0));
    } catch (Throwable $e) {
        return 0;
    }
}

function _authoritativeLastActive(string $token, ?PDO $db = null): int {
    $sessionTs = max(0, (int)($_SESSION['last_active'] ?? 0));
    if (!$token) return $sessionTs;

    $db ??= getDB();
    $dbTs = _sessionLastActiveTimestamp($db, $token);
    $best = max($sessionTs, $dbTs);

    if ($best > $sessionTs) {
        $_SESSION['last_active'] = $best;
    }

    return $best;
}

function refreshCurrentSessionActivity(?PDO $db = null): int {
    $db ??= getDB();
    $now = time();

    $_SESSION['last_active'] = $now;
    _touchSessionActivity($db, $_SESSION['token'] ?? '');

    return $now;
}

function expireStaleSessionsForUser(int $userId, string $role, int $graceSeconds = 300): int {
    if (!$userId) return 0;

    $db      = getDB();
    $timeout = max(1, _sessionTimeoutForRole($role));
    $threshold = $timeout + max(0, $graceSeconds);

    $stmt = $db->prepare("
        SELECT us.*, u.username, u.role
        FROM user_sessions us
        JOIN users u ON us.user_id = u.id
        WHERE us.user_id = ?
          AND us.is_active = 1
          AND TIMESTAMPDIFF(SECOND, us.last_active, NOW()) >= ?
    ");
    $stmt->execute([$userId, $threshold]);
    $staleSessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$staleSessions) return 0;

    $tokens = array_column($staleSessions, 'session_token');
    $in     = implode(',', array_fill(0, count($tokens), '?'));
    $db->prepare("
        UPDATE user_sessions
        SET is_active=0, logged_out_at=NOW()
        WHERE session_token IN ($in)
    ")->execute($tokens);

    foreach ($staleSessions as $sess) {
        _writeSessionLogStatic($db, $sess, 'auto');
    }

    return count($staleSessions);
}

function activeSessionCountForUser(int $userId): int {
    if ($userId <= 0) return 0;
    $db = getDB();
    $stmt = $db->prepare("SELECT COUNT(*) FROM user_sessions WHERE user_id = ? AND is_active = 1");
    $stmt->execute([$userId]);
    return (int)($stmt->fetchColumn() ?? 0);
}

function expireStaleSessionsForAllUsers(string $excludeToken = ''): int {
    $db = getDB();

    $excludeSql = $excludeToken !== '' ? " AND us.session_token <> ?" : "";
    $stmt = $db->prepare("
        SELECT us.*, u.username, u.role
        FROM user_sessions us
        JOIN users u ON us.user_id = u.id
        WHERE us.is_active = 1
          $excludeSql
          AND TIMESTAMPDIFF(
                SECOND,
                us.last_active,
                NOW()
              ) >= CASE
                    WHEN u.role = 'sub_admin' THEN ?
                    WHEN u.role = 'office_admin' THEN ?
                    ELSE ?
                   END
    ");
    $params = [];
    if ($excludeToken !== '') $params[] = $excludeToken;
    // 300s grace period for slow networks (e.g. large form uploads)
    $params[] = max(1, SUBADMIN_IDLE_TIMEOUT) + 300;
    $params[] = max(1, OFFICE_ADMIN_IDLE_TIMEOUT) + 300;
    $params[] = max(1, ADMIN_IDLE_TIMEOUT) + 300;
    $stmt->execute($params);
    $staleSessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$staleSessions) return 0;

    $tokens = array_column($staleSessions, 'session_token');
    $in     = implode(',', array_fill(0, count($tokens), '?'));
    $db->prepare("
        UPDATE user_sessions
        SET is_active=0, logged_out_at=NOW()
        WHERE session_token IN ($in)
    ")->execute($tokens);

    foreach ($staleSessions as $sess) {
        _writeSessionLogStatic($db, $sess, 'auto');
    }

    return count($staleSessions);
}

/* ── Login ────────────────────────────────────────────────────────────────── */
function loginUser(string $username, string $password): array {
    $db   = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, $user['password'])) {
        return ['ok' => false, 'error' => 'Invalid username or password.'];
    }
    if (!empty($user['is_locked'])) {
        return ['ok' => false, 'error' => 'Account is locked. Contact main admin.'];
    }
    if (in_array($user['role'], ['sub_admin', 'office_admin'], true) && !empty($user['password_expires_at'])) {
        if (strtotime($user['password_expires_at']) < time()) {
            return ['ok' => false, 'error' => 'Password has expired. Contact main admin.'];
        }
    }

    // ── Mandatory 8:30 PM IST daily password reset ────────────────────────────
    // For sub_admin and office_admin: if current IST time is past 20:30
    // and password was last changed before today's 20:30 IST, block login.
    if (in_array($user['role'], ['sub_admin', 'office_admin'], true)) {
        $tz = new DateTimeZone('Asia/Kolkata');
        $nowIST = new DateTime('now', $tz);
        $resetTimeToday = new DateTime('today 20:30:00', $tz);

        if ($nowIST >= $resetTimeToday) {
            // It's past 8:30 PM today — check if password was changed after today's 8:30 PM
            $pwChangedAt = null;
            if (!empty($user['password_changed_at'])) {
                $pwChangedAt = new DateTime($user['password_changed_at'], $tz);
            } elseif (!empty($user['password_expires_at'])) {
                // Infer: password_expires_at is set +24h from last reset
                // If expires_at is before today's 20:30 + 24h, password was set before today's 20:30
                $expires = new DateTime($user['password_expires_at'], $tz);
                $expDay  = clone $resetTimeToday;
                $expDay->modify('+24 hours');
                if ($expires <= $expDay) {
                    // password was changed before today's 8:30 PM reset window
                    $pwChangedAt = null;
                } else {
                    $pwChangedAt = clone $resetTimeToday; // treat as already reset today
                }
            }

            if ($pwChangedAt === null || $pwChangedAt < $resetTimeToday) {
                return ['ok' => false, 'error' => 'Daily password reset required. Please contact the admin to reset your password (mandatory at 8:30 PM daily).'];
            }
        }
    }

    expireStaleSessionsForUser((int)$user['id'], (string)$user['role']);

    $maxSessions = ($user['role'] === 'main_admin')
                   ? MAX_MAIN_SESSIONS : MAX_SUB_SESSIONS;

    $activeSessionCount = activeSessionCountForUser((int)$user['id']);
    if ($activeSessionCount >= $maxSessions) {
        // Login should immediately reclaim sessions that have already crossed
        // the visible idle-timeout threshold instead of waiting for the
        // slower network grace window used by background cleanup.
        expireStaleSessionsForUser((int)$user['id'], (string)$user['role'], 0);
        $activeSessionCount = activeSessionCountForUser((int)$user['id']);
    }

    if ($activeSessionCount >= $maxSessions) {
        return ['ok' => false, 'error' => 'Already logged in on another device. Please logout there or ask admin to terminate the session.'];
    }

    $token  = bin2hex(random_bytes(32));
    $device = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 500);
    $ip     = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    $db->prepare(
        "INSERT INTO user_sessions (user_id, session_token, device_info, ip_address)
         VALUES (?,?,?,?)"
    )->execute([$user['id'], $token, $device, $ip]);

    session_regenerate_id(true);
    $_SESSION['user_id']     = $user['id'];
    $_SESSION['username']    = $user['username'];
    $_SESSION['role']        = $user['role'];
    $_SESSION['token']       = $token;
    $_SESSION['last_active'] = time();

    logActivity($user['id'], 'login', 'User logged in');
    return ['ok' => true, 'role' => $user['role']];
}

/* ── Shared session log writer ────────────────────────────────────────────── */
// logout_type: 'normal' | 'force' | 'force_all' | 'auto'
function _writeSessionLogStatic(PDO $db, array $sess, string $logoutType): void {
    $token   = $sess['session_token'] ?? '';
    $loginDt = $sess['logged_in_at']  ?? null;
    if (!$token || !$loginDt) return;

    // Ensure table + ENUM exist
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS session_log (
            id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id       INT          NOT NULL,
            username      VARCHAR(100) NOT NULL,
            role          VARCHAR(50)  NOT NULL,
            ip_address    VARCHAR(45)  DEFAULT NULL,
            device_info   TEXT         DEFAULT NULL,
            logged_in_at  DATETIME     NOT NULL,
            logged_out_at DATETIME     DEFAULT NULL,
            logout_type   ENUM('normal','force','force_all','auto') DEFAULT NULL,
            duration_secs INT UNSIGNED  DEFAULT NULL,
            session_token VARCHAR(255) DEFAULT NULL UNIQUE,
            device_login_time DATETIME DEFAULT NULL,
            INDEX idx_user  (user_id),
            INDEX idx_login (logged_in_at),
            INDEX idx_token (session_token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {}

    try {
        $db->exec("ALTER TABLE session_log MODIFY COLUMN logout_type
                   ENUM('normal','force','force_all','auto') DEFAULT NULL");
    } catch (Throwable $e) {}

    $durRow = $db->prepare("SELECT TIMESTAMPDIFF(SECOND, ?, NOW()) AS secs");
    $durRow->execute([$loginDt]);
    $durSecs = max(0, (int)($durRow->fetchColumn() ?? 0));

    $existing = $db->prepare("SELECT id FROM session_log WHERE session_token = ? LIMIT 1");
    $existing->execute([$token]);

    if ($existing->fetchColumn()) {
        $db->prepare("
            UPDATE session_log
            SET logged_out_at = NOW(),
                logout_type   = ?,
                duration_secs = ?
            WHERE session_token = ?
        ")->execute([$logoutType, $durSecs, $token]);
    } else {
        $db->prepare("
            INSERT INTO session_log
                (user_id, username, role, ip_address, device_info,
                 logged_in_at, logged_out_at, logout_type, duration_secs,
                 session_token, device_login_time)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?)
        ")->execute([
            $sess['user_id'],
            $sess['username']          ?? '',
            $sess['role']              ?? '',
            $sess['ip_address']        ?? '',
            $sess['device_info']       ?? '',
            $loginDt,
            $logoutType,
            $durSecs,
            $token,
            $sess['device_login_time'] ?? null,
        ]);
    }
}

/* ── Fetch full session row before it is deleted ─────────────────────────── */
function _fetchSessionRow(PDO $db, string $token): ?array {
    if (!$token) return null;
    $stmt = $db->prepare("
        SELECT us.*, u.username, u.role
        FROM user_sessions us
        JOIN users u ON us.user_id = u.id
        WHERE us.session_token = ? AND us.is_active = 1
    ");
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/* ── Logout ───────────────────────────────────────────────────────────────── */
function logoutUser(int $userId = 0, string $token = ''): void {
    $db  = getDB();
    $uid = $userId ?: ((int)($_SESSION['user_id'] ?? 0));
    $tok = $token  ?: ($_SESSION['token']         ?? '');

    if ($tok) {
        $sess = _fetchSessionRow($db, $tok);

        $db->prepare(
            "UPDATE user_sessions SET is_active=0, logged_out_at=NOW()
             WHERE session_token=?"
        )->execute([$tok]);

        if ($sess) {
            _writeSessionLogStatic($db, $sess, 'normal');
        }
    }
    if ($uid) logActivity($uid, 'logout', 'User logged out');

    if (!$userId) {
        _destroySession();
    }
}

/* ── Force-logout another user ───────────────────────────────────────────── */
function forceLogoutUser(int $userId): void {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT us.*, u.username, u.role
        FROM user_sessions us
        JOIN users u ON us.user_id = u.id
        WHERE us.user_id = ? AND us.is_active = 1
    ");
    $stmt->execute([$userId]);
    $activeSessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $db->prepare(
        "UPDATE user_sessions SET is_active=0, logged_out_at=NOW()
         WHERE user_id=? AND is_active=1"
    )->execute([$userId]);

    foreach ($activeSessions as $sess) {
        _writeSessionLogStatic($db, $sess, 'force');
    }

    logActivity($userId, 'force_logout', 'Force logout by admin');
}

/* ── Self-terminate (main admin) ─────────────────────────────────────────── */
function terminateSelf(): string {
    $db  = getDB();
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $tok = $_SESSION['token'] ?? '';

    if ($tok) {
        $sess = _fetchSessionRow($db, $tok);

        $db->prepare(
            "UPDATE user_sessions SET is_active=0, logged_out_at=NOW()
             WHERE session_token=?"
        )->execute([$tok]);

        if ($sess) {
            _writeSessionLogStatic($db, $sess, 'normal');
        }
    }
    if ($uid) logActivity($uid, 'self_terminate', 'Main admin self-terminated session');
    _destroySession();
    return APP_URL . '/index.php?reason=terminated';
}

function terminateAllSelf(): string {
    $db  = getDB();
    $uid = (int)($_SESSION['user_id'] ?? 0);

    if ($uid) {
        $stmt = $db->prepare("
            SELECT us.*, u.username, u.role
            FROM user_sessions us
            JOIN users u ON us.user_id = u.id
            WHERE us.user_id = ? AND us.is_active = 1
        ");
        $stmt->execute([$uid]);
        $activeSessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $db->prepare(
            "UPDATE user_sessions SET is_active=0, logged_out_at=NOW()
             WHERE user_id=? AND is_active=1"
        )->execute([$uid]);

        foreach ($activeSessions as $sess) {
            _writeSessionLogStatic($db, $sess, 'normal');
        }

        logActivity($uid, 'terminate_all_sessions', 'Main admin killed all own sessions');
    }
    _destroySession();
    return APP_URL . '/index.php?reason=terminated';
}

/* ── Internal: destroy PHP session cleanly ───────────────────────────────── */
function _destroySession(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(
            session_name(), '',
            time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']
        );
    }
    session_destroy();
}

/* ── Check session token in DB ───────────────────────────────────────────── */
function isLoggedIn(): bool {
    if (empty($_SESSION['token'])) return false;
    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT id FROM user_sessions WHERE session_token=? AND is_active=1"
    );
    $stmt->execute([$_SESSION['token']]);
    return (bool)$stmt->fetchColumn();
}

/* ── Idle timeout — ALL roles now time out ───────────────────────────────── */
// main_admin   → ADMIN_IDLE_TIMEOUT
// office_admin → OFFICE_ADMIN_IDLE_TIMEOUT
// sub_admin   → SUBADMIN_IDLE_TIMEOUT
// Returns false if session was expired and destroyed (caller must redirect).
function checkAdminIdleTimeout(): bool {
    $role = $_SESSION['role'] ?? '';
    $tok  = $_SESSION['token'] ?? '';

    $timeout = _sessionTimeoutForRole($role);
    $db = getDB();
    $lastActive = _authoritativeLastActive($tok, $db);
    if ($lastActive <= 0) $lastActive = time();
    $idle = time() - $lastActive;

    // 300s grace period for slow uploads preventing ping updates
    $grace = 300; 

    if ($idle >= ($timeout + $grace)) {
        $uid = (int)($_SESSION['user_id'] ?? 0);

        if ($tok) {
            // Fetch BEFORE marking inactive so we can write the log
            $sess = _fetchSessionRow($db, $tok);

            $db->prepare(
                "UPDATE user_sessions SET is_active=0, logged_out_at=NOW()
                 WHERE session_token=?"
            )->execute([$tok]);

            // Write session_log with logout_type = 'auto'
            if ($sess) {
                _writeSessionLogStatic($db, $sess, 'auto');
            }
        }

        if ($uid) logActivity($uid, 'timeout_logout', 'Session timed out (idle) — role: ' . $role);
        _destroySession();
        return false;
    }

    refreshCurrentSessionActivity($db);
    return true;
}

/* ── requireLogin — bounces to /index.php with correct reason ────────────── */
function requireLogin(string $requiredRole = ''): void {
    // 1. Not logged in at all → login page
    if (!isLoggedIn()) {
        header('Location: ' . APP_URL . '/index.php?reason=expired');
        exit;
    }

    // 2. Role mismatch → login page
    //    Exception: main_admin may open office_admin pages.
    if ($requiredRole !== '') {
        $actualRole = $_SESSION['role'] ?? '';
        $allowed = ($actualRole === $requiredRole)
                || ($requiredRole === 'office_admin' && $actualRole === 'main_admin');
        if (!$allowed) {
            header('Location: ' . APP_URL . '/index.php');
            exit;
        }
    }

    // 3. Idle timeout → login page with ?reason=timeout
    //    checkAdminIdleTimeout() destroys the session if expired and returns false.
    if (!checkAdminIdleTimeout()) {
        header('Location: ' . APP_URL . '/index.php?reason=timeout');
        exit;
    }
}

/* ── Current user ─────────────────────────────────────────────────────────── */
function currentUser(): array {
    if (empty($_SESSION['user_id'])) return [];
    $db   = getDB();
    $stmt = $db->prepare("SELECT id, username, role FROM users WHERE id=?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

/* ── Activity log ─────────────────────────────────────────────────────────── */
function logActivity(int $userId, string $action, string $desc = ''): void {
    try {
        $db = getDB();
        $db->prepare(
            "INSERT INTO activity_log
                 (user_id, action, description, ip_address, device_info)
             VALUES (?,?,?,?,?)"
        )->execute([
            $userId, $action, $desc,
            $_SERVER['REMOTE_ADDR']      ?? '',
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        ]);
    } catch (Exception $e) { /* silent fail */ }
}

/* ── CSRF ─────────────────────────────────────────────────────────────────── */
function csrfToken(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf'];
}

function verifyCsrf(): void {
    $posted = $_POST['csrf'] ?? '';
    $stored = $_SESSION['csrf'] ?? '';
    if (!$stored || !hash_equals($stored, $posted)) {
        http_response_code(403);
        die('Invalid CSRF token. Please go back and try again.');
    }
}

/* ── Seconds left before idle timeout (works for ALL roles now) ──────────── */
function adminIdleSecondsLeft(): int {
    $role = $_SESSION['role'] ?? '';
    if (!$role) return 0;
    $timeout = _sessionTimeoutForRole($role);
    $lastActive = _authoritativeLastActive($_SESSION['token'] ?? '');
    if ($lastActive <= 0) $lastActive = time();
    $elapsed = time() - $lastActive;
    return max(0, $timeout - $elapsed);
}

/* ── Schema helpers ──────────────────────────────────────────────────────── */
function columnExists(string $table, string $column): bool {
    try {
        $db   = getDB();
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = ?
               AND COLUMN_NAME  = ?"
        );
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function ensureDeviceTimeColumn(): void {
    if (!columnExists('submissions', 'device_submitted_at')) {
        try {
            getDB()->exec(
                "ALTER TABLE submissions
                 ADD COLUMN device_submitted_at DATETIME NULL AFTER submitted_at"
            );
        } catch (Exception $e) {}
    }
}
