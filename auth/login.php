<?php
// auth/login.php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

$error = '';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $coop_no = strtoupper(trim($_POST['coop_no']));
    $password = $_POST['password'];

    if (empty($coop_no) || empty($password)) {
        $error = "Coop No. and Password are required.";
    } else {
        $twofaColumn = resolve_twofa_column($pdo);
        $selectTwofa = $twofaColumn ? ", {$twofaColumn} AS twofa_enabled" : "";
        $stmt = $pdo->prepare("SELECT id, coop_no, name, password_hash, role, email{$selectTwofa} FROM users WHERE coop_no = ?");
        $stmt->execute([$coop_no]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            $twofaEnabled = !empty($user['twofa_enabled']);

            if (!$twofaEnabled) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['coop_no'] = $user['coop_no'];
                $_SESSION['name'] = $user['name'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['last_activity'] = time();

                log_audit($pdo, $user['id'], 'login_success', 'Login without 2FA');

                if ($user['role'] === 'admin') {
                    header("Location: ../admin/index.php");
                } else {
                    header("Location: ../member/dashboard.php");
                }
                exit();
            }

            if (empty($user['email'])) {
                $error = "2FA is enabled on your account, but no email is set. Please contact admin.";
            } else {
                // Start 2FA process
                $code = rand(100000, 999999);
                $_SESSION['temp_user'] = $user;
                $_SESSION['2fa_code'] = $code;
                $_SESSION['2fa_expiry'] = time() + 600; // 10 minutes

                // Send email
                require_once '../vendor/autoload.php';
                $mail = new PHPMailer\PHPMailer\PHPMailer(true);

                try {
                    $mail->isSMTP();
                    $mail->Host = 'smtp.gmail.com';           // Change to your SMTP host
                    $mail->SMTPAuth = true;
                    $mail->Username = 'your-email@gmail.com'; // Change
                    $mail->Password = 'your-app-password';    // Use App Password
                    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port = 587;

                    $mail->setFrom('no-reply@beulahcoop.local', 'Beulah Coop');
                    $mail->addAddress($user['email']);
                    $mail->isHTML(true);
                    $mail->Subject = 'Your Beulah Coop 2FA Code';
                    $mail->Body = "Dear {$user['name']},<br><br>Your 6-digit verification code is: <b>$code</b><br><br>This code expires in 10 minutes.<br><br>Thank you.";

                    $mail->send();

                    log_audit($pdo, $user['id'], '2fa_initiated', '2FA code sent to email');
                    header("Location: verify_2fa.php");
                    exit();
                } catch (Exception $e) {
                    $error = "Failed to send 2FA code. Please contact admin.";
                }
            }
        } else {
            $error = "Invalid Coop No. or Password.";
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

            <form method="POST" action="">
                <div class="mb-3">
                    <label class="form-label">Coop No. (e.g. BC01)</label>
                    <input type="text" name="coop_no" class="form-control" required autofocus>
                </div>
                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                <button type="submit" class="btn btn-primary w-100">Login</button>
                <div class="auth-footer">Contact admin if you forgot your password.</div>
            </form>
        </div>
    </div>
</div>
</body>
</html>
