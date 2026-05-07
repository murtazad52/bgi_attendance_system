<?php
require_once 'session_check.php';
require_once 'db.php';
require_once 'lib/totp.php';

if (bgi_is_member()) {
    header('Location: dashboard.php');
    exit;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$username = (string) ($_SESSION['username'] ?? '');
$flash = '';
$flashTone = 'success';
$error = '';

$stmt = $conn->prepare("SELECT totp_enabled, totp_secret FROM admin_users WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $userId);
$stmt->execute();
$adminRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

$totpEnabled = (bool) ($adminRow['totp_enabled'] ?? false);
$currentSecret = (string) ($adminRow['totp_secret'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'generate') {
        $newSecret = bgi_totp_generate_secret();
        $_SESSION['2fa_pending_secret'] = $newSecret;
        $currentSecret = $newSecret;

    } elseif ($action === 'enable') {
        $secret = (string) ($_SESSION['2fa_pending_secret'] ?? $currentSecret);
        $code   = trim((string) ($_POST['code'] ?? ''));

        if (!bgi_totp_verify($secret, $code)) {
            $error = 'Invalid code. Make sure your authenticator app is set up correctly and try again.';
        } else {
            $stmt = $conn->prepare("UPDATE admin_users SET totp_secret = ?, totp_enabled = 1 WHERE id = ?");
            $stmt->bind_param('si', $secret, $userId);
            $stmt->execute();
            $stmt->close();
            unset($_SESSION['2fa_pending_secret']);
            $totpEnabled = true;
            $currentSecret = $secret;
            $flash = '2FA has been enabled on your account.';
        }

    } elseif ($action === 'disable') {
        $code = trim((string) ($_POST['disable_code'] ?? ''));

        if (!bgi_totp_verify($currentSecret, $code)) {
            $error = 'Invalid code. 2FA was not disabled.';
        } else {
            $stmt = $conn->prepare("UPDATE admin_users SET totp_enabled = 0, totp_secret = NULL WHERE id = ?");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->close();
            $totpEnabled = false;
            $currentSecret = '';
            unset($_SESSION['2fa_pending_secret']);
            $flash = '2FA has been disabled on your account.';
            $flashTone = 'error';
        }
    }
}

$pendingSecret = (string) ($_SESSION['2fa_pending_secret'] ?? '');
$setupSecret   = $pendingSecret ?: ($totpEnabled ? $currentSecret : '');
$totpUri       = $setupSecret ? bgi_totp_uri($setupSecret, $username) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Two-Factor Authentication - <?= htmlspecialchars(bgi_app_name()) ?></title>
    <link rel="stylesheet" href="app.css">
    <script src="lib/qrcode.min.js"></script>
    <style>
        #qrcode { display: flex; justify-content: center; margin: 1rem 0; }
        #qrcode canvas, #qrcode img { border-radius: 10px; border: 4px solid #d6e7dd; }
        .twofa-secret {
            font-family: monospace;
            font-size: 1.3rem;
            font-weight: 800;
            letter-spacing: 0.2em;
            background: #f4f8f6;
            border: 1px solid #d6e7dd;
            border-radius: 10px;
            padding: 14px 18px;
            text-align: center;
            word-break: break-all;
            color: #176b53;
        }
        .twofa-steps { list-style: decimal; padding-left: 1.4rem; line-height: 2; color: #444; }
        .twofa-status { padding: 12px 16px; border-radius: 10px; font-weight: 700; margin-bottom: 1rem; }
        .twofa-status.on  { background: #dcfce7; color: #166534; }
        .twofa-status.off { background: #fee2e2; color: #991b1b; }
        .twofa-form-group { margin-top: 1.2rem; }
        .section-divider { border: none; border-top: 1px solid #e5e7eb; margin: 2rem 0; }
    </style>
</head>
<body>
<div class="topbar">
    <div><strong><?= htmlspecialchars(bgi_app_name()) ?></strong></div>
    <div>
        <a href="dashboard.php" class="back">← Dashboard</a>
        <a href="logout.php" class="logout" style="margin-left:8px;">Logout</a>
    </div>
</div>

<div class="container">
    <h2>Two-Factor Authentication</h2>
    <p>Add an extra layer of security to your account with a one-time code from your authenticator app.</p>

    <?php if ($flash !== ''): ?>
        <div class="message <?= $flashTone === 'error' ? 'error' : 'success' ?>"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="message error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="twofa-status <?= $totpEnabled ? 'on' : 'off' ?>">
        2FA is currently <?= $totpEnabled ? '✓ Enabled' : '✗ Disabled' ?> for your account.
    </div>

    <?php if (!$totpEnabled): ?>
    <h3>Set Up 2FA</h3>
    <ol class="twofa-steps">
        <li>Install <strong>Google Authenticator</strong>, <strong>Authy</strong>, or any TOTP app on your phone.</li>
        <li>Click <strong>Generate Secret Key</strong> below.</li>
        <li>Open your authenticator app → Add account → Enter setup key manually.</li>
        <li>Enter the key shown below as the <em>Secret Key</em>.</li>
        <li>Enter the 6-digit code your app shows to confirm and enable 2FA.</li>
    </ol>

    <?php if (!$pendingSecret): ?>
    <form method="POST" action="">
        <input type="hidden" name="action" value="generate">
        <button type="submit" class="btn">Generate Secret Key</button>
    </form>
    <?php else: ?>
    <div class="twofa-form-group">
        <label>Scan with your authenticator app</label>
        <div id="qrcode"></div>
        <p style="text-align:center;font-size:0.85rem;color:#888;margin-top:0.3rem;">
            Can't scan? Enter the key manually below.
        </p>
        <label style="margin-top:1rem;">Secret Key (manual entry)</label>
        <div class="twofa-secret"><?= htmlspecialchars(chunk_split($pendingSecret, 4, ' ')) ?></div>
        <p style="font-size:0.85rem;color:#666;margin-top:0.5rem;">
            Account name: <strong><?= htmlspecialchars($username) ?></strong> &nbsp;|&nbsp;
            Issuer: <strong>BGI Attendance</strong>
        </p>
    </div>
    <script>
        new QRCode(document.getElementById('qrcode'), {
            text: <?= json_encode($totpUri) ?>,
            width: 220,
            height: 220,
            colorDark: '#0f3d2a',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.M
        });
    </script>

    <form method="POST" action="" style="margin-top:1.5rem;">
        <input type="hidden" name="action" value="enable">
        <div class="form-group">
            <label for="code">Enter the 6-digit code from your app to confirm</label>
            <input type="text" id="code" name="code" inputmode="numeric" pattern="\d{6}" maxlength="6"
                   placeholder="000000" required autofocus
                   style="letter-spacing:0.3em;font-size:1.2rem;text-align:center;max-width:200px;">
        </div>
        <button type="submit" class="btn">Enable 2FA</button>
        <a href="setup_2fa.php" class="btn secondary" style="margin-left:8px;">Reset</a>
    </form>
    <?php endif; ?>

    <?php else: ?>
    <hr class="section-divider">
    <h3>Disable 2FA</h3>
    <p>Enter your current authenticator code to disable 2FA on your account.</p>
    <form method="POST" action="">
        <input type="hidden" name="action" value="disable">
        <div class="form-group">
            <label for="disable_code">Authenticator Code</label>
            <input type="text" id="disable_code" name="disable_code" inputmode="numeric"
                   pattern="\d{6}" maxlength="6" placeholder="000000" required
                   style="letter-spacing:0.3em;font-size:1.2rem;text-align:center;max-width:200px;">
        </div>
        <button type="submit" class="btn danger">Disable 2FA</button>
    </form>
    <?php endif; ?>
</div>
</body>
</html>
