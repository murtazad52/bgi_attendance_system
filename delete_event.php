<?php
require_once __DIR__ . '/auth.php';
include('db.php');

bgi_require_roles([BGI_ROLE_SUPER_ADMIN]);

if (!bgi_can_delete()) {
    bgi_set_flash('Only Super Admin can delete events.', 'error');
    header('Location: admin_events.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

if (empty($_SESSION['csrf_token']) || !isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    bgi_set_flash('Invalid request token. Please try again.', 'error');
    header('Location: admin_events.php');
    exit;
}

$eventId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$eventId) {
    bgi_set_flash('Invalid event ID.', 'error');
    header('Location: admin_events.php');
    exit;
}

$checkStmt = $conn->prepare("SELECT id FROM events WHERE id = ? LIMIT 1");
$checkStmt->bind_param("i", $eventId);
$checkStmt->execute();
$result = $checkStmt->get_result();

if ($result->num_rows === 0) {
    $checkStmt->close();
    bgi_set_flash('Event not found.', 'error');
    header('Location: admin_events.php');
    exit;
}

$checkStmt->close();

mysqli_begin_transaction($conn);

try {
    $deleteAttendanceStmt = $conn->prepare("DELETE FROM attendance WHERE event_id = ?");
    $deleteAttendanceStmt->bind_param("i", $eventId);

    if (!$deleteAttendanceStmt->execute()) {
        throw new Exception($deleteAttendanceStmt->error);
    }

    $deleteEventStmt = $conn->prepare("DELETE FROM events WHERE id = ?");
    $deleteEventStmt->bind_param("i", $eventId);

    if (!$deleteEventStmt->execute()) {
        throw new Exception($deleteEventStmt->error);
    }

    mysqli_commit($conn);
    bgi_set_flash('Event deleted successfully.', 'success');

    $deleteAttendanceStmt->close();
    $deleteEventStmt->close();
} catch (Throwable $e) {
    mysqli_rollback($conn);
    bgi_set_flash('Error deleting event. Please try again.', 'error');

    if (isset($deleteAttendanceStmt) && $deleteAttendanceStmt instanceof mysqli_stmt) {
        $deleteAttendanceStmt->close();
    }
    if (isset($deleteEventStmt) && $deleteEventStmt instanceof mysqli_stmt) {
        $deleteEventStmt->close();
    }
}

$conn->close();
header('Location: admin_events.php');
exit;
?>
