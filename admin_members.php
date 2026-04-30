<?php
include('session_check.php');
include('db.php');

if (!bgi_can_manage_members()) {
    header('Location: ' . bgi_home_path_for_current_user());
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$canDelete = bgi_can_delete();

$flashMessage = $_SESSION['flash_message'] ?? '';
$flashType = $_SESSION['flash_type'] ?? 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

$isScopedAdmin = !bgi_is_super_admin();
$scopeLabel = bgi_current_scope_label();
$membersSelect = "
    SELECT
        m.*,
        tl.member_name AS team_leader_name,
        cap.member_name AS captain_name
    FROM members m
    LEFT JOIN members tl ON m.team_leader_its_id = tl.its_id
    LEFT JOIN members cap ON COALESCE(m.captain_its_id, tl.captain_its_id) = cap.its_id
";

if (bgi_is_mohalla_admin()) {
    $memberStmt = $conn->prepare($membersSelect . " WHERE m.mohalla = ? ORDER BY m.created_at DESC");
    $scopeMohalla = bgi_current_scope_mohalla();
    $memberStmt->bind_param("s", $scopeMohalla);
    $memberStmt->execute();
    $result = $memberStmt->get_result();
} elseif ($isScopedAdmin) {
    $memberStmt = $conn->prepare($membersSelect . " WHERE m.idara = ? AND m.mohalla = ? ORDER BY m.created_at DESC");
    $scopeIdara = bgi_current_scope_idara();
    $scopeMohalla = bgi_current_scope_mohalla();
    $memberStmt->bind_param("ss", $scopeIdara, $scopeMohalla);
    $memberStmt->execute();
    $result = $memberStmt->get_result();
} else {
    $result = mysqli_query($conn, $membersSelect . " ORDER BY m.created_at DESC");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Members - <?= htmlspecialchars(bgi_app_name()) ?></title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f6f8; margin: 0; padding: 0; }
        .navbar { background: #2E8B57; padding: 15px 30px; display: flex; justify-content: space-between; color: white; }
        .navbar h1 { font-size: 24px; }
        .navbar a.logout { background: #ff4d4d; padding: 8px 15px; border-radius: 5px; color: white; text-decoration: none; }
        .container { max-width: 1100px; margin: 40px auto; background: white; padding: 20px; border-radius: 10px; }
        .header { text-align: center; margin-bottom: 30px; }
        .header-actions { display: flex; justify-content: center; flex-wrap: wrap; gap: 12px; margin-top: 16px; }
        .btn { display: inline-block; background: #2E8B57; color: white; padding: 10px 20px; margin-bottom: 20px; text-decoration: none; border-radius: 5px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table th, table td { border: 1px solid #ccc; padding: 10px; text-align: left; }
        table th { background: #2E8B57; color: white; }
        table tr:nth-child(even) { background: #f9f9f9; }
        .flash-message { padding: 12px 14px; border-radius: 6px; margin-bottom: 20px; }
        .flash-message.success { background: #e6f4ea; color: #0f5132; border: 1px solid #c7eed1; }
        .flash-message.error { background: #fdecea; color: #842029; border: 1px solid #f5c2c7; }
        .actions { display: flex; align-items: center; gap: 8px; }
        .actions form { display: inline; margin: 0; }
        .link-button {
            background: none;
            border: none;
            padding: 0;
            color: #0d6efd;
            cursor: pointer;
            font: inherit;
            text-decoration: underline;
        }
        .link-button:hover { color: #0a58ca; }
    </style>
    <link rel="stylesheet" href="app.css">
</head>
<body class="app-page page-table">

<div class="navbar">
    <h1><?= htmlspecialchars(bgi_app_name()) ?></h1>
    <a href="logout.php" class="logout">Logout</a>
</div>

<div class="container">
    <a href="dashboard.php" class="btn">Back to Dashboard</a>
    <div class="header">
        <h2>Manage Members</h2>
        <p class="page-intro">
            <?= $isScopedAdmin
                ? 'Review, add, and update member records assigned to your scope: ' . htmlspecialchars($scopeLabel) . '.'
                : 'Review, add, update, or remove member records across all Idara and Mohalla scopes, with ITS ID used as the main member reference.' ?>
        </p>
        <div class="header-actions">
            <a href="add_member.php" class="btn">Add New Member</a>
            <a href="add_member.php#member-import" class="btn secondary">Import Members</a>
            <a href="export_report.php?type=member" class="btn secondary">Export Members</a>
        </div>
    </div>

    <?php if ($flashMessage !== ''): ?>
        <div class="flash-message <?= $flashType === 'error' ? 'error' : 'success' ?>">
            <?= htmlspecialchars($flashMessage) ?>
        </div>
    <?php endif; ?>

    <?php if (!$canDelete): ?>
        <div class="flash-message">
            Delete actions are reserved for the main admin account. Your current member scope is <?= htmlspecialchars($scopeLabel) ?>.
        </div>
    <?php endif; ?>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>ITS ID</th>
                    <th>BGI ID</th>
                    <th>Idara</th>
                    <th>Mohalla</th>
                    <th>Member Name</th>
                    <th>Position</th>
                    <th>Team Leader</th>
                    <th>Captain</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if ($result && mysqli_num_rows($result) > 0) {
                    while ($row = mysqli_fetch_assoc($result)) {
                        echo "<tr>";
                        echo "<td>".htmlspecialchars($row['its_id'])."</td>";
                        echo "<td>".htmlspecialchars($row['bgi_id'])."</td>";
                        echo "<td>".htmlspecialchars($row['idara'])."</td>";
                        echo "<td>".htmlspecialchars($row['mohalla'])."</td>";
                        echo "<td>".htmlspecialchars($row['member_name'])."</td>";
                        echo "<td>".htmlspecialchars(bgi_member_position_label($row['position'] ?? BGI_POSITION_MEMBER))."</td>";
                        echo "<td>".htmlspecialchars($row['team_leader_name'] ?? '')."</td>";
                        echo "<td>".htmlspecialchars($row['captain_name'] ?? '')."</td>";
                        echo "<td>".htmlspecialchars($row['email'])."</td>";
                        echo "<td>".htmlspecialchars($row['phone'])."</td>";
                        echo '<td><div class="actions"><a href="edit_member.php?id='.intval($row['id']).'">Edit</a>';
                        if ($canDelete) {
                            echo '<form method="POST" action="delete_member.php" onsubmit="return confirm(\'Are you sure you want to delete this member?\')">
                                        <input type="hidden" name="id" value="'.intval($row['id']).'">
                                        <input type="hidden" name="csrf_token" value="'.htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8').'">
                                        <button type="submit" class="link-button">Delete</button>
                                    </form>';
                        }
                        echo '</div></td>';
                        echo "</tr>";
                    }
                } else {
                    echo '<tr><td colspan="11">No members found.</td></tr>';
                }
                ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>

<?php
if (isset($memberStmt) && $memberStmt instanceof mysqli_stmt) {
    $memberStmt->close();
}
mysqli_close($conn);
?>
