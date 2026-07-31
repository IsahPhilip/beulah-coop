<?php
/**
 * Login Page
 * Handles user authentication with optional 2FA
 */

// Load environment and start session
require_once '../includes/env.php';

$sessionName = env('SESSION_NAME', 'beulah_session');
session_name($sessionName);
session_start();

require_once '../config/db.php';
require_once '../includes/functions.php';

$error = '';

/**
 * Resolve the 2FA column name dynamically
 */
function resolve_twofa_column($pdo) {
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
    $row = $stmt->fetch();
    return $row['COLUMN_NAME'] ?? null;
}

/**
 * Resolve the password column name (support both password and password_hash)
 */
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $coop_no = strtoupper(trim($_POST['coop_no'] ?? ''));
    $password = $_POST['password'] ?? '';

    if (empty($coop_no) || empty($password)) {
        $error = "Coop No. and Password are required.";
    } else {
        try {
            $twofaColumn = resolve_twofa_column($pdo);
            $passwordColumn = resolve_password_column($pdo);
            
            $selectTwofa = $twofaColumn ? ", {$twofaColumn} AS twofa_enabled" : "";
            $selectStatus = table_has_column($pdo, 'users', 'status') ? ', status' : '';
            $selectPasswordReset = table_has_column($pdo, 'users', 'password_reset_required') ? ', password_reset_required' : '';
            $selectRegStatus = table_has_column($pdo, 'users', 'registration_status') ? ', registration_status' : '';
            $stmt = $pdo->prepare("SELECT id, coop_no, name, {$passwordColumn}, role, email{$selectTwofa}{$selectStatus}{$selectPasswordReset}{$selectRegStatus} FROM users WHERE coop_no = ? OR email = ?");
            $stmt->execute([$coop_no, $coop_no]);
            $user = $stmt->fetch();

            if (!$user) {
                $error = "Invalid credentials.";
            } elseif (!password_verify($password, $user[$passwordColumn])) {
                $error = "Invalid credentials.";
            } elseif ($user['role'] !== 'admin' && ($user['registration_status'] ?? 'active') === 'unverified') {
                $error = "Please verify your email address before logging in. Check your inbox for the verification link.";
            } elseif ($user['role'] !== 'admin' && ($user['registration_status'] ?? 'active') === 'pending') {
                // Allow login — member will see the pending banner on dashboard
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['coop_no'] = $user['coop_no'] ?? '';
                $_SESSION['name']    = $user['name'];
                $_SESSION['role']    = $user['role'];
                $_SESSION['registration_status'] = 'pending';
                $_SESSION['last_activity'] = time();
                log_audit($pdo, $user['id'], 'login_success', 'Login — registration pending');
                header("Location: ../member/dashboard.php");
                exit();
            } else {
                // User authenticated successfully
                $_SESSION['last_activity'] = time();

                // Check if password reset is required for non-admin users.
                if ($user['role'] !== 'admin' && !empty($user['password_reset_required'])) {
                    $_SESSION['force_password_reset_user_id'] = $user['id'];
                    log_audit($pdo, $user['id'], 'login_force_password_reset', 'Redirecting to force password change.');
                    header("Location: force_change_password.php");
                    exit();
                }

                $twofaEnabled = !empty($user['twofa_enabled']);

                if (!$twofaEnabled) {
                    // No 2FA - login directly
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['coop_no'] = $user['coop_no'];
                    $_SESSION['name'] = $user['name'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['registration_status'] = $user['registration_status'] ?? 'active';
                    $_SESSION['last_activity'] = time();

                    log_audit($pdo, $user['id'], 'login_success', 'Login without 2FA');

                    if ($user['role'] === 'admin') {
                        header("Location: ../admin/index.php");
                    } else {
                        header("Location: ../member/dashboard.php");
                    }
                    exit();
                }

                // 2FA is enabled
                if (empty($user['email'])) {
                    $error = "2FA is enabled on your account, but no email is set. Please contact admin.";
                } else {
                    // Start 2FA process
                    $code = rand(100000, 999999);
                    $_SESSION['temp_user'] = $user;
                    $_SESSION['2fa_code'] = $code;
                    $_SESSION['2fa_expiry'] = time() + (int)env('2FA_CODE_EXPIRY', 600);

                    // Send email using environment config
                    try {
                        // If in debug mode, show 2FA code directly instead of sending email
                        if (env('APP_DEBUG', false)) {
                            // Store 2FA code but show it in debug mode
                            $_SESSION['2fa_debug_code'] = $code;
                            log_audit($pdo, $user['id'], '2fa_initiated', '2FA code generated (debug mode, email not sent)');
                            
                            // Redirect with debug flag
                            header("Location: verify_2fa.php?debug=1");
                            exit();
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
                            $mail->Subject = 'Your Beulah Coop 2FA Code';
                            $mail->Body = "Dear {$user['name']},<br><br>Your 6-digit verification code is: <b>$code</b><br><br>This code expires in " . env('2FA_CODE_EXPIRY', 600) / 60 . " minutes.<br><br>Thank you.";

                            $mail->send();

                            log_audit($pdo, $user['id'], '2fa_initiated', '2FA code sent to email');
                            header("Location: verify_2fa.php");
                            exit();
                        }
                    } catch (Exception $e) {
                        // If PHPMailer fails in debug mode, allow login without 2FA
                        if (env('APP_DEBUG', false)) {
                            $_SESSION['user_id'] = $user['id'];
                            $_SESSION['coop_no'] = $user['coop_no'];
                            $_SESSION['name'] = $user['name'];
                            $_SESSION['role'] = $user['role'];
                            $_SESSION['last_activity'] = time();
                            
                            log_audit($pdo, $user['id'], 'login_success', 'Login with 2FA enabled but email failed (debug mode)');
                            
                            if ($user['role'] === 'admin') {
                                header("Location: ../admin/index.php");
                            } else {
                                header("Location: ../member/dashboard.php");
                            }
                            exit();
                        }
                        $error = "Failed to send 2FA code. Please contact admin.";
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
    <title>Beulah Coop - Login</title>
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
            <div class="auth-title">Welcome Back</div>
            <div class="auth-subtitle">Sign in with your Coop No.</div>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if (($_GET['msg'] ?? '') === 'unverified'): ?>
                <div class="alert alert-warning"><i class="ph-bold ph-envelope me-2"></i>Please verify your email before logging in.</div>
            <?php elseif (($_GET['msg'] ?? '') === 'timeout'): ?>
                <div class="alert alert-warning">Your session expired. Please log in again.</div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="mb-3">
                    <label class="form-label">Coop No. or Email</label>
                    <input type="text" name="coop_no" class="form-control" required autofocus>
                </div>
                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <div class="password-field">
                        <input type="password" name="password" class="form-control password-toggle-input pe-5" required>
                        <button type="button" class="password-toggle" aria-label="Show password">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                        </button>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100">Login</button>
                <div class="auth-footer d-flex justify-content-between">
                    <a href="forgot-password.php" class="text-decoration-none">Forgot your password?</a>
                    <a href="register.php" class="text-decoration-none">New user? Register</a>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
document.querySelectorAll('.password-field').forEach(function (field) {
    const input = field.querySelector('input');
    const button = field.querySelector('.password-toggle');
    if (!input || !button) return;

    button.addEventListener('click', function (event) {
        event.preventDefault();
        const isPassword = input.type === 'password';
        input.type = isPassword ? 'text' : 'password';
        button.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
        button.innerHTML = isPassword
            ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3l18 18"></path><path d="M10.58 10.58A3 3 0 0 0 13.42 13.42"></path><path d="M9.88 5.09A10.94 10.94 0 0 1 12 5c6.5 0 10 7 10 7a17.8 17.8 0 0 1-4.16 5.01"></path><path d="M6.61 6.61A17.7 17.7 0 0 0 2 12s3.5 7 10 7a10.9 10.9 0 0 0 4.4-.93"></path></svg>'
            : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"></path><circle cx="12" cy="12" r="3"></circle></svg>';
    });
});
</script>
</body>
</html>