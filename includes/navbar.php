<?php
// includes/navbar.php
// Fetch pending registration count for admin badge
$pendingRegCount = 0;
if (($role ?? '') === 'admin') {
    try {
        $pendingRegCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE registration_status = 'pending' AND role = 'member'")->fetchColumn();
    } catch (Throwable $e) { /* column may not exist yet */ }
}
?>
<aside class="dash-sidebar">
    <div class="dash-brand">
        <a href="<?= $homeLink ?>" class="dash-brand-link"><i class="ph-bold ph-diamond"></i> Beulah Coop</a>
        <span class="dash-sub">Coop Manager</span>
    </div>
    <div class="dash-quick">
        <?php if ($role === 'admin'): ?>
            <a class="btn btn-primary w-100 mb-2" href="<?= $basePath ?>/admin/members.php"><i class="ph-bold ph-user-plus"></i> Add Member</a>
            <a class="btn btn-outline-primary w-100" href="<?= $basePath ?>/admin/transactions.php?open=add"><i class="ph-bold ph-currency-circle-dollar"></i> Add Transaction</a>
        <?php elseif ($role === 'member'): ?>
            <a class="btn btn-primary w-100 mb-2" href="<?= $basePath ?>/member/download-ledger.php"><i class="ph-bold ph-file-pdf"></i> Download PDF</a>
            <a class="btn btn-outline-primary w-100" href="<?= $basePath ?>/member/download-ledger-excel.php"><i class="ph-bold ph-microsoft-excel-logo"></i> Download Excel</a>
        <?php endif; ?>
    </div>
    <nav class="dash-nav">
        <?php if ($role === 'admin'): ?>
            <div class="dash-nav-group">
                <div class="dash-nav-group-title">Main menu</div>
                <a class="dash-nav-link<?= $isActive(['index.php']) ?>" href="<?= $basePath ?>/admin/index.php"><i class="ph-bold ph-squares-four"></i> Dashboard</a>
                <a class="dash-nav-link<?= $isActive(['transactions.php']) ?>" href="<?= $basePath ?>/admin/transactions.php"><i class="ph-bold ph-receipt"></i> Transactions</a>
                <a class="dash-nav-link<?= $isActive(['reports.php']) ?>" href="<?= $basePath ?>/admin/reports.php"><i class="ph-bold ph-chart-bar"></i> Reports</a>
            </div>
            <div class="dash-nav-group">
                <div class="dash-nav-group-title">Managements</div>
                <a class="dash-nav-link<?= $isActive(['members.php']) ?>" href="<?= $basePath ?>/admin/members.php"><i class="ph-bold ph-users"></i> Members</a>
                <a class="dash-nav-link<?= $isActive(['registrations.php']) ?>" href="<?= $basePath ?>/admin/registrations.php">
                    <i class="ph-bold ph-user-check"></i> Registrations
                    <?php if ($pendingRegCount > 0): ?>
                        <span class="nav-badge"><?= $pendingRegCount ?></span>
                    <?php endif; ?>
                </a>
                <a class="dash-nav-link<?= $isActive(['loans.php']) ?>" href="<?= $basePath ?>/admin/loans.php"><i class="ph-bold ph-hand-coins"></i> Loans</a>
                <a class="dash-nav-link<?= $isActive(['announcements.php']) ?>" href="<?= $basePath ?>/admin/announcements.php"><i class="ph-bold ph-megaphone"></i> Announcements</a>
                <a class="dash-nav-link<?= $isActive(['import.php', 'import-excel.php']) ?>" href="<?= $basePath ?>/admin/import.php"><i class="ph-bold ph-upload-simple"></i> Import Excel</a>
            </div>
            <div class="dash-nav-group">
                <div class="dash-nav-group-title">System</div>
                <a class="dash-nav-link<?= $isActive(['settings.php']) ?>" href="<?= $basePath ?>/admin/settings.php"><i class="ph-bold ph-gear"></i> Settings</a>
            </div>
        <?php elseif ($role === 'member'): ?>
            <div class="dash-nav-group">
                <div class="dash-nav-group-title">Main menu</div>
                <a class="dash-nav-link<?= $isActive(['dashboard.php']) ?>" href="<?= $basePath ?>/member/dashboard.php"><i class="ph-bold ph-squares-four"></i> Dashboard</a>
                <a class="dash-nav-link<?= $isActive(['transactions.php']) ?>" href="<?= $basePath ?>/member/transactions.php"><i class="ph-bold ph-receipt"></i> Transactions</a>
                <a class="dash-nav-link<?= $isActive(['statement.php']) ?>" href="<?= $basePath ?>/member/statement.php"><i class="ph-bold ph-file-text"></i> Statement</a>
                <a class="dash-nav-link<?= $isActive(['my-loans.php']) ?>" href="<?= $basePath ?>/member/my-loans.php"><i class="ph-bold ph-hand-coins"></i> My Loans</a>
                <a class="dash-nav-link<?= $isActive(['profile.php']) ?>" href="<?= $basePath ?>/member/profile.php"><i class="ph-bold ph-user-circle"></i> My Profile</a>
                <a class="dash-nav-link<?= $isActive(['notifications.php']) ?>" href="<?= $basePath ?>/notifications.php"><i class="ph-bold ph-bell"></i> Notifications</a>
            </div>
        <?php endif; ?>
        <div class="dash-support-card">
            <strong>Need support?</strong>
            <p>Contact our support team for assistance.</p>
            <a class="btn btn-sm btn-outline-primary" href="#">Call the Expert</a>
        </div>
        <a class="dash-nav-link<?= $isActive(['logout.php']) ?>" href="<?= $basePath ?>/auth/logout.php"><i class="ph-bold ph-sign-out"></i> Logout</a>
    </nav>
</aside>
