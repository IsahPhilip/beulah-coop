<?php
// member/dashboard.php
require_once '../includes/auth.php';
if ($_SESSION['role'] === 'admin') {
    header("Location: ../admin/index.php");
    exit();
}
if ($_SESSION['role'] !== 'member') {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Get current balances
$stmt = $pdo->prepare("
    SELECT 
        SUM(CASE WHEN type IN ('savings_credit') THEN amount ELSE 0 END) as total_savings,
        SUM(CASE WHEN type = 'loan_disbursed' THEN amount ELSE 0 END) -
        SUM(CASE WHEN type = 'loan_repayment' THEN amount ELSE 0 END) as outstanding_loan,
        SUM(CASE WHEN type = 'interest_charged' THEN amount ELSE 0 END) as total_interest
    FROM transactions WHERE user_id = ?
");
$stmt->execute([$user_id]);
$balances = $stmt->fetch();

// Recent transactions
$stmt = $pdo->prepare("SELECT * FROM transactions WHERE user_id = ? ORDER BY trans_date DESC, id DESC LIMIT 10");
$stmt->execute([$user_id]);
$transactions = $stmt->fetchAll();
?>

<?php
$pageTitle = 'My Dashboard - Beulah Coop';
$useDashboardLayout = true;
?>
<?php include '../includes/header.php'; ?>
<div class="dash-grid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="dash-title">My Savings Overview</h2>
        <div class="dash-pill">Coop No: <?= htmlspecialchars($_SESSION['coop_no']) ?></div>
    </div>

    <div class="dash-cards">
        <div class="dash-card">
            <div class="dash-card-label">Savings Balance</div>
            <div class="dash-card-value"><?= format_money($balances['total_savings'] ?? 0) ?></div>
            <div class="dash-card-sub">All deposits to date</div>
        </div>
        <div class="dash-card">
            <div class="dash-card-label">Outstanding Loan</div>
            <div class="dash-card-value text-danger"><?= format_money($balances['outstanding_loan'] ?? 0) ?></div>
            <div class="dash-card-sub">Active loan balance</div>
        </div>
        <div class="dash-card">
            <div class="dash-card-label">Interest Accrued</div>
            <div class="dash-card-value text-warning"><?= format_money($balances['total_interest'] ?? 0) ?></div>
            <div class="dash-card-sub">Charged so far</div>
        </div>
    </div>

    <div class="dash-panels">
        <div class="dash-panel">
            <div class="dash-panel-title">Savings Growth Over Time</div>
            <canvas id="savingsChart"></canvas>
        </div>
        <div class="dash-panel">
            <div class="dash-panel-title">Recent Transactions</div>
            <div class="dash-table">
                <table class="table table-sm">
                    <thead><tr><th>Date</th><th>Type</th><th>Amount</th></tr></thead>
                    <tbody>
                    <?php foreach ($transactions as $t): ?>
                        <tr>
                            <td><?= $t['trans_date'] ?></td>
                            <td><?= str_replace('_', ' ', $t['type']) ?></td>
                            <td><?= format_money($t['amount']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
// Simple Chart.js example - you can enhance with real monthly data via AJAX
const ctx = document.getElementById('savingsChart');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: ['Apr 2025', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec', 'Jan 2026', 'Feb', 'Mar'],
        datasets: [{
            label: 'Savings Balance',
            data: [1000, 5000, 12000, 15000, 22000, 25000, 30000, 35000, 42000, 50000, 65000, 76025], // Replace with real data
            borderColor: '#28a745',
            tension: 0.4
        }]
    },
    options: { responsive: true }
});
</script>
<?php include '../includes/footer.php'; ?>
