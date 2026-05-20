<?php
require_once __DIR__ . '/auth.php';
include('db.php');

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
$isScopeRestricted = bgi_is_scope_restricted();

function bind_dynamic_params(mysqli_stmt $stmt, string $types, array &$params): void
{
    if ($types === '') {
        return;
    }

    $bindParams = [$types];
    foreach ($params as $key => &$value) {
        $bindParams[] = &$value;
    }

    call_user_func_array([$stmt, 'bind_param'], $bindParams);
}

function fetch_all_rows(mysqli_result $result): array
{
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    return $rows;
}

function build_attendance_filters(int $filterMonth, int $filterYear, string $alias = 'a'): array
{
    $conditions = [];
    $types = '';
    $params = [];

    if ($filterMonth > 0) {
        $conditions[] = "MONTH($alias.attendance_time) = ?";
        $types .= 'i';
        $params[] = $filterMonth;

        $effectiveYear = $filterYear > 0 ? $filterYear : (int) date('Y');
        $conditions[] = "YEAR($alias.attendance_time) = ?";
        $types .= 'i';
        $params[] = $effectiveYear;
    } elseif ($filterYear > 0) {
        $conditions[] = "YEAR($alias.attendance_time) = ?";
        $types .= 'i';
        $params[] = $filterYear;
    }

    return [$conditions, $types, $params];
}

function build_plain_attendance_filters(int $filterMonth, int $filterYear): array
{
    $conditions = [];
    $types = '';
    $params = [];

    if ($filterMonth > 0) {
        $conditions[] = "MONTH(attendance_time) = ?";
        $types .= 'i';
        $params[] = $filterMonth;

        $effectiveYear = $filterYear > 0 ? $filterYear : (int) date('Y');
        $conditions[] = "YEAR(attendance_time) = ?";
        $types .= 'i';
        $params[] = $effectiveYear;
    } elseif ($filterYear > 0) {
        $conditions[] = "YEAR(attendance_time) = ?";
        $types .= 'i';
        $params[] = $filterYear;
    }

    return [$conditions, $types, $params];
}

function describe_member_event_row(?array $attendanceRow, ?string $reportingTime): array
{
    if (!$attendanceRow) {
        return [
            'status_text' => 'Absent',
            'status_class' => 'status-absent',
            'attendance_time' => '--',
            'remark' => 'Absent',
        ];
    }

    $attendanceTime = $attendanceRow['attendance_time'] ?? '--';
    $storedStatus = $attendanceRow['status'] ?? '';
    $storedRemark = trim($attendanceRow['remark'] ?? '');

    if ($storedStatus === 'Out of Kuwait') {
        return [
            'status_text' => 'Out of Kuwait',
            'status_class' => 'status-out-of-kuwait',
            'attendance_time' => $attendanceTime,
            'remark' => $storedRemark !== '' ? $storedRemark : 'Out of Kuwait',
        ];
    }

    if ($storedStatus === 'Absent') {
        return [
            'status_text' => 'Absent',
            'status_class' => 'status-absent',
            'attendance_time' => '--',
            'remark' => $storedRemark !== '' ? $storedRemark : 'Absent',
        ];
    }

    if ($storedStatus === 'InformedAbsent') {
        return [
            'status_text' => 'Informed Absent',
            'status_class' => 'status-informed-absent',
            'attendance_time' => '--',
            'remark' => $storedRemark !== '' ? $storedRemark : 'Informed Absent',
        ];
    }

    if ($storedStatus === 'Late') {
        return [
            'status_text' => 'Present (Late)',
            'status_class' => 'status-late',
            'attendance_time' => $attendanceTime,
            'remark' => $storedRemark !== '' ? $storedRemark : 'Late',
        ];
    }

    if ($attendanceTime !== '--' && $reportingTime !== null && strtotime($attendanceTime) <= strtotime($reportingTime)) {
        return [
            'status_text' => 'Present (On Time)',
            'status_class' => 'status-present',
            'attendance_time' => $attendanceTime,
            'remark' => $storedRemark !== '' ? $storedRemark : 'On Time',
        ];
    }

    if ($attendanceTime !== '--') {
        return [
            'status_text' => 'Present (Late)',
            'status_class' => 'status-late',
            'attendance_time' => $attendanceTime,
            'remark' => $storedRemark !== '' ? $storedRemark : 'Late',
        ];
    }

    return [
        'status_text' => 'Absent',
        'status_class' => 'status-absent',
        'attendance_time' => '--',
        'remark' => $storedRemark !== '' ? $storedRemark : 'Absent',
    ];
}

$search_name = $isSelfMemberView ? '' : trim($_GET['search_name'] ?? '');
$event_id = filter_var($_GET['event_id'] ?? 0, FILTER_VALIDATE_INT);
$event_id = $event_id !== false && $event_id > 0 ? $event_id : 0;

$filter_month = filter_var($_GET['month'] ?? 0, FILTER_VALIDATE_INT);
$filter_month = $filter_month !== false && $filter_month >= 1 && $filter_month <= 12 ? $filter_month : 0;

$current_year = (int) date('Y');
$filter_year = filter_var($_GET['year'] ?? 0, FILTER_VALIDATE_INT);
$filter_year = $filter_year !== false && $filter_year >= $current_year - 10 && $filter_year <= $current_year ? $filter_year : 0;

if ($isScopeRestricted) {
    if (bgi_is_mohalla_admin()) {
        $eventsStmt = $conn->prepare("SELECT id, event_name, event_code, reporting_time, idara, mohalla FROM events WHERE mohalla = ? ORDER BY event_name");
        $scopeMohalla = bgi_current_scope_mohalla();
        $eventsStmt->bind_param("s", $scopeMohalla);
    } else {
        $eventsStmt = $conn->prepare("SELECT id, event_name, event_code, reporting_time, idara, mohalla FROM events WHERE idara = ? AND mohalla = ? ORDER BY event_name");
        $scopeIdara = bgi_current_scope_idara();
        $scopeMohalla = bgi_current_scope_mohalla();
        $eventsStmt->bind_param("ss", $scopeIdara, $scopeMohalla);
    }
    $eventsStmt->execute();
    $eventsResult = $eventsStmt->get_result();
} else {
    $eventsResult = mysqli_query($conn, "SELECT id, event_name, event_code, reporting_time, idara, mohalla FROM events ORDER BY event_name");
}
$eventsList = fetch_all_rows($eventsResult);
if (isset($eventsStmt) && $eventsStmt instanceof mysqli_stmt) {
    $eventsStmt->close();
}

$reporting_time = null;
$selectedEventName = 'All Events';
if ($event_id > 0) {
    $eventQuery = "SELECT event_name, event_code, reporting_time, idara, mohalla FROM events WHERE id = ?";
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
    $eventRow = $eventResult->fetch_assoc();
    $eventStmt->close();

    if ($eventRow) {
        $selectedEventName = $eventRow['event_name'];
        $reporting_time = $eventRow['reporting_time'];
        $selectedEventCode = $eventRow['event_code'] ?? '';
    } else {
        $event_id = 0;
    }
}

$membersSql = "SELECT m.member_name, m.bgi_id, m.idara, m.mohalla, m.its_id, m.position FROM members m";
$membersTypes = '';
$membersParams = [];
$membersConditions = [];

if ($isSelfMemberView) {
    $membersConditions[] = "m.its_id = ?";
    $membersTypes .= 's';
    $membersParams[] = $memberScopeItsId;
} elseif ($isTeamLeaderView) {
    $membersConditions[] = "(m.team_leader_its_id = ? OR m.its_id = ?)";
    $membersTypes .= 'ss';
    $membersParams[] = $memberScopeItsId;
    $membersParams[] = $memberScopeItsId;
}

if (!$isSelfMemberView && $search_name !== '') {
    $membersConditions[] = "m.member_name LIKE ?";
    $membersTypes .= 's';
    $membersParams[] = '%' . $search_name . '%';
}

if ($isScopeRestricted) {
    if (bgi_is_mohalla_admin()) {
        $membersConditions[] = "m.mohalla = ?";
        $membersTypes .= 's';
        $membersParams[] = bgi_current_scope_mohalla();
    } else {
        $membersConditions[] = "m.idara = ?";
        $membersConditions[] = "m.mohalla = ?";
        $membersTypes .= 'ss';
        $membersParams[] = bgi_current_scope_idara();
        $membersParams[] = bgi_current_scope_mohalla();
    }
}

if (!empty($membersConditions)) {
    $membersSql .= " WHERE " . implode(' AND ', $membersConditions);
}

$membersSql .= " ORDER BY m.member_name ASC";

$membersStmt = $conn->prepare($membersSql);
if ($membersTypes !== '') {
    bind_dynamic_params($membersStmt, $membersTypes, $membersParams);
}
$membersStmt->execute();
$membersList = fetch_all_rows($membersStmt->get_result());
$membersStmt->close();

[$dateConditions, $dateTypes, $dateParams] = build_attendance_filters($filter_month, $filter_year, 'a');

if ($event_id === 0) {
    $totalEventsSql = "
        SELECT COUNT(DISTINCT e.id) AS total_events
        FROM events e
        JOIN attendance a ON a.event_id = e.id
    ";
    $totalEventsConditions = [];
    $totalEventsTypes = '';
    $totalEventsParams = [];
    if ($isScopeRestricted) {
        if (bgi_is_mohalla_admin()) {
            $totalEventsConditions[] = "e.mohalla = ?";
            $totalEventsTypes .= 's';
            $totalEventsParams[] = bgi_current_scope_mohalla();
        } else {
            $totalEventsConditions[] = "e.idara = ?";
            $totalEventsConditions[] = "e.mohalla = ?";
            $totalEventsTypes .= 'ss';
            $totalEventsParams[] = bgi_current_scope_idara();
            $totalEventsParams[] = bgi_current_scope_mohalla();
        }
    }
    if (!empty($dateConditions)) {
        $totalEventsConditions = array_merge($totalEventsConditions, $dateConditions);
        $totalEventsTypes .= $dateTypes;
        $totalEventsParams = array_merge($totalEventsParams, $dateParams);
    }
    if (!empty($totalEventsConditions)) {
        $totalEventsSql .= " WHERE " . implode(' AND ', $totalEventsConditions);
    }

    $totalEventsStmt = $conn->prepare($totalEventsSql);
    if ($totalEventsTypes !== '') {
        bind_dynamic_params($totalEventsStmt, $totalEventsTypes, $totalEventsParams);
    }
    $totalEventsStmt->execute();
    $totalEventsRow = $totalEventsStmt->get_result()->fetch_assoc();
    $totalEventsStmt->close();
    $total_events = (int) ($totalEventsRow['total_events'] ?? 0);
} else {
    $total_events = 1;
}

$ranking = [];
$eventRows = [];
$badgeClassMap = [
    'status-present' => 'ontime',
    'status-late' => 'late',
    'status-absent' => 'absent',
    'status-out-of-kuwait' => 'out-of-kuwait',
];

if ($event_id === 0) {
    $presentSql = "
        SELECT COUNT(DISTINCT a.event_id) AS present_count
        FROM attendance a
        JOIN events e ON a.event_id = e.id
        WHERE a.its_id = ?
        AND COALESCE(a.status, '') NOT IN ('Late', 'Absent', 'InformedAbsent', 'Out of Kuwait')
        AND a.attendance_time IS NOT NULL
        AND TIME(a.attendance_time) <= TIME(e.reporting_time)
    ";
    if ($isScopeRestricted) {
        $presentSql .= bgi_is_mohalla_admin() ? " AND e.mohalla = ?" : " AND e.idara = ? AND e.mohalla = ?";
    }
    if (!empty($dateConditions)) {
        $presentSql .= " AND " . implode(' AND ', $dateConditions);
    }

    $lateSql = "
        SELECT COUNT(DISTINCT a.event_id) AS late_count
        FROM attendance a
        JOIN events e ON a.event_id = e.id
        WHERE a.its_id = ?
        AND (
            COALESCE(a.status, '') = 'Late'
            OR (
                COALESCE(a.status, '') NOT IN ('Absent', 'InformedAbsent', 'Out of Kuwait')
                AND a.attendance_time IS NOT NULL
                AND TIME(a.attendance_time) > TIME(e.reporting_time)
            )
        )
    ";
    if ($isScopeRestricted) {
        $lateSql .= bgi_is_mohalla_admin() ? " AND e.mohalla = ?" : " AND e.idara = ? AND e.mohalla = ?";
    }
    if (!empty($dateConditions)) {
        $lateSql .= " AND " . implode(' AND ', $dateConditions);
    }

    $outOfKuwaitSql = "
        SELECT COUNT(DISTINCT a.event_id) AS out_of_kuwait_count
        FROM attendance a
        JOIN events e ON a.event_id = e.id
        WHERE a.its_id = ?
        AND a.status = 'Out of Kuwait'
    ";
    if ($isScopeRestricted) {
        $outOfKuwaitSql .= bgi_is_mohalla_admin() ? " AND e.mohalla = ?" : " AND e.idara = ? AND e.mohalla = ?";
    }
    if (!empty($dateConditions)) {
        $outOfKuwaitSql .= " AND " . implode(' AND ', $dateConditions);
    }

    $presentStmt = $conn->prepare($presentSql);
    $lateStmt = $conn->prepare($lateSql);
    $outOfKuwaitStmt = $conn->prepare($outOfKuwaitSql);
    $scopeTypes = '';
    $scopeParams = [];
    if ($isScopeRestricted) {
        if (bgi_is_mohalla_admin()) {
            $scopeTypes = 's';
            $scopeParams = [bgi_current_scope_mohalla()];
        } else {
            $scopeTypes = 'ss';
            $scopeParams = [bgi_current_scope_idara(), bgi_current_scope_mohalla()];
        }
    }
    $presentTypes = 's' . $scopeTypes . $dateTypes;
    $lateTypes = 's' . $scopeTypes . $dateTypes;
    $outOfKuwaitTypes = 's' . $scopeTypes . $dateTypes;

    foreach ($membersList as $row) {
        $memberItsId = (string) $row['its_id'];

        $presentParams = array_merge([$memberItsId], $scopeParams, $dateParams);
        bind_dynamic_params($presentStmt, $presentTypes, $presentParams);
        $presentStmt->execute();
        $presentRow = $presentStmt->get_result()->fetch_assoc();
        $presentCount = (int) ($presentRow['present_count'] ?? 0);

        $lateParams = array_merge([$memberItsId], $scopeParams, $dateParams);
        bind_dynamic_params($lateStmt, $lateTypes, $lateParams);
        $lateStmt->execute();
        $lateRow = $lateStmt->get_result()->fetch_assoc();
        $lateCount = (int) ($lateRow['late_count'] ?? 0);

        $outOfKuwaitParams = array_merge([$memberItsId], $scopeParams, $dateParams);
        bind_dynamic_params($outOfKuwaitStmt, $outOfKuwaitTypes, $outOfKuwaitParams);
        $outOfKuwaitStmt->execute();
        $outOfKuwaitRow = $outOfKuwaitStmt->get_result()->fetch_assoc();
        $outOfKuwaitCount = (int) ($outOfKuwaitRow['out_of_kuwait_count'] ?? 0);

        $absentCount = max(0, $total_events - $presentCount - $lateCount - $outOfKuwaitCount);
        $attendancePercent = $total_events > 0 ? round((($presentCount + $lateCount) / $total_events) * 100, 2) : 0;

        $ranking[] = [
            'member' => $row,
            'present_count' => $presentCount,
            'late_count' => $lateCount,
            'out_of_kuwait_count' => $outOfKuwaitCount,
            'absent_count' => $absentCount,
            'attendance_percent' => $attendancePercent,
        ];
    }

    $presentStmt->close();
    $lateStmt->close();
    $outOfKuwaitStmt->close();

    usort($ranking, function ($a, $b) {
        return $b['attendance_percent'] <=> $a['attendance_percent'];
    });

    $currentRank = 0;
    $prevPercent = null;
    foreach ($ranking as $key => &$item) {
        if ($prevPercent === null || $item['attendance_percent'] < $prevPercent) {
            $currentRank = $key + 1;
        }
        $item['rank'] = $currentRank;
        $prevPercent = $item['attendance_percent'];
    }
    unset($item);
} else {
    [$plainConditions, $plainTypes, $plainParams] = build_plain_attendance_filters($filter_month, $filter_year);

    $attendanceSql = "SELECT attendance_time, status, remark FROM attendance WHERE its_id = ? AND event_id = ?";
    if (!empty($plainConditions)) {
        $attendanceSql .= " AND " . implode(' AND ', $plainConditions);
    }
    $attendanceSql .= " LIMIT 1";

    $attendanceStmt = $conn->prepare($attendanceSql);
    $attendanceTypes = 'si' . $plainTypes;

    foreach ($membersList as $row) {
        $memberItsId = (string) $row['its_id'];
        $attendanceParams = array_merge([$memberItsId, $event_id], $plainParams);
        bind_dynamic_params($attendanceStmt, $attendanceTypes, $attendanceParams);
        $attendanceStmt->execute();
        $attendanceRow = $attendanceStmt->get_result()->fetch_assoc();
        $displayRow = describe_member_event_row($attendanceRow ?: null, $reporting_time);

        $eventRows[] = [
            'member' => $row,
            'status_text' => $displayRow['status_text'],
            'status_class' => $displayRow['status_class'],
            'status_badge_class' => $badgeClassMap[$displayRow['status_class']] ?? 'absent',
            'attendance_time' => $displayRow['attendance_time'],
            'remark' => $displayRow['remark'],
        ];
    }

    $attendanceStmt->close();
}

$activeFilterCount = 0;
if ($search_name !== '') {
    $activeFilterCount++;
}
if ($event_id > 0) {
    $activeFilterCount++;
}
if ($filter_month > 0) {
    $activeFilterCount++;
}
if ($filter_year > 0) {
    $activeFilterCount++;
}

$totalMembersInView = $event_id === 0 ? count($ranking) : count($eventRows);
$allPresentTotal = 0;
$allLateTotal = 0;
$allOutOfKuwaitTotal = 0;
$allAbsentTotal = 0;
$averageAttendancePercent = 0;
$topAttendancePercent = 0;
$topPerformerName = 'No data yet';
$eventStatusTotals = [
    'status-present' => 0,
    'status-late' => 0,
    'status-absent' => 0,
    'status-out-of-kuwait' => 0,
];

if ($event_id === 0 && !empty($ranking)) {
    foreach ($ranking as $item) {
        $allPresentTotal += (int) $item['present_count'];
        $allLateTotal += (int) $item['late_count'];
        $allOutOfKuwaitTotal += (int) $item['out_of_kuwait_count'];
        $allAbsentTotal += (int) $item['absent_count'];
        $averageAttendancePercent += (float) $item['attendance_percent'];
    }

    $averageAttendancePercent = round($averageAttendancePercent / count($ranking), 2);
    $topAttendancePercent = (float) $ranking[0]['attendance_percent'];
    $topPerformerName = $ranking[0]['member']['member_name'];
}

if ($event_id > 0 && !empty($eventRows)) {
    foreach ($eventRows as $row) {
        if (isset($eventStatusTotals[$row['status_class']])) {
            $eventStatusTotals[$row['status_class']]++;
        }
    }
}

$heroEyebrow = $isSelfMemberView
    ? 'My Attendance'
    : ($isTeamLeaderView ? 'Team Attendance' : ($isCaptainView ? 'Captain Attendance' : 'Member Summary'));
$heroTitle = $isSelfMemberView && $event_id === 0
    ? 'My Attendance Summary'
    : (($isTeamLeaderView && $event_id === 0)
        ? 'Team Attendance Summary'
        : (($isCaptainView && $event_id === 0) ? 'Captain Attendance Summary' : $selectedEventName));
$selectedEventCode = $selectedEventCode ?? '';
$pageIntro = $isSelfMemberView
    ? 'Review only your own attendance history by event, month, and year.'
    : ($isTeamLeaderView
        ? 'Review attendance data for the members assigned to your team.'
        : ($isCaptainView
            ? 'Review attendance data for all members in your Idara and Mohalla scope.'
            : 'Switch between all-event rankings and single-event detail views while keeping month, year, and member filters within easy reach.'));
$filterHeading = $isSelfMemberView ? 'Filter My Report' : 'Refine The Member View';
$filterDescription = $isSelfMemberView
    ? 'Choose an event, month, or year to focus on your own attendance history.'
    : ($isTeamLeaderView
        ? 'Search within your team, or focus on one event, month, and year.'
        : 'Search by name, focus on a single event, or narrow the report to a specific month and year.');
$detailHeading = $event_id === 0
    ? ($isSelfMemberView ? 'My Attendance Totals' : 'Member Ranking Table')
    : ($isSelfMemberView ? 'My Event Attendance' : 'Event Attendance Table');
$detailDescription = $event_id === 0
    ? ($isSelfMemberView ? 'Your attendance totals across the selected filters.' : 'Best current performer: ' . $topPerformerName . '.')
    : ($isSelfMemberView ? 'Showing only your record for the selected event.' : $totalMembersInView . ' member row(s) match the current event filters.');

mysqli_close($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Member Attendance Summary - <?= htmlspecialchars(bgi_app_name()) ?></title>
    <link rel="stylesheet" href="app.css">
</head>
<body class="app-page page-table">

<div class="page-shell">
    <div class="hero-actions">
        <a href="<?= htmlspecialchars($homePath) ?>" class="btn secondary">&larr; <?= $isMemberView ? 'Back' : 'Back to Dashboard' ?></a>
        <?php if ($isMemberView): ?>
            <a href="member_self_checkin.php" class="btn secondary">Check In</a>
            <a href="report_events.php" class="btn secondary">Event Report</a>
            <a href="logout.php" class="btn">Logout</a>
        <?php endif; ?>
    </div>

    <section class="report-hero">
        <span class="eyebrow"><?= htmlspecialchars($heroEyebrow) ?></span>
        <h2><?= htmlspecialchars($heroTitle) ?></h2>
        <p class="page-intro"><?= htmlspecialchars($pageIntro) ?></p>

        <div class="report-meta">
                <span class="meta-pill"><?= $isSelfMemberView ? 'ITS ID ' . htmlspecialchars($memberScopeItsId) : $totalMembersInView . ' member(s) in view' ?></span>
            <span class="meta-pill"><?= $event_id > 0 ? 'Single event focus' : $total_events . ' event(s) considered' ?></span>
            <?php if ($selectedEventCode !== ''): ?>
                <span class="meta-pill">Code <?= htmlspecialchars($selectedEventCode) ?></span>
            <?php endif; ?>
            <span class="meta-pill"><?= $activeFilterCount > 0 ? $activeFilterCount . ' filter(s) active' : 'No filters applied' ?></span>
            <span class="meta-pill"><?= $filter_year > 0 ? 'Year ' . $filter_year : 'All years' ?></span>
            <span class="meta-pill"><?= htmlspecialchars(bgi_current_scope_label()) ?></span>
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
            <?php if (!$isSelfMemberView): ?>
                <input type="text" name="search_name" placeholder="Search by member name" value="<?= htmlspecialchars($search_name) ?>">
            <?php endif; ?>

            <select name="event_id">
                <option value="0">-- All Events --</option>
                <?php foreach ($eventsList as $event): ?>
                    <option value="<?= (int) $event['id'] ?>" <?= ($event_id === (int) $event['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars(($event['event_code'] ?? '') . ' - ' . $event['event_name'] . ' (' . ($event['idara'] ?? BGI_DEFAULT_IDARA) . ' / ' . ($event['mohalla'] ?? BGI_DEFAULT_MOHALLA) . ')') ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="month">
                <option value="0">-- Month --</option>
                <?php
                for ($m = 1; $m <= 12; $m++) {
                    $selected = ($filter_month === $m) ? 'selected' : '';
                    $monthName = date('F', mktime(0, 0, 0, $m, 10));
                    echo "<option value='$m' $selected>$monthName</option>";
                }
                ?>
            </select>

            <select name="year">
                <option value="0">-- Year --</option>
                <?php
                for ($y = $current_year; $y >= $current_year - 10; $y--) {
                    $selected = ($filter_year === $y) ? 'selected' : '';
                    echo "<option value='$y' $selected>$y</option>";
                }
                ?>
            </select>

            <button type="submit">Filter</button>
        </form>
    </section>

    <?php if ($event_id === 0): ?>
        <div class="summary">
            <div class="summary-card summary-total"><span class="summary-label"><?= $isSelfMemberView ? 'Events Considered' : 'Members Shown' ?></span><span class="summary-value"><?= $isSelfMemberView ? $total_events : $totalMembersInView ?></span></div>
            <div class="summary-card summary-present"><span class="summary-label">Present Total</span><span class="summary-value"><?= $allPresentTotal ?></span></div>
            <div class="summary-card summary-late"><span class="summary-label">Late Total</span><span class="summary-value"><?= $allLateTotal ?></span></div>
            <div class="summary-card summary-out"><span class="summary-label">Out of Kuwait</span><span class="summary-value"><?= $allOutOfKuwaitTotal ?></span></div>
            <div class="summary-card summary-time"><span class="summary-label"><?= $isSelfMemberView ? 'Attendance Rate' : 'Avg Attendance' ?></span><span class="summary-value"><?= $averageAttendancePercent ?>%</span></div>
            <div class="summary-card summary-ontime"><span class="summary-label"><?= $isSelfMemberView ? 'Absent Total' : 'Best Attendance' ?></span><span class="summary-value"><?= $isSelfMemberView ? $allAbsentTotal : $topAttendancePercent . '%' ?></span></div>
        </div>
    <?php else: ?>
        <div class="summary">
            <div class="summary-card summary-total"><span class="summary-label"><?= $isSelfMemberView ? 'Rows Shown' : 'Members Shown' ?></span><span class="summary-value"><?= $totalMembersInView ?></span></div>
            <div class="summary-card summary-ontime"><span class="summary-label">On Time</span><span class="summary-value"><?= $eventStatusTotals['status-present'] ?></span></div>
            <div class="summary-card summary-late"><span class="summary-label">Late</span><span class="summary-value"><?= $eventStatusTotals['status-late'] ?></span></div>
            <div class="summary-card summary-out"><span class="summary-label">Out of Kuwait</span><span class="summary-value"><?= $eventStatusTotals['status-out-of-kuwait'] ?></span></div>
            <div class="summary-card summary-absent"><span class="summary-label">Absent</span><span class="summary-value"><?= $eventStatusTotals['status-absent'] ?></span></div>
            <div class="summary-card summary-time"><span class="summary-label">Reporting Time</span><span class="summary-value"><?= htmlspecialchars((string) $reporting_time) ?></span></div>
        </div>
    <?php endif; ?>

    <section class="section-card">
        <div class="panel-heading">
            <div>
                <span class="eyebrow">Detailed View</span>
                <h3><?= htmlspecialchars($detailHeading) ?></h3>
                <p><?= htmlspecialchars($detailDescription) ?></p>
            </div>
        </div>

        <?php if ($event_id === 0): ?>
            <?php if (!empty($ranking)): ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <?php if (!$isSelfMemberView): ?>
                                    <th>Rank</th>
                                <?php endif; ?>
                                <th>Member Name</th>
                                <th>Position</th>
                                <th>ITS ID</th>
                                <th>BGI ID</th>
                                <th>Idara</th>
                                <th>Mohalla</th>
                                <th>Present</th>
                                <th>Late</th>
                                <th>Out of Kuwait</th>
                                <th>Absent</th>
                                <th>Attendance %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ranking as $item): ?>
                                <?php $row = $item['member']; ?>
                                <tr>
                                    <?php if (!$isSelfMemberView): ?>
                                        <td><?= $item['rank'] ?></td>
                                    <?php endif; ?>
                                    <td><?= htmlspecialchars($row['member_name']) ?></td>
                                    <td><?= htmlspecialchars(bgi_member_position_label($row['position'] ?? BGI_POSITION_MEMBER)) ?></td>
                                    <td><?= htmlspecialchars($row['its_id']) ?></td>
                                    <td><?= htmlspecialchars($row['bgi_id']) ?></td>
                                    <td><?= htmlspecialchars($row['idara']) ?></td>
                                    <td><?= htmlspecialchars($row['mohalla']) ?></td>
                                    <td class="status-present"><?= $item['present_count'] ?></td>
                                    <td class="status-late"><?= $item['late_count'] ?></td>
                                    <td class="status-out-of-kuwait"><?= $item['out_of_kuwait_count'] ?></td>
                                    <td class="status-absent"><?= $item['absent_count'] ?></td>
                                    <td><?= $item['attendance_percent'] ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="table-note">
                    <?= $isSelfMemberView
                        ? 'Only your own attendance totals are shown here.'
                        : 'This ranking combines all filtered events and counts late arrivals separately from present-on-time attendance.' ?>
                </p>
            <?php else: ?>
                <div class="empty-state"><?= $isMemberView ? 'No attendance data matched your current filters.' : 'No members matched the current all-event filters.' ?></div>
            <?php endif; ?>
        <?php else: ?>
            <?php if (!empty($eventRows)): ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <?php if (!$isSelfMemberView): ?>
                                    <th>Rank</th>
                                <?php endif; ?>
                                <th>Member Name</th>
                                <th>Position</th>
                                <th>ITS ID</th>
                                <th>BGI ID</th>
                                <th>Idara</th>
                                <th>Mohalla</th>
                                <th>Status</th>
                                <th>Attendance Time</th>
                                <th>Remark</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($eventRows as $row): ?>
                                <tr>
                                    <?php if (!$isSelfMemberView): ?>
                                        <td>--</td>
                                    <?php endif; ?>
                                    <td><?= htmlspecialchars($row['member']['member_name']) ?></td>
                                    <td><?= htmlspecialchars(bgi_member_position_label($row['member']['position'] ?? BGI_POSITION_MEMBER)) ?></td>
                                    <td><?= htmlspecialchars($row['member']['its_id']) ?></td>
                                    <td><?= htmlspecialchars($row['member']['bgi_id']) ?></td>
                                    <td><?= htmlspecialchars($row['member']['idara']) ?></td>
                                    <td><?= htmlspecialchars($row['member']['mohalla']) ?></td>
                                    <td><span class="status-badge <?= htmlspecialchars($row['status_badge_class']) ?>"><?= htmlspecialchars($row['status_text']) ?></span></td>
                                    <td><?= htmlspecialchars($row['attendance_time']) ?></td>
                                    <td><?= htmlspecialchars($row['remark']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="table-note">
                    <?= $isSelfMemberView
                        ? 'Only your own record is shown for the selected event.'
                        : 'Each row shows the stored attendance status and remark for the selected event.' ?>
                </p>
            <?php else: ?>
                <div class="empty-state"><?= $isMemberView ? 'No event data matched your current filters.' : 'No members matched the current event filters.' ?></div>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</div>

</body>
</html>
