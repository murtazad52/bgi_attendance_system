<?php
require_once __DIR__ . '/bootstrap.php';

bgi_mobile_require_login();

if (!bgi_can_take_attendance()) {
    bgi_mobile_error('This user cannot take attendance from mobile.', 403);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $scopeFilter = bgi_scope_filter_sql('e.idara', 'e.mohalla');
    $scopeSql = $scopeFilter[0];
    $scopeTypes = $scopeFilter[1];
    $scopeParams = $scopeFilter[2];

    $events = bgi_mobile_query_rows(
        $conn,
        "SELECT e.id, e.event_name, COALESCE(e.event_code, '') AS event_code,
                DATE_FORMAT(e.event_date, '%Y-%m-%d') AS event_date,
                COALESCE(DATE_FORMAT(e.reporting_time, '%H:%i'), '') AS reporting_time,
                e.idara, e.mohalla, COUNT(a.id) AS recorded_count
         FROM events e
         LEFT JOIN attendance a ON a.event_id = e.id" . ($scopeSql !== '' ? ' WHERE ' . $scopeSql : '') . "
         GROUP BY e.id, e.event_name, e.event_code, e.event_date, e.reporting_time, e.idara, e.mohalla
         ORDER BY e.event_date DESC, e.reporting_time DESC
         LIMIT 20",
        $scopeTypes,
        $scopeParams
    );

    bgi_mobile_respond([
        'ok' => true,
        'user' => bgi_mobile_current_user_payload(),
        'allowedStatuses' => ['Present', 'Late', 'Absent', 'Out of Kuwait'],
        'events' => bgi_mobile_format_event_rows($events),
    ]);
}

$input = bgi_mobile_input();
$eventId = isset($input['eventId']) ? (int) $input['eventId'] : 0;
$itsId = isset($input['itsId']) ? trim((string) $input['itsId']) : '';
$statusInput = isset($input['status']) ? trim((string) $input['status']) : '';
$remark = isset($input['remark']) ? trim((string) $input['remark']) : '';

if ($eventId <= 0) {
    bgi_mobile_error('Please select an event.');
}
if (!preg_match('/^\d{8}$/', $itsId)) {
    bgi_mobile_error('Please enter a valid 8-digit ITS ID.');
}

$scopeFilter = bgi_scope_filter_sql('idara', 'mohalla');
$scopeSql = $scopeFilter[0];
$scopeTypes = $scopeFilter[1];
$scopeParams = $scopeFilter[2];

$eventSql = "SELECT id, event_name, COALESCE(event_code, '') AS event_code,
                    DATE_FORMAT(event_date, '%Y-%m-%d') AS event_date,
                    COALESCE(DATE_FORMAT(reporting_time, '%H:%i:%s'), '') AS reporting_time,
                    idara, mohalla
             FROM events
             WHERE id = ?";
if ($scopeSql !== '') {
    $eventSql .= ' AND ' . $scopeSql;
}
$eventSql .= ' LIMIT 1';

$eventStmt = $conn->prepare($eventSql);
if (!$eventStmt) {
    bgi_mobile_error('Unable to load the selected event.', 500);
}

$eventTypes = 'i' . $scopeTypes;
$eventParams = array_merge([$eventId], $scopeParams);
bgi_mobile_bind_dynamic_params($eventStmt, $eventTypes, $eventParams);
$eventStmt->execute();
$eventResult = $eventStmt->get_result();
$event = $eventResult->fetch_assoc();
$eventStmt->close();

if (!$event) {
    bgi_mobile_error('The selected event is not available for this user.', 404);
}

$memberSql = "SELECT id, member_name, bgi_id, its_id, idara, mohalla
              FROM members
              WHERE its_id = ?";
if ($scopeSql !== '') {
    $memberSql .= ' AND ' . $scopeSql;
}
$memberSql .= ' LIMIT 1';

$memberStmt = $conn->prepare($memberSql);
if (!$memberStmt) {
    bgi_mobile_error('Unable to load the selected member.', 500);
}

$memberTypes = 's' . $scopeTypes;
$memberParams = array_merge([$itsId], $scopeParams);
bgi_mobile_bind_dynamic_params($memberStmt, $memberTypes, $memberParams);
$memberStmt->execute();
$memberResult = $memberStmt->get_result();
$member = $memberResult->fetch_assoc();
$memberStmt->close();

if (!$member) {
    bgi_mobile_error('No member was found for that ITS ID in the allowed scope.', 404);
}

if (
    strcasecmp((string) ($member['idara'] ?? ''), (string) ($event['idara'] ?? '')) !== 0 ||
    strcasecmp((string) ($member['mohalla'] ?? ''), (string) ($event['mohalla'] ?? '')) !== 0
) {
    bgi_mobile_error('The selected member does not belong to the event Idara and Mohalla.');
}

$duplicateStmt = $conn->prepare("SELECT id FROM attendance WHERE event_id = ? AND its_id = ? LIMIT 1");
if (!$duplicateStmt) {
    bgi_mobile_error('Unable to validate duplicate attendance.', 500);
}

$duplicateStmt->bind_param("is", $eventId, $itsId);
$duplicateStmt->execute();
$duplicateExists = (bool) $duplicateStmt->get_result()->fetch_assoc();
$duplicateStmt->close();

if ($duplicateExists) {
    bgi_mobile_error('Attendance has already been recorded for this member and event.');
}

$allowedStatuses = ['Present', 'Late', 'Absent', 'Out of Kuwait'];
$finalStatus = $statusInput;
if ($finalStatus === '' || strcasecmp($finalStatus, 'Auto') === 0) {
    $reportingBase = ($event['event_date'] ?? '') !== '' ? $event['event_date'] : date('Y-m-d');
    $reportingTime = ($event['reporting_time'] ?? '') !== '' ? $event['reporting_time'] : '00:00:00';
    $reportingTimestamp = strtotime($reportingBase . ' ' . $reportingTime);
    $finalStatus = ($reportingTimestamp !== false && time() > $reportingTimestamp) ? 'Late' : 'Present';
} elseif (!in_array($finalStatus, $allowedStatuses, true)) {
    bgi_mobile_error('The selected attendance status is not valid.');
}

$memberId = (int) $member['id'];
$memberName = (string) ($member['member_name'] ?? '');
$bgiId = (string) ($member['bgi_id'] ?? '');
$idara = (string) ($member['idara'] ?? '');
$mohalla = (string) ($member['mohalla'] ?? '');
$insertColumns = ['event_id', 'member_id', 'member_name', 'its_id', 'bgi_id', 'idara', 'mohalla'];
$insertPlaceholders = ['?', '?', '?', '?', '?', '?', '?'];
$insertTypes = 'iisssss';
$insertParams = [$eventId, $memberId, $memberName, $itsId, $bgiId, $idara, $mohalla];

if (bgi_mobile_column_exists($conn, 'attendance', 'attendance_date')) {
    $insertColumns[] = 'attendance_date';
    $insertPlaceholders[] = '?';
    $insertTypes .= 's';
    $insertParams[] = date('Y-m-d');
}

if (bgi_mobile_column_exists($conn, 'attendance', 'attendance_time')) {
    $insertColumns[] = 'attendance_time';
    $insertPlaceholders[] = '?';
    $insertTypes .= 's';
    $insertParams[] = date('Y-m-d H:i:s');
}

if (bgi_mobile_column_exists($conn, 'attendance', 'status')) {
    $insertColumns[] = 'status';
    $insertPlaceholders[] = '?';
    $insertTypes .= 's';
    $insertParams[] = $finalStatus;
}

if (bgi_mobile_column_exists($conn, 'attendance', 'remark')) {
    $insertColumns[] = 'remark';
    $insertPlaceholders[] = '?';
    $insertTypes .= 's';
    $insertParams[] = $remark;
}

$insertSql = sprintf(
    'INSERT INTO attendance (%s) VALUES (%s)',
    implode(', ', $insertColumns),
    implode(', ', $insertPlaceholders)
);

$insertStmt = $conn->prepare($insertSql);
if (!$insertStmt) {
    bgi_mobile_error('Unable to save attendance right now.', 500);
}

bgi_mobile_bind_dynamic_params($insertStmt, $insertTypes, $insertParams);

if (!$insertStmt->execute()) {
    $insertError = $insertStmt->error;
    $insertStmt->close();
    error_log('Mobile attendance insert failed: ' . $insertError);
    bgi_mobile_error('Attendance could not be saved right now.', 500);
}
$insertStmt->close();

bgi_mobile_respond([
    'ok' => true,
    'message' => 'Attendance recorded successfully.',
    'recordedStatus' => $finalStatus,
    'eventName' => (string) ($event['event_name'] ?? ''),
    'memberName' => $memberName,
]);
