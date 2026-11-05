<?php
/**
 * config/security.php
 * Central security configuration: session hardening, headers, CSP.
 * Include this BEFORE session_start() and before any output.
 */

declare(strict_types=1);

// ---- Environment ----
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || (($_SERVER['SERVER_PORT'] ?? '') == 443);

// ---- Session cookie parameters (set before session_start) ----
session_set_cookie_params([
    'lifetime' => 0,                 // session cookie (expires on browser close)
    'path'     => '/',
    'domain'   => '',                // current host
    'secure'   => $isHttps,          // HTTPS only in prod
    'httponly' => true,              // no JS access
    'samesite' => 'Lax',             // CSRF mitigation
]);

// ---- Session hardening (ini) ----
ini_set('session.use_strict_mode', '1');   // reject uninitialized session IDs
ini_set('session.use_only_cookies', '1');   // no URL-based sessions
ini_set('session.use_trans_sid', '0');
ini_set('session.cookie_httponly', '1');
ini_set('session.gc_maxlifetime', (string)(30 * 60)); // 30 min idle
ini_set('session.hash_function', 'sha256'); // harmless on modern PHP

// ---- Error handling: log, don't display ----
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__DIR__) . '/logs/php_error.log');
error_reporting(E_ALL);

// ---- Security headers ----
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header('X-XSS-Protection: 1; mode=block');

    // Content Security Policy (tune as assets are added)
    $csp = "default-src 'self'; "
         . "style-src 'self' 'unsafe-inline'; "
         . "script-src 'self' 'unsafe-inline'; "
         . "img-src 'self' data:; "
         . "font-src 'self'; "
         . "frame-ancestors 'self'; "
         . "base-uri 'self'; "
         . "form-action 'self';";
    header('Content-Security-Policy: ' . $csp);

    if ($isHttps) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/**
 * Start a hardened session.
 */
function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        if (headers_sent()) {
            error_log('Session start skipped because headers were already sent.');
            return;
        }
        session_start();
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    // Absolute + idle timeout enforcement
    $now = time();
    $timeout = 30 * 60;
    if (isset($_SESSION['_last_activity']) && ($now - $_SESSION['_last_activity']) > $timeout) {
        // idle timeout
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        if (!headers_sent()) {
            session_start();
        }
        return;
    }
    $_SESSION['_last_activity'] = $now;

    // Regenerate ID periodically (every 5 min) to limit fixation window
    if (!isset($_SESSION['_created_at'])) {
        $_SESSION['_created_at'] = $now;
    } elseif ($now - $_SESSION['_created_at'] > 5 * 60) {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['_created_at'] = $now;
    }
}

/**
 * Destroy session completely (secure logout).
 */
function destroy_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
