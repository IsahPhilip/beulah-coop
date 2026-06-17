<?php
// admin/import.php - Simple Excel upload interface
require_once '../includes/auth.php';
if ($_SESSION['role'] === 'member') {
    header("Location: ../member/dashboard.php");
    exit();
}
if ($_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}
?>

<?php
$pageTitle = 'Import Excel - Beulah Coop';
$useDashboardLayout = true;
?>
<?php include '../includes/header.php'; ?>
<div class="dash-grid">
    <h2>Import New Excel Data</h2>
    <form action="../import-excel.php" method="POST" enctype="multipart/form-data">
        <div class="mb-3">
            <label class="form-label">Upload Excel Ledger (.xlsx)</label>
            <input type="file" name="excel_file" class="form-control" accept=".xlsx" required>
        </div>
        <button type="submit" class="btn btn-warning">Process Import</button>
    </form>
    <p class="mt-3 text-muted">Note: This will update members and add new transactions.</p>
</div>
<?php include '../includes/footer.php'; ?>
