<?php
require_once __DIR__ . '/auth.php';
include('db.php');

bgi_require_roles([BGI_ROLE_SUPER_ADMIN]);

if (!bgi_is_super_admin()) {
    bgi_set_flash('Only the main admin account can delete admin users.', 'error');
    header('Location: manage_admins.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

if (empty($_SESSION['csrf_token']) || !isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    bgi_set_flash('Invalid request token. Please try again.', 'error');
    header('Location: manage_admins.php');
    exit;
}

$adminId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$adminId) {
    bgi_set_flash('Invalid admin user.', 'error');
    header('Location: manage_admins.php');
    exit;
}

$currentUserId = (int) ($_SESSION['user_id'] ?? 0);

$checkStmt = $conn->prepare("SELECT id, username, role, idara, mohalla FROM admin_users WHERE id = ? LIMIT 1");
$checkStmt->bind_param("i", $adminId);
$checkStmt->execute();
$result = $checkStmt->get_result();

if ($result->num_rows === 0) {
    $checkStmt->close();
    bgi_set_flash('Admin user not found.', 'error');
    header('Location: manage_admins.php');
    exit;
}

$adminUser = $result->fetch_assoc();
$checkStmt->close();

$targetUsername = (string) ($adminUser['username'] ?? '');
$targetRole = (string) ($adminUser['role'] ?? BGI_ROLE_ADMIN);
$targetIdara = bgi_normalize_scope_value($adminUser['idara'] ?? '', BGI_DEFAULT_IDARA);
$targetMohalla = bgi_normalize_scope_value($adminUser['mohalla'] ?? '', BGI_DEFAULT_MOHALLA);

if ($targetUsername === 'admin' || $targetRole === BGI_ROLE_SUPER_ADMIN) {
    bgi_set_flash('The main super admin account cannot be deleted.', 'error');
    header('Location: manage_admins.php');
    exit;
}

if ((int) ($adminUser['id'] ?? 0) === $currentUserId) {
    bgi_set_flash('You cannot delete the admin account currently signed in.', 'error');
    header('Location: manage_admins.php');
    exit;
}

mysqli_begin_transaction($conn);

try {
    $deleteStmt = $conn->prepare("DELETE FROM admin_users WHERE id = ?");
    $deleteStmt->bind_param("i", $adminId);
    if (!$deleteStmt->execute()) {
        throw new Exception($deleteStmt->error);
    }
    $deleteStmt->close();

    $scopeStillUsed = false;
    foreach (['admin_users', 'members', 'events', 'attendance'] as $table) {
        $usageStmt = $conn->prepare("SELECT 1 FROM `$table` WHERE idara = ? AND mohalla = ? LIMIT 1");
        if (!$usageStmt) {
            throw new Exception('Unable to verify scope usage.');
        }

        $usageStmt->bind_param("ss", $targetIdara, $targetMohalla);
        $usageStmt->execute();
        $usageResult = $usageStmt->get_result();
        $scopeStillUsed = $usageResult && $usageResult->num_rows > 0;
        $usageStmt->close();

        if ($scopeStillUsed) {
            break;
        }
    }

    $scopeRemoved = false;
    if (!$scopeStillUsed) {
        $cleanupStmt = $conn->prepare("DELETE FROM idara_mohalla_map WHERE idara = ? AND mohalla = ?");
        if (!$cleanupStmt) {
            throw new Exception('Unable to clean the unused scope.');
        }

        $cleanupStmt->bind_param("ss", $targetIdara, $targetMohalla);
        if (!$cleanupStmt->execute()) {
            throw new Exception($cleanupStmt->error);
        }
        $scopeRemoved = $cleanupStmt->affected_rows > 0;
        $cleanupStmt->close();
    }

    mysqli_commit($conn);

    $message = 'Admin user deleted successfully.';
    if ($scopeRemoved) {
        $message .= ' The unused ' . $targetIdara . ' / ' . $targetMohalla . ' scope was also removed.';
    }
    bgi_set_flash($message, 'success');
} catch (Throwable $e) {
    mysqli_rollback($conn);
    bgi_set_flash('Error deleting admin user. Please try again.', 'error');
}

$conn->close();
header('Location: manage_admins.php');
exit;
?>
