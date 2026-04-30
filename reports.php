<?php
include('session_check.php');
include('db.php');

bgi_require_roles([BGI_ROLE_SUPER_ADMIN, BGI_ROLE_IDARA_ADMIN, BGI_ROLE_MOHALLA_ADMIN]);

function bind_report_params(mysqli_stmt $stmt, string $types, array &$params): void {
    if ($types === '') {
        return;
    }

    $bindParams = [$types];
    foreach ($params as $key => &$value) {
        $bindParams[] = &$value;
    }

    call_user_func_array([$stmt, 'bind_param'], $bindParams);
}

function is_valid_report_date($date) {
    if ($date === '') {
        return false;
    }

    $parsed = DateTime::createFromFormat('Y-m-d', $date);
    return $parsed && $parsed->format('Y-m-d') === $date;
}

$start_date = trim($_GET['start_date'] ?? '');
$end_date = trim($_GET['end_date'] ?? '');
$dateFilterEnabled = false;
$filterError = '';
$isScopedAdmin = !bgi_is_super_admin();

if ($start_date !== '' || $end_date !== '') {
    if ($start_date === '' || $end_date === '') {
        $filterError = 'Please select both start and end dates.';
    } elseif (!is_valid_report_date($start_date) || !is_valid_report_date($end_date)) {
        $filterError = 'Please enter a valid date range.';
    } else {
        $dateFilterEnabled = true;
    }
}

// Attendance summary by event
$query_event = "
    SELECT e.event_code, e.event_name, e.idara, e.mohalla, e.event_date, COUNT(a.id) AS total_attendees
    FROM events e
    LEFT JOIN attendance a ON a.event_id = e.id";
$eventConditions = [];
$eventTypes = '';
$eventParams = [];
if ($dateFilterEnabled) {
    $query_event .= " AND a.attendance_date BETWEEN ? AND ?";
    $eventTypes .= 'ss';
    $eventParams[] = $start_date;
    $eventParams[] = $end_date;
}
if ($isScopedAdmin) {
    if (bgi_is_mohalla_admin()) {
        $eventConditions[] = "e.mohalla = ?";
        $eventTypes .= 's';
        $eventParams[] = bgi_current_scope_mohalla();
    } else {
        $eventConditions[] = "e.idara = ? AND e.mohalla = ?";
        $eventTypes .= 'ss';
        $eventParams[] = bgi_current_scope_idara();
        $eventParams[] = bgi_current_scope_mohalla();
    }
}
if (!empty($eventConditions)) {
    $query_event .= " WHERE " . implode(' AND ', $eventConditions);
}
$query_event .= "
    GROUP BY e.id, e.event_code, e.event_name, e.idara, e.mohalla, e.event_date
    ORDER BY e.event_date DESC";
$event_stmt = $conn->prepare($query_event);
bind_report_params($event_stmt, $eventTypes, $eventParams);
$event_stmt->execute();
$event_rows = $event_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Attendance summary by member
$query_member = "
    SELECT m.its_id, m.member_name, m.bgi_id, m.idara, m.mohalla, COUNT(a.id) AS events_attended
    FROM members m
    LEFT JOIN attendance a ON a.its_id = m.its_id";
$memberConditions = [];
$memberTypes = '';
$memberParams = [];
if ($dateFilterEnabled) {
    $query_member .= " AND a.attendance_date BETWEEN ? AND ?";
    $memberTypes .= 'ss';
    $memberParams[] = $start_date;
    $memberParams[] = $end_date;
}
if ($isScopedAdmin) {
    if (bgi_is_mohalla_admin()) {
        $memberConditions[] = "m.mohalla = ?";
        $memberTypes .= 's';
        $memberParams[] = bgi_current_scope_mohalla();
    } else {
        $memberConditions[] = "m.idara = ? AND m.mohalla = ?";
        $memberTypes .= 'ss';
        $memberParams[] = bgi_current_scope_idara();
        $memberParams[] = bgi_current_scope_mohalla();
    }
}
if (!empty($memberConditions)) {
    $query_member .= " WHERE " . implode(' AND ', $memberConditions);
}
$query_member .= "
    GROUP BY m.its_id, m.member_name, m.bgi_id, m.idara, m.mohalla
    ORDER BY events_attended DESC";
$member_stmt = $conn->prepare($query_member);
bind_report_params($member_stmt, $memberTypes, $memberParams);
$member_stmt->execute();
$member_rows = $member_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$reportPeriodLabel = $dateFilterEnabled ? ($start_date . ' to ' . $end_date) : 'All available dates';
$eventSummaryCount = count($event_rows);
$memberSummaryCount = count($member_rows);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Reports</title>
    <style>
        body { font-family: Arial; background: #f4f6f8; padding: 20px; }
        .container { background: white; padding: 20px; border-radius: 10px; margin-bottom: 30px; }
        h2 { margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        th { background-color: #2E8B57; color: white; }
        .filter-form { margin-bottom: 20px; }
        .btn { padding: 8px 15px; background: #2E8B57; color: white; text-decoration: none; border-radius: 5px; }
        .btn:hover { background: #246B46; }
    </style>
    <link rel="stylesheet" href="app.css">
</head>
<body class="app-page page-table">

<div class="page-shell">
    <a href="dashboard.php" class="btn secondary">Back to Dashboard</a>

    <section class="report-hero">
        <span class="eyebrow">Report Center</span>
        <h2>Date Range Reporting</h2>
        <p class="page-intro">Filter high-level attendance summaries, then export the current event or member report as CSV for follow-up work.</p>

        <div class="report-meta">
            <span class="meta-pill"><?= htmlspecialchars($reportPeriodLabel) ?></span>
            <span class="meta-pill"><?= $eventSummaryCount ?> event summary row(s)</span>
            <span class="meta-pill"><?= $memberSummaryCount ?> member summary row(s)</span>
            <span class="meta-pill"><?= htmlspecialchars(bgi_current_scope_label()) ?></span>
        </div>
    </section>

    <section class="filter-card">
        <div class="panel-heading">
            <div>
                <span class="eyebrow">Filters & Export</span>
                <h3>Choose Your Reporting Window</h3>
                <p>Select a date range, review the summary below, and export the same filtered data when needed.</p>
            </div>
        </div>

        <form class="filter-form" method="GET">
            <label>Start Date: <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>"></label>
            <label>End Date: <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>"></label>
            <button type="submit" class="btn">Filter</button>
            <a href="monthly_reports.php" class="btn secondary">Open Monthly Reports</a>
            <a href="export_report.php?type=event&start_date=<?= rawurlencode($start_date) ?>&end_date=<?= rawurlencode($end_date) ?>" class="btn secondary">Export Event CSV</a>
            <a href="export_report.php?type=member&start_date=<?= rawurlencode($start_date) ?>&end_date=<?= rawurlencode($end_date) ?>" class="btn secondary">Export Member CSV</a>
        </form>

        <?php if ($filterError !== ''): ?>
            <div class="message error"><?= htmlspecialchars($filterError) ?></div>
        <?php endif; ?>
    </section>

    <div class="summary">
        <div class="summary-card summary-total"><span class="summary-label">Period</span><span class="summary-value"><?= $dateFilterEnabled ? 'Filtered' : 'All' ?></span></div>
        <div class="summary-card summary-present"><span class="summary-label">Event Rows</span><span class="summary-value"><?= $eventSummaryCount ?></span></div>
        <div class="summary-card summary-time"><span class="summary-label">Member Rows</span><span class="summary-value"><?= $memberSummaryCount ?></span></div>
    </div>

    <section class="section-card">
        <div class="panel-heading">
            <div>
                <span class="eyebrow">Event Overview</span>
                <h3>Attendance Summary by Event</h3>
                <p>Each row shows the attendee count recorded against an event in the selected period.</p>
            </div>
        </div>

        <?php if (!empty($event_rows)): ?>
            <div class="table-wrap">
                <table>
                    <tr>
                        <th>Event Name</th>
                        <th>Event Code</th>
                        <th>Idara</th>
                        <th>Mohalla</th>
                        <th>Event Date</th>
                        <th>Total Attendees</th>
                    </tr>
                    <?php foreach ($event_rows as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['event_name']) ?></td>
                            <td><?= htmlspecialchars($row['event_code'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['idara']) ?></td>
                            <td><?= htmlspecialchars($row['mohalla']) ?></td>
                            <td><?= htmlspecialchars($row['event_date']) ?></td>
                            <td><?= htmlspecialchars($row['total_attendees']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">No event summary rows were found for the selected date range.</div>
        <?php endif; ?>
    </section>

    <section class="section-card">
        <div class="panel-heading">
            <div>
                <span class="eyebrow">Member Overview</span>
                <h3>Attendance Summary by Member</h3>
                <p>Use this table to compare who attended the most events inside the selected period.</p>
            </div>
        </div>

        <?php if (!empty($member_rows)): ?>
            <div class="table-wrap">
                <table>
                    <tr>
                        <th>ITS ID</th>
                        <th>Member Name</th>
                        <th>BGI ID</th>
                        <th>Idara</th>
                        <th>Mohalla</th>
                        <th>Events Attended</th>
                    </tr>
                    <?php foreach ($member_rows as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['its_id']) ?></td>
                            <td><?= htmlspecialchars($row['member_name']) ?></td>
                            <td><?= htmlspecialchars($row['bgi_id']) ?></td>
                            <td><?= htmlspecialchars($row['idara']) ?></td>
                            <td><?= htmlspecialchars($row['mohalla']) ?></td>
                            <td><?= htmlspecialchars($row['events_attended']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">No member summary rows were found for the selected date range.</div>
        <?php endif; ?>
    </section>
</div>

</body>
</html>
