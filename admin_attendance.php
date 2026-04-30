<?php
include('session_check.php');

include('db.php'); // your DB connection
require_once __DIR__ . '/mailer.php';

if (!bgi_can_take_attendance()) {
    header('Location: ' . bgi_home_path_for_current_user());
    exit;
}

// helper: check if a column exists
function column_exists($conn, $table, $column) {
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '" . mysqli_real_escape_string($conn, $column) . "'");
    if (!$res) return false;
    return mysqli_num_rows($res) > 0;
}

// ensure required attendance columns exist (adds them if missing)
function ensure_attendance_columns($conn) {
    // attendance_time DATETIME
    if (!column_exists($conn, 'attendance', 'attendance_time')) {
        $q = "ALTER TABLE attendance ADD COLUMN attendance_time DATETIME NULL";
        mysqli_query($conn, $q);
    }
    // status ENUM
    if (!column_exists($conn, 'attendance', 'status')) {
        $q = "ALTER TABLE attendance ADD COLUMN `status` ENUM('Present','Late','Absent','Out of Kuwait') DEFAULT 'Present'";
        mysqli_query($conn, $q);
    }
    // remark
    if (!column_exists($conn, 'attendance', 'remark')) {
        $q = "ALTER TABLE attendance ADD COLUMN `remark` VARCHAR(255) NULL";
        mysqli_query($conn, $q);
    }
}

// call ensure (wrap in try/catch style handling by checking for errors later)
ensure_attendance_columns($conn);

$isScopedAdmin = !bgi_is_super_admin();
$scopeLabel = bgi_current_scope_label();

// Fetch events for dropdown
if (bgi_is_mohalla_admin()) {
    $eventsStmt = $conn->prepare("SELECT id, event_name, event_code, reporting_time, idara, mohalla FROM events WHERE mohalla = ? ORDER BY event_date DESC, reporting_time ASC");
    $scopeMohalla = bgi_current_scope_mohalla();
    $eventsStmt->bind_param("s", $scopeMohalla);
    $eventsStmt->execute();
    $events_result = $eventsStmt->get_result();
} elseif ($isScopedAdmin) {
    $eventsStmt = $conn->prepare("SELECT id, event_name, event_code, reporting_time, idara, mohalla FROM events WHERE idara = ? AND mohalla = ? ORDER BY event_date DESC, reporting_time ASC");
    $scopeIdara = bgi_current_scope_idara();
    $scopeMohalla = bgi_current_scope_mohalla();
    $eventsStmt->bind_param("ss", $scopeIdara, $scopeMohalla);
    $eventsStmt->execute();
    $events_result = $eventsStmt->get_result();
} else {
    $events_result = mysqli_query($conn, "SELECT id, event_name, event_code, reporting_time, idara, mohalla FROM events ORDER BY event_date DESC, reporting_time ASC");
}
if (!$events_result) {
    die('Error fetching events: ' . mysqli_error($conn));
}

$attendance_message = '';
$attendance_error = '';
$attendance_warning = '';
$allowed_statuses = ['Present', 'Late', 'Absent', 'Out of Kuwait'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['event_id'])) {
    $event_id = intval($_POST['event_id']);
    $its_id = isset($_POST['its_id']) ? trim(mysqli_real_escape_string($conn, $_POST['its_id'])) : '';
    $status_input = isset($_POST['status']) ? trim(mysqli_real_escape_string($conn, $_POST['status'])) : '';
    $remark_input = isset($_POST['remark']) ? trim(mysqli_real_escape_string($conn, $_POST['remark'])) : '';

    if ($event_id <= 0) {
        $attendance_error = 'Please select an event.';
    } elseif ($its_id === '') {
        $attendance_error = 'Please enter ITS ID.';
    } elseif (!preg_match('/^\d{8}$/', $its_id)) {
        $attendance_error = 'ITS ID must be exactly 8 digits.';
    } elseif ($status_input !== '' && !in_array($status_input, $allowed_statuses, true)) {
        $attendance_error = 'Invalid status selected.';
    } elseif (strlen($remark_input) > 255) {
        $attendance_error = 'Remark must be 255 characters or fewer.';
    } else {
        // find member by ITS ID
        if (bgi_is_mohalla_admin()) {
            $memberStmt = $conn->prepare("SELECT id, member_name, bgi_id, idara, mohalla, email, position, team_leader_its_id, captain_its_id FROM members WHERE its_id = ? AND mohalla = ? LIMIT 1");
            $memberScopeMohalla = bgi_current_scope_mohalla();
            $memberStmt->bind_param("ss", $its_id, $memberScopeMohalla);
        } elseif ($isScopedAdmin) {
            $memberStmt = $conn->prepare("SELECT id, member_name, bgi_id, idara, mohalla, email, position, team_leader_its_id, captain_its_id FROM members WHERE its_id = ? AND idara = ? AND mohalla = ? LIMIT 1");
            $memberScopeIdara = bgi_current_scope_idara();
            $memberScopeMohalla = bgi_current_scope_mohalla();
            $memberStmt->bind_param("sss", $its_id, $memberScopeIdara, $memberScopeMohalla);
        } else {
            $memberStmt = $conn->prepare("SELECT id, member_name, bgi_id, idara, mohalla, email, position, team_leader_its_id, captain_its_id FROM members WHERE its_id = ? LIMIT 1");
            $memberStmt->bind_param("s", $its_id);
        }

        $memberStmt->execute();
        $member_q = $memberStmt->get_result();
        if (!$member_q) {
            $attendance_error = 'Unable to search for the member right now.';
        } elseif (mysqli_num_rows($member_q) === 0) {
            $attendance_error = $isScopedAdmin
                ? 'ITS ID not found inside your assigned Idara and Mohalla.'
                : 'ITS ID not found in members.';
        } else {
            $member = mysqli_fetch_assoc($member_q);
            $member_id = $member['id'];
            $member_name = $member['member_name'];
            $bgi_id = $member['bgi_id'] ?? '';
            $member_idara = $member['idara'] ?? BGI_DEFAULT_IDARA;
            $member_mohalla = $member['mohalla'] ?? BGI_DEFAULT_MOHALLA;

            // prevent duplicates
            $check = mysqli_query($conn, "SELECT id FROM attendance WHERE event_id = $event_id AND its_id = '" . mysqli_real_escape_string($conn, $its_id) . "' LIMIT 1");
            if (!$check) {
                $attendance_error = 'Unable to verify existing attendance right now.';
            } elseif (mysqli_num_rows($check) > 0) {
                $attendance_error = 'Attendance already recorded for this member at this event.';
            } else {
                // compute default status based on event reporting time if user didn't select a status
                if (bgi_is_mohalla_admin()) {
                    $eventStmt = $conn->prepare("SELECT event_name, event_code, event_date, reporting_time, idara, mohalla FROM events WHERE id = ? AND mohalla = ? LIMIT 1");
                    $eventScopeMohalla = bgi_current_scope_mohalla();
                    $eventStmt->bind_param("is", $event_id, $eventScopeMohalla);
                } elseif ($isScopedAdmin) {
                    $eventStmt = $conn->prepare("SELECT event_name, event_code, event_date, reporting_time, idara, mohalla FROM events WHERE id = ? AND idara = ? AND mohalla = ? LIMIT 1");
                    $eventScopeIdara = bgi_current_scope_idara();
                    $eventScopeMohalla = bgi_current_scope_mohalla();
                    $eventStmt->bind_param("iss", $event_id, $eventScopeIdara, $eventScopeMohalla);
                } else {
                    $eventStmt = $conn->prepare("SELECT event_name, event_code, event_date, reporting_time, idara, mohalla FROM events WHERE id = ? LIMIT 1");
                    $eventStmt->bind_param("i", $event_id);
                }
                $eventStmt->execute();
                $eventResult = $eventStmt->get_result();
                $reporting_time = null;
                if ($eventResult && $eventResult->num_rows > 0) {
                    $ev = $eventResult->fetch_assoc();
                    $reporting_time = $ev['reporting_time'];
                    $event_idara = $ev['idara'] ?? BGI_DEFAULT_IDARA;
                    $event_mohalla = $ev['mohalla'] ?? BGI_DEFAULT_MOHALLA;
                } else {
                    $event_idara = '';
                    $event_mohalla = '';
                }
                $eventStmt->close();

                if ($reporting_time === null) {
                    $attendance_error = 'The selected event could not be found inside your allowed scope.';
                } elseif (strcasecmp($member_idara, $event_idara) !== 0 || strcasecmp($member_mohalla, $event_mohalla) !== 0) {
                    $attendance_error = 'This member belongs to a different Idara or Mohalla than the selected event.';
                } else {
                    // compute current server time and default status
                    $now_dt = date('Y-m-d H:i:s');
                    $computed_status = 'Present';
                    if ($reporting_time !== null && $reporting_time !== '') {
                        // compare only time-of-day (use server date)
                        $report_dt = date('Y-m-d') . ' ' . $reporting_time;
                        if (strtotime($now_dt) <= strtotime($report_dt)) {
                            $computed_status = 'Present'; // we'll treat as present/ontime by default
                        } else {
                            $computed_status = 'Late';
                        }
                    }

                    // choose final status: user's selection overrides computed
                    $final_status = $status_input !== '' ? $status_input : $computed_status;

                    // Insert attendance — safe columns ensured earlier
                    $ins_cols = [];
                    $ins_vals = [];

                    // always add these
                    $ins_cols[] = 'event_id';      $ins_vals[] = $event_id;
                    $ins_cols[] = 'member_id';     $ins_vals[] = $member_id;
                    $ins_cols[] = 'member_name';   $ins_vals[] = "'" . mysqli_real_escape_string($conn, $member_name) . "'";
                    $ins_cols[] = 'its_id';        $ins_vals[] = "'" . mysqli_real_escape_string($conn, $its_id) . "'";
                    $ins_cols[] = 'bgi_id';        $ins_vals[] = "'" . mysqli_real_escape_string($conn, $bgi_id) . "'";
                    $ins_cols[] = 'idara';         $ins_vals[] = "'" . mysqli_real_escape_string($conn, $member_idara) . "'";
                    $ins_cols[] = 'mohalla';       $ins_vals[] = "'" . mysqli_real_escape_string($conn, $member_mohalla) . "'";

                    // add attendance_date when the current schema supports it
                    if (column_exists($conn, 'attendance', 'attendance_date')) {
                        $ins_cols[] = 'attendance_date';
                        $ins_vals[] = "CURDATE()";
                    }

                    // status and remark (we ensured they exist above)
                    if (column_exists($conn, 'attendance', 'status')) {
                        $ins_cols[] = 'status';
                        $ins_vals[] = "'" . mysqli_real_escape_string($conn, $final_status) . "'";
                    }
                    if (column_exists($conn, 'attendance', 'remark')) {
                        $ins_cols[] = 'remark';
                        $ins_vals[] = "'" . mysqli_real_escape_string($conn, $remark_input) . "'";
                    }

                    $sql = "INSERT INTO attendance (" . implode(',', $ins_cols) . ") VALUES (" . implode(',', $ins_vals) . ")";
                    if (mysqli_query($conn, $sql)) {
                        $attendance_message = 'Attendance recorded successfully.';

                        if ($final_status === 'Absent') {
                            $notificationResult = bgi_send_absent_notification($conn, $member, $ev, $remark_input);
                            if (($notificationResult['status'] ?? '') === 'sent') {
                                $attendance_message .= ' Absent email sent to ' . (int) ($notificationResult['recipient_count'] ?? 0) . ' recipient(s).';
                            } elseif (($notificationResult['status'] ?? '') === 'failed') {
                                $attendance_warning = 'Attendance was recorded, but the absent email could not be sent. ' . ($notificationResult['error'] ?? '');
                            }
                        }
                    } else {
                        $attendance_error = 'Unable to record attendance right now. Please try again.';
                    }
                }
            }
        }

        $memberStmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Admin Attendance - BGI</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="app.css">
</head>
<body class="app-page">

<div class="topbar">
    <div><strong><?= htmlspecialchars(bgi_app_name()) ?></strong></div>
    <div>
        <a href="dashboard.php" class="back">← Dashboard</a>
    </div>
</div>

<div class="container">
    <h1>Record Attendance (ITS ID)</h1>
    <p class="page-intro">
        <?= $isScopedAdmin
            ? 'Capture attendance quickly by ITS ID inside your assigned scope: ' . htmlspecialchars($scopeLabel) . '.'
            : 'Capture attendance quickly by ITS ID, with optional status and remark overrides across all scopes.' ?>
    </p>

    <?php if ($attendance_message): ?>
        <div class="message success"><?= htmlspecialchars($attendance_message) ?></div>
    <?php endif; ?>
    <?php if ($attendance_error): ?>
        <div class="message error"><?= htmlspecialchars($attendance_error) ?></div>
    <?php endif; ?>
    <?php if ($attendance_warning): ?>
        <div class="message error"><?= htmlspecialchars($attendance_warning) ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-row">
            <div class="field-grow">
                <label for="event_id">Event</label>
                <select name="event_id" id="event_id" required>
                    <option value="">-- Select Event --</option>
                    <?php
                    // rewind result pointer and output events (we previously queried $events_result)
                    mysqli_data_seek($events_result, 0);
                    while ($er = mysqli_fetch_assoc($events_result)) {
                        $sel = (isset($_POST['event_id']) && intval($_POST['event_id']) === intval($er['id'])) ? 'selected' : '';
                        $scopeText = htmlspecialchars(($er['idara'] ?? BGI_DEFAULT_IDARA) . ' / ' . ($er['mohalla'] ?? BGI_DEFAULT_MOHALLA));
                        $eventCodeText = htmlspecialchars($er['event_code'] ?? '');
                        echo "<option value=\"" . intval($er['id']) . "\" $sel>" . $eventCodeText . " - " . htmlspecialchars($er['event_name']) . " (" . htmlspecialchars($er['reporting_time']) . ") - " . $scopeText . "</option>";
                    }
                    ?>
                </select>
            </div>

            <div class="field-fixed-220">
                <label for="its_id">ITS ID</label>
                <input type="text" name="its_id" id="its_id" placeholder="Enter ITS ID" value="<?= $attendance_message ? '' : (isset($_POST['its_id']) ? htmlspecialchars($_POST['its_id']) : '') ?>" required pattern="\d{8}" maxlength="8" inputmode="numeric">
            </div>
        </div>

        <div class="form-row">
            <div class="field-fixed-270">
                <label for="status">Status</label>
                <select name="status" id="status" class="small">
                    <option value="" <?= ($attendance_message || !isset($_POST['status']) || $_POST['status'] === '') ? 'selected' : '' ?>>-- Auto (Default) --</option>
                    <option value="Present" <?= (!$attendance_message && isset($_POST['status']) && $_POST['status'] === 'Present') ? 'selected' : '' ?>>Present</option>
                    <option value="Late" <?= (!$attendance_message && isset($_POST['status']) && $_POST['status'] === 'Late') ? 'selected' : '' ?>>Late</option>
                    <option value="Absent" <?= (!$attendance_message && isset($_POST['status']) && $_POST['status'] === 'Absent') ? 'selected' : '' ?>>Absent</option>
                    <option value="Out of Kuwait" <?= (!$attendance_message && isset($_POST['status']) && $_POST['status'] === 'Out of Kuwait') ? 'selected' : '' ?>>Out of Kuwait</option>
                </select>
                <div class="small-note">Leave empty to auto-calculate status using event reporting time.</div>
            </div>

            <div class="field-grow">
                <label for="remark">Remark (optional)</label>
                <textarea name="remark" id="remark" rows="2" maxlength="255" placeholder="Optional note (e.g., medical leave, late due to traffic)"><?= isset($_POST['remark']) ? htmlspecialchars($_POST['remark']) : '' ?></textarea>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn">Record Attendance</button>
            <a href="admin_attendance.php" class="btn secondary">Clear</a>
        </div>
    </form>
</div>

</body>
</html>
