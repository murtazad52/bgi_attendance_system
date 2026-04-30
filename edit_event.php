<?php
include('session_check.php');
include('db.php');

$isScopedAdmin = !bgi_is_super_admin();
$isPairScopedAdmin = bgi_is_idara_admin();
$isMohallaAdmin = bgi_is_mohalla_admin();

if (!bgi_can_manage_events()) {
    header('Location: ' . bgi_home_path_for_current_user());
    exit;
}

$scopeOptions = bgi_get_scope_options($conn);
$validScopePairs = [];
$idaraOptions = [];

foreach ($scopeOptions as $scopeOption) {
    $idara = bgi_normalize_scope_value($scopeOption['idara'] ?? '', BGI_DEFAULT_IDARA);
    $mohalla = bgi_normalize_scope_value($scopeOption['mohalla'] ?? '', BGI_DEFAULT_MOHALLA);

    if ($isMohallaAdmin && strcasecmp($mohalla, bgi_current_scope_mohalla()) !== 0) {
        continue;
    }

    $validScopePairs[$idara . '||' . $mohalla] = true;
    $idaraOptions[$idara] = $idara;
}

if (empty($_GET['id']) || !filter_var($_GET['id'], FILTER_VALIDATE_INT)) {
    header('Location: admin_events.php');
    exit;
}

$id = (int) $_GET['id'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $event_name = trim($_POST['event_name'] ?? '');
    $event_date = $_POST['event_date'] ?? '';
    $reporting_time = $_POST['reporting_time'] ?? '';
    if ($isMohallaAdmin) {
        $selectedIdara = bgi_normalize_scope_value($_POST['idara'] ?? '', '');
        $selectedMohalla = bgi_current_scope_mohalla();
    } else {
        $selectedIdara = $isScopedAdmin
            ? bgi_current_scope_idara()
            : bgi_normalize_scope_value($_POST['idara'] ?? '', BGI_DEFAULT_IDARA);
        $selectedMohalla = $isScopedAdmin
            ? bgi_current_scope_mohalla()
            : bgi_normalize_scope_value($_POST['mohalla'] ?? '', BGI_DEFAULT_MOHALLA);
    }

    if ($event_name === '' || $event_date === '' || $reporting_time === '') {
        $error = 'All fields are required.';
    } elseif (!$isScopedAdmin && !isset($validScopePairs[$selectedIdara . '||' . $selectedMohalla])) {
        $error = 'Please choose a valid saved Idara and Mohalla pair.';
    } elseif (!bgi_register_scope($conn, $selectedIdara, $selectedMohalla, $scopeError)) {
        $error = $scopeError ?: 'Invalid Idara and Mohalla mapping.';
    } else {
        $updateStmt = $conn->prepare("UPDATE events SET event_name = ?, idara = ?, mohalla = ?, event_date = ?, reporting_time = ? WHERE id = ?");
        $updateStmt->bind_param("sssssi", $event_name, $selectedIdara, $selectedMohalla, $event_date, $reporting_time, $id);

        if ($updateStmt->execute()) {
            $updateStmt->close();
            $conn->close();
            header('Location: admin_events.php');
            exit;
        }

        $error = 'Error: ' . $updateStmt->error;
        $updateStmt->close();
    }
}

$selectStmt = $conn->prepare("SELECT * FROM events WHERE id = ? LIMIT 1");
$selectStmt->bind_param("i", $id);
$selectStmt->execute();
$result = $selectStmt->get_result();
$event = $result->fetch_assoc();
$selectStmt->close();

if (!$event) {
    $conn->close();
    header('Location: admin_events.php');
    exit;
}

if ($isScopedAdmin && !bgi_scope_matches_current($event['idara'] ?? '', $event['mohalla'] ?? '')) {
    $conn->close();
    $_SESSION['flash_message'] = 'You can edit only events inside your assigned Idara and Mohalla.';
    $_SESSION['flash_type'] = 'error';
    header('Location: admin_events.php');
    exit;
}

$selectedIdara = bgi_normalize_scope_value($event['idara'] ?? '', BGI_DEFAULT_IDARA);
$selectedMohalla = bgi_normalize_scope_value($event['mohalla'] ?? '', BGI_DEFAULT_MOHALLA);

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Event</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f6f8; }
        .container { max-width: 500px; margin: 50px auto; background: white; padding: 30px; border-radius: 10px; }
        h2 { text-align: center; }
        form { margin-top: 20px; }
        input[type="text"], input[type="date"], input[type="time"] {
            width: 100%; padding: 10px; margin-bottom: 20px; border-radius: 5px; border: 1px solid #ccc;
        }
        button {
            width: 100%; background: #2E8B57; color: white; padding: 10px; border: none; border-radius: 5px;
        }
        button:hover { background: #246B46; }
        .error-message {
            color: #842029;
            background: #fdecea;
            border: 1px solid #f5c2c7;
            padding: 10px 12px;
            border-radius: 6px;
            margin-bottom: 15px;
        }
    </style>
    <link rel="stylesheet" href="app.css">
</head>
<body class="app-page page-form">

<div class="container">
    <a href="admin_events.php" class="btn secondary back-btn">Back to Manage Events</a>
    <h2>Edit Event</h2>
    <p class="page-intro">Adjust event details while keeping the attendance workflow unchanged.</p>

    <?php if ($error !== ''): ?>
        <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="post">
        <label>Event Code</label>
        <input type="text" value="<?php echo htmlspecialchars($event['event_code'] ?? ''); ?>" readonly>
        <label>Event Name</label>
        <input type="text" name="event_name" value="<?php echo htmlspecialchars($event_name ?? $event['event_name']); ?>" required>
        <?php if ($isPairScopedAdmin): ?>
            <label>Idara</label>
            <input type="text" value="<?php echo htmlspecialchars($selectedIdara); ?>" readonly>
            <input type="hidden" name="idara" value="<?php echo htmlspecialchars($selectedIdara); ?>">
            <label>Mohalla</label>
            <input type="text" value="<?php echo htmlspecialchars($selectedMohalla); ?>" readonly>
            <input type="hidden" name="mohalla" value="<?php echo htmlspecialchars($selectedMohalla); ?>">
        <?php elseif ($isMohallaAdmin): ?>
            <label>Idara</label>
            <select name="idara" required>
                <option value="">-- Select Idara --</option>
                <?php foreach ($idaraOptions as $idaraOption): ?>
                    <option value="<?php echo htmlspecialchars($idaraOption); ?>" <?php echo $selectedIdara === $idaraOption ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($idaraOption); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <label>Mohalla</label>
            <input type="text" value="<?php echo htmlspecialchars($selectedMohalla); ?>" readonly>
            <input type="hidden" name="mohalla" value="<?php echo htmlspecialchars($selectedMohalla); ?>">
        <?php else: ?>
            <label>Idara</label>
            <select name="idara" required>
                <option value="">-- Select Idara --</option>
                <?php foreach ($scopeOptions as $scopeOption): ?>
                    <option value="<?php echo htmlspecialchars($scopeOption['idara']); ?>" <?php echo $selectedIdara === $scopeOption['idara'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($scopeOption['idara']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <label>Mohalla</label>
            <select name="mohalla" required>
                <option value="">-- Select Mohalla --</option>
                <?php foreach ($scopeOptions as $scopeOption): ?>
                    <option value="<?php echo htmlspecialchars($scopeOption['mohalla']); ?>" <?php echo $selectedMohalla === $scopeOption['mohalla'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($scopeOption['mohalla']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <label>Event Date</label>
        <input type="date" name="event_date" value="<?php echo htmlspecialchars($event_date ?? $event['event_date']); ?>" required>
        <label>Reporting Time</label>
        <input type="time" name="reporting_time" value="<?php echo htmlspecialchars($reporting_time ?? $event['reporting_time']); ?>" required>
        <button type="submit">Update Event</button>
    </form>
</div>

</body>
</html>
