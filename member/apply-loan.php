<?php
// member/apply-loan.php - Loan Application for Members
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
$error = '';
$success = '';
$successData = null;

// Get user's current savings and outstanding loan
$stmt = $pdo->prepare("
    SELECT 
        SUM(CASE WHEN type IN ('savings_credit') THEN amount ELSE 0 END) as total_savings,
        SUM(CASE WHEN type = 'loan_disbursed' THEN amount ELSE 0 END) -
        SUM(CASE WHEN type = 'loan_repayment' THEN amount ELSE 0 END) as outstanding_loan
    FROM transactions WHERE user_id = ?
");
$stmt->execute([$user_id]);
$userFinances = $stmt->fetch();
$totalSavings = $userFinances['total_savings'] ?? 0;
$outstandingLoan = $userFinances['outstanding_loan'] ?? 0;
$maxLoanAmount = max($totalSavings * 3, 1000); // Ensure max is at least 1000

// Check if user has any pending loan applications
$stmt = $pdo->prepare("SELECT COUNT(*) as pending_count FROM loan_applications WHERE user_id = ? AND status = 'pending'");
$stmt->execute([$user_id]);
$pendingLoans = $stmt->fetch()['pending_count'] ?? 0;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = floatval($_POST['amount'] ?? 0);
    $durationMonths = intval($_POST['duration_months'] ?? 0);
    $purpose = trim($_POST['purpose'] ?? '');
    $guarantorNames = trim($_POST['guarantor_names'] ?? '');
    
    // Validation
    if ($amount < 1000) {
        $error = 'Minimum loan amount is ₦1,000.';
    } elseif ($amount > $maxLoanAmount) {
        $error = 'Loan amount cannot exceed ' . format_money($maxLoanAmount) . '.';
    } elseif ($durationMonths < 1 || $durationMonths > 36) {
        $error = 'Loan duration must be between 1 and 36 months.';
    } elseif (empty($purpose)) {
        $error = 'Please provide a purpose for the loan.';
    } elseif ($outstandingLoan > 0) {
        $error = 'You have an outstanding loan balance of ₦' . number_format($outstandingLoan, 2) . '. Please clear it before applying for a new loan.';
    } elseif ($pendingLoans > 0) {
        $error = 'You already have a pending loan application. Please wait for it to be processed.';
    } else {
        // Insert loan application
        $stmt = $pdo->prepare("
            INSERT INTO loan_applications (user_id, amount, duration_months, purpose, guarantor_names, status) 
            VALUES (?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->execute([$user_id, $amount, $durationMonths, $purpose, $guarantorNames]);
        
        $loanId = $pdo->lastInsertId();
        
        // Log audit
        log_audit($pdo, $user_id, 'loan_applied', "Applied for loan of ₦" . number_format($amount, 2) . " over $durationMonths months");
        
        // Calculate estimated monthly repayment (assuming 5% interest rate as example)
        $estimatedInterestRate = 5.0;
        $monthlyInterest = ($amount * $estimatedInterestRate / 100) / $durationMonths;
        $monthlyPrincipal = $amount / $durationMonths;
        $monthlyRepayment = $monthlyPrincipal + $monthlyInterest;
        
        $success = 'Loan application submitted successfully!';
        $successData = [
            'loan_id' => $loanId,
            'amount' => $amount,
            'duration' => $durationMonths,
            'monthly_repayment' => $monthlyRepayment,
            'interest_rate' => $estimatedInterestRate
        ];
        
        // Clear form
        $_POST = array();
    }
}

// Get user's previous loans for reference
$stmt = $pdo->prepare("
    SELECT id, amount, duration_months, status, applied_at 
    FROM loan_applications 
    WHERE user_id = ? 
    ORDER BY applied_at DESC 
    LIMIT 5
");
$stmt->execute([$user_id]);
$previousLoans = $stmt->fetchAll();
?>

<?php
$pageTitle = 'Apply for Loan - Beulah Coop';
$useDashboardLayout = true;
?>
<?php include '../includes/header.php'; ?>
<div class="dash-grid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="dash-title">Apply for a Loan</h2>
        <a href="my-loans.php" class="btn btn-outline-primary">
            <i class="bi bi-list-ul"></i> View My Loans
        </a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($success && $successData): ?>
        <div class="alert alert-success" role="alert">
            <h5><i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($success) ?></h5>
            <hr>
            <div class="row">
                <div class="col-md-6">
                    <p><strong>Application ID:</strong> #<?= $successData['loan_id'] ?></p>
                    <p><strong>Loan Amount:</strong> <?= format_money($successData['amount']) ?></p>
                    <p><strong>Duration:</strong> <?= $successData['duration'] ?> months</p>
                </div>
                <div class="col-md-6">
                    <p><strong>Est. Interest Rate:</strong> <?= $successData['interest_rate'] ?>%</p>
                    <p><strong>Est. Monthly Repayment:</strong> <?= format_money($successData['monthly_repayment']) ?></p>
                    <p class="text-muted"><small>* Final interest rate will be determined by admin</small></p>
                </div>
            </div>
            <a href="my-loans.php" class="btn btn-success mt-2">
                <i class="bi bi-eye"></i> Track Application Status
            </a>
        </div>
    <?php endif; ?>

    <!-- Financial Summary -->
    <div class="dash-cards mb-4">
        <div class="dash-card">
            <div class="dash-card-label">Your Total Savings</div>
            <div class="dash-card-value text-success"><?= format_money($totalSavings) ?></div>
            <div class="dash-card-sub">Maximum loan: <?= format_money($maxLoanAmount) ?></div>
        </div>
        <div class="dash-card">
            <div class="dash-card-label">Outstanding Loan</div>
            <div class="dash-card-value <?= $outstandingLoan > 0 ? 'text-danger' : 'text-success' ?>">
                <?= format_money($outstandingLoan) ?>
            </div>
            <div class="dash-card-sub">
                <?= $outstandingLoan > 0 ? 'Clear before applying' : 'No outstanding balance' ?>
            </div>
        </div>
        <div class="dash-card">
            <div class="dash-card-label">Pending Applications</div>
            <div class="dash-card-value <?= $pendingLoans > 0 ? 'text-warning' : 'text-success' ?>">
                <?= $pendingLoans ?>
            </div>
            <div class="dash-card-sub">
                <?= $pendingLoans > 0 ? 'Awaiting approval' : 'No pending applications' ?>
            </div>
        </div>
    </div>

    <?php if ($outstandingLoan == 0 && $pendingLoans == 0): ?>
    <!-- Loan Application Form -->
    <div class="dash-panel">
        <div class="dash-panel-title">
            <i class="bi bi-cash-coin me-2"></i>Loan Application Form
        </div>
        <form method="POST" action="" class="mt-3">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Loan Amount (₦) <span class="text-danger">*</span></label>
                    <input type="number" name="amount" class="form-control form-control-lg" 
                           min="1000" max="<?= $maxLoanAmount ?>" step="100" 
                           value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>" required>
                    <div class="form-text">
                        Minimum: ₦1,000 | Maximum: <?= format_money($maxLoanAmount) ?>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Repayment Duration (Months) <span class="text-danger">*</span></label>
                    <select name="duration_months" class="form-select form-select-lg" required>
                        <?php for ($i = 1; $i <= 36; $i++): ?>
                            <option value="<?= $i ?>" <?= (($_POST['duration_months'] ?? 0) == $i ? 'selected' : '') ?>>
                                <?= $i ?> month<?= $i > 1 ? 's' : '' ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Purpose of Loan <span class="text-danger">*</span></label>
                    <textarea name="purpose" class="form-control" rows="3" 
                              placeholder="Please describe why you need this loan..." required><?= htmlspecialchars($_POST['purpose'] ?? '') ?></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label">Guarantors (Optional)</label>
                    <input type="text" name="guarantor_names" class="form-control" 
                           value="<?= htmlspecialchars($_POST['guarantor_names'] ?? '') ?>"
                           placeholder="Enter names of guarantors (comma-separated)">
                    <div class="form-text">
                        Provide names of people who can guarantee your loan (optional).
                    </div>
                </div>
            </div>
            <div class="mt-4 d-flex justify-content-between">
                <a href="dashboard.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Cancel
                </a>
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="bi bi-send"></i> Submit Application
                </button>
            </div>
        </form>
    </div>
    <?php else: ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle-fill me-2"></i>
            <?php if ($outstandingLoan > 0): ?>
                You have an outstanding loan balance. Please clear it before applying for a new loan.
            <?php elseif ($pendingLoans > 0): ?>
                You have a pending loan application. Please wait for it to be processed before applying again.
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Previous Loan History -->
    <?php if (!empty($previousLoans)): ?>
    <div class="dash-panel mt-4">
        <div class="dash-panel-title">
            <i class="bi bi-clock-history me-2"></i>Previous Loan Applications
        </div>
        <div class="dash-table">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Amount</th>
                        <th>Duration</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($previousLoans as $loan): ?>
                        <tr>
                            <td><?= date('d M Y', strtotime($loan['applied_at'])) ?></td>
                            <td><?= format_money($loan['amount']) ?></td>
                            <td><?= $loan['duration_months'] ?> months</td>
                            <td>
                                <span class="badge bg-<?= 
                                    $loan['status'] === 'pending' ? 'warning' : 
                                    ($loan['status'] === 'approved' ? 'info' : 
                                    ($loan['status'] === 'disbursed' ? 'primary' : 
                                    ($loan['status'] === 'rejected' ? 'danger' : 'success'))) 
                                ?>">
                                    <?= ucfirst($loan['status']) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php include '../includes/footer.php'; ?>