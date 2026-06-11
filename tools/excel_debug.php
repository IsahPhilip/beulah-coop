<?php
require __DIR__ . '/../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

$path = __DIR__ . '/../2025COOP LEDGERS.xlsx';
if (!file_exists($path)) {
    echo "File not found: $path\n";
    exit(1);
}

$wb = IOFactory::load($path);
$names = $wb->getSheetNames();
echo "sheets=" . implode(',', $names) . PHP_EOL;

$limit = min(5, count($names));
for ($i = 0; $i < $limit; $i++) {
    $name = $names[$i];
    $ws = $wb->getSheetByName($name);
    echo PHP_EOL . "Sheet " . $name . PHP_EOL;
    for ($r = 1; $r <= 12; $r++) {
        $row = [];
        for ($c = 1; $c <= 15; $c++) {
            $colLetter = Coordinate::stringFromColumnIndex($c);
            $v = $ws->getCell($colLetter . $r)->getValue();
            $row[] = $v;
        }
        $has = false;
        foreach ($row as $v) { if ($v !== null && $v !== '') { $has = true; break; } }
        if ($has) {
            echo $r . ' ' . json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        }
    }
    if ($i === 0) {
        echo '--- sample first 20 rows ---' . PHP_EOL;
        for ($r = 1; $r <= 20; $r++) {
            $row = [];
            for ($c = 1; $c <= 15; $c++) { $colLetter = Coordinate::stringFromColumnIndex($c); $v = $ws->getCell($colLetter . $r)->getValue(); $row[] = $v; }
            echo $r . ' ' . json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        }
    }
}
