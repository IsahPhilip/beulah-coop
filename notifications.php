<?php
// notifications.php - User Notifications Center
require_once 'includes/auth.php';

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Handle mark as read
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? null;
    
    if ($action === 'mark_read') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $user_id]);
            $success = 'Notification marked as read.';
        }
    } elseif ($action === 'mark_all_read') {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $success = 'All notifications marked as read.';
    }
}

// Fetch notifications
$typeFilter = $_GET['type'] ?? 'all';
$typeOptions = ['all', 'transaction', 'loan', 'announcement', 'system', 'meeting'];
if (!in_array($typeFilter, $typeOptions)) $typeFilter = 'all';

$query = "SELECT * FROM notifications WHERE user_id = ?";
$params = [$user_id];

if ($typeFilter !== 'all') {
    $query .= " AND type = ?";
    $params[] = $typeFilter;
}

$query .= " ORDER BY created_at DESC LIMIT 50";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$notifications = $stmt->fetchAll();

// Get unread count
$unreadStmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
$unreadStmt->execute([$user_id]);
$unreadCount = $unreadStmt->fetch()['count'];

// Get stats
$statsQuery = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread,
        SUM(CASE WHEN type = 'loan' THEN 1 ELSE 0 END) as loan,
        SUM(CASE WHEN type = 'transaction' THEN 1 ELSE 0 END) as transaction,
        SUM(CASE WHEN type = 'announcement' THEN 1 ELSE 0 END) as announcement
    FROM notifications
    WHERE user_id = ?
";
$statsStmt = $pdo->prepare($statsQuery);
$statsStmt->execute([$user_id]);
$stats = $statsStmt->fetch();

$pageTitle = 'Notifications - Beulah Coop';
$useDashboardLayout = true;
?>
<?php include 'includes/header.php'; ?>
<div class="dash-grid">
    <div class="dash-section-head">
        <h2 class="dash-title">Notifications</h2>
        <?php if ($unreadCount > 0): ?>
            <form method="POST" style="display: inline;">
                <input type="hidden" name="action" value="mark_all_read">
                <button type="submit" class="btn btn-outline-primary">Mark All as Read</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php elseif ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <!-- Notification Summary Cards -->
    <div class="dash-cards">
        <div class="dash-card">
            <div class="dash-card-label">Total Notifications</div>
            <div class="dash-card-value"><?= $stats['total'] ?? 0 ?></div>
        </div>
        <div class="dash-card">
            <div class="dash-card-label">Unread</div>
            <div class="dash-card-value text-warning"><?= $stats['unread'] ?? 0 ?></div>
        </div>
        <div class="dash-card">
            <div class="dash-card-label">Loan Updates</div>
            <div class="dash-card-value text-info"><?= $stats['loan'] ?? 0 ?></div>
        </div>
        <div class="dash-card">
            <div class="dash-card-label">Announcements</div>
            <div class="dash-card-value text-primary"><?= $stats['announcement'] ?? 0 ?></div>
        </div>
    </div>

    <!-- Type Filter Tabs -->
    <div class="profile-tabs">
        <?php foreach ($typeOptions as $opt): ?>
            <a href="?type=<?= $opt ?>" class="profile-tab<?= $typeFilter === $opt ? ' active' : '' ?>">
                <?= ucfirst($opt) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Notifications List -->
    <div class="dash-panel">
        <div class="dash-panel-title">Your Notifications</div>
        <div class="p-3">
            <?php if (empty($notifications)): ?>
                <div class="text-center text-muted py-5">
                    <i class="bi bi-bell-slash" style="font-size: 3rem;"></i>
                    <p class="mt-3">No notifications yet.</p>
                </div>
            <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($notifications as $notification): ?>
                        <div class="list-group-item p-3 mb-2 rounded <?= !$notification['is_read'] ? 'bg-primary-soft border-primary' : '' ?>" style="border: 1px solid var(--gray-200);">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="flex-grow-1">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <i class="bi bi-<?= $notification['type'] === 'loan' ? 'cash-coin' : ($notification['type'] === 'transaction' ? 'arrow-left-right' : ($notification['type'] === 'announcement' ? 'megaphone' : ($notification['type'] === 'meeting' ? 'calendar-event' : 'gear'))) ?> text-<?= $notification['type'] === 'loan' ? 'info' : ($notification['type'] === 'transaction' ? 'success' : ($notification['type'] === 'announcement' ? 'primary' : 'secondary')) ?>"></i>
                                        <h6 class="mb-0 <?= !$notification['is_read'] ? 'fw-bold' : '' ?>"><?= htmlspecialchars($notification['title']) ?></h6>
                                        <?php if (!$notification['is_read']): ?>
                                            <span class="badge bg-primary">New</span>
                                        <?php endif; ?>
                                        <span class="badge bg-<?= $notification['type'] === 'loan' ? 'info' : ($notification['type'] === 'transaction' ? 'success' : ($notification['type'] === 'announcement' ? 'primary' : 'secondary')) ?>">
                                            <?= ucfirst($notification['type']) ?>
                                        </span>
                                    </div>
                                    <p class="text-muted mb-2"><?= htmlspecialchars($notification['message']) ?></p>
                                    <div class="d-flex gap-3 small text-muted">
                                        <span><i class="bi bi-clock me-1"></i><?= date('d M Y, H:i', strtotime($notification['created_at'])) ?></span>
                                        <?php if ($notification['action_url']): ?>
                                            <span><a href="<?= htmlspecialchars($notification['action_url']) ?>" class="text-primary"><i class="bi bi-arrow-right me-1"></i>View Details</a></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if (!$notification['is_read']): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="mark_read">
                                        <input type="hidden" name="id" value="<?= $notification['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary">Mark Read</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php include 'includes/footer.php'; ?>