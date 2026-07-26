<?php
// admin/profile.php - Admin Profile Page
require_once '../includes/auth.php';
if ($_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$success_msg = '';
$error_msg = '';

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    header("Location: index.php");
    exit();
}

// Handle profile photo upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_photo'])) {
    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
        $uploadConfig = get_upload_config();
        $allowedTypes = array_map(fn($t) => 'image/' . trim($t), $uploadConfig['allowed_types']);
        $fileType = $_FILES['profile_photo']['type'];
        $fileSize = $_FILES['profile_photo']['size'];

        if (!in_array($fileType, $allowedTypes)) {
            $error_msg = 'Invalid file type. Only ' . implode(', ', $uploadConfig['allowed_types']) . ' are allowed.';
        } elseif ($fileSize > $uploadConfig['max_size']) {
            $error_msg = 'File size must be less than ' . round($uploadConfig['max_size'] / 1024 / 1024, 1) . 'MB.';
        } else {
            $uploadDir = __DIR__ . '/../' . $uploadConfig['profiles_dir'];
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $extension = pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION);
            $filename = 'profile_' . $user_id . '_' . time() . '.' . $extension;
            $webPath = $uploadConfig['profiles_dir'] . $filename;
            if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $uploadDir . $filename)) {
                if (!empty($user['profile_photo']) && file_exists(__DIR__ . '/../' . $user['profile_photo'])) {
                    unlink(__DIR__ . '/../' . $user['profile_photo']);
                }
                $pdo->prepare("UPDATE users SET profile_photo = ? WHERE id = ?")->execute([$webPath, $user_id]);
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

// Handle profile photo deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_photo'])) {
    if (!empty($user['profile_photo']) && file_exists(__DIR__ . '/../' . $user['profile_photo'])) {
        unlink(__DIR__ . '/../' . $user['profile_photo']);
    }
    $pdo->prepare("UPDATE users SET profile_photo = NULL WHERE id = ?")->execute([$user_id]);
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

    if (empty($full_name) || empty($email)) {
        $error_msg = 'Full name and email are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_msg = 'Please enter a valid email address.';
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $user_id]);
        if ($stmt->fetch()) {
            $error_msg = 'This email is already registered to another account.';
        } else {
            $pdo->prepare("UPDATE users SET name = ?, email = ?, phone = ? WHERE id = ?")->execute([$full_name, $email, $phone, $user_id]);
            $_SESSION['name'] = $full_name;
            log_audit($pdo, $user_id, 'profile_update', 'Profile information updated');
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

    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error_msg = 'All password fields are required.';
    } elseif ($new_password !== $confirm_password) {
        $error_msg = 'New passwords do not match.';
    } elseif (strlen($new_password) < $minLength) {
        $error_msg = 'Password must be at least ' . $minLength . ' characters long.';
    } else {
        $passwordColumn = isset($user['password_hash']) ? 'password_hash' : 'password';
        if (password_verify($current_password, $user[$passwordColumn])) {
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([password_hash($new_password, PASSWORD_DEFAULT), $user_id]);
            log_audit($pdo, $user_id, 'password_change', 'Password changed successfully');
            $success_msg = 'Password changed successfully!';
        } else {
            $error_msg = 'Current password is incorrect.';
        }
    }
}

$user_name  = $user['name'] ?? 'Admin';
$user_email = $user['email'] ?? '';
$user_phone = $user['phone'] ?? '';
$user_photo = $user['profile_photo'] ?? '';

$created_date = new DateTime($user['created_at'] ?? 'now');
$now = new DateTime();
$account_age = $created_date->diff($now);

$activeTab = $_GET['tab'] ?? 'personal';
if (!in_array($activeTab, ['personal', 'security', 'activity'])) $activeTab = 'personal';

$pageTitle = 'My Profile - Beulah Coop';
$useDashboardLayout = true;
?>
<?php include '../includes/header.php'; ?>
<style>
.profile-tabs { display:flex; gap:.5rem; background:var(--gray-100); border-radius:1rem; padding:.5rem; margin-bottom:1.5rem; }
.profile-tab { flex:1; padding:.75rem 1rem; border:none; background:transparent; border-radius:.75rem; font-weight:600; font-size:.875rem; color:var(--gray-600); cursor:pointer; transition:all .2s; text-decoration:none; display:flex; align-items:center; justify-content:center; gap:.5rem; }
.profile-tab:hover { background:var(--primary-soft); color:var(--primary); }
.profile-tab.active { background:var(--primary); color:#fff; box-shadow:0 4px 12px rgba(97,4,95,.3); }
.tab-content { display:none; }
.tab-content.active { display:block; }
.profile-photo-preview { width:120px; height:120px; border-radius:50%; object-fit:cover; border:4px solid var(--primary); box-shadow:var(--shadow-lg); }
.profile-photo-actions { margin-top:1rem; display:flex; gap:.75rem; justify-content:center; }
</style>

<div class="dash-grid">
    <div class="dash-section-head">
        <div>
            <h2 class="dash-title">My Profile</h2>
            <div class="dash-meta">Manage your account settings</div>
        </div>
        <div class="dash-pill">Administrator</div>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success_msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error_msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="profile-tabs">
        <a href="?tab=personal" class="profile-tab<?= $activeTab === 'personal' ? ' active' : '' ?>">
            <i class="ph-bold ph-user-circle"></i> Personal Info
        </a>
        <a href="?tab=security" class="profile-tab<?= $activeTab === 'security' ? ' active' : '' ?>">
            <i class="ph-bold ph-shield-lock"></i> Security
        </a>
        <a href="?tab=activity" class="profile-tab<?= $activeTab === 'activity' ? ' active' : '' ?>">
            <i class="ph-bold ph-clock-clockwise"></i> Activity
        </a>
    </div>

    <!-- Personal Info Tab -->
    <div class="tab-content<?= $activeTab === 'personal' ? ' active' : '' ?>">
        <div class="dash-split">
            <div class="dash-card-panel">
                <div class="dash-card-header p-3"><h5 class="mb-0"><i class="ph-bold ph-image me-2"></i>Profile Photo</h5></div>
                <div class="profile-photo-section text-center p-3">
                    <?php if (!empty($user_photo) && file_exists(__DIR__ . '/../' . $user_photo)): ?>
                        <img src="../<?= htmlspecialchars($user_photo) ?>" alt="Profile Photo" class="profile-photo-preview">
                    <?php else: ?>
                        <div class="dash-avatar mx-auto" style="width:120px;height:120px;font-size:48px;border:4px solid var(--primary);">
                            <?= strtoupper(substr($user_name, 0, 1)) ?>
                        </div>
                    <?php endif; ?>
                    <div class="profile-photo-actions">
                        <form method="POST" enctype="multipart/form-data" style="display:inline;">
                            <label class="btn btn-outline-primary" for="photo-upload"><i class="ph-bold ph-camera me-1"></i>Upload Photo</label>
                            <input type="file" id="photo-upload" name="profile_photo" accept="image/*" style="display:none;" onchange="this.form.submit()">
                            <input type="hidden" name="upload_photo" value="1">
                        </form>
                        <?php if (!empty($user_photo)): ?>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Remove profile photo?');">
                                <input type="hidden" name="delete_photo" value="1">
                                <button type="submit" class="btn btn-outline-danger"><i class="ph-bold ph-trash me-1"></i>Remove</button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <small class="text-muted">Max 5MB. JPEG, PNG, GIF, WebP</small>
                </div>

                <div class="dash-card-header p-3"><h5 class="mb-0"><i class="ph-bold ph-user-circle me-2"></i>Personal Information</h5></div>
                <div class="p-3">
                    <form method="POST">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Full Name *</label>
                                <input type="text" class="form-control" name="full_name" value="<?= htmlspecialchars($user_name) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email Address *</label>
                                <input type="email" class="form-control" name="email" value="<?= htmlspecialchars($user_email) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Phone Number</label>
                                <input type="tel" class="form-control" name="phone" value="<?= htmlspecialchars($user_phone) ?>">
                            </div>
                            <div class="col-12">
                                <button type="submit" name="update_profile" class="btn btn-primary"><i class="ph-bold ph-floppy-disk me-1"></i>Save Changes</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="dash-summary">
                <div class="dash-summary-card text-center">
                    <h6 class="mb-1"><?= htmlspecialchars($user_name) ?></h6>
                    <small class="text-muted">Admin since <?= $created_date->format('M Y') ?></small>
                </div>
                <div class="dash-summary-card">
                    <div class="dash-summary-label">Role</div>
                    <div class="dash-summary-value text-primary"><i class="ph-bold ph-shield-check me-1"></i>Administrator</div>
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
    <div class="tab-content<?= $activeTab === 'security' ? ' active' : '' ?>">
        <div class="dash-card-panel" style="max-width:500px;">
            <div class="dash-card-header p-3"><h5 class="mb-0"><i class="ph-bold ph-key me-2"></i>Change Password</h5></div>
            <div class="p-3">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Current Password</label>
                        <input type="password" class="form-control" name="current_password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New Password</label>
                        <input type="password" class="form-control" name="new_password" minlength="8" required>
                        <div class="form-text">Minimum 8 characters</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" class="form-control" name="confirm_password" required>
                    </div>
                    <button type="submit" name="change_password" class="btn btn-warning"><i class="ph-bold ph-key me-1"></i>Change Password</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Activity Tab -->
    <div class="tab-content<?= $activeTab === 'activity' ? ' active' : '' ?>">
        <div class="dash-card-panel">
            <div class="dash-card-header p-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="ph-bold ph-clock-clockwise me-2"></i>Recent Activity</h5>
                <span class="badge bg-primary-soft">Last 10 entries</span>
            </div>
            <div class="dash-panel-table px-3 pb-3">
                <?php
                $stmt = $pdo->prepare("SELECT * FROM audit_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
                $stmt->execute([$user_id]);
                $activities = $stmt->fetchAll();
                ?>
                <?php if (empty($activities)): ?>
                    <div class="text-center text-muted py-4"><i class="ph-bold ph-tray" style="font-size:2rem;"></i><p class="mb-0 mt-2">No recent activity</p></div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead><tr><th>Date & Time</th><th>Action</th><th>Details</th><th>IP Address</th></tr></thead>
                            <tbody>
                                <?php foreach ($activities as $a): ?>
                                    <tr>
                                        <td><?= date('d M Y, h:i A', strtotime($a['created_at'])) ?></td>
                                        <td><span class="badge bg-primary-soft"><?= htmlspecialchars(str_replace('_', ' ', $a['action'])) ?></span></td>
                                        <td><?= htmlspecialchars($a['details'] ?? '-') ?></td>
                                        <td><code><?= htmlspecialchars($a['ip_address'] ?? 'unknown') ?></code></td>
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.alert').forEach(function(alert) {
        setTimeout(function() { new bootstrap.Alert(alert).close(); }, 5000);
    });
});
</script>
<?php include '../includes/footer.php'; ?>
