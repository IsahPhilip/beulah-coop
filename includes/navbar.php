<?php
// includes/navbar.php
?>
<aside class="dash-sidebar">
    <div class="dash-brand">
        <a href="<?= $homeLink ?>" class="dash-brand-link"><i class="bi bi-gem"></i> Beulah Coop</a>
        <span class="dash-sub">Coop Manager</span>
    </div>
    <div class="dash-quick">
        <?php if ($role === 'admin'): ?>
            <a class="btn btn-primary w-100 mb-2" href="<?= $basePath ?>/admin/members.php"><i class="bi bi-person-plus"></i> Add Member</a>
            <a class="btn btn-outline-primary w-100" href="<?= $basePath ?>/admin/transactions.php?open=add"><i class="bi bi-cash-stack"></i> Add Transaction</a>
        <?php elseif ($role === 'member'): ?>
            <a class="btn btn-primary w-100 mb-2" href="<?= $basePath ?>/member/download-ledger.php"><i class="bi bi-filetype-pdf"></i> Download PDF</a>
            <a class="btn btn-outline-primary w-100" href="<?= $basePath ?>/member/download-ledger-excel.php"><i class="bi bi-file-earmark-excel"></i> Download Excel</a>
        <?php endif; ?>
    </div>
    <nav class="dash-nav">
        <?php if ($role === 'admin'): ?>
            <a class="dash-nav-link<?= $isActive(['index.php']) ?>" href="<?= $basePath ?>/admin/index.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
            <a class="dash-nav-link<?= $isActive(['members.php']) ?>" href="<?= $basePath ?>/admin/members.php"><i class="bi bi-people"></i> Members</a>
            <a class="dash-nav-link<?= $isActive(['transactions.php']) ?>" href="<?= $basePath ?>/admin/transactions.php"><i class="bi bi-receipt"></i> Transactions</a>
            <a class="dash-nav-link<?= $isActive(['loans.php']) ?>" href="<?= $basePath ?>/admin/loans.php"><i class="bi bi-cash-coin"></i> Loans</a>
            <a class="dash-nav-link<?= $isActive(['reports.php']) ?>" href="<?= $basePath ?>/admin/reports.php"><i class="bi bi-bar-chart-line"></i> Reports</a>
            <a class="dash-nav-link<?= $isActive(['announcements.php']) ?>" href="<?= $basePath ?>/admin/announcements.php"><i class="bi bi-megaphone"></i> Announcements</a>
            <a class="dash-nav-link<?= $isActive(['import.php', 'import-excel.php']) ?>" href="<?= $basePath ?>/admin/import.php"><i class="bi bi-upload"></i> Import Excel</a>
        <?php elseif ($role === 'member'): ?>
            <a class="dash-nav-link<?= $isActive(['dashboard.php']) ?>" href="<?= $basePath ?>/member/dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
            <a class="dash-nav-link<?= $isActive(['profile.php']) ?>" href="<?= $basePath ?>/member/profile.php"><i class="bi bi-person-circle"></i> My Profile</a>
            <a class="dash-nav-link<?= $isActive(['notifications.php']) ?>" href="<?= $basePath ?>/notifications.php"><i class="bi bi-bell"></i> Notifications</a>
        <?php endif; ?>
        <a class="dash-nav-link<?= $isActive(['logout.php']) ?>" href="<?= $basePath ?>/auth/logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a>
    </nav>
</aside>
