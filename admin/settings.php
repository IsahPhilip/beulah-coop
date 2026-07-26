<?php
// admin/settings.php
require_once '../includes/auth.php';
if ($_SESSION['role'] !== 'admin') {
    header("Location: ../login.php"); exit();
}

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = ['coop_name', 'coop_address', 'coop_reg_no', 'coop_phone', 'coop_email'];
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()");
        foreach ($fields as $f) {
            $stmt->execute([$f, trim($_POST[$f] ?? '')]);
        }

        // Logo upload
        if (!empty($_FILES['coop_logo']['tmp_name'])) {
            $file     = $_FILES['coop_logo'];
            $allowed  = ['image/jpeg', 'image/png', 'image/webp'];
            $maxSize  = 2 * 1024 * 1024;
            $finfo    = new finfo(FILEINFO_MIME_TYPE);
            $mime     = $finfo->file($file['tmp_name']);

            if (!in_array($mime, $allowed)) {
                throw new RuntimeException('Logo must be a JPG, PNG, or WebP image.');
            }
            if ($file['size'] > $maxSize) {
                throw new RuntimeException('Logo must be under 2MB.');
            }

            $ext      = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime];
            $logoPath = 'uploads/profiles/coop_logo.' . $ext;
            $dest     = __DIR__ . '/../' . $logoPath;

            // Remove old logos
            foreach (['jpg','png','webp'] as $e) {
                $old = __DIR__ . '/../uploads/profiles/coop_logo.' . $e;
                if (file_exists($old)) @unlink($old);
            }

            if (!move_uploaded_file($file['tmp_name'], $dest)) {
                throw new RuntimeException('Failed to save logo.');
            }
            $stmt->execute(['coop_logo', $logoPath]);
        }

        // Logo delete
        if (isset($_POST['delete_logo'])) {
            foreach (['jpg','png','webp'] as $e) {
                $old = __DIR__ . '/../uploads/profiles/coop_logo.' . $e;
                if (file_exists($old)) @unlink($old);
            }
            $stmt->execute(['coop_logo', '']);
        }

        $pdo->commit();
        // Bust settings cache
        get_settings($pdo);
        log_audit($pdo, $_SESSION['user_id'], 'settings_updated', 'Coop settings updated');
        $success = 'Settings saved successfully.';
    } catch (Throwable $e) {
        $pdo->rollBack();
        $error = $e->getMessage();
    }
}

$s = get_settings($pdo);
?>
<?php
$pageTitle = 'Settings - Beulah Coop';
$useDashboardLayout = true;
?>
<?php include '../includes/header.php'; ?>
<div class="dash-grid">
    <div class="dash-section-head">
        <h2 class="dash-title">Cooperative Settings</h2>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><i class="ph-bold ph-check-circle me-2"></i><?= htmlspecialchars($success) ?></div>
    <?php elseif ($error): ?>
        <div class="alert alert-danger"><i class="ph-bold ph-warning-circle me-2"></i><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <div class="dash-panel mb-4">
            <div class="dash-panel-title"><i class="ph-bold ph-building-office me-2"></i>Society Information</div>
            <div class="p-4">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Society Name</label>
                        <input type="text" name="coop_name" class="form-control" value="<?= htmlspecialchars($s['coop_name'] ?? 'Beulah Multi-Purpose Cooperative Society Ltd.') ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Official Address</label>
                        <textarea name="coop_address" class="form-control" rows="3"><?= htmlspecialchars($s['coop_address'] ?? '') ?></textarea>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Registration Number</label>
                        <input type="text" name="coop_reg_no" class="form-control" placeholder="e.g. CAC/IT/12345" value="<?= htmlspecialchars($s['coop_reg_no'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Phone</label>
                        <input type="text" name="coop_phone" class="form-control" value="<?= htmlspecialchars($s['coop_phone'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Email</label>
                        <input type="email" name="coop_email" class="form-control" value="<?= htmlspecialchars($s['coop_email'] ?? '') ?>">
                    </div>
                </div>
            </div>
        </div>

        <div class="dash-panel mb-4">
            <div class="dash-panel-title"><i class="ph-bold ph-image me-2"></i>Society Logo</div>
            <div class="p-4">
                <?php $logoPath = $s['coop_logo'] ?? ''; ?>
                <?php if ($logoPath && file_exists(__DIR__ . '/../' . $logoPath)): ?>
                    <div class="d-flex align-items-center gap-4 mb-3">
                        <img src="../<?= htmlspecialchars($logoPath) ?>?v=<?= time() ?>" alt="Coop Logo" style="max-height:80px;max-width:200px;border-radius:.5rem;border:1px solid var(--gray-200);padding:6px;">
                        <div>
                            <div class="fw-600 mb-1">Current Logo</div>
                            <button type="submit" name="delete_logo" value="1" class="btn btn-sm btn-outline-danger" onclick="return confirm('Remove logo?')">
                                <i class="ph-bold ph-trash me-1"></i>Remove
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
                <label class="form-label"><?= $logoPath ? 'Replace Logo' : 'Upload Logo' ?></label>
                <input type="file" name="coop_logo" class="form-control" accept="image/jpeg,image/png,image/webp">
                <div class="form-text">JPG, PNG or WebP. Max 2MB. Recommended: 300×100px transparent PNG.</div>
            </div>
        </div>

        <div class="d-flex justify-content-end">
            <button type="submit" class="btn btn-primary px-4">
                <i class="ph-bold ph-floppy-disk me-1"></i>Save Settings
            </button>
        </div>
    </form>
</div>
<?php include '../includes/footer.php'; ?>
