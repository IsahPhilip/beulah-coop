<?php
// member/statement.php
require_once '../includes/auth.php';
if ($_SESSION['role'] === 'admin') { header("Location: ../admin/index.php"); exit(); }
if ($_SESSION['role'] !== 'member') { header("Location: ../login.php"); exit(); }

// Block pending members
if (($_SESSION['registration_status'] ?? 'active') === 'pending') {
    header("Location: dashboard.php"); exit();
}

$userId   = $_SESSION['user_id'];
$dateFrom = $_GET['date_from'] ?? date('Y-01-01');
$dateTo   = $_GET['date_to']   ?? date('Y-m-d');

// Preview stats
$creditTypes = ['savings_credit', 'loan_repayment', 'interest_paid'];
$debitTypes  = ['savings_debit', 'loan_disbursed', 'interest_charged', 'registration_fee'];

$stmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN type IN ('savings_credit','loan_repayment','interest_paid') THEN amount ELSE 0 END), 0) -
        COALESCE(SUM(CASE WHEN type IN ('savings_debit','loan_disbursed','interest_charged','registration_fee') THEN amount ELSE 0 END), 0)
    FROM transactions WHERE user_id = ? AND trans_date < ?
");
$stmt->execute([$userId, $dateFrom]);
$openingBalance = (float)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT
        COUNT(*) as txn_count,
        COALESCE(SUM(CASE WHEN type IN ('savings_credit','loan_repayment','interest_paid') THEN amount ELSE 0 END), 0) as total_credit,
        COALESCE(SUM(CASE WHEN type IN ('savings_debit','loan_disbursed','interest_charged','registration_fee') THEN amount ELSE 0 END), 0) as total_debit
    FROM transactions WHERE user_id = ? AND trans_date BETWEEN ? AND ?
");
$stmt->execute([$userId, $dateFrom, $dateTo]);
$preview = $stmt->fetch();
$closingBalance = $openingBalance + (float)$preview['total_credit'] - (float)$preview['total_debit'];

$pageTitle = 'My Statement - Beulah Coop';
$useDashboardLayout = true;
?>
<?php include '../includes/header.php'; ?>
<div class="dash-grid">
    <div class="dash-section-head">
        <h2 class="dash-title">Account Statement</h2>
    </div>

    <form method="GET">
        <div class="dash-filters">
            <div class="dash-filter-group">
                <label><i class="ph-bold ph-calendar-blank"></i> From</label>
                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>">
            </div>
            <div class="dash-filter-group">
                <label><i class="ph-bold ph-calendar-check"></i> To</label>
                <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($dateTo) ?>">
            </div>
            <div class="dash-filter-actions">
                <button type="submit" class="btn-filter-apply"><i class="ph-bold ph-funnel"></i> Apply</button>
                <a href="statement.php" class="btn-filter-clear"><i class="ph-bold ph-x"></i> Clear</a>
            </div>
        </div>
    </form>

    <div class="dash-cards">
        <div class="dash-card">
            <div class="dash-card-label">Opening Balance</div>
            <div class="dash-card-value"><?= format_money($openingBalance) ?></div>
            <div class="dash-card-sub">Before <?= date('d M Y', strtotime($dateFrom)) ?></div>
        </div>
        <div class="dash-card">
            <div class="dash-card-label">Total Credits</div>
            <div class="dash-card-value text-success"><?= format_money($preview['total_credit']) ?></div>
            <div class="dash-card-sub"><?= (int)$preview['txn_count'] ?> transactions</div>
        </div>
        <div class="dash-card">
            <div class="dash-card-label">Total Debits</div>
            <div class="dash-card-value text-danger"><?= format_money($preview['total_debit']) ?></div>
        </div>
        <div class="dash-card">
            <div class="dash-card-label">Closing Balance</div>
            <div class="dash-card-value <?= $closingBalance >= 0 ? 'text-primary' : 'text-danger' ?>"><?= format_money($closingBalance) ?></div>
            <div class="dash-card-sub">Net position</div>
        </div>
    </div>

    <div class="dash-panel p-4">
        <div class="dash-panel-title mb-3"><i class="ph-bold ph-file-text me-2"></i>Download Statement</div>
        <p class="text-muted mb-4" style="font-size:.9rem;">
            Your statement covers <strong><?= date('d M Y', strtotime($dateFrom)) ?></strong> to <strong><?= date('d M Y', strtotime($dateTo)) ?></strong>
            and includes all transaction types with a running net position balance.
        </p>
        <div class="d-flex gap-3 flex-wrap">
            <a href="../api/generate-statement.php?format=pdf&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>"
               class="btn btn-primary" target="_blank">
                <i class="ph-bold ph-file-pdf me-2"></i>Download PDF Statement
            </a>
            <a href="../api/generate-statement.php?format=excel&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>"
               class="btn btn-outline-primary" target="_blank">
                <i class="ph-bold ph-microsoft-excel-logo me-2"></i>Download Excel Statement
            </a>
        </div>
    </div>
</div>
<?php include '../includes/footer.php'; ?>
