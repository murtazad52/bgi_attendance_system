<?php
require_once __DIR__ . '/bootstrap.php';

bgi_mobile_require_login();

if (!bgi_is_member()) {
    bgi_mobile_error('Self check-in is only available for members.', 403);
}

$itsId = bgi_current_member_its_id();
$memberId = (int) ($_SESSION['member_id'] ?? 0);

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
                e.idara, e.mohalla, e.latitude, e.longitude, e.radius_meters,
                a.status AS user_status
         FROM events e
         LEFT JOIN attendance a ON a.event_id = e.id AND a.its_id = ?" .
         ($scopeSql !== '' ? ' WHERE ' . $scopeSql : '') . "
         ORDER BY e.event_date DESC, e.reporting_time DESC
         LIMIT 20",
        's' . $scopeTypes,
        array_merge([$itsId], $scopeParams)
    );

    bgi_mobile_respond([
        'ok' => true,
        'user' => bgi_mobile_current_user_payload(),
        'events' => bgi_mobile_format_event_rows($events),
    ]);
}

$input = bgi_mobile_input();
$eventId = isset($input['eventId']) ? (int) $input['eventId'] : 0;
$lat = isset($input['lat']) && is_numeric($input['lat']) ? (float) $input['lat'] : null;
$lng = isset($input['lng']) && is_numeric($input['lng']) ? (float) $input['lng'] : null;

if ($eventId <= 0) {
    bgi_mobile_error('Please select an event.');
}

$scopeFilter = bgi_scope_filter_sql('idara', 'mohalla');
$scopeSql = $scopeFilter[0];
$scopeTypes = $scopeFilter[1];
$scopeParams = $scopeFilter[2];

$eventSql = "SELECT id, event_name, COALESCE(event_code, '') AS event_code,
                    DATE_FORMAT(event_date, '%Y-%m-%d') AS event_date,
                    COALESCE(DATE_FORMAT(reporting_time, '%H:%i:%s'), '') AS reporting_time,
                    idara, mohalla, latitude, longitude, radius_meters
             FROM events WHERE id = ?";
if ($scopeSql !== '') {
    $eventSql .= ' AND ' . $scopeSql;
}
$eventSql .= ' LIMIT 1';

$eventStmt = $conn->prepare($eventSql);
if (!$eventStmt) {
    bgi_mobile_error('Unable to load the selected event.', 500);
}

bgi_mobile_bind_dynamic_params($eventStmt, 'i' . $scopeTypes, array_merge([$eventId], $scopeParams));
$eventStmt->execute();
$event = $eventStmt->get_result()->fetch_assoc();
$eventStmt->close();

if (!$event) {
    bgi_mobile_error('The selected event is not available.', 404);
}

$dupStmt = $conn->prepare("SELECT id FROM attendance WHERE event_id = ? AND its_id = ? LIMIT 1");
if (!$dupStmt) {
    bgi_mobile_error('Unable to validate check-in.', 500);
}
$dupStmt->bind_param('is', $eventId, $itsId);
$dupStmt->execute();
$alreadyIn = (bool) $dupStmt->get_result()->fetch_assoc();
$dupStmt->close();

if ($alreadyIn) {
    bgi_mobile_error('You have already checked in for this event.');
}

// Check-in time window: 30 min before → 1 hour after reporting_time
$tw_base  = ($event['event_date'] ?? '') !== '' ? $event['event_date'] : date('Y-m-d');
$tw_time  = ($event['reporting_time'] ?? '') !== '' ? $event['reporting_time'] : '00:00:00';
$tw_start = strtotime($tw_base . ' ' . $tw_time) - 1800;
$tw_end   = strtotime($tw_base . ' ' . $tw_time) + 3600;
if (time() < $tw_start || time() > $tw_end) {
    bgi_mobile_error('Check-in window: ' . date('H:i', $tw_start) . ' – ' . date('H:i', $tw_end) . '. Check-in is not open yet or has already closed.');
}

$isRemote = 0;
$distanceM = null;
$eventLat = isset($event['latitude']) && $event['latitude'] !== null ? (float) $event['latitude'] : null;
$eventLng = isset($event['longitude']) && $event['longitude'] !== null ? (float) $event['longitude'] : null;
$eventRadius = isset($event['radius_meters']) && $event['radius_meters'] !== null ? (int) $event['radius_meters'] : 200;

if ($eventLat !== null && $eventLng !== null && $lat !== null && $lng !== null) {
    $distanceM = bgi_geo_distance_meters($lat, $lng, $eventLat, $eventLng);
    if ($distanceM > $eventRadius) {
        $isRemote = 1;
    }
}

// ── 50 m geofence enforcement ─────────────────────────────────────────────────
// If the event has a geofence set, the member MUST be within 50 m to check in.
// No location sent = also blocked (prevents bypassing by withholding GPS).
define('BGI_MAX_CHECKIN_DISTANCE_M', 50);
if ($eventLat !== null && $eventLng !== null) {
    if ($lat === null || $lng === null) {
        bgi_mobile_error('This event requires your location. Please enable GPS and try again.');
    }
    if ($distanceM === null || $distanceM > BGI_MAX_CHECKIN_DISTANCE_M) {
        $dist = $distanceM !== null ? (int) round($distanceM) : '?';
        bgi_mobile_error("Check-in blocked. You are {$dist} m away from the event venue. Check-in is only allowed within 50 m.");
    }
}
// ─────────────────────────────────────────────────────────────────────────────

if ($lat !== null && $lng !== null && bgi_is_outside_kuwait($lat, $lng)) {
    $finalStatus = 'Out of Kuwait';
} else {
    $reportingBase = ($event['event_date'] ?? '') !== '' ? $event['event_date'] : date('Y-m-d');
    $reportingTime = ($event['reporting_time'] ?? '') !== '' ? $event['reporting_time'] : '00:00:00';
    $reportingTimestamp = strtotime($reportingBase . ' ' . $reportingTime);
    $finalStatus = ($reportingTimestamp !== false && time() > $reportingTimestamp) ? 'Late' : 'Present';
}

$memberStmt = $conn->prepare("SELECT member_name, bgi_id, idara, mohalla FROM members WHERE its_id = ? LIMIT 1");
if (!$memberStmt) {
    bgi_mobile_error('Unable to load member record.', 500);
}
$memberStmt->bind_param('s', $itsId);
$memberStmt->execute();
$member = $memberStmt->get_result()->fetch_assoc();
$memberStmt->close();

if (!$member) {
    bgi_mobile_error('Member record not found.', 404);
}

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

if (bgi_mobile_column_exists($conn, 'attendance', 'checkin_source')) {
    $insertColumns[] = 'checkin_source';
    $insertPlaceholders[] = '?';
    $insertTypes .= 's';
    $insertParams[] = 'self';
}

if (bgi_mobile_column_exists($conn, 'attendance', 'is_remote')) {
    $insertColumns[] = 'is_remote';
    $insertPlaceholders[] = '?';
    $insertTypes .= 'i';
    $insertParams[] = $isRemote;
}

if ($lat !== null && bgi_mobile_column_exists($conn, 'attendance', 'checkin_lat')) {
    $insertColumns[] = 'checkin_lat';
    $insertPlaceholders[] = '?';
    $insertTypes .= 'd';
    $insertParams[] = $lat;
}

if ($lng !== null && bgi_mobile_column_exists($conn, 'attendance', 'checkin_lng')) {
    $insertColumns[] = 'checkin_lng';
    $insertPlaceholders[] = '?';
    $insertTypes .= 'd';
    $insertParams[] = $lng;
}

if ($distanceM !== null && bgi_mobile_column_exists($conn, 'attendance', 'checkin_distance_m')) {
    $insertColumns[] = 'checkin_distance_m';
    $insertPlaceholders[] = '?';
    $insertTypes .= 'i';
    $insertParams[] = $distanceM;
}

$insertSql = sprintf(
    'INSERT INTO attendance (%s) VALUES (%s)',
    implode(', ', $insertColumns),
    implode(', ', $insertPlaceholders)
);

$insertStmt = $conn->prepare($insertSql);
if (!$insertStmt) {
    bgi_mobile_error('Unable to save check-in.', 500);
}

bgi_mobile_bind_dynamic_params($insertStmt, $insertTypes, $insertParams);

if (!$insertStmt->execute()) {
    $insertError = $insertStmt->error;
    $insertStmt->close();
    error_log('Mobile checkin insert failed: ' . $insertError);
    bgi_mobile_error('Check-in could not be saved right now.', 500);
}
$insertStmt->close();

bgi_mobile_respond([
    'ok' => true,
    'message' => $isRemote ? 'Checked in remotely.' : 'Checked in successfully.',
    'recordedStatus' => $finalStatus,
    'eventName' => (string) ($event['event_name'] ?? ''),
    'isRemote' => (bool) $isRemote,
    'distanceMeters' => $distanceM,
]);
