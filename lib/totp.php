<?php
function bgi_totp_base32_decode(string $base32): string
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split(strtoupper($base32)) as $char) {
        $val = strpos($chars, $char);
        if ($val === false) {
            continue;
        }
        $bits .= str_pad(decbin($val), 5, '0', STR_PAD_LEFT);
    }
    $bytes = '';
    for ($i = 0; $i + 8 <= strlen($bits); $i += 8) {
        $bytes .= chr((int) bindec(substr($bits, $i, 8)));
    }
    return $bytes;
}

function bgi_totp_generate_secret(): string
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    for ($i = 0; $i < 16; $i++) {
        $secret .= $chars[random_int(0, 31)];
    }
    return $secret;
}

function bgi_totp_get_code(string $secret, int $timeStep): string
{
    $key = bgi_totp_base32_decode($secret);
    $msg = pack('N*', 0) . pack('N*', $timeStep);
    $hash = hash_hmac('sha1', $msg, $key, true);
    $offset = ord($hash[19]) & 0x0F;
    $code = (
        ((ord($hash[$offset]) & 0x7F) << 24) |
        ((ord($hash[$offset + 1]) & 0xFF) << 16) |
        ((ord($hash[$offset + 2]) & 0xFF) << 8) |
        (ord($hash[$offset + 3]) & 0xFF)
    ) % 1000000;
    return str_pad((string) $code, 6, '0', STR_PAD_LEFT);
}

function bgi_totp_verify(string $secret, string $code, int $window = 1): bool
{
    if (!preg_match('/^\d{6}$/', $code)) {
        return false;
    }
    $timeStep = (int) floor(time() / 30);
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(bgi_totp_get_code($secret, $timeStep + $i), $code)) {
            return true;
        }
    }
    return false;
}

function bgi_totp_uri(string $secret, string $username, string $issuer = 'BGI Attendance'): string
{
    $label = rawurlencode($issuer . ':' . $username);
    return 'otpauth://totp/' . $label . '?' . http_build_query([
        'secret'    => $secret,
        'issuer'    => $issuer,
        'algorithm' => 'SHA1',
        'digits'    => 6,
        'period'    => 30,
    ]);
}
