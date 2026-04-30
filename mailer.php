<?php
require_once __DIR__ . '/auth.php';

function bgi_smtp_config_path(): string
{
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'bgi_attendance_system_smtp.php';
}

function bgi_default_smtp_config(): array
{
    return [
        'enabled' => false,
        'host' => '',
        'port' => 587,
        'encryption' => 'tls',
        'username' => '',
        'password' => '',
        'from_email' => '',
        'from_name' => 'Attendance System',
        'reply_to_email' => '',
        'timeout' => 15,
    ];
}

function bgi_sanitize_smtp_config(array $config): array
{
    $defaults = bgi_default_smtp_config();
    $normalized = array_merge($defaults, $config);

    $normalized['enabled'] = !empty($normalized['enabled']);
    $normalized['host'] = trim((string) ($normalized['host'] ?? ''));
    $normalized['port'] = max(1, min(65535, (int) ($normalized['port'] ?? $defaults['port'])));
    $normalized['encryption'] = strtolower(trim((string) ($normalized['encryption'] ?? $defaults['encryption'])));
    if (!in_array($normalized['encryption'], ['none', 'tls', 'ssl'], true)) {
        $normalized['encryption'] = $defaults['encryption'];
    }

    $normalized['username'] = trim((string) ($normalized['username'] ?? ''));
    $normalized['password'] = (string) ($normalized['password'] ?? '');
    $normalized['from_email'] = trim((string) ($normalized['from_email'] ?? ''));
    $normalized['from_name'] = trim((string) ($normalized['from_name'] ?? ''));
    $normalized['reply_to_email'] = trim((string) ($normalized['reply_to_email'] ?? ''));
    $normalized['timeout'] = max(5, min(60, (int) ($normalized['timeout'] ?? $defaults['timeout'])));

    return $normalized;
}

function bgi_load_smtp_config(): array
{
    $configPath = bgi_smtp_config_path();
    $config = bgi_default_smtp_config();

    if (is_file($configPath)) {
        $savedConfig = require $configPath;
        if (is_array($savedConfig)) {
            $config = array_merge($config, $savedConfig);
        }
    }

    return bgi_sanitize_smtp_config($config);
}

function bgi_save_smtp_config(array $config, ?string &$error = null): bool
{
    $error = null;
    $normalized = bgi_sanitize_smtp_config($config);

    if ($normalized['enabled']) {
        if ($normalized['host'] === '') {
            $error = 'SMTP host is required when email notifications are enabled.';
            return false;
        }

        if ($normalized['from_email'] === '' || !filter_var($normalized['from_email'], FILTER_VALIDATE_EMAIL)) {
            $error = 'A valid From Email is required when email notifications are enabled.';
            return false;
        }
    } elseif ($normalized['from_email'] !== '' && !filter_var($normalized['from_email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'From Email must be valid.';
        return false;
    }

    if ($normalized['reply_to_email'] !== '' && !filter_var($normalized['reply_to_email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Reply-To Email must be valid.';
        return false;
    }

    $configPath = bgi_smtp_config_path();
    $configDir = dirname($configPath);
    if (!is_dir($configDir) && !mkdir($configDir, 0755, true) && !is_dir($configDir)) {
        $error = 'Unable to create the SMTP config directory.';
        return false;
    }

    $configFileContents = "<?php\nreturn " . var_export($normalized, true) . ";\n";
    if (file_put_contents($configPath, $configFileContents) === false) {
        $error = 'Unable to save the SMTP configuration file.';
        return false;
    }

    return true;
}

function bgi_mailbox(string $email, string $name = ''): string
{
    $email = trim($email);
    $name = trim($name);

    if ($name === '') {
        return '<' . $email . '>';
    }

    $safeName = str_replace(['"', "\r", "\n"], ['\"', '', ''], $name);
    return '"' . $safeName . '" <' . $email . '>';
}

function bgi_smtp_read_response($socket, ?string &$responseText = null): int
{
    $responseText = '';
    $statusCode = 0;

    while (!feof($socket)) {
        $line = fgets($socket, 515);
        if ($line === false) {
            break;
        }

        $responseText .= $line;
        if (preg_match('/^(\d{3})([\s-])/', $line, $matches)) {
            $statusCode = (int) $matches[1];
            if ($matches[2] === ' ') {
                break;
            }
        }
    }

    return $statusCode;
}

function bgi_smtp_send_command($socket, string $command, array $allowedCodes, ?string &$error = null): bool
{
    fwrite($socket, $command . "\r\n");
    $code = bgi_smtp_read_response($socket, $responseText);

    if (!in_array($code, $allowedCodes, true)) {
        $error = trim($responseText) !== '' ? trim($responseText) : ('SMTP command failed: ' . $command);
        return false;
    }

    return true;
}

function bgi_sanitize_attachment_filename(string $filename): string
{
    $cleanName = preg_replace('/[^A-Za-z0-9._-]+/', '_', trim($filename));
    return $cleanName !== '' ? $cleanName : 'attachment.bin';
}

function bgi_normalize_email_body(string $body): string
{
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $body = str_replace("\n.", "\n..", $body);
    return str_replace("\n", "\r\n", $body);
}

function bgi_prepare_email_attachments(array $attachments): array
{
    $preparedAttachments = [];

    foreach ($attachments as $attachment) {
        $content = (string) ($attachment['content'] ?? '');
        if ($content === '') {
            continue;
        }

        $preparedAttachments[] = [
            'filename' => bgi_sanitize_attachment_filename((string) ($attachment['filename'] ?? 'attachment.bin')),
            'mime_type' => trim((string) ($attachment['mime_type'] ?? 'application/octet-stream')) ?: 'application/octet-stream',
            'content' => $content,
        ];
    }

    return $preparedAttachments;
}

function bgi_send_smtp_message(array $recipients, string $subject, string $body, ?string &$error = null, array $attachments = []): bool
{
    $error = null;
    $config = bgi_load_smtp_config();

    if (!$config['enabled']) {
        $error = 'SMTP is disabled.';
        return false;
    }

    if ($recipients === []) {
        $error = 'No email recipients were available.';
        return false;
    }

    $socketHost = $config['host'];
    if ($config['encryption'] === 'ssl') {
        $socketHost = 'ssl://' . $socketHost;
    }

    $socket = @stream_socket_client(
        $socketHost . ':' . $config['port'],
        $errno,
        $errstr,
        $config['timeout'],
        STREAM_CLIENT_CONNECT
    );

    if (!$socket) {
        $error = 'SMTP connection failed: ' . $errstr;
        return false;
    }

    stream_set_timeout($socket, $config['timeout']);

    $code = bgi_smtp_read_response($socket, $responseText);
    if ($code !== 220) {
        fclose($socket);
        $error = trim($responseText) !== '' ? trim($responseText) : 'SMTP server did not accept the connection.';
        return false;
    }

    if (!bgi_smtp_send_command($socket, 'EHLO localhost', [250], $error)) {
        fclose($socket);
        return false;
    }

    if ($config['encryption'] === 'tls') {
        if (!bgi_smtp_send_command($socket, 'STARTTLS', [220], $error)) {
            fclose($socket);
            return false;
        }

        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            $error = 'Unable to start TLS encryption with the SMTP server.';
            return false;
        }

        if (!bgi_smtp_send_command($socket, 'EHLO localhost', [250], $error)) {
            fclose($socket);
            return false;
        }
    }

    if ($config['username'] !== '') {
        if (!bgi_smtp_send_command($socket, 'AUTH LOGIN', [334], $error)) {
            fclose($socket);
            return false;
        }
        if (!bgi_smtp_send_command($socket, base64_encode($config['username']), [334], $error)) {
            fclose($socket);
            return false;
        }
        if (!bgi_smtp_send_command($socket, base64_encode($config['password']), [235], $error)) {
            fclose($socket);
            return false;
        }
    }

    if (!bgi_smtp_send_command($socket, 'MAIL FROM:<' . $config['from_email'] . '>', [250], $error)) {
        fclose($socket);
        return false;
    }

    $formattedRecipients = [];
    foreach ($recipients as $recipient) {
        $recipientEmail = trim((string) ($recipient['email'] ?? ''));
        if ($recipientEmail === '' || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            continue;
        }

        if (!bgi_smtp_send_command($socket, 'RCPT TO:<' . $recipientEmail . '>', [250, 251], $error)) {
            fclose($socket);
            return false;
        }

        $formattedRecipients[] = bgi_mailbox($recipientEmail, (string) ($recipient['name'] ?? ''));
    }

    if ($formattedRecipients === []) {
        fclose($socket);
        $error = 'No valid recipient email addresses were available.';
        return false;
    }

    if (!bgi_smtp_send_command($socket, 'DATA', [354], $error)) {
        fclose($socket);
        return false;
    }

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $preparedAttachments = bgi_prepare_email_attachments($attachments);
    $encodedTextBody = function_exists('quoted_printable_encode') ? quoted_printable_encode($body) : $body;
    $encodedTextBody = bgi_normalize_email_body($encodedTextBody);

    $headers = [
        'Date: ' . date(DATE_RFC2822),
        'From: ' . bgi_mailbox($config['from_email'], $config['from_name']),
        'To: ' . implode(', ', $formattedRecipients),
        'Subject: ' . $encodedSubject,
        'MIME-Version: 1.0',
    ];

    if ($preparedAttachments === []) {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: quoted-printable';
        $messageBody = $encodedTextBody;
    } else {
        $boundary = 'bgi-mixed-' . bin2hex(random_bytes(12));
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';

        $parts = [
            '--' . $boundary,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: quoted-printable',
            '',
            $encodedTextBody,
        ];

        foreach ($preparedAttachments as $attachment) {
            $parts[] = '--' . $boundary;
            $parts[] = 'Content-Type: ' . $attachment['mime_type'] . '; name="' . $attachment['filename'] . '"';
            $parts[] = 'Content-Transfer-Encoding: base64';
            $parts[] = 'Content-Disposition: attachment; filename="' . $attachment['filename'] . '"';
            $parts[] = '';
            $parts[] = trim(chunk_split(base64_encode($attachment['content']), 76, "\r\n"));
        }

        $parts[] = '--' . $boundary . '--';
        $messageBody = bgi_normalize_email_body(implode("\r\n", $parts));
    }

    if ($config['reply_to_email'] !== '') {
        $headers[] = 'Reply-To: ' . bgi_mailbox($config['reply_to_email'], $config['from_name']);
    }

    fwrite($socket, implode("\r\n", $headers) . "\r\n\r\n" . $messageBody . "\r\n.\r\n");
    $dataCode = bgi_smtp_read_response($socket, $dataResponse);
    if ($dataCode !== 250) {
        fclose($socket);
        $error = trim($dataResponse) !== '' ? trim($dataResponse) : 'SMTP server rejected the email body.';
        return false;
    }

    bgi_smtp_send_command($socket, 'QUIT', [221], $quitError);
    fclose($socket);
    return true;
}

function bgi_collect_absent_notification_recipients(mysqli $conn, array $member): array
{
    $recipients = [];

    $memberEmail = trim((string) ($member['email'] ?? ''));
    if ($memberEmail !== '' && filter_var($memberEmail, FILTER_VALIDATE_EMAIL)) {
        $recipients[strtolower($memberEmail)] = [
            'email' => $memberEmail,
            'name' => (string) ($member['member_name'] ?? 'Member'),
        ];
    }

    $teamLeaderItsId = trim((string) ($member['team_leader_its_id'] ?? ''));
    $captainItsId = trim((string) ($member['captain_its_id'] ?? ''));
    if ($teamLeaderItsId !== '') {
        $leaderStmt = $conn->prepare(
            "SELECT member_name, email, captain_its_id
             FROM members
             WHERE its_id = ? AND position = ? AND idara = ? AND mohalla = ?
             LIMIT 1"
        );
        if ($leaderStmt) {
            $teamLeaderPosition = BGI_POSITION_TEAM_LEADER;
            $leaderStmt->bind_param(
                "ssss",
                $teamLeaderItsId,
                $teamLeaderPosition,
                $member['idara'],
                $member['mohalla']
            );
            $leaderStmt->execute();
            $leaderRow = $leaderStmt->get_result()->fetch_assoc();
            $leaderStmt->close();

            $leaderEmail = trim((string) ($leaderRow['email'] ?? ''));
            if ($leaderEmail !== '' && filter_var($leaderEmail, FILTER_VALIDATE_EMAIL)) {
                $recipients[strtolower($leaderEmail)] = [
                    'email' => $leaderEmail,
                    'name' => (string) ($leaderRow['member_name'] ?? 'Team Leader'),
                ];
            }

            if ($captainItsId === '') {
                $captainItsId = trim((string) ($leaderRow['captain_its_id'] ?? ''));
            }
        }
    }

    if ($captainItsId !== '') {
        $captainStmt = $conn->prepare(
            "SELECT member_name, email
             FROM members
             WHERE its_id = ? AND position = ? AND idara = ? AND mohalla = ?
             LIMIT 1"
        );
        if ($captainStmt) {
            $captainPosition = BGI_POSITION_CAPTAIN;
            $captainStmt->bind_param("ssss", $captainItsId, $captainPosition, $member['idara'], $member['mohalla']);
            $captainStmt->execute();
            $captainRow = $captainStmt->get_result()->fetch_assoc();
            $captainStmt->close();

            $captainEmail = trim((string) ($captainRow['email'] ?? ''));
            if ($captainEmail !== '' && filter_var($captainEmail, FILTER_VALIDATE_EMAIL)) {
                $recipients[strtolower($captainEmail)] = [
                    'email' => $captainEmail,
                    'name' => (string) ($captainRow['member_name'] ?? 'Captain'),
                ];
            }
        }
    }

    return array_values($recipients);
}

function bgi_send_absent_notification(mysqli $conn, array $member, array $event, string $remark = ''): array
{
    $config = bgi_load_smtp_config();
    if (!$config['enabled']) {
        return ['status' => 'skipped', 'reason' => 'disabled', 'recipient_count' => 0];
    }

    $recipients = bgi_collect_absent_notification_recipients($conn, $member);
    if ($recipients === []) {
        return ['status' => 'skipped', 'reason' => 'no_recipients', 'recipient_count' => 0];
    }

    $subject = 'Absent Notice: ' . ($member['member_name'] ?? 'Member') . ' - ' . ($event['event_name'] ?? 'Event');
    if (!empty($event['event_code'])) {
        $subject .= ' (' . $event['event_code'] . ')';
    }

    $bodyLines = [
        'Attendance alert',
        '',
        'Member: ' . ($member['member_name'] ?? 'Member'),
        'ITS ID: ' . ($member['its_id'] ?? ''),
        'Position: ' . bgi_member_position_label($member['position'] ?? BGI_POSITION_MEMBER),
        'Event: ' . ($event['event_name'] ?? 'Event'),
    ];

    if (!empty($event['event_code'])) {
        $bodyLines[] = 'Event Code: ' . $event['event_code'];
    }

    $bodyLines[] = 'Date: ' . ($event['event_date'] ?? '');
    $bodyLines[] = 'Reporting Time: ' . ($event['reporting_time'] ?? '');
    $bodyLines[] = 'Scope: ' . ($member['idara'] ?? BGI_DEFAULT_IDARA) . ' / ' . ($member['mohalla'] ?? BGI_DEFAULT_MOHALLA);
    $bodyLines[] = 'Status: Absent';

    if (trim($remark) !== '') {
        $bodyLines[] = 'Remark: ' . trim($remark);
    }

    $bodyLines[] = '';
    $bodyLines[] = 'This message was sent by the attendance system.';
    $body = implode("\r\n", $bodyLines);

    if (!bgi_send_smtp_message($recipients, $subject, $body, $error)) {
        return ['status' => 'failed', 'reason' => 'smtp_error', 'recipient_count' => count($recipients), 'error' => $error];
    }

    return ['status' => 'sent', 'reason' => 'sent', 'recipient_count' => count($recipients)];
}
?>
