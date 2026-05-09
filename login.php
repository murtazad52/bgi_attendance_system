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
</head>
<body class="login-page">

<div class="login-container">
    <div class="login-brand"><?= htmlspecialchars(bgi_app_name()) ?></div>
    <h2>Member Login</h2>
    <p class="login-subtitle">Sign in with your ITS ID and registered phone number.</p>

    <?php if ($error !== ''): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST">
        <label for="identifier">ITS ID</label>
        <input type="text" id="identifier" name="identifier"
               value="<?= htmlspecialchars($identifier) ?>"
               placeholder="Enter your 8-digit ITS ID"
               inputmode="numeric" maxlength="8" pattern="\d{8}" required autofocus>

        <label for="secret">Phone Number</label>
        <input type="text" id="secret" name="secret"
               placeholder="Enter your registered phone number"
               inputmode="numeric" required>

        <input type="submit" value="Login">
    </form>

    <p style="text-align:center;margin-top:20px;font-size:0.9rem;color:#666;">
        Staff / Admin? <a href="adminlogin.php">Admin Login →</a>
    </p>
</div>

</body>
</html>
