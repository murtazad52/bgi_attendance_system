<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';
include 'db.php';

bgi_require_roles([BGI_ROLE_SUPER_ADMIN]);

$config = bgi_load_smtp_config();
$error = '';
$success = '';
$testEmail = trim((string) ($config['reply_to_email'] ?: $config['from_email'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? 'save'));
    $testEmail = trim((string) ($_POST['test_email'] ?? ''));
    $submittedConfig = [
        'enabled' => isset($_POST['enabled']),
        'host' => trim($_POST['host'] ?? ''),
        'port' => trim($_POST['port'] ?? ''),
        'encryption' => trim($_POST['encryption'] ?? 'tls'),
        'username' => trim($_POST['username'] ?? ''),
        'password' => (string) ($_POST['password'] ?? ''),
        'from_email' => trim($_POST['from_email'] ?? ''),
        'from_name' => trim($_POST['from_name'] ?? ''),
        'reply_to_email' => trim($_POST['reply_to_email'] ?? ''),
        'timeout' => trim($_POST['timeout'] ?? '15'),
    ];

    if (bgi_save_smtp_config($submittedConfig, $saveError)) {
        $config = bgi_load_smtp_config();
        $success = 'SMTP settings saved successfully.';

        if ($action === 'test') {
            if (!$config['enabled']) {
                $error = 'Enable SMTP email notifications before sending a test email.';
            } elseif ($testEmail === '' || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid Test Email Recipient.';
            } else {
                $subject = 'SMTP Test Email - ' . bgi_app_name();
                $body = implode("\r\n", [
                    'This is a test email from the attendance system.',
                    '',
                    'Host: ' . (string) ($config['host'] ?? ''),
                    'Port: ' . (string) ($config['port'] ?? ''),
                    'Encryption: ' . strtoupper((string) ($config['encryption'] ?? 'none')),
                    'From Email: ' . (string) ($config['from_email'] ?? ''),
                    'Time: ' . date('Y-m-d H:i:s'),
                    '',
                    'If you received this message, the SMTP settings are working.',
                ]);

                $recipients = [[
                    'email' => $testEmail,
                    'name' => 'SMTP Test Recipient',
                ]];

                if (bgi_send_smtp_message($recipients, $subject, $body, $smtpError)) {
                    $success = 'SMTP settings saved and test email sent to ' . $testEmail . '.';
                } else {
                    $error = 'SMTP settings were saved, but the test email failed: ' . ($smtpError ?: 'Unknown SMTP error.');
                }
            }
        }
    } else {
        $config = bgi_sanitize_smtp_config($submittedConfig);
        $error = $saveError ?: 'Unable to save the SMTP settings.';
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMTP Settings - <?= htmlspecialchars(bgi_app_name()) ?></title>
    <link rel="stylesheet" href="app.css">
</head>
<body class="app-page page-form">
    <div class="container">
        <a href="dashboard.php" class="btn secondary back-btn">Back to Dashboard</a>
        <h2>SMTP Settings</h2>
        <p class="page-intro">Save the email server details used for absent-notification emails. Absent emails are sent when attendance is explicitly recorded with the status <strong>Absent</strong>.</p>

        <?php if ($success !== ''): ?>
            <div class="message success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="message error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>
                    <input type="checkbox" name="enabled" value="1" <?= !empty($config['enabled']) ? 'checked' : '' ?>>
                    Enable SMTP email notifications
                </label>
            </div>

            <div class="form-group">
                <label for="host">SMTP Host</label>
                <input type="text" id="host" name="host" value="<?= htmlspecialchars((string) ($config['host'] ?? '')) ?>">
            </div>

            <div class="form-row">
                <div class="field-fixed-220">
                    <label for="port">Port</label>
                    <input type="text" id="port" name="port" value="<?= htmlspecialchars((string) ($config['port'] ?? 587)) ?>">
                </div>

                <div class="field-fixed-220">
                    <label for="encryption">Encryption</label>
                    <select id="encryption" name="encryption">
                        <option value="none" <?= ($config['encryption'] ?? '') === 'none' ? 'selected' : '' ?>>None</option>
                        <option value="tls" <?= ($config['encryption'] ?? '') === 'tls' ? 'selected' : '' ?>>TLS</option>
                        <option value="ssl" <?= ($config['encryption'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL</option>
                    </select>
                </div>

                <div class="field-fixed-220">
                    <label for="timeout">Timeout (seconds)</label>
                    <input type="text" id="timeout" name="timeout" value="<?= htmlspecialchars((string) ($config['timeout'] ?? 15)) ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" value="<?= htmlspecialchars((string) ($config['username'] ?? '')) ?>">
            </div>

            <div class="form-group">
                <label for="password">Password / App Password</label>
                <input type="password" id="password" name="password" value="<?= htmlspecialchars((string) ($config['password'] ?? '')) ?>">
            </div>

            <div class="form-group">
                <label for="from_email">From Email</label>
                <input type="email" id="from_email" name="from_email" value="<?= htmlspecialchars((string) ($config['from_email'] ?? '')) ?>">
            </div>

            <div class="form-group">
                <label for="from_name">From Name</label>
                <input type="text" id="from_name" name="from_name" value="<?= htmlspecialchars((string) ($config['from_name'] ?? '')) ?>">
            </div>

            <div class="form-group">
                <label for="reply_to_email">Reply-To Email (optional)</label>
                <input type="email" id="reply_to_email" name="reply_to_email" value="<?= htmlspecialchars((string) ($config['reply_to_email'] ?? '')) ?>">
            </div>

            <div class="form-group">
                <label for="test_email">Test Email Recipient</label>
                <input type="email" id="test_email" name="test_email" value="<?= htmlspecialchars($testEmail) ?>">
                <div class="small-note">Optional for save. Required only when sending a test email.</div>
            </div>

            <div class="button-row">
                <button type="submit" name="action" value="save" class="btn">Save SMTP Settings</button>
                <button type="submit" name="action" value="test" class="btn secondary">Save and Send Test Email</button>
            </div>
        </form>
    </div>
</body>
</html>
