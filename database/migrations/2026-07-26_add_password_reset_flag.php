<?php
require_once __DIR__ . '/../config/db.php';

try {
    $pdo->exec("ALTER TABLE users ADD COLUMN password_reset_required TINYINT(1) NOT NULL DEFAULT 1 AFTER twofa_enabled;");
    echo "Migration successful: 'password_reset_required' column added to 'users' table and set to 1 (true) by default." . PHP_EOL;
} catch (PDOException $e) {
    die("Migration failed: " . $e->getMessage() . PHP_EOL);
}
