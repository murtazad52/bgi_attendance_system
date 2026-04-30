<?php
require_once __DIR__ . '/auth.php';
include('db.php');

bgi_require_roles([BGI_ROLE_SUPER_ADMIN]);

if (!bgi_can_delete()) {
    bgi_set_flash('Only Super Admin can delete member records.', 'error');
    header('Location: admin_members.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

if (empty($_SESSION['csrf_token']) || !isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    bgi_set_flash('Invalid request token. Please try again.', 'error');
    header('Location: admin_members.php');
    exit;
}

$member_id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$member_id) {
    bgi_set_flash('Invalid member ID.', 'error');
    header('Location: admin_members.php');
    exit;
}

$checkStmt = $conn->prepare("SELECT id, its_id FROM members WHERE id = ? LIMIT 1");
$checkStmt->bind_param("i", $member_id);
$checkStmt->execute();
$result = $checkStmt->get_result();

if ($result->num_rows === 0) {
    $checkStmt->close();
    bgi_set_flash('Member not found.', 'error');
    header('Location: admin_members.php');
    exit;
}

$member = $result->fetch_assoc();
$memberItsId = (string) ($member['its_id'] ?? '');
$checkStmt->close();

mysqli_begin_transaction($conn);

try {
    $clearFollowersStmt = $conn->prepare("UPDATE members SET team_leader_its_id = NULL, captain_its_id = NULL WHERE team_leader_its_id = ?");
    $clearFollowersStmt->bind_param("s", $memberItsId);
    if (!$clearFollowersStmt->execute()) {
        throw new Exception($clearFollowersStmt->error);
    }
    $clearFollowersStmt->close();

    $clearCaptainFollowersStmt = $conn->prepare("UPDATE members SET captain_its_id = NULL WHERE captain_its_id = ?");
    $clearCaptainFollowersStmt->bind_param("s", $memberItsId);
    if (!$clearCaptainFollowersStmt->execute()) {
        throw new Exception($clearCaptainFollowersStmt->error);
    }
    $clearCaptainFollowersStmt->close();

    $deleteAttendanceStmt = $conn->prepare("DELETE FROM attendance WHERE member_id = ? OR its_id = ?");
    $deleteAttendanceStmt->bind_param("is", $member_id, $memberItsId);
    if (!$deleteAttendanceStmt->execute()) {
        throw new Exception($deleteAttendanceStmt->error);
    }
    $deletedAttendanceRows = $deleteAttendanceStmt->affected_rows;
    $deleteAttendanceStmt->close();

    $deleteStmt = $conn->prepare("DELETE FROM members WHERE id = ?");
    $deleteStmt->bind_param("i", $member_id);
    if (!$deleteStmt->execute()) {
        throw new Exception($deleteStmt->error);
    }
    $deleteStmt->close();

    mysqli_commit($conn);

    if ($deletedAttendanceRows > 0) {
        bgi_set_flash('Member deleted successfully, along with related attendance records.', 'success');
    } else {
        bgi_set_flash('Member deleted successfully.', 'success');
    }
} catch (Throwable $e) {
    mysqli_rollback($conn);
    bgi_set_flash('Error deleting member. Please try again.', 'error');
}
$conn->close();

header('Location: admin_members.php');
exit;
?>
