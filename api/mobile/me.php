<?php
require_once __DIR__ . '/bootstrap.php';

bgi_mobile_require_login();

bgi_mobile_respond([
    'ok' => true,
    'user' => bgi_mobile_current_user_payload(),
]);
