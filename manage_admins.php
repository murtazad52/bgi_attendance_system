<?php
include('session_check.php');
include('db.php');

bgi_require_roles([BGI_ROLE_SUPER_ADMIN, BGI_ROLE_IDARA_ADMIN, BGI_ROLE_MOHALLA_ADMIN]);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$flashMessage = $_SESSION['flash_message'] ?? '';
$flashType = $_SESSION['flash_type'] ?? 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

$isSuperAdmin = bgi_is_super_admin();
$scopeLabel = bgi_current_scope_label();
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$adminRows = [];

$orderBy = "ORDER BY CASE WHEN role = 'super_admin' THEN 0 ELSE 1 END, username ASC";

if ($isSuperAdmin) {
    $result = mysqli_query($conn, "SELECT id, username, role, idara, mohalla FROM admin_users $orderBy");
} else {
    if (bgi_is_mohalla_admin()) {
        $scopeMohalla = bgi_current_scope_mohalla();
        $adminStmt = $conn->prepare("SELECT id, username, role, idara, mohalla FROM admin_users WHERE mohalla = ? $orderBy");
        $adminStmt->bind_param("s", $scopeMohalla);
    } else {
        $scopeIdara = bgi_current_scope_idara();
        $scopeMohalla = bgi_current_scope_mohalla();
        $adminStmt = $conn->prepare("SELECT id, username, role, idara, mohalla FROM admin_users WHERE idara = ? AND mohalla = ? $orderBy");
        $adminStmt->bind_param("ss", $scopeIdara, $scopeMohalla);
    }
    $adminStmt->execute();
    $result = $adminStmt->get_result();
}

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $adminRows[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Admins - <?= htmlspecialchars(bgi_app_name()) ?></title>
    <style>
        .role-chip,
        .access-chip {
            display: inline-flex;
            align-items: center;
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 0.84rem;
            font-weight: 800;
            letter-spacing: 0.02em;
        }

        .role-super {
            background: #efe7ff;
            color: #6b21a8;
        }

        .role-admin {
            background: #e6f6ec;
            color: #0f5132;
        }

        .role-mohalla {
            background: #eef4ff;
            color: #1d4ed8;
        }

        .role-attendance {
            background: #fff3df;
            color: #b45309;
        }

        .access-chip {
            background: #edf7f1;
            color: #176b53;
        }

        .protected-note {
            color: #607168;
            font-size: 0.86rem;
            font-weight: 700;
        }
    </style>
    <link rel="stylesheet" href="app.css">
</head>
<body class="app-page page-table">

<div class="navbar">
    <h1><?= htmlspecialchars(bgi_app_name()) ?></h1>
    <a href="logout.php" class="logout">Logout</a>
</div>

<div class="container">
    <div class="action-row">
        <a href="dashboard.php" class="btn secondary">Back to Dashboard</a>
        <?php if ($isSuperAdmin): ?>
            <a href="create_admin.php" class="btn">Create Admin</a>
        <?php endif; ?>
    </div>

    <div class="header">
        <h2>Manage Admins</h2>
        <p class="page-intro">
            <?= $isSuperAdmin
                ? 'Review every admin account, watch scope assignments, and remove old admin access when it is no longer needed.'
                : 'You can review only the admin accounts assigned to your scope: ' . htmlspecialchars($scopeLabel) . '. The main admin account still controls creation and deletion.' ?>
        </p>
    </div>

    <?php if ($flashMessage !== ''): ?>
        <div class="flash-message <?= $flashType === 'error' ? 'error' : 'success' ?>">
            <?= htmlspecialchars($flashMessage) ?>
        </div>
    <?php endif; ?>

    <?php if (!$isSuperAdmin): ?>
        <div class="flash-message">
            You are viewing a scope-limited admin directory for <?= htmlspecialchars($scopeLabel) ?>. Only the main admin account can create or delete admin users.
        </div>
    <?php endif; ?>

    <div class="summary">
        <div class="summary-card">
            <span class="summary-label">Visible Admins</span>
            <strong><?= count($adminRows) ?></strong>
            <p><?= $isSuperAdmin ? 'All active admin accounts across every scope.' : 'Admin accounts inside your visible staff scope only.' ?></p>
        </div>
        <div class="summary-card">
            <span class="summary-label">Your Access</span>
            <strong><?= $isSuperAdmin ? 'Super Admin' : htmlspecialchars(bgi_role_label(bgi_current_user_role())) ?></strong>
            <p><?= $isSuperAdmin ? 'Full control over admin access and scope cleanup.' : 'Visibility follows your assigned Idara or Mohalla scope, while create and delete remain reserved for the main admin account.' ?></p>
        </div>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Username</th>
                    <th>Role</th>
                    <th>Idara</th>
                    <th>Mohalla</th>
                    <th>Access</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($adminRows !== []): ?>
                    <?php foreach ($adminRows as $row): ?>
                        <?php
                        $isProtectedAdmin = (($row['username'] ?? '') === 'admin') || (($row['role'] ?? '') === BGI_ROLE_SUPER_ADMIN);
                        $isCurrentSession = (int) ($row['id'] ?? 0) === $currentUserId;
                        $normalizedRole = bgi_normalize_admin_role($row['role'] ?? '');
                        $isSuperAdminRow = $normalizedRole === BGI_ROLE_SUPER_ADMIN;
                        $displayIdara = $isSuperAdminRow ? BGI_SUPER_ADMIN_IDARA : (string) ($row['idara'] ?? BGI_DEFAULT_IDARA);
                        $displayMohalla = $isSuperAdminRow ? BGI_SUPER_ADMIN_MOHALLA : (string) ($row['mohalla'] ?? BGI_DEFAULT_MOHALLA);
                        $accessLabel = $isSuperAdminRow ? 'Global Access' : ($displayIdara . ' / ' . $displayMohalla);
                        $roleClass = 'role-admin';
                        if ($normalizedRole === BGI_ROLE_SUPER_ADMIN) {
                            $roleClass = 'role-super';
                        } elseif ($normalizedRole === BGI_ROLE_MOHALLA_ADMIN) {
                            $roleClass = 'role-mohalla';
                        } elseif ($normalizedRole === BGI_ROLE_IDARA_ATTENDANCE_ADMIN) {
                            $roleClass = 'role-attendance';
                        }
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($row['username'] ?? '') ?></td>
                            <td>
                                <span class="role-chip <?= htmlspecialchars($roleClass) ?>">
                                    <?= htmlspecialchars(bgi_role_label($normalizedRole)) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($displayIdara) ?></td>
                            <td><?= htmlspecialchars($displayMohalla) ?></td>
                            <td><span class="access-chip"><?= htmlspecialchars($accessLabel) ?></span></td>
                            <td>
                                <div class="actions">
                                    <?php if ($isSuperAdmin && !$isProtectedAdmin): ?>
                                        <a href="edit_admin.php?id=<?= (int) ($row['id'] ?? 0) ?>" class="link-button">Edit</a>
                                        <?php if (!$isCurrentSession): ?>
                                            <form method="POST" action="delete_admin.php" onsubmit="return confirm('Delete this admin user?');">
                                                <input type="hidden" name="id" value="<?= (int) ($row['id'] ?? 0) ?>">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                <button type="submit" class="link-button">Delete</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="protected-note">Current session</span>
                                        <?php endif; ?>
                                    <?php elseif ($isCurrentSession): ?>
                                        <span class="protected-note">Current session</span>
                                    <?php elseif ($isProtectedAdmin): ?>
                                        <span class="protected-note">Protected account</span>
                                    <?php else: ?>
                                        <span class="protected-note">View only</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6">No admin users found for this scope.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>
<?php
if (isset($adminStmt) && $adminStmt instanceof mysqli_stmt) {
    $adminStmt->close();
}

$conn->close();
?>
