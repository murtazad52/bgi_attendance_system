<?php
require_once __DIR__ . '/auth.php';
include 'db.php';

bgi_require_roles([BGI_ROLE_SUPER_ADMIN, BGI_ROLE_IDARA_ADMIN, BGI_ROLE_MOHALLA_ADMIN, BGI_ROLE_MEMBER]);

$isMemberView = bgi_is_member();
$memberReportScopeMode = bgi_member_report_scope_mode();
$isSelfMemberView = $memberReportScopeMode === 'self';
$isTeamLeaderView = $memberReportScopeMode === 'team';
$isCaptainView = $memberReportScopeMode === 'scope';
$memberScopeId = $isMemberView ? bgi_current_member_id() : 0;
$memberScopeItsId = $isMemberView ? bgi_current_member_its_id() : '';
$memberScopeName = $isMemberView ? bgi_current_member_name() : '';
$homePath = bgi_home_path_for_current_user();
$backLabel = $isSelfMemberView
    ? 'Back to My Summary'
    : ($isTeamLeaderView ? 'Back to Team Summary' : ($isCaptainView ? 'Back to Scope Summary' : 'Back to Dashboard'));
$isScopeRestricted = bgi_is_scope_restricted();

if ($isScopeRestricted) {
    if (bgi_is_mohalla_admin()) {
        $eventsStmt = $conn->prepare("SELECT id, event_name, event_code, reporting_time, idara, mohalla FROM events WHERE mohalla = ? ORDER BY event_date DESC, reporting_time DESC, event_name ASC");
        $scopeMohalla = bgi_current_scope_mohalla();
        $eventsStmt->bind_param("s", $scopeMohalla);
    } else {
        $eventsStmt = $conn->prepare("SELECT id, event_name, event_code, reporting_time, idara, mohalla FROM events WHERE idara = ? AND mohalla = ? ORDER BY event_date DESC, reporting_time DESC, event_name ASC");
        $scopeIdara = bgi_current_scope_idara();
        $scopeMohalla = bgi_current_scope_mohalla();
        $eventsStmt->bind_param("ss", $scopeIdara, $scopeMohalla);
    }
    $eventsStmt->execute();
    $events = $eventsStmt->get_result();
} else {
    $events = mysqli_query($conn, "SELECT id, event_name, event_code, reporting_time, idara, mohalla FROM events ORDER BY event_date DESC, reporting_time DESC, event_name ASC");
}
$eventsList = [];
if ($events) {
    while ($row = mysqli_fetch_assoc($events)) {
        $eventsList[] = $row;
    }
}
if (isset($eventsStmt) && $eventsStmt instanceof mysqli_stmt) {
    $eventsStmt->close();
}

$event_id = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);
if (!$event_id) {
    mysqli_close($conn);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Select Event</title>
        <style>
            body { font-family: 'Segoe UI', sans-serif; background: #f4f6f9; padding: 30px; color: #333; }
            .container { max-width: 600px; margin: auto; background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
            h2 { text-align: center; margin-bottom: 25px; }
            label, select, button { display: block; width: 100%; margin-bottom: 15px; font-size: 16px; }
            select, button { padding: 10px; border-radius: 6px; border: 1px solid #ccc; }
            button { background: #007BFF; color: white; border: none; cursor: pointer; }
            button:hover { background: #0056b3; }
            .back-link { display: block; text-align: center; margin-top: 15px; text-decoration: none; color: #007BFF; }
        </style>
        <link rel="stylesheet" href="app.css">
    </head>
    <body class="app-page page-form">
        <div class="container">
            <h2><?= $isSelfMemberView ? 'Select Event to View My Report' : ($isTeamLeaderView ? 'Select Event to View Team Report' : ($isCaptainView ? 'Select Event to View Scope Report' : 'Select Event to View Report')) ?></h2>
            <p class="page-intro">
                <?= $isMemberView
                    ? ($isSelfMemberView
                        ? 'Choose an event to open only your own attendance status for that event.'
                        : ($isTeamLeaderView
                            ? 'Choose an event to open attendance status for your assigned team members.'
                            : 'Choose an event to open attendance status for your Idara and Mohalla scope.'))
                    : 'Choose one event to open the attendance breakdown and status summary.' ?>
            </p>
            <form method="GET">
                <label for="event_id">Choose Event:</label>
                <select name="event_id" id="event_id" required>
                    <option value="">-- Select Event --</option>
                    <?php foreach ($eventsList as $eventOption): ?>
                        <option value="<?= (int) $eventOption['id'] ?>">
                            <?= htmlspecialchars(($eventOption['event_code'] ?? '') . ' - ' . $eventOption['event_name'] . ' (' . ($eventOption['idara'] ?? BGI_DEFAULT_IDARA) . ' / ' . ($eventOption['mohalla'] ?? BGI_DEFAULT_MOHALLA) . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit">View Report</button>
            </form>
            <a href="<?= htmlspecialchars($homePath) ?>" class="back-link"><?= htmlspecialchars($backLabel) ?></a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$eventQuery = "SELECT id, event_name, event_code, reporting_time, idara, mohalla FROM events WHERE id = ?";
if ($isScopeRestricted) {
    if (bgi_is_mohalla_admin()) {
        $eventQuery .= " AND mohalla = ?";
    } else {
        $eventQuery .= " AND idara = ? AND mohalla = ?";
    }
}
$eventQuery .= " LIMIT 1";
$eventStmt = $conn->prepare($eventQuery);
if ($isScopeRestricted) {
    if (bgi_is_mohalla_admin()) {
        $scopeMohalla = bgi_current_scope_mohalla();
        $eventStmt->bind_param("is", $event_id, $scopeMohalla);
    } else {
        $scopeIdara = bgi_current_scope_idara();
        $scopeMohalla = bgi_current_scope_mohalla();
        $eventStmt->bind_param("iss", $event_id, $scopeIdara, $scopeMohalla);
    }
} else {
    $eventStmt->bind_param("i", $event_id);
}
$eventStmt->execute();
$eventResult = $eventStmt->get_result();
$event = $eventResult->fetch_assoc();
$eventStmt->close();

if (!$event) {
    mysqli_close($conn);
    http_response_code(404);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Invalid Event</title>
        <link rel="stylesheet" href="app.css">
    </head>
    <body class="app-page page-form">
        <div class="container">
            <h2>Invalid Event</h2>
            <p class="page-intro">The requested event could not be found. Please choose another event.</p>
            <a href="report_events.php" class="back-link">Choose Another Event</a>
            <a href="<?= htmlspecialchars($homePath) ?>" class="back-link"><?= htmlspecialchars($backLabel) ?></a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$reporting_time = $event['reporting_time'];

$all_members = [];
if (!$isMemberView || $memberScopeItsId !== '') {
    if ($isSelfMemberView) {
        $membersQuery = "SELECT member_name, its_id, idara, mohalla, position FROM members WHERE its_id = ?";
        if ($isScopeRestricted) {
            $membersQuery .= " AND idara = ? AND mohalla = ?";
        }
        $membersQuery .= " LIMIT 1";
        $membersStmt = $conn->prepare($membersQuery);
        if ($isScopeRestricted) {
            $scopeIdara = bgi_current_scope_idara();
            $scopeMohalla = bgi_current_scope_mohalla();
            $membersStmt->bind_param("sss", $memberScopeItsId, $scopeIdara, $scopeMohalla);
        } else {
            $membersStmt->bind_param("s", $memberScopeItsId);
        }
    } elseif ($isTeamLeaderView) {
        $membersQuery = "SELECT member_name, its_id, idara, mohalla, position FROM members WHERE (team_leader_its_id = ? OR its_id = ?)";
        if ($isScopeRestricted) {
            $membersQuery .= " AND idara = ? AND mohalla = ?";
        }
        $membersQuery .= " ORDER BY member_name ASC";
        $membersStmt = $conn->prepare($membersQuery);
        if ($isScopeRestricted) {
            $scopeIdara = bgi_current_scope_idara();
            $scopeMohalla = bgi_current_scope_mohalla();
            $membersStmt->bind_param("ssss", $memberScopeItsId, $memberScopeItsId, $scopeIdara, $scopeMohalla);
        } else {
            $membersStmt->bind_param("ss", $memberScopeItsId, $memberScopeItsId);
        }
    } else {
        $membersQuery = "SELECT member_name, its_id, idara, mohalla, position FROM members";
        if ($isScopeRestricted) {
            $membersQuery .= bgi_is_mohalla_admin() ? " WHERE mohalla = ?" : " WHERE idara = ? AND mohalla = ?";
        }
        $membersQuery .= " ORDER BY member_name ASC";
        $membersStmt = $conn->prepare($membersQuery);
        if ($isScopeRestricted) {
            if (bgi_is_mohalla_admin()) {
                $scopeMohalla = bgi_current_scope_mohalla();
                $membersStmt->bind_param("s", $scopeMohalla);
            } else {
                $scopeIdara = bgi_current_scope_idara();
                $scopeMohalla = bgi_current_scope_mohalla();
                $membersStmt->bind_param("ss", $scopeIdara, $scopeMohalla);
            }
        }
    }
    $membersStmt->execute();
    $membersResult = $membersStmt->get_result();
    while ($row = $membersResult->fetch_assoc()) {
        $all_members[(string) $row['its_id']] = [
            'member_name' => $row['member_name'],
            'its_id' => $row['its_id'],
            'idara' => $row['idara'] ?? BGI_DEFAULT_IDARA,
            'mohalla' => $row['mohalla'] ?? BGI_DEFAULT_MOHALLA,
            'position' => bgi_normalize_member_position($row['position'] ?? BGI_POSITION_MEMBER),
        ];
    }
    $membersStmt->close();
}

$attendance_data = [];
if ($isSelfMemberView && $memberScopeItsId !== '') {
    $attendanceStmt = $conn->prepare("SELECT its_id, attendance_time, status, remark FROM attendance WHERE event_id = ? AND its_id = ?");
    $attendanceStmt->bind_param("is", $event_id, $memberScopeItsId);
} else {
    $attendanceStmt = $conn->prepare("SELECT its_id, attendance_time, status, remark FROM attendance WHERE event_id = ?");
    $attendanceStmt->bind_param("i", $event_id);
}
$attendanceStmt->execute();
$attendanceResult = $attendanceStmt->get_result();
while ($row = $attendanceResult->fetch_assoc()) {
    $attendance_data[(string) $row['its_id']] = $row;
}
$attendanceStmt->close();

$search_name = $isSelfMemberView ? '' : trim($_GET['search_name'] ?? '');
$status_filter = $_GET['status_filter'] ?? '';
$allowed_status_filters = ['present', 'absent', 'ontime', 'late', 'out-of-kuwait'];
$statusFilterLabels = [
    'present' => 'Present',
    'absent' => 'Absent',
    'ontime' => 'On Time',
    'late' => 'Late',
    'out-of-kuwait' => 'Out of Kuwait',
];
if (!in_array($status_filter, $allowed_status_filters, true) && $status_filter !== '') {
    $status_filter = '';
}

// Function to get attendance status of a member
function get_status($member_id, $attendance_data, $reporting_time) {
    if (!isset($attendance_data[$member_id])) {
        return 'absent';
    }

    $stored_status = $attendance_data[$member_id]['status'] ?? '';
    if ($stored_status === 'Late') {
        return 'late';
    }
    if ($stored_status === 'Absent') {
        return 'absent';
    }
    if ($stored_status === 'Out of Kuwait') {
        return 'out-of-kuwait';
    }

    $att_time = $attendance_data[$member_id]['attendance_time'] ?? null;
    if (!$att_time) {
        return 'absent';
    }
    $time_only = date('H:i:s', strtotime($att_time));
    if (strtotime($time_only) <= strtotime($reporting_time)) {
        return 'ontime';
    } else {
        return 'late';
    }
}

// Filter members according to search_name and status_filter
$filtered_members = [];

foreach ($all_members as $member_id => $member) {
    $name = $member['member_name'];
    // Filter by name (case-insensitive)
    if ($search_name !== '' && stripos($name, $search_name) === false) {
        continue;
    }

    $status = get_status($member_id, $attendance_data, $reporting_time);

    // Map present status: 'ontime' and 'late' both count as present
    if ($status == 'ontime' || $status == 'late') {
        $present_status = 'present';
    } else {
        $present_status = 'absent';
    }

    // Filter by attendance status
    if ($status_filter !== '') {
        if ($status_filter == 'present' && $present_status != 'present') {
            continue;
        }
        if ($status_filter == 'absent' && $present_status != 'absent') {
            continue;
        }
        if ($status_filter == 'ontime' && $status != 'ontime') {
            continue;
        }
        if ($status_filter == 'late' && $status != 'late') {
            continue;
        }
        if ($status_filter == 'out-of-kuwait' && $status != 'out-of-kuwait') {
            continue;
        }
    }

    $filtered_members[$member_id] = $member;
}

// Update totals based on filtered members
$total_members = count($filtered_members);
$total_ontime = 0;
$total_late = 0;
$total_absent = 0;
$total_out_of_kuwait = 0;
foreach ($filtered_members as $member_id => $member) {
    $status = get_status($member_id, $attendance_data, $reporting_time);

    if ($status === 'ontime') {
        $total_ontime++;
        continue;
    }

    if ($status === 'late') {
        $total_late++;
        continue;
    }

    if ($status === 'out-of-kuwait') {
        $total_out_of_kuwait++;
        continue;
    }

    $total_absent++;
}

$activeFilterCount = 0;
if ($search_name !== '') {
    $activeFilterCount++;
}
if ($status_filter !== '') {
    $activeFilterCount++;
}

$heroEyebrow = $isSelfMemberView
    ? 'My Event Status'
    : ($isTeamLeaderView ? 'Team Event Status' : ($isCaptainView ? 'Captain Event Status' : 'Event Summary'));
$heroTitle = $isSelfMemberView && $memberScopeName !== ''
    ? $event['event_name'] . ' - ' . $memberScopeName
    : $event['event_name'];
$eventCode = $event['event_code'] ?? '';
$pageIntro = $isSelfMemberView
    ? 'Review only your own ITS record, attendance time, and remark for this event.'
    : ($isTeamLeaderView
        ? 'Review event attendance status for the members assigned to your team.'
        : ($isCaptainView
            ? 'Review event attendance status for all members in your Idara and Mohalla scope.'
            : 'Review member-by-member attendance status for this event with premium summaries, faster filtering, and clearer visual grouping.'));
$filterHeading = $isSelfMemberView ? 'Filter My Event Record' : 'Refine This Event Report';
$filterDescription = $isSelfMemberView
    ? 'Use the status filter to focus on one result type for your own event record.'
    : 'Search for a member by name, or narrow the table to one attendance status.';
$detailHeading = $isSelfMemberView ? 'My Attendance Record' : 'Member Status Table';
$detailDescription = $isSelfMemberView
    ? ($total_members === 1 ? 'Showing your event attendance row only.' : 'No rows from your own record match the current filters.')
    : $total_members . ' row(s) match the current filters for this event.';
$emptyMessage = $isSelfMemberView
    ? 'No rows from your own record match the current filters for this event.'
    : 'No members matched the current filters for this event.';

mysqli_close($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event Report</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f0f2f5; padding: 20px; color: #333; }
        .container { max-width: 1200px; margin: auto; background: #fff; padding: 30px; border-radius: 12px; box-shadow: 0 0 12px rgba(0,0,0,0.1); }
        h2 { margin-bottom: 20px; color: #007BFF; }
        .summary { display: flex; flex-wrap: wrap; gap: 15px; margin-bottom: 30px; }
        .summary-card { padding: 20px; border-radius: 12px; text-align: center; flex: 1 1 160px; box-shadow: 0 6px 16px rgba(15,23,42,0.08); border-top: 5px solid transparent; }
        .summary-label { display: block; font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 8px; }
        .summary-value { display: block; font-size: 26px; font-weight: 800; }
        .summary-total { background: #eff6ff; border-top-color: #2563eb; color: #1d4ed8; }
        .summary-ontime { background: #ecfdf5; border-top-color: #16a34a; color: #166534; }
        .summary-late { background: #fff7ed; border-top-color: #f59e0b; color: #b45309; }
        .summary-absent { background: #fef2f2; border-top-color: #dc2626; color: #b91c1c; }
        .summary-out { background: #ecfeff; border-top-color: #0891b2; color: #0f766e; }
        .summary-time { background: #f8fafc; border-top-color: #475569; color: #334155; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 12px 15px; border: 1px solid #dee2e6; text-align: center; }
        th { background: #007BFF; color: white; }
        tr:nth-child(even) { background: #f8f9fa; }
        tr:hover { background: #eef6ff; }
        .back-link { margin-top: 20px; display: inline-block; background: #6c757d; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; }
        .status-badge { display: inline-block; min-width: 110px; padding: 7px 12px; border-radius: 999px; font-weight: 700; }
        .status-badge.ontime { background: #dcfce7; color: #166534; }
        .status-badge.late { background: #ffedd5; color: #c2410c; }
        .status-badge.absent { background: #fee2e2; color: #b91c1c; }
        .status-badge.out-of-kuwait { background: #cffafe; color: #155e75; }
        form.filter-form {
            margin-bottom: 20px;
        }
        form.filter-form input[type="text"], form.filter-form select {
            padding: 8px;
            font-size: 16px;
            margin-right: 15px;
            border: 1px solid #ccc;
            border-radius: 5px;
            width: 200px;
        }
        form.filter-form button {
            padding: 8px 16px;
            background: #007BFF;
            color: #fff;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        form.filter-form button:hover {
            background: #0056b3;
        }
    </style>
    <link rel="stylesheet" href="app.css">
</head>
<body class="app-page page-table">
    <div class="page-shell">
        <?php if ($isMemberView): ?>
            <div class="hero-actions">
                <a href="<?= htmlspecialchars($homePath) ?>" class="btn secondary">&larr; <?= htmlspecialchars($backLabel) ?></a>
                <a href="logout.php" class="btn">Logout</a>
            </div>
        <?php else: ?>
            <form action="<?= htmlspecialchars($homePath) ?>" method="get">
                <button type="submit" class="btn secondary">&larr; <?= htmlspecialchars($backLabel) ?></button>
            </form>
        <?php endif; ?>

        <section class="report-hero">
            <span class="eyebrow"><?= htmlspecialchars($heroEyebrow) ?></span>
            <h2><?= htmlspecialchars($heroTitle) ?></h2>
            <p class="page-intro"><?= htmlspecialchars($pageIntro) ?></p>

            <div class="report-meta">
                <span class="meta-pill">Reporting Time <?= htmlspecialchars(substr($reporting_time, 0, 5)) ?></span>
                <?php if ($eventCode !== ''): ?>
                    <span class="meta-pill">Code <?= htmlspecialchars($eventCode) ?></span>
                <?php endif; ?>
                <span class="meta-pill"><?= $isSelfMemberView ? 'ITS ID ' . htmlspecialchars($memberScopeItsId) : $total_members . ' members in view' ?></span>
                <span class="meta-pill"><?= htmlspecialchars(($event['idara'] ?? BGI_DEFAULT_IDARA) . ' / ' . ($event['mohalla'] ?? BGI_DEFAULT_MOHALLA)) ?></span>
                <span class="meta-pill"><?= $activeFilterCount > 0 ? $activeFilterCount . ' filter(s) active' : 'No filters applied' ?></span>
                <span class="meta-pill"><?= $status_filter !== '' ? 'Status: ' . $statusFilterLabels[$status_filter] : 'All statuses' ?></span>
            </div>
        </section>

        <section class="filter-card">
            <div class="panel-heading">
                <div>
                    <span class="eyebrow">Filters</span>
                    <h3><?= htmlspecialchars($filterHeading) ?></h3>
                    <p><?= htmlspecialchars($filterDescription) ?></p>
                </div>
            </div>

            <form method="GET" class="filter-form">
                <input type="hidden" name="event_id" value="<?= (int) $event_id ?>">
                <?php if (!$isSelfMemberView): ?>
                    <input type="text" name="search_name" placeholder="Search by member name" value="<?= htmlspecialchars($search_name) ?>">
                <?php endif; ?>
                <select name="status_filter">
                    <option value="">-- Filter by Status --</option>
                    <option value="present" <?= $status_filter === 'present' ? 'selected' : '' ?>>Present</option>
                    <option value="absent" <?= $status_filter === 'absent' ? 'selected' : '' ?>>Absent</option>
                    <option value="ontime" <?= $status_filter === 'ontime' ? 'selected' : '' ?>>On Time</option>
                    <option value="late" <?= $status_filter === 'late' ? 'selected' : '' ?>>Late</option>
                    <option value="out-of-kuwait" <?= $status_filter === 'out-of-kuwait' ? 'selected' : '' ?>>Out of Kuwait</option>
                </select>
                <button type="submit">Filter</button>
            </form>
        </section>

        <div class="summary">
            <div class="summary-card summary-total"><span class="summary-label"><?= $isSelfMemberView ? 'Rows Shown' : 'Members Shown' ?></span><span class="summary-value"><?= $total_members ?></span></div>
            <div class="summary-card summary-ontime"><span class="summary-label">On Time</span><span class="summary-value"><?= $total_ontime ?></span></div>
            <div class="summary-card summary-late"><span class="summary-label">Late</span><span class="summary-value"><?= $total_late ?></span></div>
            <div class="summary-card summary-absent"><span class="summary-label">Absent</span><span class="summary-value"><?= $total_absent ?></span></div>
            <div class="summary-card summary-out"><span class="summary-label">Out of Kuwait</span><span class="summary-value"><?= $total_out_of_kuwait ?></span></div>
            <div class="summary-card summary-time"><span class="summary-label">Reporting Time</span><span class="summary-value"><?= htmlspecialchars($reporting_time) ?></span></div>
        </div>

        <section class="section-card">
            <div class="panel-heading">
                <div>
                    <span class="eyebrow">Detailed View</span>
                    <h3><?= htmlspecialchars($detailHeading) ?></h3>
                    <p><?= htmlspecialchars($detailDescription) ?></p>
                </div>
            </div>

            <?php if (count($filtered_members) === 0): ?>
                <div class="empty-state"><?= htmlspecialchars($emptyMessage) ?></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>ITS ID</th>
                                <th>Member Name</th>
                                <th>Position</th>
                                <th>Status</th>
                                <th>Attendance Time</th>
                                <th>Remark</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($filtered_members as $member_id => $member): ?>
                                <?php 
                                    $status = get_status($member_id, $attendance_data, $reporting_time);
                                    $att_time = $attendance_data[$member_id]['attendance_time'] ?? null;
                                    $status_class = strtolower($status);
                                    $stored_remark = trim($attendance_data[$member_id]['remark'] ?? '');
                                    $status_label = [
                                        'ontime' => 'On Time',
                                        'late' => 'Late',
                                        'absent' => 'Absent',
                                        'out-of-kuwait' => 'Out of Kuwait',
                                    ][$status] ?? ucfirst($status);
                                    $remark = $stored_remark !== '' ? $stored_remark : $status_label;
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($member['its_id']) ?></td>
                                    <td><?= htmlspecialchars($member['member_name']) ?></td>
                                    <td><?= htmlspecialchars(bgi_member_position_label($member['position'] ?? BGI_POSITION_MEMBER)) ?></td>
                                    <td><span class="status-badge <?= $status_class ?>"><?= htmlspecialchars($status_label) ?></span></td>
                                    <td><?= $att_time ? date('Y-m-d H:i:s', strtotime($att_time)) : '--' ?></td>
                                    <td><?= htmlspecialchars($remark) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="table-note">
                    <?= $isSelfMemberView
                        ? 'Only your own ITS record is shown in this event report.'
                        : 'Use the status filter above to isolate late arrivals, absences, or members marked as out of Kuwait.' ?>
                </p>
            <?php endif; ?>
        </section>
    </div>
</body>
</html>
