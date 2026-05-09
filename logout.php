<?php
require_once __DIR__ . '/auth.php';
$loginPage = (!bgi_is_member() && bgi_is_logged_in()) ? 'adminlogin.php' : 'login.php';
session_destroy();
header('Location: ' . $loginPage);
exit;
