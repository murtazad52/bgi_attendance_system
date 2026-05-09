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
        $error = 'Please enter your ITS ID and phone number.';
    } elseif (!preg_match('/^\d{8}$/', $identifier)) {
        $error = 'ITS ID must be exactly 8 digits.';
    } else {
        $stmt = $conn->prepare("SELECT id, its_id, member_name, phone, idara, mohalla, position FROM members WHERE its_id = ? LIMIT 1");
        $stmt->bind_param("s", $identifier);
        $stmt->execute();
        $member = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($member && hash_equals((string) $member['phone'], $secret)) {
            bgi_clear_auth_session();
            $_SESSION['member_logged_in']  = true;
            $_SESSION['user_role']         = BGI_ROLE_MEMBER;
            $_SESSION['member_id']         = (int) $member['id'];
            $_SESSION['member_name']       = $member['member_name'];
            $_SESSION['member_its_id']     = $member['its_id'];
            $_SESSION['member_position']   = bgi_normalize_member_position($member['position'] ?? BGI_POSITION_MEMBER);
            $_SESSION['username']          = $member['member_name'];
            bgi_set_scope_session($member['idara'] ?? BGI_DEFAULT_IDARA, $member['mohalla'] ?? BGI_DEFAULT_MOHALLA);

            $conn->close();
            header('Location: ' . bgi_home_path_for_current_user());
            exit;
        }

        $error = 'Invalid ITS ID or phone number.';
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Member Login — <?= htmlspecialchars(bgi_app_name()) ?></title>
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
            background: linear-gradient(135deg, var(--accent) 0%, #23956f 100%);
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
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(23,107,83,0.1);
            background: #fff;
        }
        .login-submit {
            width: 100%;
            padding: 14px;
            margin-top: 8px;
            border: none;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--accent) 0%, #23956f 100%);
            color: #fff;
            font-size: 1rem;
            font-weight: 800;
            cursor: pointer;
            box-shadow: 0 6px 18px rgba(23,107,83,0.22);
            transition: transform 0.15s, box-shadow 0.15s;
        }
        .login-submit:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 24px rgba(23,107,83,0.28);
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
        body.login-page {
            background: var(--page-bg);
        }
    </style>
</head>
<body class="login-page">

<div class="login-card">
    <div class="login-app-name"><?= htmlspecialchars(bgi_app_name()) ?></div>
    <div class="login-page-title">Member Sign In</div>

    <?php if ($error !== ''): ?>
        <div class="login-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
        <div class="login-field">
            <label for="identifier">ITS ID</label>
            <input type="text" id="identifier" name="identifier"
                   value="<?= htmlspecialchars($identifier) ?>"
                   placeholder="8-digit ITS ID"
                   inputmode="numeric" maxlength="8" pattern="\d{8}"
                   required autofocus>
        </div>

        <div class="login-field">
            <label for="secret">Phone Number</label>
            <input type="text" id="secret" name="secret"
                   placeholder="Registered phone number"
                   inputmode="numeric" required>
        </div>

        <button type="submit" class="login-submit">Sign In</button>
    </form>

    <div class="login-divider">
        Staff or Admin? <a href="adminlogin.php">Admin Login &rarr;</a>
    </div>
</div>

</body>
</html>
