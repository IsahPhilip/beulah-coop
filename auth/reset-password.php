<?php
/**
 * Reset Password Page
 * Handles password reset via secure token
 */

require_once '../includes/env.php';

$sessionName = env('SESSION_NAME', 'beulah_session');
session_name($sessionName);
session_start();

require_once '../config/db.php';
require_once '../includes/functions.php';

// Ensure table exists (in case migration was not yet applied)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
        `id`         INT AUTO_INCREMENT PRIMARY KEY,
        `user_id`    INT NOT NULL,
        `token`      VARCHAR(64) NOT NULL UNIQUE,
        `otp_code`   VARCHAR(6)  NULL,
        `expires_at` DATETIME NOT NULL,
        `used_at`    DATETIME NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) { /* already exists */ }

$error = '';
$success = '';
$token = $_GET['token'] ?? '';

if (empty($token)) {
    $error = 'Invalid password reset link.';
} else {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $token = $_POST['token'] ?? '';

        $minLength = get_password_min_length();

        if (empty($new_password) || empty($confirm_password)) {
            $error = 'Please fill in all password fields.';
        } elseif ($new_password !== $confirm_password) {
            $error = 'Passwords do not match.';
        } elseif (strlen($new_password) < $minLength) {
            $error = "Password must be at least $minLength characters long.";
        } else {
            try {
                // Verify token is valid and not expired
                $stmt = $pdo->prepare("
                    SELECT prt.user_id, prt.expires_at, prt.used_at, u.id as user_exists
                    FROM password_reset_tokens prt
                    JOIN users u ON u.id = prt.user_id
                    WHERE prt.token = ?
                    LIMIT 1
                ");
                $stmt->execute([$token]);
                $token_data = $stmt->fetch();

                if (!$token_data || !empty($token_data['used_at'])) {
                    $error = 'This reset link has already been used or is invalid.';
                } elseif (strtotime($token_data['expires_at']) < time()) {
                    $error = 'This reset link has expired. Please request a new one.';
                } else {
                    // Update password
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $passwordColumn = 'password_hash';

                    $stmt = $pdo->prepare("UPDATE users SET {$passwordColumn} = ? WHERE id = ?");
                    $stmt->execute([$hashed_password, $token_data['user_id']]);

                    // Mark token as used
                    $stmt = $pdo->prepare("UPDATE password_reset_tokens SET used_at = NOW() WHERE token = ?");
                    $stmt->execute([$token]);

                    // If 2FA is enabled, we should optionally disable it or notify the user
                    // For security, let's check if 2FA is enabled
                    $twofaColumn = null;
                    $candidates = ['twofa_enabled', 'two_factor_enabled'];
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
                    $twofaResult = $stmt->fetch();
                    $twofaColumn = $twofaResult['COLUMN_NAME'] ?? null;

                    if ($twofaColumn) {
                        // Optionally disable 2FA on password reset for security
                        // This ensures that if account was compromised, resetting password also removes 2FA bypass
                        $stmt = $pdo->prepare("UPDATE users SET {$twofaColumn} = 0 WHERE id = ?");
                        $stmt->execute([$token_data['user_id']]);

                        log_audit($pdo, $token_data['user_id'], '2fa_disabled', '2FA disabled during password reset');
                    }

                    log_audit($pdo, $token_data['user_id'], 'password_changed', 'Password reset via email link');

                    $success = 'Your password has been reset successfully! You can now login with your new password.';
                }
            } catch (PDOException $e) {
                if (env('APP_DEBUG', false)) {
                    $error = "Database error: " . $e->getMessage();
                } else {
                    $error = "An error occurred. Please try again.";
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Beulah Coop</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/custom.css" rel="stylesheet">
</head>
<body class="auth-body">
<div class="auth-shell">
    <div class="auth-card">
        <div class="auth-left">
            <div class="auth-brand">Beulah Coop</div>
            <div class="auth-tagline">Savings & Loans Management</div>
            <div class="auth-badge">Secure Access Portal</div>
            <div class="auth-ornament"></div>
        </div>
        <div class="auth-right">
            <div class="auth-title">Reset Password</div>
            <div class="auth-subtitle">Enter your new password below</div>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success) ?>
                </div>
                <div class="text-center mt-4">
                    <a href="login.php" class="btn btn-primary">
                        <i class="bi bi-box-arrow-in-right me-1"></i>Go to Login
                    </a>
                </div>
            <?php endif; ?>

            <?php if (!$success && !empty($token)): ?>
                <?php
                // Verify token on page load
                try {
                    $stmt = $pdo->prepare("
                        SELECT prt.user_id, prt.expires_at, prt.used_at
                        FROM password_reset_tokens prt
                        WHERE prt.token = ?
                        LIMIT 1
                    ");
                    $stmt->execute([$token]);
                    $token_data = $stmt->fetch();

                    if (!$token_data || !empty($token_data['used_at'])) {
                        $error = 'This reset link has already been used or is invalid.';
                    } elseif (strtotime($token_data['expires_at']) < time()) {
                        $error = 'This reset link has expired. Please <a href="forgot-password.php">request a new one</a>.';
                    }
                } catch (PDOException $e) {
                    if (env('APP_DEBUG', false)) {
                        $error = "Database error: " . $e->getMessage();
                    } else {
                        $error = "An error occurred. Please try again.";
                    }
                }
                ?>

                <?php if (!$error): ?>
                    <form method="POST" action="">
                        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                        
                        <div class="mb-3">
                            <label class="form-label">New Password</label>
                            <input type="password" name="new_password" class="form-control" 
                                   required minlength="<?= get_password_min_length() ?>" autofocus>
                            <div class="form-text">Minimum <?= get_password_min_length() ?> characters</div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="form-control" required>
                        </div>

                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            <small>
                                <strong>Security Note:</strong> For your protection, two-factor authentication will be temporarily disabled after resetting your password. You can re-enable it from your profile settings after logging in.
                            </small>
                        </div>
                        
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-key me-1"></i>Reset Password
                        </button>
                        
                        <div class="auth-footer mt-3">
                            <a href="login.php" class="text-decoration-none">Back to Login</a>
                        </div>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
            
            <?php if ($error && !$success): ?>
                <div class="text-center mt-4">
                    <a href="forgot-password.php" class="btn btn-outline-primary">
                        <i class="bi bi-envelope me-1"></i>Request New Reset Link
                    </a>
                    <br>
                    <a href="login.php" class="text-decoration-none mt-3 d-block">Back to Login</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>