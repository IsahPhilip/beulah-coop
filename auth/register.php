<?php
/**
 * Registration page for new users
 */

require_once '../includes/env.php';
require_once '../config/db.php';
require_once '../includes/functions.php';

$error = '';
$success = '';
$uploadConfig = get_receipt_upload_config();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $coopNo = normalize_coop_no($_POST['coop_no'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirmPassword = trim($_POST['confirm_password'] ?? '');

    if ($coopNo === '' || $name === '' || $email === '' || $password === '' || $confirmPassword === '') {
        $error = 'Please complete all required fields.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } elseif (strlen($password) < get_password_min_length()) {
        $error = 'Password must be at least ' . get_password_min_length() . ' characters long.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE coop_no = ? OR email = ?");
            $stmt->execute([$coopNo, $email]);
            $existing = $stmt->fetch();
            if ($existing) {
                $error = 'A user with that Coop No. or email already exists.';
            } else {
                $receiptPath = null;
                if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] !== UPLOAD_ERR_NO_FILE) {
                    if ($_FILES['receipt']['error'] !== UPLOAD_ERR_OK) {
                        $error = 'There was a problem uploading the receipt file.';
                    } else {
                        $allowed = $uploadConfig['allowed_types'];
                        $fileType = strtolower(pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION));
                        if (!in_array($fileType, $allowed, true)) {
                            $error = 'Receipt file type must be: ' . implode(', ', $allowed) . '.';
                        } elseif ($_FILES['receipt']['size'] > $uploadConfig['max_size']) {
                            $error = 'Receipt file is too large. Maximum size is ' . number_format($uploadConfig['max_size'] / 1048576, 2) . ' MB.';
                        } else {
                            if (!is_dir(__DIR__ . '/../' . $uploadConfig['receipts_dir'])) {
                                mkdir(__DIR__ . '/../' . $uploadConfig['receipts_dir'], 0755, true);
                            }
                            $safeFileName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($_FILES['receipt']['name']));
                            $targetPath = $uploadConfig['receipts_dir'] . uniqid('receipt_', true) . '_' . $safeFileName;
                            if (!move_uploaded_file($_FILES['receipt']['tmp_name'], __DIR__ . '/../' . $targetPath)) {
                                $error = 'Unable to save receipt upload. Please try again.';
                            } else {
                                $receiptPath = $targetPath;
                            }
                        }
                    }
                }

                if ($error === '') {
                    $passwordHash = password_hash($password, PASSWORD_BCRYPT);
                    $insertColumns = ['coop_no', 'name', 'email', 'phone', 'password_hash', 'role'];
                    $insertValues = [$coopNo, $name, $email, $phone, $passwordHash, 'member'];
                    $placeholders = '?, ?, ?, ?, ?, ?';

                    if (table_has_column($pdo, 'users', 'status')) {
                        $insertColumns[] = 'status';
                        $insertValues[] = $receiptPath ? 'receipt_submitted' : 'pending';
                        $placeholders .= ', ?';
                    }
                    if (table_has_column($pdo, 'users', 'receipt_path')) {
                        $insertColumns[] = 'receipt_path';
                        $insertValues[] = $receiptPath;
                        $placeholders .= ', ?';
                    }

                    $sql = sprintf(
                        'INSERT INTO users (%s) VALUES (%s)',
                        implode(', ', $insertColumns),
                        $placeholders
                    );

                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($insertValues);

                    log_audit($pdo, null, 'user_registered', "New registration requested for {$coopNo}");
                    $success = 'Registration submitted successfully. Please allow admin to verify your payment receipt.';
                    if ($receiptPath) {
                        $success .= ' Receipt has been uploaded.';
                    }
                }
            }
        } catch (PDOException $e) {
            if (env('APP_DEBUG', false)) {
                $error = 'Database error: ' . $e->getMessage();
            } else {
                $error = 'Unable to complete registration. Please try again later.';
            }
        }
    }
}

function normalize_coop_no($value) {
    $value = (string)$value;
    $value = str_replace("\xC2\xA0", ' ', $value);
    $value = trim($value);
    $value = preg_replace('/\s+/', ' ', $value);
    return strtoupper($value);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Beulah Coop - Register</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/custom.css" rel="stylesheet">
</head>
<body class="auth-body">
<div class="auth-shell">
    <div class="auth-card">
        <div class="auth-left">
            <div class="auth-brand">Beulah Coop</div>
            <div class="auth-tagline">New member registration</div>
            <div class="auth-badge">Submit payment receipt for approval</div>
            <div class="auth-ornament"></div>
        </div>
        <div class="auth-right">
            <div class="auth-title">Create your account</div>
            <div class="auth-subtitle">Complete the registration and upload your N2000 payment receipt.</div>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <form method="POST" action="" enctype="multipart/form-data">
                <div class="mb-3">
                    <label class="form-label">Coop No. (e.g. BC01)</label>
                    <input type="text" name="coop_no" class="form-control" required value="<?= htmlspecialchars($_POST['coop_no'] ?? '') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Full Name</label>
                    <input type="text" name="name" class="form-control" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Confirm Password</label>
                    <input type="password" name="confirm_password" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Upload Receipt (optional)</label>
                    <input type="file" name="receipt" class="form-control" accept=".jpeg,.jpg,.png,.pdf">
                    <div class="form-text">Upload proof of N2000 payment. Payment verification is done by admin.</div>
                </div>
                <button type="submit" class="btn btn-primary w-100">Submit Registration</button>
                <div class="auth-footer d-flex justify-content-between">
                    <a href="login.php" class="text-decoration-none">Back to login</a>
                </div>
            </form>
        </div>
    </div>
</div>
</body>
</html>
