<?php
// includes/auth.php - Session & Timeout Check
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

// 30-minute inactivity timeout
$timeout = 1800; // 30 * 60
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
    log_audit($pdo, $_SESSION['user_id'], 'session_timeout', 'Session expired due to inactivity');
    session_destroy();
    header("Location: ../login.php?msg=timeout");
    exit();
}

$_SESSION['last_activity'] = time();

// Regenerate session ID periodically for security
if (!isset($_SESSION['last_regen']) || (time() - $_SESSION['last_regen'] > 300)) {
    session_regenerate_id(true);
    $_SESSION['last_regen'] = time();
}
?>