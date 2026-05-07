<?php
include('session_check.php');

include('db.php');

if (!bgi_can_manage_members()) {
    header('Location: ' . bgi_home_path_for_current_user());
    exit;
}

$isScopedAdmin = !bgi_is_super_admin();
$isPairScopedAdmin = bgi_is_idara_admin();
$isMohallaAdmin = bgi_is_mohalla_admin();
$scopeOptions = bgi_get_scope_options($conn);
$availableScopePairs = [];
$idaraOptions = [];
$teamLeaderOptions = [];
$captainOptions = [];

foreach ($scopeOptions as $scopeOption) {
    $idara = bgi_normalize_scope_value($scopeOption['idara'] ?? '', BGI_DEFAULT_IDARA);
    $mohalla = bgi_normalize_scope_value($scopeOption['mohalla'] ?? '', BGI_DEFAULT_MOHALLA);

    if ($isMohallaAdmin && strcasecmp($mohalla, bgi_current_scope_mohalla()) !== 0) {
        continue;
    }

    $availableScopePairs[$idara . '||' . $mohalla] = ['idara' => $idara, 'mohalla' => $mohalla];
    $idaraOptions[$idara] = $idara;
}

$teamLeaderSql = "SELECT its_id, member_name, idara, mohalla FROM members WHERE position = ?";
if ($isMohallaAdmin) {
    $teamLeaderSql .= " AND mohalla = ?";
} elseif ($isPairScopedAdmin) {
    $teamLeaderSql .= " AND idara = ? AND mohalla = ?";
}
$teamLeaderSql .= " ORDER BY member_name ASC";
$teamLeaderStmt = $conn->prepare($teamLeaderSql);
if ($teamLeaderStmt) {
    $teamLeaderPosition = BGI_POSITION_TEAM_LEADER;
    if ($isMohallaAdmin) {
        $scopeMohalla = bgi_current_scope_mohalla();
        $teamLeaderStmt->bind_param("ss", $teamLeaderPosition, $scopeMohalla);
    } elseif ($isPairScopedAdmin) {
        $scopeIdara = bgi_current_scope_idara();
        $scopeMohalla = bgi_current_scope_mohalla();
        $teamLeaderStmt->bind_param("sss", $teamLeaderPosition, $scopeIdara, $scopeMohalla);
    } else {
        $teamLeaderStmt->bind_param("s", $teamLeaderPosition);
    }
    $teamLeaderStmt->execute();
    $teamLeaderResult = $teamLeaderStmt->get_result();
    while ($teamLeaderResult && ($teamLeaderRow = $teamLeaderResult->fetch_assoc())) {
        $teamLeaderOptions[(string) $teamLeaderRow['its_id']] = $teamLeaderRow;
    }
    $teamLeaderStmt->close();
}

$captainSql = "SELECT its_id, member_name, idara, mohalla FROM members WHERE position = ?";
if ($isMohallaAdmin) {
    $captainSql .= " AND mohalla = ?";
} elseif ($isPairScopedAdmin) {
    $captainSql .= " AND idara = ? AND mohalla = ?";
}
$captainSql .= " ORDER BY member_name ASC";
$captainStmt = $conn->prepare($captainSql);
if ($captainStmt) {
    $captainPosition = BGI_POSITION_CAPTAIN;
    if ($isMohallaAdmin) {
        $scopeMohalla = bgi_current_scope_mohalla();
        $captainStmt->bind_param("ss", $captainPosition, $scopeMohalla);
    } elseif ($isPairScopedAdmin) {
        $scopeIdara = bgi_current_scope_idara();
        $scopeMohalla = bgi_current_scope_mohalla();
        $captainStmt->bind_param("sss", $captainPosition, $scopeIdara, $scopeMohalla);
    } else {
        $captainStmt->bind_param("s", $captainPosition);
    }
    $captainStmt->execute();
    $captainResult = $captainStmt->get_result();
    while ($captainResult && ($captainRow = $captainResult->fetch_assoc())) {
        $captainOptions[(string) $captainRow['its_id']] = $captainRow;
    }
    $captainStmt->close();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$selectedIdara = $isMohallaAdmin ? '' : ($isScopedAdmin ? bgi_current_scope_idara() : BGI_DEFAULT_IDARA);
$selectedMohalla = $isScopedAdmin ? bgi_current_scope_mohalla() : BGI_DEFAULT_MOHALLA;
$selectedPosition = BGI_POSITION_MEMBER;
$selectedTeamLeaderItsId = '';
$selectedCaptainItsId = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $its_id = trim($_POST['its_id'] ?? '');
    $bgi_id = trim($_POST['bgi_id'] ?? '');
    $member_name = trim($_POST['member_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $selectedPosition = bgi_normalize_member_position($_POST['position'] ?? BGI_POSITION_MEMBER);
    $selectedTeamLeaderItsId = trim($_POST['team_leader_its_id'] ?? '');
    $selectedCaptainItsId = trim($_POST['captain_its_id'] ?? '');
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

    $scopeKey = $selectedIdara . '||' . $selectedMohalla;

    $csrfToken = trim($_POST['csrf_token'] ?? '');
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        $error = 'Invalid request token. Please refresh the page and try again.';
    } elseif (!preg_match('/^\d{8}$/', $its_id)) {
        $error = "ITS ID must be exactly 8 numeric digits.";
    } elseif (!preg_match('/^\d{1,4}$/', $bgi_id)) {
        $error = "BGI ID must be up to 4 numeric digits.";
    } elseif ($member_name === '') {
        $error = "Member name is required.";
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Email must be valid.";
    } elseif (!preg_match('/^\d{8}$/', $phone)) {
        $error = "Phone must be exactly 8 numeric digits.";
    } elseif (!isset($availableScopePairs[$scopeKey])) {
        $error = "Please choose a valid saved Idara and Mohalla.";
    } elseif ($selectedPosition === BGI_POSITION_MEMBER && $selectedTeamLeaderItsId === '') {
        $error = "Please choose a Team Leader for this member.";
    } elseif ($selectedPosition === BGI_POSITION_TEAM_LEADER && $selectedCaptainItsId === '') {
        $error = "Please choose a Captain for this Team Leader.";
    } elseif ($selectedPosition === BGI_POSITION_MEMBER && $selectedTeamLeaderItsId !== '' && $selectedTeamLeaderItsId === $its_id) {
        $error = "A member cannot be assigned to themselves as Team Leader.";
    } elseif ($selectedPosition === BGI_POSITION_TEAM_LEADER && $selectedCaptainItsId !== '' && $selectedCaptainItsId === $its_id) {
        $error = "A Team Leader cannot be assigned to themselves as Captain.";
    } elseif ($selectedPosition === BGI_POSITION_MEMBER && $selectedTeamLeaderItsId !== '') {
        $leaderStmt = $conn->prepare(
            "SELECT its_id, captain_its_id FROM members
             WHERE its_id = ? AND position = ? AND idara = ? AND mohalla = ?
             LIMIT 1"
        );
        $teamLeaderPosition = BGI_POSITION_TEAM_LEADER;
        $leaderStmt->bind_param("ssss", $selectedTeamLeaderItsId, $teamLeaderPosition, $selectedIdara, $selectedMohalla);
        $leaderStmt->execute();
        $leaderResult = $leaderStmt->get_result();
        if (!$leaderResult || $leaderResult->num_rows === 0) {
            $error = "Please choose a Team Leader from the same Idara and Mohalla.";
        } else {
            $leaderRow = $leaderResult->fetch_assoc();
            $selectedCaptainItsId = trim((string) ($leaderRow['captain_its_id'] ?? ''));
        }
        $leaderStmt->close();
    } elseif ($selectedPosition === BGI_POSITION_TEAM_LEADER && $selectedCaptainItsId !== '') {
        $captainValidateStmt = $conn->prepare(
            "SELECT its_id FROM members
             WHERE its_id = ? AND position = ? AND idara = ? AND mohalla = ?
             LIMIT 1"
        );
        $captainPosition = BGI_POSITION_CAPTAIN;
        $captainValidateStmt->bind_param("ssss", $selectedCaptainItsId, $captainPosition, $selectedIdara, $selectedMohalla);
        $captainValidateStmt->execute();
        $captainResult = $captainValidateStmt->get_result();
        if (!$captainResult || $captainResult->num_rows === 0) {
            $error = "Please choose a Captain from the same Idara and Mohalla.";
        }
        $captainValidateStmt->close();
    }

    if (!isset($error) || $error === '') {
        if ($selectedPosition !== BGI_POSITION_MEMBER) {
            $selectedTeamLeaderItsId = '';
        }
        if ($selectedPosition !== BGI_POSITION_TEAM_LEADER) {
            $selectedCaptainItsId = '';
        }

        $itsStmt = $conn->prepare("SELECT id FROM members WHERE its_id = ? LIMIT 1");
        $itsStmt->bind_param("s", $its_id);
        $itsStmt->execute();
        $itsResult = $itsStmt->get_result();

        if ($itsResult->num_rows > 0) {
            $error = "ITS ID already exists. Please use a unique ITS ID.";
        } else {
            $bgiStmt = $conn->prepare("SELECT id FROM members WHERE bgi_id = ? LIMIT 1");
            $bgiStmt->bind_param("s", $bgi_id);
            $bgiStmt->execute();
            $bgiResult = $bgiStmt->get_result();

            if ($bgiResult->num_rows > 0) {
                $error = "BGI ID already belongs to another member.";
            } else {
                $insertStmt = $conn->prepare("INSERT INTO members (bgi_id, idara, mohalla, its_id, member_name, position, team_leader_its_id, captain_its_id, email, phone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $teamLeaderValue = $selectedTeamLeaderItsId !== '' ? $selectedTeamLeaderItsId : null;
                $captainValue = $selectedCaptainItsId !== '' ? $selectedCaptainItsId : null;
                $insertStmt->bind_param("ssssssssss", $bgi_id, $selectedIdara, $selectedMohalla, $its_id, $member_name, $selectedPosition, $teamLeaderValue, $captainValue, $email, $phone);

                if ($insertStmt->execute()) {
                    $insertStmt->close();
                    $bgiStmt->close();
                    $itsStmt->close();
                    echo "<script>
                            alert('Member Created Successfully');
                            window.location.href = 'admin_members.php';
                          </script>";
                    exit();
                }

                $error = "Error adding member: " . $insertStmt->error;
                $insertStmt->close();
            }

            $bgiStmt->close();
        }

        $itsStmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Member & Import Members</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f6f8; padding: 20px; }
        .form-container { background: white; max-width: 600px; margin: 20px auto; padding: 30px; border-radius: 10px; box-shadow: 0 0 10px #ccc; }
        .form-container h2 { text-align: center; margin-bottom: 20px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; }
        input[type="text"], input[type="email"], input[type="file"] { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 5px; }
        .btn { background: #2E8B57; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; }
        .btn:hover { background: #246B46; }
        .back-btn { display: inline-block; margin-bottom: 20px; }
        hr { margin: 40px 0; border: none; border-top: 1px solid #ddd; }
    </style>
    <link rel="stylesheet" href="app.css">
</head>
<body class="app-page">

<a href="dashboard.php" class="btn back-btn">Back to Dashboard</a>

<div class="form-container" id="member-import">
    <h2>Import Members via CSV</h2>
    <p class="page-intro">
        <?= $isScopedAdmin
            ? 'Upload a CSV file to bring in multiple members at once. Imported rows will automatically use your scope: ' . htmlspecialchars(bgi_current_scope_label()) . '.'
            : 'Upload a CSV file to bring in multiple members at once. ITS ID is used as the main member reference during import.' ?>
    </p>
    <form method="post" enctype="multipart/form-data" action="import_members.php">
        <div class="form-group">
            <input type="file" name="csv_file" accept=".csv" required>
        </div>
        <button type="submit" class="btn">Import CSV</button>
    </form>
</div>

<hr>

<div class="form-container">
    <h2>Add New Member</h2>

    <?php if (isset($error)) echo "<div class='message error'>" . htmlspecialchars($error) . "</div>"; ?>
    <p class="page-intro">Create a single member record with ITS ID as the primary identifier and BGI/contact details as supporting data.</p>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <div class="form-group">
            <label>ITS ID (exact 8 digits)</label>
            <input type="text" name="its_id" value="<?= htmlspecialchars($_POST['its_id'] ?? '') ?>" required pattern="\d{8}" maxlength="8" minlength="8" title="Exactly 8 numeric digits">
        </div>
        <div class="form-group">
            <label>BGI ID (up to 4 digits)</label>
            <input type="text" name="bgi_id" value="<?= htmlspecialchars($_POST['bgi_id'] ?? '') ?>" required pattern="\d{1,4}" maxlength="4" title="Up to 4 numeric digits only">
        </div>
        <?php if ($isPairScopedAdmin): ?>
            <div class="form-group">
                <label>Idara</label>
                <input type="text" value="<?= htmlspecialchars($selectedIdara) ?>" readonly>
                <input type="hidden" name="idara" value="<?= htmlspecialchars($selectedIdara) ?>">
            </div>
            <div class="form-group">
                <label>Mohalla</label>
                <input type="text" value="<?= htmlspecialchars($selectedMohalla) ?>" readonly>
                <input type="hidden" name="mohalla" value="<?= htmlspecialchars($selectedMohalla) ?>">
            </div>
        <?php elseif ($isMohallaAdmin): ?>
            <div class="form-group">
                <label>Idara</label>
                <select name="idara" required>
                    <option value="">-- Select Idara --</option>
                    <?php foreach ($idaraOptions as $idaraOption): ?>
                        <option value="<?= htmlspecialchars($idaraOption) ?>" <?= $selectedIdara === $idaraOption ? 'selected' : '' ?>><?= htmlspecialchars($idaraOption) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Mohalla</label>
                <input type="text" value="<?= htmlspecialchars($selectedMohalla) ?>" readonly>
                <input type="hidden" name="mohalla" value="<?= htmlspecialchars($selectedMohalla) ?>">
            </div>
        <?php else: ?>
            <div class="form-group">
                <label>Idara</label>
                <input type="text" name="idara" value="<?= htmlspecialchars($selectedIdara) ?>" list="idara-options" required>
            </div>
            <div class="form-group">
                <label>Mohalla</label>
                <input type="text" name="mohalla" value="<?= htmlspecialchars($selectedMohalla) ?>" list="mohalla-options" required>
            </div>
            <datalist id="idara-options">
                <?php foreach ($scopeOptions as $scopeOption): ?>
                    <option value="<?= htmlspecialchars($scopeOption['idara']) ?>"></option>
                <?php endforeach; ?>
            </datalist>
            <datalist id="mohalla-options">
                <?php foreach ($scopeOptions as $scopeOption): ?>
                    <option value="<?= htmlspecialchars($scopeOption['mohalla']) ?>"></option>
                <?php endforeach; ?>
            </datalist>
        <?php endif; ?>
        <div class="form-group">
            <label>Member Name</label>
            <input type="text" name="member_name" value="<?= htmlspecialchars($_POST['member_name'] ?? '') ?>" required>
        </div>
        <div class="form-group">
            <label>Position</label>
            <select name="position" id="position" required>
                <option value="<?= htmlspecialchars(BGI_POSITION_MEMBER) ?>" <?= $selectedPosition === BGI_POSITION_MEMBER ? 'selected' : '' ?>><?= htmlspecialchars(bgi_member_position_label(BGI_POSITION_MEMBER)) ?></option>
                <option value="<?= htmlspecialchars(BGI_POSITION_TEAM_LEADER) ?>" <?= $selectedPosition === BGI_POSITION_TEAM_LEADER ? 'selected' : '' ?>><?= htmlspecialchars(bgi_member_position_label(BGI_POSITION_TEAM_LEADER)) ?></option>
                <option value="<?= htmlspecialchars(BGI_POSITION_CAPTAIN) ?>" <?= $selectedPosition === BGI_POSITION_CAPTAIN ? 'selected' : '' ?>><?= htmlspecialchars(bgi_member_position_label(BGI_POSITION_CAPTAIN)) ?></option>
            </select>
        </div>
        <div class="form-group" id="team-leader-wrap">
            <label>Assigned Team Leader</label>
            <select name="team_leader_its_id" id="team_leader_its_id">
                <option value="">-- Select Team Leader --</option>
                <?php foreach ($teamLeaderOptions as $teamLeaderItsId => $teamLeaderOption): ?>
                    <option value="<?= htmlspecialchars($teamLeaderItsId) ?>" <?= $selectedTeamLeaderItsId === $teamLeaderItsId ? 'selected' : '' ?>>
                        <?= htmlspecialchars($teamLeaderOption['member_name'] . ' (' . $teamLeaderItsId . ') - ' . ($teamLeaderOption['idara'] ?? BGI_DEFAULT_IDARA) . ' / ' . ($teamLeaderOption['mohalla'] ?? BGI_DEFAULT_MOHALLA)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="small-note">Required when the Position is Member.</div>
        </div>
        <div class="form-group" id="captain-wrap">
            <label>Assigned Captain</label>
            <select name="captain_its_id" id="captain_its_id">
                <option value="">-- Select Captain --</option>
                <?php foreach ($captainOptions as $captainItsId => $captainOption): ?>
                    <option value="<?= htmlspecialchars($captainItsId) ?>" <?= $selectedCaptainItsId === $captainItsId ? 'selected' : '' ?>>
                        <?= htmlspecialchars($captainOption['member_name'] . ' (' . $captainItsId . ') - ' . ($captainOption['idara'] ?? BGI_DEFAULT_IDARA) . ' / ' . ($captainOption['mohalla'] ?? BGI_DEFAULT_MOHALLA)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="small-note">Required when the Position is Team Leader.</div>
        </div>
        <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label>Phone (exact 8 digits)</label>
            <input type="text" name="phone" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" required pattern="\d{8}" maxlength="8" minlength="8" title="Exactly 8 numeric digits">
        </div>
        <button type="submit" class="btn">Add Member</button>
    </form>
</div>

<script>
const memberPositionSelect = document.getElementById('position');
const teamLeaderWrap = document.getElementById('team-leader-wrap');
const teamLeaderSelect = document.getElementById('team_leader_its_id');
const captainWrap = document.getElementById('captain-wrap');
const captainSelect = document.getElementById('captain_its_id');

function updatePositionVisibility() {
    if (!memberPositionSelect || !teamLeaderWrap || !captainWrap) {
        return;
    }

    const isMemberPosition = memberPositionSelect.value === '<?= htmlspecialchars(BGI_POSITION_MEMBER) ?>';
    const isTeamLeaderPosition = memberPositionSelect.value === '<?= htmlspecialchars(BGI_POSITION_TEAM_LEADER) ?>';
    teamLeaderWrap.style.display = isMemberPosition ? 'block' : 'none';
    captainWrap.style.display = isTeamLeaderPosition ? 'block' : 'none';

    if (!isMemberPosition && teamLeaderSelect) {
        teamLeaderSelect.value = '';
    }
    if (!isTeamLeaderPosition && captainSelect) {
        captainSelect.value = '';
    }
}

if (memberPositionSelect) {
    memberPositionSelect.addEventListener('change', updatePositionVisibility);
    updatePositionVisibility();
}
</script>

</body>
</html>
