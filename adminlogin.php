<?php
require_once __DIR__ . '/auth.php';
include 'db.php';

if (bgi_is_logged_in()) {
    header('Location: ' . bgi_home_path_for_current_user());
    exit;
}

$identifier = trim($_POST['identifier'] ?? '');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $secret = trim($_POST['secret'] ?? '');

    if ($identifier === '' || $secret === '') {
        $error = 'Please enter your username and password.';
    } else {
        $stmt = $conn->prepare("SELECT id, username, password, role, idara, mohalla, totp_enabled FROM admin_users WHERE username = ? LIMIT 1");
        $stmt->bind_param("s", $identifier);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user && password_verify($secret, $user['password'])) {
            bgi_clear_auth_session();
            session_regenerate_id(true);

            if (!empty($user['totp_enabled'])) {
                $_SESSION['pending_2fa']         = true;
                $_SESSION['pending_2fa_user_id'] = (int) $user['id'];
                $conn->close();
                header('Location: verify_2fa.php');
                exit;
            }

            $_SESSION['user_id']        = (int) $user['id'];
            $_SESSION['username']       = $user['username'];
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['user_role']      = bgi_normalize_admin_role($user['role'] ?? BGI_ROLE_ADMIN);
            bgi_set_scope_session($user['idara'] ?? BGI_DEFAULT_IDARA, $user['mohalla'] ?? BGI_DEFAULT_MOHALLA);

            $conn->close();
            header('Location: ' . bgi_home_path_for_current_user());
            exit;
        }

        $error = 'Invalid username or password.';
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login — <?= htmlspecialchars(bgi_app_name()) ?></title>
    <link rel="stylesheet" href="app.css">
</head>
<body class="login-page">

<div class="login-container">
    <div class="login-brand"><?= htmlspecialchars(bgi_app_name()) ?></div>
    <h2>Admin Login</h2>
    <p class="login-subtitle">Staff and administrators sign in here.</p>

    <?php if ($error !== ''): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST">
        <label for="identifier">Username</label>
        <input type="text" id="identifier" name="identifier"
               value="<?= htmlspecialchars($identifier) ?>"
               placeholder="Enter your username"
               required autofocus>

        <label for="secret">Password</label>
        <input type="password" id="secret" name="secret"
               placeholder="Enter your password"
               required>

        <input type="submit" value="Login">
    </form>

    <p style="text-align:center;margin-top:20px;font-size:0.9rem;color:#666;">
        Member / Team Leader / Captain? <a href="login.php">Member Login →</a>
    </p>
</div>

</body>
</html>
