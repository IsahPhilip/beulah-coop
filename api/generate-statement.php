<?php
// api/generate-statement.php
// Generates a bank statement PDF or Excel for a member.
// Accessible by the member (own statement) or admin (any member).
require_once '../includes/auth.php';
require_once '../vendor/autoload.php';

$role     = $_SESSION['role'] ?? '';
$format   = $_GET['format'] ?? 'pdf'; // pdf | excel
$dateFrom = $_GET['date_from'] ?? date('Y-01-01');
$dateTo   = $_GET['date_to']   ?? date('Y-m-d');
$userId   = (int)($_GET['user_id'] ?? $_SESSION['user_id']);

// Access control
if ($role === 'member' && $userId !== (int)$_SESSION['user_id']) {
    http_response_code(403); exit('Access denied.');
}
if ($role !== 'admin' && $role !== 'member') {
    http_response_code(403); exit('Access denied.');
}

// Validate dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-01-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = date('Y-m-d');

// Fetch member
$stmt = $pdo->prepare("SELECT id, name, coop_no, email, phone FROM users WHERE id = ? AND role = 'member'");
$stmt->execute([$userId]);
$member = $stmt->fetch();
if (!$member) { http_response_code(404); exit('Member not found.'); }

// Coop settings
$s = get_settings($pdo);
$coopName    = $s['coop_name']    ?? 'Beulah Multi-Purpose Cooperative Society Ltd.';
$coopAddress = $s['coop_address'] ?? '';
$coopRegNo   = $s['coop_reg_no']  ?? '';
$coopPhone   = $s['coop_phone']   ?? '';
$coopEmail   = $s['coop_email']   ?? '';
$logoPath    = (!empty($s['coop_logo']) && file_exists(__DIR__ . '/../' . $s['coop_logo']))
               ? __DIR__ . '/../' . $s['coop_logo'] : '';

// Credit types (increase net position)
$creditTypes = ['savings_credit', 'loan_repayment', 'interest_paid'];
// Debit types (decrease net position)
$debitTypes  = ['savings_debit', 'loan_disbursed', 'interest_charged', 'registration_fee'];

// Opening balance = net position BEFORE dateFrom
$stmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN type IN ('savings_credit','loan_repayment','interest_paid') THEN amount ELSE 0 END), 0) -
        COALESCE(SUM(CASE WHEN type IN ('savings_debit','loan_disbursed','interest_charged','registration_fee') THEN amount ELSE 0 END), 0)
    FROM transactions WHERE user_id = ? AND trans_date < ?
");
$stmt->execute([$userId, $dateFrom]);
$openingBalance = (float)$stmt->fetchColumn();

// Transactions in range
$stmt = $pdo->prepare("
    SELECT id, trans_date, type, amount, description
    FROM transactions
    WHERE user_id = ? AND trans_date BETWEEN ? AND ?
    ORDER BY trans_date ASC, id ASC
");
$stmt->execute([$userId, $dateFrom, $dateTo]);
$transactions = $stmt->fetchAll();

// Build rows with running balance
$typeLabels = [
    'savings_credit'   => 'Savings Credit',
    'savings_debit'    => 'Savings Debit',
    'loan_disbursed'   => 'Loan Disbursed',
    'loan_repayment'   => 'Loan Repayment',
    'interest_charged' => 'Interest Charged',
    'interest_paid'    => 'Interest Paid',
    'registration_fee' => 'Registration Fee',
];

$rows           = [];
$runningBalance = $openingBalance;
$totalCredit    = 0;
$totalDebit     = 0;

foreach ($transactions as $t) {
    $isCredit = in_array($t['type'], $creditTypes);
    $credit   = $isCredit ? (float)$t['amount'] : 0;
    $debit    = !$isCredit ? (float)$t['amount'] : 0;
    $runningBalance += $credit - $debit;
    $totalCredit    += $credit;
    $totalDebit     += $debit;
    $rows[] = [
        'date'    => $t['trans_date'],
        'ref'     => 'TXN' . str_pad($t['id'], 6, '0', STR_PAD_LEFT),
        'desc'    => $t['description'] ?: ($typeLabels[$t['type']] ?? ucwords(str_replace('_', ' ', $t['type']))),
        'type'    => $typeLabels[$t['type']] ?? ucwords(str_replace('_', ' ', $t['type'])),
        'credit'  => $credit,
        'debit'   => $debit,
        'balance' => $runningBalance,
    ];
}
$closingBalance = $runningBalance;

$slug     = preg_replace('/[^a-z0-9]+/i', '-', strtolower($member['name']));
$slug     = trim($slug, '-') ?: 'member';
$filename = "statement-{$slug}-{$dateFrom}-to-{$dateTo}";

// ─── PDF ──────────────────────────────────────────────────────────────────────
if ($format === 'pdf') {
    $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8');
    $pdf->SetCreator($coopName);
    $pdf->SetTitle('Account Statement');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(12, 12, 12);
    $pdf->SetAutoPageBreak(true, 14);
    $pdf->AddPage();

    // ── Header ──
    $headerY = 12;
    if ($logoPath) {
        $pdf->Image($logoPath, 12, $headerY, 28, 0, '', '', 'T', false, 300);
        $pdf->SetXY(44, $headerY);
    } else {
        $pdf->SetXY(12, $headerY);
    }

    $pdf->SetFont('helvetica', 'B', 13);
    $pdf->SetTextColor(79, 70, 229);
    $pdf->Cell(0, 7, $coopName, 0, 1, $logoPath ? 'L' : 'C');
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(107, 114, 128);
    if ($logoPath) $pdf->SetX(44);
    if ($coopAddress) { $pdf->Cell(0, 4, $coopAddress, 0, 1, $logoPath ? 'L' : 'C'); if ($logoPath) $pdf->SetX(44); }
    $infoParts = array_filter([$coopRegNo ? 'Reg No: ' . $coopRegNo : '', $coopPhone, $coopEmail]);
    if ($infoParts) { $pdf->Cell(0, 4, implode('  |  ', $infoParts), 0, 1, $logoPath ? 'L' : 'C'); }

    $pdf->Ln(2);
    $pdf->SetDrawColor(79, 70, 229);
    $pdf->SetLineWidth(0.5);
    $pdf->Line(12, $pdf->GetY(), 285, $pdf->GetY());
    $pdf->Ln(3);

    // ── Statement title ──
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetTextColor(17, 24, 39);
    $pdf->Cell(0, 7, 'ACCOUNT STATEMENT', 0, 1, 'C');
    $pdf->Ln(1);

    // ── Member info box ──
    $pdf->SetFillColor(238, 242, 255);
    $pdf->SetDrawColor(199, 210, 254);
    $pdf->RoundedRect(12, $pdf->GetY(), 130, 22, 2, '1111', 'DF');
    $pdf->SetXY(15, $pdf->GetY() + 2);
    $pdf->SetFont('helvetica', 'B', 8); $pdf->SetTextColor(79, 70, 229);
    $pdf->Cell(35, 5, 'Member Name:', 0, 0);
    $pdf->SetFont('helvetica', '', 8); $pdf->SetTextColor(17, 24, 39);
    $pdf->Cell(0, 5, $member['name'], 0, 1);
    $pdf->SetX(15);
    $pdf->SetFont('helvetica', 'B', 8); $pdf->SetTextColor(79, 70, 229);
    $pdf->Cell(35, 5, 'Coop No.:', 0, 0);
    $pdf->SetFont('helvetica', '', 8); $pdf->SetTextColor(17, 24, 39);
    $pdf->Cell(0, 5, $member['coop_no'] ?: '—', 0, 1);
    $pdf->SetX(15);
    $pdf->SetFont('helvetica', 'B', 8); $pdf->SetTextColor(79, 70, 229);
    $pdf->Cell(35, 5, 'Email:', 0, 0);
    $pdf->SetFont('helvetica', '', 8); $pdf->SetTextColor(17, 24, 39);
    $pdf->Cell(0, 5, $member['email'] ?: '—', 0, 1);

    // Period box
    $periodX = 155;
    $pdf->SetXY($periodX, $pdf->GetY() - 17);
    $pdf->SetFillColor(238, 242, 255);
    $pdf->SetDrawColor(199, 210, 254);
    $pdf->RoundedRect($periodX, $pdf->GetY(), 130, 22, 2, '1111', 'DF');
    $pdf->SetXY($periodX + 3, $pdf->GetY() + 2);
    $pdf->SetFont('helvetica', 'B', 8); $pdf->SetTextColor(79, 70, 229);
    $pdf->Cell(35, 5, 'Statement Period:', 0, 0);
    $pdf->SetFont('helvetica', '', 8); $pdf->SetTextColor(17, 24, 39);
    $pdf->Cell(0, 5, date('d M Y', strtotime($dateFrom)) . ' — ' . date('d M Y', strtotime($dateTo)), 0, 1);
    $pdf->SetX($periodX + 3);
    $pdf->SetFont('helvetica', 'B', 8); $pdf->SetTextColor(79, 70, 229);
    $pdf->Cell(35, 5, 'Opening Balance:', 0, 0);
    $pdf->SetFont('helvetica', '', 8); $pdf->SetTextColor(17, 24, 39);
    $pdf->Cell(0, 5, '₦' . number_format($openingBalance, 2), 0, 1);
    $pdf->SetX($periodX + 3);
    $pdf->SetFont('helvetica', 'B', 8); $pdf->SetTextColor(79, 70, 229);
    $pdf->Cell(35, 5, 'Closing Balance:', 0, 0);
    $pdf->SetFont('helvetica', 'B', 8); $pdf->SetTextColor($closingBalance >= 0 ? 5 : 239, $closingBalance >= 0 ? 150 : 68, $closingBalance >= 0 ? 105 : 68);
    $pdf->Cell(0, 5, '₦' . number_format($closingBalance, 2), 0, 1);

    $pdf->Ln(4);

    // ── Transactions table ──
    $colW = [28, 24, 80, 38, 26, 26, 30]; // date, ref, desc, type, credit, debit, balance
    $headers = ['Date', 'Reference', 'Description', 'Type', 'Credit (₦)', 'Debit (₦)', 'Balance (₦)'];

    $pdf->SetFillColor(79, 70, 229);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('helvetica', 'B', 7.5);
    $pdf->SetDrawColor(79, 70, 229);
    foreach ($headers as $i => $h) {
        $align = $i >= 4 ? 'R' : 'L';
        $pdf->Cell($colW[$i], 7, $h, 1, 0, $align, true);
    }
    $pdf->Ln();

    $pdf->SetFont('helvetica', '', 7.5);
    $pdf->SetDrawColor(229, 231, 235);
    $fill = false;
    foreach ($rows as $r) {
        $pdf->SetFillColor($fill ? 248 : 255, $fill ? 250 : 255, $fill ? 252 : 255);
        $pdf->SetTextColor(55, 65, 81);
        $pdf->Cell($colW[0], 6, date('d/m/Y', strtotime($r['date'])), 1, 0, 'L', true);
        $pdf->Cell($colW[1], 6, $r['ref'], 1, 0, 'L', true);
        $pdf->Cell($colW[2], 6, mb_strimwidth($r['desc'], 0, 45, '…'), 1, 0, 'L', true);
        $pdf->Cell($colW[3], 6, $r['type'], 1, 0, 'L', true);
        $pdf->SetTextColor($r['credit'] > 0 ? 5 : 156, $r['credit'] > 0 ? 150 : 163, $r['credit'] > 0 ? 105 : 175);
        $pdf->Cell($colW[4], 6, $r['credit'] > 0 ? number_format($r['credit'], 2) : '—', 1, 0, 'R', true);
        $pdf->SetTextColor($r['debit'] > 0 ? 239 : 156, $r['debit'] > 0 ? 68 : 163, $r['debit'] > 0 ? 68 : 175);
        $pdf->Cell($colW[5], 6, $r['debit'] > 0 ? number_format($r['debit'], 2) : '—', 1, 0, 'R', true);
        $pdf->SetTextColor($r['balance'] >= 0 ? 17 : 239, $r['balance'] >= 0 ? 24 : 68, $r['balance'] >= 0 ? 39 : 68);
        $pdf->Cell($colW[6], 6, number_format($r['balance'], 2), 1, 1, 'R', true);
        $fill = !$fill;
    }

    if (empty($rows)) {
        $pdf->SetTextColor(107, 114, 128);
        $pdf->Cell(array_sum($colW), 8, 'No transactions found for this period.', 1, 1, 'C');
    }

    // ── Summary row ──
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetFillColor(238, 242, 255);
    $pdf->SetTextColor(79, 70, 229);
    $pdf->SetDrawColor(199, 210, 254);
    $pdf->Cell($colW[0] + $colW[1] + $colW[2] + $colW[3], 7, 'TOTALS', 1, 0, 'L', true);
    $pdf->SetTextColor(5, 150, 105);
    $pdf->Cell($colW[4], 7, number_format($totalCredit, 2), 1, 0, 'R', true);
    $pdf->SetTextColor(239, 68, 68);
    $pdf->Cell($colW[5], 7, number_format($totalDebit, 2), 1, 0, 'R', true);
    $pdf->SetTextColor($closingBalance >= 0 ? 79 : 239, $closingBalance >= 0 ? 70 : 68, $closingBalance >= 0 ? 229 : 68);
    $pdf->Cell($colW[6], 7, number_format($closingBalance, 2), 1, 1, 'R', true);

    // ── Footer ──
    $pdf->Ln(6);
    $pdf->SetDrawColor(229, 231, 235);
    $pdf->Line(12, $pdf->GetY(), 285, $pdf->GetY());
    $pdf->Ln(3);
    $pdf->SetFont('helvetica', 'I', 7.5);
    $pdf->SetTextColor(107, 114, 128);
    $pdf->Cell(0, 5, 'This statement is computer-generated and requires no signature.', 0, 1, 'C');
    $pdf->Cell(0, 5, 'Generated on ' . date('d M Y, h:i A') . ' | ' . $coopName, 0, 1, 'C');

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '.pdf"');
    $pdf->Output($filename . '.pdf', 'D');
    exit();
}

// ─── EXCEL ────────────────────────────────────────────────────────────────────
if ($format === 'excel') {
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet       = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Statement');

    $boldStyle   = ['font' => ['bold' => true]];
    $headerStyle = [
        'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
        'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF4F46E5']],
        'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
    ];
    $moneyFmt    = '#,##0.00';
    $centerAlign = ['alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER]];

    // ── Coop header ──
    $sheet->mergeCells('A1:G1');
    $sheet->setCellValue('A1', $coopName);
    $sheet->getStyle('A1')->applyFromArray(['font' => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FF4F46E5']]] + $centerAlign);

    $row = 2;
    if ($coopAddress) {
        $sheet->mergeCells("A{$row}:G{$row}");
        $sheet->setCellValue("A{$row}", $coopAddress);
        $sheet->getStyle("A{$row}")->applyFromArray($centerAlign);
        $row++;
    }
    $infoParts = array_filter([$coopRegNo ? 'Reg No: ' . $coopRegNo : '', $coopPhone, $coopEmail]);
    if ($infoParts) {
        $sheet->mergeCells("A{$row}:G{$row}");
        $sheet->setCellValue("A{$row}", implode('  |  ', $infoParts));
        $sheet->getStyle("A{$row}")->applyFromArray($centerAlign);
        $row++;
    }

    $row++;
    $sheet->mergeCells("A{$row}:G{$row}");
    $sheet->setCellValue("A{$row}", 'ACCOUNT STATEMENT');
    $sheet->getStyle("A{$row}")->applyFromArray(['font' => ['bold' => true, 'size' => 12]] + $centerAlign);
    $row++;

    // ── Member info ──
    $row++;
    $infoRows = [
        ['Member Name', $member['name']],
        ['Coop No.',    $member['coop_no'] ?: '—'],
        ['Email',       $member['email'] ?: '—'],
        ['Period',      date('d M Y', strtotime($dateFrom)) . ' — ' . date('d M Y', strtotime($dateTo))],
        ['Opening Balance', '₦' . number_format($openingBalance, 2)],
    ];
    foreach ($infoRows as [$label, $val]) {
        $sheet->setCellValue("A{$row}", $label);
        $sheet->setCellValue("B{$row}", $val);
        $sheet->getStyle("A{$row}")->applyFromArray(['font' => ['bold' => true, 'color' => ['argb' => 'FF4F46E5']]]);
        $row++;
    }

    // ── Table header ──
    $row++;
    $headers = ['Date', 'Reference', 'Description', 'Type', 'Credit (₦)', 'Debit (₦)', 'Balance (₦)'];
    foreach ($headers as $ci => $h) {
        $sheet->setCellValueByColumnAndRow($ci + 1, $row, $h);
    }
    $sheet->getStyle("A{$row}:G{$row}")->applyFromArray($headerStyle);
    $dataStart = $row + 1;
    $row++;

    // ── Data rows ──
    $greenFill = ['fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFD1FAE5']]];
    $redFill   = ['fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFEE2E2']]];
    $altFill   = ['fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF8FAFC']]];

    foreach ($rows as $i => $r) {
        $sheet->setCellValue("A{$row}", $r['date']);
        $sheet->setCellValue("B{$row}", $r['ref']);
        $sheet->setCellValue("C{$row}", $r['desc']);
        $sheet->setCellValue("D{$row}", $r['type']);
        $sheet->setCellValue("E{$row}", $r['credit'] > 0 ? $r['credit'] : '');
        $sheet->setCellValue("F{$row}", $r['debit']  > 0 ? $r['debit']  : '');
        $sheet->setCellValue("G{$row}", $r['balance']);
        $sheet->getStyle("E{$row}:G{$row}")->getNumberFormat()->setFormatCode($moneyFmt);
        if ($r['credit'] > 0) $sheet->getStyle("E{$row}")->applyFromArray($greenFill);
        if ($r['debit']  > 0) $sheet->getStyle("F{$row}")->applyFromArray($redFill);
        if ($i % 2 === 1)     $sheet->getStyle("A{$row}:G{$row}")->applyFromArray($altFill);
        $row++;
    }

    if (empty($rows)) {
        $sheet->mergeCells("A{$row}:G{$row}");
        $sheet->setCellValue("A{$row}", 'No transactions found for this period.');
        $sheet->getStyle("A{$row}")->applyFromArray($centerAlign);
        $row++;
    }

    // ── Totals row ──
    $sheet->setCellValue("A{$row}", 'TOTALS');
    $sheet->setCellValue("E{$row}", $totalCredit);
    $sheet->setCellValue("F{$row}", $totalDebit);
    $sheet->setCellValue("G{$row}", $closingBalance);
    $sheet->getStyle("A{$row}:G{$row}")->applyFromArray([
        'font' => ['bold' => true],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFEEF2FF']],
    ]);
    $sheet->getStyle("E{$row}:G{$row}")->getNumberFormat()->setFormatCode($moneyFmt);

    $row += 2;
    $sheet->mergeCells("A{$row}:G{$row}");
    $sheet->setCellValue("A{$row}", 'This statement is computer-generated. Generated on ' . date('d M Y, h:i A'));
    $sheet->getStyle("A{$row}")->applyFromArray(['font' => ['italic' => true, 'color' => ['argb' => 'FF6B7280']]] + $centerAlign);

    // Column widths
    foreach (['A' => 14, 'B' => 16, 'C' => 40, 'D' => 22, 'E' => 16, 'F' => 16, 'G' => 18] as $col => $w) {
        $sheet->getColumnDimension($col)->setWidth($w);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
    header('Cache-Control: max-age=0');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
    exit();
}

http_response_code(400);
echo 'Invalid format.';
