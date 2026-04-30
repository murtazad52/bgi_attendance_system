<?php
require_once __DIR__ . '/auth.php';

if (!bgi_is_logged_in()) {
    header('Location: login.php');
    exit;
}

header('Location: report_members.php');
exit;
