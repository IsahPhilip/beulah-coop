<?php
// includes/functions.php - Helper Functions

/**
 * Format amount as Nigerian Naira
 */
function format_money($amount) {
    return '₦' . number_format((float)$amount, 2);
}

/**
 * Audit Logging
 */
function log_audit($pdo, $user_id, $action, $details = '') {
    $stmt = $pdo->prepare("
        INSERT INTO audit_logs (user_id, action, details, ip_address) 
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
?>