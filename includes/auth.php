<?php
/**
 * Session & Authentication Check
 * Uses environment variables for configuration
 */

// Load environment and start session
require_once __DIR__ . '/../includes/env.php';

// Session configuration from .env
$sessionName = env('SESSION_NAME', 'beulah_session');
$sessionLifetime = env('SESSION_LIFETIME', 1800);

session_name($sessionName);
session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/functions.php';

if (!function_exists('is_ajax_request')) {
    function is_ajax_request(): bool
    {
        if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            return true;
        }
        if (!empty($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
            return true;
        }
        return false;
    }
}

if (!function_exists('json_exit')) {
    function json_exit(array $payload, int $status = 401): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($payload);
        exit();
    }
}

if (!isset($_SESSION['user_id'])) {
    if (is_ajax_request()) {
        json_exit(['ok' => false, 'error' => 'Authentication required. Please log in again.'], 401);
    }
    header("Location: ../login.php");
    exit();
}

// Inactivity timeout from .env
$timeout = (int) $sessionLifetime;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
    log_audit($pdo, $_SESSION['user_id'], 'session_timeout', 'Session expired due to inactivity');
    session_destroy();
    if (is_ajax_request()) {
        json_exit(['ok' => false, 'error' => 'Session expired due to inactivity. Please log in again.'], 401);
    }
    header("Location: ../login.php?msg=timeout");
    exit();
}

$_SESSION['last_activity'] = time();

// Regenerate session ID periodically for security (every 5 minutes)
if (!isset($_SESSION['last_regen']) || (time() - $_SESSION['last_regen'] > 300)) {
    session_regenerate_id(true);
    $_SESSION['last_regen'] = time();
}
?>
