<?php
/**
 * Helper Functions
 * Uses environment variables for configuration
 */

// Ensure env loader is available
if (!function_exists('env')) {
    require_once __DIR__ . '/env.php';
}

/**
 * Format amount as Nigerian Naira
 */
function format_money($amount) {
    return '₦' . number_format((float)$amount, 2);
}

/**
 * Audit Logging
 * Uses AUDIT_LOG_TABLE from .env
 */
function log_audit($pdo, $user_id, $action, $details = '') {
    // Check if audit logging is enabled
    if (!env('AUDIT_LOG_ENABLED', true)) {
        return;
    }
    
    $auditTable = env('AUDIT_LOG_TABLE', 'audit_logs');
    
    $stmt = $pdo->prepare("
        INSERT INTO {$auditTable} (user_id, action, details, ip_address) 
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([
        $user_id,
        $action,
        $details,
        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);
}

/**
 * Check whether a database table contains a column.
 */
function table_has_column(PDO $pdo, string $table, string $column): bool
{
    static $columnMap = [];
    $cacheKey = strtolower($table . '.' . $column);

    if (array_key_exists($cacheKey, $columnMap)) {
        return $columnMap[$cacheKey];
    }

    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
        $stmt->execute([$column]);
        $columnMap[$cacheKey] = (bool)$stmt->fetch();
    } catch (Throwable $e) {
        $columnMap[$cacheKey] = false;
    }

    return $columnMap[$cacheKey];
}

/**
 * Some deployments have a non-auto-increment primary key on transactions.
 * Generate the next numeric id as a compatibility fallback.
 */
function next_numeric_table_id(PDO $pdo, string $table): int
{
    $stmt = $pdo->query("SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM `{$table}`");
    $row = $stmt->fetch();
    return max(1, (int)($row['next_id'] ?? 1));
}

/**
 * Insert a transaction while tolerating older schemas.
 */
function create_transaction(
    PDO $pdo,
    int $userId,
    string $transDate,
    string $type,
    float $amount,
    string $description = '',
    $createdBy = null
): int {
    $hasCreatedBy = table_has_column($pdo, 'transactions', 'created_by');

    $columns = ['user_id', 'trans_date', 'type', 'amount', 'description'];
    $values = [$userId, $transDate, $type, $amount, $description];

    if ($hasCreatedBy) {
        $columns[] = 'created_by';
        $values[] = $createdBy;
    }

    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $columnSql = implode(', ', $columns);

    try {
        $stmt = $pdo->prepare("INSERT INTO transactions ({$columnSql}) VALUES ({$placeholders})");
        $stmt->execute($values);
        return (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        $isDuplicatePrimaryZero = $e->getCode() === '23000'
            && strpos($e->getMessage(), "Duplicate entry '0' for key 'PRIMARY'") !== false;

        if (!$isDuplicatePrimaryZero) {
            throw $e;
        }

        $nextId = next_numeric_table_id($pdo, 'transactions');
        array_unshift($columns, 'id');
        array_unshift($values, $nextId);
        $columnSql = implode(', ', $columns);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        $stmt = $pdo->prepare("INSERT INTO transactions ({$columnSql}) VALUES ({$placeholders})");
        $stmt->execute($values);

        return $nextId;
    }
}

/**
 * Calculate current savings and loan balance for a user
 */
function get_user_balances($pdo, $user_id) {
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN type IN ('savings_credit') THEN amount ELSE 0 END), 0) AS total_savings,
            COALESCE(SUM(CASE WHEN type = 'loan_disbursed' THEN amount ELSE 0 END), 0) -
            COALESCE(SUM(CASE WHEN type = 'loan_repayment' THEN amount ELSE 0 END), 0) AS outstanding_loan,
            COALESCE(SUM(CASE WHEN type = 'interest_charged' THEN amount ELSE 0 END), 0) AS total_interest
        FROM transactions 
        WHERE user_id = ?
    ");
    $stmt->execute([$user_id]);
    return $stmt->fetch();
}

/**
 * Get password minimum length from environment
 */
function get_password_min_length() {
    return (int) env('PASSWORD_MIN_LENGTH', 8);
}

/**
 * Get upload configuration
 */
function get_upload_config() {
    return [
        'max_size' => (int) env('UPLOAD_MAX_SIZE', 5242880),
        'profiles_dir' => env('UPLOAD_PROFILES_DIR', 'uploads/profiles/'),
        'allowed_types' => explode(',', env('ALLOWED_IMAGE_TYPES', 'jpeg,png,gif,webp')),
    ];
}

/**
 * Get 2FA configuration
 */
function get_2fa_config() {
    return [
        'code_length' => (int) env('2FA_CODE_LENGTH', 6),
        'code_expiry' => (int) env('2FA_CODE_EXPIRY', 600),
        'issuer' => env('2FA_ISSUER', 'Beulah Coop'),
    ];
}

/**
 * Get mail configuration
 */
function get_mail_config() {
    return [
        'host' => env('MAIL_HOST', 'smtp.gmail.com'),
        'port' => (int) env('MAIL_PORT', 587),
        'username' => env('MAIL_USERNAME', ''),
        'password' => env('MAIL_PASSWORD', ''),
        'encryption' => env('MAIL_ENCRYPTION', 'tls'),
        'from_address' => env('MAIL_FROM_ADDRESS', 'no-reply@beulahcoop.local'),
        'from_name' => env('MAIL_FROM_NAME', env('APP_NAME', 'Beulah Coop')),
    ];
}

/**
 * Format date according to environment configuration
 */
function format_date($date, $format = 'display') {
    $formats = [
        'date' => env('DATE_FORMAT', 'Y-m-d'),
        'datetime' => env('DATETIME_FORMAT', 'Y-m-d H:i:s'),
        'display' => env('DISPLAY_DATE_FORMAT', 'd M Y, h:i A'),
    ];
    
    $fmt = $formats[$format] ?? $formats['display'];
    return date($fmt, strtotime($date));
}

/**
 * Get timezone from environment
 */
function get_timezone() {
    return env('TIMEZONE', 'Africa/Lagos');
}

/**
 * Set application timezone
 */
function set_app_timezone() {
    date_default_timezone_set(get_timezone());
}

// Set timezone on function load
set_app_timezone();
?>
