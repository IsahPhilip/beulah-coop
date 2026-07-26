<?php
// admin/registrations.php - Pending registration queue
require_once '../includes/auth.php';
if ($_SESSION['role'] !== 'admin') {
    header("Location: ../login.php"); exit();
}

require_once '../vendor/autoload.php';

function respond_json($payload) {
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit();
}

// Generate next coop no. in format BC/001
function generate_coop_no(PDO $pdo): string {
    $stmt = $pdo->query("SELECT coop_no FROM users WHERE coop_no REGEXP '^BC/[0-9]+$' ORDER BY CAST(SUBSTRING(coop_no,4) AS UNSIGNED) DESC LIMIT 1");
    $last = $stmt->fetchColumn();
    $next = $last ? ((int)substr($last, 3) + 1) : 1;
    return 'BC/' . str_pad($next, 3, '0', STR_PAD_LEFT);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $userId = (int)($_POST['user_id'] ?? 0);

    if ($action === 'activate' && $userId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND registration_status = 'pending'");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user) respond_json(['ok' => false, 'error' => 'Member not found or already processed.']);

        $coopNo = generate_coop_no($pdo);

        $pdo->beginTransaction();
        try {
            // Activate member
            $pdo->prepare("
                UPDATE users SET
                    registration_status = 'active',
                    coop_no = ?,
                    registration_paid_at = NOW(),
                    registration_confirmed_by = ?
                WHERE id = ?
            ")->execute([$coopNo, $_SESSION['user_id'], $userId]);

            // Record ₦2,000 registration fee transaction
            create_transaction($pdo, $userId, date('Y-m-d'), 'registration_fee', 2000.00, 'Registration fee payment', $_SESSION['user_id']);

            // Generate PDF receipt
            $receiptPath = 'uploads/receipts/receipt_' . $userId . '_' . time() . '.pdf';
            $pdf = new TCPDF('P', 'mm', 'A5', true, 'UTF-8');
            $pdf->SetCreator('Beulah Coop');
            $pdf->SetAuthor('Beulah Cooperative Society');
            $pdf->SetTitle('Registration Receipt');
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            $pdf->AddPage();
            $pdf->SetFont('helvetica', 'B', 18);
            $pdf->SetTextColor(79, 70, 229);
            $pdf->Cell(0, 10, 'Beulah Cooperative Society', 0, 1, 'C');
            $pdf->SetFont('helvetica', '', 11);
            $pdf->SetTextColor(75, 85, 99);
            $pdf->Cell(0, 6, 'Registration Fee Receipt', 0, 1, 'C');
            $pdf->Ln(6);
            $pdf->SetDrawColor(229, 231, 235);
            $pdf->Line(15, $pdf->GetY(), 133, $pdf->GetY());
            $pdf->Ln(5);
            $pdf->SetFont('helvetica', '', 10);
            $pdf->SetTextColor(55, 65, 81);
            $rows = [
                ['Member Name',   $user['name']],
                ['Coop No.',      $coopNo],
                ['Email',         $user['email']],
                ['Phone',         $user['phone'] ?? '—'],
                ['Amount Paid',   '₦2,000.00'],
                ['Date',          date('d M Y')],
                ['Status',        'PAID — Account Activated'],
            ];
            foreach ($rows as [$label, $value]) {
                $pdf->SetFont('helvetica', 'B', 9);
                $pdf->Cell(45, 7, $label . ':', 0, 0);
                $pdf->SetFont('helvetica', '', 9);
                $pdf->Cell(0, 7, $value, 0, 1);
            }
            $pdf->Ln(4);
            $pdf->Line(15, $pdf->GetY(), 133, $pdf->GetY());
            $pdf->Ln(4);
            $pdf->SetFont('helvetica', 'I', 8);
            $pdf->SetTextColor(107, 114, 128);
            $pdf->Cell(0, 5, 'Thank you for joining Beulah Cooperative Society.', 0, 1, 'C');
            $pdf->Output(__DIR__ . '/../' . $receiptPath, 'F');

            // Email receipt to member
            $receiptUrl = rtrim(env('APP_URL', 'http://localhost/codes/beulah-coop'), '/') . '/' . $receiptPath;
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = env('MAIL_HOST', 'smtp.gmail.com');
            $mail->SMTPAuth   = true;
            $mail->Username   = env('MAIL_USERNAME', '');
            $mail->Password   = env('MAIL_PASSWORD', '');
            $mail->SMTPSecure = env('MAIL_ENCRYPTION', 'tls');
            $mail->Port       = (int)env('MAIL_PORT', 587);
            $mail->setFrom(env('MAIL_FROM_ADDRESS', 'no-reply@beulahcoop.local'), env('MAIL_FROM_NAME', 'Beulah Coop'));
            $mail->addAddress($user['email'], $user['name']);
            $mail->isHTML(true);
            $mail->Subject = 'Welcome to Beulah Coop — Account Activated';
            $mail->Body    = "
                <p>Dear {$user['name']},</p>
                <p>Your registration has been confirmed and your account is now <strong>active</strong>.</p>
                <p><strong>Your Coop No. is: {$coopNo}</strong></p>
                <p>A receipt for your ₦2,000 registration fee is attached to this email.</p>
                <p>You can now log in and access all member features.</p>
                <br><p>— Beulah Cooperative Society</p>
            ";
            $mail->addAttachment(__DIR__ . '/../' . $receiptPath);
            $mail->send();

            $pdo->commit();
            log_audit($pdo, $_SESSION['user_id'], 'registration_activated', "Activated member {$userId} as {$coopNo}");
            respond_json(['ok' => true, 'message' => "Member activated as {$coopNo}. Receipt emailed.", 'coop_no' => $coopNo]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            $msg = env('APP_DEBUG', false) ? $e->getMessage() : 'Activation failed. Please try again.';
            respond_json(['ok' => false, 'error' => $msg]);
        }
    }

    if ($action === 'reject' && $userId > 0) {
        $reason = trim($_POST['reason'] ?? '');
        $stmt = $pdo->prepare("SELECT email, name FROM users WHERE id = ? AND registration_status = 'pending'");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) respond_json(['ok' => false, 'error' => 'Member not found.']);

        $pdo->prepare("DELETE FROM users WHERE id = ? AND registration_status = 'pending'")->execute([$userId]);
        log_audit($pdo, $_SESSION['user_id'], 'registration_rejected', "Rejected registration for user {$userId}");

        // Notify member
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = env('MAIL_HOST', 'smtp.gmail.com');
            $mail->SMTPAuth   = true;
            $mail->Username   = env('MAIL_USERNAME', '');
            $mail->Password   = env('MAIL_PASSWORD', '');
            $mail->SMTPSecure = env('MAIL_ENCRYPTION', 'tls');
            $mail->Port       = (int)env('MAIL_PORT', 587);
            $mail->setFrom(env('MAIL_FROM_ADDRESS', 'no-reply@beulahcoop.local'), env('MAIL_FROM_NAME', 'Beulah Coop'));
            $mail->addAddress($user['email'], $user['name']);
            $mail->isHTML(true);
            $mail->Subject = 'Beulah Coop — Registration Update';
            $mail->Body    = "<p>Dear {$user['name']},</p><p>Unfortunately your registration could not be approved." . ($reason ? " Reason: {$reason}" : '') . "</p><p>Please contact us for more information.</p><br><p>— Beulah Cooperative Society</p>";
            $mail->send();
        } catch (Throwable $e) { /* silent */ }

        respond_json(['ok' => true, 'message' => 'Registration rejected and member notified.']);
    }
}

// Fetch pending registrations
$pending = $pdo->query("
    SELECT u.*, g.name AS g_name, g.phone AS g_phone, g.coop_no AS g_coop_no
    FROM users u
    LEFT JOIN guarantors g ON g.user_id = u.id
    WHERE u.registration_status = 'pending'
    ORDER BY u.email_verified_at DESC
")->fetchAll();

$counts = $pdo->query("
    SELECT
        SUM(registration_status = 'pending')    AS pending,
        SUM(registration_status = 'unverified') AS unverified,
        SUM(registration_status = 'active' AND role = 'member') AS active
    FROM users WHERE role = 'member'
")->fetch();
?>
<?php
$pageTitle = 'Pending Registrations - Beulah Coop';
$useDashboardLayout = true;
$extraHead = '<link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">';
?>
<?php include '../includes/header.php'; ?>
<div class="dash-grid">
    <div class="dash-section-head">
        <h2 class="dash-title">Pending Registrations</h2>
    </div>

    <div id="regAlerts"></div>

    <div class="dash-cards">
        <div class="dash-card">
            <div class="dash-card-label">Awaiting Activation</div>
            <div class="dash-card-value text-warning"><?= (int)($counts['pending'] ?? 0) ?></div>
        </div>
        <div class="dash-card">
            <div class="dash-card-label">Unverified Email</div>
            <div class="dash-card-value text-secondary"><?= (int)($counts['unverified'] ?? 0) ?></div>
        </div>
        <div class="dash-card">
            <div class="dash-card-label">Active Members</div>
            <div class="dash-card-value text-success"><?= (int)($counts['active'] ?? 0) ?></div>
        </div>
    </div>

    <div class="dash-panel dash-panel-table">
        <div class="dash-panel-title">Members Awaiting Fee Confirmation</div>
        <?php if (empty($pending)): ?>
            <div class="text-center text-muted py-5">
                <i class="ph-bold ph-check-circle" style="font-size:3rem;color:#10B981;"></i>
                <p class="mt-3">No pending registrations. All caught up!</p>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table id="regTable" class="table table-hover">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Verified</th>
                        <th>Guarantor</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pending as $u): ?>
                    <tr data-id="<?= $u['id'] ?>"
                        data-name="<?= htmlspecialchars($u['name']) ?>"
                        data-email="<?= htmlspecialchars($u['email']) ?>">
                        <td><div class="tbl-name"><?= htmlspecialchars($u['name']) ?></div></td>
                        <td class="tbl-sub"><?= htmlspecialchars($u['email']) ?></td>
                        <td class="tbl-sub"><?= htmlspecialchars($u['phone'] ?? '—') ?></td>
                        <td>
                            <?php if ($u['email_verified_at']): ?>
                                <span class="badge badge-savings"><i class="ph-bold ph-check me-1"></i><?= date('d M Y', strtotime($u['email_verified_at'])) ?></span>
                            <?php else: ?>
                                <span class="badge badge-debit">Not verified</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($u['g_name']): ?>
                                <div class="tbl-name" style="font-size:.85rem;"><?= htmlspecialchars($u['g_name']) ?></div>
                                <div class="tbl-sub"><?= htmlspecialchars($u['g_coop_no']) ?> · <?= htmlspecialchars($u['g_phone']) ?></div>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="tbl-actions">
                                <button class="btn-icon btn-icon-view btn-activate" title="Activate & Assign Coop No.">
                                    <i class="ph-bold ph-check-circle"></i>
                                </button>
                                <button class="btn-icon btn-icon-delete btn-reject" title="Reject Registration">
                                    <i class="ph-bold ph-x-circle"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="ph-bold ph-x-circle me-2 text-danger"></i>Reject Registration</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Rejecting <strong id="rejectName"></strong>. This will delete their account and notify them by email.</p>
                <label class="form-label">Reason <span class="text-muted fw-normal">(optional)</span></label>
                <textarea id="rejectReason" class="form-control" rows="3" placeholder="e.g. Guarantor not verified, incomplete information..."></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmReject"><i class="ph-bold ph-x-circle me-1"></i>Reject</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script>
<?php if (!empty($pending)): ?>
$('#regTable').DataTable({ pageLength: 25, dom: '<"dt-top"lf>rt<"dt-bottom"ip>', columnDefs: [{ orderable: false, targets: -1 }] });
<?php endif; ?>

function showAlert(msg, type) {
    const el = document.getElementById('regAlerts');
    el.innerHTML = `<div class="alert alert-${type}">${msg}</div>`;
    el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

async function postAction(data) {
    const res = await fetch('', { method: 'POST', body: data, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    const ct = res.headers.get('content-type') || '';
    if (!ct.includes('application/json')) throw new Error('Session expired. Please refresh.');
    return res.json();
}

// Activate
document.addEventListener('click', async function(e) {
    const btn = e.target.closest('.btn-activate');
    if (!btn) return;
    const row = btn.closest('tr');
    const name = row.dataset.name;
    if (!confirm(`Confirm payment received from ${name} and activate their account?`)) return;
    btn.disabled = true;
    const data = new FormData();
    data.append('action', 'activate');
    data.append('user_id', row.dataset.id);
    try {
        const json = await postAction(data);
        if (!json.ok) { showAlert(json.error, 'danger'); btn.disabled = false; return; }
        showAlert(`${name} activated as <strong>${json.coop_no}</strong>. Receipt emailed.`, 'success');
        row.remove();
    } catch(err) { showAlert(err.message, 'danger'); btn.disabled = false; }
});

// Reject — open modal
let rejectRow = null;
document.addEventListener('click', function(e) {
    const btn = e.target.closest('.btn-reject');
    if (!btn) return;
    rejectRow = btn.closest('tr');
    document.getElementById('rejectName').textContent = rejectRow.dataset.name;
    document.getElementById('rejectReason').value = '';
    new bootstrap.Modal(document.getElementById('rejectModal')).show();
});

document.getElementById('confirmReject').addEventListener('click', async function() {
    if (!rejectRow) return;
    this.disabled = true;
    const data = new FormData();
    data.append('action', 'reject');
    data.append('user_id', rejectRow.dataset.id);
    data.append('reason', document.getElementById('rejectReason').value.trim());
    try {
        const json = await postAction(data);
        bootstrap.Modal.getInstance(document.getElementById('rejectModal')).hide();
        if (!json.ok) { showAlert(json.error, 'danger'); this.disabled = false; return; }
        showAlert(json.message, 'success');
        rejectRow.remove();
    } catch(err) { showAlert(err.message, 'danger'); }
    this.disabled = false;
});
</script>
<?php include '../includes/footer.php'; ?>
