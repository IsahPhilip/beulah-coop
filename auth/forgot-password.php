<?php
/**
 * Forgot Password Page
 * Handles password reset requests via email
 */

require_once '../includes/env.php';

$sessionName = env('SESSION_NAME', 'beulah_session');
session_name($sessionName);
session_start();

require_once '../config/db.php';
require_once '../includes/functions.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $coop_no = strtoupper(trim($_POST['coop_no'] ?? ''));

    if (empty($email) || empty($coop_no)) {
        $error = 'Please provide both your Coop No. and email address.';
    } else {
        try {
            // Find user by coop_no and email
            $stmt = $pdo->prepare("SELECT id, coop_no, name, email FROM users WHERE coop_no = ? AND email = ?");
            $stmt->execute([$coop_no, $email]);
            $user = $stmt->fetch();

            if (!$user) {
                $error = 'No account found with that Coop No. and email combination.';
            } else {
                // Generate secure reset token
                $token = bin2hex(random_bytes(32));
                $expires_at = date('Y-m-d H:i:s', time() + (int)env('PASSWORD_RESET_EXPIRY', 3600)); // 1 hour default

                // Store token in database
                $stmt = $pdo->prepare("
                    INSERT INTO password_reset_tokens (user_id, token, expires_at)
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$user['id'], $token, $expires_at]);

                // Build reset link
                $reset_link = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . 
                             $_SERVER['HTTP_HOST'] . 
                             dirname($_SERVER['SCRIPT_NAME']) . 
                             '/reset-password.php?token=' . $token;

                // Send email
                try {
                    // If in debug mode, skip sending and show link directly
                    if (env('APP_DEBUG', false)) {
                        $success = 'Debug Mode: Email sending is bypassed. Use the link below to reset your password:<br><br>';
                        $success .= '<a href="' . $reset_link . '" class="btn btn-primary">Reset Password</a>';
                        $success .= '<br><br><small class="text-muted">In production, this link would be sent to ' . htmlspecialchars($user['email']) . '</small>';
                        
                        log_audit($pdo, $user['id'], 'password_reset_requested', 'Password reset link generated (debug mode, email not sent)');
                    } else {
                        require_once '../vendor/autoload.php';
                        $mail = new PHPMailer\PHPMailer\PHPMailer(true);

                        $mail->isSMTP();
                        $mail->Host = env('MAIL_HOST', 'smtp.gmail.com');
                        $mail->SMTPAuth = true;
                        $mail->Username = env('MAIL_USERNAME', '');
                        $mail->Password = env('MAIL_PASSWORD', '');
                        $mail->SMTPSecure = env('MAIL_ENCRYPTION', 'tls');
                        $mail->Port = (int)env('MAIL_PORT', 587);

                        $mail->setFrom(env('MAIL_FROM_ADDRESS', 'no-reply@beulahcoop.local'), env('MAIL_FROM_NAME', 'Beulah Coop'));
                        $mail->addAddress($user['email']);
                        $mail->isHTML(true);
                        $mail->Subject = 'Password Reset Request - Beulah Coop';
                        $mail->Body = "
                            Dear {$user['name']},<br><br>
                            You have requested to reset your password for your Beulah Coop account.<br><br>
                            Click the link below to reset your password:<br>
                            <a href='$reset_link' style='background: var(--primary); color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; margin: 10px 0;'>
                                Reset Password
                            </a><br><br>
                            This link will expire in " . (env('PASSWORD_RESET_EXPIRY', 3600) / 60) . " minutes.<br><br>
                            If you did not request this password reset, please ignore this email or contact support.<br><br>
                            Thank you.<br>
                            Beulah Coop Team
                        ";

                        $mail->send();

                        log_audit($pdo, $user['id'], 'password_reset_requested', 'Password reset link sent via email');
                        $success = 'Password reset instructions have been sent to your email address.';
                    }
                } catch (Exception $e) {
                    if (env('APP_DEBUG', false)) {
                        $error = "Failed to send reset email: " . $e->getMessage() . "<br><br>Debug: Reset link would be: $reset_link";
                    } else {
                        $error = 'Failed to send reset email. Please try again later or contact admin.';
                    }
                }
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Beulah Coop</title>
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
            <div class="auth-subtitle">Enter your Coop No. and email to receive reset instructions</div>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
                <div class="text-center mt-4">
                    <a href="login.php" class="btn btn-primary">Back to Login</a>
                </div>
            <?php endif; ?>

            <?php if (!$success): ?>
                <form method="POST" action="">
                    <div class="mb-3">
                        <label class="form-label">Coop No. (e.g. BC01)</label>
                        <input type="text" name="coop_no" class="form-control" required autofocus
                               value="<?= htmlspecialchars($_POST['coop_no'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email Address</label>
                        <input type="email" name="email" class="form-control" required
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                        <div class="form-text">Enter the email associated with your account</div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Send Reset Link</button>
                    <div class="auth-footer mt-3">
                        <a href="login.php" class="text-decoration-none">Back to Login</a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>