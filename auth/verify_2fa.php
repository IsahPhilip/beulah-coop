<?php
// auth/verify_2fa.php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['temp_user']) || !isset($_SESSION['2fa_code'])) {
    header("Location: ../login.php");
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $entered_code = trim($_POST['code']);

    if (time() > $_SESSION['2fa_expiry']) {
        $error = "Code has expired. Please login again.";
        unset($_SESSION['temp_user'], $_SESSION['2fa_code']);
    } elseif ($entered_code == $_SESSION['2fa_code']) {
        // Successful 2FA
        $user = $_SESSION['temp_user'];

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['coop_no'] = $user['coop_no'];
        $_SESSION['name'] = $user['name'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['last_activity'] = time();

        log_audit($pdo, $user['id'], 'login_success', 'Successful 2FA login');

        unset($_SESSION['temp_user'], $_SESSION['2fa_code']);

        // Redirect based on role
        if ($user['role'] === 'admin') {
            header("Location: ../admin/index.php");
        } else {
            header("Location: ../member/dashboard.php");
        }
        exit();
    } else {
        $error = "Invalid verification code.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>2FA Verification - Beulah Coop</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/custom.css" rel="stylesheet">
</head>
<body class="auth-body">
<div class="auth-shell">
    <div class="auth-card">
        <div class="auth-left">
            <div class="auth-brand">Beulah Coop</div>
            <div class="auth-tagline">Secure Two-Factor Check</div>
            <div class="auth-badge">Verification Required</div>
            <div class="auth-ornament"></div>
        </div>
        <div class="auth-right">
            <div class="auth-title">Two-Factor Authentication</div>
            <div class="auth-subtitle">Enter the 6-digit code sent to your email.</div>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="mb-3">
                    <input type="text" name="code" maxlength="6" class="form-control text-center fs-4" required autofocus>
                </div>
                <button type="submit" class="btn btn-primary w-100">Verify Code</button>
                <div class="auth-footer">If you did not receive a code, contact admin.</div>
            </form>
        </div>
    </div>
</div>
</body>
</html>
