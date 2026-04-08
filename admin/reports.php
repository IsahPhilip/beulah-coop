<?php
// admin/reports.php - Financial Reports & Analytics
require_once '../includes/auth.php';
if ($_SESSION['role'] === 'member') {
    header("Location: ../member/dashboard.php");
    exit();
}
if ($_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Date range filter
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

// Get financial summary
$financialQuery = "
    SELECT 
        SUM(CASE WHEN type = 'savings_credit' THEN amount ELSE 0 END) as total_savings,
        SUM(CASE WHEN type = 'loan_disbursed' THEN amount ELSE 0 END) as total_loans_disbursed,
        SUM(CASE WHEN type = 'loan_repayment' THEN amount ELSE 0 END) as total_loan_repayments,
        SUM(CASE WHEN type = 'interest_charged' THEN amount ELSE 0 END) as total_interest
    FROM transactions 
    WHERE trans_date BETWEEN ? AND ?
";
$financialStmt = $pdo->prepare($financialQuery);
$financialStmt->execute([$dateFrom, $dateTo]);
$financials = $financialStmt->fetch();

// Monthly trends
$trendQuery = "
    SELECT 
        DATE_FORMAT(trans_date, '%Y-%m') as month,
        SUM(CASE WHEN type = 'savings_credit' THEN amount ELSE 0 END) as savings,
        SUM(CASE WHEN type = 'loan_disbursed' THEN amount ELSE 0 END) as loans,
        SUM(CASE WHEN type = 'loan_repayment' THEN amount ELSE 0 END) as repayments
    FROM transactions 
    WHERE trans_date BETWEEN ? AND ?
    GROUP BY DATE_FORMAT(trans_date, '%Y-%m')
    ORDER BY month DESC
    LIMIT 6
";
$trendStmt = $pdo->prepare($trendQuery);
$trendStmt->execute([$dateFrom, $dateTo]);
$trends = $trendStmt->fetchAll();

// Top members by savings
$topSaversQuery = "
    SELECT u.name, u.coop_no, 
           SUM(CASE WHEN t.type = 'savings_credit' THEN t.amount ELSE 0 END) as total_savings
    FROM users u
    LEFT JOIN transactions t ON u.id = t.user_id
    WHERE u.role = 'member' AND (t.trans_date BETWEEN ? AND ? OR t.trans_date IS NULL)
    GROUP BY u.id
    ORDER BY total_savings DESC
    LIMIT 10
";
$topSaversStmt = $pdo->prepare($topSaversQuery);
$topSaversStmt->execute([$dateFrom, $dateTo]);
$topSavers = $topSaversStmt->fetchAll();

// Loan portfolio health
$loanHealthQuery = "
    SELECT 
        COUNT(*) as total_loans,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status = 'disbursed' THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status = 'defaulted' THEN 1 ELSE 0 END) as defaulted,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
    FROM loan_applications
";
$loanHealth = $pdo->query($loanHealthQuery)->fetch();

// Member statistics
$memberStatsQuery = "
    SELECT 
        COUNT(*) as total_members,
        SUM(CASE WHEN created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as new_members
    FROM users 
    WHERE role = 'member'
";
$memberStatsStmt = $pdo->prepare($memberStatsQuery);
$memberStatsStmt->execute([$dateFrom, $dateTo]);
$memberStats = $memberStatsStmt->fetch();

$pageTitle = 'Reports & Analytics - Beulah Coop';
$useDashboardLayout = true;
$extraHead = '<link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">';
?>
<?php include '../includes/header.php'; ?>
<div class="dash-grid">
    <div class="dash-section-head">
        <h2 class="dash-title">Reports & Analytics</h2>
        <div class="dash-section-actions">
            <button class="btn btn-outline-primary" onclick="exportReport('pdf')">
                <i class="bi bi-filetype-pdf me-1"></i>Export PDF
            </button>
            <button class="btn btn-outline-primary" onclick="exportReport('excel')">
                <i class="bi bi-file-earmark-excel me-1"></i>Export Excel
            </button>
        </div>
    </div>

    <!-- Date Range Filter -->
    <div class="dash-panel">
        <div class="dash-panel-title">Filter by Date Range</div>
        <div class="p-3">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label">From Date</label>
                    <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">To Date</label>
                    <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($dateTo) ?>">
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-funnel me-1"></i>Apply Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Financial Summary Cards -->
    <div class="dash-cards">
        <div class="dash-card">
            <div class="dash-card-label">Total Savings</div>
            <div class="dash-card-value text-success"><?= format_money($financials['total_savings'] ?? 0) ?></div>
        </div>
        <div class="dash-card">
            <div class="dash-card-label">Loans Disbursed</div>
            <div class="dash-card-value text-primary"><?= format_money($financials['total_loans_disbursed'] ?? 0) ?></div>
        </div>
        <div class="dash-card">
            <div class="dash-card-label">Loan Repayments</div>
            <div class="dash-card-value text-info"><?= format_money($financials['total_loan_repayments'] ?? 0) ?></div>
        </div>
        <div class="dash-card">
            <div class="dash-card-label">Interest Earned</div>
            <div class="dash-card-value text-warning"><?= format_money($financials['total_interest'] ?? 0) ?></div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="row g-4">
        <div class="col-md-8">
            <div class="dash-panel">
                <div class="dash-panel-title">Monthly Financial Trends</div>
                <div class="p-3">
                    <canvas id="trendChart" height="100"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="dash-panel">
                <div class="dash-panel-title">Loan Portfolio Health</div>
                <div class="p-3">
                    <canvas id="loanHealthChart" height="200"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Top Savers Table -->
    <div class="dash-panel dash-panel-table">
        <div class="dash-panel-title">Top 10 Members by Savings</div>
        <table id="topSaversTable" class="table dash-table-grid">
            <thead>
                <tr>
                    <th>Rank</th>
                    <th>Member</th>
                    <th>Coop No.</th>
                    <th>Total Savings</th>
                </tr>
            </thead>
            <tbody>
                <?php $rank = 0; foreach ($topSavers as $saver): $rank++; ?>
                    <tr>
                        <td><span class="badge bg-<?= $rank <= 3 ? 'warning' : 'secondary' ?>">#<?= $rank ?></span></td>
                        <td><?= htmlspecialchars($saver['name']) ?></td>
                        <td><?= htmlspecialchars($saver['coop_no']) ?></td>
                        <td><?= format_money($saver['total_savings'] ?? 0) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Member Statistics -->
    <div class="dash-panel">
        <div class="dash-panel-title">Member Statistics</div>
        <div class="p-3">
            <div class="row">
                <div class="col-md-6">
                    <div class="dash-summary-card">
                        <div class="dash-summary-label">Total Members</div>
                        <div class="dash-summary-value"><?= $memberStats['total_members'] ?? 0 ?></div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="dash-summary-card">
                        <div class="dash-summary-label">New Members (Period)</div>
                        <div class="dash-summary-value text-success"><?= $memberStats['new_members'] ?? 0 ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script>
// Monthly Trends Chart
const trendCtx = document.getElementById('trendChart').getContext('2d');
const trendData = <?= json_encode(array_reverse($trends)) ?>;

new Chart(trendCtx, {
    type: 'bar',
    data: {
        labels: trendData.map(d => d.month),
        datasets: [
            {
                label: 'Savings',
                data: trendData.map(d => parseFloat(d.savings)),
                backgroundColor: '#059669',
                borderRadius: 8
            },
            {
                label: 'Loans',
                data: trendData.map(d => parseFloat(d.loans)),
                backgroundColor: '#3B82F6',
                borderRadius: 8
            },
            {
                label: 'Repayments',
                data: trendData.map(d => parseFloat(d.repayments)),
                backgroundColor: '#06B6D4',
                borderRadius: 8
            }
        ]
    },
    options: {
        responsive: true,
        plugins: {
            legend: {
                position: 'top',
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        return '₦' + value.toLocaleString();
                    }
                }
            }
        }
    }
});

// Loan Health Chart
const loanCtx = document.getElementById('loanHealthChart').getContext('2d');
const loanData = <?= json_encode($loanHealth) ?>;

new Chart(loanCtx, {
    type: 'doughnut',
    data: {
        labels: ['Pending', 'Approved', 'Active', 'Completed', 'Defaulted', 'Rejected'],
        datasets: [{
            data: [
                loanData.pending || 0,
                loanData.approved || 0,
                loanData.active || 0,
                loanData.completed || 0,
                loanData.defaulted || 0,
                loanData.rejected || 0
            ],
            backgroundColor: [
                '#F59E0B', // Pending - yellow
                '#3B82F6', // Approved - blue
                '#10B981', // Active - green
                '#059669', // Completed - dark green
                '#EF4444', // Defaulted - red
                '#6B7280'  // Rejected - gray
            ],
            borderWidth: 0,
            borderRadius: 8
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    padding: 15,
                    usePointStyle: true
                }
            }
        },
        cutout: '60%'
    }
});

// Initialize DataTable
$('#topSaversTable').DataTable({
    searching: false,
    pageLength: 10,
    info: false,
    dom: 'rt<"dt-bottom"ip>'
});

function exportReport(format) {
    const params = new URLSearchParams(window.location.search);
    const dateFrom = params.get('date_from') || '<?= $dateFrom ?>';
    const dateTo = params.get('date_to') || '<?= $dateTo ?>';
    
    // In a real implementation, this would call a server-side export function
    alert(`Exporting ${format.toUpperCase()} report for ${dateFrom} to ${dateTo}...\n\nThis feature would generate a downloadable file in a production environment.`);
}
</script>
<?php include '../includes/footer.php'; ?>