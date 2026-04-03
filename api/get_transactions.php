<?php
// api/get_transactions.php
require_once '../includes/auth.php';
header('Content-Type: application/json');

$user_id = $_SESSION['role'] === 'admin' ? ($_GET['user_id'] ?? $_SESSION['user_id']) : $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT * FROM transactions WHERE user_id = ? ORDER BY trans_date DESC LIMIT 20");
$stmt->execute([$user_id]);
echo json_encode($stmt->fetchAll());