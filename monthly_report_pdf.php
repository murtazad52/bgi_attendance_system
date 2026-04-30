<?php
require_once __DIR__ . '/monthly_reports_lib.php';
include('session_check.php');
include('db.php');

bgi_require_roles([BGI_ROLE_SUPER_ADMIN, BGI_ROLE_IDARA_ADMIN, BGI_ROLE_MOHALLA_ADMIN]);

$requestedItsId = trim((string) ($_GET['its_id'] ?? ''));
$requestedRole = bgi_normalize_member_position($_GET['role'] ?? '');
$requestedMonth = filter_var($_GET['month'] ?? 0, FILTER_VALIDATE_INT);
$requestedMonth = $requestedMonth !== false && $requestedMonth >= 1 && $requestedMonth <= 12 ? $requestedMonth : 0;
$requestedYear = filter_var($_GET['year'] ?? 0, FILTER_VALIDATE_INT);
$currentYear = (int) date('Y');
$requestedYear = $requestedYear !== false && $requestedYear >= $currentYear - 5 && $requestedYear <= $currentYear + 1 ? $requestedYear : 0;

if (!preg_match('/^\d{8}$/', $requestedItsId) || !in_array($requestedRole, [BGI_POSITION_CAPTAIN, BGI_POSITION_TEAM_LEADER, BGI_POSITION_MEMBER], true) || $requestedMonth === 0 || $requestedYear === 0) {
    $conn->close();
    http_response_code(404);
    exit;
}

$scopeFilter = bgi_monthly_scope_filter_for_current_user();
$recipients = bgi_fetch_monthly_report_recipients($conn, $requestedYear, $requestedMonth, $scopeFilter, $requestedRole);
$selectedRecipient = null;

foreach ($recipients as $recipient) {
    if ((string) ($recipient['its_id'] ?? '') === $requestedItsId) {
        $selectedRecipient = $recipient;
        break;
    }
}

if (!$selectedRecipient) {
    $conn->close();
    http_response_code(404);
    exit;
}

$context = bgi_build_monthly_report_context($conn, $selectedRecipient, $requestedYear, $requestedMonth);
$pdfAttachment = bgi_build_monthly_report_pdf($context);
$conn->close();

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $pdfAttachment['filename']) . '"');
header('Content-Length: ' . strlen($pdfAttachment['content']));
echo $pdfAttachment['content'];
exit;
