<?php
// import-excel.php - Improved version with secure file upload
require_once 'includes/auth.php';

function import_page_start() {
    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Excel Import - Beulah Coop</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="assets/css/custom.css" rel="stylesheet">
</head>
<body class="dash-body">
    <div class="container py-4">
        <div class="dash-panel">
            <div class="dash-panel-title">Beulah Coop - Excel Import</div>';
}

function import_page_end() {
    echo '        </div>
    </div>
</body>
</html>';
}

function import_fail($message, $statusCode = 400) {
    http_response_code($statusCode);
    echo '<div class="alert alert-danger"><strong>Import failed.</strong><br>' . htmlspecialchars($message) . '</div>';
    echo '<a href="admin/import.php" class="btn btn-primary mt-2">Back to Import</a>';
    import_page_end();
    exit();
}

set_exception_handler(function($e) {
    error_log('Excel import failed: ' . $e->getMessage());
    import_fail($e->getMessage(), 500);
});

// Security: Only admin can access
if (($_SESSION['role'] ?? '') !== 'admin') {
    die("Access denied. Admin login required.");
}

import_page_start();

$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    import_fail('PhpSpreadsheet is not installed on the server. Upload the vendor folder or run composer install on the server.', 500);
}

require_once $autoloadPath;

if (!class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
    import_fail('PhpSpreadsheet could not be loaded. Please reinstall Composer dependencies.', 500);
}

$uploadDir = 'uploads/';
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        import_fail('Could not create the uploads directory. Please check folder permissions.', 500);
    }
}

$allowedTypes = ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/octet-stream'];
$maxSize = 10 * 1024 * 1024; // 10MB

$inputFileName = '';

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file'])) {
    $file = $_FILES['excel_file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        import_fail("Upload error: " . $file['error']);
    }

    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($file['type'], $allowedTypes) && $extension !== 'xlsx') {
        import_fail("Invalid file type. Only .xlsx files are allowed.");
    }

    if ($file['size'] > $maxSize) {
        import_fail("File is too large. Maximum size is 10MB.");
    }

    $inputFileName = $uploadDir . '2025COOP_LEDGERS_' . time() . '.xlsx';
    if (!move_uploaded_file($file['tmp_name'], $inputFileName)) {
        import_fail("Failed to save uploaded file. Please check uploads folder permissions.");
    }

    echo '<div class="alert alert-success">File uploaded successfully.</div>';
} else {
    import_fail("No file uploaded.");
}

if (!file_exists($inputFileName)) {
    import_fail("Excel file not found.");
}

// Load spreadsheet
$spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($inputFileName);

echo '<p class="text-muted">Processing Excel file...</p>';

function normalize_coop_no($value) {
    $value = (string)$value;
    $value = str_replace("\xC2\xA0", ' ', $value); // non-breaking space
    $value = trim($value);
    $value = preg_replace('/\s+/', ' ', $value);
    return strtoupper($value);
}

function get_header_column($sheet, $rowIndex, $labels) {
    $labels = array_map(function($v) {
        return strtoupper(trim($v));
    }, $labels);
    $highestCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());
    for ($col = 1; $col <= $highestCol; $col++) {
        $val = (string)$sheet->getCell(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $rowIndex)->getValue();
        $val = strtoupper(trim(preg_replace('/\s+/', ' ', $val)));
        if ($val !== '' && in_array($val, $labels, true)) {
            return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
        }
    }
    return null;
}

function get_cell_value_safe($sheet, $cellRef) {
    try {
        return $sheet->getCell($cellRef)->getCalculatedValue();
    } catch (\PhpOffice\PhpSpreadsheet\Calculation\Exception $e) {
        return $sheet->getCell($cellRef)->getValue();
    } catch (\Exception $e) {
        return $sheet->getCell($cellRef)->getValue();
    }
}

function parse_amount($value) {
    if (is_numeric($value)) return (float)$value;
    if (is_string($value)) {
        $clean = preg_replace('/[^\d\.\-]/', '', $value);
        return is_numeric($clean) ? (float)$clean : null;
    }
    return null;
}

function delete_members_not_in_list($pdo, $coopNos) {
    if (empty($coopNos)) return;
    $chunkSize = 400;
    $chunks = array_chunk($coopNos, $chunkSize);
    $conditions = [];
    $params = [];
    foreach ($chunks as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $conditions[] = "coop_no NOT IN ($placeholders)";
        $params = array_merge($params, $chunk);
    }
    $sql = "DELETE FROM users WHERE role = 'member' AND (" . implode(' AND ', $conditions) . ")";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

// Helper function - DEFINED EARLY for use in import loop
function insertTransaction($pdo, $userId, $date, $type, $amount, $desc, &$counter) {
    if (!$date || $amount <= 0) return;
    try {
        create_transaction($pdo, (int)$userId, $date, $type, (float)$amount, $desc, $_SESSION['user_id'] ?? null);
        $counter++;
        return true;
    } catch (\Exception $e) {
        error_log("Transaction insert failed for user $userId: " . $e->getMessage());
        return false;
    }
}

// Ensure the transactions.type ENUM recognises all types used by this import.
try {
    $pdo->exec("ALTER TABLE `transactions` MODIFY COLUMN `type`
        ENUM('savings_credit','savings_debit','loan_disbursed','loan_repayment','interest_charged','interest_paid')
        NOT NULL");
} catch (PDOException $e) {
    error_log('ENUM alter skipped: ' . $e->getMessage());
}

// ======================
// STEP 1: Import Members from SUMMARY sheet
// ======================
$summarySheet = $spreadsheet->getSheetByName('SUMMARY');
if (!$summarySheet) {
    import_fail("SUMMARY sheet not found in the Excel file.");
}

$members = [];
$headerRow = 4;
$nameCol = get_header_column($summarySheet, $headerRow, ['NAMES', 'NAME']);
$coopCol = get_header_column($summarySheet, $headerRow, ['COOP NO', 'COOP NO.', 'COOP NUMBER', 'COOP#', 'COOP']);

if (!$nameCol || !$coopCol) {
    import_fail("Could not detect NAME/COOP NO columns in SUMMARY sheet.");
}

$row = $headerRow + 1;
$lastRow = $summarySheet->getHighestRow();

while ($row <= $lastRow) {
    $name = trim((string)$summarySheet->getCell($nameCol . $row)->getValue());
    $coop = normalize_coop_no($summarySheet->getCell($coopCol . $row)->getValue());

    if (empty($coop) || empty($name)) {
        $row++;
        continue;
    }

    $members[$coop] = ['name' => $name, 'coop_no' => $coop];
    $row++;
}

echo '<p class="text-muted">Found ' . count($members) . ' members.</p>';

// Clear existing member transactions and remove members not in sheet
$pdo->beginTransaction();
try {
    $pdo->exec("DELETE FROM transactions WHERE user_id IN (SELECT id FROM users WHERE role = 'member')");
    delete_members_not_in_list($pdo, array_keys($members));
    $pdo->commit();
    echo '<p class="text-muted">Cleared existing member transactions and removed members not in sheet.</p>';
} catch (Exception $e) {
    $pdo->rollBack();
    import_fail("Failed to clear existing data: " . $e->getMessage(), 500);
}

// Insert/Update users
$imported = 0;
$generatedPasswords = [];
foreach ($members as $coop => $data) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE coop_no = ?");
    $stmt->execute([$coop]);
    $exists = $stmt->fetch();

    if ($exists) {
        $pdo->prepare("UPDATE users SET name = ? WHERE coop_no = ?")
            ->execute([$data['name'], $coop]);
    } else {
        $placeholderEmail = strtolower(str_replace([' ', '/'], '', $coop)) . '@beulahcoop.local';
        $tempPassword = substr(bin2hex(random_bytes(6)), 0, 12);
        $passwordHash = password_hash($tempPassword, PASSWORD_BCRYPT);
        $pdo->prepare("
            INSERT INTO users (coop_no, name, email, password_hash, role)
            VALUES (?, ?, ?, ?, 'member')
        ")->execute([$coop, $data['name'], $placeholderEmail, $passwordHash]);
        $generatedPasswords[] = [$coop, $data['name'], $tempPassword];
    }
    $imported++;
}

echo '<p class="text-muted">' . $imported . ' members imported/updated.</p>';

if (!empty($generatedPasswords)) {
    $passwordFile = $uploadDir . 'import_passwords_' . date('Ymd_His') . '.csv';
    $fp = fopen($passwordFile, 'w');
    fputcsv($fp, ['Coop No', 'Name', 'Temporary Password']);
    foreach ($generatedPasswords as $row) {
        fputcsv($fp, $row);
    }
    fclose($fp);
    echo '<div class="alert alert-info">Temporary passwords generated for new members. File: ' . basename($passwordFile) . '</div>';
}

// ======================
// STEP 2: Import Transactions from individual sheets
// ======================
$transCount = 0;
$sheetsProcessed = 0;
$sheetsSkipped = [];

for ($i = 1; $i <= 80; $i++) {
    $sheetNames = ["NO $i", "No $i", "NO$i"];
    $sheet = null;

    foreach ($sheetNames as $name) {
        $sheet = $spreadsheet->getSheetByName($name);
        if ($sheet) break;
    }

    if (!$sheet) {
        $sheetsSkipped[] = $i;
        continue;
    }

    $sheetsProcessed++;

    // Get Coop No. Some sheets have the coop number in D4 instead of D3.
    $coopNo = normalize_coop_no(
        $sheet->getCell("D3")->getValue()
        ?: $sheet->getCell("C3")->getValue()
        ?: $sheet->getCell("D4")->getValue()
        ?: $sheet->getCell("B3")->getValue()
    );

    // If extracted value is not a known member, fall back to the derived coop number (BC01..BC80)
    if (empty($coopNo) || !isset($members[$coopNo])) {
        $derivedCoopNo = normalize_coop_no(sprintf('BC%02d', $i));
        if (isset($members[$derivedCoopNo])) {
            $coopNo = $derivedCoopNo;
        } else {
            echo "<p class='text-muted'>⚠ Sheet $i: Coop no not found or not in member list ($coopNo)</p>";
            continue;
        }
    }

    $stmt = $pdo->prepare("SELECT id FROM users WHERE coop_no = ?");
    $stmt->execute([$coopNo]);
    $user = $stmt->fetch();
    if (!$user) {
        echo "<p class='text-muted'>⚠ Sheet $i: User not found for coop $coopNo</p>";
        continue;
    }

    $userId = $user['id'];

    // Identify balance columns by looking for category headers in row 6 and type headers in row 7
    // Structure: Row 6 = SAVINGS | LOANS | LOANS INTEREST
    //           Row 7 = DR | CR | BALANCE | DR | CR | BALANCE | DR | CR | BALANCE
    $categoryRow = 6;
    $typeRow = 7;
    $savingsBalCol = null;
    $loanBalCol = null;
    $interestBalCol = null;
    
    // Find the balance column for each category by locating "BALANCE" cells in the type row
    // then scanning left on the category row to determine which category header applies.
    $highestCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());
    for ($col = 1; $col <= $highestCol; $col++) {
        $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
        $type = strtoupper(trim((string)$sheet->getCell($colLetter . $typeRow)->getValue()));
        if ($type !== 'BALANCE') continue;

        // Scan left to find the nearest non-empty category header in categoryRow
        $category = '';
        for ($c2 = $col; $c2 >= 1; $c2--) {
            $catLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c2);
            $candidate = trim((string)$sheet->getCell($catLetter . $categoryRow)->getValue());
            if ($candidate !== '' && $candidate !== null) {
                $category = strtoupper($candidate);
                break;
            }
        }

        if ($category === '') continue;

        if (strpos($category, 'SAVINGS') !== false) {
            $savingsBalCol = $colLetter;
        } elseif (strpos($category, 'INTEREST') !== false) {
            $interestBalCol = $colLetter;
        } elseif (strpos($category, 'LOAN') !== false) {
            // ensure not to pick LOAN INTEREST as loan principal
            if (strpos($category, 'INTEREST') === false) {
                $loanBalCol = $colLetter;
            }
        }
    }
    
    if (!$savingsBalCol && !$loanBalCol && !$interestBalCol) {
        echo "<p class='text-warning'>⚠ Sheet $i ($coopNo): No balance columns found in rows 6-7. Skipping.</p>";
        continue;
    }

    // Read transactions starting from row 8
    $prevSavingsBal = 0;
    $prevLoanBal = 0;
    $prevInterestBal = 0;
    $transForSheet = 0;

    for ($row = 8; $row <= 100; $row++) {
        $dateVal = $sheet->getCell("A$row")->getValue();
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

        // Reject obviously invalid dates (e.g. "TOTAL" rows, template artifacts)
        if ($transDate) {
            $year = (int)substr($transDate, 0, 4);
            if ($year < 2000 || $year > 2040) {
                $transDate = null;
            }
        }

        if (!$transDate) continue;

        // Read raw values from balance columns.
        // null return = cell is EMPTY = no transaction in this account on this row.
        // Explicit 0 = balance genuinely reached zero (loan paid off, interest cleared).
        $rawSav  = parse_amount($savingsBalCol  ? get_cell_value_safe($sheet, "$savingsBalCol$row")  : null);
        $rawLoan = parse_amount($loanBalCol     ? get_cell_value_safe($sheet, "$loanBalCol$row")     : null);
        $rawInt  = parse_amount($interestBalCol ? get_cell_value_safe($sheet, "$interestBalCol$row") : null);

        // Preserve null (empty cell) so this category is skipped for this row.
        // Apply abs() to loan/interest to normalise sheets that store balances with a negative sign.
        $currSavingsBal  = is_numeric($rawSav)  ? $rawSav        : null;
        $currLoanBal     = is_numeric($rawLoan) ? abs($rawLoan)  : null;
        $currInterestBal = is_numeric($rawInt)  ? abs($rawInt)   : null;

        // Compute change only for categories that had an entry this row.
        $savingsChange  = ($currSavingsBal  !== null) ? $currSavingsBal  - $prevSavingsBal  : null;
        $loanChange     = ($currLoanBal     !== null) ? $currLoanBal     - $prevLoanBal     : null;
        $interestChange = ($currInterestBal !== null) ? $currInterestBal - $prevInterestBal : null;

        // Savings: increase = deposit, decrease = withdrawal
        if ($savingsChange > 0) {
            insertTransaction($pdo, $userId, $transDate, 'savings_credit', $savingsChange, "Savings Deposit", $transCount);
            $transForSheet++;
        } elseif ($savingsChange < 0) {
            insertTransaction($pdo, $userId, $transDate, 'savings_debit', abs($savingsChange), "Savings Withdrawal", $transCount);
            $transForSheet++;
        }

        // Loans: increase = disbursement, decrease = repayment
        if ($loanChange > 0) {
            insertTransaction($pdo, $userId, $transDate, 'loan_disbursed', $loanChange, "Loan Disbursed", $transCount);
            $transForSheet++;
        } elseif ($loanChange < 0) {
            insertTransaction($pdo, $userId, $transDate, 'loan_repayment', abs($loanChange), "Loan Repayment", $transCount);
            $transForSheet++;
        }

        // Interest: increase = charged to member, decrease = paid by member
        if ($interestChange > 0) {
            insertTransaction($pdo, $userId, $transDate, 'interest_charged', $interestChange, "Loan Interest Charged", $transCount);
            $transForSheet++;
        } elseif ($interestChange < 0) {
            insertTransaction($pdo, $userId, $transDate, 'interest_paid', abs($interestChange), "Loan Interest Payment", $transCount);
            $transForSheet++;
        }

        // Advance only categories that had an entry this row
        if ($currSavingsBal  !== null) $prevSavingsBal  = $currSavingsBal;
        if ($currLoanBal     !== null) $prevLoanBal     = $currLoanBal;
        if ($currInterestBal !== null) $prevInterestBal = $currInterestBal;
    }
    
    if ($transForSheet > 0) {
        echo "<p class='text-muted'>✓ Sheet $i ($coopNo): $transForSheet transactions</p>";
    }
}
echo '<div class="alert alert-success mb-3"><strong>Import completed successfully!</strong></div>';
echo '<p class="text-muted">Members: ' . $imported . ' | Transactions processed: ' . $transCount . '</p>';
echo '<p class="text-muted">Sheets processed: ' . $sheetsProcessed . ' | Sheets with data: ' . (count(array_diff(range(1, 55), $sheetsSkipped))) . '</p>';

if (!empty($sheetsSkipped) && count($sheetsSkipped) < 20) {
    echo '<p class="text-muted small">Sheets not found: ' . implode(', ', $sheetsSkipped) . '</p>';
}

log_audit($pdo, $_SESSION['user_id'], 'excel_import', "Imported $imported members and $transCount transactions");

echo '<a href="admin/index.php" class="btn btn-primary mt-2">Go to Admin Dashboard</a>';
import_page_end();
?>
