<?php
// admin/members.php
require_once '../includes/auth.php';
if ($_SESSION['role'] === 'member') {
    if (is_ajax_request()) json_exit(['ok' => false, 'error' => 'Access denied.'], 403);
    header("Location: ../member/dashboard.php"); exit();
}
if ($_SESSION['role'] !== 'admin') {
    if (is_ajax_request()) json_exit(['ok' => false, 'error' => 'Access denied.'], 403);
    header("Location: ../login.php"); exit();
}

function respond_json($payload) {
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit();
}

function normalize_coop_no($value) {
    $value = str_replace("\xC2\xA0", ' ', (string)$value);
    return strtoupper(preg_replace('/\s+/', ' ', trim($value)));
}

$error = '';
$success = '';
$hasMustChange = table_has_column($pdo, 'users', 'must_change_password');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? null;
    $isAjax = isset($_POST['ajax']) && $_POST['ajax'] === '1';

    if ($action === 'add') {
        $coopNo   = normalize_coop_no($_POST['coop_no'] ?? '');
        $name     = trim($_POST['name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $phone    = trim($_POST['phone'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if ($coopNo === '' || $name === '') {
            $error = 'Coop No. and Name are required.';
            if ($isAjax) respond_json(['ok' => false, 'error' => $error]);
        } else {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE coop_no = ?");
            $stmt->execute([$coopNo]);
            if ($stmt->fetch()) {
                $error = 'A member with this Coop No. already exists.';
                if ($isAjax) respond_json(['ok' => false, 'error' => $error]);
            } else {
                if ($email === '') $email = strtolower(str_replace([' ', '/'], '', $coopNo)) . '@beulahcoop.local';
                $autoPass = $password === '';
                if ($autoPass) $password = substr(bin2hex(random_bytes(6)), 0, 12);
                $hash = password_hash($password, PASSWORD_BCRYPT);

                if ($hasMustChange) {
                    $stmt = $pdo->prepare("INSERT INTO users (coop_no, name, email, phone, password_hash, role, must_change_password) VALUES (?, ?, ?, ?, ?, 'member', 1)");
                } else {
                    $stmt = $pdo->prepare("INSERT INTO users (coop_no, name, email, phone, password_hash, role) VALUES (?, ?, ?, ?, ?, 'member')");
                }
                $stmt->execute([$coopNo, $name, $email, $phone, $hash]);
                $userId = (int)$pdo->lastInsertId();
                log_audit($pdo, $_SESSION['user_id'], 'member_created', "Created member {$coopNo}");
                $success = 'Member added. Temporary password: ' . $password;
                if ($isAjax) respond_json(['ok' => true, 'message' => $success, 'member' => ['id' => $userId, 'coop_no' => $coopNo, 'name' => $name, 'email' => $email, 'phone' => $phone]]);
            }
        }
    } elseif ($action === 'edit') {
        $id    = (int)($_POST['id'] ?? 0);
        $name  = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        if ($id <= 0 || $name === '') {
            if ($isAjax) respond_json(['ok' => false, 'error' => 'Name is required.']);
        } else {
            $pdo->prepare("UPDATE users SET name=?, email=?, phone=? WHERE id=?")->execute([$name, $email, $phone, $id]);
            log_audit($pdo, $_SESSION['user_id'], 'member_updated', "Updated member {$id}");
            if ($isAjax) respond_json(['ok' => true, 'message' => 'Member updated.']);
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            if ($isAjax) respond_json(['ok' => false, 'error' => 'Invalid member.']);
        } else {
            try {
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'member'");
                $stmt->execute([$id]);
                if ($stmt->rowCount() === 0) {
                    if ($isAjax) respond_json(['ok' => false, 'error' => 'Member not found.']);
                }
                log_audit($pdo, $_SESSION['user_id'], 'member_deleted', "Deleted member {$id}");
                if ($isAjax) respond_json(['ok' => true, 'message' => 'Member deleted.']);
            } catch (PDOException $e) {
                if ($isAjax) respond_json(['ok' => false, 'error' => 'Cannot delete — member has related transactions.']);
            }
        }
    }
}

$stmt = $pdo->query("
    SELECT u.*,
           COALESCE(SUM(CASE WHEN t.type='savings_credit' THEN t.amount ELSE 0 END),0) -
           COALESCE(SUM(CASE WHEN t.type='savings_debit'  THEN t.amount ELSE 0 END),0) AS savings,
           COALESCE(SUM(CASE WHEN t.type='loan_disbursed' THEN t.amount ELSE 0 END),0) -
           COALESCE(SUM(CASE WHEN t.type='loan_repayment' THEN t.amount ELSE 0 END),0) AS loan_outstanding,
           COALESCE(SUM(CASE WHEN t.type='loan_disbursed' THEN t.amount ELSE 0 END),0) AS total_loans_issued
    FROM users u
    LEFT JOIN transactions t ON u.id = t.user_id
    WHERE u.role = 'member'
    GROUP BY u.id
    ORDER BY u.coop_no
");
$members = $stmt->fetchAll();

$totalMembers = count($members);
$totalSavings = $totalLoans = 0;
foreach ($members as $m) {
    $totalSavings += (float)($m['savings'] ?? 0);
    $totalLoans   += (float)($m['total_loans_issued'] ?? 0);
}
?>
<?php
$pageTitle = 'Members - Beulah Coop';
$useDashboardLayout = true;
$extraHead = '<link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">'
    . '<link href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css" rel="stylesheet">';
?>
<?php include '../includes/header.php'; ?>
<div class="dash-grid">
    <div class="dash-section-head">
        <h2 class="dash-title">All Members</h2>
        <div class="dash-section-actions">
            <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#addMemberModal">
                <i class="ph-bold ph-user-plus me-1"></i>Add Member
            </button>
            <a class="btn btn-outline-primary" href="import.php">
                <i class="ph-bold ph-upload-simple me-1"></i>Import Excel
            </a>
        </div>
    </div>

    <div id="memberAlerts"></div>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php elseif ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="dash-cards">
        <div class="dash-card">
            <div class="dash-card-label">Total Members</div>
            <div class="dash-card-value"><?= $totalMembers ?></div>
        </div>
        <div class="dash-card">
            <div class="dash-card-label">Total Savings</div>
            <div class="dash-card-value"><?= format_money($totalSavings) ?></div>
        </div>
        <div class="dash-card">
            <div class="dash-card-label">Total Loans Issued</div>
            <div class="dash-card-value text-danger"><?= format_money($totalLoans) ?></div>
        </div>
    </div>

    <div class="dash-panel dash-panel-table">
        <div class="dash-panel-title">Members</div>
        <div class="table-responsive">
            <table id="membersTable" class="table table-hover">
                <thead>
                    <tr>
                        <th>Coop No.</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Savings</th>
                        <th>Loan Outstanding</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($members as $m): ?>
                    <tr data-id="<?= (int)$m['id'] ?>"
                        data-coop-no="<?= htmlspecialchars($m['coop_no']) ?>"
                        data-name="<?= htmlspecialchars($m['name']) ?>"
                        data-email="<?= htmlspecialchars($m['email'] ?? '') ?>"
                        data-phone="<?= htmlspecialchars($m['phone'] ?? '') ?>">
                        <td><span class="tbl-coop-chip"><?= htmlspecialchars($m['coop_no']) ?></span></td>
                        <td>
                            <div class="tbl-name"><?= htmlspecialchars($m['name']) ?></div>
                        </td>
                        <td class="tbl-sub"><?= htmlspecialchars($m['email'] ?? '—') ?></td>
                        <td class="tbl-sub"><?= htmlspecialchars($m['phone'] ?? '—') ?></td>
                        <td><span class="tbl-amount positive"><?= format_money($m['savings'] ?? 0) ?></span></td>
                        <td><span class="tbl-amount <?= ($m['loan_outstanding'] ?? 0) > 0 ? 'negative' : 'neutral' ?>"><?= format_money($m['loan_outstanding'] ?? 0) ?></span></td>
                        <td>
                            <div class="tbl-actions">
                                <a href="transactions.php?user_id=<?= $m['id'] ?>" class="btn-icon btn-icon-view" title="View Transactions">
                                    <i class="ph-bold ph-eye"></i>
                                </a>
                                <button type="button" class="btn-icon btn-icon-edit btn-edit" title="Edit Member">
                                    <i class="ph-bold ph-pencil-simple"></i>
                                </button>
                                <button type="button" class="btn-icon btn-icon-delete btn-delete" title="Delete Member">
                                    <i class="ph-bold ph-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Member Modal -->
<div class="modal fade" id="addMemberModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="ph-bold ph-user-plus me-2"></i>Add New Member</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="addMemberForm">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Coop No.</label>
                            <input type="text" name="coop_no" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Full Name</label>
                            <input type="text" name="name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email <span class="text-muted fw-normal">(optional)</span></label>
                            <input type="email" name="email" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone <span class="text-muted fw-normal">(optional)</span></label>
                            <input type="text" name="phone" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Password <span class="text-muted fw-normal">(leave blank to auto-generate)</span></label>
                            <input type="text" name="password" class="form-control">
                        </div>
                    </div>
                    <div class="mt-4 d-flex align-items-center justify-content-between">
                        <small class="text-muted"><i class="ph-bold ph-info me-1"></i>Member will be prompted to change their password on first login.</small>
                        <button type="submit" name="add_member" class="btn btn-primary"><i class="ph-bold ph-user-plus me-1"></i>Add Member</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Edit Member Modal -->
<div class="modal fade" id="editMemberModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="ph-bold ph-pencil-simple me-2"></i>Edit Member</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editMemberId">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Coop No.</label>
                        <input type="text" id="editCoopNo" class="form-control" disabled>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Full Name</label>
                        <input type="text" id="editName" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email</label>
                        <input type="email" id="editEmail" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Phone</label>
                        <input type="text" id="editPhone" class="form-control">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="saveMemberChanges"><i class="ph-bold ph-floppy-disk me-1"></i>Save Changes</button>
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
const membersTable = $('#membersTable').DataTable({
    searching: true,
    pageLength: 25,
    dom: '<"dt-top"lfB>rt<"dt-bottom"ip>',
    buttons: [
        { extend: 'csvHtml5', className: 'btn btn-outline-primary btn-sm', text: '<i class="ph-bold ph-file-csv me-1"></i>Export CSV' },
        { extend: 'pdfHtml5', className: 'btn btn-outline-primary btn-sm', text: '<i class="ph-bold ph-file-pdf me-1"></i>Export PDF', orientation: 'landscape' }
    ],
    columnDefs: [{ orderable: false, targets: -1 }]
});

function showAlert(msg, type) {
    const el = document.getElementById('memberAlerts');
    el.innerHTML = `<div class="alert alert-${type}">${msg}</div>`;
    el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

async function postJson(data) {
    const res = await fetch('', { method: 'POST', body: data, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    const ct = res.headers.get('content-type') || '';
    if (!ct.includes('application/json')) throw new Error('Session expired. Please refresh and log in again.');
    return res.json();
}

document.getElementById('addMemberForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const data = new FormData(this);
    data.append('action', 'add');
    data.append('ajax', '1');
    let json;
    try { json = await postJson(data); } catch(err) { showAlert(err.message, 'danger'); return; }
    if (!json.ok) { showAlert(json.error || 'Failed to add member.', 'danger'); return; }

    const m = json.member;
    const actionHtml = `<div class="tbl-actions">
        <a href="transactions.php?user_id=${m.id}" class="btn-icon btn-icon-view" title="View Transactions"><i class="ph-bold ph-eye"></i></a>
        <button type="button" class="btn-icon btn-icon-edit btn-edit" title="Edit Member"><i class="ph-bold ph-pencil-simple"></i></button>
        <button type="button" class="btn-icon btn-icon-delete btn-delete" title="Delete Member"><i class="ph-bold ph-trash"></i></button>
    </div>`;
    const rowNode = membersTable.row.add([
        `<span class="tbl-coop-chip">${m.coop_no}</span>`,
        `<div class="tbl-name">${m.name}</div>`,
        `<span class="tbl-sub">${m.email || '—'}</span>`,
        `<span class="tbl-sub">${m.phone || '—'}</span>`,
        `<span class="tbl-amount positive">₦0.00</span>`,
        `<span class="tbl-amount neutral">₦0.00</span>`,
        actionHtml
    ]).draw(false).node();
    rowNode.dataset.id = m.id;
    rowNode.dataset.coopNo = m.coop_no;
    rowNode.dataset.name = m.name;
    rowNode.dataset.email = m.email || '';
    rowNode.dataset.phone = m.phone || '';

    this.reset();
    showAlert(json.message, 'success');
    bootstrap.Modal.getInstance(document.getElementById('addMemberModal')).hide();
});

document.getElementById('membersTable').addEventListener('click', function(e) {
    const btn = e.target.closest('.btn-edit');
    if (!btn) return;
    const row = btn.closest('tr');
    document.getElementById('editMemberId').value = row.dataset.id;
    document.getElementById('editCoopNo').value   = row.dataset.coopNo || '';
    document.getElementById('editName').value     = row.dataset.name || '';
    document.getElementById('editEmail').value    = row.dataset.email || '';
    document.getElementById('editPhone').value    = row.dataset.phone || '';
    new bootstrap.Modal(document.getElementById('editMemberModal')).show();
});

document.getElementById('saveMemberChanges').addEventListener('click', async function() {
    const data = new FormData();
    data.append('action', 'edit'); data.append('ajax', '1');
    data.append('id',    document.getElementById('editMemberId').value);
    data.append('name',  document.getElementById('editName').value.trim());
    data.append('email', document.getElementById('editEmail').value.trim());
    data.append('phone', document.getElementById('editPhone').value.trim());
    let json;
    try { json = await postJson(data); } catch(err) { showAlert(err.message, 'danger'); return; }
    if (!json.ok) { showAlert(json.error || 'Failed to update.', 'danger'); return; }

    const id  = document.getElementById('editMemberId').value;
    const row = document.querySelector(`tr[data-id="${id}"]`);
    if (row) {
        row.dataset.name  = document.getElementById('editName').value.trim();
        row.dataset.email = document.getElementById('editEmail').value.trim();
        row.dataset.phone = document.getElementById('editPhone').value.trim();
        const d = membersTable.row(row).data();
        d[1] = `<div class="tbl-name">${row.dataset.name}</div>`;
        d[2] = `<span class="tbl-sub">${row.dataset.email || '—'}</span>`;
        d[3] = `<span class="tbl-sub">${row.dataset.phone || '—'}</span>`;
        membersTable.row(row).data(d).draw(false);
    }
    showAlert(json.message || 'Member updated.', 'success');
    bootstrap.Modal.getInstance(document.getElementById('editMemberModal')).hide();
});

document.getElementById('membersTable').addEventListener('click', async function(e) {
    const btn = e.target.closest('.btn-delete');
    if (!btn) return;
    const row = btn.closest('tr');
    if (!confirm(`Delete ${row.dataset.coopNo || 'this member'}? This cannot be undone.`)) return;
    const data = new FormData();
    data.append('action', 'delete'); data.append('ajax', '1'); data.append('id', row.dataset.id);
    let json;
    try { json = await postJson(data); } catch(err) { showAlert(err.message, 'danger'); return; }
    if (!json.ok) { showAlert(json.error || 'Failed to delete.', 'danger'); return; }
    membersTable.row(row).remove().draw(false);
    showAlert(json.message || 'Member deleted.', 'success');
});
</script>
<?php include '../includes/footer.php'; ?>
