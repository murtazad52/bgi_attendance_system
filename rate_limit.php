<?php
require_once __DIR__ . '/auth.php';

function bgi_ensure_rate_limit_table(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $conn->query(
        "CREATE TABLE IF NOT EXISTS login_rate_limit (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ip_hash CHAR(64) NOT NULL,
            action VARCHAR(30) NOT NULL,
            attempt_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ip_action_time (ip_hash, action, attempt_at)
        )"
    );
}

function bgi_is_rate_limited(mysqli $conn, string $ip, string $action, int $maxAttempts, int $windowSeconds): bool
{
    bgi_ensure_rate_limit_table($conn);

    $ipHash = hash('sha256', $ip);
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS cnt FROM login_rate_limit
         WHERE ip_hash = ? AND action = ?
           AND attempt_at > DATE_SUB(NOW(), INTERVAL ? SECOND)"
    );
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("ssi", $ipHash, $action, $windowSeconds);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row['cnt'] ?? 0) >= $maxAttempts;
}

function bgi_record_rate_limit_hit(mysqli $conn, string $ip, string $action): void
{
    bgi_ensure_rate_limit_table($conn);

    $ipHash = hash('sha256', $ip);
    $stmt = $conn->prepare("INSERT INTO login_rate_limit (ip_hash, action) VALUES (?, ?)");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param("ss", $ipHash, $action);
    $stmt->execute();
    $stmt->close();

    // Prune entries older than 24 hours to keep the table small
    $conn->query("DELETE FROM login_rate_limit WHERE attempt_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
}

function bgi_client_ip(): string
{
    // Trust X-Forwarded-For only if you control the proxy; otherwise use REMOTE_ADDR
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}
