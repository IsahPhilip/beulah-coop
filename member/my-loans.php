<?php
// member/my-loans.php - View Loan History and Status
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

// Get all loan applications for this user
$stmt = $pdo->prepare("
    SELECT la.*, 
           reviewer.name as reviewer_name
    FROM loan_applications la
    LEFT JOIN users reviewer ON la.reviewed_by = reviewer.id
    WHERE la.user_id = ?
    ORDER BY la.applied_at DESC
");
$stmt->execute([$user_id]);
$loans = $stmt->fetchAll();

// Get loan summary stats
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status = 'disbursed' THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
        SUM(CASE WHEN status = 'defaulted' THEN 1 ELSE 0 END) as defaulted
    FROM loan_applications
    WHERE user_id = ?
");
$stmt->execute([$user_id]);
$loanStats = $stmt->fetch();

// Get outstanding loan balance
$stmt = $pdo->prepare("
    SELECT 
        SUM(CASE WHEN type = 'loan_disbursed' THEN amount ELSE 0 END) -
        SUM(CASE WHEN type = 'loan_repayment' THEN amount ELSE 0 END) as outstanding_loan
    FROM transactions WHERE user_id = ?
");
$stmt->execute([$user_id]);
$loanBalance = $stmt->fetch();
$outstandingLoan = $loanBalance['outstanding_loan'] ?? 0;

// Get repayment schedule for active loans
$activeLoanSchedules = [];
foreach ($loans as $loan) {
    if ($loan['status'] === 'disbursed') {
        $stmt = $pdo->prepare("
            SELECT * FROM loan_schedules 
            WHERE loan_application_id = ? 
            ORDER BY installment_number ASC
        ");
        $stmt->execute([$loan['id']]);
        $activeLoanSchedules[$loan['id']] = $stmt->fetchAll();
    }
}
?>

<?php
$pageTitle = 'My Loans - Beulah Coop';
$useDashboardLayout = true;
?>
<?php include '../includes/header.php'; ?>
<div class="dash-grid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="dash-title">My Loan History</h2>
        <a href="apply-loan.php" class="btn btn-primary">
            <i class="bi bi-plus-circle"></i> Apply for Loan
        </a>
    </div>

    <!-- Loan Summary Cards -->
    <div class="dash-cards">
        <div class="dash-card">
            <div class="dash-card-label">Total Applications</div>
            <div class="dash-card-value"><?= $loanStats['total'] ?? 0 ?></div>
        </div>
        <div class="dash-card">
            <div class="dash-card-label">Pending</div>
            <div class="dash-card-value text-warning"><?= $loanStats['pending'] ?? 0 ?></div>
        </div>
        <div class="dash-card">
            <div class="dash-card-label">Active Loans</div>
            <div class="dash-card-value text-primary"><?= $loanStats['active'] ?? 0 ?></div>
        </div>
        <div class="dash-card">
            <div class="dash-card-label">Completed</div>
            <div class="dash-card-value text-success"><?= $loanStats['completed'] ?? 0 ?></div>
        </div>
        <div class="dash-card">
            <div class="dash-card-label">Outstanding Balance</div>
            <div class="dash-card-value text-danger"><?= format_money($outstandingLoan) ?></div>
        </div>
    </div>

    <?php if (empty($loans)): ?>
        <div class="dash-panel">
            <div class="text-center py-5">
                <i class="bi bi-wallet2" style="font-size: 4rem; color: #ccc;"></i>
                <h4 class="mt-3 text-muted">No Loan Applications Yet</h4>
                <p class="text-muted">You haven't applied for any loans. When you do, they'll appear here.</p>
                <a href="apply-loan.php" class="btn btn-primary mt-2">
                    <i class="bi bi-plus-circle"></i> Apply for Your First Loan
                </a>
            </div>
        </div>
    <?php else: ?>
        <!-- Loan Applications List -->
        <div class="dash-panel">
            <div class="dash-panel-title">
                <i class="bi bi-list-ul me-2"></i>Loan Applications
            </div>
            <div class="dash-table">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Date Applied</th>
                            <th>Amount</th>
                            <th>Duration</th>
                            <th>Interest Rate</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($loans as $loan): ?>
                            <tr>
                                <td><?= date('d M Y', strtotime($loan['applied_at'])) ?></td>
                                <td><strong><?= format_money($loan['amount']) ?></strong></td>
                                <td><?= $loan['duration_months'] ?> months</td>
                                <td>
                                    <?= $loan['interest_rate'] > 0 
                                        ? '<span class="text-primary">' . $loan['interest_rate'] . '%</span>' 
                                        : '<span class="text-muted">Pending</span>' ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?= 
                                        $loan['status'] === 'pending' ? 'warning' : 
                                        ($loan['status'] === 'approved' ? 'info' : 
                                        ($loan['status'] === 'disbursed' ? 'primary' : 
                                        ($loan['status'] === 'rejected' ? 'danger' : 
                                        ($loan['status'] === 'defaulted' ? 'danger' : 'success')))) 
                                    ?> fs-6">
                                        <?= ucfirst($loan['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline-primary" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#loanDetailsModal"
                                            onclick="showLoanDetails(<?= json_encode($loan) ?>)">
                                        <i class="bi bi-eye"></i> Details
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Active Loan Repayment Schedules -->
        <?php if (!empty($activeLoanSchedules)): ?>
            <?php foreach ($activeLoanSchedules as $loanId => $schedules): ?>
                <?php 
                    $loan = null;
                    foreach ($loans as $l) {
                        if ($l['id'] == $loanId) {
                            $loan = $l;
                            break;
                        }
                    }
                    $totalPaid = array_sum(array_column($schedules, 'paid_amount'));
                    $totalDue = array_sum(array_column($schedules, 'total_amount'));
                    $progress = $totalDue > 0 ? ($totalPaid / $totalDue) * 100 : 0;
                ?>
                <div class="dash-panel mt-4">
                    <div class="dash-panel-title d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-calendar-check me-2"></i>Repayment Schedule - Loan #<?= $loanId ?></span>
                        <span class="badge bg-primary"><?= format_money($loan['amount']) ?></span>
                    </div>
                    
                    <!-- Progress Bar -->
                    <div class="px-3 pb-2">
                        <div class="d-flex justify-content-between small text-muted mb-1">
                            <span>Progress</span>
                            <span><?= number_format($progress, 1) ?>%</span>
                        </div>
                        <div class="progress" style="height: 8px;">
                            <div class="progress-bar bg-success" role="progressbar" 
                                 style="width: <?= $progress ?>%"></div>
                        </div>
                        <div class="d-flex justify-content-between small mt-1">
                            <span class="text-success">Paid: <?= format_money($totalPaid) ?></span>
                            <span class="text-muted">Total: <?= format_money($totalDue) ?></span>
                        </div>
                    </div>

                    <div class="dash-table">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Due Date</th>
                                    <th>Principal</th>
                                    <th>Interest</th>
                                    <th>Total</th>
                                    <th>Paid</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($schedules as $schedule): ?>
                                    <tr class="<?= $schedule['status'] === 'overdue' ? 'table-danger' : '' ?>">
                                        <td><?= $schedule['installment_number'] ?></td>
                                        <td><?= date('d M Y', strtotime($schedule['due_date'])) ?></td>
                                        <td><?= format_money($schedule['principal_amount']) ?></td>
                                        <td><?= format_money($schedule['interest_amount']) ?></td>
                                        <td><strong><?= format_money($schedule['total_amount']) ?></strong></td>
                                        <td><?= $schedule['paid_amount'] > 0 ? format_money($schedule['paid_amount']) : '-' ?></td>
                                        <td>
                                            <span class="badge bg-<?= 
                                                $schedule['status'] === 'paid' ? 'success' : 
                                                ($schedule['status'] === 'overdue' ? 'danger' : 
                                                ($schedule['status'] === 'partial' ? 'warning' : 'secondary')) 
                                            ?>">
                                                <?= ucfirst($schedule['status']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Loan Details Modal -->
<div class="modal fade" id="loanDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Loan Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="loanDetailsContent">
                <!-- Populated by JavaScript -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
function showLoanDetails(loan) {
    const content = document.getElementById('loanDetailsContent');
    const statusColors = {
        'pending': 'warning',
        'approved': 'info',
        'rejected': 'danger',
        'disbursed': 'primary',
        'completed': 'success',
        'defaulted': 'danger'
    };
    
    let detailsHTML = `
        <div class="row">
            <div class="col-md-6">
                <h6 class="text-primary mb-3">Loan Information</h6>
                <table class="table table-borderless">
                    <tr>
                        <td><strong>Application ID:</strong></td>
                        <td>#${loan.id}</td>
                    </tr>
                    <tr>
                        <td><strong>Amount:</strong></td>
                        <td>₦${parseFloat(loan.amount).toLocaleString('en-NG', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
                    </tr>
                    <tr>
                        <td><strong>Duration:</strong></td>
                        <td>${loan.duration_months} months</td>
                    </tr>
                    <tr>
                        <td><strong>Interest Rate:</strong></td>
                        <td>${loan.interest_rate > 0 ? loan.interest_rate + '%' : 'Pending approval'}</td>
                    </tr>
                    <tr>
                        <td><strong>Status:</strong></td>
                        <td><span class="badge bg-${statusColors[loan.status] || 'secondary'}">${loan.status.toUpperCase()}</span></td>
                    </tr>
                </table>
            </div>
            <div class="col-md-6">
                <h6 class="text-primary mb-3">Timeline</h6>
                <table class="table table-borderless">
                    <tr>
                        <td><strong>Applied:</strong></td>
                        <td>${new Date(loan.applied_at).toLocaleDateString('en-GB', {day: 'numeric', month: 'short', year: 'numeric'})}</td>
                    </tr>
                    ${loan.reviewed_at ? `
                    <tr>
                        <td><strong>Reviewed:</strong></td>
                        <td>${new Date(loan.reviewed_at).toLocaleDateString('en-GB', {day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit'})}</td>
                    </tr>
                    ` : ''}
                    ${loan.reviewed_by ? `
                    <tr>
                        <td><strong>Reviewed By:</strong></td>
                        <td>${loan.reviewer_name || 'Admin'}</td>
                    </tr>
                    ` : ''}
                </table>
            </div>
            ${loan.purpose ? `
            <div class="col-12 mt-3">
                <h6 class="text-primary mb-2">Purpose</h6>
                <p class="bg-light p-3 rounded">${loan.purpose}</p>
            </div>
            ` : ''}
            ${loan.guarantor_names ? `
            <div class="col-12">
                <h6 class="text-primary mb-2">Guarantors</h6>
                <p class="bg-light p-3 rounded">${loan.guarantor_names}</p>
            </div>
            ` : ''}
            ${loan.review_notes ? `
            <div class="col-12">
                <h6 class="text-primary mb-2">Admin Notes</h6>
                <p class="bg-light p-3 rounded">${loan.review_notes}</p>
            </div>
            ` : ''}
        </div>
    `;
    
    content.innerHTML = detailsHTML;
}
</script>
<?php include '../includes/footer.php'; ?>