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
                    <div class="dash-greeting">Good day, <?= htmlspecialchars($name) ?></div>
                    <div class="dash-meta"><?= date('l, j M Y') ?></div>
                </div>
                <div class="dash-actions">
                    <button class="dash-hamburger" type="button" aria-label="Toggle menu">
                        <i class="bi bi-list"></i>
                    </button>
                    <div class="dash-search">
                        <input type="text" placeholder="Search members, loans, transactions...">
                    </div>
                    <div class="dash-user">
                        <div class="dash-avatar"><?= strtoupper(substr($name, 0, 1)) ?></div>
                        <div class="dash-user-text">
                            <div class="dash-user-name"><?= htmlspecialchars($name) ?></div>
                            <div class="dash-user-role"><?= htmlspecialchars(ucfirst($role ?? 'guest')) ?></div>
                        </div>
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
