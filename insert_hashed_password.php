<?php
require_once __DIR__ . '/auth.php';

if (!bgi_is_logged_in()) {
    http_response_code(404);
    exit('Not Found');
}

if (!bgi_is_staff()) {
    header('Location: ' . bgi_home_path_for_current_user());
    exit;
}

header('Location: create_admin.php');
exit;
?>
