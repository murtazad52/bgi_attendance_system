<?php
require_once __DIR__ . '/auth.php';
include('db.php');

bgi_require_roles([BGI_ROLE_MEMBER]);

$myItsId   = bgi_current_member_its_id();
$myId      = bgi_current_member_id();
$myIdara   = bgi_current_scope_idara();
$myMohalla = bgi_current_scope_mohalla();
$isTL      = bgi_is_team_leader_member();
$isCaptain = bgi_is_captain_member();

$flash = null;

// ── POST: process check-in ───────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $postEventId = (int) ($_POST['event_id'] ?? 0);
    $targetItsId = trim($_POST['target_its_id'] ?? '');
    $lat = isset($_POST['lat']) && is_numeric($_POST['lat']) ? (float) $_POST['lat'] : null;
    $lng = isset($_POST['lng']) && is_numeric($_POST['lng']) ? (float) $_POST['lng'] : null;

    $evStmt = $conn->prepare(
        "SELECT id, event_name, DATE_FORMAT(event_date,'%Y-%m-%d') AS event_date,
                COALESCE(DATE_FORMAT(reporting_time,'%H:%i:%s'),'') AS reporting_time
         FROM events WHERE id = ? AND idara = ? AND mohalla = ? LIMIT 1"
    );
    $evStmt->bind_param('iss', $postEventId, $myIdara, $myMohalla);
    $evStmt->execute();
    $postEvent = $evStmt->get_result()->fetch_assoc();
    $evStmt->close();

    if (!$postEvent || $postEventId <= 0 || $targetItsId === '') {
        $flash = ['type' => 'error', 'msg' => 'Invalid request.'];
    } else {
        $tStmt = $conn->prepare(
            "SELECT id, its_id, member_name, bgi_id, idara, mohalla FROM members WHERE its_id = ? LIMIT 1"
        );
        $tStmt->bind_param('s', $targetItsId);
        $tStmt->execute();
        $tMember = $tStmt->get_result()->fetch_assoc();
        $tStmt->close();

        $allowed = false;
        if ($tMember) {
            if ($targetItsId === $myItsId) {
                $allowed = true;
            } elseif ($isTL) {
                $chk = $conn->prepare(
                    "SELECT 1 FROM members WHERE its_id = ? AND team_leader_its_id = ? LIMIT 1"
                );
                $chk->bind_param('ss', $targetItsId, $myItsId);
                $chk->execute();
                $allowed = (bool) $chk->get_result()->fetch_row();
                $chk->close();
            } elseif ($isCaptain) {
                $allowed = strcasecmp($tMember['idara'], $myIdara) === 0
                        && strcasecmp($tMember['mohalla'], $myMohalla) === 0;
            }
        }

        if (!$allowed) {
            $flash = ['type' => 'error', 'msg' => 'You are not authorised to check in this member.'];
        } else {
            $dup = $conn->prepare("SELECT id FROM attendance WHERE event_id = ? AND its_id = ? LIMIT 1");
            $dup->bind_param('is', $postEventId, $targetItsId);
            $dup->execute();
            $already = (bool) $dup->get_result()->fetch_row();
            $dup->close();

            if ($already) {
                $flash = ['type' => 'error', 'msg' => htmlspecialchars($tMember['member_name']) . ' is already checked in for this event.'];
            } else {
                if ($lat !== null && $lng !== null && bgi_is_outside_kuwait($lat, $lng)) {
                    $status = 'Out of Kuwait';
                } else {
                    $repBase = $postEvent['event_date'] !== '' ? $postEvent['event_date'] : date('Y-m-d');
                    $repTime = $postEvent['reporting_time'] !== '' ? $postEvent['reporting_time'] : '00:00:00';
                    $repTs   = strtotime($repBase . ' ' . $repTime);
                    $status  = ($repTs !== false && time() > $repTs) ? 'Late' : 'Present';
                }

                $tId    = (int) $tMember['id'];
                $tName  = (string) $tMember['member_name'];
                $tBgi   = (string) ($tMember['bgi_id'] ?? '');
                $tIdara = (string) $tMember['idara'];
                $tMoh   = (string) $tMember['mohalla'];
                $nowD   = date('Y-m-d');
                $nowDT  = date('Y-m-d H:i:s');

                $ins = $conn->prepare(
                    "INSERT INTO attendance
                     (event_id, member_id, member_name, its_id, bgi_id, idara, mohalla,
                      attendance_date, attendance_time, status, checkin_source)
                     VALUES (?,?,?,?,?,?,?,?,?,?,'web')"
                );
                $ins->bind_param(
                    'iissssssss',
                    $postEventId, $tId, $tName, $targetItsId, $tBgi, $tIdara, $tMoh,
                    $nowD, $nowDT, $status
                );

                if ($ins->execute()) {
                    $_SESSION['checkin_flash'] = [
                        'type' => 'success',
                        'msg'  => htmlspecialchars($tMember['member_name']) . ' checked in — <strong>' . htmlspecialchars($status) . '</strong>.',
                    ];
                } else {
                    $_SESSION['checkin_flash'] = ['type' => 'error', 'msg' => 'Check-in could not be saved. Please try again.'];
                }
                $ins->close();

                header('Location: member_checkin.php?event_id=' . $postEventId);
                exit;
            }
        }
    }
}

if (empty($flash) && !empty($_SESSION['checkin_flash'])) {
    $flash = $_SESSION['checkin_flash'];
    unset($_SESSION['checkin_flash']);
}

// ── Load events for member's scope ───────────────────────────────────────────
$evListStmt = $conn->prepare(
    "SELECT id, event_name, COALESCE(event_code,'') AS event_code,
            DATE_FORMAT(event_date,'%Y-%m-%d') AS event_date,
            COALESCE(DATE_FORMAT(reporting_time,'%H:%i'),'') AS reporting_time
     FROM events WHERE idara = ? AND mohalla = ?
     ORDER BY event_date DESC, reporting_time DESC LIMIT 60"
);
$evListStmt->bind_param('ss', $myIdara, $myMohalla);
$evListStmt->execute();
$eventsList = [];
$evRes = $evListStmt->get_result();
while ($r = $evRes->fetch_assoc()) {
    $eventsList[] = $r;
}
$evListStmt->close();

$selectedEventId = filter_var($_GET['event_id'] ?? 0, FILTER_VALIDATE_INT);
$selectedEventId = ($selectedEventId !== false && $selectedEventId > 0) ? (int) $selectedEventId : 0;

$selectedEvent = null;
foreach ($eventsList as $ev) {
    if ((int) $ev['id'] === $selectedEventId) {
        $selectedEvent = $ev;
        break;
    }
}

// ── Load members with check-in status for selected event ─────────────────────
$memberRows = [];
if ($selectedEvent) {
    if ($isCaptain) {
        $mStmt = $conn->prepare(
            "SELECT m.id, m.its_id, m.member_name, m.bgi_id, m.position,
                    a.status AS checkin_status, a.attendance_time AS checkin_time
             FROM members m
             LEFT JOIN attendance a ON a.its_id = m.its_id AND a.event_id = ?
             WHERE m.idara = ? AND m.mohalla = ?
             ORDER BY FIELD(m.position,'captain','team_leader','member'), m.member_name"
        );
        $mStmt->bind_param('iss', $selectedEventId, $myIdara, $myMohalla);
    } elseif ($isTL) {
        $mStmt = $conn->prepare(
            "SELECT m.id, m.its_id, m.member_name, m.bgi_id, m.position,
                    a.status AS checkin_status, a.attendance_time AS checkin_time
             FROM members m
             LEFT JOIN attendance a ON a.its_id = m.its_id AND a.event_id = ?
             WHERE m.its_id = ? OR m.team_leader_its_id = ?
             ORDER BY FIELD(m.position,'captain','team_leader','member'), m.member_name"
        );
        $mStmt->bind_param('iss', $selectedEventId, $myItsId, $myItsId);
    } else {
        $mStmt = $conn->prepare(
            "SELECT m.id, m.its_id, m.member_name, m.bgi_id, m.position,
                    a.status AS checkin_status, a.attendance_time AS checkin_time
             FROM members m
             LEFT JOIN attendance a ON a.its_id = m.its_id AND a.event_id = ?
             WHERE m.its_id = ?"
        );
        $mStmt->bind_param('is', $selectedEventId, $myItsId);
    }
    $mStmt->execute();
    $mRes = $mStmt->get_result();
    while ($r = $mRes->fetch_assoc()) {
        $memberRows[] = $r;
    }
    $mStmt->close();
}

$checkedIn  = count(array_filter($memberRows, fn($r) => $r['checkin_status'] !== null));
$pending    = count($memberRows) - $checkedIn;

if ($isCaptain) {
    $pageTitle = 'Group Check-In';
    $pageIntro = 'Check in yourself or any member in your Mohalla for the selected event.';
} elseif ($isTL) {
    $pageTitle = 'Team Check-In';
    $pageIntro = 'Check in yourself or any member in your team for the selected event.';
} else {
    $pageTitle = 'Self Check-In';
    $pageIntro = 'Record your attendance for the selected event.';
}

mysqli_close($conn);

function checkin_status_badge(?string $status): string
{
    if ($status === null) {
        return '<span class="status-badge absent">Not Checked In</span>';
    }
    return match ($status) {
        'Late'          => '<span class="status-badge late">Checked In (Late)</span>',
        'Out of Kuwait' => '<span class="status-badge out-of-kuwait">Out of Kuwait</span>',
        default         => '<span class="status-badge ontime">Checked In</span>',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> — <?= htmlspecialchars(bgi_app_name()) ?></title>
    <link rel="stylesheet" href="app.css">
</head>
<body class="app-page page-table">

<div class="topbar">
    <div><strong><?= htmlspecialchars(bgi_app_name()) ?></strong></div>
    <div>
        <a href="report_members.php" class="back">← My Reports</a>
        <a href="logout.php" class="logout" style="margin-left:8px;">Logout</a>
    </div>
</div>

<div class="container">
    <h2><?= htmlspecialchars($pageTitle) ?></h2>
    <p class="page-intro"><?= htmlspecialchars($pageIntro) ?></p>

    <?php if ($flash): ?>
        <p class="message <?= $flash['type'] === 'success' ? 'success' : 'error' ?>"><?= $flash['msg'] ?></p>
    <?php endif; ?>

    <form method="GET" style="margin-bottom:24px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <select name="event_id" style="flex:1;min-width:220px;">
            <option value="0">— Select an event —</option>
            <?php foreach ($eventsList as $ev): ?>
                <option value="<?= (int) $ev['id'] ?>" <?= $selectedEventId === (int) $ev['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($ev['event_date'] . ' — ' . $ev['event_name'] . ($ev['event_code'] !== '' ? ' (' . $ev['event_code'] . ')' : '') . ' ' . $ev['reporting_time']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit">Load Event</button>
    </form>

    <?php if ($selectedEvent): ?>
        <div class="table-wrap" style="margin-bottom:8px;">
            <div style="display:flex;gap:16px;flex-wrap:wrap;padding:14px 18px;background:var(--surface,#f8faf9);border-radius:14px;margin-bottom:16px;font-size:0.93rem;">
                <span><strong>Event:</strong> <?= htmlspecialchars($selectedEvent['event_name']) ?></span>
                <?php if ($selectedEvent['event_code'] !== ''): ?>
                    <span><strong>Code:</strong> <?= htmlspecialchars($selectedEvent['event_code']) ?></span>
                <?php endif; ?>
                <span><strong>Date:</strong> <?= htmlspecialchars($selectedEvent['event_date']) ?></span>
                <span><strong>Reporting Time:</strong> <?= htmlspecialchars($selectedEvent['reporting_time']) ?></span>
                <span><strong>Checked In:</strong> <?= $checkedIn ?> / <?= count($memberRows) ?></span>
                <span><strong>Pending:</strong> <?= $pending ?></span>
            </div>

            <?php if (!empty($memberRows)): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Position</th>
                            <th>ITS ID</th>
                            <th>BGI ID</th>
                            <th>Status</th>
                            <th>Time</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($memberRows as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['member_name']) ?></td>
                                <td><?= htmlspecialchars(bgi_member_position_label($row['position'] ?? BGI_POSITION_MEMBER)) ?></td>
                                <td><?= htmlspecialchars($row['its_id']) ?></td>
                                <td><?= htmlspecialchars($row['bgi_id'] ?? '') ?></td>
                                <td><?= checkin_status_badge($row['checkin_status']) ?></td>
                                <td><?= $row['checkin_time'] ? htmlspecialchars(date('H:i:s', strtotime($row['checkin_time']))) : '—' ?></td>
                                <td>
                                    <?php if ($row['checkin_status'] === null): ?>
                                        <form method="POST" onsubmit="return submitCheckin(this, event)">
                                            <input type="hidden" name="event_id" value="<?= (int) $selectedEventId ?>">
                                            <input type="hidden" name="target_its_id" value="<?= htmlspecialchars($row['its_id']) ?>">
                                            <input type="hidden" name="lat" value="">
                                            <input type="hidden" name="lng" value="">
                                            <button type="submit" class="btn" style="min-height:32px;padding:4px 16px;font-size:0.85rem;">Check In</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No members found.</p>
            <?php endif; ?>
        </div>
    <?php elseif ($selectedEventId > 0): ?>
        <p class="message error">Event not found in your scope.</p>
    <?php else: ?>
        <p style="color:#666;">Select an event above to load the check-in list.</p>
    <?php endif; ?>
</div>

<script>
function submitCheckin(form, e) {
    e.preventDefault();
    if (!navigator.geolocation) {
        form.submit();
        return false;
    }
    navigator.geolocation.getCurrentPosition(
        function(pos) {
            form.querySelector('[name="lat"]').value = pos.coords.latitude;
            form.querySelector('[name="lng"]').value = pos.coords.longitude;
            form.submit();
        },
        function() {
            form.submit();
        },
        { timeout: 5000, maximumAge: 60000 }
    );
    return false;
}
</script>

</body>
</html>
