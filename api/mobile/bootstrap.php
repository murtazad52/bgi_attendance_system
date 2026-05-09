<?php
date_default_timezone_set('Asia/Kuwait');
ob_start();
require_once dirname(__DIR__, 2) . '/auth.php';
require_once dirname(__DIR__, 2) . '/db.php';
ob_clean();

bgi_ensure_admin_role_schema($conn);
if (function_exists('bgi_bootstrap_access_schema')) {
    bgi_bootstrap_access_schema($conn);
}

$allowedOrigins = [
    'https://badriattendance.duckdns.org',
    'http://localhost:8081',
    'http://localhost:19006',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

bgi_mobile_rate_limit();

function bgi_mobile_rate_limit(int $maxRequests = 60, int $windowSeconds = 60): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bgi_rl_' . md5($ip) . '.json';
    $now = time();
    $data = ['count' => 0, 'window_start' => $now];

    if (is_file($file)) {
        $raw = @file_get_contents($file);
        if ($raw !== false) {
            $parsed = json_decode($raw, true);
            if (is_array($parsed)) {
                $data = $parsed;
            }
        }
    }

    if ($now - (int) ($data['window_start'] ?? 0) >= $windowSeconds) {
        $data = ['count' => 0, 'window_start' => $now];
    }

    $data['count'] = (int) ($data['count'] ?? 0) + 1;
    @file_put_contents($file, json_encode($data), LOCK_EX);

    if ($data['count'] > $maxRequests) {
        http_response_code(429);
        header('Retry-After: ' . $windowSeconds);
        echo json_encode(['ok' => false, 'message' => 'Too many requests. Please wait before trying again.'], JSON_UNESCAPED_SLASHES);
        exit;
    }
}

function bgi_mobile_respond(array $payload, int $status = 200)
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    global $conn;
    if ($conn instanceof mysqli) {
        $conn->close();
    }

    exit;
}

function bgi_mobile_error(string $message, int $status = 400, array $extra = [])
{
    bgi_mobile_respond(array_merge([
        'ok' => false,
        'message' => $message,
    ], $extra), $status);
}

function bgi_mobile_input(): array
{
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    return $_POST;
}

function bgi_mobile_bind_dynamic_params(mysqli_stmt $stmt, string $types, array $params): void
{
    if ($types === '') {
        return;
    }

    $bind = [$types];
    foreach ($params as $index => $value) {
        $bind[] = &$params[$index];
    }

    call_user_func_array([$stmt, 'bind_param'], $bind);
}

function bgi_mobile_query_count(mysqli $conn, string $sql, string $types = '', array $params = []): int
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return 0;
    }

    if ($types !== '') {
        bgi_mobile_bind_dynamic_params($stmt, $types, $params);
    }

    $stmt->execute();
    $stmt->bind_result($value);
    $stmt->fetch();
    $stmt->close();

    return (int) $value;
}

function bgi_mobile_query_rows(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    if ($types !== '') {
        bgi_mobile_bind_dynamic_params($stmt, $types, $params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

function bgi_mobile_column_exists(mysqli $conn, string $table, string $column): bool
{
    $sql = "SELECT 1
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND column_name = ?
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result instanceof mysqli_result && $result->num_rows > 0;
    $stmt->close();

    return $exists;
}

function bgi_mobile_current_user_payload(): array
{
    $role = bgi_current_user_role();

    return [
        'username' => bgi_is_member() ? bgi_current_member_name() : (string) ($_SESSION['username'] ?? ''),
        'role' => $role,
        'roleLabel' => bgi_is_member() ? bgi_member_position_label(bgi_current_member_position()) : bgi_role_label($role),
        'scopeLabel' => bgi_current_scope_label(),
        'idara' => bgi_current_scope_idara(),
        'mohalla' => bgi_current_scope_mohalla(),
        'memberPosition' => bgi_is_member() ? bgi_current_member_position() : null,
        'reportMode' => bgi_is_member() ? bgi_member_report_scope_mode() : 'staff',
        'canTakeAttendance' => bgi_can_take_attendance(),
        'canViewReports' => bgi_can_view_reports(),
        'homePath' => bgi_home_path_for_current_user(),
    ];
}

function bgi_mobile_require_login(): void
{
    if (!bgi_is_logged_in()) {
        bgi_mobile_error('Please sign in again.', 401);
    }
}

function bgi_is_outside_kuwait(float $lat, float $lng): bool
{
    return $lat < 28.5247 || $lat > 30.0888 || $lng < 46.5527 || $lng > 48.4363;
}

function bgi_geo_distance_meters(float $lat1, float $lng1, float $lat2, float $lng2): int
{
    $r = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return (int) round($r * 2 * asin(sqrt($a)));
}

function bgi_mobile_format_event_rows(array $rows): array
{
    return array_map(static function (array $row): array {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'eventName' => (string) ($row['event_name'] ?? ''),
            'eventCode' => (string) ($row['event_code'] ?? ''),
            'eventDate' => (string) ($row['event_date'] ?? ''),
            'reportingTime' => (string) ($row['reporting_time'] ?? ''),
            'idara' => (string) ($row['idara'] ?? ''),
            'mohalla' => (string) ($row['mohalla'] ?? ''),
            'recordedCount' => isset($row['recorded_count']) ? (int) $row['recorded_count'] : null,
            'userStatus' => $row['user_status'] ?? null,
            'latitude' => array_key_exists('latitude', $row) && $row['latitude'] !== null ? (float) $row['latitude'] : null,
            'longitude' => array_key_exists('longitude', $row) && $row['longitude'] !== null ? (float) $row['longitude'] : null,
            'radiusMeters' => array_key_exists('radius_meters', $row) && $row['radius_meters'] !== null ? (int) $row['radius_meters'] : null,
        ];
    }, $rows);
}
