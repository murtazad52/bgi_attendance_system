<?php
require_once __DIR__ . '/auth.php';
include 'db.php';

bgi_ensure_admin_role_schema($conn);

if (bgi_is_logged_in()) {
    header('Location: ' . bgi_home_path_for_current_user());
    exit;
}

$loginType = $_POST['login_type'] ?? 'admin';
$identifier = trim($_POST['identifier'] ?? '');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $secret = trim($_POST['secret'] ?? '');

    if (!in_array($loginType, ['admin', 'member'], true)) {
        $loginType = 'admin';
    }

    if ($identifier === '' || $secret === '') {
        $error = 'Please complete all required fields.';
    } elseif ($loginType === 'member') {
        if (!preg_match('/^\d{8}$/', $identifier)) {
            $error = 'Members must sign in with an 8-digit ITS ID.';
        } else {
            $memberStmt = $conn->prepare("SELECT id, its_id, member_name, phone, idara, mohalla, position FROM members WHERE its_id = ? LIMIT 1");
            $memberStmt->bind_param("s", $identifier);
            $memberStmt->execute();
            $memberResult = $memberStmt->get_result();

            if ($memberResult->num_rows === 1) {
                $member = $memberResult->fetch_assoc();

                if (hash_equals((string) $member['phone'], $secret)) {
                    bgi_clear_auth_session();
                    $_SESSION['member_logged_in'] = true;
                    $_SESSION['user_role'] = BGI_ROLE_MEMBER;
                    $_SESSION['member_id'] = (int) $member['id'];
                    $_SESSION['member_name'] = $member['member_name'];
                    $_SESSION['member_its_id'] = $member['its_id'];
                    $_SESSION['member_position'] = bgi_normalize_member_position($member['position'] ?? BGI_POSITION_MEMBER);
                    $_SESSION['username'] = $member['member_name'];
                    bgi_set_scope_session($member['idara'] ?? BGI_DEFAULT_IDARA, $member['mohalla'] ?? BGI_DEFAULT_MOHALLA);

                    $memberStmt->close();
                    $conn->close();
                    header('Location: report_members.php');
                    exit;
                }
            }

            $error = 'Invalid ITS ID or phone number.';
            $memberStmt->close();
        }
    } else {
        $adminStmt = $conn->prepare("SELECT id, username, password, role, idara, mohalla FROM admin_users WHERE username = ? LIMIT 1");
        $adminStmt->bind_param("s", $identifier);
        $adminStmt->execute();
        $adminResult = $adminStmt->get_result();

        if ($adminResult->num_rows === 1) {
            $user = $adminResult->fetch_assoc();

            if (password_verify($secret, $user['password'])) {
                bgi_clear_auth_session();
                $_SESSION['user_id'] = (int) $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['user_role'] = bgi_normalize_admin_role($user['role'] ?? BGI_ROLE_ADMIN);
                bgi_set_scope_session($user['idara'] ?? BGI_DEFAULT_IDARA, $user['mohalla'] ?? BGI_DEFAULT_MOHALLA);

                $adminStmt->close();
                $conn->close();
                header('Location: dashboard.php');
                exit;
            }
        }

        $error = 'Invalid username or password.';
        $adminStmt->close();
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?= htmlspecialchars(bgi_app_name()) ?></title>
    <link rel="stylesheet" href="app.css">
</head>
<body class="login-page">

<div class="login-container">
    <div class="login-brand"><?= htmlspecialchars(bgi_app_name()) ?></div>
    <h2>Portal Login</h2>
    <p class="login-subtitle">Super Admin, Idara Admin, Idara Attendance Admin, and Mohalla Admin sign in here with their assigned access scope, while members, Team Leaders, and Captains sign in through the member portal using ITS ID and phone.</p>

    <?php if ($error !== '') { echo '<div class="error">' . htmlspecialchars($error) . '</div>'; } ?>

    <form method="POST" action="">
        <label for="login_type">Login As</label>
        <select name="login_type" id="login_type" required>
            <option value="admin" <?= $loginType === 'admin' ? 'selected' : '' ?>>Super Admin / Admin</option>
            <option value="member" <?= $loginType === 'member' ? 'selected' : '' ?>>Member / Team Leader / Captain</option>
        </select>

        <label for="identifier" id="identifier_label"><?= $loginType === 'member' ? 'ITS ID' : 'Username' ?></label>
        <input type="text" id="identifier" name="identifier" value="<?= htmlspecialchars($identifier) ?>" placeholder="<?= $loginType === 'member' ? 'Enter your ITS ID' : 'Enter your username' ?>" required autofocus>

        <label for="secret" id="secret_label"><?= $loginType === 'member' ? 'Phone Number' : 'Password' ?></label>
        <input type="<?= $loginType === 'member' ? 'text' : 'password' ?>" id="secret" name="secret" placeholder="<?= $loginType === 'member' ? 'Enter your phone number' : 'Enter your password' ?>" required>

        <p class="login-subtitle" id="login_hint">
            <?= $loginType === 'member'
                ? 'Members sign in using their ITS ID and phone number. Team Leaders can view assigned team reports, Captains can view their scope reports, and Members can view only their own reports.'
                : 'Staff sign in using their assigned username and password. Super Admin has global access, Idara roles stay inside one Idara and Mohalla, and Mohalla Admin works across one Mohalla.' ?>
        </p>
        <input type="submit" value="Login">
    </form>
</div>

<script>
const loginType = document.getElementById('login_type');
const identifierLabel = document.getElementById('identifier_label');
const secretLabel = document.getElementById('secret_label');
const identifierInput = document.getElementById('identifier');
const secretInput = document.getElementById('secret');
const loginHint = document.getElementById('login_hint');

function updateLoginLabels() {
    const isMember = loginType.value === 'member';
    identifierLabel.textContent = isMember ? 'ITS ID' : 'Username';
    secretLabel.textContent = isMember ? 'Phone Number' : 'Password';
    identifierInput.placeholder = isMember ? 'Enter your ITS ID' : 'Enter your username';
    secretInput.placeholder = isMember ? 'Enter your phone number' : 'Enter your password';
    secretInput.type = isMember ? 'text' : 'password';
    loginHint.textContent = isMember
        ? 'Members sign in using their ITS ID and phone number. Team Leaders can view assigned team reports, Captains can view their scope reports, and Members can view only their own reports.'
        : 'Staff sign in using their assigned username and password, with access limited to their Idara and Mohalla unless they are the main admin.';
}

loginType.addEventListener('change', updateLoginLabels);
updateLoginLabels();
</script>

</body>
</html>
