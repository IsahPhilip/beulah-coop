<?php
require_once '../includes/env.php';

$sessionName = env('SESSION_NAME', 'beulah_session');
session_name($sessionName);
session_start();

require_once '../config/db.php';
require_once '../includes/functions.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Normalise: strip spaces so users can enter "123 456"
    $otp = preg_replace('/\s+/', '', trim($_POST['otp_code'] ?? ''));

    if (!preg_match('/^\d{6}$/', $otp)) {
        $error = 'Please enter a valid 6-digit code.';
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT token
                FROM password_reset_tokens
                WHERE otp_code = ?
                  AND used_at IS NULL
                  AND expires_at > NOW()
                LIMIT 1
            ");
            $stmt->execute([$otp]);
            $row = $stmt->fetch();

            if ($row) {
                // OTP is valid — redirect to the reset-password page
                header('Location: reset-password.php?token=' . urlencode($row['token']));
                exit;
            } else {
                $error = 'That code is invalid or has expired. Please <a href="forgot-password.php" class="alert-link">request a new one</a>.';
            }
        } catch (PDOException $e) {
            $error = env('APP_DEBUG', false)
                ? 'Database error: ' . htmlspecialchars($e->getMessage())
                : 'An error occurred. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enter OTP - Beulah Coop</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/custom.css" rel="stylesheet">
    <style>
        .otp-input {
            letter-spacing: 0.4em;
            font-size: 1.5rem;
            font-weight: 600;
            text-align: center;
            max-width: 200px;
            margin: 0 auto;
        }
    </style>
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
            <div class="auth-title">Enter OTP</div>
            <div class="auth-subtitle">Enter the 6-digit code sent to your email address</div>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= $error /* may contain a safe link */ ?></div>
            <?php endif; ?>

            <form method="POST" action="" novalidate>
                <div class="mb-4 text-center">
                    <label class="form-label d-block mb-2">Verification Code</label>
                    <input type="text" name="otp_code" class="form-control otp-input"
                           inputmode="numeric" pattern="\d{6}" maxlength="7"
                           placeholder="000000" autofocus autocomplete="one-time-code"
                           value="<?= htmlspecialchars($_POST['otp_code'] ?? '') ?>">
                    <div class="form-text mt-1">Check your email for a 6-digit code</div>
                </div>

                <button type="submit" class="btn btn-primary w-100">Verify Code</button>
            </form>

            <div class="auth-footer mt-3 text-center">
                <a href="forgot-password.php" class="text-decoration-none">Request a new code</a>
                <span class="mx-2 text-muted">·</span>
                <a href="login.php" class="text-decoration-none">Back to Login</a>
            </div>
        </div>
    </div>
</div>

<script>
// Auto-format: allow digits and single space, max 6 digits shown
document.querySelector('[name="otp_code"]').addEventListener('input', function () {
    this.value = this.value.replace(/[^\d]/g, '').slice(0, 6);
});
</script>
</body>
</html>
