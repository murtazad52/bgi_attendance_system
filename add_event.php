<?php
include('session_check.php');

// Include database connection
include('db.php');

$isScopedAdmin = !bgi_is_super_admin();
$isPairScopedAdmin = bgi_is_idara_admin();
$isMohallaAdmin = bgi_is_mohalla_admin();

if (!bgi_can_manage_events()) {
    header('Location: ' . bgi_home_path_for_current_user());
    exit;
}

$scopeOptions = bgi_get_scope_options($conn);
$selectedIdara = $isPairScopedAdmin ? bgi_current_scope_idara() : '';
$selectedMohalla = $isScopedAdmin ? bgi_current_scope_mohalla() : '';
$idaraOptions = [];
$mohallaOptions = [];
$scopePairs = [];

foreach ($scopeOptions as $scopeOption) {
    $idara = bgi_normalize_scope_value($scopeOption['idara'] ?? '', BGI_DEFAULT_IDARA);
    $mohalla = bgi_normalize_scope_value($scopeOption['mohalla'] ?? '', BGI_DEFAULT_MOHALLA);

    if ($isMohallaAdmin && strcasecmp($mohalla, bgi_current_scope_mohalla()) !== 0) {
        continue;
    }

    $scopePairs[$idara . '||' . $mohalla] = ['idara' => $idara, 'mohalla' => $mohalla];
    $idaraOptions[$idara] = $idara;
    $mohallaOptions[$mohalla] = $mohalla;
}

ksort($idaraOptions);
ksort($mohallaOptions);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $event_name = trim($_POST['event_name'] ?? '');
    $event_date = trim($_POST['event_date'] ?? '');
    $reporting_time = trim($_POST['reporting_time'] ?? '');
    $selectedIdara = $isPairScopedAdmin
        ? bgi_current_scope_idara()
        : bgi_normalize_scope_value($_POST['idara'] ?? '', '');
    $selectedMohalla = $isScopedAdmin
        ? bgi_current_scope_mohalla()
        : bgi_normalize_scope_value($_POST['mohalla'] ?? '', '');
    $targetPairs = [];

    if ($event_name === '' || $event_date === '' || $reporting_time === '') {
        $error = "All fields are required.";
    } else {
        if ($isPairScopedAdmin) {
            $targetPairs[] = [
                'idara' => $selectedIdara,
                'mohalla' => $selectedMohalla,
            ];
        } elseif ($isMohallaAdmin) {
            if ($selectedIdara === '') {
                $targetPairs = array_values($scopePairs);
            } else {
                foreach ($scopePairs as $scopePair) {
                    if (
                        strcasecmp($scopePair['idara'], $selectedIdara) === 0 &&
                        strcasecmp($scopePair['mohalla'], $selectedMohalla) === 0
                    ) {
                        $targetPairs[] = $scopePair;
                    }
                }
            }

            if ($targetPairs === []) {
                $error = "No saved Idara mapping was found inside your Mohalla.";
            }
        } elseif ($selectedIdara === '' && $selectedMohalla === '') {
            $error = "Select at least one Idara or one Mohalla.";
        } elseif ($selectedIdara !== '' && $selectedMohalla !== '') {
            $scopeKey = $selectedIdara . '||' . $selectedMohalla;
            if (!isset($scopePairs[$scopeKey])) {
                $error = "Please choose a valid Idara and Mohalla pair.";
            } else {
                $targetPairs[] = $scopePairs[$scopeKey];
            }
        } elseif ($selectedIdara !== '') {
            foreach ($scopePairs as $scopePair) {
                if (strcasecmp($scopePair['idara'], $selectedIdara) === 0) {
                    $targetPairs[] = $scopePair;
                }
            }

            if ($targetPairs === []) {
                $error = "No Mohalla mappings were found for the selected Idara.";
            }
        } else {
            foreach ($scopePairs as $scopePair) {
                if (strcasecmp($scopePair['mohalla'], $selectedMohalla) === 0) {
                    $targetPairs[] = $scopePair;
                }
            }

            if ($targetPairs === []) {
                $error = "No Idara mappings were found for the selected Mohalla.";
            }
        }

        if (!isset($error)) {
            mysqli_begin_transaction($conn);

            try {
                $createdEvents = [];
                $skippedScopes = [];

                foreach ($targetPairs as $targetPair) {
                    $targetIdara = $targetPair['idara'];
                    $targetMohalla = $targetPair['mohalla'];

                    $duplicateStmt = $conn->prepare(
                        "SELECT id FROM events
                         WHERE event_name = ? AND idara = ? AND mohalla = ? AND event_date = ? AND reporting_time = ?
                         LIMIT 1"
                    );
                    if (!$duplicateStmt) {
                        throw new Exception('Unable to check for duplicate events.');
                    }

                    $duplicateStmt->bind_param("sssss", $event_name, $targetIdara, $targetMohalla, $event_date, $reporting_time);
                    $duplicateStmt->execute();
                    $duplicateExists = $duplicateStmt->get_result()->num_rows > 0;
                    $duplicateStmt->close();

                    if ($duplicateExists) {
                        $skippedScopes[] = $targetIdara . ' / ' . $targetMohalla;
                        continue;
                    }

                    $eventCode = bgi_generate_event_code($conn, $event_date);
                    $insertStmt = $conn->prepare(
                        "INSERT INTO events (event_name, event_code, idara, mohalla, event_date, reporting_time)
                         VALUES (?, ?, ?, ?, ?, ?)"
                    );
                    if (!$insertStmt) {
                        throw new Exception('Unable to prepare event creation.');
                    }

                    $insertStmt->bind_param("ssssss", $event_name, $eventCode, $targetIdara, $targetMohalla, $event_date, $reporting_time);
                    if (!$insertStmt->execute()) {
                        throw new Exception($insertStmt->error);
                    }
                    $insertStmt->close();

                    $createdEvents[] = $eventCode . ' (' . $targetIdara . ' / ' . $targetMohalla . ')';
                }

                if ($createdEvents === []) {
                    mysqli_rollback($conn);
                    $error = 'Matching event records already exist for the selected scope.';
                    if ($skippedScopes !== []) {
                        $error .= ' Checked: ' . implode(', ', $skippedScopes) . '.';
                    }
                } else {
                    mysqli_commit($conn);
                    $message = 'Created ' . count($createdEvents) . ' event record(s): ' . implode(', ', $createdEvents) . '.';
                    if ($skippedScopes !== []) {
                        $message .= ' Skipped existing scope(s): ' . implode(', ', $skippedScopes) . '.';
                    }
                    bgi_set_flash($message, 'success');
                    mysqli_close($conn);
                    header('Location: admin_events.php');
                    exit;
                }
            } catch (Throwable $e) {
                mysqli_rollback($conn);
                $error = "Error adding event. Please try again.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Event - <?= htmlspecialchars(bgi_app_name()) ?></title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f4f6f8;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }
        .form-container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            width: 400px;
        }
        .form-container h2 {
            text-align: center;
            margin-bottom: 25px;
            color: #2E8B57;
        }
        form label {
            display: block;
            margin-bottom: 8px;
            color: #333;
        }
        form input[type="text"],
        form input[type="date"],
        form input[type="time"] {
            width: 100%;
            padding: 10px;
            margin-bottom: 20px;
            border: 1px solid #ccc;
            border-radius: 5px;
        }
        .btn-submit {
            width: 100%;
            background-color: #2E8B57;
            color: white;
            padding: 12px;
            font-size: 16px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        .btn-submit:hover {
            background-color: #246B46;
        }
        .back-link {
            display: block;
            margin-top: 15px;
            text-align: center;
            color: #2E8B57;
            text-decoration: none;
            font-weight: bold;
        }
        .back-link:hover {
            text-decoration: underline;
        }
        .error-message {
            color: red;
            text-align: center;
            margin-bottom: 15px;
        }
    </style>
    <link rel="stylesheet" href="app.css">
</head>
<body class="app-page page-form">

<div class="form-container">
    <a href="admin_events.php" class="btn secondary back-btn">Back to Manage Events</a>
    <h2>Add New Event</h2>
    <p class="page-intro">
        <?= $isPairScopedAdmin
            ? 'Create a new event inside your current scope: ' . htmlspecialchars(bgi_current_scope_label()) . '.'
            : ($isMohallaAdmin
                ? 'Choose one Idara inside your assigned Mohalla, or leave Idara empty to create separate linked event rows for every Idara in ' . htmlspecialchars(bgi_current_scope_mohalla()) . '.'
                : 'Choose one Idara, one Mohalla, or both. One selection creates separate linked event rows across matching scopes, each with its own event code.') ?>
    </p>

    <?php
    if (isset($error)) {
        echo '<div class="error-message">' . htmlspecialchars($error) . '</div>';
    }
    ?>

    <form method="POST" action="">
        <label for="event_name">Event Name:</label>
        <input type="text" id="event_name" name="event_name" value="<?= htmlspecialchars($_POST['event_name'] ?? '') ?>" required>

        <?php if ($isPairScopedAdmin): ?>
            <label for="idara">Idara:</label>
            <input type="text" id="idara" value="<?= htmlspecialchars($selectedIdara) ?>" readonly>
            <input type="hidden" name="idara" value="<?= htmlspecialchars($selectedIdara) ?>">

            <label for="mohalla">Mohalla:</label>
            <input type="text" id="mohalla" value="<?= htmlspecialchars($selectedMohalla) ?>" readonly>
            <input type="hidden" name="mohalla" value="<?= htmlspecialchars($selectedMohalla) ?>">
        <?php elseif ($isMohallaAdmin): ?>
            <label for="idara">Idara:</label>
            <select id="idara" name="idara">
                <option value="">-- All Idaras In This Mohalla --</option>
                <?php foreach ($idaraOptions as $idaraOption): ?>
                    <option value="<?= htmlspecialchars($idaraOption) ?>" <?= $selectedIdara === $idaraOption ? 'selected' : '' ?>>
                        <?= htmlspecialchars($idaraOption) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="mohalla">Mohalla:</label>
            <input type="text" id="mohalla" value="<?= htmlspecialchars($selectedMohalla) ?>" readonly>
            <input type="hidden" name="mohalla" value="<?= htmlspecialchars($selectedMohalla) ?>">
            <div class="small-note">Leave Idara empty to create one separate event for every Idara inside your assigned Mohalla.</div>
        <?php else: ?>
            <label for="idara">Idara:</label>
            <select id="idara" name="idara">
                <option value="">-- Select Idara (optional) --</option>
                <?php foreach ($idaraOptions as $idaraOption): ?>
                    <option value="<?= htmlspecialchars($idaraOption) ?>" <?= $selectedIdara === $idaraOption ? 'selected' : '' ?>>
                        <?= htmlspecialchars($idaraOption) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="mohalla">Mohalla:</label>
            <select id="mohalla" name="mohalla">
                <option value="">-- Select Mohalla (optional) --</option>
                <?php foreach ($mohallaOptions as $mohallaOption): ?>
                    <option value="<?= htmlspecialchars($mohallaOption) ?>" <?= $selectedMohalla === $mohallaOption ? 'selected' : '' ?>>
                        <?= htmlspecialchars($mohallaOption) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="small-note">
                Choose both to create one event for one exact scope.
                Choose only Idara to create one separate event for every Mohalla in that Idara.
                Choose only Mohalla to create one separate event for every Idara in that Mohalla.
            </div>
        <?php endif; ?>

        <label for="event_date">Event Date:</label>
        <input type="date" id="event_date" name="event_date" value="<?= htmlspecialchars($_POST['event_date'] ?? '') ?>" required>

        <label for="reporting_time">Reporting Time:</label>
        <input type="time" id="reporting_time" name="reporting_time" value="<?= htmlspecialchars($_POST['reporting_time'] ?? '') ?>" required>

        <button type="submit" class="btn-submit">Add Event</button>
    </form>

</div>

</body>
</html>

<?php
// Close database connection
mysqli_close($conn);
?>
