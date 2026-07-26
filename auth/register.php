<?php
// auth/register.php
require_once '../includes/env.php';
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../vendor/autoload.php';

$sessionName = env('SESSION_NAME', 'beulah_session');
session_name($sessionName);
session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: ../member/dashboard.php'); exit();
}

$error = '';
$success = '';
$activeStep = 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $activeStep = max(1, min(2, (int)($_POST['current_step'] ?? 1)));
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';
    $gName    = trim($_POST['guarantor_name'] ?? '');
    $gPhone   = trim($_POST['guarantor_phone'] ?? '');
    $gCoopNo  = strtoupper(trim($_POST['guarantor_coop_no'] ?? ''));

    if (!$name || !$email || !$phone || !$password || !$gName || !$gPhone || !$gCoopNo) {
        $error = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif (strlen($password) < get_password_min_length()) {
        $error = 'Password must be at least ' . get_password_min_length() . ' characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        // Check email uniqueness
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'An account with this email already exists.';
        } else {
            // Validate guarantor is an existing active member
            $stmt = $pdo->prepare("SELECT id FROM users WHERE coop_no = ? AND role = 'member' AND registration_status = 'active'");
            $stmt->execute([$gCoopNo]);
            if (!$stmt->fetch()) {
                $error = 'Guarantor Coop No. not found or is not an active member.';
            } else {
                $token = bin2hex(random_bytes(32));
                $hash  = password_hash($password, PASSWORD_BCRYPT);

                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO users (name, email, phone, password_hash, role, registration_status, email_verify_token, must_change_password)
                        VALUES (?, ?, ?, ?, 'member', 'unverified', ?, 0)
                    ");
                    $stmt->execute([$name, $email, $phone, $hash, $token]);
                    $userId = (int)$pdo->lastInsertId();

                    $stmt = $pdo->prepare("INSERT INTO guarantors (user_id, name, phone, coop_no) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$userId, $gName, $gPhone, $gCoopNo]);

                    // Send verification email
                    $verifyUrl = rtrim(env('APP_URL', 'http://localhost/codes/beulah-coop'), '/') . '/auth/verify-email.php?token=' . $token;

                    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                    $mail->isSMTP();
                    $mail->Host       = env('MAIL_HOST', 'smtp.gmail.com');
                    $mail->SMTPAuth   = true;
                    $mail->Username   = env('MAIL_USERNAME', '');
                    $mail->Password   = env('MAIL_PASSWORD', '');
                    $mail->SMTPSecure = env('MAIL_ENCRYPTION', 'tls');
                    $mail->Port       = (int)env('MAIL_PORT', 587);
                    $mail->setFrom(env('MAIL_FROM_ADDRESS', 'no-reply@beulahcoop.local'), env('MAIL_FROM_NAME', 'Beulah Coop'));
                    $mail->addAddress($email, $name);
                    $mail->isHTML(true);
                    $mail->Subject = 'Verify your Beulah Coop email';
                    $mail->Body    = "
                        <p>Dear {$name},</p>
                        <p>Thank you for registering with Beulah Cooperative Society.</p>
                        <p>Please click the button below to verify your email address:</p>
                        <p><a href='{$verifyUrl}' style='background:#4F46E5;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;display:inline-block;'>Verify Email</a></p>
                        <p>Or copy this link: <a href='{$verifyUrl}'>{$verifyUrl}</a></p>
                        <p>This link expires in 24 hours.</p>
                        <p>After verification, your account will be reviewed by the admin and you will be asked to pay the ₦2,000 registration fee to activate your account.</p>
                        <br><p>— Beulah Cooperative Society</p>
                    ";
                    $mail->send();

                    $pdo->commit();
                    log_audit($pdo, $userId, 'registration_initiated', "Self-registration by {$email}");
                    $success = 'Registration successful! Please check your email to verify your account.';
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    if (env('APP_DEBUG', false)) {
                        $error = 'Registration failed: ' . $e->getMessage();
                    } else {
                        $error = 'Registration failed. Please try again or contact support.';
                    }
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
    <title>Register - Beulah Coop</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/bold/style.css">
    <link href="../assets/css/custom.css" rel="stylesheet">
</head>
<body class="auth-body">
<div class="auth-shell">
    <div class="auth-card" style="max-width:780px;">
        <div class="auth-left">
            <div class="auth-brand">Beulah Coop</div>
            <div class="auth-tagline">Savings & Loans Management</div>
            <div class="auth-badge">Member Registration</div>
            <div class="auth-ornament"></div>
        </div>
        <div class="auth-right" style="overflow-y:auto;max-height:100vh;padding:2rem;">
            <div class="auth-title">Create Account</div>
            <div class="auth-subtitle">Join Beulah Cooperative Society</div>

            <?php if ($success): ?>
                <div class="alert alert-success d-flex align-items-center gap-2">
                    <i class="ph-bold ph-check-circle"></i>
                    <?= htmlspecialchars($success) ?>
                </div>
                <div class="text-center mt-3">
                    <a href="login.php" class="btn btn-primary">Back to Login</a>
                </div>
            <?php else: ?>

            <?php if ($error): ?>
                <div class="alert alert-danger d-flex align-items-center gap-2">
                    <i class="ph-bold ph-warning-circle"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">
                <input type="hidden" name="current_step" id="currentStepInput" value="<?= htmlspecialchars((string)$activeStep) ?>">

                <div class="register-steps mb-4">
                    <div class="step-pill <?= $activeStep === 1 ? 'active' : '' ?>" data-step="1">
                        <span class="step-number">1</span>
                        <span>Account Details</span>
                    </div>
                    <div class="step-pill <?= $activeStep === 2 ? 'active' : '' ?>" data-step="2">
                        <span class="step-number">2</span>
                        <span>Guarantor Info</span>
                    </div>
                </div>

                <div class="step-content <?= $activeStep === 1 ? 'active' : '' ?>" data-step-content="1">
                    <div class="mb-3 mt-2">
                        <div class="fw-600 text-primary mb-2" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.05em;">
                            <i class="ph-bold ph-user me-1"></i>Personal Information
                        </div>
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Full Name</label>
                                <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email Address</label>
                                <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Phone Number</label>
                                <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Password</label>
                                <div class="password-field">
                                    <input type="password" name="password" class="form-control password-toggle-input pe-5" required minlength="<?= get_password_min_length() ?>">
                                    <button type="button" class="password-toggle" aria-label="Show password">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"></path>
                                            <circle cx="12" cy="12" r="3"></circle>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Confirm Password</label>
                                <div class="password-field">
                                    <input type="password" name="confirm_password" class="form-control password-toggle-input pe-5" required>
                                    <button type="button" class="password-toggle" aria-label="Show password">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"></path>
                                            <circle cx="12" cy="12" r="3"></circle>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 d-flex justify-content-between align-items-center">
                        <div></div>
                        <button type="button" class="btn btn-primary next-step-btn">
                            Next <i class="ph-bold ph-arrow-right ms-1"></i>
                        </button>
                    </div>
                </div>

                <div class="step-content <?= $activeStep === 2 ? 'active' : '' ?>" data-step-content="2">
                    <div class="mb-3">
                        <div class="fw-600 text-primary mb-2" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.05em;">
                            <i class="ph-bold ph-shield-check me-1"></i>Guarantor Information
                        </div>
                        <p class="text-muted" style="font-size:.8rem;">Your guarantor must be an existing active member of Beulah Cooperative Society.</p>
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Guarantor Full Name</label>
                                <input type="text" name="guarantor_name" class="form-control" value="<?= htmlspecialchars($_POST['guarantor_name'] ?? '') ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Guarantor Phone</label>
                                <input type="text" name="guarantor_phone" class="form-control" value="<?= htmlspecialchars($_POST['guarantor_phone'] ?? '') ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Guarantor Coop No.</label>
                                <input type="text" name="guarantor_coop_no" class="form-control" placeholder="e.g. BC/001" value="<?= htmlspecialchars($_POST['guarantor_coop_no'] ?? '') ?>" required>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 d-flex justify-content-between align-items-center">
                        <button type="button" class="btn btn-outline-secondary prev-step-btn">
                            <i class="ph-bold ph-arrow-left me-1"></i>Back
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="ph-bold ph-user-plus me-1"></i>Register
                        </button>
                    </div>
                </div>

                <div class="auth-footer text-center mt-3">
                    Already have an account? <a href="login.php">Sign in</a>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
function setupPasswordToggles() {
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
}

function showStep(step) {
    document.querySelectorAll('.step-content').forEach(function (content) {
        content.classList.toggle('active', Number(content.getAttribute('data-step-content')) === step);
    });

    document.querySelectorAll('.step-pill').forEach(function (pill) {
        pill.classList.toggle('active', Number(pill.getAttribute('data-step')) === step);
    });

    const currentStepInput = document.getElementById('currentStepInput');
    if (currentStepInput) {
        currentStepInput.value = String(step);
    }
}

function validateCurrentStep(step) {
    const container = document.querySelector('.step-content.active');
    if (!container) return true;

    const fields = Array.from(container.querySelectorAll('input[required]'));
    for (const field of fields) {
        if (!field.checkValidity()) {
            field.reportValidity();
            field.focus();
            return false;
        }
    }

    return true;
}

setupPasswordToggles();

document.querySelectorAll('.next-step-btn').forEach(function (button) {
    button.addEventListener('click', function () {
        const currentStep = Number(document.getElementById('currentStepInput').value || 1);
        if (!validateCurrentStep(currentStep)) {
            return;
        }
        showStep(currentStep + 1);
    });
});

document.querySelectorAll('.prev-step-btn').forEach(function (button) {
    button.addEventListener('click', function () {
        const currentStep = Number(document.getElementById('currentStepInput').value || 2);
        showStep(currentStep - 1);
    });
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
