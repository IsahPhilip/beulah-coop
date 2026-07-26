<?php
// member/transactions.php - Member Transaction History
require_once '../includes/auth.php';
if ($_SESSION['role'] !== 'member') {
    header("Location: ../login.php");
    exit();
}

$userId = $_SESSION['user_id'];

// Date range filter
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

// Get member's transactions
$query = "
    SELECT t.*, 
           CASE 
               WHEN t.type = 'savings_credit' THEN 'Savings Credit'
               WHEN t.type = 'loan_disbursed' THEN 'Loan Disbursed'
               WHEN t.type = 'loan_repayment' THEN 'Loan Repayment'
               WHEN t.type = 'interest_charged' THEN 'Interest Charged'
               ELSE t.type
           END as type_label
    FROM transactions t
    WHERE t.user_id = ? AND t.trans_date BETWEEN ? AND ?
    ORDER BY t.trans_date DESC
";

$stmt = $pdo->prepare($query);
$stmt->execute([$userId, $dateFrom, $dateTo . ' 23:59:59']);
$transactions = $stmt->fetchAll();

// Get member stats
$statsQuery = "
    SELECT 
        COUNT(*) as total_transactions,
        SUM(CASE WHEN type = 'savings_credit' THEN amount ELSE 0 END) as total_savings,
        SUM(CASE WHEN type = 'loan_repayment' THEN amount ELSE 0 END) as total_repayments,
        SUM(amount) as total_amount
    FROM transactions 
    WHERE user_id = ? AND trans_date BETWEEN ? AND ?
";
$statsStmt = $pdo->prepare($statsQuery);
$statsStmt->execute([$userId, $dateFrom, $dateTo . ' 23:59:59']);
$stats = $statsStmt->fetch();

// Get member info
$memberStmt = $pdo->prepare("SELECT name, coop_no FROM users WHERE id = ?");
$memberStmt->execute([$userId]);
$member = $memberStmt->fetch();

$pageTitle = 'My Transactions - Beulah Coop';
$useDashboardLayout = true;
$extraHead = '<link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">'
    . '<link href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css" rel="stylesheet">';
?>
<?php include '../includes/header.php'; ?>
<div class="dash-grid">
    <div class="dash-section-head">
        <h2 class="dash-title">My Transactions</h2>
        <div class="dash-section-actions">
            <button class="btn btn-outline-primary" onclick="exportCSV()">
                <i class="ph-bold ph-download-simple me-1"></i>Export CSV
            </button>
        </div>
    </div>

    <!-- Date Range Filter -->
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
                <a href="transactions.php" class="btn-filter-clear"><i class="ph-bold ph-x"></i> Clear</a>
            </div>
        </div>
    </form>

    <!-- Summary Cards -->
    <div class="dash-cards">
        <div class="dash-card">
            <div class="dash-card-label">Total Transactions</div>
            <div class="dash-card-value"><?= $stats['total_transactions'] ?? 0 ?></div>
        </div>
        <div class="dash-card">
            <div class="dash-card-label">Total Savings</div>
            <div class="dash-card-value text-success"><?= format_money($stats['total_savings'] ?? 0) ?></div>
        </div>
        <div class="dash-card">
            <div class="dash-card-label">Total Repayments</div>
            <div class="dash-card-value text-info"><?= format_money($stats['total_repayments'] ?? 0) ?></div>
        </div>
        <div class="dash-card">
            <div class="dash-card-label">Period Total</div>
            <div class="dash-card-value text-primary"><?= format_money($stats['total_amount'] ?? 0) ?></div>
        </div>
    </div>

    <!-- Transactions Table -->
    <div class="dash-panel dash-panel-table">
        <div class="dash-panel-title">Transaction History</div>
        <div class="p-3">
            <?php if (empty($transactions)): ?>
                <div class="text-center text-muted py-5">
                    <i class="ph-bold ph-receipt" style="font-size: 3rem;"></i>
                    <p class="mt-3">No transactions found for the selected period.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table id="transactionsTable" class="table table-hover dash-table-grid">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Description</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($transactions as $t): ?>
                            <tr>
                                <td><?= date('d M Y', strtotime($t['trans_date'])) ?></td>
                        <td>
                                <span class="badge <?= 
                                        $t['type'] === 'savings_credit' ? 'badge-savings' : 
                                        ($t['type'] === 'loan_disbursed' ? 'badge-loan' : 
                                        ($t['type'] === 'loan_repayment' ? 'badge-repay' : 'badge-interest')) 
                                    ?>">
                                        <?= htmlspecialchars($t['type_label']) ?>
                                    </span>
                                </td>
                                <td><span class="tbl-amount <?= str_contains($t['type'],'credit')||str_contains($t['type'],'repayment') ? 'positive' : 'negative' ?>"><?= format_money($t['amount']) ?></span></td>
                                <td><?= htmlspecialchars($t['description'] ?? '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script>
$(document).ready(function() {
    <?php if (!empty($transactions)): ?>
    const table = $('#transactionsTable').DataTable({
        searching: true,
        pageLength: 25,
        order: [[0, 'desc']],
        dom: '<"dt-top"lfB>rt<"dt-bottom"ip>',
        buttons: [
            { extend: 'csvHtml5', className: 'btn btn-outline-primary btn-sm', text: '<i class="ph-bold ph-file-csv me-1"></i>Export CSV' }
        ]
    });
    <?php endif; ?>
});

function exportCSV() {
    const params = new URLSearchParams(window.location.search);
    const dateFrom = params.get('date_from') || '<?= $dateFrom ?>';
    const dateTo = params.get('date_to') || '<?= $dateTo ?>';
    
    alert(`Exporting CSV for ${dateFrom} to ${dateTo}...\n\nIn a production environment, this would generate a downloadable CSV file.`);
}
</script>
<?php include '../includes/footer.php'; ?>