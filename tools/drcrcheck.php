<?php
require __DIR__ . '/../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date;

$wb = IOFactory::load(__DIR__ . '/../2025COOP LEDGERS.xlsx');

function cellVal($sheet, $col, $row) {
    try { return $sheet->getCell($col . $row)->getCalculatedValue(); }
    catch (Exception $e) { return $sheet->getCell($col . $row)->getValue(); }
}

function showSheet($wb, $name, $label) {
    $s = $wb->getSheetByName($name);
    if (!$s) { echo "Sheet '$name' not found\n"; return; }

    echo "\n=== $label ($name) ===\n";
    echo str_pad('DATE', 12) . str_pad('S_DR', 12) . str_pad('S_CR', 12) . str_pad('S_BAL', 14)
        . str_pad('L_DR', 12) . str_pad('L_CR', 14) . str_pad('L_BAL', 14)
        . str_pad('I_DR', 10) . str_pad('I_CR', 10) . "I_BAL\n";
    echo str_repeat('-', 110) . "\n";

    for ($r = 8; $r <= 100; $r++) {
        $dateRaw = $s->getCell('A' . $r)->getValue();
        if ($dateRaw === null || $dateRaw === '') continue;
        if (!is_numeric($dateRaw) || $dateRaw <= 40000) {
            echo "ROW $r: non-date colA=" . json_encode($dateRaw) . "\n";
            continue;
        }
        $date = Date::excelToDateTimeObject($dateRaw)->format('Y-m-d');

        $sdr = cellVal($s, 'B', $r);
        $scr = cellVal($s, 'C', $r);
        $sbal = cellVal($s, 'D', $r);
        $ldr = cellVal($s, 'E', $r);
        $lcr = cellVal($s, 'F', $r);
        $lbal = cellVal($s, 'G', $r);
        $idr = cellVal($s, 'H', $r);
        $icr = cellVal($s, 'I', $r);
        $ibal = cellVal($s, 'J', $r);

        echo str_pad($date, 12)
            . str_pad($sdr ?? '-', 12) . str_pad($scr ?? '-', 12) . str_pad($sbal ?? '-', 14)
            . str_pad($ldr ?? '-', 12) . str_pad($lcr ?? '-', 14) . str_pad($lbal ?? '-', 14)
            . str_pad($idr ?? '-', 10) . str_pad($icr ?? '-', 10) . ($ibal ?? '-') . "\n";
    }
}

// Show a variety of sheets to verify DR/CR behaviour
showSheet($wb, 'NO 1',  'BC01 - savings opening, no DR/CR');
showSheet($wb, 'NO 2',  'BC02 - savings CR entries');
showSheet($wb, 'No 4',  'BC04 - negative loan CR opening');
showSheet($wb, 'NO 5',  'BC05 - loan DR/CR check');
showSheet($wb, 'NO 10', 'BC10 - mixed savings+loan+interest');
showSheet($wb, 'NO 16', 'BC16 - large loan, only BALANCE col');
showSheet($wb, 'NO 18', 'BC18 - interest charged + paid?');
showSheet($wb, 'NO 35', 'BC35 - loan + repayment');
