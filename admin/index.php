<?php
// admin/index.php
require_once '../includes/auth.php';
if ($_SESSION['role'] === 'member') {
    header("Location: ../member/dashboard.php");
    exit();
}
if ($_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Grand totals
$stmt = $pdo->prepare("
    SELECT 
        SUM(CASE WHEN type IN ('savings_credit') THEN amount ELSE 0 END) as total_savings,
        SUM(CASE WHEN type = 'loan_disbursed' THEN amount ELSE 0 END) as total_loans_issued,
        SUM(CASE WHEN type = 'interest_charged' THEN amount ELSE 0 END) as total_interest
    FROM transactions
");
$stmt->execute();
$totals = $stmt->fetch();

// Monthly chart data (last 12 months)
$monthlyStmt = $pdo->prepare("
    SELECT 
        DATE_FORMAT(trans_date, '%Y-%m') AS ym,
        SUM(CASE WHEN type = 'savings_credit' THEN amount ELSE 0 END) AS savings,
        SUM(CASE WHEN type = 'loan_disbursed' THEN amount ELSE 0 END) AS loans,
        SUM(CASE WHEN type = 'loan_repayment' THEN amount ELSE 0 END) AS repayments
    FROM transactions
    WHERE trans_date >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
    GROUP BY ym
    ORDER BY ym ASC
");
$monthlyStmt->execute();
$monthlyRows = $monthlyStmt->fetchAll();

$monthlyMap = [];
foreach ($monthlyRows as $row) {
    $monthlyMap[$row['ym']] = [
        'savings' => (float)$row['savings'],
        'loans' => (float)$row['loans'],
        'repayments' => (float)$row['repayments']
    ];
}

$labels = [];
$savingsData = [];
$loansData = [];
$repaymentData = [];
$dt = new DateTime('first day of this month');
$dt->modify('-11 months');
for ($i = 0; $i < 12; $i++) {
    $ym = $dt->format('Y-m');
    $labels[] = $dt->format('M Y');
    $savingsData[] = $monthlyMap[$ym]['savings'] ?? 0;
    $loansData[] = $monthlyMap[$ym]['loans'] ?? 0;
    $repaymentData[] = $monthlyMap[$ym]['repayments'] ?? 0;
    $dt->modify('+1 month');
}

// Overdue loans (simple example)
$overdue = $pdo->query("SELECT COUNT(*) as count FROM loans WHERE status = 'active' AND due_date < CURDATE()")->fetch()['count'];
?>
<?php
$pageTitle = 'Admin Dashboard - Beulah Coop';
$useDashboardLayout = true;
?>
<?php include '../includes/header.php'; ?>
<div class="dash-grid">
    <div class="d-flex justify-content-between mb-4">
        <h2 class="dash-title">Cooperative Overview</h2>
    </div>

    <div class="dash-cards">
        <div class="dash-card">
            <div class="dash-card-label">Total Savings</div>
            <div class="dash-card-value"><?= format_money($totals['total_savings'] ?? 0) ?></div>
            <div class="dash-card-sub">Updated today</div>
        </div>
        <div class="dash-card">
            <div class="dash-card-label">Total Loans Issued</div>
            <div class="dash-card-value text-danger"><?= format_money($totals['total_loans_issued'] ?? 0) ?></div>
            <div class="dash-card-sub">All active loans</div>
        </div>
        <div class="dash-card">
            <div class="dash-card-label">Total Interest</div>
            <div class="dash-card-value text-warning"><?= format_money($totals['total_interest'] ?? 0) ?></div>
            <div class="dash-card-sub">Year to date</div>
        </div>
    </div>

    <?php if ($overdue > 0): ?>
    <div class="alert alert-danger mt-4">
        <strong>Alert:</strong> <?= $overdue ?> overdue loan(s). Please check Transactions.
    </div>
    <?php endif; ?>

    <div class="dash-panels">
        <div class="dash-panel">
            <div class="dash-panel-title">Monthly Savings vs Loans</div>
            <canvas id="monthlyChart"></canvas>
        </div>
        <div class="dash-panel">
            <div class="dash-panel-title">Portfolio Mix</div>
            <canvas id="portfolioChart"></canvas>
        </div>
    </div>
</div>

<script>
    const monthlyCtx = document.getElementById('monthlyChart');
    const portfolioCtx = document.getElementById('portfolioChart');

    new Chart(monthlyCtx, {
        type: 'line',
        data: {
            labels: <?= json_encode($labels) ?>,
            datasets: [
                {
                    label: 'Savings Credits',
                    data: <?= json_encode($savingsData) ?>,
                    borderColor: '#7a5cff',
                    backgroundColor: 'rgba(122, 92, 255, 0.15)',
                    tension: 0.35,
                    fill: true
                },
                {
                    label: 'Loans Disbursed',
                    data: <?= json_encode($loansData) ?>,
                    borderColor: '#ff9f7a',
                    backgroundColor: 'rgba(255, 159, 122, 0.15)',
                    tension: 0.35,
                    fill: true
                },
                {
                    label: 'Loan Repayments',
                    data: <?= json_encode($repaymentData) ?>,
                    borderColor: '#2bb673',
                    backgroundColor: 'rgba(43, 182, 115, 0.12)',
                    tension: 0.35,
                    fill: true
                }
            ]
        },
        options: {
            responsive: true,
            plugins: { legend: { position: 'bottom' } },
            scales: {
                y: { ticks: { callback: value => '₦' + value } }
            }
        }
    });

    new Chart(portfolioCtx, {
        type: 'doughnut',
        data: {
            labels: ['Savings', 'Loans Issued', 'Interest'],
            datasets: [{
                data: [
                    <?= (float)($totals['total_savings'] ?? 0) ?>,
                    <?= (float)($totals['total_loans_issued'] ?? 0) ?>,
                    <?= (float)($totals['total_interest'] ?? 0) ?>
                ],
                backgroundColor: ['#7a5cff', '#ff9f7a', '#ffd56a'],
                borderWidth: 0
            }]
        },
        options: {
            cutout: '70%',
            plugins: { legend: { position: 'bottom' } }
        }
    });
</script>
<?php include '../includes/footer.php'; ?>
