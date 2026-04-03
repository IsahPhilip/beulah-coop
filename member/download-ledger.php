<?php
// member/download-ledger.php - PDF ledger download
require_once '../includes/auth.php';
require_once '../vendor/autoload.php';

if ($_SESSION['role'] === 'admin') {
    header("Location: ../admin/index.php");
    exit();
}
if ($_SESSION['role'] !== 'member') {
    header("Location: ../login.php");
    exit();
}

$userId = $_SESSION['user_id'];
$memberName = $_SESSION['name'] ?? 'Member';
$coopNo = $_SESSION['coop_no'] ?? '';

$stmt = $pdo->prepare("
    SELECT trans_date, type, amount, description
    FROM transactions
    WHERE user_id = ?
    ORDER BY trans_date DESC, id DESC
");
$stmt->execute([$userId]);
$transactions = $stmt->fetchAll();

$pdf = new TCPDF();
$pdf->SetCreator('Beulah Coop');
$pdf->SetTitle('Member Ledger');
$pdf->SetPrintHeader(false);
$pdf->SetPrintFooter(false);
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 11);

$safeName = htmlspecialchars($memberName, ENT_QUOTES, 'UTF-8');
$safeCoop = htmlspecialchars($coopNo, ENT_QUOTES, 'UTF-8');

$html = '<h3>Member Ledger</h3>';
$html .= '<p><strong>Name:</strong> ' . $safeName . '<br><strong>Coop No.:</strong> ' . $safeCoop . '</p>';

if (!$transactions) {
    $html .= '<p>No transactions found.</p>';
} else {
    $html .= '<table border="1" cellpadding="4">';
    $html .= '<thead><tr><th>Date</th><th>Type</th><th>Amount</th><th>Description</th></tr></thead><tbody>';
    foreach ($transactions as $t) {
        $date = htmlspecialchars($t['trans_date'], ENT_QUOTES, 'UTF-8');
        $type = htmlspecialchars(str_replace('_', ' ', $t['type']), ENT_QUOTES, 'UTF-8');
        $amount = htmlspecialchars(format_money($t['amount']), ENT_QUOTES, 'UTF-8');
        $desc = htmlspecialchars($t['description'] ?? '', ENT_QUOTES, 'UTF-8');
        $html .= "<tr><td>{$date}</td><td>{$type}</td><td>{$amount}</td><td>{$desc}</td></tr>";
    }
    $html .= '</tbody></table>';
}

$pdf->writeHTML($html, true, false, true, false, '');

$slug = preg_replace('/[^a-z0-9]+/i', '-', $memberName);
$slug = trim(strtolower($slug), '-');
if ($slug === '') {
    $slug = 'member';
}
$filename = "beulah-ledger-{$slug}-" . date('Ymd') . ".pdf";
$pdf->Output($filename, 'D');
exit();
