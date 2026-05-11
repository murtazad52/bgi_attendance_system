<?php
require_once __DIR__ . '/auth.php';
include 'db.php';
require_once __DIR__ . '/rate_limit.php';

if (bgi_is_logged_in()) {
    header('Location: ' . bgi_home_path_for_current_user());
    exit;
}

$identifier = trim($_POST['identifier'] ?? '');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clientIp = bgi_client_ip();
    $secret = trim($_POST['secret'] ?? '');

    if (bgi_is_rate_limited($conn, $clientIp, 'admin_login', 5, 300)) {
        $error = 'Too many login attempts. Please wait 5 minutes and try again.';
    } elseif ($identifier === '' || $secret === '') {
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
                $_SESSION['pending_2fa']          = true;
                $_SESSION['pending_2fa_user_id']  = (int) $user['id'];
                $conn->close();
                header('Location: verify_2fa.php');
                exit;
            }

            $_SESSION['user_id']         = (int) $user['id'];
            $_SESSION['username']        = $user['username'];
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['user_role']       = bgi_normalize_admin_role($user['role'] ?? BGI_ROLE_ADMIN);
            bgi_set_scope_session($user['idara'] ?? BGI_DEFAULT_IDARA, $user['mohalla'] ?? BGI_DEFAULT_MOHALLA);

            $conn->close();
            header('Location: ' . bgi_home_path_for_current_user());
            exit;
        }

        bgi_record_rate_limit_hit($conn, $clientIp, 'admin_login');
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
    <style>
        .login-card {
            background: #fff;
            border-radius: 24px;
            box-shadow: 0 8px 40px rgba(20,68,53,0.13);
            padding: 40px 36px 32px;
            width: min(100%, 400px);
        }
        .login-logo {
            width: 56px;
            height: 56px;
            background: linear-gradient(135deg, #334155 0%, #51606d 100%);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 1.6rem;
        }
        .login-app-name {
            text-align: center;
            font-family: Georgia, "Times New Roman", serif;
            font-size: 1.35rem;
            font-weight: 700;
            color: var(--accent);
            margin: 0 0 4px;
        }
        .login-page-title {
            text-align: center;
            font-size: 0.88rem;
            color: var(--muted);
            margin: 0 0 28px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
        }
        .login-error {
            background: var(--danger-soft);
            color: #842029;
            border: 1px solid #f2bcc4;
            border-radius: 12px;
            padding: 12px 14px;
            font-size: 0.9rem;
            margin-bottom: 20px;
            text-align: center;
        }
        .login-field {
            margin-bottom: 16px;
        }
        .login-field label {
            display: block;
            font-size: 0.82rem;
            font-weight: 700;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 6px;
        }
        .login-field input {
            width: 100%;
            padding: 13px 16px;
            border: 1.5px solid var(--line);
            border-radius: 12px;
            font-size: 1rem;
            color: var(--ink);
            background: #f8faf9;
            outline: none;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .login-field input:focus {
            border-color: #334155;
            box-shadow: 0 0 0 3px rgba(51,65,85,0.1);
            background: #fff;
        }
        .login-submit {
            width: 100%;
            padding: 14px;
            margin-top: 8px;
            border: none;
            border-radius: 12px;
            background: linear-gradient(135deg, #334155 0%, #51606d 100%);
            color: #fff;
            font-size: 1rem;
            font-weight: 800;
            cursor: pointer;
            box-shadow: 0 6px 18px rgba(51,65,85,0.22);
            transition: transform 0.15s, box-shadow 0.15s;
        }
        .login-submit:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 24px rgba(51,65,85,0.28);
        }
        .login-submit:active { transform: scale(0.98); }
        .login-divider {
            text-align: center;
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid var(--line);
            font-size: 0.85rem;
            color: var(--muted);
        }
        .login-divider a {
            color: var(--accent);
            font-weight: 700;
            text-decoration: none;
        }
        .login-divider a:hover { text-decoration: underline; }
    </style>
</head>
<body class="login-page">

<div class="login-card">
    <div class="login-app-name"><?= htmlspecialchars(bgi_app_name()) ?></div>
    <div class="login-page-title">Admin Sign In</div>

    <?php if ($error !== ''): ?>
        <div class="login-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
        <div class="login-field">
            <label for="identifier">Username</label>
            <input type="text" id="identifier" name="identifier"
                   value="<?= htmlspecialchars($identifier) ?>"
                   placeholder="Enter your username"
                   required autofocus>
        </div>

        <div class="login-field">
            <label for="secret">Password</label>
            <input type="password" id="secret" name="secret"
                   placeholder="Enter your password"
                   required>
        </div>

        <button type="submit" class="login-submit">Sign In</button>
    </form>

    <div class="login-divider">
        Member / Team Leader / Captain? <a href="login.php">Member Login &rarr;</a>
    </div>
</div>

</body>
</html>
