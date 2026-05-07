<?php
include('session_check.php');
include('db.php');

function fetch_dashboard_count(mysqli $conn, string $table, ?string $idaraColumn = null, ?string $mohallaColumn = null): int
{
    if (!bgi_is_super_admin() && $idaraColumn !== null && $mohallaColumn !== null) {
        if (bgi_is_mohalla_admin()) {
            $scopeMohalla = bgi_current_scope_mohalla();
            $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM `$table` WHERE `$mohallaColumn` = ?");
            if (!$stmt) {
                return 0;
            }

            $stmt->bind_param("s", $scopeMohalla);
        } else {
            $scopeIdara = bgi_current_scope_idara();
            $scopeMohalla = bgi_current_scope_mohalla();
            $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM `$table` WHERE `$idaraColumn` = ? AND `$mohallaColumn` = ?");
            if (!$stmt) {
                return 0;
            }

            $stmt->bind_param("ss", $scopeIdara, $scopeMohalla);
        }

        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int) ($row['total'] ?? 0);
    }

    $result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM `$table`");
    if (!$result) {
        return 0;
    }
    $row = mysqli_fetch_assoc($result);
    return (int) ($row['total'] ?? 0);
}

$isScopedAdmin = !bgi_is_super_admin();
$scopeLabel = bgi_current_scope_label();
$canManageMembers = bgi_can_manage_members();
$canManageEvents = bgi_can_manage_events();
$canTakeAttendance = bgi_can_take_attendance();
$canViewAttendanceRecords = bgi_can_view_attendance_records();
$canViewReports = bgi_can_view_reports();
$canViewAdminDirectory = bgi_can_view_admin_directory();
$canOpenReportsHub = in_array(bgi_current_user_role(), [BGI_ROLE_SUPER_ADMIN, BGI_ROLE_IDARA_ADMIN, BGI_ROLE_MOHALLA_ADMIN], true);

$totalMembers = fetch_dashboard_count($conn, 'members', 'idara', 'mohalla');
$totalEvents = fetch_dashboard_count($conn, 'events', 'idara', 'mohalla');
$totalAttendance = fetch_dashboard_count($conn, 'attendance', 'idara', 'mohalla');
$totalAdmins = $canViewAdminDirectory ? fetch_dashboard_count($conn, 'admin_users', 'idara', 'mohalla') : 0;

$latestEvent = null;
$latestEventResult = false;
if (!$isScopedAdmin) {
    $latestEventResult = mysqli_query(
        $conn,
        "SELECT event_name, event_code, event_date, reporting_time, idara, mohalla FROM events ORDER BY event_date DESC, reporting_time DESC LIMIT 1"
    );
} elseif (bgi_is_mohalla_admin()) {
    $latestEventStmt = $conn->prepare("SELECT event_name, event_code, event_date, reporting_time, idara, mohalla FROM events WHERE mohalla = ? ORDER BY event_date DESC, reporting_time DESC LIMIT 1");
    $scopeMohalla = bgi_current_scope_mohalla();
    $latestEventStmt->bind_param("s", $scopeMohalla);
    $latestEventStmt->execute();
    $latestEventResult = $latestEventStmt->get_result();
} else {
    $latestEventStmt = $conn->prepare("SELECT event_name, event_code, event_date, reporting_time, idara, mohalla FROM events WHERE idara = ? AND mohalla = ? ORDER BY event_date DESC, reporting_time DESC LIMIT 1");
    $scopeIdara = bgi_current_scope_idara();
    $scopeMohalla = bgi_current_scope_mohalla();
    $latestEventStmt->bind_param("ss", $scopeIdara, $scopeMohalla);
    $latestEventStmt->execute();
    $latestEventResult = $latestEventStmt->get_result();
}

if ($latestEventResult && mysqli_num_rows($latestEventResult) > 0) {
    $latestEvent = mysqli_fetch_assoc($latestEventResult);
}

if (isset($latestEventStmt) && $latestEventStmt instanceof mysqli_stmt) {
    $latestEventStmt->close();
}

mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?= htmlspecialchars(bgi_app_name()) ?></title>
    <link rel="stylesheet" href="app.css">
</head>
<body class="app-page">

<div class="navbar">
    <h1><?= htmlspecialchars(bgi_app_name()) ?></h1>
    <a href="logout.php" class="logout">Logout &rarr;</a>
</div>

<div class="dashboard-shell">
    <section class="dashboard-hero">
        <div class="dashboard-copy">
            <span class="eyebrow"><?= htmlspecialchars(bgi_role_label(bgi_current_user_role())) ?> Workspace</span>
            <h2>Welcome back, <?= htmlspecialchars($_SESSION['username']) ?>.</h2>
            <p>
                <?= $isScopedAdmin
                    ? 'Keep the daily workflow moving inside your assigned scope: ' . htmlspecialchars($scopeLabel) . '.'
                    : 'Keep the directory accurate, move events forward, and watch attendance trends from one polished control center across all Idara and Mohalla scopes.' ?>
            </p>

            <div class="hero-actions">
                <?php if ($canTakeAttendance): ?>
                    <a href="admin_attendance.php" class="btn">Record Attendance</a>
                <?php endif; ?>
                <?php if ($canViewReports): ?>
                    <a href="report_events.php" class="btn secondary">Open Event Report</a>
                <?php endif; ?>
            </div>

            <div class="inline-pills">
                <?php if ($canManageMembers): ?><a href="admin_members.php" class="pill">Directory ready</a><?php endif; ?>
                <?php if ($canViewReports): ?><a href="report_events.php" class="pill">Live status summaries</a><?php endif; ?>
                <a href="#operations" class="pill">Quick admin actions</a>
                <span class="pill"><?= htmlspecialchars($scopeLabel) ?></span>
            </div>
        </div>

        <aside class="dashboard-sidecard">
            <span class="panel-kicker">Latest Scheduled Event</span>
            <?php if ($latestEvent): ?>
                <h3><?= htmlspecialchars($latestEvent['event_name']) ?></h3>
                <?php if (!empty($latestEvent['event_code'])): ?>
                    <p>Code <?= htmlspecialchars($latestEvent['event_code']) ?></p>
                <?php endif; ?>
                <p>
                    <?= htmlspecialchars($latestEvent['event_date']) ?>
                    at
                    <?= htmlspecialchars(substr($latestEvent['reporting_time'], 0, 5)) ?>
                </p>
                <p><?= htmlspecialchars(($latestEvent['idara'] ?? BGI_DEFAULT_IDARA) . ' / ' . ($latestEvent['mohalla'] ?? BGI_DEFAULT_MOHALLA)) ?></p>
            <?php else: ?>
                <h3>No events scheduled yet</h3>
                <p>Create your first event to start tracking attendance with reporting times and summaries.</p>
            <?php endif; ?>

            <div class="metric-strip">
                <span class="metric-chip">Members <?= $totalMembers ?></span>
                <span class="metric-chip">Events <?= $totalEvents ?></span>
            </div>

            <p style="margin-top:16px;">
                <?php if ($canManageEvents): ?>
                    <a href="admin_events.php" class="section-link">Manage event calendar</a>
                <?php elseif ($canTakeAttendance): ?>
                    <a href="admin_attendance.php" class="section-link">Open attendance workspace</a>
                <?php endif; ?>
            </p>
        </aside>
    </section>

    <section class="stat-grid">
        <div class="stat-card stat-members">
            <span class="stat-label">Members</span>
            <span class="stat-value"><?= $totalMembers ?></span>
            <span class="stat-meta">Active member records in the directory.</span>
        </div>
        <div class="stat-card stat-events">
            <span class="stat-label">Events</span>
            <span class="stat-value"><?= $totalEvents ?></span>
            <span class="stat-meta">Configured gatherings with reporting times.</span>
        </div>
        <div class="stat-card stat-attendance">
            <span class="stat-label">Attendance Logs</span>
            <span class="stat-value"><?= $totalAttendance ?></span>
            <span class="stat-meta">Recorded check-ins across all events.</span>
        </div>
        <div class="stat-card stat-admins">
            <span class="stat-label">Admins</span>
            <span class="stat-value"><?= $totalAdmins ?></span>
            <span class="stat-meta">Secured administrator accounts with access.</span>
        </div>
    </section>

    <section class="panel-section" id="operations">
        <div class="split-header">
            <div>
                <span class="eyebrow">Operations</span>
                <h3>Choose Where You Want To Work</h3>
                <p>Move between daily admin tasks, reports, and secure setup tools without losing context.</p>
            </div>
            <?php if ($canOpenReportsHub): ?>
                <a href="reports.php" class="section-link">Open exports and date range reports</a>
            <?php endif; ?>
        </div>

        <div class="card-grid">
            <?php if ($canManageMembers): ?><a href="admin_members.php" class="card"><span class="card-title">Manage Members</span><small>Profiles, ITS numbers, and import tools.</small></a><?php endif; ?>
            <?php if ($canManageEvents): ?><a href="admin_events.php" class="card"><span class="card-title">Manage Events</span><small>Create schedules and maintain reporting times.</small></a><?php endif; ?>
            <?php if ($canViewAdminDirectory): ?><a href="manage_admins.php" class="card"><span class="card-title">Manage Admins</span><small><?= bgi_is_super_admin() ? 'Review every admin account and control scope-based access.' : 'See admin accounts assigned to your visible scope only.' ?></small></a><?php endif; ?>
            <?php if ($canTakeAttendance): ?><a href="admin_attendance.php" class="card"><span class="card-title">Record Attendance</span><small>Fast entry for event-based attendance updates.</small></a><?php endif; ?>
            <?php if ($canViewAttendanceRecords): ?><a href="view_attendance.php" class="card"><span class="card-title">View Attendance</span><small>Browse the latest attendance history in one table.</small></a><?php endif; ?>
            <?php if ($canViewReports): ?><a href="report_events.php" class="card"><span class="card-title">Event Summary</span><small>See attendance status by event with filters.</small></a><?php endif; ?>
            <?php if ($canViewReports): ?><a href="report_members.php" class="card"><span class="card-title">Member Summary</span><small>Compare member attendance across events and dates.</small></a><?php endif; ?>
            <?php if ($canOpenReportsHub): ?><a href="monthly_reports.php" class="card"><span class="card-title">Monthly Reports</span><small>Preview and send last-month summaries to Captains and Team Leaders.</small></a><?php endif; ?>
            <?php if (bgi_can_manage_admins()): ?>
                <a href="create_admin.php" class="card"><span class="card-title">Create Admin</span><small>Add another administrator securely when needed.</small></a>
                <a href="smtp_settings.php" class="card"><span class="card-title">SMTP Settings</span><small>Configure absent-notification emails and sender details.</small></a>
            <?php endif; ?>
        </div>
    </section>
</div>

</body>
</html>
