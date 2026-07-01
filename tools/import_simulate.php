<?php
require __DIR__ . '/../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

$path = __DIR__ . '/../2025COOP LEDGERS.xlsx';
if (!file_exists($path)) { echo "File not found: $path\n"; exit(1); }
$wb = IOFactory::load($path);
$names = $wb->getSheetNames();

function parse_amount($value) {
    if (is_numeric($value)) return (float)$value;
    if (is_string($value)) {
        $clean = preg_replace('/[^\d\.\-]/', '', $value);
        return is_numeric($clean) ? (float)$clean : null;
    }
    return null;
}

// Build member list from SUMMARY sheet (mirrors import-excel.php STEP 1)
$summarySheet = $wb->getSheetByName('SUMMARY');
$members = [];
if ($summarySheet) {
    $lastRow = $summarySheet->getHighestRow();
    for ($r = 5; $r <= $lastRow; $r++) {
        $name = trim((string)$summarySheet->getCell('C' . $r)->getValue());
        $coop = strtoupper(trim((string)$summarySheet->getCell('D' . $r)->getValue()));
        if (!empty($coop) && !empty($name)) {
            $members[$coop] = $name;
        }
    }
}
echo "Members in SUMMARY: " . count($members) . "\n";

for ($i = 1; $i <= 80; $i++) {
    $sheetNames = ["NO $i", "No $i", "NO$i"];
    $sheet = null;
    foreach ($sheetNames as $name) { if (in_array($name, $names)) { $sheet = $wb->getSheetByName($name); break; } }
    if (!$sheet) { echo "Sheet $i: not found\n"; continue; }

    // Extract coop number (D3, C3, D4 fallbacks, then B3)
    $coopNo = strtoupper(trim((string)(
        $sheet->getCell('D3')->getValue()
        ?: $sheet->getCell('C3')->getValue()
        ?: $sheet->getCell('D4')->getValue()
        ?: $sheet->getCell('B3')->getValue()
    )));

    // Fall back to derived coop number if not found in member list
    if (empty($coopNo) || !isset($members[$coopNo])) {
        $derived = sprintf('BC%02d', $i);
        if (isset($members[$derived])) {
            $coopNo = $derived;
        }
    }

    echo "\nSheet $i ($coopNo)";
    if (!isset($members[$coopNo])) { echo " [NOT IN MEMBER LIST - skipped]\n"; continue; }
    echo " - " . $members[$coopNo] . "\n";

    $categoryRow = 6; $typeRow = 7;
    $savingsBalCol = $loanBalCol = $interestBalCol = null;
    $highestCol = Coordinate::columnIndexFromString($sheet->getHighestColumn());
    for ($col = 1; $col <= $highestCol; $col++) {
        $colLetter = Coordinate::stringFromColumnIndex($col);
        $type = strtoupper(trim((string)$sheet->getCell($colLetter . $typeRow)->getValue()));
        if ($type !== 'BALANCE') continue;
        $category = '';
        for ($c2 = $col; $c2 >= 1; $c2--) {
            $catLetter = Coordinate::stringFromColumnIndex($c2);
            $candidate = trim((string)$sheet->getCell($catLetter . $categoryRow)->getValue());
            if ($candidate !== '' && $candidate !== null) { $category = strtoupper($candidate); break; }
        }
        if ($category === '') continue;
        if (strpos($category, 'SAVINGS') !== false) $savingsBalCol = $colLetter;
        elseif (strpos($category, 'INTEREST') !== false) $interestBalCol = $colLetter;
        elseif (strpos($category, 'LOAN') !== false && strpos($category,'INTEREST')===false) $loanBalCol = $colLetter;
    }

    echo "Detected columns - savings: " . ($savingsBalCol?:'-') . ", loan: " . ($loanBalCol?:'-') . ", interest: " . ($interestBalCol?:'-') . "\n";
    if (!$savingsBalCol && !$loanBalCol && !$interestBalCol) { echo "  No balance columns found.\n"; continue; }

    $prevSavingsBal = $prevLoanBal = $prevInterestBal = 0; $transForSheet = 0;
    for ($row = 8; $row <= 100; $row++) {
        $dateVal = $sheet->getCell('A' . $row)->getValue();
        if (empty($dateVal)) continue;

        $transDate = null;
        if (is_numeric($dateVal) && $dateVal > 40000) {
            $transDate = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($dateVal)->format('Y-m-d');
        } elseif (is_string($dateVal)) {
            $ts = strtotime($dateVal);
            if ($ts !== false) {
                $transDate = date('Y-m-d', $ts);
            }
        }
        // Reject obviously invalid dates (e.g. TOTAL rows)
        if ($transDate) {
            $year = (int)substr($transDate, 0, 4);
            if ($year < 2000 || $year > 2040) $transDate = null;
        }
        if (!$transDate) continue;

        // null = cell empty = no transaction in that account this row; explicit 0 = balance reached zero
        try { $rawSav = parse_amount($savingsBalCol ? $sheet->getCell($savingsBalCol . $row)->getCalculatedValue() : null); }
        catch (Exception $ex) { $rawSav = null; }
        try { $rawLoan = parse_amount($loanBalCol ? $sheet->getCell($loanBalCol . $row)->getCalculatedValue() : null); }
        catch (Exception $ex) { $rawLoan = null; }
        try { $rawInt = parse_amount($interestBalCol ? $sheet->getCell($interestBalCol . $row)->getCalculatedValue() : null); }
        catch (Exception $ex) { $rawInt = null; }

        $currSavingsBal  = is_numeric($rawSav)  ? $rawSav       : null;
        $currLoanBal     = is_numeric($rawLoan) ? abs($rawLoan) : null;
        $currInterestBal = is_numeric($rawInt)  ? abs($rawInt)  : null;

        $savingsChange  = ($currSavingsBal  !== null) ? $currSavingsBal  - $prevSavingsBal  : null;
        $loanChange     = ($currLoanBal     !== null) ? $currLoanBal     - $prevLoanBal     : null;
        $interestChange = ($currInterestBal !== null) ? $currInterestBal - $prevInterestBal : null;

        if ($savingsChange > 0)    { echo "  $transDate: savings_credit " . number_format($savingsChange, 2) . "\n"; $transForSheet++; }
        elseif ($savingsChange < 0) { echo "  $transDate: savings_debit  " . number_format(abs($savingsChange), 2) . "\n"; $transForSheet++; }
        if ($loanChange > 0)        { echo "  $transDate: loan_disbursed " . number_format($loanChange, 2) . "\n"; $transForSheet++; }
        elseif ($loanChange < 0)    { echo "  $transDate: loan_repayment " . number_format(abs($loanChange), 2) . "\n"; $transForSheet++; }
        if ($interestChange > 0)    { echo "  $transDate: interest_charged " . number_format($interestChange, 2) . "\n"; $transForSheet++; }
        elseif ($interestChange < 0){ echo "  $transDate: interest_paid  " . number_format(abs($interestChange), 2) . "\n"; $transForSheet++; }

        if ($currSavingsBal  !== null) $prevSavingsBal  = $currSavingsBal;
        if ($currLoanBal     !== null) $prevLoanBal     = $currLoanBal;
        if ($currInterestBal !== null) $prevInterestBal = $currInterestBal;
    }
    echo "  Transactions detected: $transForSheet\n";
}
