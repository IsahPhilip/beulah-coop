<?php
/**
 * Database Configuration
 * Uses environment variables from .env file
 */

// Load environment variables
require_once __DIR__ . '/../includes/env.php';

// Database configuration from .env
$host = env('DB_HOST', 'localhost');
$port = env('DB_PORT', '3306');
$dbname = env('DB_DATABASE', 'beulah_coop');
$username = env('DB_USERNAME', 'root');
$password = env('DB_PASSWORD', '');

try {
    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
} catch (PDOException $e) {
    if (env('APP_DEBUG', false)) {
        die("Database connection failed: " . $e->getMessage());
    } else {
        die("Database connection failed. Please check your configuration.");
    }
}
?>