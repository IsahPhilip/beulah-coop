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

for ($i = 1; $i <= 55; $i++) {
    $sheetNames = ["NO $i", "No $i", "NO$i"];
    $sheet = null;
    foreach ($sheetNames as $name) { if (in_array($name, $names)) { $sheet = $wb->getSheetByName($name); break; } }
    if (!$sheet) { echo "Sheet $i: not found\n"; continue; }

    $coopNo = strtoupper(trim((string)$sheet->getCell('D3')->getValue() ?: $sheet->getCell('C3')->getValue() ?: $sheet->getCell('B3')->getValue()));
    echo "\nSheet $i ($coopNo)\n";

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
        if (is_numeric($dateVal) && $dateVal > 40000) $transDate = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($dateVal)->format('Y-m-d');
        else $transDate = date('Y-m-d', strtotime((string)$dateVal));
        if (!$transDate) continue;
        $currSavingsBal = parse_amount($savingsBalCol ? $sheet->getCell($savingsBalCol . $row)->getCalculatedValue() : 0);
        $currLoanBal = parse_amount($loanBalCol ? $sheet->getCell($loanBalCol . $row)->getCalculatedValue() : 0);
        $currInterestBal = parse_amount($interestBalCol ? $sheet->getCell($interestBalCol . $row)->getCalculatedValue() : 0);
        $currSavingsBal = is_numeric($currSavingsBal) ? $currSavingsBal : 0;
        $currLoanBal = is_numeric($currLoanBal) ? $currLoanBal : 0;
        $currInterestBal = is_numeric($currInterestBal) ? $currInterestBal : 0;
        $savingsChange = $currSavingsBal - $prevSavingsBal; $loanChange = $currLoanBal - $prevLoanBal; $interestChange = $currInterestBal - $prevInterestBal;
        if ($savingsChange != 0) { echo "  $transDate: savings change: $savingsChange\n"; $transForSheet++; }
        if ($loanChange != 0) { echo "  $transDate: loan change: $loanChange\n"; $transForSheet++; }
        if ($interestChange > 0) { echo "  $transDate: interest change: $interestChange\n"; $transForSheet++; }
        $prevSavingsBal = $currSavingsBal; $prevLoanBal = $currLoanBal; $prevInterestBal = $currInterestBal;
    }
    echo "  Transactions detected: $transForSheet\n";
}
