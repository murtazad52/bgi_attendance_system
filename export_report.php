<?php
include('session_check.php');
include('db.php');

bgi_require_roles([BGI_ROLE_SUPER_ADMIN, BGI_ROLE_IDARA_ADMIN, BGI_ROLE_MOHALLA_ADMIN]);

function bind_export_params(mysqli_stmt $stmt, string $types, array &$params): void
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

function is_valid_export_date($date) {
    if ($date === '') {
        return false;
    }

    $parsed = DateTime::createFromFormat('Y-m-d', $date);
    return $parsed && $parsed->format('Y-m-d') === $date;
}

$type = $_GET['type'] ?? '';
$start_date = trim($_GET['start_date'] ?? '');
$end_date = trim($_GET['end_date'] ?? '');
$dateFilterEnabled = false;
$isScopedAdmin = !bgi_is_super_admin();

if ($start_date !== '' || $end_date !== '') {
    if ($start_date === '' || $end_date === '') {
        exit('Please provide both start and end dates.');
    }
    if (!is_valid_export_date($start_date) || !is_valid_export_date($end_date)) {
        exit('Invalid date range.');
    }
    $dateFilterEnabled = true;
}

if ($type === 'event') {
    $query = "
        SELECT e.event_code, e.event_name, e.idara, e.mohalla, e.event_date, COUNT(a.id) AS total_attendees
        FROM events e
        LEFT JOIN attendance a ON a.event_id = e.id";
    $conditions = [];
    $types = '';
    $params = [];
    if ($isScopedAdmin) {
        if (bgi_is_mohalla_admin()) {
            $conditions[] = "e.mohalla = ?";
            $types .= 's';
            $params[] = bgi_current_scope_mohalla();
        } else {
            $conditions[] = "e.idara = ? AND e.mohalla = ?";
            $types .= 'ss';
            $params[] = bgi_current_scope_idara();
            $params[] = bgi_current_scope_mohalla();
        }
    }
    if ($dateFilterEnabled) {
        $query .= " AND a.attendance_date BETWEEN ? AND ?";
        $types .= 'ss';
        $params[] = $start_date;
        $params[] = $end_date;
    }
    if (!empty($conditions)) {
        $query .= " WHERE " . implode(' AND ', $conditions);
    }
    $query .= " GROUP BY e.id, e.event_code, e.event_name, e.idara, e.mohalla, e.event_date ORDER BY e.event_date DESC";
    $filename = 'event_report.csv';
    $headers = ['Event Code', 'Event Name', 'Idara', 'Mohalla', 'Event Date', 'Total Attendees'];
} elseif ($type === 'member') {
    $query = "
        SELECT m.its_id, m.member_name, m.bgi_id, m.idara, m.mohalla, m.position, m.phone, tl.member_name AS team_leader_name, tl.its_id AS team_leader_its_id, cap.member_name AS captain_name, cap.its_id AS captain_its_id, COUNT(a.id) AS events_attended
        FROM members m
        LEFT JOIN attendance a ON a.its_id = m.its_id
        LEFT JOIN members tl ON m.team_leader_its_id = tl.its_id
        LEFT JOIN members cap ON COALESCE(m.captain_its_id, tl.captain_its_id) = cap.its_id";
    $conditions = [];
    $types = '';
    $params = [];
    if ($dateFilterEnabled) {
        $query .= " AND a.attendance_date BETWEEN ? AND ?";
        $types .= 'ss';
        $params[] = $start_date;
        $params[] = $end_date;
    }
    if ($isScopedAdmin) {
        if (bgi_is_mohalla_admin()) {
            $conditions[] = "m.mohalla = ?";
            $types .= 's';
            $params[] = bgi_current_scope_mohalla();
        } else {
            $conditions[] = "m.idara = ? AND m.mohalla = ?";
            $types .= 'ss';
            $params[] = bgi_current_scope_idara();
            $params[] = bgi_current_scope_mohalla();
        }
    }
    if (!empty($conditions)) {
        $query .= " WHERE " . implode(' AND ', $conditions);
    }
    $query .= "
        GROUP BY m.its_id, m.member_name, m.bgi_id, m.idara, m.mohalla, m.position, m.phone, tl.member_name, tl.its_id, cap.member_name, cap.its_id
        ORDER BY m.member_name ASC";
    $filename = 'member_report.csv';
    $headers = ['ITS ID', 'Member Name', 'BGI ID', 'Idara', 'Mohalla', 'Position', 'Mobile Number', 'Team Leader Name', 'Team Leader ITS ID', 'Captain Name', 'Captain ITS ID', 'Events Attended'];
} else {
    die('Invalid export type.');
}

$stmt = $conn->prepare($query);
if (!$stmt) {
    die('Unable to prepare export query.');
}
bind_export_params($stmt, $types, $params);
$stmt->execute();
$result = $stmt->get_result();

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');
fputcsv($output, $headers);

while ($row = mysqli_fetch_assoc($result)) {
    if ($type === 'member') {
        $row['position'] = bgi_member_position_label($row['position'] ?? BGI_POSITION_MEMBER);
    }
    fputcsv($output, $row);
}
fclose($output);
exit;
