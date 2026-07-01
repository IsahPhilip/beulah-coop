<?php
// test-email.php - Test SMTP email configuration
// Access this file in browser to test your email settings

require_once 'includes/env.php';
EnvLoader::load();

// Only allow admin access
if (!isset($_SESSION)) {
    session_start();
}
if (($_SESSION['role'] ?? '') !== 'admin') {
    die("Access denied. Admin only.");
}
?> 
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Test Email Configuration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="container py-5">
    <h1>📧 Email Configuration Test</h1>
    
    <div class="card mb-4">
        <div class="card-header">Current Configuration</div>
        <div class="card-body">
            <table class="table table-sm">
                <tr><td><strong>Host:</strong></td><td><?= htmlspecialchars(env('MAIL_HOST', 'NOT SET')) ?></td></tr>
                <tr><td><strong>Port:</strong></td><td><?= htmlspecialchars(env('MAIL_PORT', 'NOT SET')) ?></td></tr>
                <tr><td><strong>Username:</strong></td><td><?= htmlspecialchars(env('MAIL_USERNAME', 'NOT SET')) ?></td></tr>
                <tr><td><strong>Password:</strong></td><td><?= substr(env('MAIL_PASSWORD', ''), 0, 4) ?>...</td></tr>
                <tr><td><strong>Encryption:</strong></td><td><?= htmlspecialchars(env('MAIL_ENCRYPTION', 'NOT SET')) ?></td></tr>
                <tr><td><strong>From Address:</strong></td><td><?= htmlspecialchars(env('MAIL_FROM_ADDRESS', 'NOT SET')) ?></td></tr>
                <tr><td><strong>Debug Mode:</strong></td><td><?= env('APP_DEBUG', false) ? 'ON' : 'OFF' ?></td></tr>
            </table>
        </div>
    </div>

    <?php
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_email'])) {
        $testEmail = trim($_POST['test_email']);
        
        echo '<div class="alert alert-info">Testing email delivery to: ' . htmlspecialchars($testEmail) . '</div>';
        
        try {
            require_once __DIR__ . '/vendor/autoload.php';
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            
            // Enable debug output
            $mail->SMTPDebug = 2;
            $mail->Debugoutput = function($str, $level) {
                echo '<div class="alert alert-warning"><small>' . htmlspecialchars($str) . '</small></div>';
            };
            
            $mail->isSMTP();
            $mail->Host = env('MAIL_HOST', 'smtp.gmail.com');
            $mail->SMTPAuth = true;
            $mail->Username = env('MAIL_USERNAME', '');
            $mail->Password = env('MAIL_PASSWORD', '');
            $mail->SMTPSecure = env('MAIL_ENCRYPTION', 'tls');
            $mail->Port = (int)env('MAIL_PORT', 587);
            
            $mail->setFrom(env('MAIL_FROM_ADDRESS', 'no-reply@beulahcoop.local'), env('MAIL_FROM_NAME', 'Beulah Coop'));
            $mail->addAddress($testEmail);
            $mail->isHTML(true);
            $mail->Subject = 'Test Email - Beulah Coop';
            $mail->Body = '<h1>Test Email</h1><p>If you received this, email is working!</p>';
            
            $mail->send();
            
            echo '<div class="alert alert-success"><strong>✓ Email sent successfully!</strong></div>';
            
        } catch (Exception $e) {
            echo '<div class="alert alert-danger"><strong>✗ Email failed:</strong><br>' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    }
    ?>

    <div class="card">
        <div class="card-header">Send Test Email</div>
        <div class="card-body">
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label">Send test email to:</label>
                    <input type="email" name="test_email" class="form-control" required 
                           value="<?= htmlspecialchars(env('MAIL_FROM_ADDRESS', '')) ?>">
                </div>
                <button type="submit" class="btn btn-primary">Send Test Email</button>
            </form>
        </div>
    </div>

    <div class="mt-4">
        <h3>Common Issues:</h3>
        <ul>
            <li><strong>Gmail:</strong> Use an App Password (not your regular password). Enable 2FA first.</li>
            <li><strong>"Less secure apps" blocked:</strong> Google no longer supports this. Use App Passwords.</li>
            <li><strong>Hosting provider:</strong> Check cPanel for correct SMTP settings.</li>
            <li><strong>Port blocked:</strong> Some hosts block outbound SMTP. Contact support.</li>
            <li><strong>SSL vs TLS:</strong> Try PORT 465 with SSL or PORT 587 with TLS.</li>
        </ul>
        
        <h3 class="mt-3">Quick Fixes:</h3>
        <ol>
            <li>For Gmail: Use App Password from myaccount.google.com/apppasswords</li>
            <li>For cPanel: Use mail.yourdomain.com with your full email as username</li>
            <li>Check that MAIL_USERNAME is the FULL email address</li>
            <li>Verify MAIL_PASSWORD has no extra spaces</li>
        </ol>
    </div>
</body>
</html>