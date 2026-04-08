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

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

// Inactivity timeout from .env
$timeout = (int) $sessionLifetime;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
    log_audit($pdo, $_SESSION['user_id'], 'session_timeout', 'Session expired due to inactivity');
    session_destroy();
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
