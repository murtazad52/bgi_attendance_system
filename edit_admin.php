<?php
include('session_check.php');
include('db.php');

bgi_require_roles([BGI_ROLE_SUPER_ADMIN]);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$adminId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $adminId = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT);
}

if (!$adminId) {
    bgi_set_flash('Invalid admin user.', 'error');
    $conn->close();
    header('Location: manage_admins.php');
    exit;
}

$loadAdminStmt = $conn->prepare("SELECT id, username, role, idara, mohalla FROM admin_users WHERE id = ? LIMIT 1");
$loadAdminStmt->bind_param("i", $adminId);
$loadAdminStmt->execute();
$adminResult = $loadAdminStmt->get_result();
$adminUser = $adminResult ? $adminResult->fetch_assoc() : null;
$loadAdminStmt->close();

if (!$adminUser) {
    bgi_set_flash('Admin user not found.', 'error');
    $conn->close();
    header('Location: manage_admins.php');
    exit;
}

$normalizedExistingRole = bgi_normalize_admin_role($adminUser['role'] ?? BGI_ROLE_IDARA_ADMIN);
$isProtectedAdmin = (($adminUser['username'] ?? '') === 'admin') || $normalizedExistingRole === BGI_ROLE_SUPER_ADMIN;
if ($isProtectedAdmin) {
    bgi_set_flash('The main super admin account cannot be edited here.', 'error');
    $conn->close();
    header('Location: manage_admins.php');
    exit;
}

$scopeOptions = bgi_get_scope_options($conn);
$scopeMap = [];
$mohallaOptions = [];

foreach ($scopeOptions as $scopeOption) {
    $idara = bgi_normalize_scope_value($scopeOption['idara'] ?? '', BGI_DEFAULT_IDARA);
    $mohalla = bgi_normalize_scope_value($scopeOption['mohalla'] ?? '', BGI_DEFAULT_MOHALLA);
    $scopeMap[$idara . '||' . $mohalla] = [
        'idara' => $idara,
        'mohalla' => $mohalla,
    ];
    $mohallaOptions[$mohalla] = $mohalla;
}

$username = trim((string) ($adminUser['username'] ?? ''));
$selectedRole = $normalizedExistingRole;
$selectedIdara = $selectedRole === BGI_ROLE_MOHALLA_ADMIN
    ? BGI_SUPER_ADMIN_IDARA
    : bgi_normalize_scope_value($adminUser['idara'] ?? '', BGI_DEFAULT_IDARA);
$selectedMohalla = bgi_normalize_scope_value($adminUser['mohalla'] ?? '', BGI_DEFAULT_MOHALLA);
$selectedScopeValue = $selectedRole === BGI_ROLE_MOHALLA_ADMIN ? '' : ($selectedIdara . '||' . $selectedMohalla);
$selectedMohallaValue = $selectedMohalla;
$error = '';

if ($selectedScopeValue !== '' && !isset($scopeMap[$selectedScopeValue])) {
    $scopeMap[$selectedScopeValue] = [
        'idara' => $selectedIdara,
        'mohalla' => $selectedMohalla,
    ];
}
if ($selectedMohallaValue !== '') {
    $mohallaOptions[$selectedMohallaValue] = $selectedMohallaValue;
}

ksort($mohallaOptions);
ksort($scopeMap);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $username = trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
    $selectedRole = bgi_normalize_admin_role($_POST['role'] ?? BGI_ROLE_IDARA_ADMIN);
    $selectedScopeValue = trim($_POST['saved_scope'] ?? '');
    $selectedMohallaValue = bgi_normalize_scope_value($_POST['mohalla_scope'] ?? '', '');

    if ($selectedRole === BGI_ROLE_MOHALLA_ADMIN) {
        $selectedIdara = BGI_SUPER_ADMIN_IDARA;
        $selectedMohalla = $selectedMohallaValue;
    } elseif (isset($scopeMap[$selectedScopeValue])) {
        $selectedIdara = $scopeMap[$selectedScopeValue]['idara'];
        $selectedMohalla = $scopeMap[$selectedScopeValue]['mohalla'];
    }

    if (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        $error = 'Invalid request token. Please refresh the page and try again.';
    } elseif ($username === '') {
        $error = 'Username is required.';
    } elseif ($username === 'admin') {
        $error = 'The username admin is reserved for the main super admin account.';
    } elseif ($selectedRole === BGI_ROLE_SUPER_ADMIN) {
        $error = 'The main admin account remains the only super admin.';
    } elseif ($selectedRole === BGI_ROLE_MOHALLA_ADMIN && !isset($mohallaOptions[$selectedMohallaValue])) {
        $error = 'Please choose a valid saved Mohalla.';
    } elseif ($selectedRole !== BGI_ROLE_MOHALLA_ADMIN && !isset($scopeMap[$selectedScopeValue])) {
        $error = 'Please choose a valid saved Idara and Mohalla.';
    } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
        $error = 'Username must be 3 to 50 characters and use only letters, numbers, dots, dashes, or underscores.';
    } elseif (($password !== '' || $confirmPassword !== '') && strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long when you choose to change it.';
    } elseif (($password !== '' || $confirmPassword !== '') && $password !== $confirmPassword) {
        $error = 'Password confirmation does not match.';
    } else {
        $checkStmt = $conn->prepare("SELECT id FROM admin_users WHERE username = ? AND id <> ? LIMIT 1");
        $checkStmt->bind_param("si", $username, $adminId);
        $checkStmt->execute();
        $duplicateResult = $checkStmt->get_result();

        if ($duplicateResult && $duplicateResult->num_rows > 0) {
            $error = 'That username is already in use.';
        } else {
            if ($selectedRole === BGI_ROLE_MOHALLA_ADMIN) {
                $selectedIdara = BGI_SUPER_ADMIN_IDARA;
                $selectedMohalla = $selectedMohallaValue;
            }

            if ($password !== '') {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $updateStmt = $conn->prepare("UPDATE admin_users SET username = ?, password = ?, role = ?, idara = ?, mohalla = ? WHERE id = ?");
                $updateStmt->bind_param("sssssi", $username, $hashedPassword, $selectedRole, $selectedIdara, $selectedMohalla, $adminId);
            } else {
                $updateStmt = $conn->prepare("UPDATE admin_users SET username = ?, role = ?, idara = ?, mohalla = ? WHERE id = ?");
                $updateStmt->bind_param("ssssi", $username, $selectedRole, $selectedIdara, $selectedMohalla, $adminId);
            }

            if ($updateStmt->execute()) {
                $updateStmt->close();
                $checkStmt->close();
                bgi_set_flash('Admin user updated successfully.', 'success');
                $conn->close();
                header('Location: manage_admins.php');
                exit;
            }

            $error = 'Error updating admin user: ' . $updateStmt->error;
            $updateStmt->close();
        }

        $checkStmt->close();
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Admin - <?= htmlspecialchars(bgi_app_name()) ?></title>
    <link rel="stylesheet" href="app.css">
</head>
<body class="app-page page-form">
    <div class="container">
        <a href="manage_admins.php" class="btn secondary back-link">Back to Manage Admins</a>
        <h2>Edit Admin User</h2>
        <p class="page-intro">Update admin username, role, scope, and optionally reset the password. Leave the password fields blank to keep the current password unchanged.</p>

        <?php if ($error !== ''): ?>
            <div class="message error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="id" value="<?= (int) $adminId ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

            <label for="username">Username</label>
            <input type="text" id="username" name="username" value="<?= htmlspecialchars($username) ?>" required>

            <label for="password">New Password</label>
            <input type="password" id="password" name="password">
            <div class="help">Optional. Use at least 8 characters if you want to change the password.</div>

            <label for="confirm_password">Confirm New Password</label>
            <input type="password" id="confirm_password" name="confirm_password">

            <label for="role">Role</label>
            <select id="role" name="role" required>
                <option value="<?= htmlspecialchars(BGI_ROLE_IDARA_ADMIN) ?>" <?= $selectedRole === BGI_ROLE_IDARA_ADMIN ? 'selected' : '' ?>><?= htmlspecialchars(bgi_role_label(BGI_ROLE_IDARA_ADMIN)) ?></option>
                <option value="<?= htmlspecialchars(BGI_ROLE_IDARA_ATTENDANCE_ADMIN) ?>" <?= $selectedRole === BGI_ROLE_IDARA_ATTENDANCE_ADMIN ? 'selected' : '' ?>><?= htmlspecialchars(bgi_role_label(BGI_ROLE_IDARA_ATTENDANCE_ADMIN)) ?></option>
                <option value="<?= htmlspecialchars(BGI_ROLE_MOHALLA_ADMIN) ?>" <?= $selectedRole === BGI_ROLE_MOHALLA_ADMIN ? 'selected' : '' ?>><?= htmlspecialchars(bgi_role_label(BGI_ROLE_MOHALLA_ADMIN)) ?></option>
            </select>
            <div class="help">Idara roles stay inside one saved Idara and Mohalla. Mohalla Admin can manage all Idaras inside one saved Mohalla.</div>

            <div id="scope-select-wrap">
                <label for="saved_scope">Saved Idara / Mohalla</label>
                <select id="saved_scope" name="saved_scope" class="scope-select">
                    <option value="">Choose from saved scopes</option>
                    <?php foreach ($scopeMap as $scopeValue => $scopeOption): ?>
                        <option value="<?= htmlspecialchars($scopeValue) ?>" <?= $selectedScopeValue === $scopeValue ? 'selected' : '' ?>>
                            <?= htmlspecialchars($scopeOption['idara'] . ' - ' . $scopeOption['mohalla']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="help">Choose one saved database scope for Idara Admin or Idara Attendance Admin.</div>
            </div>

            <div id="mohalla-select-wrap">
                <label for="mohalla_scope">Saved Mohalla</label>
                <select id="mohalla_scope" name="mohalla_scope">
                    <option value="">Choose from saved Mohallas</option>
                    <?php foreach ($mohallaOptions as $mohallaOption): ?>
                        <option value="<?= htmlspecialchars($mohallaOption) ?>" <?= $selectedMohallaValue === $mohallaOption ? 'selected' : '' ?>>
                            <?= htmlspecialchars($mohallaOption) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="help">Mohalla Admin automatically gets <strong>All Idara</strong> inside the selected Mohalla.</div>
            </div>

            <div class="scope-preview">
                Updating <strong id="role-preview"><?= htmlspecialchars(bgi_role_label($selectedRole)) ?></strong> access to
                <strong id="scope-preview-idara"><?= htmlspecialchars($selectedIdara) ?></strong> /
                <strong id="scope-preview-mohalla"><?= htmlspecialchars($selectedMohalla) ?></strong>
            </div>

            <div class="button-row">
                <button type="submit" class="btn">Save Changes</button>
                <a href="manage_admins.php" class="btn secondary">Cancel</a>
            </div>
        </form>
    </div>
    <script>
        const roleSelect = document.getElementById('role');
        const savedScopeSelect = document.getElementById('saved_scope');
        const mohallaScopeSelect = document.getElementById('mohalla_scope');
        const scopeSelectWrap = document.getElementById('scope-select-wrap');
        const mohallaSelectWrap = document.getElementById('mohalla-select-wrap');
        const rolePreview = document.getElementById('role-preview');
        const scopePreviewIdara = document.getElementById('scope-preview-idara');
        const scopePreviewMohalla = document.getElementById('scope-preview-mohalla');

        function updateRoleUi() {
            const isMohallaAdmin = roleSelect && roleSelect.value === '<?= htmlspecialchars(BGI_ROLE_MOHALLA_ADMIN) ?>';
            if (scopeSelectWrap) {
                scopeSelectWrap.style.display = isMohallaAdmin ? 'none' : 'block';
            }
            if (mohallaSelectWrap) {
                mohallaSelectWrap.style.display = isMohallaAdmin ? 'block' : 'none';
            }
            if (rolePreview && roleSelect) {
                const selectedLabel = roleSelect.options[roleSelect.selectedIndex] ? roleSelect.options[roleSelect.selectedIndex].text : '';
                rolePreview.textContent = selectedLabel;
            }
        }

        function updateScopePreview() {
            if (!scopePreviewIdara || !scopePreviewMohalla || !roleSelect) {
                return;
            }

            if (roleSelect.value === '<?= htmlspecialchars(BGI_ROLE_MOHALLA_ADMIN) ?>') {
                scopePreviewIdara.textContent = '<?= htmlspecialchars(BGI_SUPER_ADMIN_IDARA) ?>';
                scopePreviewMohalla.textContent = mohallaScopeSelect && mohallaScopeSelect.value ? mohallaScopeSelect.value : '';
                return;
            }

            if (savedScopeSelect && savedScopeSelect.value) {
                const parts = savedScopeSelect.value.split('||');
                scopePreviewIdara.textContent = parts[0] || '';
                scopePreviewMohalla.textContent = parts[1] || '';
            }
        }

        if (roleSelect) {
            roleSelect.addEventListener('change', function () {
                updateRoleUi();
                updateScopePreview();
            });
        }

        if (savedScopeSelect) {
            savedScopeSelect.addEventListener('change', updateScopePreview);
        }

        if (mohallaScopeSelect) {
            mohallaScopeSelect.addEventListener('change', updateScopePreview);
        }

        updateRoleUi();
        updateScopePreview();
    </script>
</body>
</html>
