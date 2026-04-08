<?php
// member/profile.php - Member Profile Page with Tabs
require_once '../includes/auth.php';
if ($_SESSION['role'] === 'admin') {
    header("Location: ../admin/index.php");
    exit();
}
if ($_SESSION['role'] !== 'member') {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$success_msg = '';
$error_msg = '';

// Fetch user profile data from users table
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    header("Location: dashboard.php");
    exit();
}

// Check for 2FA column
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
$twofaEnabled = $twofaColumn && !empty($user[$twofaColumn]);

// Handle profile picture upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_photo'])) {
    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
        $uploadConfig = get_upload_config();
        $allowedTypes = array_map(function($type) {
            return 'image/' . trim($type);
        }, $uploadConfig['allowed_types']);
        $maxSize = $uploadConfig['max_size'];
        
        $fileType = $_FILES['profile_photo']['type'];
        $fileSize = $_FILES['profile_photo']['size'];
        
        if (!in_array($fileType, $allowedTypes)) {
            $error_msg = 'Invalid file type. Only ' . implode(', ', $uploadConfig['allowed_types']) . ' are allowed.';
        } elseif ($fileSize > $maxSize) {
            $error_msg = 'File size must be less than ' . round($maxSize / 1024 / 1024, 1) . 'MB.';
        } else {
            // Create uploads directory if it doesn't exist
            $uploadDir = __DIR__ . '/../' . $uploadConfig['profiles_dir'];
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            // Generate unique filename
            $extension = pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION);
            $filename = 'profile_' . $user_id . '_' . time() . '.' . $extension;
            $targetPath = $uploadDir . $filename;
            $webPath = $uploadConfig['profiles_dir'] . $filename;
            
            if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $targetPath)) {
                // Delete old profile picture if exists
                if (!empty($user['profile_photo']) && file_exists(__DIR__ . '/../' . $user['profile_photo'])) {
                    unlink(__DIR__ . '/../' . $user['profile_photo']);
                }
                
                // Update database
                $stmt = $pdo->prepare("UPDATE users SET profile_photo = ? WHERE id = ?");
                $stmt->execute([$webPath, $user_id]);
                
                // Refresh user data
                $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch();
                
                log_audit($pdo, $user_id, 'profile_photo_upload', 'Profile photo uploaded');
                $success_msg = 'Profile photo uploaded successfully!';
            } else {
                $error_msg = 'Failed to upload photo. Please try again.';
            }
        }
    } else {
        $error_msg = 'No file selected or upload error occurred.';
    }
}

// Handle profile picture deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_photo'])) {
    if (!empty($user['profile_photo']) && file_exists(__DIR__ . '/../' . $user['profile_photo'])) {
        unlink(__DIR__ . '/../' . $user['profile_photo']);
    }
    
    $stmt = $pdo->prepare("UPDATE users SET profile_photo = NULL WHERE id = ?");
    $stmt->execute([$user_id]);
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    
    log_audit($pdo, $user_id, 'profile_photo_delete', 'Profile photo deleted');
    $success_msg = 'Profile photo removed successfully!';
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    // Validation
    if (empty($full_name) || empty($email)) {
        $error_msg = 'Full name and email are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_msg = 'Please enter a valid email address.';
    } else {
        // Check if email is already taken by another user
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $user_id]);
        if ($stmt->fetch()) {
            $error_msg = 'This email is already registered to another account.';
        } else {
            // Update profile
            $stmt = $pdo->prepare("
                UPDATE users 
                SET name = ?, email = ?, phone = ?
                WHERE id = ?
            ");
            $stmt->execute([$full_name, $email, $phone, $user_id]);

            // Update session name
            $_SESSION['name'] = $full_name;

            // Log audit
            log_audit($pdo, $user_id, 'profile_update', 'Profile information updated');

            // Refresh user data
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();

            $success_msg = 'Profile updated successfully!';
        }
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $minLength = get_password_min_length();

    // Validation
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error_msg = 'All password fields are required.';
    } elseif ($new_password !== $confirm_password) {
        $error_msg = 'New passwords do not match.';
    } elseif (strlen($new_password) < $minLength) {
        $error_msg = 'Password must be at least ' . $minLength . ' characters long.';
    } else {
        // Verify current password
        $passwordColumn = isset($user['password_hash']) ? 'password_hash' : 'password';
        
        if (password_verify($current_password, $user[$passwordColumn])) {
            // Update password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmt->execute([$hashed_password, $user_id]);

            // Log audit
            log_audit($pdo, $user_id, 'password_change', 'Password changed successfully');

            $success_msg = 'Password changed successfully!';
        } else {
            $error_msg = 'Current password is incorrect.';
        }
    }
}

// Handle 2FA toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_2fa'])) {
    if ($twofaColumn) {
        $twofaConfig = get_2fa_config();
        $action = $_POST['2fa_action'] ?? 'toggle';
        
        if ($action === 'enable') {
            // Verify password before enabling 2FA
            $password = $_POST['verify_password'] ?? '';
            $passwordColumn = isset($user['password_hash']) ? 'password_hash' : 'password';
            
            if (empty($password) || !password_verify($password, $user[$passwordColumn])) {
                $error_msg = 'Please enter your correct password to enable 2FA.';
            } else {
                // Check if email is set
                if (empty($user['email'])) {
                    $error_msg = 'Please add an email address to your profile before enabling 2FA.';
                } else {
                    // Generate verification code
                    $codeLength = $twofaConfig['code_length'];
                    $testCode = '';
                    for ($i = 0; $i < $codeLength; $i++) {
                        $testCode .= rand(0, 9);
                    }
                    $_SESSION['2fa_setup_code'] = $testCode;
                    $_SESSION['2fa_setup_expiry'] = time() + $twofaConfig['code_expiry'];
                    
                    // In production, send email here. For now, we'll show the code for testing
                    // mail($user['email'], '2FA Setup Code', "Your verification code is: $testCode");
                    
                    $success_msg = "A test verification code has been generated. Enter it below to confirm 2FA setup. (Code: $testCode)";
                    $_SESSION['2fa_setup_pending'] = true;
                }
            }
        } elseif ($action === 'verify_setup') {
            // Verify the setup code
            if (!isset($_SESSION['2fa_setup_code']) || !isset($_SESSION['2fa_setup_pending'])) {
                $error_msg = 'Setup session expired. Please try again.';
            } elseif (time() > ($_SESSION['2fa_setup_expiry'] ?? 0)) {
                unset($_SESSION['2fa_setup_code'], $_SESSION['2fa_setup_pending']);
                $error_msg = 'Verification code expired. Please try again.';
            } else {
                $enteredCode = $_POST['setup_code'] ?? '';
                if ($enteredCode == $_SESSION['2fa_setup_code']) {
                    // Enable 2FA
                    $stmt = $pdo->prepare("UPDATE users SET {$twofaColumn} = 1 WHERE id = ?");
                    $stmt->execute([$user_id]);
                    
                    log_audit($pdo, $user_id, '2fa_enabled', 'Two-factor authentication enabled');
                    
                    unset($_SESSION['2fa_setup_code'], $_SESSION['2fa_setup_pending']);
                    
                    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                    $stmt->execute([$user_id]);
                    $user = $stmt->fetch();
                    
                    $twofaEnabled = true;
                    $success_msg = 'Two-factor authentication has been enabled successfully!';
                } else {
                    $error_msg = 'Invalid verification code. Please try again.';
                }
            }
        } elseif ($action === 'disable') {
            // Disable 2FA
            $password = $_POST['verify_password'] ?? '';
            $passwordColumn = isset($user['password_hash']) ? 'password_hash' : 'password';
            
            if (empty($password) || !password_verify($password, $user[$passwordColumn])) {
                $error_msg = 'Please enter your correct password to disable 2FA.';
            } else {
                $stmt = $pdo->prepare("UPDATE users SET {$twofaColumn} = 0 WHERE id = ?");
                $stmt->execute([$user_id]);
                
                log_audit($pdo, $user_id, '2fa_disabled', 'Two-factor authentication disabled');
                
                $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch();
                
                $twofaEnabled = false;
                $success_msg = 'Two-factor authentication has been disabled.';
            }
        }
    }
}

// Handle account deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_account'])) {
    $confirm_name = trim($_POST['confirm_name'] ?? '');
    $password = $_POST['delete_password'] ?? '';
    
    if (empty($confirm_name) || empty($password)) {
        $error_msg = 'Please fill in all fields to confirm account deletion.';
    } elseif ($confirm_name !== $user['name']) {
        $error_msg = 'The name entered does not match your account name.';
    } else {
        $passwordColumn = isset($user['password_hash']) ? 'password_hash' : 'password';
        if (!password_verify($password, $user[$passwordColumn])) {
            $error_msg = 'Incorrect password. Account deletion cancelled.';
        } else {
            // Check for outstanding loans
            $balances = get_user_balances($pdo, $user_id);
            if (($balances['outstanding_loan'] ?? 0) > 0) {
                $error_msg = 'Cannot delete account with outstanding loan balance. Please clear your loan first.';
            } else {
                // Delete profile photo if exists
                if (!empty($user['profile_photo']) && file_exists(__DIR__ . '/../' . $user['profile_photo'])) {
                    unlink(__DIR__ . '/../' . $user['profile_photo']);
                }
                
                // Log final audit
                log_audit($pdo, $user_id, 'account_deleted', 'Account deleted by user');
                
                // Delete user (transactions will be handled by foreign key constraints or cascade)
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                
                // Destroy session
                session_destroy();
                
                header("Location: ../login.php?msg=account_deleted");
                exit();
            }
        }
    }
}

// Get user's account statistics
$balances = get_user_balances($pdo, $user_id);

// Calculate account age
$created_date = new DateTime($user['created_at'] ?? 'now');
$now = new DateTime();
$account_age = $created_date->diff($now);

// Get the name column (could be 'name' or 'full_name')
$user_name = $user['name'] ?? $user['full_name'] ?? 'User';
$user_email = $user['email'] ?? '';
$user_phone = $user['phone'] ?? '';
$user_coop_no = $user['coop_no'] ?? '';
$user_photo = $user['profile_photo'] ?? '';

// Determine active tab
$activeTab = $_GET['tab'] ?? 'personal';
$validTabs = ['personal', 'security', 'privacy', 'activity'];
if (!in_array($activeTab, $validTabs)) {
    $activeTab = 'personal';
}

$pageTitle = 'My Profile - Beulah Coop';
$useDashboardLayout = true;
?>
<?php include '../includes/header.php'; ?>
<style>
/* Profile Tabs - Matching Platform Design */
.profile-tabs {
    display: flex;
    gap: 0.5rem;
    background: var(--gray-100);
    border-radius: 1rem;
    padding: 0.5rem;
    margin-bottom: 1.5rem;
}

.profile-tab {
    flex: 1;
    padding: 0.75rem 1rem;
    border: none;
    background: transparent;
    border-radius: 0.75rem;
    font-weight: 600;
    font-size: 0.875rem;
    color: var(--gray-600);
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
}

.profile-tab:hover {
    background: var(--primary-soft);
    color: var(--primary);
}

.profile-tab.active {
    background: var(--primary);
    color: #fff;
    box-shadow: 0 4px 12px rgba(97, 4, 95, 0.3);
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

/* Profile Photo Section */
.profile-photo-section {
    text-align: center;
    padding: 1.5rem;
}

.profile-photo-preview {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    object-fit: cover;
    border: 4px solid var(--primary);
    box-shadow: var(--shadow-lg);
}

.profile-photo-actions {
    margin-top: 1rem;
    display: flex;
    gap: 0.75rem;
    justify-content: center;
}

/* 2FA Status Badges */
.twofa-status {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    border-radius: 9999px;
    font-weight: 600;
    font-size: 0.875rem;
}

.twofa-enabled {
    background: var(--success-light);
    color: #065F46;
}

.twofa-disabled {
    background: var(--gray-200);
    color: var(--gray-600);
}

/* Danger Zone */
.danger-zone {
    border: 2px solid var(--danger);
    border-radius: 1.5rem;
    background: var(--danger-light);
}

.danger-zone .dash-card-header {
    background: rgba(220, 38, 38, 0.1);
    color: var(--danger);
    border-bottom-color: rgba(220, 38, 38, 0.2);
}
</style>

<div class="dash-grid">
    <!-- Profile Header -->
    <div class="dash-section-head">
        <div>
            <h2 class="dash-title">My Profile</h2>
            <div class="dash-meta">Manage your account settings and preferences</div>
        </div>
        <div class="dash-pill">Coop No: <?= htmlspecialchars($user_coop_no) ?></div>
    </div>

    <!-- Alerts -->
    <?php if ($success_msg): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success_msg) ?>
            <button type="button" class="btn-close" data-bs-toggle="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error_msg) ?>
            <button type="button" class="btn-close" data-bs-toggle="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Profile Tabs -->
    <div class="profile-tabs">
        <a href="?tab=personal" class="profile-tab<?= $activeTab === 'personal' ? ' active' : '' ?>">
            <i class="bi bi-person-circle"></i> Personal Info
        </a>
        <a href="?tab=security" class="profile-tab<?= $activeTab === 'security' ? ' active' : '' ?>">
            <i class="bi bi-shield-lock"></i> Security
        </a>
        <a href="?tab=privacy" class="profile-tab<?= $activeTab === 'privacy' ? ' active' : '' ?>">
            <i class="bi bi-eye-slash"></i> Privacy
        </a>
        <a href="?tab=activity" class="profile-tab<?= $activeTab === 'activity' ? ' active' : '' ?>">
            <i class="bi bi-clock-history"></i> Activity
        </a>
    </div>

    <!-- Personal Info Tab -->
    <div class="tab-content<?= $activeTab === 'personal' ? ' active' : '' ?>" id="tab-personal">
        <div class="dash-split">
            <!-- Main Profile Form -->
            <div class="dash-card-panel">
                <!-- Profile Photo Section -->
                <div class="dash-card-header p-3">
                    <h5 class="mb-0"><i class="bi bi-image me-2"></i>Profile Photo</h5>
                </div>
                <div class="profile-photo-section">
                    <?php if (!empty($user_photo) && file_exists(__DIR__ . '/../' . $user_photo)): ?>
                        <img src="../<?= htmlspecialchars($user_photo) ?>" alt="Profile Photo" class="profile-photo-preview">
                    <?php else: ?>
                        <div class="dash-avatar mx-auto" style="width: 120px; height: 120px; font-size: 48px; border: 4px solid var(--dash-primary);">
                            <?= strtoupper(substr($user_name, 0, 1)) ?>
                        </div>
                    <?php endif; ?>
                    <div class="profile-photo-actions">
                        <form method="POST" enctype="multipart/form-data" style="display: inline;">
                            <label class="btn btn-outline-primary" for="photo-upload">
                                <i class="bi bi-camera me-1"></i>Upload Photo
                            </label>
                            <input type="file" id="photo-upload" name="profile_photo" accept="image/*" style="display: none;" onchange="this.form.submit()">
                            <input type="hidden" name="upload_photo" value="1">
                        </form>
                        <?php if (!empty($user_photo)): ?>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to remove your profile photo?');">
                                <input type="hidden" name="delete_photo" value="1">
                                <button type="submit" class="btn btn-outline-danger">
                                    <i class="bi bi-trash me-1"></i>Remove
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <small class="text-muted">Max size: 5MB. Formats: JPEG, PNG, GIF, WebP</small>
                </div>

                <!-- Personal Information Form -->
                <div class="dash-card-header p-3">
                    <h5 class="mb-0"><i class="bi bi-person-circle me-2"></i>Personal Information</h5>
                </div>
                <div class="p-3">
                    <form method="POST" action="">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Full Name *</label>
                                <input type="text" class="form-control" name="full_name" 
                                       value="<?= htmlspecialchars($user_name) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email Address *</label>
                                <input type="email" class="form-control" name="email" 
                                       value="<?= htmlspecialchars($user_email) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Phone Number</label>
                                <input type="tel" class="form-control" name="phone" 
                                       value="<?= htmlspecialchars($user_phone) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Membership Number</label>
                                <input type="text" class="form-control" 
                                       value="<?= htmlspecialchars($user_coop_no) ?>" disabled>
                            </div>
                            <div class="col-12">
                                <button type="submit" name="update_profile" class="btn btn-primary">
                                    <i class="bi bi-save me-1"></i>Save Changes
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Sidebar - Account Summary -->
            <div class="dash-summary">
                <!-- Profile Card -->
                <div class="dash-summary-card text-center">
                    <h6 class="mb-1"><?= htmlspecialchars($user_name) ?></h6>
                    <small class="text-muted">Member since <?= $created_date->format('M Y') ?></small>
                </div>

                <!-- Account Stats -->
                <div class="dash-summary-card">
                    <div class="dash-summary-label">Account Status</div>
                    <div class="dash-summary-value text-success">
                        <i class="bi bi-check-circle-fill me-1"></i>Active
                    </div>
                </div>

                <div class="dash-summary-card">
                    <div class="dash-summary-label">Savings Balance</div>
                    <div class="dash-summary-value"><?= format_money($balances['total_savings'] ?? 0) ?></div>
                </div>

                <div class="dash-summary-card">
                    <div class="dash-summary-label">Outstanding Loan</div>
                    <div class="dash-summary-value text-danger"><?= format_money($balances['outstanding_loan'] ?? 0) ?></div>
                </div>

                <div class="dash-summary-card">
                    <div class="dash-summary-label">Account Age</div>
                    <div class="dash-summary-value"><?= $account_age->y ?>y <?= $account_age->m ?>m</div>
                    <div class="dash-summary-note"><?= $account_age->days ?> total days</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Security Tab -->
    <div class="tab-content<?= $activeTab === 'security' ? ' active' : '' ?>" id="tab-security">
        <div class="row g-4">
            <!-- Password Change -->
            <div class="col-md-6">
                <div class="dash-card-panel">
                    <div class="dash-card-header p-3">
                        <h5 class="mb-0"><i class="bi bi-key me-2"></i>Change Password</h5>
                    </div>
                    <div class="p-3">
                        <form method="POST" action="">
                            <div class="mb-3">
                                <label class="form-label">Current Password</label>
                                <input type="password" class="form-control" name="current_password" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">New Password</label>
                                <input type="password" class="form-control" name="new_password" 
                                       minlength="8" required>
                                <div class="form-text">Minimum 8 characters</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Confirm New Password</label>
                                <input type="password" class="form-control" name="confirm_password" required>
                            </div>
                            <button type="submit" name="change_password" class="btn btn-warning">
                                <i class="bi bi-key me-1"></i>Change Password
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- 2FA Settings -->
            <div class="col-md-6">
                <div class="dash-card-panel">
                    <div class="dash-card-header p-3">
                        <h5 class="mb-0"><i class="bi bi-shield-check me-2"></i>Two-Factor Authentication</h5>
                    </div>
                    <div class="p-3">
                        <div class="mb-4">
                            <p class="text-muted mb-2">Current Status:</p>
                            <?php if ($twofaColumn): ?>
                                <span class="twofa-status <?= $twofaEnabled ? 'twofa-enabled' : 'twofa-disabled' ?>">
                                    <i class="bi bi-<?= $twofaEnabled ? 'check-shield' : 'shield-x' ?>"></i>
                                    <?= $twofaEnabled ? 'Enabled' : 'Disabled' ?>
                                </span>
                            <?php else: ?>
                                <span class="twofa-status twofa-disabled">
                                    <i class="bi bi-info-circle"></i>
                                    Not Configured
                                </span>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($twofaColumn): ?>
                            <?php if ($twofaEnabled): ?>
                                <!-- Disable 2FA Form -->
                                <form method="POST" action="">
                                    <p class="text-muted mb-3">
                                        2FA is currently enabled. Enter your password to disable it.
                                    </p>
                                    <input type="hidden" name="2fa_action" value="disable">
                                    <div class="mb-3">
                                        <label class="form-label">Confirm Password</label>
                                        <input type="password" class="form-control" name="verify_password" required>
                                    </div>
                                    <button type="submit" name="toggle_2fa" class="btn btn-outline-danger">
                                        <i class="bi bi-shield-x me-1"></i>Disable 2FA
                                    </button>
                                </form>
                            <?php else: ?>
                                <!-- Enable 2FA Form -->
                                <?php if (isset($_SESSION['2fa_setup_pending']) && $_SESSION['2fa_setup_pending']): ?>
                                    <form method="POST" action="">
                                        <p class="text-muted mb-3">
                                            Enter the verification code sent to your email to complete 2FA setup.
                                        </p>
                                        <input type="hidden" name="2fa_action" value="verify_setup">
                                        <div class="mb-3">
                                            <label class="form-label">Verification Code</label>
                                            <input type="text" class="form-control text-center fs-4" name="setup_code" 
                                                   maxlength="6" pattern="[0-9]{6}" required autofocus 
                                                   style="letter-spacing: 8px; font-size: 24px;">
                                        </div>
                                        <div class="d-flex gap-2">
                                            <button type="submit" name="toggle_2fa" class="btn btn-success">
                                                <i class="bi bi-check-circle me-1"></i>Verify & Enable
                                            </button>
                                            <a href="?tab=security" class="btn btn-outline-secondary">Cancel</a>
                                        </div>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" action="">
                                        <p class="text-muted mb-3">
                                            Enable 2FA to add an extra layer of security. You'll receive a verification code via email when logging in.
                                        </p>
                                        <input type="hidden" name="2fa_action" value="enable">
                                        <div class="mb-3">
                                            <label class="form-label">Confirm Password</label>
                                            <input type="password" class="form-control" name="verify_password" required>
                                        </div>
                                        <button type="submit" name="toggle_2fa" class="btn btn-outline-success">
                                            <i class="bi bi-shield-check me-1"></i>Enable 2FA
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>
                                Two-factor authentication is not configured for this system. Contact your administrator.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Privacy Tab -->
    <div class="tab-content<?= $activeTab === 'privacy' ? ' active' : '' ?>" id="tab-privacy">
        <div class="dash-card-panel danger-zone">
            <div class="dash-card-header p-3">
                <h5 class="mb-0"><i class="bi bi-exclamation-triangle me-2"></i>Danger Zone</h5>
            </div>
            <div class="p-3">
                <div class="row">
                    <div class="col-md-8">
                        <h6 class="text-danger mb-2">Delete Your Account</h6>
                        <p class="text-muted mb-3">
                            Permanently delete your account and all associated data. This action cannot be undone.
                            Your savings must be withdrawn and loans cleared before deletion.
                        </p>
                        <ul class="text-muted small">
                            <li>All personal information will be removed</li>
                            <li>Transaction history will be anonymized</li>
                            <li>Profile photo will be deleted</li>
                        </ul>
                    </div>
                    <div class="col-md-4 text-end">
                        <button class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#deleteAccountModal">
                            <i class="bi bi-trash me-1"></i>Delete Account
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Activity Tab -->
    <div class="tab-content<?= $activeTab === 'activity' ? ' active' : '' ?>" id="tab-activity">
        <div class="dash-card-panel">
            <div class="dash-card-header p-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Recent Account Activity</h5>
                <span class="badge bg-primary-soft">Last 10 entries</span>
            </div>
            <div class="dash-panel-table px-3 pb-3">
                <?php
                $stmt = $pdo->prepare("
                    SELECT * FROM audit_logs 
                    WHERE user_id = ? 
                    ORDER BY created_at DESC 
                    LIMIT 10
                ");
                $stmt->execute([$user_id]);
                $activities = $stmt->fetchAll();
                ?>
                <?php if (empty($activities)): ?>
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                        <p class="mb-0 mt-2">No recent activity</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Date & Time</th>
                                    <th>Action</th>
                                    <th>Details</th>
                                    <th>IP Address</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($activities as $activity): ?>
                                    <tr>
                                        <td><?= date('d M Y, h:i A', strtotime($activity['created_at'])) ?></td>
                                        <td><span class="badge bg-primary-soft"><?= htmlspecialchars(str_replace('_', ' ', $activity['action'])) ?></span></td>
                                        <td><?= htmlspecialchars($activity['details'] ?? '-') ?></td>
                                        <td><code><?= htmlspecialchars($activity['ip_address'] ?? 'unknown') ?></code></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Delete Account Modal -->
<div class="modal fade" id="deleteAccountModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0">
                <h5 class="modal-title text-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i>Confirm Account Deletion
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <strong>This action cannot be undone!</strong>
                </div>
                <p class="mb-4">
                    You are about to permanently delete your account. Please confirm by entering your details below:
                </p>
                <form method="POST" action="">
                    <div class="mb-3">
                        <label class="form-label">Enter your full name: <?= htmlspecialchars($user_name) ?></label>
                        <input type="text" class="form-control" name="confirm_name" required 
                               placeholder="Type your full name exactly as shown">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Enter your password</label>
                        <input type="password" class="form-control" name="delete_password" required>
                    </div>
                    <div class="d-flex justify-content-between">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="delete_account" class="btn btn-danger">
                            <i class="bi bi-trash me-1"></i>Delete My Account
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Auto-hide alerts after 5 seconds
document.addEventListener('DOMContentLoaded', function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        setTimeout(function() {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        }, 5000);
    });
});
</script>
<?php include '../includes/footer.php'; ?>