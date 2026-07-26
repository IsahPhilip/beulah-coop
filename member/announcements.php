<?php
// member/announcements.php - View Announcements for Members
require_once '../includes/auth.php';
if ($_SESSION['role'] !== 'member') {
    header("Location: ../login.php");
    exit();
}

// Fetch active announcements for the member
$query = "
    SELECT a.*, u.name as creator_name
    FROM announcements a
    JOIN users u ON a.created_by = u.id
    WHERE a.is_active = 1
    AND (a.target_audience = 'all' OR a.target_audience = 'member')
    AND (a.expires_at IS NULL OR a.expires_at >= CURDATE())
    ORDER BY a.created_at DESC
";
$stmt = $pdo->prepare($query);
$stmt->execute();
$announcements = $stmt->fetchAll();

$pageTitle = 'Announcements - Beulah Coop';
$useDashboardLayout = true;
?>
<?php include '../includes/header.php'; ?>
<div class="dash-grid">
    <div class="dash-section-head">
        <h2 class="dash-title">Announcements</h2>
    </div>

    <div class="dash-panel">
        <div class="dash-panel-title">Latest Announcements</div>
        <div class="p-3">
            <?php if (empty($announcements)): ?>
                <div class="text-center text-muted py-5">
                    <i class="ph-bold ph-megaphone" style="font-size: 3rem;"></i>
                    <p class="mt-3">No announcements at this time.</p>
                </div>
            <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($announcements as $announcement): ?>
                        <div class="list-group-item p-3 mb-2 rounded" style="border: 1px solid var(--gray-200);">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="flex-grow-1">
                                    <div class="d-flex align-items-center gap-2 mb-2">
                                        <h5 class="mb-0"><?= htmlspecialchars($announcement['title']) ?></h5>
                                        <span class="badge bg-<?= 
                                            $announcement['priority'] === 'urgent' ? 'danger' : 
                                            ($announcement['priority'] === 'high' ? 'warning' : 
                                            ($announcement['priority'] === 'normal' ? 'info' : 'secondary')) 
                                        ?>">
                                            <?= ucfirst($announcement['priority']) ?>
                                        </span>
                                    </div>
                                    <div class="announcement-body mb-3" style="white-space: pre-wrap;"><?= nl2br(htmlspecialchars($announcement['content'])) ?></div>
                                    <div class="d-flex gap-3 small text-muted">
                                        <span><i class="ph-bold ph-user me-1"></i><?= htmlspecialchars($announcement['creator_name']) ?></span>
                                        <span><i class="ph-bold ph-clock me-1"></i><?= date('d M Y, H:i', strtotime($announcement['created_at'])) ?></span>
                                        <?php if ($announcement['expires_at']): ?>
                                            <span><i class="ph-bold ph-calendar-x me-1"></i>Expires: <?= date('d M Y', strtotime($announcement['expires_at'])) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php include '../includes/footer.php'; ?>