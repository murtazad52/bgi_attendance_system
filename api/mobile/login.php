<?php
require_once __DIR__ . '/bootstrap.php';

$input = bgi_mobile_input();
$loginType = isset($input['loginType']) ? trim((string) $input['loginType']) : 'admin';
$identifier = isset($input['identifier']) ? trim((string) $input['identifier']) : '';
$secret = isset($input['secret']) ? trim((string) $input['secret']) : '';

if (!in_array($loginType, ['admin', 'member'], true)) {
    $loginType = 'admin';
}

if ($identifier === '' || $secret === '') {
    bgi_mobile_error('Please complete all required fields.');
}

bgi_clear_auth_session();
session_regenerate_id(true);

if ($loginType === 'member') {
    if (!preg_match('/^\d{8}$/', $identifier)) {
        bgi_mobile_error('Members must sign in with an 8-digit ITS ID.');
    }

    $memberStmt = $conn->prepare("SELECT id, its_id, member_name, phone, idara, mohalla, position FROM members WHERE its_id = ? LIMIT 1");
    if (!$memberStmt) {
        bgi_mobile_error('Unable to sign in right now.', 500);
    }

    $memberStmt->bind_param("s", $identifier);
    $memberStmt->execute();
    $memberResult = $memberStmt->get_result();
    $member = $memberResult->fetch_assoc();
    $memberStmt->close();

    if (!$member || !hash_equals((string) ($member['phone'] ?? ''), $secret)) {
        bgi_mobile_error('Invalid ITS ID or phone number.', 401);
    }

    $_SESSION['member_logged_in'] = true;
    $_SESSION['user_role'] = BGI_ROLE_MEMBER;
    $_SESSION['member_id'] = (int) $member['id'];
    $_SESSION['member_name'] = (string) $member['member_name'];
    $_SESSION['member_its_id'] = (string) $member['its_id'];
    $_SESSION['member_position'] = bgi_normalize_member_position($member['position'] ?? BGI_POSITION_MEMBER);
    $_SESSION['username'] = (string) $member['member_name'];
    bgi_set_scope_session($member['idara'] ?? BGI_DEFAULT_IDARA, $member['mohalla'] ?? BGI_DEFAULT_MOHALLA);

    bgi_mobile_respond([
        'ok' => true,
        'user' => bgi_mobile_current_user_payload(),
    ]);
}

$adminStmt = $conn->prepare("SELECT id, username, password, role, idara, mohalla FROM admin_users WHERE username = ? LIMIT 1");
if (!$adminStmt) {
    bgi_mobile_error('Unable to sign in right now.', 500);
}

$adminStmt->bind_param("s", $identifier);
$adminStmt->execute();
$adminResult = $adminStmt->get_result();
$adminUser = $adminResult->fetch_assoc();
$adminStmt->close();

if (!$adminUser || !password_verify($secret, (string) $adminUser['password'])) {
    bgi_mobile_error('Invalid username or password.', 401);
}

$_SESSION['user_id'] = (int) $adminUser['id'];
$_SESSION['username'] = (string) $adminUser['username'];
$_SESSION['admin_logged_in'] = true;
$_SESSION['user_role'] = bgi_normalize_admin_role($adminUser['role'] ?? BGI_ROLE_IDARA_ADMIN);
bgi_set_scope_session($adminUser['idara'] ?? BGI_DEFAULT_IDARA, $adminUser['mohalla'] ?? BGI_DEFAULT_MOHALLA);

bgi_mobile_respond([
    'ok' => true,
    'user' => bgi_mobile_current_user_payload(),
]);
