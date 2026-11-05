<?php
/**
 * includes/auth.php
 * Authentication, session, RBAC, MFA helpers.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/csrf.php';

function current_user(): ?array
{
    start_secure_session();
    if (empty($_SESSION['user_id'])) return null;
    global $mysqli;
    return db_fetch_one(
        $mysqli,
        "SELECT u.id, u.role_id, u.full_name, u.email, u.status, u.mfa_enabled,
                r.name AS role_name
         FROM users u JOIN roles r ON r.id = u.role_id
         WHERE u.id = ?",
        "i",
        [$_SESSION['user_id']]
    );
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function require_login(string $redirect = '/VOTING_SYSTEM/login.php'): void
{
    if (!is_logged_in()) {
        set_flash('warning', 'Please log in to continue.');
        header('Location: ' . $redirect);
        exit;
    }
}

function require_role(array $roles, string $redirect = '/VOTING_SYSTEM/login.php'): void
{
    require_login($redirect);
    $u = current_user();
    if (!$u || !in_array($u['role_name'], $roles, true)) {
        http_response_code(403);
        exit('Access denied: insufficient permissions.');
    }
    if ($u['status'] !== 'active') {
        destroy_session();
        set_flash('danger', 'Your account is not active.');
        header('Location: ' . $redirect);
        exit;
    }
    process_due_elections();
}

function require_election_scope(int $electionId): void
{
    $u = current_user();
    if (!$u) { require_login(); return; }
    if ($u['role_name'] === 'admin') return;
    if ($u['role_name'] !== 'manager') {
        http_response_code(403); exit('Access denied.');
    }
    global $mysqli;
    $row = db_fetch_one(
        $mysqli,
        "SELECT id FROM election_managers WHERE user_id = ? AND election_id = ?",
        "ii",
        [$u['id'], $electionId]
    );
    if (!$row) {
        http_response_code(403); exit('You are not assigned to this election.');
    }
}

function login_user(string $email, string $password): array
{
    global $mysqli;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if (too_many_attempts($email, $ip)) {
        record_login_attempt($email, false);
        return ['ok' => false, 'error' => 'Too many failed attempts. Try again later.', 'mfa_required' => false];
    }

    $user = db_fetch_one(
        $mysqli,
        "SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.email = ?",
        "s",
        [$email]
    );

    if (!$user || !password_verify($password, $user['password_hash'])) {
        record_login_attempt($email, false);
        if ($user) {
            db_execute($mysqli, "UPDATE users SET failed_login_count = failed_login_count + 1 WHERE id = ?", "i", [$user['id']]);
            $fc = (int)$user['failed_login_count'] + 1;
            if ($fc >= 10) {
                db_execute($mysqli, "UPDATE users SET status='locked', locked_until = DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE id = ?", "i", [$user['id']]);
            }
        }
        return ['ok' => false, 'error' => 'Invalid email or password.', 'mfa_required' => false];
    }

    if ($user['status'] === 'locked') {
        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            record_login_attempt($email, false);
            return ['ok' => false, 'error' => 'Account locked. Try again later.', 'mfa_required' => false];
        }
        db_execute($mysqli, "UPDATE users SET status='active', locked_until=NULL, failed_login_count=0 WHERE id=?", "i", [$user['id']]);
    }
    if ($user['status'] !== 'active') {
        record_login_attempt($email, false);
        return ['ok' => false, 'error' => 'Account is not active. Contact administrator.', 'mfa_required' => false];
    }

    if (in_array($user['role_name'], ['admin', 'manager'], true) && (int)$user['mfa_enabled'] === 1) {
        start_secure_session();
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['pending_mfa_user_id'] = (int)$user['id'];
        }
        record_login_attempt($email, true);
        return ['ok' => true, 'error' => '', 'mfa_required' => true];
    }

    start_secure_session();
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['_created_at'] = time();
        $_SESSION['_last_activity'] = time();
    }

    db_execute($mysqli, "UPDATE users SET failed_login_count=0, last_login_at=NOW(), last_login_ip=? WHERE id=?",
        "si", [$ip, $user['id']]);
    record_login_attempt($email, true);
    audit_log((int)$user['id'], 'login', 'user', (int)$user['id'], 'Successful login');

    return ['ok' => true, 'error' => '', 'mfa_required' => false];
}
function complete_mfa_login(int $userId): bool
{
    global $mysqli;
    start_secure_session();
    if (empty($_SESSION['pending_mfa_user_id']) || (int)$_SESSION['pending_mfa_user_id'] !== $userId) {
        return false;
    }
    $user = db_fetch_one($mysqli, "SELECT id, status FROM users WHERE id = ?", "i", [$userId]);
    if (!$user || $user['status'] !== 'active') return false;

    unset($_SESSION['pending_mfa_user_id']);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['user_id'] = $userId;
    $_SESSION['_created_at'] = time();
    $_SESSION['_last_activity'] = time();

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    db_execute($mysqli, "UPDATE users SET last_login_at=NOW(), last_login_ip=? WHERE id=?", "si", [$ip, $userId]);
    audit_log($userId, 'login_mfa', 'user', $userId, 'MFA login completed');
    return true;
}

function logout_user(): void
{
    $u = current_user();
    if ($u) audit_log((int)$u['id'], 'logout', 'user', (int)$u['id'], 'Logout');
    destroy_session();
}

function register_voter_account(string $fullName, string $email, string $password): array
{
    global $mysqli;
    $fullName = clean_string($fullName, 120);
    $email = trim($email);
    if ($fullName === '' || !is_valid_email($email)) {
        return ['ok' => false, 'error' => 'Invalid name or email.'];
    }
    if (!is_strong_password($password)) {
        return ['ok' => false, 'error' => 'Password must be 10+ chars with upper, lower, digit and symbol.'];
    }
    $exists = db_fetch_one($mysqli, "SELECT id FROM users WHERE email = ?", "s", [$email]);
    if ($exists) {
        return ['ok' => false, 'error' => 'Email already registered.'];
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $roleId = db_fetch_one($mysqli, "SELECT id FROM roles WHERE name='voter'", "", [])['id'] ?? null;
    if (!$roleId) return ['ok' => false, 'error' => 'Role configuration error.'];

    $mysqli->begin_transaction();
    try {
        db_execute($mysqli,
            "INSERT INTO users (role_id, full_name, email, password_hash, status) VALUES (?, ?, ?, ?, 'pending')",
            "isss", [$roleId, $fullName, $email, $hash]);
        $userId = $mysqli->insert_id;
        $cardNo = 'V' . date('Y') . str_pad((string)$userId, 6, '0', STR_PAD_LEFT);
        db_execute($mysqli,
            "INSERT INTO voters (user_id, voter_card_no, is_verified) VALUES (?, ?, 0)",
            "is", [$userId, $cardNo]);
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        db_execute($mysqli,
            "INSERT INTO email_verifications (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))",
            "is", [$userId, $tokenHash]);
        $mysqli->commit();
    } catch (Throwable $t) {
        $mysqli->rollback();
        error_log('register_voter_account failed: ' . $t->getMessage());
        return ['ok' => false, 'error' => 'Registration failed. Please try again.'];
    }

    audit_log($userId, 'register', 'user', $userId, 'Voter self-registration');
    return ['ok' => true, 'error' => '', 'verify_token' => $rawToken, 'user_id' => $userId];
}
