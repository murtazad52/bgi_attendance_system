<?php
require_once 'auth.php';
require_once 'db.php';
require_once 'lib/totp.php';

if (!isset($_SESSION['pending_2fa']) || !isset($_SESSION['pending_2fa_user_id'])) {
    header('Location: adminlogin.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim((string) ($_POST['code'] ?? ''));

    $stmt = $conn->prepare("SELECT id, username, password, role, idara, mohalla, totp_secret FROM admin_users WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $_SESSION['pending_2fa_user_id']);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user || !bgi_totp_verify((string) $user['totp_secret'], $code)) {
        $error = 'Invalid code. Please try again.';
    } else {
        unset($_SESSION['pending_2fa'], $_SESSION['pending_2fa_user_id']);
        session_regenerate_id(true);

        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['username'] = (string) $user['username'];
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['user_role'] = bgi_normalize_admin_role($user['role'] ?? BGI_ROLE_IDARA_ADMIN);
        bgi_set_scope_session($user['idara'] ?? BGI_DEFAULT_IDARA, $user['mohalla'] ?? BGI_DEFAULT_MOHALLA);

        header('Location: dashboard.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Two-Factor Authentication - <?= htmlspecialchars(bgi_app_name()) ?></title>
    <link rel="stylesheet" href="app.css">
</head>
<body>
<div class="login-container">
    <div class="login-brand"><?= htmlspecialchars(bgi_app_name()) ?></div>
    <h2>Two-Factor Authentication</h2>
    <p class="login-subtitle">Enter the 6-digit code from your authenticator app.</p>

    <?php if ($error !== ''): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="" autocomplete="off">
        <label for="code">Authenticator Code</label>
        <input type="text" id="code" name="code" inputmode="numeric" pattern="\d{6}" maxlength="6"
               placeholder="000000" required autofocus style="letter-spacing: 0.3em; font-size: 1.4rem; text-align: center;">
        <input type="submit" value="Verify">
    </form>

    <p style="margin-top: 1rem; font-size: 0.9rem; color: #888; text-align: center;">
        <a href="login.php">← Back to Login</a>
    </p>
</div>
</body>
</html>
