<?php
include('session_check.php');

include('db.php');

$isScopedAdmin = !bgi_is_super_admin();
$isPairScopedAdmin = bgi_is_idara_admin();
$isMohallaAdmin = bgi_is_mohalla_admin();
$canManageMembers = bgi_can_manage_members();
$scopeOptions = bgi_get_scope_options($conn);
$availableScopePairs = [];
$idaraOptions = [];
$teamLeaderOptions = [];
$captainOptions = [];

if (!$canManageMembers) {
    header('Location: ' . bgi_home_path_for_current_user());
    exit;
}

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

$memberId = filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT);
if (!$memberId) {
    header('Location: admin_members.php');
    exit;
}

$memberStmt = $conn->prepare("SELECT id, bgi_id, idara, mohalla, its_id, member_name, position, team_leader_its_id, captain_its_id, email, phone FROM members WHERE id = ? LIMIT 1");
$memberStmt->bind_param("i", $memberId);
$memberStmt->execute();
$memberResult = $memberStmt->get_result();
$member = $memberResult->fetch_assoc();
$memberStmt->close();

if (!$member) {
    $conn->close();
    header('Location: admin_members.php');
    exit;
}

if ($isScopedAdmin && !bgi_scope_matches_current($member['idara'] ?? '', $member['mohalla'] ?? '')) {
    $conn->close();
    $_SESSION['flash_message'] = 'You can edit only members inside your assigned Idara and Mohalla.';
    $_SESSION['flash_type'] = 'error';
    header('Location: admin_members.php');
    exit;
}

$error = '';
$selectedIdara = bgi_normalize_scope_value($member['idara'] ?? '', BGI_DEFAULT_IDARA);
$selectedMohalla = bgi_normalize_scope_value($member['mohalla'] ?? '', BGI_DEFAULT_MOHALLA);
$selectedPosition = bgi_normalize_member_position($member['position'] ?? BGI_POSITION_MEMBER);
$selectedTeamLeaderItsId = trim((string) ($member['team_leader_its_id'] ?? ''));
$selectedCaptainItsId = trim((string) ($member['captain_its_id'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $itsId = trim($_POST['its_id'] ?? '');
    $bgiId = trim($_POST['bgi_id'] ?? '');
    $memberName = trim($_POST['member_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $selectedPosition = bgi_normalize_member_position($_POST['position'] ?? BGI_POSITION_MEMBER);
    $selectedTeamLeaderItsId = trim($_POST['team_leader_its_id'] ?? '');
    $selectedCaptainItsId = trim($_POST['captain_its_id'] ?? '');
    $oldItsId = $member['its_id'];
    $oldPosition = bgi_normalize_member_position($member['position'] ?? BGI_POSITION_MEMBER);
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

    if (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        $error = 'Invalid request token. Please refresh the page and try again.';
    } elseif (!preg_match('/^\d{8}$/', $itsId)) {
        $error = 'ITS ID must be exactly 8 numeric digits.';
    } elseif (!preg_match('/^\d{1,4}$/', $bgiId)) {
        $error = 'BGI ID must be up to 4 numeric digits.';
    } elseif ($memberName === '') {
        $error = 'Member name is required.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Email must be valid.';
    } elseif (!preg_match('/^\d{8}$/', $phone)) {
        $error = 'Phone must be exactly 8 numeric digits.';
    } elseif (!isset($availableScopePairs[$scopeKey])) {
        $error = 'Please choose a valid saved Idara and Mohalla pair.';
    } elseif ($selectedPosition === BGI_POSITION_MEMBER && $selectedTeamLeaderItsId === '') {
        $error = 'Please choose a Team Leader for this member.';
    } elseif ($selectedPosition === BGI_POSITION_TEAM_LEADER && $selectedCaptainItsId === '') {
        $error = 'Please choose a Captain for this Team Leader.';
    } elseif ($selectedPosition === BGI_POSITION_MEMBER && $selectedTeamLeaderItsId !== '' && $selectedTeamLeaderItsId === $itsId) {
        $error = 'A member cannot be assigned to themselves as Team Leader.';
    } elseif ($selectedPosition === BGI_POSITION_TEAM_LEADER && $selectedCaptainItsId !== '' && $selectedCaptainItsId === $itsId) {
        $error = 'A Team Leader cannot be assigned to themselves as Captain.';
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
            $error = 'Please choose a Team Leader from the same Idara and Mohalla.';
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
            $error = 'Please choose a Captain from the same Idara and Mohalla.';
        }
        $captainValidateStmt->close();
    }

    if ($error === '') {
        if ($selectedPosition !== BGI_POSITION_MEMBER) {
            $selectedTeamLeaderItsId = '';
        }
        if ($selectedPosition !== BGI_POSITION_TEAM_LEADER) {
            $selectedCaptainItsId = '';
        }

        $itsDuplicateStmt = $conn->prepare("SELECT id FROM members WHERE its_id = ? AND id <> ? LIMIT 1");
        $itsDuplicateStmt->bind_param("si", $itsId, $memberId);
        $itsDuplicateStmt->execute();
        $itsDuplicateResult = $itsDuplicateStmt->get_result();

        if ($itsDuplicateResult->num_rows > 0) {
            $error = 'ITS ID already belongs to another member.';
        } else {
            $bgiConflictStmt = $conn->prepare("SELECT id FROM members WHERE bgi_id = ? AND its_id <> ? AND id <> ? LIMIT 1");
            $bgiConflictStmt->bind_param("ssi", $bgiId, $itsId, $memberId);
            $bgiConflictStmt->execute();
            $bgiConflictResult = $bgiConflictStmt->get_result();

            if ($bgiConflictResult->num_rows > 0) {
                $error = 'BGI ID already belongs to another member.';
            } else {
                $conn->begin_transaction();

                try {
                    $updateStmt = $conn->prepare("UPDATE members SET bgi_id = ?, idara = ?, mohalla = ?, its_id = ?, member_name = ?, position = ?, team_leader_its_id = ?, captain_its_id = ?, email = ?, phone = ? WHERE id = ?");
                    $teamLeaderValue = $selectedTeamLeaderItsId !== '' ? $selectedTeamLeaderItsId : null;
                    $captainValue = $selectedCaptainItsId !== '' ? $selectedCaptainItsId : null;
                    $updateStmt->bind_param("ssssssssssi", $bgiId, $selectedIdara, $selectedMohalla, $itsId, $memberName, $selectedPosition, $teamLeaderValue, $captainValue, $email, $phone, $memberId);

                    if (!$updateStmt->execute()) {
                        throw new Exception($updateStmt->error);
                    }
                    $updateStmt->close();

                    if ($oldItsId !== $itsId) {
                        $teamAssignmentStmt = $conn->prepare("UPDATE members SET team_leader_its_id = ? WHERE team_leader_its_id = ?");
                        $teamAssignmentStmt->bind_param("ss", $itsId, $oldItsId);
                        if (!$teamAssignmentStmt->execute()) {
                            throw new Exception($teamAssignmentStmt->error);
                        }
                        $teamAssignmentStmt->close();

                        $captainAssignmentRenameStmt = $conn->prepare("UPDATE members SET captain_its_id = ? WHERE captain_its_id = ?");
                        $captainAssignmentRenameStmt->bind_param("ss", $itsId, $oldItsId);
                        if (!$captainAssignmentRenameStmt->execute()) {
                            throw new Exception($captainAssignmentRenameStmt->error);
                        }
                        $captainAssignmentRenameStmt->close();
                    }

                    if ($selectedPosition === BGI_POSITION_TEAM_LEADER) {
                        $syncFollowerCaptainsStmt = $conn->prepare("UPDATE members SET captain_its_id = ? WHERE team_leader_its_id = ?");
                        $syncFollowerCaptainsStmt->bind_param("ss", $selectedCaptainItsId, $itsId);
                        if (!$syncFollowerCaptainsStmt->execute()) {
                            throw new Exception($syncFollowerCaptainsStmt->error);
                        }
                        $syncFollowerCaptainsStmt->close();
                    }

                    if ($oldPosition === BGI_POSITION_TEAM_LEADER && $selectedPosition !== BGI_POSITION_TEAM_LEADER) {
                        $clearAssignmentsStmt = $conn->prepare("UPDATE members SET team_leader_its_id = NULL, captain_its_id = NULL WHERE team_leader_its_id = ?");
                        $clearAssignmentsStmt->bind_param("s", $itsId);
                        if (!$clearAssignmentsStmt->execute()) {
                            throw new Exception($clearAssignmentsStmt->error);
                        }
                        $clearAssignmentsStmt->close();
                    }

                    if ($oldPosition === BGI_POSITION_CAPTAIN && $selectedPosition !== BGI_POSITION_CAPTAIN) {
                        $clearCaptainAssignmentsStmt = $conn->prepare("UPDATE members SET captain_its_id = NULL WHERE captain_its_id = ?");
                        $clearCaptainAssignmentsStmt->bind_param("s", $itsId);
                        if (!$clearCaptainAssignmentsStmt->execute()) {
                            throw new Exception($clearCaptainAssignmentsStmt->error);
                        }
                        $clearCaptainAssignmentsStmt->close();
                    }

                    $attendanceUpdateStmt = $conn->prepare("UPDATE attendance SET bgi_id = ?, idara = ?, mohalla = ?, its_id = ?, member_name = ? WHERE member_id = ? OR its_id = ?");
                    $attendanceUpdateStmt->bind_param("sssssis", $bgiId, $selectedIdara, $selectedMohalla, $itsId, $memberName, $memberId, $oldItsId);
                    if (!$attendanceUpdateStmt->execute()) {
                        throw new Exception($attendanceUpdateStmt->error);
                    }
                    $attendanceUpdateStmt->close();

                    $conn->commit();

                    $_SESSION['flash_message'] = 'Member updated successfully.';
                    $_SESSION['flash_type'] = 'success';
                    $bgiConflictStmt->close();
                    $itsDuplicateStmt->close();
                    $conn->close();
                    header('Location: admin_members.php');
                    exit;
                } catch (Throwable $e) {
                    $conn->rollback();
                    $error = 'Error updating member: ' . $e->getMessage();
                }
            }

            $bgiConflictStmt->close();
        }

        $itsDuplicateStmt->close();
    }
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Member - <?= htmlspecialchars(bgi_app_name()) ?></title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f6f8; padding: 20px; }
        .form-container { background: white; max-width: 600px; margin: 20px auto; padding: 30px; border-radius: 10px; box-shadow: 0 0 10px #ccc; }
        .form-container h2 { text-align: center; margin-bottom: 20px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; }
        input[type="text"], input[type="email"] { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 5px; box-sizing: border-box; }
        .btn { background: #2E8B57; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; }
        .btn:hover { background: #246B46; }
        .back-btn { display: inline-block; margin-bottom: 20px; text-decoration: none; }
        .error-message { color: #842029; background: #fdecea; border: 1px solid #f5c2c7; padding: 10px 12px; border-radius: 6px; margin-bottom: 15px; }
    </style>
    <link rel="stylesheet" href="app.css">
</head>
<body class="app-page">

<a href="admin_members.php" class="btn back-btn">Back to Members</a>

<div class="form-container">
    <h2>Edit Member</h2>
    <p class="page-intro">Update member identity and contact information without leaving the admin workspace.</p>

    <?php if ($error !== ''): ?>
        <div class="error-message"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

        <div class="form-group">
            <label>ITS ID (exact 8 digits)</label>
            <input type="text" name="its_id" value="<?= htmlspecialchars($itsId ?? $member['its_id']) ?>" required pattern="\d{8}" maxlength="8" minlength="8">
        </div>

        <div class="form-group">
            <label>BGI ID (up to 4 digits)</label>
            <input type="text" name="bgi_id" value="<?= htmlspecialchars($bgiId ?? $member['bgi_id']) ?>" required pattern="\d{1,4}" maxlength="4">
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
            <input type="text" name="member_name" value="<?= htmlspecialchars($memberName ?? $member['member_name']) ?>" required>
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
                    <?php if ($teamLeaderItsId === ($member['its_id'] ?? '')) { continue; } ?>
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
                    <?php if ($captainItsId === ($member['its_id'] ?? '')) { continue; } ?>
                    <option value="<?= htmlspecialchars($captainItsId) ?>" <?= $selectedCaptainItsId === $captainItsId ? 'selected' : '' ?>>
                        <?= htmlspecialchars($captainOption['member_name'] . ' (' . $captainItsId . ') - ' . ($captainOption['idara'] ?? BGI_DEFAULT_IDARA) . ' / ' . ($captainOption['mohalla'] ?? BGI_DEFAULT_MOHALLA)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="small-note">Required when the Position is Team Leader.</div>
        </div>

        <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" value="<?= htmlspecialchars($email ?? $member['email']) ?>">
        </div>

        <div class="form-group">
            <label>Phone (exact 8 digits)</label>
            <input type="text" name="phone" value="<?= htmlspecialchars($phone ?? $member['phone']) ?>" required pattern="\d{8}" maxlength="8" minlength="8">
        </div>

        <button type="submit" class="btn">Update Member</button>
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
