<?php
// api/announcement.php - Get announcement details
require_once '../includes/auth.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid announcement ID']);
    exit();
}

$stmt = $pdo->prepare("
    SELECT a.*, u.name as creator_name 
    FROM announcements a 
    JOIN users u ON a.created_by = u.id 
    WHERE a.id = ? AND a.is_active = 1
");
$stmt->execute([$id]);
$announcement = $stmt->fetch();

if (!$announcement) {
    http_response_code(404);
    echo json_encode(['error' => 'Announcement not found']);
    exit();
}

echo json_encode([
    'id' => $announcement['id'],
    'title' => $announcement['title'],
    'content' => $announcement['content'],
    'priority' => $announcement['priority'],
    'target_audience' => $announcement['target_audience'],
    'creator_name' => $announcement['creator_name'],
    'created_at' => $announcement['created_at'],
    'expires_at' => $announcement['expires_at']
]);