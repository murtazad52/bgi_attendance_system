<?php
require_once __DIR__ . '/auth.php';

if (PHP_SAPI === 'cli') {
    if ($argc < 3) {
        fwrite(STDERR, "Usage: php create_admin.php <username> <password> [role] [idara] [mohalla]" . PHP_EOL);
        exit(1);
    }

    include 'db.php';

    $username = trim($argv[1]);
    $password = $argv[2];
    $role = $argc >= 4 ? bgi_normalize_admin_role($argv[3]) : BGI_ROLE_IDARA_ADMIN;
    $idara = $argc >= 5 ? trim($argv[4]) : BGI_DEFAULT_IDARA;
    $mohalla = $argc >= 6 ? trim($argv[5]) : BGI_DEFAULT_MOHALLA;

    if ($username === 'admin') {
        $role = BGI_ROLE_SUPER_ADMIN;
        $idara = BGI_SUPER_ADMIN_IDARA;
        $mohalla = BGI_SUPER_ADMIN_MOHALLA;
    } elseif ($role === BGI_ROLE_MOHALLA_ADMIN) {
        $idara = BGI_SUPER_ADMIN_IDARA;
        $mohalla = $argc >= 5 ? trim($argv[4]) : '';
    }

    if ($username === '' || $password === '') {
        fwrite(STDERR, "Username and password are required." . PHP_EOL);
        exit(1);
    }

    if ($role !== BGI_ROLE_SUPER_ADMIN && $role !== BGI_ROLE_MOHALLA_ADMIN && !bgi_register_scope($conn, $idara, $mohalla, $scopeError)) {
        fwrite(STDERR, ($scopeError ?: 'Invalid Idara and Mohalla mapping.') . PHP_EOL);
        exit(1);
    }

    $idara = bgi_normalize_scope_value($idara, $role === BGI_ROLE_MOHALLA_ADMIN ? BGI_SUPER_ADMIN_IDARA : BGI_DEFAULT_IDARA);
    $mohalla = bgi_normalize_scope_value($mohalla, $role === BGI_ROLE_MOHALLA_ADMIN ? '' : BGI_DEFAULT_MOHALLA);

    if ($role === BGI_ROLE_MOHALLA_ADMIN && $mohalla === '') {
        fwrite(STDERR, "Mohalla Admin requires a Mohalla." . PHP_EOL);
        exit(1);
    }

    $checkStmt = $conn->prepare("SELECT id FROM admin_users WHERE username = ? LIMIT 1");
    $checkStmt->bind_param("s", $username);
    $checkStmt->execute();
    $existingUser = $checkStmt->get_result();

    if ($existingUser->num_rows > 0) {
        fwrite(STDERR, "Admin user already exists for username: $username" . PHP_EOL);
        $checkStmt->close();
        $conn->close();
        exit(1);
    }

    $checkStmt->close();

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("INSERT INTO admin_users (username, password, role, idara, mohalla) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssss", $username, $hashedPassword, $role, $idara, $mohalla);

    if ($stmt->execute()) {
        fwrite(STDOUT, "Admin user created successfully for $idara / $mohalla with role: $role" . PHP_EOL);
    } else {
        fwrite(STDERR, "Error: " . $stmt->error . PHP_EOL);
        $stmt->close();
        $conn->close();
        exit(1);
    }

    $stmt->close();
    $conn->close();
    exit(0);
}

bgi_require_roles([BGI_ROLE_SUPER_ADMIN]);

include 'db.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';
$username = '';
$selectedRole = BGI_ROLE_IDARA_ADMIN;
$selectedIdara = BGI_DEFAULT_IDARA;
$selectedMohalla = BGI_DEFAULT_MOHALLA;
$scopeOptions = bgi_get_scope_options($conn);
$scopeMap = [];
$mohallaOptions = [];
$selectedScopeValue = '';
$selectedMohallaValue = BGI_DEFAULT_MOHALLA;

foreach ($scopeOptions as $scopeOption) {
    $scopeValue = ($scopeOption['idara'] ?? '') . '||' . ($scopeOption['mohalla'] ?? '');
    $scopeMap[$scopeValue] = [
        'idara' => bgi_normalize_scope_value($scopeOption['idara'] ?? '', BGI_DEFAULT_IDARA),
        'mohalla' => bgi_normalize_scope_value($scopeOption['mohalla'] ?? '', BGI_DEFAULT_MOHALLA),
    ];
    $mohallaOptions[bgi_normalize_scope_value($scopeOption['mohalla'] ?? '', BGI_DEFAULT_MOHALLA)] = bgi_normalize_scope_value($scopeOption['mohalla'] ?? '', BGI_DEFAULT_MOHALLA);
}

ksort($mohallaOptions);

if ($scopeMap !== []) {
    $selectedScopeValue = BGI_DEFAULT_IDARA . '||' . BGI_DEFAULT_MOHALLA;
}

if (!isset($scopeMap[$selectedScopeValue]) && $scopeMap !== []) {
    $selectedScopeValue = (string) array_key_first($scopeMap);
    $selectedIdara = $scopeMap[$selectedScopeValue]['idara'];
    $selectedMohalla = $scopeMap[$selectedScopeValue]['mohalla'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
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
    } elseif ($username === '' || $password === '' || $confirmPassword === '') {
        $error = 'Username, password, and confirmation are required.';
    } elseif ($selectedRole === BGI_ROLE_SUPER_ADMIN) {
        $error = 'The main admin account remains the only super admin.';
    } elseif ($selectedRole === BGI_ROLE_MOHALLA_ADMIN && !isset($mohallaOptions[$selectedMohallaValue])) {
        $error = 'Please choose a valid saved Mohalla.';
    } elseif ($selectedRole !== BGI_ROLE_MOHALLA_ADMIN && !isset($scopeMap[$selectedScopeValue])) {
        $error = 'Please choose a valid saved Idara and Mohalla.';
    } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
        $error = 'Username must be 3 to 50 characters and use only letters, numbers, dots, dashes, or underscores.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Password confirmation does not match.';
    } else {
        $checkStmt = $conn->prepare("SELECT id FROM admin_users WHERE username = ? LIMIT 1");
        $checkStmt->bind_param("s", $username);
        $checkStmt->execute();
        $existingUser = $checkStmt->get_result();

        if ($existingUser->num_rows > 0) {
            $error = 'That username is already in use.';
        } else {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            if ($selectedRole === BGI_ROLE_MOHALLA_ADMIN) {
                $selectedIdara = BGI_SUPER_ADMIN_IDARA;
                $selectedMohalla = $selectedMohallaValue;
            }
            $stmt = $conn->prepare("INSERT INTO admin_users (username, password, role, idara, mohalla) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssss", $username, $hashedPassword, $selectedRole, $selectedIdara, $selectedMohalla);

            if ($stmt->execute()) {
                $success = bgi_role_label($selectedRole) . ' created successfully for ' . $selectedIdara . ' / ' . $selectedMohalla . '.';
                $username = '';
                $selectedRole = BGI_ROLE_IDARA_ADMIN;
                $scopeOptions = bgi_get_scope_options($conn);
                $scopeMap = [];
                $mohallaOptions = [];

                foreach ($scopeOptions as $scopeOption) {
                    $scopeValue = ($scopeOption['idara'] ?? '') . '||' . ($scopeOption['mohalla'] ?? '');
                    $scopeMap[$scopeValue] = [
                        'idara' => bgi_normalize_scope_value($scopeOption['idara'] ?? '', BGI_DEFAULT_IDARA),
                        'mohalla' => bgi_normalize_scope_value($scopeOption['mohalla'] ?? '', BGI_DEFAULT_MOHALLA),
                    ];
                    $mohallaOptions[bgi_normalize_scope_value($scopeOption['mohalla'] ?? '', BGI_DEFAULT_MOHALLA)] = bgi_normalize_scope_value($scopeOption['mohalla'] ?? '', BGI_DEFAULT_MOHALLA);
                }

                ksort($mohallaOptions);
                $selectedScopeValue = BGI_DEFAULT_IDARA . '||' . BGI_DEFAULT_MOHALLA;
                if (!isset($scopeMap[$selectedScopeValue]) && $scopeMap !== []) {
                    $selectedScopeValue = (string) array_key_first($scopeMap);
                }

                $selectedIdara = $scopeMap[$selectedScopeValue]['idara'] ?? BGI_DEFAULT_IDARA;
                $selectedMohalla = $scopeMap[$selectedScopeValue]['mohalla'] ?? BGI_DEFAULT_MOHALLA;
                $selectedMohallaValue = $selectedMohalla;
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            } else {
                $error = 'Error creating admin user: ' . $stmt->error;
            }

            $stmt->close();
        }

        $checkStmt->close();
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Admin - <?= htmlspecialchars(bgi_app_name()) ?></title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f4f6f8;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 620px;
            margin: 40px auto;
            background: #fff;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 0 12px rgba(0,0,0,0.1);
        }
        h2 {
            margin-top: 0;
            text-align: center;
            color: #2E8B57;
        }
        label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
        }
        input[type="text"],
        input[type="password"],
        select {
            width: 100%;
            padding: 10px;
            margin-bottom: 18px;
            border: 1px solid #ccc;
            border-radius: 5px;
            box-sizing: border-box;
        }
        .btn {
            display: inline-block;
            background: #2E8B57;
            color: white;
            padding: 10px 18px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            font-size: 15px;
        }
        .btn:hover {
            background: #246B46;
        }
        .back-link {
            margin-bottom: 20px;
        }
        .message {
            padding: 10px 12px;
            border-radius: 6px;
            margin-bottom: 16px;
        }
        .success {
            background: #e6f4ea;
            color: #0f5132;
            border: 1px solid #c7eed1;
        }
        .error {
            background: #fdecea;
            color: #842029;
            border: 1px solid #f5c2c7;
        }
        .help {
            color: #666;
            font-size: 14px;
            margin-top: -10px;
            margin-bottom: 18px;
        }
        .scope-preview {
            background: #f4f8f6;
            border: 1px solid #d6e7dd;
            border-radius: 6px;
            color: #264d39;
            margin-bottom: 18px;
            padding: 12px 14px;
        }
    </style>
    <link rel="stylesheet" href="app.css">
</head>
<body class="app-page page-form">
    <div class="container">
        <a href="dashboard.php" class="btn secondary back-link">Back to Dashboard</a>
        <h2>Create Admin User</h2>
        <p class="page-intro">Create scoped admin accounts by role. The main <strong>admin</strong> account remains the only super admin with global access.</p>

        <?php if ($success !== ''): ?>
            <div class="message success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="message error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

            <label for="username">Username</label>
            <input type="text" id="username" name="username" value="<?= htmlspecialchars($username) ?>" required>

            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>
            <div class="help">Use at least 8 characters.</div>

            <label for="confirm_password">Confirm Password</label>
            <input type="password" id="confirm_password" name="confirm_password" required>

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
                    <?php foreach ($scopeOptions as $scopeOption): ?>
                        <?php $scopeValue = $scopeOption['idara'] . '||' . $scopeOption['mohalla']; ?>
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
                Assigning <strong id="role-preview"><?= htmlspecialchars(bgi_role_label($selectedRole)) ?></strong> access to
                <strong id="scope-preview-idara"><?= htmlspecialchars($selectedIdara) ?></strong> /
                <strong id="scope-preview-mohalla"><?= htmlspecialchars($selectedMohalla) ?></strong>
            </div>

            <button type="submit" class="btn">Create Admin</button>
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
