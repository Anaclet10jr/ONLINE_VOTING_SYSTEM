<?php
/**
 * includes/functions.php
 * Reusable helpers: validation, sanitization, output encoding, uploads, logging, pagination.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';

// ---------- Output encoding (XSS prevention) ----------
function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---------- Validation ----------
function is_valid_email(string $email): bool
{
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

function is_strong_password(string $pw): bool
{
    // min 10 chars, upper, lower, digit, special
    if (strlen($pw) < 10) return false;
    if (!preg_match('/[A-Z]/', $pw)) return false;
    if (!preg_match('/[a-z]/', $pw)) return false;
    if (!preg_match('/[0-9]/', $pw)) return false;
    if (!preg_match('/[^A-Za-z0-9]/', $pw)) return false;
    return true;
}

function clean_string(string $v, int $max = 255): string
{
    $v = trim($v);
    if (strlen($v) > $max) $v = substr($v, 0, $max);
    return $v;
}

// ---------- Flash messages ----------
function set_flash(string $type, string $msg): void
{
    start_secure_session();
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function get_flash(): ?array
{
    start_secure_session();
    if (isset($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

// ---------- Audit logging ----------
function audit_log(?int $userId, string $action, ?string $entityType = null, ?int $entityId = null, ?string $details = null): void
{
    global $mysqli;
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    try {
        db_execute(
            $mysqli,
            "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            "issssss",
            [$userId, $action, $entityType, $entityId, $details, $ip, $ua]
        );
    } catch (Throwable $t) {
        error_log('audit_log failed: ' . $t->getMessage());
    }
}

function process_due_elections(): void
{
    global $mysqli;

    $autoTransition = db_fetch_one(
        $mysqli,
        "SELECT value FROM system_settings WHERE `key`='election_auto_transition'",
        "",
        []
    );
    if ($autoTransition && $autoTransition['value'] !== '1') {
        return;
    }

    $expiredScheduled = db_fetch_all(
        $mysqli,
        "SELECT id, title FROM elections WHERE status='scheduled' AND end_at<=NOW()"
    );
    foreach ($expiredScheduled as $election) {
        $updated = db_execute(
            $mysqli,
            "UPDATE elections SET status='closed'
             WHERE id=? AND status='scheduled' AND end_at<=NOW()",
            "i",
            [(int)$election['id']]
        );
        if ($updated === 1) {
            audit_log(null, 'election_auto_closed', 'election', (int)$election['id'], 'Election period ended before it was activated.');
        }
    }

    $expiredActive = db_fetch_all(
        $mysqli,
        "SELECT id, title FROM elections WHERE status='active' AND end_at<=NOW()"
    );
    foreach ($expiredActive as $election) {
        $updated = db_execute(
            $mysqli,
            "UPDATE elections SET status='closed'
             WHERE id=? AND status='active' AND end_at<=NOW()",
            "i",
            [(int)$election['id']]
        );
        if ($updated === 1) {
            audit_log(null, 'election_auto_closed', 'election', (int)$election['id'], 'Election voting period ended.');
        }
    }

    $dueElections = db_fetch_all(
        $mysqli,
        "SELECT e.id, e.title, e.eligibility_mode
         FROM elections e
         WHERE e.status='scheduled' AND e.start_at<=NOW() AND e.end_at>NOW()
           AND NOT EXISTS (
               SELECT 1 FROM election_candidates ec
               WHERE ec.election_id=e.id AND ec.status='pending'
           )
           AND EXISTS (
               SELECT 1 FROM election_candidates ec
               WHERE ec.election_id=e.id AND ec.status='approved'
           )
           AND (
               e.eligibility_mode='all_voters'
               OR EXISTS (
                   SELECT 1 FROM voter_elections ve
                   WHERE ve.election_id=e.id AND ve.is_eligible=1
               )
           )
         ORDER BY e.id"
    );

    foreach ($dueElections as $election) {
        $mysqli->begin_transaction();
        try {
            if ($election['eligibility_mode'] === 'all_voters') {
                db_execute(
                    $mysqli,
                    "INSERT INTO voter_elections (voter_id, election_id, is_eligible)
                     SELECT id, ?, 1 FROM voters
                     ON DUPLICATE KEY UPDATE is_eligible=1",
                    "i",
                    [(int)$election['id']]
                );
            }

            $updated = db_execute(
                $mysqli,
                "UPDATE elections SET status='active'
                 WHERE id=? AND status='scheduled' AND start_at<=NOW() AND end_at>NOW()",
                "i",
                [(int)$election['id']]
            );
            $mysqli->commit();
        } catch (Throwable $t) {
            $mysqli->rollback();
            error_log('Automatic election transition failed: ' . $t->getMessage());
            throw $t;
        }

        if ($updated === 1) {
            audit_log(null, 'election_auto_started', 'election', (int)$election['id'], 'Election reached its scheduled start time.');
        }
    }
}

// ---------- Login rate limiting ----------
function record_login_attempt(string $email, bool $success): void
{
    global $mysqli;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    try {
        db_execute(
            $mysqli,
            "INSERT INTO login_attempts (email, ip_address, success, user_agent) VALUES (?, ?, ?, ?)",
            "ssis",
            [$email, $ip, $success ? 1 : 0, $ua]
        );
    } catch (Throwable $t) {
        error_log('record_login_attempt failed: ' . $t->getMessage());
    }
}

function too_many_attempts(string $email, string $ip, int $max = 5, int $windowMinutes = 15): bool
{
    global $mysqli;
    $since = date('Y-m-d H:i:s', time() - ($windowMinutes * 60));
    $row = db_fetch_one(
        $mysqli,
        "SELECT COUNT(*) AS c FROM login_attempts
         WHERE (email = ? OR ip_address = ?) AND success = 0 AND created_at >= ?",
        "sss",
        [$email, $ip, $since]
    );
    return ((int)($row['c'] ?? 0)) >= $max;
}

// ---------- File upload security ----------
function secure_upload(array $file, string $destDir, array $allowedExt = ['jpg','jpeg','png','gif','pdf'], int $maxBytes = 2097152): array
{
    // Returns ['ok'=>bool, 'path'=>string|null, 'error'=>string]
    if (!isset($file['error']) || is_array($file['error'])) {
        return ['ok' => false, 'path' => null, 'error' => 'Invalid upload.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'path' => null, 'error' => 'Upload error code ' . $file['error']];
    }
    if ($file['size'] <= 0 || $file['size'] > $maxBytes) {
        return ['ok' => false, 'path' => null, 'error' => 'File size invalid or too large.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowedMimes = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'pdf' => 'application/pdf',
    ];
    $allowedMimes = array_intersect_key($allowedMimes, array_flip($allowedExt));

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        return ['ok' => false, 'path' => null, 'error' => 'Extension not allowed.'];
    }
    if (!in_array($mime, $allowedMimes, true)) {
        return ['ok' => false, 'path' => null, 'error' => 'MIME type not allowed.'];
    }

    // Verify actual image content (for images)
    if (in_array($ext, ['jpg','jpeg','png','gif'], true)) {
        if (getimagesize($file['tmp_name']) === false) {
            return ['ok' => false, 'path' => null, 'error' => 'File is not a valid image.'];
        }
    }

    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }
    $newName = bin2hex(random_bytes(16)) . '.' . $ext;
    $target = rtrim($destDir, '/') . '/' . $newName;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return ['ok' => false, 'path' => null, 'error' => 'Failed to store file.'];
    }
    chmod($target, 0644);
    return ['ok' => true, 'path' => $newName, 'error' => ''];
}

// ---------- Pagination helper ----------
function paginate(int $total, int $perPage, int $page): array
{
    $page = max(1, $page);
    $pages = (int) ceil($total / max(1, $perPage));
    if ($page > $pages && $pages > 0) $page = $pages;
    $offset = ($page - 1) * $perPage;
    return ['page' => $page, 'pages' => $pages, 'offset' => $offset, 'perPage' => $perPage];
}

// ---------- IP hashing (privacy) ----------
function hash_ip(?string $ip): ?string
{
    if ($ip === null) return null;
    return hash('sha256', $ip . ($_ENV['APP_KEY'] ?? 'sovms-salt'));
}
