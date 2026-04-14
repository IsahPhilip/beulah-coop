<?php
// import-excel.php - Improved version with secure file upload
require_once 'includes/auth.php';
require_once 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Calculation\Exception as CalcException;

// Security: Only admin can access
if (($_SESSION['role'] ?? '') !== 'admin') {
    die("Access denied. Admin login required.");
}

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
flush();

$uploadDir = 'uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$allowedTypes = ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/octet-stream'];
$maxSize = 10 * 1024 * 1024; // 10MB

$inputFileName = '';

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file'])) {
    $file = $_FILES['excel_file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        die("Upload error: " . $file['error']);
    }

    if (!in_array($file['type'], $allowedTypes) && !str_ends_with(strtolower($file['name']), '.xlsx')) {
        die("Invalid file type. Only .xlsx files are allowed.");
    }

    if ($file['size'] > $maxSize) {
        die("File is too large. Maximum size is 10MB.");
    }

    $inputFileName = $uploadDir . '2025COOP_LEDGERS_' . time() . '.xlsx';
    if (!move_uploaded_file($file['tmp_name'], $inputFileName)) {
        die("Failed to save uploaded file.");
    }

    echo '<div class="alert alert-success">File uploaded successfully.</div>';
} else {
    die("No file uploaded.");
}

if (!file_exists($inputFileName)) {
    die("Excel file not found.");
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
    $labels = array_map(fn($v) => strtoupper(trim($v)), $labels);
    $highestCol = Coordinate::columnIndexFromString($sheet->getHighestColumn());
    for ($col = 1; $col <= $highestCol; $col++) {
        $val = (string)$sheet->getCell(Coordinate::stringFromColumnIndex($col) . $rowIndex)->getValue();
        $val = strtoupper(trim(preg_replace('/\s+/', ' ', $val)));
        if ($val !== '' && in_array($val, $labels, true)) {
            return Coordinate::stringFromColumnIndex($col);
        }
    }
    return null;
}

function get_cell_value_safe($sheet, $cellRef) {
    try {
        return $sheet->getCell($cellRef)->getCalculatedValue();
    } catch (CalcException $e) {
        return $sheet->getCell($cellRef)->getValue();
    } catch (Exception $e) {
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

// ======================
// STEP 1: Import Members from SUMMARY sheet
// ======================
$summarySheet = $spreadsheet->getSheetByName('SUMMARY');
if (!$summarySheet) {
    die("SUMMARY sheet not found in the Excel file.");
}

$members = [];
$headerRow = 4;
$nameCol = get_header_column($summarySheet, $headerRow, ['NAMES', 'NAME']);
$coopCol = get_header_column($summarySheet, $headerRow, ['COOP NO', 'COOP NO.', 'COOP NUMBER', 'COOP#', 'COOP']);

if (!$nameCol || !$coopCol) {
    die("Could not detect NAME/COOP NO columns in SUMMARY sheet.");
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
    die("Failed to clear existing data: " . $e->getMessage());
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

for ($i = 1; $i <= 55; $i++) {
    $sheetNames = ["NO $i", "No $i", "NO$i"];
    $sheet = null;

    foreach ($sheetNames as $name) {
        $sheet = $spreadsheet->getSheetByName($name);
        if ($sheet) break;
    }

    if (!$sheet) continue;

    // Get Coop No.
    $coopNo = normalize_coop_no(
        $sheet->getCell("D3")->getValue()
        ?: $sheet->getCell("C3")->getValue()
        ?: $sheet->getCell("B3")->getValue()
    );
    if (empty($coopNo) || !isset($members[$coopNo])) continue;

    $stmt = $pdo->prepare("SELECT id FROM users WHERE coop_no = ?");
    $stmt->execute([$coopNo]);
    $user = $stmt->fetch();
    if (!$user) continue;

    $userId = $user['id'];

    // Read transactions starting from row 8
    for ($row = 8; $row <= 100; $row++) {
        $dateVal = $sheet->getCell("A$row")->getValue();
        if (empty($dateVal)) continue;

        $transDate = null;
        if (is_numeric($dateVal) && $dateVal > 40000) {
            $transDate = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($dateVal)->format('Y-m-d');
        } elseif (is_string($dateVal)) {
            $transDate = date('Y-m-d', strtotime($dateVal));
        }

        if (!$transDate) continue;

        // Savings Credit (Column C)
        $savingsCr = parse_amount(get_cell_value_safe($sheet, "C$row"));
        if (is_numeric($savingsCr) && $savingsCr > 0) {
            insertTransaction($pdo, $userId, $transDate, 'savings_credit', $savingsCr, "Savings Deposit", $transCount);
        }

        // Loan Disbursed (Column E, DR)
        $loanDr = parse_amount(get_cell_value_safe($sheet, "E$row"));
        if (is_numeric($loanDr) && $loanDr > 0) {
            insertTransaction($pdo, $userId, $transDate, 'loan_disbursed', $loanDr, "Loan Disbursed", $transCount);
        }

        // Loan Repayment (Column F, CR)
        $loanCr = parse_amount(get_cell_value_safe($sheet, "F$row"));
        if (is_numeric($loanCr) && $loanCr > 0) {
            insertTransaction($pdo, $userId, $transDate, 'loan_repayment', $loanCr, "Loan Repayment", $transCount);
        }

        // Interest Charged (Column H, DR)
        $interest = parse_amount(get_cell_value_safe($sheet, "H$row"));
        if (is_numeric($interest) && $interest > 0) {
            insertTransaction($pdo, $userId, $transDate, 'interest_charged', $interest, "Loan Interest", $transCount);
        }
    }
}

echo '<div class="alert alert-success mb-3"><strong>Import completed successfully!</strong></div>';
echo '<p class="text-muted">Members: ' . $imported . ' | Transactions processed: ' . $transCount . '</p>';

log_audit($pdo, $_SESSION['user_id'], 'excel_import', "Imported $imported members and $transCount transactions");

echo '<a href="admin/index.php" class="btn btn-primary mt-2">Go to Admin Dashboard</a>';

// Helper function
function insertTransaction($pdo, $userId, $date, $type, $amount, $desc, &$counter) {
    if (!$date || $amount <= 0) return;
    $stmt = $pdo->prepare("
        INSERT INTO transactions (user_id, trans_date, type, amount, description, created_by)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$userId, $date, $type, $amount, $desc, $_SESSION['user_id'] ?? null]);
    $counter++;
}
?>
        </div>
    </div>
</body>
</html>
