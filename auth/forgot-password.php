<?php
require_once '../includes/env.php';

$sessionName = env('SESSION_NAME', 'beulah_session');
session_name($sessionName);
session_start();

require_once '../config/db.php';
require_once '../includes/functions.php';

// Ensure the table and otp_code column exist (safe to run every request)
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

// Add otp_code column to tables created before this feature
try {
    $has = $pdo->query("SHOW COLUMNS FROM `password_reset_tokens` LIKE 'otp_code'")->fetchAll();
    if (empty($has)) {
        $pdo->exec("ALTER TABLE `password_reset_tokens` ADD COLUMN `otp_code` VARCHAR(6) NULL AFTER `token`");
        $pdo->exec("ALTER TABLE `password_reset_tokens` ADD INDEX `idx_prt_otp` (`otp_code`)");
    }
} catch (PDOException $e) { /* ignore */ }

$error       = '';
$successMsg  = '';
$emailSentTo = '';  // set after a successful send
$canEnterOtp = !empty($_SESSION['password_reset_pending'] ?? []);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email   = trim($_POST['email']   ?? '');
    $coop_no = strtoupper(trim($_POST['coop_no'] ?? ''));

    if (empty($email) || empty($coop_no)) {
        $error = 'Please provide both your Coop No. and email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id, coop_no, name, email FROM users WHERE coop_no = ? AND email = ?");
            $stmt->execute([$coop_no, $email]);
            $user = $stmt->fetch();

            if (!$user) {
                $error = 'No account found with that Coop No. and email combination.';
            } else {
                unset($_SESSION['password_reset_pending']);

                // Invalidate any previous unused tokens for this user
                $pdo->prepare("DELETE FROM password_reset_tokens WHERE user_id = ? AND used_at IS NULL")
                    ->execute([$user['id']]);

                // Generate a secure 64-char token and a 6-digit OTP
                $token     = bin2hex(random_bytes(32));
                $otp       = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
                $expiryMin = max(1, (int)(env('PASSWORD_RESET_EXPIRY', 3600) / 60));
                $expiresAt = date('Y-m-d H:i:s', time() + $expiryMin * 60);

                $pdo->prepare("INSERT INTO password_reset_tokens (user_id, token, otp_code, expires_at) VALUES (?, ?, ?, ?)")
                    ->execute([$user['id'], $token, $otp, $expiresAt]);

                // Build the reset link
                $scheme     = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $resetLink  = $scheme . '://' . $_SERVER['HTTP_HOST']
                            . dirname($_SERVER['SCRIPT_NAME'])
                            . '/reset-password.php?token=' . urlencode($token);

                $otpPageLink = $scheme . '://' . $_SERVER['HTTP_HOST']
                             . dirname($_SERVER['SCRIPT_NAME'])
                             . '/verify-otp.php';

                // Email body (HTML)
                $fromName    = htmlspecialchars(env('MAIL_FROM_NAME', 'Beulah Coop'));
                $userName    = htmlspecialchars($user['name']);
                $userEmail   = htmlspecialchars($user['email']);
                $htmlBody    = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;font-family:'Segoe UI',Arial,sans-serif;background:#f4f6f9;color:#333;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f9;padding:30px 0;">
  <tr><td align="center">
    <table width="560" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.08);">

      <!-- Header -->
      <tr><td style="background:#1a3a5c;padding:28px 32px;text-align:center;">
        <p style="margin:0;font-size:22px;font-weight:700;color:#fff;">Beulah Multi-Purpose Cooperative</p>
        <p style="margin:6px 0 0;font-size:13px;color:#90b8d8;">Password Reset Request</p>
      </td></tr>

      <!-- Body -->
      <tr><td style="padding:32px;">
        <p style="margin:0 0 16px;">Dear <strong>{$userName}</strong>,</p>
        <p style="margin:0 0 24px;line-height:1.6;">
          We received a request to reset the password for your Beulah Coop account
          (<strong>{$userEmail}</strong>). Use one of the options below — both expire in
          <strong>{$expiryMin} minutes</strong>.
        </p>

        <!-- Option 1: Reset Link -->
        <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px;">
          <tr><td style="background:#f0f4f8;border-radius:8px;padding:20px;">
            <p style="margin:0 0 12px;font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#6c757d;">Option 1 — Click the link</p>
            <p style="margin:0 0 16px;font-size:13px;line-height:1.5;color:#555;">
              Click the button below to go directly to the password reset page.
            </p>
            <p style="text-align:center;margin:0;">
              <a href="{$resetLink}"
                 style="display:inline-block;background:#1a3a5c;color:#fff;padding:13px 32px;border-radius:6px;text-decoration:none;font-size:15px;font-weight:600;">
                Reset My Password
              </a>
            </p>
          </td></tr>
        </table>

        <!-- Option 2: OTP -->
        <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px;">
          <tr><td style="background:#fff8ec;border:1px solid #ffe0a0;border-radius:8px;padding:20px;">
            <p style="margin:0 0 12px;font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#6c757d;">Option 2 — Enter this code</p>
            <p style="margin:0 0 16px;font-size:13px;line-height:1.5;color:#555;">
              If the link doesn't work, go to the
              <a href="{$otpPageLink}" style="color:#1a3a5c;">OTP verification page</a>
              and enter the code below.
            </p>
            <p style="text-align:center;margin:0;">
              <span style="display:inline-block;letter-spacing:10px;font-size:36px;font-weight:700;color:#1a3a5c;font-family:'Courier New',monospace;padding:10px 16px;background:#fff;border:2px dashed #ffe0a0;border-radius:8px;">
                {$otp}
              </span>
            </p>
          </td></tr>
        </table>

        <p style="margin:0 0 8px;font-size:13px;color:#888;">
          If you did not request this, you can safely ignore this email — your password will not change.
        </p>
      </td></tr>

      <!-- Footer -->
      <tr><td style="background:#f0f4f8;padding:16px 32px;text-align:center;">
        <p style="margin:0;font-size:12px;color:#aaa;">&copy; Beulah Multi-Purpose Cooperative Society Ltd.</p>
      </td></tr>

    </table>
  </td></tr>
</table>
</body>
</html>
HTML;

                // Plain-text fallback
                $textBody = <<<TEXT
Dear {$user['name']},

We received a request to reset the password for your Beulah Coop account ({$user['email']}).

Option 1 - Reset via link:
{$resetLink}

Option 2 - Enter OTP code on the website ({$otpPageLink}):
{$otp}

Both options expire in {$expiryMin} minutes.

If you did not request this, please ignore this email.

— Beulah Multi-Purpose Cooperative Society Ltd.
TEXT;

                // Attempt to send the email
                $emailSent = false;
                $mailError = '';
                try {
                    require_once '../vendor/autoload.php';
                    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                    $mail->isSMTP();
                    $mail->Host       = env('MAIL_HOST', 'smtp.gmail.com');
                    $mail->SMTPAuth   = true;
                    $mail->Username   = env('MAIL_USERNAME', '');
                    $mail->Password   = env('MAIL_PASSWORD', '');
                    $mail->SMTPSecure = env('MAIL_ENCRYPTION', 'tls');
                    $mail->Port       = (int)env('MAIL_PORT', 587);

                    // MAIL_FROM_NAME may contain an unexpanded "${APP_NAME}" reference
                    $fromName = env('MAIL_FROM_NAME', '');
                    if (empty($fromName) || str_starts_with($fromName, '${')) {
                        $fromName = env('APP_NAME', 'Beulah Coop');
                    }
                    $mail->setFrom(env('MAIL_FROM_ADDRESS', ''), $fromName);
                    $mail->addAddress($user['email'], $user['name']);

                    $mail->isHTML(true);
                    $mail->Subject  = 'Password Reset — Beulah Coop';
                    $mail->Body     = $htmlBody;
                    $mail->AltBody  = $textBody;

                    $mail->send();
                    $emailSent  = true;
                    $emailSentTo = $user['email'];

                    log_audit($pdo, $user['id'], 'password_reset_requested', 'Reset link + OTP sent to ' . $user['email']);
                } catch (Exception $e) {
                    $mailError = $e->getMessage();
                    log_audit($pdo, $user['id'], 'password_reset_failed', 'Email send failed: ' . $mailError);
                }

                if ($emailSent) {
                    $_SESSION['password_reset_pending'] = [
                        'user_id' => $user['id'],
                        'token' => $token,
                        'otp_code' => $otp,
                        'expires_at' => $expiresAt,
                    ];
                    $canEnterOtp = true;
                    $successMsg = 'Reset instructions have been sent to ' . htmlspecialchars($user['email']) . '.';
                } else {
                    unset($_SESSION['password_reset_pending']);
                    $error = 'We could not send the reset email right now. Please try again later or contact the administrator.';
                    // Roll back the token so it cannot be used
                    $pdo->prepare("DELETE FROM password_reset_tokens WHERE token = ?")->execute([$token]);
                }
            }
        } catch (PDOException $e) {
            $error = env('APP_DEBUG', false)
                ? 'Database error: ' . $e->getMessage()
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
            <div class="auth-title">Forgot Password</div>
            <div class="auth-subtitle">Enter your Coop No. and registered email to receive reset instructions</div>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($successMsg && !$error): ?>
                <div class="alert alert-success mb-3"><?= htmlspecialchars($successMsg) ?></div>

                <?php if ($emailSentTo): ?>
                    <!-- Show OTP entry option for users who receive the email -->
                    <div class="card border-0 bg-light mb-3">
                        <div class="card-body text-center">
                            <p class="mb-1 fw-semibold">Didn't receive the email or prefer to enter a code?</p>
                            <p class="text-muted small mb-3">Enter the 6-digit OTP from your email on the verification page.</p>
                            <a href="verify-otp.php" class="btn btn-outline-primary">Enter OTP Code</a>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="text-center">
                    <a href="login.php" class="text-decoration-none text-muted small">Back to Login</a>
                </div>
            <?php else: ?>
                <form method="POST" action="" novalidate>
                    <div class="mb-3">
                        <label class="form-label">Coop No. <span class="text-muted small">(e.g. BC01)</span></label>
                        <input type="text" name="coop_no" class="form-control" required autofocus
                               value="<?= htmlspecialchars($_POST['coop_no'] ?? '') ?>">
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Registered Email Address</label>
                        <input type="email" name="email" class="form-control" required
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                        <div class="form-text">Must match the email on your account</div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Send Reset Instructions</button>
                    <div class="auth-footer mt-3 text-center">
                        <a href="login.php" class="text-decoration-none">Back to Login</a>
                        <?php if ($canEnterOtp): ?>
                            <span class="mx-2 text-muted">·</span>
                            <a href="verify-otp.php" class="text-decoration-none">Enter OTP</a>
                        <?php endif; ?>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
