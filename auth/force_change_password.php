<?php
// auth/force_change_password.php
require_once '../includes/env.php';
$sessionName = env('SESSION_NAME', 'beulah_session');
session_name($sessionName);
session_start();

require_once '../config/db.php';
require_once '../includes/functions.php';

// If the user is not in the 'force_password_reset' flow, redirect them.
if (!isset($_SESSION['force_password_reset_user_id'])) {
    header("Location: login.php");
    exit();
}

$error = '';
$success = '';
$userId = $_SESSION['force_password_reset_user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($new_password) || empty($confirm_password)) {
        $error = 'Please fill in both password fields.';
    } elseif ($new_password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } elseif (strlen($new_password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } else {
        try {
            $passwordHash = password_hash($new_password, PASSWORD_BCRYPT);

            // Determine the correct password column name
            $passwordColumn = resolve_password_column($pdo);

            // Update the password and reset the flag
            $stmt = $pdo->prepare("UPDATE users SET {$passwordColumn} = ?, password_reset_required = 0 WHERE id = ?");
            $stmt->execute([$passwordHash, $userId]);

            log_audit($pdo, $userId, 'forced_password_change', 'User successfully changed their password.');

            // Log the user in properly now
            $stmt = $pdo->prepare("SELECT id, coop_no, name, role FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();

            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['coop_no'] = $user['coop_no'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['last_activity'] = time();
            unset($_SESSION['force_password_reset_user_id']);

            // Redirect to the appropriate dashboard
            if ($user['role'] === 'admin') {
                header("Location: ../admin/index.php");
            } else {
                header("Location: ../member/dashboard.php");
            }
            exit();

        } catch (PDOException $e) {
            $error = 'An error occurred while updating your password. Please try again.';
            log_audit($pdo, $userId, 'forced_password_change_failed', $e->getMessage());
        }
    }
}

// Function to resolve password column name, needed if this page is used independently.
if (!function_exists('resolve_password_column')) {
    function resolve_password_column($pdo) {
        $candidates = ['password_hash', 'password'];
        $placeholders = implode(',', array_fill(0, count($candidates), '?'));
        $sql = "
            SELECT COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'users'
              AND COLUMN_NAME IN ($placeholders)
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($candidates);
        $row = $stmt->fetch();
        return $row['COLUMN_NAME'] ?? 'password_hash';
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Your Password - Beulah Coop</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/custom.css" rel="stylesheet">
</head>
<body class="auth-body">
<div class="auth-shell">
    <div class="auth-card">
        <div class="auth-left">
            <div class="auth-brand">Beulah Coop</div>
            <div class="auth-tagline">Welcome!</div>
            <div class="auth-badge">Secure Access Portal</div>
            <div class="auth-ornament"></div>
        </div>
        <div class="auth-right">
            <div class="auth-title">Change Your Password</div>
            <div class="auth-subtitle">For your security, please create a new password.</div>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="alert alert-info">
                This is your first login or a password was reset for you. Please choose a new password to continue.
            </div>

            <form method="POST" action="">
                <div class="mb-3">
                    <label class="form-label">New Password</label>
                    <input type="password" name="new_password" class="form-control" required minlength="8" autofocus>
                </div>
                <div class="mb-3">
                    <label class="form-label">Confirm New Password</label>
                    <input type="password" name="confirm_password" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Set New Password and Login</button>
            </form>
        </div>
    </div>
</div>
</body>
</html>
