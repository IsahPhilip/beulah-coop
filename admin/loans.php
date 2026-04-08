<?php
// admin/loans.php - Loan Management
require_once '../includes/auth.php';
if ($_SESSION['role'] === 'member') {
    header("Location: ../member/dashboard.php");
    exit();
}
if ($_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$error = '';
$success = '';

// Handle loan actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? null;
    $isAjax = isset($_POST['ajax']) && $_POST['ajax'] === '1';
    
    function respond_json($payload) {
        header('Content-Type: application/json');
        echo json_encode($payload);
        exit();
    }
    
    if ($action === 'approve') {
        $loanId = (int)($_POST['loan_id'] ?? 0);
        $interestRate = (float)($_POST['interest_rate'] ?? 0);
        $reviewNotes = trim($_POST['review_notes'] ?? '');
        
        if ($loanId <= 0) {
            $error = 'Invalid loan ID.';
            if ($isAjax) respond_json(['ok' => false, 'error' => $error]);
        } else {
            // Update loan status
            $stmt = $pdo->prepare("UPDATE loan_applications SET status = 'approved', interest_rate = ?, review_notes = ?, reviewed_at = NOW(), reviewed_by = ? WHERE id = ?");
            $stmt->execute([$interestRate, $reviewNotes, $_SESSION['user_id'], $loanId]);
            
            // Generate loan schedule
            $stmt = $pdo->prepare("SELECT user_id, amount, duration_months, interest_rate FROM loan_applications WHERE id = ?");
            $stmt->execute([$loanId]);
            $loan = $stmt->fetch();
            
            if ($loan) {
                $principal = $loan['amount'];
                $monthlyInterest = ($principal * $interestRate / 100) / $loan['duration_months'];
                $monthlyPrincipal = $principal / $loan['duration_months'];
                
                for ($i = 1; $i <= $loan['duration_months']; $i++) {
                    $dueDate = date('Y-m-d', strtotime("+{$i} month"));
                    $stmt = $pdo->prepare("INSERT INTO loan_schedules (loan_application_id, installment_number, due_date, principal_amount, interest_amount, total_amount) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$loanId, $i, $dueDate, $monthlyPrincipal, $monthlyInterest, $monthlyPrincipal + $monthlyInterest]);
                }
                
                // Create notification for member
                $stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
                $stmt->execute([$loan['user_id']]);
                $member = $stmt->fetch();
                
                $notificationMsg = "Your loan application of " . format_money($principal) . " has been approved with " . $interestRate . "% interest rate.";
                $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)");
                $stmt->execute([$loan['user_id'], 'Loan Approved', $notificationMsg, 'loan']);
                
                log_audit($pdo, $_SESSION['user_id'], 'loan_approved', "Approved loan {$loanId} for member {$loan['user_id']}");
                $success = 'Loan approved successfully. Repayment schedule generated.';
            }
            
            if ($isAjax) respond_json(['ok' => true, 'message' => $success]);
        }
    } elseif ($action === 'reject') {
        $loanId = (int)($_POST['loan_id'] ?? 0);
        $reviewNotes = trim($_POST['review_notes'] ?? '');
        
        if ($loanId <= 0) {
            $error = 'Invalid loan ID.';
            if ($isAjax) respond_json(['ok' => false, 'error' => $error]);
        } else {
            $stmt = $pdo->prepare("UPDATE loan_applications SET status = 'rejected', review_notes = ?, reviewed_at = NOW(), reviewed_by = ? WHERE id = ?");
            $stmt->execute([$reviewNotes, $_SESSION['user_id'], $loanId]);
            
            $stmt = $pdo->prepare("SELECT user_id FROM loan_applications WHERE id = ?");
            $stmt->execute([$loanId]);
            $loan = $stmt->fetch();
            
            if ($loan) {
                $notificationMsg = "Your loan application has been rejected. Reason: " . $reviewNotes;
                $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)");
                $stmt->execute([$loan['user_id'], 'Loan Rejected', $notificationMsg, 'loan']);
            }
            
            log_audit($pdo, $_SESSION['user_id'], 'loan_rejected', "Rejected loan {$loanId}");
            $success = 'Loan rejected.';
            if ($isAjax) respond_json(['ok' => true, 'message' => $success]);
        }
    } elseif ($action === 'disburse') {
        $loanId = (int)($_POST['loan_id'] ?? 0);
        
        if ($loanId <= 0) {
            $error = 'Invalid loan ID.';
            if ($isAjax) respond_json(['ok' => false, 'error' => $error]);
        } else {
            $stmt = $pdo->prepare("UPDATE loan_applications SET status = 'disbursed' WHERE id = ?");
            $stmt->execute([$loanId]);
            
            $stmt = $pdo->prepare("SELECT user_id, amount FROM loan_applications WHERE id = ?");
            $stmt->execute([$loanId]);
            $loan = $stmt->fetch();
            
            if ($loan) {
                // Create transaction record
                $stmt = $pdo->prepare("INSERT INTO transactions (user_id, trans_date, type, amount, description, created_by) VALUES (?, CURDATE(), 'loan_disbursed', ?, 'Loan disbursed', ?)");
                $stmt->execute([$loan['user_id'], $loan['amount'], $_SESSION['user_id']]);
                
                $notificationMsg = "Your loan of " . format_money($loan['amount']) . " has been disbursed to your account.";
                $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)");
                $stmt->execute([$loan['user_id'], 'Loan Disbursed', $notificationMsg, 'loan']);
            }
            
            log_audit($pdo, $_SESSION['user_id'], 'loan_disbursed', "Disbursed loan {$loanId}");
            $success = 'Loan disbursed successfully.';
            if ($isAjax) respond_json(['ok' => true, 'message' => $success]);
        }
    }
}

// Fetch loan applications
$statusFilter = $_GET['status'] ?? 'all';
$statusOptions = ['all', 'pending', 'approved', 'rejected', 'disbursed', 'completed', 'defaulted'];
if (!in_array($statusFilter, $statusOptions)) $statusFilter = 'all';

$query = "
    SELECT la.*, u.name, u.coop_no, u.email, 
           reviewer.name as reviewer_name
    FROM loan_applications la
    JOIN users u ON la.user_id = u.id
    LEFT JOIN users reviewer ON la.reviewed_by = reviewer.id
    WHERE 1=1
";
$params = [];

if ($statusFilter !== 'all') {
    $query .= " AND la.status = ?";
    $params[] = $statusFilter;
}

$query .= " ORDER BY la.applied_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$loans = $stmt->fetchAll();

// Get summary stats
$statsQuery = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status = 'disbursed' THEN 1 ELSE 0 END) as disbursed,
        SUM(CASE WHEN status = 'defaulted' THEN 1 ELSE 0 END) as defaulted,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
    FROM loan_applications
";
$stats = $pdo->query($statsQuery)->fetch();

$membersStmt = $pdo->query("SELECT id, coop_no, name, email FROM users WHERE role = 'member' ORDER BY coop_no");
$membersList = $membersStmt->fetchAll();
?>

<?php
$pageTitle = 'Loan Management - Beulah Coop';
$useDashboardLayout = true;
$extraHead = '<link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">';
?>
<?php include '../includes/header.php'; ?>
<div class="dash-grid">
    <div class="dash-section-head">
        <h2 class="dash-title">Loan Management</h2>
        <div class="dash-section-actions">
            <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#addLoanModal">New Loan Application</button>
        </div>
    </div>

    <div id="loanAlerts"></div>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php elseif ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <!-- Loan Summary Cards -->
    <div class="dash-cards">
        <div class="dash-card">
            <div class="dash-card-label">Total Loans</div>
            <div class="dash-card-value"><?= $stats['total'] ?? 0 ?></div>
        </div>
        <div class="dash-card">
            <div class="dash-card-label">Pending Approval</div>
            <div class="dash-card-value text-warning"><?= $stats['pending'] ?? 0 ?></div>
        </div>
        <div class="dash-card">
            <div class="dash-card-label">Active Loans</div>
            <div class="dash-card-value text-primary"><?= $stats['disbursed'] ?? 0 ?></div>
        </div>
        <div class="dash-card">
            <div class="dash-card-label">Defaulted</div>
            <div class="dash-card-value text-danger"><?= $stats['defaulted'] ?? 0 ?></div>
        </div>
    </div>

    <!-- Status Filter Tabs -->
    <div class="profile-tabs">
        <?php foreach ($statusOptions as $opt): ?>
            <a href="?status=<?= $opt ?>" class="profile-tab<?= $statusFilter === $opt ? ' active' : '' ?>">
                <?= ucfirst($opt) ?>
                <?php if ($opt !== 'all'): ?>
                    <span class="badge bg-primary-soft"><?= $stats[$opt] ?? 0 ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Loans Table -->
    <div class="dash-panel dash-panel-table">
        <div class="dash-panel-title">Loan Applications</div>
        <table id="loansTable" class="table dash-table-grid">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Member</th>
                    <th>Amount</th>
                    <th>Duration</th>
                    <th>Interest Rate</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($loans as $loan): ?>
                <tr data-id="<?= (int)$loan['id'] ?>" data-status="<?= $loan['status'] ?>">
                    <td><?= date('d M Y', strtotime($loan['applied_at'])) ?></td>
                    <td>
                        <?= htmlspecialchars($loan['name']) ?><br>
                        <small class="text-muted"><?= htmlspecialchars($loan['coop_no']) ?></small>
                    </td>
                    <td><?= format_money($loan['amount']) ?></td>
                    <td><?= $loan['duration_months'] ?> months</td>
                    <td><?= $loan['interest_rate'] > 0 ? $loan['interest_rate'] . '%' : '-' ?></td>
                    <td>
                        <span class="badge bg-<?= $loan['status'] === 'pending' ? 'warning' : ($loan['status'] === 'approved' ? 'info' : ($loan['status'] === 'disbursed' ? 'primary' : ($loan['status'] === 'rejected' ? 'danger' : 'success'))) ?>">
                            <?= ucfirst($loan['status']) ?>
                        </span>
                    </td>
                    <td>
                        <button type="button" class="btn btn-sm btn-outline-primary btn-view" data-bs-toggle="modal" data-bs-target="#viewLoanModal">View</button>
                        <?php if ($loan['status'] === 'pending'): ?>
                            <button type="button" class="btn btn-sm btn-outline-success btn-approve">Approve</button>
                            <button type="button" class="btn btn-sm btn-outline-danger btn-reject">Reject</button>
                        <?php elseif ($loan['status'] === 'approved'): ?>
                            <button type="button" class="btn btn-sm btn-outline-success btn-disburse">Disburse</button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add Loan Modal -->
<div class="modal fade" id="addLoanModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">New Loan Application</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="add">
                    <div class="d-grid gap-3">
                        <div>
                            <label class="form-label">Member</label>
                            <select name="member_id" class="form-select" required>
                                <option value="">Select member</option>
                                <?php foreach ($membersList as $m): ?>
                                    <option value="<?= (int)$m['id'] ?>">
                                        <?= htmlspecialchars($m['coop_no']) ?> - <?= htmlspecialchars($m['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="form-label">Loan Amount</label>
                            <input type="number" name="amount" class="form-control" min="1000" step="100" required>
                        </div>
                        <div>
                            <label class="form-label">Duration (Months)</label>
                            <input type="number" name="duration_months" class="form-control" min="1" max="36" value="12" required>
                        </div>
                        <div>
                            <label class="form-label">Purpose</label>
                            <textarea name="purpose" class="form-control" rows="2"></textarea>
                        </div>
                        <div>
                            <label class="form-label">Guarantors (comma-separated names)</label>
                            <input type="text" name="guarantor_names" class="form-control">
                        </div>
                    </div>
                    <div class="mt-3 d-flex justify-content-end">
                        <button type="submit" name="add_loan" class="btn btn-primary">Submit Application</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- View Loan Modal -->
<div class="modal fade" id="viewLoanModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Loan Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="loanDetails">
                <!-- Populated by JavaScript -->
            </div>
        </div>
    </div>
</div>

<!-- Approve Loan Modal -->
<div class="modal fade" id="approveLoanModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Approve Loan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="loan_id" id="approveLoanId">
                    <div class="mb-3">
                        <label class="form-label">Interest Rate (%)</label>
                        <input type="number" name="interest_rate" class="form-control" min="0" max="30" step="0.5" value="5" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Review Notes</label>
                        <textarea name="review_notes" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="d-flex justify-content-between">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Approve Loan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Reject Loan Modal -->
<div class="modal fade" id="rejectLoanModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Reject Loan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="loan_id" id="rejectLoanId">
                    <div class="mb-3">
                        <label class="form-label">Rejection Reason</label>
                        <textarea name="review_notes" class="form-control" rows="4" required></textarea>
                    </div>
                    <div class="d-flex justify-content-between">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Reject Loan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script>
const loansTable = $('#loansTable').DataTable({
    searching: true,
    pageLength: 25,
    dom: '<"dt-top"lf>rt<"dt-bottom"ip>'
});

function showLoanAlert(message, type) {
    const el = document.getElementById('loanAlerts');
    el.innerHTML = `<div class="alert alert-${type}">${message}</div>`;
}

// Handle approve button clicks
document.getElementById('loansTable').addEventListener('click', function(e) {
    const btn = e.target.closest('.btn-approve');
    if (!btn) return;
    const row = btn.closest('tr');
    document.getElementById('approveLoanId').value = row.getAttribute('data-id');
    new bootstrap.Modal(document.getElementById('approveLoanModal')).show();
});

// Handle reject button clicks
document.getElementById('loansTable').addEventListener('click', function(e) {
    const btn = e.target.closest('.btn-reject');
    if (!btn) return;
    const row = btn.closest('tr');
    document.getElementById('rejectLoanId').value = row.getAttribute('data-id');
    new bootstrap.Modal(document.getElementById('rejectLoanModal')).show();
});

// Handle disburse button clicks
document.getElementById('loansTable').addEventListener('click', function(e) {
    const btn = e.target.closest('.btn-disburse');
    if (!btn) return;
    const row = btn.closest('tr');
    if (!confirm('Confirm loan disbursement? This will create a transaction record.')) return;
    
    const data = new FormData();
    data.append('action', 'disburse');
    data.append('ajax', '1');
    data.append('loan_id', row.getAttribute('data-id'));
    
    fetch('', { method: 'POST', body: data })
        .then(res => res.json())
        .then(json => {
            if (json.ok) {
                showLoanAlert(json.message, 'success');
                location.reload();
            } else {
                showLoanAlert(json.error, 'danger');
            }
        });
});

// Handle view button clicks
document.getElementById('loansTable').addEventListener('click', function(e) {
    const btn = e.target.closest('.btn-view');
    if (!btn) return;
    const row = btn.closest('tr');
    // Populate modal with row data
    const details = document.getElementById('loanDetails');
    details.innerHTML = `
        <div class="row">
            <div class="col-md-6">
                <h6>Member Information</h6>
                <p><strong>Name:</strong> ${row.cells[1].innerHTML}</p>
                <p><strong>Amount:</strong> ${row.cells[2].textContent}</p>
                <p><strong>Duration:</strong> ${row.cells[3].textContent}</p>
            </div>
            <div class="col-md-6">
                <h6>Loan Status</h6>
                <p><strong>Status:</strong> ${row.cells[5].innerHTML}</p>
                <p><strong>Interest Rate:</strong> ${row.cells[4].textContent}</p>
            </div>
        </div>
    `;
});
</script>
<?php include '../includes/footer.php'; ?>