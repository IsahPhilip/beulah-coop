<?php
// includes/header.php
$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
$basePath = $scriptDir;
$leaf = strtolower(basename($scriptDir));
if (in_array($leaf, ['admin', 'member', 'auth', 'api', 'includes'], true)) {
    $basePath = rtrim(dirname($scriptDir), '/\\');
}
if ($basePath === '') {
    $basePath = '/';
}

$role = $_SESSION['role'] ?? null;
$name = $_SESSION['name'] ?? 'User';
$pageTitle = $pageTitle ?? 'Beulah Multi-Purpose Cooperative Society Ltd.';
$homeLink = $basePath . '/index.php';
if (!isset($useDashboardLayout)) {
    $useDashboardLayout = false;
}
$currentPath = $_SERVER['SCRIPT_NAME'] ?? '';
$currentPage = strtolower(basename($currentPath));
$isActive = function(array $pages) use ($currentPage) {
    return in_array($currentPage, $pages, true) ? ' active' : '';
};
if ($role === 'admin') {
    $homeLink = $basePath . '/admin/index.php';
} elseif ($role === 'member') {
    $homeLink = $basePath . '/member/dashboard.php';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= $basePath ?>/assets/css/custom.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <?php if (!empty($extraHead)) { echo $extraHead; } ?>
</head>
<body class="<?= $useDashboardLayout ? 'dash-body' : '' ?>">
<?php if ($useDashboardLayout): ?>
    <div class="dash-shell">
        <?php include __DIR__ . '/navbar.php'; ?>
        <div class="dash-overlay" aria-hidden="true"></div>
        <main class="dash-main">
            <div class="dash-topbar">
                <div>
                    <div class="dash-greeting">Good day, <?= htmlspecialchars($name) ?>!</div>
                    <div class="dash-meta"><?= date('l, j M Y') ?></div>
                </div>
                <div class="dash-actions">
                    <button class="dash-hamburger" type="button" aria-label="Toggle menu">
                        <i class="bi bi-list"></i>
                    </button>
                    <form class="dash-search" method="GET" action="<?= ($role === 'admin') ? $basePath . '/admin/transactions.php' : $basePath . '/member/dashboard.php' ?>">
                        <input type="text" name="search" placeholder="Search transactions..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
                    </form>
                    <div class="dash-user dropdown">
                        <div class="dash-avatar-wrapper" style="cursor: pointer;" data-bs-toggle="dropdown" aria-expanded="false">
                            <?php
                            // Fetch profile photo for current user
                            $profilePhoto = '';
                            if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
                                try {
                                    $photoStmt = $pdo->prepare("SELECT profile_photo FROM users WHERE id = ?");
                                    $photoStmt->execute([$_SESSION['user_id']]);
                                    $photoUser = $photoStmt->fetch();
                                    if ($photoUser && !empty($photoUser['profile_photo']) && file_exists(__DIR__ . '/../' . $photoUser['profile_photo'])) {
                                        $profilePhoto = $photoUser['profile_photo'];
                                    }
                                } catch (PDOException $e) {
                                    // Silent fail if query fails
                                }
                            }
                            ?>
                            <?php if (!empty($profilePhoto)): ?>
                                <img src="<?= $basePath ?>/<?= htmlspecialchars($profilePhoto) ?>" alt="Profile" class="dash-avatar-img">
                            <?php else: ?>
                                <div class="dash-avatar"><?= strtoupper(substr($name, 0, 1)) ?></div>
                            <?php endif; ?>
                        </div>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0" style="border-radius: 12px; min-width: 180px;">
                            <li>
                                <a class="dropdown-item py-2 px-3" href="<?= $basePath ?>/member/profile.php">
                                    <i class="bi bi-person-circle me-2"></i>My Profile
                                </a>
                            </li>
                            <?php if ($role === 'admin'): ?>
                                <li>
                                    <a class="dropdown-item py-2 px-3" href="<?= $basePath ?>/admin/import.php">
                                        <i class="bi bi-upload me-2"></i>Import Excel
                                    </a>
                                </li>
                            <?php else: ?>
                                <li>
                                    <a class="dropdown-item py-2 px-3" href="<?= $basePath ?>/member/download-ledger.php">
                                        <i class="bi bi-filetype-pdf me-2"></i>Download PDF
                                    </a>
                                </li>
                            <?php endif; ?>
                            <li><hr class="dropdown-divider my-1"></li>
                            <li>
                                <a class="dropdown-item py-2 px-3 text-danger" href="<?= $basePath ?>/auth/logout.php">
                                    <i class="bi bi-box-arrow-right me-2"></i>Logout
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
            <section class="dash-content">
<?php else: ?>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="<?= $homeLink ?>">Beulah Coop</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <?php if ($role === 'admin'): ?>
                        <li class="nav-item"><a class="nav-link" href="<?= $basePath ?>/admin/index.php">Dashboard</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?= $basePath ?>/admin/members.php">Members</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?= $basePath ?>/admin/transactions.php">Transactions</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?= $basePath ?>/admin/import.php">Import Excel</a></li>
                    <?php elseif ($role === 'member'): ?>
                        <li class="nav-item"><a class="nav-link" href="<?= $basePath ?>/member/dashboard.php">My Dashboard</a></li>
                    <?php else: ?>
                        <li class="nav-item"><a class="nav-link" href="<?= $basePath ?>/login.php">Login</a></li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= $basePath ?>/auth/logout.php">Logout (<?= htmlspecialchars($name) ?>)</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container mt-4 page-container">
<?php endif; ?>
