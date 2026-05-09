<?php
require_once __DIR__ . '/auth.php';

bgi_require_roles(bgi_staff_roles(), 'adminlogin.php');
?>
