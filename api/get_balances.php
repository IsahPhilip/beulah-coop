<?php
// api/get_balances.php
require_once '../includes/auth.php';
header('Content-Type: application/json');

$user_id = $_SESSION['role'] === 'admin' ? ($_GET['user_id'] ?? $_SESSION['user_id']) : $_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT 
        SUM(CASE WHEN type IN ('savings_credit') THEN amount ELSE 0 END) as savings,
        SUM(CASE WHEN type = 'loan_disbursed' THEN amount ELSE 0 END) - 
        SUM(CASE WHEN type = 'loan_repayment' THEN amount ELSE 0 END) as loan_outstanding
    FROM transactions WHERE user_id = ?
");
$stmt->execute([$user_id]);
echo json_encode($stmt->fetch());