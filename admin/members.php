<?php
// admin/members.php
require_once '../includes/auth.php';
if ($_SESSION['role'] === 'member') {
    header("Location: ../member/dashboard.php");
    exit();
}
if ($_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

function respond_json($payload) {
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit();
}

function normalize_coop_no($value) {
    $value = (string)$value;
    $value = str_replace("\xC2\xA0", ' ', $value);
    $value = trim($value);
    $value = preg_replace('/\s+/', ' ', $value);
    return strtoupper($value);
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? null;
    $isAjax = isset($_POST['ajax']) && $_POST['ajax'] === '1';

    if ($action === 'add') {
        $coopNo = normalize_coop_no($_POST['coop_no'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if ($coopNo === '' || $name === '') {
            $error = 'Coop No. and Name are required.';
            if ($isAjax) respond_json(['ok' => false, 'error' => $error]);
        } else {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE coop_no = ?");
            $stmt->execute([$coopNo]);
            $exists = $stmt->fetch();

            if ($exists) {
                $error = 'A member with this Coop No. already exists.';
                if ($isAjax) respond_json(['ok' => false, 'error' => $error]);
            } else {
                if ($email === '') {
                    $email = strtolower(str_replace([' ', '/'], '', $coopNo)) . '@beulahcoop.local';
                }
                if ($password === '') {
                    $password = substr(bin2hex(random_bytes(6)), 0, 12);
                }
                $passwordHash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("
                    INSERT INTO users (coop_no, name, email, phone, password_hash, role)
                    VALUES (?, ?, ?, ?, ?, 'member')
                ");
                $stmt->execute([$coopNo, $name, $email, $phone, $passwordHash]);

                $userId = (int)$pdo->lastInsertId();
                log_audit($pdo, $_SESSION['user_id'], 'member_created', "Created member {$coopNo}");
                $success = 'Member added successfully. Temporary password: ' . $password;

                if ($isAjax) {
                    respond_json([
                        'ok' => true,
                        'member' => [
                            'id' => $userId,
                            'coop_no' => $coopNo,
                            'name' => $name,
                            'email' => $email,
                            'phone' => $phone,
                            'savings' => 0,
                            'loan_outstanding' => 0
                        ],
                        'message' => $success
                    ]);
                }
            }
        }
    } elseif ($action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        if ($id <= 0 || $name === '') {
            $error = 'Name is required.';
            if ($isAjax) respond_json(['ok' => false, 'error' => $error]);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, phone = ? WHERE id = ?");
            $stmt->execute([$name, $email, $phone, $id]);

            log_audit($pdo, $_SESSION['user_id'], 'member_updated', "Updated member {$id}");
            if ($isAjax) respond_json(['ok' => true, 'message' => 'Member updated.']);
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $error = 'Invalid member.';
            if ($isAjax) respond_json(['ok' => false, 'error' => $error]);
        } else {
            try {
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'member'");
                $stmt->execute([$id]);
                if ($stmt->rowCount() === 0) {
                    $error = 'Member not found.';
                    if ($isAjax) respond_json(['ok' => false, 'error' => $error]);
                }
                log_audit($pdo, $_SESSION['user_id'], 'member_deleted', "Deleted member {$id}");
                if ($isAjax) respond_json(['ok' => true, 'message' => 'Member deleted.']);
            } catch (PDOException $e) {
                $error = 'Unable to delete member. They may have related transactions.';
                if ($isAjax) respond_json(['ok' => false, 'error' => $error]);
            }
        }
    } elseif (isset($_POST['add_member'])) {
        $coopNo = normalize_coop_no($_POST['coop_no'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if ($coopNo === '' || $name === '') {
            $error = 'Coop No. and Name are required.';
        } else {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE coop_no = ?");
            $stmt->execute([$coopNo]);
            $exists = $stmt->fetch();

            if ($exists) {
                $error = 'A member with this Coop No. already exists.';
            } else {
                if ($email === '') {
                    $email = strtolower(str_replace([' ', '/'], '', $coopNo)) . '@beulahcoop.local';
                }
                if ($password === '') {
                    $password = substr(bin2hex(random_bytes(6)), 0, 12);
                }
                $passwordHash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("
                    INSERT INTO users (coop_no, name, email, phone, password_hash, role)
                    VALUES (?, ?, ?, ?, ?, 'member')
                ");
                $stmt->execute([$coopNo, $name, $email, $phone, $passwordHash]);

                log_audit($pdo, $_SESSION['user_id'], 'member_created', "Created member {$coopNo}");
                $success = 'Member added successfully. Temporary password: ' . $password;
            }
        }
    }
}

$stmt = $pdo->query("
    SELECT u.*, 
           SUM(CASE WHEN t.type IN ('savings_credit') THEN t.amount ELSE 0 END) as savings,
           SUM(CASE WHEN t.type = 'loan_disbursed' THEN t.amount ELSE 0 END) -
           SUM(CASE WHEN t.type = 'loan_repayment' THEN t.amount ELSE 0 END) as loan_outstanding
    FROM users u 
    LEFT JOIN transactions t ON u.id = t.user_id 
    GROUP BY u.id 
    ORDER BY u.coop_no
");
$members = $stmt->fetchAll();
?>

<?php
$pageTitle = 'Members - Beulah Coop';
$useDashboardLayout = true;
$extraHead = '<link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">';
?>
<?php include '../includes/header.php'; ?>
<div class="dash-grid">
    <div class="dash-section-head">
        <h2 class="dash-title">All Members & Balances</h2>
        <div class="dash-section-actions">
            <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#addMemberModal">Add Member</button>
            <a class="btn btn-outline-primary" href="import.php">Import Excel</a>
        </div>
    </div>
    <div id="memberAlerts"></div>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php elseif ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="dash-split">
        <div class="dash-panel dash-panel-table">
            <div class="dash-panel-title">Members</div>
            <table id="membersTable" class="table dash-table-grid">
            <thead>
                <tr>
                    <th>Coop No.</th>
                    <th>Name</th>
                    <th>Savings</th>
                    <th>Loan Outstanding</th>
                    <th>Phone</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($members as $m): ?>
                <tr data-id="<?= (int)$m['id'] ?>"
                    data-coop-no="<?= htmlspecialchars($m['coop_no']) ?>"
                    data-name="<?= htmlspecialchars($m['name']) ?>"
                    data-email="<?= htmlspecialchars($m['email'] ?? '') ?>"
                    data-phone="<?= htmlspecialchars($m['phone'] ?? '') ?>">
                    <td><?= htmlspecialchars($m['coop_no']) ?></td>
                    <td><?= htmlspecialchars($m['name']) ?></td>
                    <td><?= format_money($m['savings'] ?? 0) ?></td>
                    <td><?= format_money($m['loan_outstanding'] ?? 0) ?></td>
                    <td><?= htmlspecialchars($m['phone'] ?? '') ?></td>
                    <td>
                        <a href="transactions.php?user_id=<?= $m['id'] ?>" class="btn btn-sm btn-primary">View</a>
                        <button type="button" class="btn btn-sm btn-outline-secondary btn-edit">Edit</button>
                        <button type="button" class="btn btn-sm btn-outline-danger btn-delete">Delete</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            </table>
        </div>
        <aside class="dash-panel dash-summary">
            <div class="dash-panel-title">Summary</div>
            <div class="dash-summary-card">
                <div class="dash-summary-label">Total Members</div>
                <div class="dash-summary-value"><?= count($members) ?></div>
            </div>
            <?php
            $totalSavings = 0;
            $totalLoans = 0;
            foreach ($members as $m) {
                $totalSavings += (float)($m['savings'] ?? 0);
                $totalLoans += (float)($m['loan_outstanding'] ?? 0);
            }
            ?>
            <div class="dash-summary-card">
                <div class="dash-summary-label">Total Savings</div>
                <div class="dash-summary-value"><?= format_money($totalSavings) ?></div>
            </div>
            <div class="dash-summary-card">
                <div class="dash-summary-label">Total Loans</div>
                <div class="dash-summary-value"><?= format_money($totalLoans) ?></div>
            </div>
            <div class="dash-summary-card">
                <div class="dash-summary-label">Savings vs Loans</div>
                <div class="dash-donut">
                    <canvas id="summaryDonut"></canvas>
                </div>
                <div class="dash-donut-legend">
                    <span><i class="dot dot-savings"></i>Savings</span>
                    <span><i class="dot dot-loans"></i>Loans</span>
                </div>
            </div>
            <div class="dash-summary-note">
                Updated on <?= date('j M Y') ?>
            </div>
        </aside>
    </div>
</div>

<div class="modal fade" id="addMemberModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Member</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form method="POST" action="" id="addMemberForm">
                    <div class="d-grid gap-3">
                        <div>
                            <label class="form-label">Coop No.</label>
                            <input type="text" name="coop_no" class="form-control" required>
                        </div>
                        <div>
                            <label class="form-label">Full Name</label>
                            <input type="text" name="name" class="form-control" required>
                        </div>
                        <div>
                            <label class="form-label">Password (leave blank to auto-generate)</label>
                            <input type="text" name="password" class="form-control">
                        </div>
                        <div>
                            <label class="form-label">Email (optional)</label>
                            <input type="email" name="email" class="form-control">
                        </div>
                        <div>
                            <label class="form-label">Phone (optional)</label>
                            <input type="text" name="phone" class="form-control">
                        </div>
                    </div>
                    <div class="mt-3 d-flex align-items-center justify-content-between">
                        <small class="text-muted">If left blank, a temporary password is generated.</small>
                        <button type="submit" name="add_member" class="btn btn-primary">Add Member</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="editMemberModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Member</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editMemberId">
                <div class="d-grid gap-3">
                    <div>
                        <label class="form-label">Coop No.</label>
                        <input type="text" id="editCoopNo" class="form-control" disabled>
                    </div>
                    <div>
                        <label class="form-label">Full Name</label>
                        <input type="text" id="editName" class="form-control" required>
                    </div>
                    <div>
                        <label class="form-label">Email</label>
                        <input type="email" id="editEmail" class="form-control">
                    </div>
                    <div>
                        <label class="form-label">Phone</label>
                        <input type="text" id="editPhone" class="form-control">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="saveMemberChanges">Save Changes</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script>
const membersTable = $('#membersTable').DataTable({ "searching": true, "pageLength": 25 });

const summaryCtx = document.getElementById('summaryDonut');
if (summaryCtx) {
    new Chart(summaryCtx, {
        type: 'doughnut',
        data: {
            labels: ['Savings', 'Loans'],
            datasets: [{
                data: [<?= (float)$totalSavings ?>, <?= (float)$totalLoans ?>],
                backgroundColor: ['#7a5cff', '#ff9f7a'],
                borderWidth: 0
            }]
        },
        options: {
            cutout: '70%',
            plugins: { legend: { display: false } },
            responsive: true
        }
    });
}

function showMemberAlert(message, type) {
    const el = document.getElementById('memberAlerts');
    el.innerHTML = `<div class="alert alert-${type}">${message}</div>`;
}

document.getElementById('addMemberForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const form = e.currentTarget;
    const data = new FormData(form);
    data.append('action', 'add');
    data.append('ajax', '1');

    const res = await fetch('', { method: 'POST', body: data });
    const json = await res.json();
    if (!json.ok) {
        showMemberAlert(json.error || 'Failed to add member.', 'danger');
        return;
    }

    const m = json.member;
    const rowHtml = [
        m.coop_no,
        m.name,
        '₦0.00',
        '₦0.00',
        m.phone || '',
        `<a href="transactions.php?user_id=${m.id}" class="btn btn-sm btn-primary">View</a>
         <button type="button" class="btn btn-sm btn-outline-secondary btn-edit">Edit</button>
         <button type="button" class="btn btn-sm btn-outline-danger btn-delete">Delete</button>`
    ];

    const rowNode = membersTable.row.add(rowHtml).draw(false).node();
    rowNode.setAttribute('data-id', m.id);
    rowNode.setAttribute('data-coop-no', m.coop_no);
    rowNode.setAttribute('data-name', m.name);
    rowNode.setAttribute('data-email', m.email || '');
    rowNode.setAttribute('data-phone', m.phone || '');

    form.reset();
    showMemberAlert(json.message || 'Member added.', 'success');
    const addModal = bootstrap.Modal.getInstance(document.getElementById('addMemberModal'));
    if (addModal) addModal.hide();
});

document.getElementById('membersTable').addEventListener('click', function(e) {
    const btn = e.target.closest('.btn-edit');
    if (!btn) return;
    const row = btn.closest('tr');
    document.getElementById('editMemberId').value = row.getAttribute('data-id');
    document.getElementById('editCoopNo').value = row.getAttribute('data-coop-no') || '';
    document.getElementById('editName').value = row.getAttribute('data-name') || '';
    document.getElementById('editEmail').value = row.getAttribute('data-email') || '';
    document.getElementById('editPhone').value = row.getAttribute('data-phone') || '';
    const modal = new bootstrap.Modal(document.getElementById('editMemberModal'));
    modal.show();
});

document.getElementById('membersTable').addEventListener('click', function(e) {
    const row = e.target.closest('tr');
    if (!row || row.parentElement.tagName !== 'TBODY') return;
    document.querySelectorAll('#membersTable tbody tr').forEach(r => r.classList.remove('table-selected'));
    row.classList.add('table-selected');
});

document.getElementById('saveMemberChanges').addEventListener('click', async function() {
    const data = new FormData();
    data.append('action', 'edit');
    data.append('ajax', '1');
    data.append('id', document.getElementById('editMemberId').value);
    data.append('name', document.getElementById('editName').value.trim());
    data.append('email', document.getElementById('editEmail').value.trim());
    data.append('phone', document.getElementById('editPhone').value.trim());

    const res = await fetch('', { method: 'POST', body: data });
    const json = await res.json();
    if (!json.ok) {
        showMemberAlert(json.error || 'Failed to update member.', 'danger');
        return;
    }

    const id = document.getElementById('editMemberId').value;
    const row = document.querySelector(`tr[data-id="${id}"]`);
    if (row) {
        row.setAttribute('data-name', document.getElementById('editName').value.trim());
        row.setAttribute('data-email', document.getElementById('editEmail').value.trim());
        row.setAttribute('data-phone', document.getElementById('editPhone').value.trim());

        const rowApi = membersTable.row(row);
        const rowData = rowApi.data();
        rowData[1] = row.getAttribute('data-name');
        rowData[4] = row.getAttribute('data-phone');
        rowApi.data(rowData).draw(false);
    }
    showMemberAlert(json.message || 'Member updated.', 'success');
    bootstrap.Modal.getInstance(document.getElementById('editMemberModal')).hide();
});

document.getElementById('membersTable').addEventListener('click', async function(e) {
    const btn = e.target.closest('.btn-delete');
    if (!btn) return;
    const row = btn.closest('tr');
    const coopNo = row.getAttribute('data-coop-no') || 'this member';
    if (!confirm(`Delete ${coopNo}? This cannot be undone.`)) return;

    const data = new FormData();
    data.append('action', 'delete');
    data.append('ajax', '1');
    data.append('id', row.getAttribute('data-id'));

    const res = await fetch('', { method: 'POST', body: data });
    const json = await res.json();
    if (!json.ok) {
        showMemberAlert(json.error || 'Failed to delete member.', 'danger');
        return;
    }

    membersTable.row(row).remove().draw(false);
    showMemberAlert(json.message || 'Member deleted.', 'success');
});
</script>
<?php include '../includes/footer.php'; ?>
