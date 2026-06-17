<?php
// admin/announcements.php - Announcement Management
require_once '../includes/auth.php';
if ($_SESSION['role'] === 'member') {
    header("Location: ../member/dashboard.php");
    exit();
}
if ($_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$error = '';
$success = '';

// Handle announcement actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? null;
    
    if ($action === 'create') {
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $targetAudience = $_POST['target_audience'] ?? 'all';
        $priority = $_POST['priority'] ?? 'normal';
        $expiresAt = $_POST['expires_at'] ?? null;
        
        if (empty($title) || empty($content)) {
            $error = 'Title and content are required.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO announcements (title, content, created_by, target_audience, priority, expires_at) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$title, $content, $_SESSION['user_id'], $targetAudience, $priority, $expiresAt]);
            
            // Create notification for all users in target audience
            $targetUsers = "SELECT id FROM users WHERE role = ?";
            if ($targetAudience === 'all') {
                $targetUsers = "SELECT id FROM users WHERE id != ?";
                $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, action_url) SELECT id, ?, ?, 'announcement', ? FROM users WHERE id != ?");
                $stmt->execute([$title, substr($content, 0, 200) . '...', '/admin/announcements.php', $_SESSION['user_id']]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, action_url) SELECT id, ?, ?, 'announcement', ? FROM users WHERE role = ?");
                $stmt->execute([$title, substr($content, 0, 200) . '...', '/admin/announcements.php', $targetAudience]);
            }
            
            log_audit($pdo, $_SESSION['user_id'], 'announcement_created', "Created announcement: {$title}");
            $success = 'Announcement created and notifications sent.';
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE announcements SET is_active = NOT is_active WHERE id = ?");
            $stmt->execute([$id]);
            $success = 'Announcement status updated.';
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("DELETE FROM announcements WHERE id = ?");
            $stmt->execute([$id]);
            $success = 'Announcement deleted.';
        }
    }
}

// Fetch announcements
$priorityFilter = $_GET['priority'] ?? 'all';
$priorityOptions = ['all', 'low', 'normal', 'high', 'urgent'];
if (!in_array($priorityFilter, $priorityOptions)) $priorityFilter = 'all';

$query = "
    SELECT a.*, u.name as creator_name,
           (SELECT COUNT(*) FROM notifications WHERE action_url LIKE '%announcements%' AND title = a.title) as notification_count
    FROM announcements a
    JOIN users u ON a.created_by = u.id
    WHERE 1=1
";
$params = [];

if ($priorityFilter !== 'all') {
    $query .= " AND a.priority = ?";
    $params[] = $priorityFilter;
}

$query .= " ORDER BY a.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$announcements = $stmt->fetchAll();

// Get stats
$statsQuery = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN priority = 'urgent' THEN 1 ELSE 0 END) as urgent,
        SUM(CASE WHEN priority = 'high' THEN 1 ELSE 0 END) as high
    FROM announcements
";
$stats = $pdo->query($statsQuery)->fetch();
?>

<?php
$pageTitle = 'Announcements - Beulah Coop';
$useDashboardLayout = true;
?>
<?php include '../includes/header.php'; ?>
<div class="dash-grid">
    <div class="dash-section-head">
        <h2 class="dash-title">Announcements</h2>
        <div class="dash-section-actions">
            <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#addAnnouncementModal">New Announcement</button>
        </div>
    </div>

    <div id="announcementAlerts"></div>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php elseif ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <!-- Announcement Summary Cards -->
    <div class="dash-cards">
        <div class="dash-card">
            <div class="dash-card-label">Total Announcements</div>
            <div class="dash-card-value"><?= $stats['total'] ?? 0 ?></div>
        </div>
        <div class="dash-card">
            <div class="dash-card-label">Active</div>
            <div class="dash-card-value text-success"><?= $stats['active'] ?? 0 ?></div>
        </div>
        <div class="dash-card">
            <div class="dash-card-label">Urgent</div>
            <div class="dash-card-value text-danger"><?= $stats['urgent'] ?? 0 ?></div>
        </div>
        <div class="dash-card">
            <div class="dash-card-label">High Priority</div>
            <div class="dash-card-value text-warning"><?= $stats['high'] ?? 0 ?></div>
        </div>
    </div>

    <!-- Priority Filter Tabs -->
    <div class="profile-tabs">
        <?php foreach ($priorityOptions as $opt): ?>
            <a href="?priority=<?= $opt ?>" class="profile-tab<?= $priorityFilter === $opt ? ' active' : '' ?>">
                <?= ucfirst($opt) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Announcements List -->
    <div class="dash-panel">
        <div class="dash-panel-title">Announcements</div>
        <div class="p-3">
            <?php if (empty($announcements)): ?>
                <div class="text-center text-muted py-5">
                    <i class="bi bi-megaphone" style="font-size: 3rem;"></i>
                    <p class="mt-3">No announcements yet. Create your first announcement!</p>
                </div>
            <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($announcements as $announcement): ?>
                        <div class="list-group-item list-group-item-action p-3 mb-2 rounded <?= !$announcement['is_active'] ? 'opacity-50' : '' ?>" style="border: 1px solid var(--gray-200);">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="flex-grow-1">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <h6 class="mb-0"><?= htmlspecialchars($announcement['title']) ?></h6>
                                        <span class="badge bg-<?= $announcement['priority'] === 'urgent' ? 'danger' : ($announcement['priority'] === 'high' ? 'warning' : ($announcement['priority'] === 'normal' ? 'info' : 'secondary')) ?>">
                                            <?= ucfirst($announcement['priority']) ?>
                                        </span>
                                        <?php if ($announcement['is_active']): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Inactive</span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-muted mb-2"><?= htmlspecialchars(substr($announcement['content'], 0, 150)) ?>...</p>
                                    <div class="d-flex gap-3 small text-muted">
                                        <span><i class="bi bi-person me-1"></i><?= htmlspecialchars($announcement['creator_name']) ?></span>
                                        <span><i class="bi bi-clock me-1"></i><?= date('d M Y, H:i', strtotime($announcement['created_at'])) ?></span>
                                        <span><i class="bi bi-people me-1"></i><?= ucfirst($announcement['target_audience']) ?></span>
                                        <?php if ($announcement['expires_at']): ?>
                                            <span><i class="bi bi-calendar-x me-1"></i>Expires: <?= date('d M Y', strtotime($announcement['expires_at'])) ?></span>
                                        <?php endif; ?>
                                        <span><i class="bi bi-bell me-1"></i><?= $announcement['notification_count'] ?> notified</span>
                                    </div>
                                </div>
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="dropdown">
                                        <i class="bi bi-three-dots-vertical"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="toggle">
                                                <input type="hidden" name="id" value="<?= $announcement['id'] ?>">
                                                <button type="submit" class="dropdown-item">
                                                    <i class="bi bi-toggle-on me-2"></i><?= $announcement['is_active'] ? 'Deactivate' : 'Activate' ?>
                                                </button>
                                            </form>
                                        </li>
                                        <li>
                                            <button class="dropdown-item" onclick="viewAnnouncement(<?= $announcement['id'] ?>)">
                                                <i class="bi bi-eye me-2"></i>View Full
                                            </button>
                                        </li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this announcement?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= $announcement['id'] ?>">
                                                <button type="submit" class="dropdown-item text-danger">
                                                    <i class="bi bi-trash me-2"></i>Delete
                                                </button>
                                            </form>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add Announcement Modal -->
<div class="modal fade" id="addAnnouncementModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">New Announcement</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="create">
                    <div class="d-grid gap-3">
                        <div>
                            <label class="form-label">Title</label>
                            <input type="text" name="title" class="form-control" required>
                        </div>
                        <div>
                            <label class="form-label">Content</label>
                            <textarea name="content" class="form-control" rows="6" required></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <label class="form-label">Priority</label>
                                <select name="priority" class="form-select">
                                    <option value="low">Low</option>
                                    <option value="normal" selected>Normal</option>
                                    <option value="high">High</option>
                                    <option value="urgent">Urgent</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Target Audience</label>
                                <select name="target_audience" class="form-select">
                                    <option value="all">All Users</option>
                                    <option value="admin">Admins Only</option>
                                    <option value="member">Members Only</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="form-label">Expire Date (optional)</label>
                            <input type="date" name="expires_at" class="form-control">
                        </div>
                    </div>
                    <div class="mt-3 d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-megaphone me-1"></i>Publish Announcement
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- View Announcement Modal -->
<div class="modal fade" id="viewAnnouncementModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Announcement Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="announcementContent">
                <!-- Populated by JavaScript -->
            </div>
        </div>
    </div>
</div>

<script>
function viewAnnouncement(id) {
    // In a real implementation, you'd fetch the full announcement via AJAX
    // For now, we'll just show a placeholder
    const content = document.getElementById('announcementContent');
    content.innerHTML = '<div class="text-center"><div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div></div>';
    
    fetch('api/announcement.php?id=' + id)
        .then(res => res.json())
        .then(data => {
            content.innerHTML = `
                <h4>${data.title}</h4>
                <div class="mb-3">
                    <span class="badge bg-${data.priority === 'urgent' ? 'danger' : 'info'}">${data.priority}</span>
                    <span class="text-muted ms-2">${new Date(data.created_at).toLocaleDateString()}</span>
                </div>
                <div class="announcement-body" style="white-space: pre-wrap;">${data.content}</div>
            `;
        })
        .catch(err => {
            content.innerHTML = '<div class="alert alert-danger">Failed to load announcement.</div>';
        });
    
    new bootstrap.Modal(document.getElementById('viewAnnouncementModal')).show();
}
</script>
<?php include '../includes/footer.php'; ?>