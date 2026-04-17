<?php
// admin/transactions.php - Basic CRUD view (expand as needed)
require_once '../includes/auth.php';
if ($_SESSION['role'] === 'member') {
    if (is_ajax_request()) {
        json_exit(['ok' => false, 'error' => 'Access denied. Admins only.'], 403);
    }
    header("Location: ../member/dashboard.php");
    exit();
}
if ($_SESSION['role'] !== 'admin') {
    if (is_ajax_request()) {
        json_exit(['ok' => false, 'error' => 'Access denied. Admins only.'], 403);
    }
    header("Location: ../login.php");
    exit();
}

$user_filter = $_GET['user_id'] ?? null;
$query = "SELECT t.*, u.name, u.coop_no FROM transactions t JOIN users u ON t.user_id = u.id";
if ($user_filter) $query .= " WHERE t.user_id = ?";
$query .= " ORDER BY t.trans_date DESC";

$error = '';
$success = '';

function respond_json($payload) {
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit();
}

function transaction_has_created_by_column(PDO $pdo): bool
{
    static $hasCreatedBy = null;

    if ($hasCreatedBy !== null) {
        return $hasCreatedBy;
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'created_by'");
        $hasCreatedBy = (bool)$stmt->fetch();
    } catch (Throwable $e) {
        $hasCreatedBy = false;
    }

    return $hasCreatedBy;
}

function safe_log_audit(PDO $pdo, $userId, string $action, string $details = ''): void
{
    try {
        log_audit($pdo, $userId, $action, $details);
    } catch (Throwable $e) {
        error_log('Audit log failed: ' . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = isset($_POST['ajax']) && $_POST['ajax'] === '1';
    
    if (isset($_POST['add_transaction'])) {
        $memberId = (int)($_POST['member_id'] ?? 0);
        $type = $_POST['type'] ?? '';
        $amount = (float)($_POST['amount'] ?? 0);
        $date = $_POST['trans_date'] ?? '';
        $description = trim($_POST['description'] ?? '');
        $allowedTypes = ['savings_credit', 'loan_disbursed', 'loan_repayment', 'interest_charged'];
        
        if ($memberId <= 0 || !in_array($type, $allowedTypes, true) || $amount <= 0 || $date === '') {
            $error = 'Please fill in all required fields with valid values.';
            if ($isAjax) respond_json(['ok' => false, 'error' => $error]);
        } else {
            $memberStmt = $pdo->prepare("SELECT coop_no, name FROM users WHERE id = ? AND role = 'member'");
            $memberStmt->execute([$memberId]);
            $member = $memberStmt->fetch();
            if (!$member) {
                $error = 'Selected member not found.';
                if ($isAjax) respond_json(['ok' => false, 'error' => $error]);
            }

            try {
                if (transaction_has_created_by_column($pdo)) {
                    $stmt = $pdo->prepare("
                        INSERT INTO transactions (user_id, trans_date, type, amount, description, created_by)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$memberId, $date, $type, $amount, $description, $_SESSION['user_id'] ?? null]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO transactions (user_id, trans_date, type, amount, description)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$memberId, $date, $type, $amount, $description]);
                }

                $newId = (int)$pdo->lastInsertId();
                safe_log_audit($pdo, $_SESSION['user_id'], 'transaction_created', "Added {$type} for member {$memberId}");
                $success = 'Transaction added successfully.';
                if ($isAjax) {
                    respond_json([
                        'ok' => true,
                        'message' => $success,
                        'transaction' => [
                            'id' => $newId,
                            'trans_date' => $date,
                            'member_label' => $member['name'] . ' (' . $member['coop_no'] . ')',
                            'type_label' => str_replace('_', ' ', $type),
                            'type' => $type,
                            'amount' => $amount,
                            'description' => $description
                        ]
                    ]);
                }
            } catch (Throwable $e) {
                error_log('Transaction insert failed: ' . $e->getMessage());
                $error = env('APP_DEBUG', false)
                    ? 'Failed to add transaction: ' . $e->getMessage()
                    : 'Failed to add transaction. Please try again later.';
                if ($isAjax) {
                    http_response_code(500);
                    respond_json(['ok' => false, 'error' => $error]);
                }
            }
        }
    } elseif (isset($_POST['action'])) {
        $action = $_POST['action'];
        $transId = (int)($_POST['id'] ?? 0);
        
        if ($action === 'edit') {
            $type = $_POST['type'] ?? '';
            $amount = (float)($_POST['amount'] ?? 0);
            $date = $_POST['trans_date'] ?? '';
            $description = trim($_POST['description'] ?? '');
            $allowedTypes = ['savings_credit', 'loan_disbursed', 'loan_repayment', 'interest_charged'];
            
            if ($transId <= 0 || !in_array($type, $allowedTypes, true) || $amount <= 0 || $date === '') {
                $error = 'Please fill in all required fields with valid values.';
                if ($isAjax) respond_json(['ok' => false, 'error' => $error]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE transactions 
                    SET trans_date = ?, type = ?, amount = ?, description = ?
                    WHERE id = ?
                ");
                $stmt->execute([$date, $type, $amount, $description, $transId]);
                safe_log_audit($pdo, $_SESSION['user_id'], 'transaction_updated', "Updated transaction {$transId}");
                if ($isAjax) respond_json(['ok' => true, 'message' => 'Transaction updated successfully.']);
            }
        } elseif ($action === 'delete') {
            if ($transId <= 0) {
                $error = 'Invalid transaction ID.';
                if ($isAjax) respond_json(['ok' => false, 'error' => $error]);
            } else {
                $stmt = $pdo->prepare("DELETE FROM transactions WHERE id = ?");
                $stmt->execute([$transId]);
                safe_log_audit($pdo, $_SESSION['user_id'], 'transaction_deleted', "Deleted transaction {$transId}");
                if ($isAjax) respond_json(['ok' => true, 'message' => 'Transaction deleted successfully.']);
            }
        }
    }
}

$stmt = $pdo->prepare($query);
$stmt->execute($user_filter ? [$user_filter] : []);
$trans = $stmt->fetchAll();

$membersStmt = $pdo->query("SELECT id, coop_no, name FROM users WHERE role = 'member' ORDER BY coop_no");
$membersList = $membersStmt->fetchAll();
?>

<?php
$pageTitle = 'Transactions - Beulah Coop';
$useDashboardLayout = true;
$extraHead = '<link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">'
    . '<link href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css" rel="stylesheet">';
?>
<?php include '../includes/header.php'; ?>
<div class="dash-grid">
    <div class="dash-section-head">
        <h2 class="dash-title">All Transactions <?= $user_filter ? '(Filtered)' : '' ?></h2>
        <div class="dash-section-actions">
            <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#addTransactionModal">Add Transaction</button>
        </div>
    </div>
    <div id="transactionAlerts"></div>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php elseif ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <div class="dash-panel dash-panel-table">
        <div class="dash-panel-title">Transactions</div>
        <div class="dash-filters">
            <div class="dash-filter-group">
                <label for="dateFrom">From</label>
                <input type="date" id="dateFrom" class="form-control form-control-sm">
            </div>
            <div class="dash-filter-group">
                <label for="dateTo">To</label>
                <input type="date" id="dateTo" class="form-control form-control-sm">
            </div>
            <button class="btn btn-outline-secondary btn-sm" onclick="clearFilters()">
                <i class="bi bi-x-circle me-1"></i>Clear
            </button>
        </div>
        <table id="transactionsTable" class="table dash-table-grid">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Member</th>
                    <th>Type</th>
                    <th>Amount</th>
                    <th>Description</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($trans as $t): ?>
                <tr data-id="<?= (int)$t['id'] ?>"
                    data-date="<?= $t['trans_date'] ?>"
                    data-member="<?= htmlspecialchars($t['name']) ?> (<?= $t['coop_no'] ?>)"
                    data-type="<?= $t['type'] ?>"
                    data-amount="<?= $t['amount'] ?>"
                    data-description="<?= htmlspecialchars($t['description'] ?? '') ?>">
                    <td><?= $t['trans_date'] ?></td>
                    <td><?= htmlspecialchars($t['name']) ?> (<?= $t['coop_no'] ?>)</td>
                    <td><?= str_replace('_', ' ', $t['type']) ?></td>
                    <td><?= format_money($t['amount']) ?></td>
                    <td><?= htmlspecialchars($t['description'] ?? '') ?></td>
                    <td>
                        <button type="button" class="btn btn-sm btn-outline-secondary btn-edit">Edit</button>
                        <button type="button" class="btn btn-sm btn-outline-danger btn-delete">Delete</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <!-- Add New Transaction Form can be added here -->
</div>

<div class="modal fade" id="addTransactionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Transaction</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
            <form method="POST" action="" id="addTransactionForm">
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
                            <label class="form-label">Transaction Type</label>
                            <select name="type" class="form-select" required>
                                <option value="">Select type</option>
                                <option value="savings_credit">Savings Credit</option>
                                <option value="loan_disbursed">Loan Disbursed</option>
                                <option value="loan_repayment">Loan Repayment</option>
                                <option value="interest_charged">Interest Charged</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label">Amount</label>
                            <input type="number" name="amount" class="form-control" min="0" step="0.01" required>
                        </div>
                        <div>
                            <label class="form-label">Date</label>
                            <input type="date" name="trans_date" class="form-control" required>
                        </div>
                        <div>
                            <label class="form-label">Description (optional)</label>
                            <textarea name="description" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="mt-3 d-flex justify-content-end">
                        <button type="submit" name="add_transaction" class="btn btn-primary">Save Transaction</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="editTransactionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Transaction</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form method="POST" action="" id="editTransactionForm">
                    <input type="hidden" name="id" id="editTransId">
                    <div class="d-grid gap-3">
                        <div>
                            <label class="form-label">Transaction Type</label>
                            <select name="type" id="editType" class="form-select" required>
                                <option value="savings_credit">Savings Credit</option>
                                <option value="loan_disbursed">Loan Disbursed</option>
                                <option value="loan_repayment">Loan Repayment</option>
                                <option value="interest_charged">Interest Charged</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label">Amount</label>
                            <input type="number" name="amount" id="editAmount" class="form-control" min="0" step="0.01" required>
                        </div>
                        <div>
                            <label class="form-label">Date</label>
                            <input type="date" name="trans_date" id="editDate" class="form-control" required>
                        </div>
                        <div>
                            <label class="form-label">Description (optional)</label>
                            <textarea name="description" id="editDescription" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="mt-3 d-flex justify-content-end">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="edit_transaction" class="btn btn-primary">Update Transaction</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script>
const dateFrom = document.getElementById('dateFrom');
const dateTo = document.getElementById('dateTo');

$.fn.dataTable.ext.search.push(function(settings, data) {
    if (settings.nTable.id !== 'transactionsTable') return true;
    const min = dateFrom.value ? new Date(dateFrom.value) : null;
    const max = dateTo.value ? new Date(dateTo.value) : null;
    const dateStr = data[0] || '';
    const rowDate = new Date(dateStr);

    if (Number.isNaN(rowDate.getTime())) return true;
    if (min && rowDate < min) return false;
    if (max && rowDate > max) return false;
    return true;
});

const transactionsTable = $('#transactionsTable').DataTable({
    searching: true,
    pageLength: 25,
    dom: '<"dt-top"lfB>rt<"dt-bottom"ip>',
    buttons: [
        { extend: 'csvHtml5', className: 'btn btn-outline-primary btn-sm', text: 'Export CSV' },
        { extend: 'pdfHtml5', className: 'btn btn-outline-primary btn-sm', text: 'Export PDF', orientation: 'landscape' }
    ]
});

dateFrom.addEventListener('change', () => transactionsTable.draw());
dateTo.addEventListener('change', () => transactionsTable.draw());

function clearFilters() {
    dateFrom.value = '';
    dateTo.value = '';
    transactionsTable.draw();
}

const params = new URLSearchParams(window.location.search);
if (params.get('open') === 'add') {
    const modal = new bootstrap.Modal(document.getElementById('addTransactionModal'));
    modal.show();
}

function showTransactionAlert(message, type) {
    const el = document.getElementById('transactionAlerts');
    el.innerHTML = `<div class="alert alert-${type}">${message}</div>`;
}

async function postJson(data) {
    try {
        console.log('Starting AJAX request...');
        const res = await fetch('', {
            method: 'POST',
            body: data,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        console.log('Response received:', res);
        
        const contentType = res.headers.get('content-type') || '';
        console.log('Content-Type:', contentType);
        
        if (!contentType.includes('application/json')) {
            const text = await res.text();
            console.error('Non-JSON response:', text);
            console.error('Status:', res.status);
            console.error('Status Text:', res.statusText);
            
            if (res.status === 401) {
                throw new Error('Authentication required. Please log in again.');
            } else if (res.status === 403) {
                throw new Error('Access denied. Admins only.');
            } else if (res.status === 500) {
                throw new Error('Server error. Please try again later.');
            } else {
                throw new Error('Session expired or unexpected response. Please refresh and log in again.');
            }
        }
        
        const json = await res.json();
        console.log('JSON response:', json);
        return { res, json };
    } catch (err) {
        console.error('Fetch error:', err);
        throw err instanceof Error
            ? err
            : new Error('Unexpected response. Please refresh and try again.');
    }
}

document.getElementById('addTransactionForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const form = e.currentTarget;
    const data = new FormData(form);
    data.append('add_transaction', '1');
    data.append('ajax', '1');

    let json;
    try {
        ({ json } = await postJson(data));
    } catch (err) {
        showTransactionAlert(err.message || 'Unexpected response. Please refresh and try again.', 'danger');
        return;
    }
    if (!json.ok) {
        showTransactionAlert(json.error || 'Failed to add transaction.', 'danger');
        return;
    }

    const t = json.transaction;
    const rowHtml = [
        t.trans_date,
        t.member_label,
        t.type_label,
        '₦' + Number(t.amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
        t.description || '',
        `<button type="button" class="btn btn-sm btn-outline-secondary btn-edit">Edit</button>
         <button type="button" class="btn btn-sm btn-outline-danger btn-delete">Delete</button>`
    ];

    const rowApi = transactionsTable.row.add(rowHtml).draw(false);
    const rowNode = rowApi.node();
    if (rowNode) {
        rowNode.setAttribute('data-id', t.id);
        rowNode.setAttribute('data-date', t.trans_date);
        rowNode.setAttribute('data-member', t.member_label);
        rowNode.setAttribute('data-type', t.type);
        rowNode.setAttribute('data-amount', t.amount);
        rowNode.setAttribute('data-description', t.description || '');
    }
    form.reset();
    showTransactionAlert(json.message || 'Transaction added.', 'success');
    const modal = bootstrap.Modal.getInstance(document.getElementById('addTransactionModal'));
    if (modal) modal.hide();
});

// Edit Transaction
document.getElementById('transactionsTable').addEventListener('click', function(e) {
    const btn = e.target.closest('.btn-edit');
    if (!btn) return;
    const row = btn.closest('tr');
    document.getElementById('editTransId').value = row.getAttribute('data-id');
    document.getElementById('editType').value = row.getAttribute('data-type');
    document.getElementById('editAmount').value = row.getAttribute('data-amount');
    document.getElementById('editDate').value = row.getAttribute('data-date');
    document.getElementById('editDescription').value = row.getAttribute('data-description');
    const modal = new bootstrap.Modal(document.getElementById('editTransactionModal'));
    modal.show();
});

document.getElementById('editTransactionForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const form = e.currentTarget;
    const data = new FormData(form);
    data.append('action', 'edit');
    data.append('ajax', '1');

    let json;
    try {
        ({ json } = await postJson(data));
    } catch (err) {
        showTransactionAlert(err.message || 'Unexpected response. Please refresh and try again.', 'danger');
        return;
    }
    if (!json.ok) {
        showTransactionAlert(json.error || 'Failed to update transaction.', 'danger');
        return;
    }

    const id = document.getElementById('editTransId').value;
    const row = document.querySelector(`tr[data-id="${id}"]`);
    if (row) {
        const rowApi = transactionsTable.row(row);
        const rowData = rowApi.data();
        rowData[2] = document.getElementById('editType').options[document.getElementById('editType').selectedIndex].text;
        rowData[3] = '₦' + Number(document.getElementById('editAmount').value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        rowData[0] = document.getElementById('editDate').value;
        rowData[4] = document.getElementById('editDescription').value;
        rowApi.data(rowData).draw(false);
    }
    
    showTransactionAlert(json.message || 'Transaction updated.', 'success');
    bootstrap.Modal.getInstance(document.getElementById('editTransactionModal')).hide();
});

// Delete Transaction
document.getElementById('transactionsTable').addEventListener('click', async function(e) {
    const btn = e.target.closest('.btn-delete');
    if (!btn) return;
    const row = btn.closest('tr');
    const transId = row.getAttribute('data-id');
    const memberLabel = row.getAttribute('data-member');
    if (!confirm(`Delete this transaction for ${memberLabel}? This cannot be undone.`)) return;

    const data = new FormData();
    data.append('action', 'delete');
    data.append('ajax', '1');
    data.append('id', transId);

    let json;
    try {
        ({ json } = await postJson(data));
    } catch (err) {
        showTransactionAlert(err.message || 'Unexpected response. Please refresh and try again.', 'danger');
        return;
    }
    if (!json.ok) {
        showTransactionAlert(json.error || 'Failed to delete transaction.', 'danger');
        return;
    }

    transactionsTable.row(row).remove().draw(false);
    showTransactionAlert(json.message || 'Transaction deleted.', 'success');
});
</script>
<?php include '../includes/footer.php'; ?>
