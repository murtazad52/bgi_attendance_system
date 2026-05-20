<?php
/**
 * AJAX endpoint: returns members for a given idara/mohalla scope.
 * Used by add_event.php to populate the khidmat assignment checkboxes.
 */
include('session_check.php');
include('db.php');

if (!bgi_can_manage_events()) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$idara   = trim($_GET['idara']   ?? '');
$mohalla = trim($_GET['mohalla'] ?? '');

if ($idara === '' || $mohalla === '') {
    echo json_encode(['members' => []]);
    exit;
}

// Scope check: scoped admins can only query within their own scope
if (bgi_is_idara_admin()) {
    $allowedIdara   = bgi_current_scope_idara();
    $allowedMohalla = bgi_current_scope_mohalla();
    if (strcasecmp($idara, $allowedIdara) !== 0 || strcasecmp($mohalla, $allowedMohalla) !== 0) {
        echo json_encode(['members' => []]);
        exit;
    }
} elseif (bgi_is_mohalla_admin()) {
    $allowedMohalla = bgi_current_scope_mohalla();
    if (strcasecmp($mohalla, $allowedMohalla) !== 0) {
        echo json_encode(['members' => []]);
        exit;
    }
}

$stmt = $conn->prepare(
    "SELECT id, its_id, member_name, bgi_id, position
     FROM members
     WHERE idara = ? AND mohalla = ?
     ORDER BY member_name ASC"
);
if (!$stmt) {
    echo json_encode(['members' => []]);
    exit;
}

$stmt->bind_param('ss', $idara, $mohalla);
$stmt->execute();
$result = $stmt->get_result();

$members = [];
while ($row = $result->fetch_assoc()) {
    $members[] = [
        'id'          => (int) $row['id'],
        'its_id'      => $row['its_id'],
        'member_name' => $row['member_name'],
        'bgi_id'      => $row['bgi_id'] ?? '',
        'position'    => $row['position'] ?? '',
    ];
}
$stmt->close();
mysqli_close($conn);

echo json_encode(['members' => $members]);
