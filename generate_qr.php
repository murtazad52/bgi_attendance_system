<?php
require_once __DIR__ . '/auth.php';

if (!bgi_is_staff()) {
    http_response_code(403);
    exit('Forbidden');
}

include('phpqrcode/qrlib.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bgi_id = trim($_POST['bgi_id']);
    $its_id = trim($_POST['its_id']);

    if (!$bgi_id || !$its_id) {
        echo "Both BGI ID and ITS ID are required.";
        exit;
    }

    // Format QR data
    $qr_data = "bgi_id:$bgi_id,its_id:$its_id";

    // File name
    $filename = 'qrcodes/' . $bgi_id . '_' . $its_id . '.png';
    if (!file_exists('qrcodes')) {
        mkdir('qrcodes', 0755, true);
    }

    // Generate QR
    QRcode::png($qr_data, $filename, QR_ECLEVEL_L, 5);

    echo "<h3>QR Code Generated</h3>";
    echo "<p>Data: <strong>$qr_data</strong></p>";
    echo "<img src='$filename' alt='QR Code'>";
    echo "<br><a href='$filename' download>Download QR Code</a>";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Generate QR</title>
</head>
<body>
    <h2>Generate QR Code for Attendance</h2>
    <form method="POST">
        <label>BGI ID:</label><br>
        <input type="text" name="bgi_id" required><br><br>
        <label>ITS ID:</label><br>
        <input type="text" name="its_id" required><br><br>
        <input type="submit" value="Generate QR">
    </form>
</body>
</html>
