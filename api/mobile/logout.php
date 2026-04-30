<?php
require_once __DIR__ . '/bootstrap.php';

bgi_clear_auth_session();

bgi_mobile_respond([
    'ok' => true,
    'message' => 'Logged out successfully.',
]);
