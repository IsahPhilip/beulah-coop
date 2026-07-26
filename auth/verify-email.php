<?php
// auth/verify-email.php
require_once '../includes/env.php';
require_once '../config/db.php';
require_once '../includes/functions.php';

$token = trim($_GET['token'] ?? '');
$status = 'invalid'; // invalid | expired | already | success

if ($token !== '') {
    $stmt = $pdo->prepare("SELECT id, name, email, email_verified_at, registration_status FROM users WHERE email_verify_token = ?");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user) {
        $status = 'invalid';
    } elseif ($user['email_verified_at'] !== null || $user['registration_status'] !== 'unverified') {
        $status = 'already';
    } else {
        $pdo->prepare("UPDATE users SET email_verified_at = NOW(), registration_status = 'pending', email_verify_token = NULL WHERE id = ?")
            ->execute([$user['id']]);
        log_audit($pdo, $user['id'], 'email_verified', 'Email verified via token link');
        $status = 'success';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification - Beulah Coop</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/bold/style.css">
    <link href="../assets/css/custom.css" rel="stylesheet">
</head>
<body class="auth-body">
<div class="auth-shell" style="align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:1.5rem;padding:3rem 2.5rem;max-width:480px;width:100%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.1);">
        <?php if ($status === 'success'): ?>
            <div style="width:72px;height:72px;background:#D1FAE5;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1.5rem;font-size:2rem;color:#059669;">
                <i class="ph-bold ph-check-circle"></i>
            </div>
            <h2 style="font-weight:700;color:#111827;margin-bottom:.5rem;">Email Verified!</h2>
            <p style="color:#6B7280;margin-bottom:2rem;">Your email has been verified. Your registration is now pending admin review. You will be notified once your account is activated after payment of the ₦2,000 registration fee.</p>
            <a href="login.php" class="btn btn-primary w-100">Continue to Login</a>
        <?php elseif ($status === 'already'): ?>
            <div style="width:72px;height:72px;background:#DBEAFE;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1.5rem;font-size:2rem;color:#3B82F6;">
                <i class="ph-bold ph-info"></i>
            </div>
            <h2 style="font-weight:700;color:#111827;margin-bottom:.5rem;">Already Verified</h2>
            <p style="color:#6B7280;margin-bottom:2rem;">Your email has already been verified. You can log in to your account.</p>
            <a href="login.php" class="btn btn-primary w-100">Go to Login</a>
        <?php else: ?>
            <div style="width:72px;height:72px;background:#FEE2E2;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1.5rem;font-size:2rem;color:#EF4444;">
                <i class="ph-bold ph-x-circle"></i>
            </div>
            <h2 style="font-weight:700;color:#111827;margin-bottom:.5rem;">Invalid Link</h2>
            <p style="color:#6B7280;margin-bottom:2rem;">This verification link is invalid or has expired. Please register again or contact support.</p>
            <a href="register.php" class="btn btn-primary w-100">Register Again</a>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
