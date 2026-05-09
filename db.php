<?php
$configPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'bgi_attendance_system.php';

if (!is_file($configPath)) {
    die('Database configuration file not found.');
}

$config = require $configPath;

$servername = getenv('BGI_DB_HOST') ?: ($config['host'] ?? 'localhost');
$username = getenv('BGI_DB_USER') ?: ($config['username'] ?? '');
$password = getenv('BGI_DB_PASS') ?: ($config['password'] ?? '');
$database = getenv('BGI_DB_NAME') ?: ($config['database'] ?? '');

$conn = new mysqli($servername, $username, $password, $database);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->query("SET time_zone = '+03:00'");

if (function_exists('bgi_bootstrap_access_schema')) {
    bgi_bootstrap_access_schema($conn);
}
?>
