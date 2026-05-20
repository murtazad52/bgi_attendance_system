<?php
require_once __DIR__ . '/auth.php';
include('db.php');

// Only regular members (not TL/Captain — they go to admin_attendance.php)
if (!bgi_is_member()) {
    header('Location: ' . bgi_home_path_for_current_user());
    exit;
}
if (bgi_is_team_leader_member() || bgi_is_captain_member()) {
    header('Location: admin_attendance.php');
    exit;
}

$myItsId   = bgi_current_member_its_id();
$myId      = bgi_current_member_id();
$myIdara   = bgi_current_scope_idara();
$myMohalla = bgi_current_scope_mohalla();
$myName    = bgi_current_member_name();

$flash = null;

// ── POST: self check-in ──────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $postEventId = (int) ($_POST['event_id'] ?? 0);
    $lat = isset($_POST['lat']) && is_numeric($_POST['lat']) ? (float) $_POST['lat'] : null;
    $lng = isset($_POST['lng']) && is_numeric($_POST['lng']) ? (float) $_POST['lng'] : null;

    $evStmt = $conn->prepare(
        "SELECT id, event_name, DATE_FORMAT(event_date,'%Y-%m-%d') AS event_date,
                COALESCE(DATE_FORMAT(reporting_time,'%H:%i:%s'),'') AS reporting_time
         FROM events
         WHERE id = ? AND idara = ? AND mohalla = ?
           AND TIMESTAMPADD(HOUR, 12, CONCAT(event_date,' ',COALESCE(TIME(reporting_time),'00:00:00'))) >= NOW()
         LIMIT 1"
    );
    $evStmt->bind_param('iss', $postEventId, $myIdara, $myMohalla);
    $evStmt->execute();
    $postEvent = $evStmt->get_result()->fetch_assoc();
    $evStmt->close();

    if (!$postEvent) {
        $_SESSION['checkin_flash'] = ['type' => 'error', 'msg' => 'Event not available or has expired.'];
    } else {
        $dup = $conn->prepare("SELECT id FROM attendance WHERE event_id = ? AND its_id = ? LIMIT 1");
        $dup->bind_param('is', $postEventId, $myItsId);
        $dup->execute();
        $already = (bool) $dup->get_result()->fetch_row();
        $dup->close();

        if ($already) {
            $_SESSION['checkin_flash'] = ['type' => 'error', 'msg' => 'You have already checked in for this event.'];
        } else {
            // Check-in time window: 30 min before → 1 hour after reporting_time
            $tw_base  = $postEvent['event_date'] !== '' ? $postEvent['event_date'] : date('Y-m-d');
            $tw_time  = $postEvent['reporting_time'] !== '' ? $postEvent['reporting_time'] : '00:00:00';
            $tw_start = strtotime($tw_base . ' ' . $tw_time) - 1800;
            $tw_end   = strtotime($tw_base . ' ' . $tw_time) + 3600;
            if (time() < $tw_start || time() > $tw_end) {
                $_SESSION['checkin_flash'] = [
                    'type' => 'error',
                    'msg'  => 'Check-in window is ' . date('H:i', $tw_start) . ' – ' . date('H:i', $tw_end) . '. Check-in is not open yet or has already closed.',
                ];
                header('Location: member_self_checkin.php');
                exit;
            }

            if ($lat !== null && $lng !== null && bgi_is_outside_kuwait($lat, $lng)) {
                $status = 'Out of Kuwait';
            } else {
                $repBase = $postEvent['event_date'] !== '' ? $postEvent['event_date'] : date('Y-m-d');
                $repTime = $postEvent['reporting_time'] !== '' ? $postEvent['reporting_time'] : '00:00:00';
                $repTs   = strtotime($repBase . ' ' . $repTime);
                $status  = ($repTs !== false && time() > $repTs) ? 'Late' : 'Present';
            }

            $mStmt = $conn->prepare("SELECT bgi_id, idara, mohalla FROM members WHERE its_id = ? LIMIT 1");
            $mStmt->bind_param('s', $myItsId);
            $mStmt->execute();
            $mRow = $mStmt->get_result()->fetch_assoc();
            $mStmt->close();

            $bgiId  = (string) ($mRow['bgi_id'] ?? '');
            $idara  = (string) ($mRow['idara'] ?? $myIdara);
            $mohalla = (string) ($mRow['mohalla'] ?? $myMohalla);
            $nowD   = date('Y-m-d');
            $nowDT  = date('Y-m-d H:i:s');

            $ins = $conn->prepare(
                "INSERT INTO attendance
                 (event_id, member_id, member_name, its_id, bgi_id, idara, mohalla,
                  attendance_date, attendance_time, status, checkin_source)
                 VALUES (?,?,?,?,?,?,?,?,?,?,'web')"
            );
            $ins->bind_param('iissssssss',
                $postEventId, $myId, $myName, $myItsId, $bgiId, $idara, $mohalla,
                $nowD, $nowDT, $status
            );

            if ($ins->execute()) {
                $_SESSION['checkin_flash'] = ['type' => 'success', 'msg' => $status, 'event' => $postEvent['event_name']];
            } else {
                $_SESSION['checkin_flash'] = ['type' => 'error', 'msg' => 'Check-in could not be saved. Please try again.'];
            }
            $ins->close();
        }
    }

    header('Location: member_self_checkin.php');
    exit;
}

if (!empty($_SESSION['checkin_flash'])) {
    $flash = $_SESSION['checkin_flash'];
    unset($_SESSION['checkin_flash']);
}

// ── Load today's events (filter: not expired more than 12 hours ago) ─────────
$todayEvStmt = $conn->prepare(
    "SELECT id, event_name, COALESCE(event_code,'') AS event_code,
            DATE_FORMAT(event_date,'%Y-%m-%d') AS event_date,
            COALESCE(DATE_FORMAT(reporting_time,'%H:%i'),'') AS reporting_time
     FROM events
     WHERE idara = ? AND mohalla = ? AND event_date = CURDATE()
       AND TIMESTAMPADD(HOUR, 12, CONCAT(event_date,' ',COALESCE(TIME(reporting_time),'00:00:00'))) >= NOW()
     ORDER BY reporting_time ASC"
);
$todayEvStmt->bind_param('ss', $myIdara, $myMohalla);
$todayEvStmt->execute();
$todayEvents = [];
$teRes = $todayEvStmt->get_result();
while ($r = $teRes->fetch_assoc()) {
    $todayEvents[] = $r;
}
$todayEvStmt->close();

// If no today events, load all recent (not expired > 12 hours)
$otherEvents = [];
if (empty($todayEvents)) {
    $otherEvStmt = $conn->prepare(
        "SELECT id, event_name, COALESCE(event_code,'') AS event_code,
                DATE_FORMAT(event_date,'%Y-%m-%d') AS event_date,
                COALESCE(DATE_FORMAT(reporting_time,'%H:%i'),'') AS reporting_time
         FROM events
         WHERE idara = ? AND mohalla = ?
           AND TIMESTAMPADD(HOUR, 12, CONCAT(event_date,' ',COALESCE(TIME(reporting_time),'00:00:00'))) >= NOW()
         ORDER BY event_date DESC, reporting_time DESC LIMIT 20"
    );
    $otherEvStmt->bind_param('ss', $myIdara, $myMohalla);
    $otherEvStmt->execute();
    $oeRes = $otherEvStmt->get_result();
    while ($r = $oeRes->fetch_assoc()) {
        $otherEvents[] = $r;
    }
    $otherEvStmt->close();
}

// Auto-select: if exactly one today's event, use it; otherwise no auto-select
$autoEvent = count($todayEvents) === 1 ? $todayEvents[0] : null;
$allEvents  = !empty($todayEvents) ? $todayEvents : $otherEvents;

// Check if already checked in for auto-selected event
$alreadyCheckedIn = false;
$checkedInStatus  = null;
$checkedInTime    = null;
if ($autoEvent) {
    $cStmt = $conn->prepare("SELECT status, attendance_time FROM attendance WHERE event_id = ? AND its_id = ? LIMIT 1");
    $cStmt->bind_param('is', $autoEvent['id'], $myItsId);
    $cStmt->execute();
    $cRow = $cStmt->get_result()->fetch_assoc();
    $cStmt->close();
    if ($cRow) {
        $alreadyCheckedIn = true;
        $checkedInStatus  = $cRow['status'];
        $checkedInTime    = $cRow['attendance_time'];
    }
}

mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Self Check-In — <?= htmlspecialchars(bgi_app_name()) ?></title>
    <link rel="stylesheet" href="app.css">
    <style>
        .checkin-card {
            max-width: 480px;
            margin: 24px auto;
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.09);
            padding: 28px 24px 24px;
        }
        .checkin-event-name {
            font-size: 1.2rem;
            font-weight: 800;
            color: var(--accent, #176b53);
            margin: 0 0 4px;
        }
        .checkin-meta {
            font-size: 0.88rem;
            color: #666;
            margin-bottom: 20px;
        }
        .checkin-meta span { margin-right: 12px; }
        .checkin-btn {
            width: 100%;
            padding: 16px;
            font-size: 1.1rem;
            font-weight: 800;
            border-radius: 14px;
            border: none;
            background: linear-gradient(135deg, var(--accent, #176b53) 0%, #23956f 100%);
            color: #fff;
            cursor: pointer;
            box-shadow: 0 8px 20px rgba(23,107,83,0.18);
            transition: transform 0.15s, box-shadow 0.15s;
        }
        .checkin-btn:active { transform: scale(0.98); }
        .checkin-btn:disabled {
            background: #c5d5cc;
            box-shadow: none;
            cursor: default;
        }
        .checkin-status-box {
            border-radius: 14px;
            padding: 16px 18px;
            text-align: center;
            font-weight: 700;
            font-size: 1rem;
            margin-bottom: 12px;
        }
        .checkin-status-box.present { background: #e6f6ec; color: #0f5132; }
        .checkin-status-box.late    { background: #fff8e1; color: #7c5a00; }
        .checkin-status-box.out     { background: #e8f4fd; color: #0a4f7a; }
        .checkin-status-time { font-size: 0.85rem; font-weight: 400; margin-top: 4px; }
        .event-select-wrap { margin-bottom: 20px; }
        .event-select-wrap select { width: 100%; font-size: 1rem; padding: 12px; border-radius: 12px; border: 1.5px solid #d0ddd8; }
        .member-greeting { font-size: 0.95rem; color: #555; margin-bottom: 20px; }
        .no-events-msg { text-align: center; color: #888; padding: 32px 0; font-size: 0.95rem; }
        .locating-msg { font-size: 0.82rem; color: #888; text-align: center; margin-top: 10px; display: none; }
    </style>
</head>
<body class="app-page">

<div class="topbar">
    <div><strong><?= htmlspecialchars(bgi_app_name()) ?></strong></div>
    <div>
        <a href="report_members.php" class="back">← My Reports</a>
        <a href="logout.php" class="logout" style="margin-left:8px;">Logout</a>
    </div>
</div>

<div class="container">

    <?php if ($flash): ?>
        <?php if ($flash['type'] === 'success'): ?>
            <div class="checkin-card" style="text-align:center;">
                <div style="font-size:2.5rem;margin-bottom:8px;">✓</div>
                <div class="checkin-event-name">Checked In!</div>
                <div class="checkin-meta"><?= htmlspecialchars($flash['event'] ?? '') ?></div>
                <div style="font-size:1rem;font-weight:700;color:<?= $flash['msg'] === 'Present' ? '#0f5132' : ($flash['msg'] === 'Late' ? '#7c5a00' : '#0a4f7a') ?>;margin-bottom:18px;">
                    <?= htmlspecialchars($flash['msg']) ?>
                </div>
                <a href="member_self_checkin.php" class="btn secondary" style="width:100%;display:block;text-align:center;box-sizing:border-box;">Done</a>
            </div>
        <?php else: ?>
            <p class="message error"><?= htmlspecialchars($flash['msg']) ?></p>
        <?php endif; ?>
    <?php endif; ?>

    <div class="checkin-card">
        <p class="member-greeting">Hello, <strong><?= htmlspecialchars($myName) ?></strong></p>

        <?php if (empty($allEvents)): ?>
            <div class="no-events-msg">No events available for check-in right now.</div>

        <?php elseif ($autoEvent): ?>
            <!-- Single today's event: show directly -->
            <div class="checkin-event-name"><?= htmlspecialchars($autoEvent['event_name']) ?></div>
            <div class="checkin-meta">
                <?php if ($autoEvent['event_code'] !== ''): ?>
                    <span><?= htmlspecialchars($autoEvent['event_code']) ?></span>
                <?php endif; ?>
                <span><?= htmlspecialchars($autoEvent['event_date']) ?></span>
                <?php if ($autoEvent['reporting_time'] !== ''): ?>
                    <span>Reporting: <?= htmlspecialchars($autoEvent['reporting_time']) ?></span>
                <?php endif; ?>
            </div>

            <?php if ($alreadyCheckedIn): ?>
                <?php
                $boxClass = match($checkedInStatus) {
                    'Late'          => 'late',
                    'Out of Kuwait' => 'out',
                    default         => 'present',
                };
                ?>
                <div class="checkin-status-box <?= $boxClass ?>">
                    Already Checked In — <?= htmlspecialchars($checkedInStatus ?? 'Present') ?>
                    <?php if ($checkedInTime): ?>
                        <div class="checkin-status-time"><?= htmlspecialchars(date('H:i:s', strtotime($checkedInTime))) ?></div>
                    <?php endif; ?>
                </div>
                <button class="checkin-btn" disabled>Already Checked In</button>
            <?php else: ?>
                <form method="POST" onsubmit="return doCheckin(this, event)">
                    <input type="hidden" name="event_id" value="<?= (int) $autoEvent['id'] ?>">
                    <input type="hidden" name="lat" value="">
                    <input type="hidden" name="lng" value="">
                    <button type="submit" class="checkin-btn">Check In Now</button>
                    <p class="locating-msg" id="locMsg">Getting your location…</p>
                </form>
            <?php endif; ?>

        <?php else: ?>
            <!-- Multiple or non-today events: show dropdown -->
            <form method="POST" onsubmit="return doCheckin(this, event)">
                <div class="event-select-wrap">
                    <label style="font-weight:700;margin-bottom:6px;display:block;">Select Event</label>
                    <select name="event_id" required>
                        <option value="">— Choose an event —</option>
                        <?php foreach ($allEvents as $ev): ?>
                            <option value="<?= (int) $ev['id'] ?>">
                                <?= htmlspecialchars($ev['event_date'] . ' — ' . $ev['event_name'] . ($ev['reporting_time'] !== '' ? ' (' . $ev['reporting_time'] . ')' : '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <input type="hidden" name="lat" value="">
                <input type="hidden" name="lng" value="">
                <button type="submit" class="checkin-btn">Check In Now</button>
                <p class="locating-msg" id="locMsg">Getting your location…</p>
            </form>
        <?php endif; ?>
    </div>

</div>

<script>
function doCheckin(form, e) {
    e.preventDefault();
    var btn = form.querySelector('button[type="submit"]');
    var msg = document.getElementById('locMsg');

    if (!navigator.geolocation) {
        form.submit();
        return false;
    }

    btn.disabled = true;
    if (msg) msg.style.display = 'block';

    navigator.geolocation.getCurrentPosition(
        function(pos) {
            form.querySelector('[name="lat"]').value = pos.coords.latitude;
            form.querySelector('[name="lng"]').value = pos.coords.longitude;
            form.submit();
        },
        function() {
            form.submit();
        },
        { timeout: 6000, maximumAge: 60000 }
    );
    return false;
}
</script>

</body>
</html>
