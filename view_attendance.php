<?php
include('session_check.php');

include('db.php');

$canViewAttendanceRecords = bgi_can_view_attendance_records();
if (!$canViewAttendanceRecords) {
    header('Location: ' . bgi_home_path_for_current_user());
    exit;
}

$isSuperAdmin = bgi_is_super_admin();
$isScopedAdmin = !$isSuperAdmin;
$scopeLabel = bgi_current_scope_label();
$deleteMessage = null;

if ($isSuperAdmin && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['delete_id'])) {
    $deleteId = (int) $_POST['delete_id'];
    $stmt = $conn->prepare("DELETE FROM attendance WHERE id = ?");
    $stmt->bind_param('i', $deleteId);
    $stmt->execute();
    $deleted = $stmt->affected_rows;
    $stmt->close();
    $deleteMessage = $deleted > 0 ? 'success' : 'not_found';
}

// Fetch attendance records with ITS ID as the canonical member reference
$query = "SELECT attendance.id AS attendance_id,
                 attendance.attendance_time,
                 attendance.member_name AS attendance_member_name,
                 attendance.bgi_id AS attendance_bgi_id,
                 attendance.idara AS attendance_idara,
                 attendance.mohalla AS attendance_mohalla,
                 attendance.its_id AS attendance_its_id,
                 members.member_name AS current_member_name,
                 members.bgi_id AS current_bgi_id,
                 members.idara AS current_idara,
                 members.mohalla AS current_mohalla,
                 members.its_id AS current_its_id,
                 events.event_name,
                 events.event_code
          FROM attendance
          LEFT JOIN members ON attendance.its_id = members.its_id
          JOIN events ON attendance.event_id = events.id";

if ($isScopedAdmin) {
    if (bgi_is_mohalla_admin()) {
        $query .= " WHERE attendance.mohalla = ?";
    } else {
        $query .= " WHERE attendance.idara = ? AND attendance.mohalla = ?";
    }
}

$query .= " ORDER BY attendance.attendance_time DESC";

if ($isScopedAdmin) {
    $attendanceStmt = $conn->prepare($query);
    if (bgi_is_mohalla_admin()) {
        $scopeMohalla = bgi_current_scope_mohalla();
        $attendanceStmt->bind_param("s", $scopeMohalla);
    } else {
        $scopeIdara = bgi_current_scope_idara();
        $scopeMohalla = bgi_current_scope_mohalla();
        $attendanceStmt->bind_param("ss", $scopeIdara, $scopeMohalla);
    }
    $attendanceStmt->execute();
    $result = $attendanceStmt->get_result();
} else {
    $result = mysqli_query($conn, $query);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Attendance - <?= htmlspecialchars(bgi_app_name()) ?></title>
    <link rel="stylesheet" href="app.css">
</head>
<body class="app-page page-table">

<div class="topbar">
    <div><strong><?= htmlspecialchars(bgi_app_name()) ?></strong></div>
    <div>
        <a href="dashboard.php" class="back">← Dashboard</a>
        <a href="logout.php" class="logout" style="margin-left:8px;">Logout</a>
    </div>
</div>

<div class="container">
    <h2>Attendance Records</h2>
    <p class="page-intro">
        <?= $isScopedAdmin
            ? 'Browse the latest attendance entries only for your assigned scope: ' . htmlspecialchars($scopeLabel) . '.'
            : 'Browse the latest attendance entries with member, event, Idara, and Mohalla details in one place.' ?>
    </p>

    <?php if ($deleteMessage === 'success'): ?>
        <p class="message success">Attendance record deleted successfully.</p>
    <?php elseif ($deleteMessage === 'not_found'): ?>
        <p class="message error">Record not found or already deleted.</p>
    <?php endif; ?>

    <?php if (mysqli_num_rows($result) > 0): ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>ITS ID</th>
						<th>BGI ID</th>
                        <th>Idara</th>
                        <th>Mohalla</th>
                        <th>Event</th>
                        <th>Event Code</th>
                        <th>Attendance Date</th>
                        <?php if ($isSuperAdmin): ?><th></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = mysqli_fetch_assoc($result)): ?>
                        <tr>
						    <td><?php echo htmlspecialchars($row['current_member_name'] ?: $row['attendance_member_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['current_its_id'] ?: $row['attendance_its_id']); ?></td>
                            <td><?php echo htmlspecialchars($row['current_bgi_id'] ?: $row['attendance_bgi_id']); ?></td>
                            <td><?php echo htmlspecialchars(($row['current_idara'] ?: $row['attendance_idara']) ?: BGI_DEFAULT_IDARA); ?></td>
                            <td><?php echo htmlspecialchars(($row['current_mohalla'] ?: $row['attendance_mohalla']) ?: BGI_DEFAULT_MOHALLA); ?></td>
                            <td><?php echo htmlspecialchars($row['event_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['event_code'] ?? ''); ?></td>
                            <td><?php echo date('Y-m-d H:i:s', strtotime($row['attendance_time'])); ?></td>
                            <?php if ($isSuperAdmin): ?>
                            <td>
                                <form method="POST" onsubmit="return confirm('Delete this attendance record? This cannot be undone.');">
                                    <input type="hidden" name="delete_id" value="<?= (int) $row['attendance_id'] ?>">
                                    <button type="submit" class="btn danger" style="min-height:32px;padding:4px 14px;font-size:0.82rem;">Delete</button>
                                </form>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p>No attendance records found.</p>
    <?php endif; ?>
</div>

</body>
</html>
<?php
if (isset($attendanceStmt) && $attendanceStmt instanceof mysqli_stmt) {
    $attendanceStmt->close();
}
mysqli_close($conn);
?>
