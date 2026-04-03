<?php
// member/download-ledger-excel.php - Excel ledger download
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

$stmt = $pdo->prepare("
    SELECT trans_date, type, amount, description
    FROM transactions
    WHERE user_id = ?
    ORDER BY trans_date DESC, id DESC
");
$stmt->execute([$userId]);
$transactions = $stmt->fetchAll();

$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Ledger');

$sheet->setCellValue('A1', 'Date');
$sheet->setCellValue('B1', 'Type');
$sheet->setCellValue('C1', 'Amount');
$sheet->setCellValue('D1', 'Description');

$row = 2;
foreach ($transactions as $t) {
    $sheet->setCellValue('A' . $row, $t['trans_date']);
    $sheet->setCellValue('B' . $row, str_replace('_', ' ', $t['type']));
    $sheet->setCellValue('C' . $row, (float)$t['amount']);
    $sheet->setCellValue('D' . $row, $t['description'] ?? '');
    $row++;
}

$sheet->getStyle('A1:D1')->getFont()->setBold(true);
$sheet->getStyle('C2:C' . max(2, $row - 1))
    ->getNumberFormat()
    ->setFormatCode('#,##0.00');
$sheet->getColumnDimension('A')->setWidth(14);
$sheet->getColumnDimension('B')->setWidth(22);
$sheet->getColumnDimension('C')->setWidth(14);
$sheet->getColumnDimension('D')->setWidth(36);

$slug = preg_replace('/[^a-z0-9]+/i', '-', $memberName);
$slug = trim(strtolower($slug), '-');
if ($slug === '') {
    $slug = 'member';
}
$filename = "beulah-ledger-{$slug}-" . date('Ymd') . ".xlsx";

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
$writer->save('php://output');
exit();
