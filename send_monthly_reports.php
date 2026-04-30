<?php
require_once __DIR__ . '/monthly_reports_lib.php';
include 'db.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$targetMonth = 0;
$targetYear = 0;
$forceResend = false;

foreach ($argv ?? [] as $argument) {
    if ($argument === '--force') {
        $forceResend = true;
        continue;
    }

    if (preg_match('/^--month=(\d{1,2})$/', (string) $argument, $matches)) {
        $targetMonth = (int) $matches[1];
        continue;
    }

    if (preg_match('/^--year=(\d{4})$/', (string) $argument, $matches)) {
        $targetYear = (int) $matches[1];
    }
}

$period = bgi_monthly_report_period($targetYear > 0 ? $targetYear : null, $targetMonth > 0 ? $targetMonth : null);
$summary = bgi_send_monthly_reports($conn, $period['year'], $period['month'], ['mode' => 'all'], BGI_MONTHLY_REPORT_ROLE_ALL, $forceResend);

echo 'Monthly report run for ' . $period['month_label'] . PHP_EOL;
echo 'Total recipients: ' . $summary['total'] . PHP_EOL;
echo 'Sent: ' . $summary['sent'] . PHP_EOL;
echo 'Already sent: ' . $summary['already_sent'] . PHP_EOL;
echo 'Missing email: ' . $summary['missing_email'] . PHP_EOL;
echo 'SMTP disabled: ' . $summary['smtp_disabled'] . PHP_EOL;
echo 'Failed: ' . $summary['failed'] . PHP_EOL;

foreach ($summary['results'] as $result) {
    $recipient = $result['recipient'] ?? [];
    echo '- ' . (string) ($recipient['member_name'] ?? 'Recipient')
        . ' [' . bgi_member_position_label($recipient['role'] ?? BGI_POSITION_MEMBER) . ']'
        . ' => ' . bgi_monthly_report_status_label((string) ($result['status'] ?? 'pending'))
        . ' :: ' . (string) ($result['message'] ?? '')
        . PHP_EOL;
}

$conn->close();
