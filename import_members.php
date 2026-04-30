<?php
include('session_check.php');

include('db.php');

$isScopedAdmin = !bgi_is_super_admin();
$isPairScopedAdmin = bgi_is_idara_admin();
$isMohallaAdmin = bgi_is_mohalla_admin();
$availableScopePairs = [];
$teamLeaderOptions = [];
$captainOptions = [];

if (!bgi_can_manage_members()) {
    header('Location: ' . bgi_home_path_for_current_user());
    exit;
}

foreach (bgi_get_scope_options($conn) as $scopeOption) {
    $idara = bgi_normalize_scope_value($scopeOption['idara'] ?? '', BGI_DEFAULT_IDARA);
    $mohalla = bgi_normalize_scope_value($scopeOption['mohalla'] ?? '', BGI_DEFAULT_MOHALLA);

    if ($isMohallaAdmin && strcasecmp($mohalla, bgi_current_scope_mohalla()) !== 0) {
        continue;
    }

    $availableScopePairs[$idara . '||' . $mohalla] = true;
}

$teamLeaderSql = "SELECT its_id, member_name, idara, mohalla, captain_its_id FROM members WHERE position = ?";
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

$pageTitle = 'Import Members';
$pageMessage = '';
$errorItems = [];

function normalize_import_header(string $value): string
{
    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
    $value = strtolower(trim($value));
    $value = str_replace(['_', '-'], ' ', $value);
    return preg_replace('/\s+/', ' ', $value);
}

function build_import_column_map(array $headerRow): array
{
    $aliases = [
        'bgi_id' => ['bgi id', 'bgi_id'],
        'idara' => ['idara'],
        'mohalla' => ['mohalla'],
        'its_id' => ['its id', 'its_id'],
        'member_name' => ['member name', 'member_name', 'full name', 'full_name', 'name'],
        'position' => ['position', 'member position'],
        'team_leader_its_id' => ['team leader its id', 'team_leader_its_id', 'team leader its', 'team leader', 'leader its id', 'leader its'],
        'captain_its_id' => ['captain its id', 'captain_its_id', 'captain its', 'captain', 'captain id'],
        'email' => ['email', 'email address', 'e mail'],
        'phone' => ['phone', 'phone number', 'mobile', 'mobile number', 'mobile no', 'mobile no.'],
    ];

    $normalizedHeaders = array_map(static function ($value) {
        return normalize_import_header((string) $value);
    }, $headerRow);

    $map = [];
    foreach ($aliases as $field => $possibleHeaders) {
        foreach ($normalizedHeaders as $index => $header) {
            if (in_array($header, $possibleHeaders, true)) {
                $map[$field] = $index;
                break;
            }
        }
    }

    $requiredFields = ['bgi_id', 'its_id', 'member_name', 'phone'];
    foreach ($requiredFields as $field) {
        if (!array_key_exists($field, $map)) {
            return [];
        }
    }

    return $map;
}

function get_import_value(array $row, ?int $index): string
{
    if ($index === null || !array_key_exists($index, $row)) {
        return '';
    }

    return trim((string) $row[$index]);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['csv_file'])) {
    $file_tmp = $_FILES['csv_file']['tmp_name'];

    if (($handle = fopen($file_tmp, 'r')) !== FALSE) {
        $row = 0;
        $errors = [];
        $insertedCount = 0;
        $updatedCount = 0;
        $columnMap = [];
        $hasHeaderRow = false;
        $itsLookupStmt = $conn->prepare("SELECT id, email, position FROM members WHERE its_id = ?");
        $bgiConflictStmt = $conn->prepare("SELECT id FROM members WHERE bgi_id = ? AND its_id <> ? LIMIT 1");
        $insertStmt = $conn->prepare("INSERT INTO members (bgi_id, idara, mohalla, its_id, member_name, position, team_leader_its_id, captain_its_id, email, phone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $updateStmt = $conn->prepare("UPDATE members SET bgi_id = ?, idara = ?, mohalla = ?, its_id = ?, member_name = ?, position = ?, team_leader_its_id = ?, captain_its_id = ?, email = ?, phone = ? WHERE id = ?");
        $attendanceUpdateStmt = $conn->prepare("UPDATE attendance SET bgi_id = ?, idara = ?, mohalla = ?, its_id = ?, member_name = ? WHERE member_id = ? OR its_id = ?");

        if (!$itsLookupStmt || !$bgiConflictStmt || !$insertStmt || !$updateStmt || !$attendanceUpdateStmt) {
            fclose($handle);
            $pageTitle = 'Import Failed';
            $pageMessage = 'The importer could not prepare the database statements.';
            $errorItems = ['Database setup error: ' . mysqli_error($conn)];
            goto render_import_page;
        }

        while (($data = fgetcsv($handle, 1000, ',')) !== FALSE) {
            $row++;

            if ($row == 1 && isset($data[0])) {
                $data[0] = preg_replace('/^\xEF\xBB\xBF/', '', $data[0]);
            }

            $nonEmptyCells = array_filter($data, static function ($value) {
                return trim((string) $value) !== '';
            });
            if (count($nonEmptyCells) === 0) {
                continue;
            }

            if ($row == 1) {
                $columnMap = build_import_column_map($data);
                if (!empty($columnMap)) {
                    $hasHeaderRow = true;
                    continue;
                }
            }

            if ($hasHeaderRow) {
                $bgi_id = get_import_value($data, $columnMap['bgi_id'] ?? null);
                if ($isPairScopedAdmin) {
                    $idara = bgi_current_scope_idara();
                    $mohalla = bgi_current_scope_mohalla();
                } elseif ($isMohallaAdmin) {
                    $idara = get_import_value($data, $columnMap['idara'] ?? null);
                    $mohalla = bgi_current_scope_mohalla();
                } else {
                    $idara = get_import_value($data, $columnMap['idara'] ?? null);
                    $mohalla = get_import_value($data, $columnMap['mohalla'] ?? null);
                }
                $its_id = get_import_value($data, $columnMap['its_id'] ?? null);
                $member_name = get_import_value($data, $columnMap['member_name'] ?? null);
                $position = bgi_normalize_member_position(get_import_value($data, $columnMap['position'] ?? null));
                $teamLeaderItsId = get_import_value($data, $columnMap['team_leader_its_id'] ?? null);
                $captainItsId = get_import_value($data, $columnMap['captain_its_id'] ?? null);
                $email = get_import_value($data, $columnMap['email'] ?? null);
                $phone = get_import_value($data, $columnMap['phone'] ?? null);
                $emailProvided = array_key_exists('email', $columnMap);
            } else {
                if (count($data) < 4) {
                    $errors[] = "Row $row: Incomplete data.";
                    continue;
                }

                $bgi_id = trim((string) $data[0]);
                if ($isPairScopedAdmin) {
                    $idara = bgi_current_scope_idara();
                    $mohalla = bgi_current_scope_mohalla();
                } elseif ($isMohallaAdmin) {
                    $idara = BGI_DEFAULT_IDARA;
                    $mohalla = bgi_current_scope_mohalla();
                } else {
                    $idara = BGI_DEFAULT_IDARA;
                    $mohalla = BGI_DEFAULT_MOHALLA;
                }
                $its_id = trim((string) $data[1]);
                $member_name = trim((string) $data[2]);
                $position = BGI_POSITION_MEMBER;
                $teamLeaderItsId = '';
                $captainItsId = '';
                if (count($data) >= 5) {
                    $email = trim((string) $data[3]);
                    $phone = trim((string) $data[4]);
                    $emailProvided = true;
                } else {
                    $email = '';
                    $phone = trim((string) $data[3]);
                    $emailProvided = false;
                }
            }

            // Validate required fields
            if ($bgi_id === '' || $its_id === '' || $member_name === '' || $phone === '') {
                $errors[] = "Row $row: Missing required fields.";
                continue;
            }

            // Validate formats
            if (!preg_match('/^\d{1,4}$/', $bgi_id)) {
                $errors[] = "Row $row: BGI ID must be 1 to 4 digits.";
                continue;
            }

            if (!preg_match('/^\d{8}$/', $its_id)) {
                $errors[] = "Row $row: ITS ID must be exactly 8 digits.";
                continue;
            }

            if (!preg_match('/^\d{8}$/', $phone)) {
                $errors[] = "Row $row: Phone must be exactly 8 digits.";
                continue;
            }

            $idara = bgi_normalize_scope_value($idara, BGI_DEFAULT_IDARA);
            $mohalla = bgi_normalize_scope_value($mohalla, BGI_DEFAULT_MOHALLA);
            $position = bgi_normalize_member_position($position ?? BGI_POSITION_MEMBER);
            $teamLeaderItsId = trim((string) ($teamLeaderItsId ?? ''));
            $captainItsId = trim((string) ($captainItsId ?? ''));

            if (!isset($availableScopePairs[$idara . '||' . $mohalla])) {
                $errors[] = "Row $row: Invalid Idara and Mohalla mapping for your allowed scope.";
                continue;
            }

            if ($position !== BGI_POSITION_MEMBER) {
                $teamLeaderItsId = '';
            }

            if ($position !== BGI_POSITION_TEAM_LEADER) {
                $captainItsId = '';
            }

            if ($position === BGI_POSITION_MEMBER && $teamLeaderItsId === '') {
                $errors[] = "Row $row: Members must be linked to a Team Leader.";
                continue;
            }

            if ($position === BGI_POSITION_TEAM_LEADER && $captainItsId === '') {
                $errors[] = "Row $row: Team Leaders must be linked to a Captain.";
                continue;
            }

            if ($position === BGI_POSITION_MEMBER && $teamLeaderItsId !== '') {
                if ($teamLeaderItsId === $its_id) {
                    $errors[] = "Row $row: A member cannot be assigned to themselves as Team Leader.";
                    continue;
                }

                $teamLeaderOption = $teamLeaderOptions[$teamLeaderItsId] ?? null;
                if (
                    !$teamLeaderOption ||
                    strcasecmp((string) ($teamLeaderOption['idara'] ?? ''), $idara) !== 0 ||
                    strcasecmp((string) ($teamLeaderOption['mohalla'] ?? ''), $mohalla) !== 0
                ) {
                    $errors[] = "Row $row: Team Leader must exist in the same Idara and Mohalla.";
                    continue;
                }

                $teamLeaderOption = $teamLeaderOptions[$teamLeaderItsId];
                $captainItsId = trim((string) ($teamLeaderOption['captain_its_id'] ?? ''));
            }

            if ($position === BGI_POSITION_TEAM_LEADER && $captainItsId !== '') {
                if ($captainItsId === $its_id) {
                    $errors[] = "Row $row: A Team Leader cannot be assigned to themselves as Captain.";
                    continue;
                }

                $captainOption = $captainOptions[$captainItsId] ?? null;
                if (
                    !$captainOption ||
                    strcasecmp((string) ($captainOption['idara'] ?? ''), $idara) !== 0 ||
                    strcasecmp((string) ($captainOption['mohalla'] ?? ''), $mohalla) !== 0
                ) {
                    $errors[] = "Row $row: Captain must exist in the same Idara and Mohalla.";
                    continue;
                }
            }

            $itsLookupStmt->bind_param("s", $its_id);
            $itsLookupStmt->execute();
            $existingResult = $itsLookupStmt->get_result();
            $matchingMembers = [];
            while ($existingResult && ($existingRow = $existingResult->fetch_assoc())) {
                $matchingMembers[] = $existingRow;
            }

            if (count($matchingMembers) > 1) {
                $errors[] = "Row $row: ITS ID matches multiple existing members.";
                continue;
            }

            $bgiConflictStmt->bind_param("ss", $bgi_id, $its_id);
            $bgiConflictStmt->execute();
            $bgiConflictResult = $bgiConflictStmt->get_result();
            if ($bgiConflictResult && $bgiConflictResult->num_rows > 0) {
                $errors[] = "Row $row: BGI ID already belongs to another ITS ID.";
                continue;
            }

            if (count($matchingMembers) === 1) {
                $existingMember = $matchingMembers[0];
                $emailForSave = $emailProvided ? $email : (string) ($existingMember['email'] ?? '');
                $memberId = (int) $existingMember['id'];
                $existingPosition = bgi_normalize_member_position($existingMember['position'] ?? BGI_POSITION_MEMBER);
                $teamLeaderValue = $teamLeaderItsId !== '' ? $teamLeaderItsId : null;
                $captainValue = $captainItsId !== '' ? $captainItsId : null;

                $updateStmt->bind_param("ssssssssssi", $bgi_id, $idara, $mohalla, $its_id, $member_name, $position, $teamLeaderValue, $captainValue, $emailForSave, $phone, $memberId);
                if (!$updateStmt->execute()) {
                    $errors[] = "Row $row: Database error - " . $updateStmt->error;
                    continue;
                }

                $attendanceUpdateStmt->bind_param("sssssis", $bgi_id, $idara, $mohalla, $its_id, $member_name, $memberId, $its_id);
                if (!$attendanceUpdateStmt->execute()) {
                    $errors[] = "Row $row: Attendance sync error - " . $attendanceUpdateStmt->error;
                    continue;
                }

                if ($existingPosition === BGI_POSITION_TEAM_LEADER && $position !== BGI_POSITION_TEAM_LEADER) {
                    $clearAssignmentsStmt = $conn->prepare("UPDATE members SET team_leader_its_id = NULL, captain_its_id = NULL WHERE team_leader_its_id = ?");
                    if (!$clearAssignmentsStmt) {
                        $errors[] = "Row $row: Team cleanup error - " . $conn->error;
                        continue;
                    }

                    $clearAssignmentsStmt->bind_param("s", $its_id);
                    if (!$clearAssignmentsStmt->execute()) {
                        $errors[] = "Row $row: Team cleanup error - " . $clearAssignmentsStmt->error;
                        $clearAssignmentsStmt->close();
                        continue;
                    }
                    $clearAssignmentsStmt->close();
                }

                if ($position === BGI_POSITION_TEAM_LEADER) {
                    $syncFollowerCaptainsStmt = $conn->prepare("UPDATE members SET captain_its_id = ? WHERE team_leader_its_id = ?");
                    if (!$syncFollowerCaptainsStmt) {
                        $errors[] = "Row $row: Team sync error - " . $conn->error;
                        continue;
                    }

                    $syncFollowerCaptainsStmt->bind_param("ss", $captainItsId, $its_id);
                    if (!$syncFollowerCaptainsStmt->execute()) {
                        $errors[] = "Row $row: Team sync error - " . $syncFollowerCaptainsStmt->error;
                        $syncFollowerCaptainsStmt->close();
                        continue;
                    }
                    $syncFollowerCaptainsStmt->close();
                }

                if ($existingPosition === BGI_POSITION_CAPTAIN && $position !== BGI_POSITION_CAPTAIN) {
                    $clearCaptainAssignmentsStmt = $conn->prepare("UPDATE members SET captain_its_id = NULL WHERE captain_its_id = ?");
                    if (!$clearCaptainAssignmentsStmt) {
                        $errors[] = "Row $row: Captain cleanup error - " . $conn->error;
                        continue;
                    }

                    $clearCaptainAssignmentsStmt->bind_param("s", $its_id);
                    if (!$clearCaptainAssignmentsStmt->execute()) {
                        $errors[] = "Row $row: Captain cleanup error - " . $clearCaptainAssignmentsStmt->error;
                        $clearCaptainAssignmentsStmt->close();
                        continue;
                    }
                    $clearCaptainAssignmentsStmt->close();
                }

                if ($position === BGI_POSITION_TEAM_LEADER) {
                    $teamLeaderOptions[$its_id] = [
                        'its_id' => $its_id,
                        'member_name' => $member_name,
                        'idara' => $idara,
                        'mohalla' => $mohalla,
                        'captain_its_id' => $captainItsId,
                    ];
                } elseif ($existingPosition === BGI_POSITION_TEAM_LEADER) {
                    unset($teamLeaderOptions[$its_id]);
                }

                if ($position === BGI_POSITION_CAPTAIN) {
                    $captainOptions[$its_id] = [
                        'its_id' => $its_id,
                        'member_name' => $member_name,
                        'idara' => $idara,
                        'mohalla' => $mohalla,
                    ];
                } elseif ($existingPosition === BGI_POSITION_CAPTAIN) {
                    unset($captainOptions[$its_id]);
                }

                $updatedCount++;
                continue;
            }

            $teamLeaderValue = $teamLeaderItsId !== '' ? $teamLeaderItsId : null;
            $captainValue = $captainItsId !== '' ? $captainItsId : null;
            $insertStmt->bind_param("ssssssssss", $bgi_id, $idara, $mohalla, $its_id, $member_name, $position, $teamLeaderValue, $captainValue, $email, $phone);
            if (!$insertStmt->execute()) {
                $errors[] = "Row $row: Database error - " . $insertStmt->error;
                continue;
            }

            if ($position === BGI_POSITION_TEAM_LEADER) {
                $teamLeaderOptions[$its_id] = [
                    'its_id' => $its_id,
                    'member_name' => $member_name,
                    'idara' => $idara,
                    'mohalla' => $mohalla,
                    'captain_its_id' => $captainItsId,
                ];
            }

            if ($position === BGI_POSITION_CAPTAIN) {
                $captainOptions[$its_id] = [
                    'its_id' => $its_id,
                    'member_name' => $member_name,
                    'idara' => $idara,
                    'mohalla' => $mohalla,
                ];
            }

            $insertedCount++;
        }

        fclose($handle);
        $itsLookupStmt->close();
        $bgiConflictStmt->close();
        $insertStmt->close();
        $updateStmt->close();
        $attendanceUpdateStmt->close();

        if (count($errors) > 0) {
            $pageTitle = 'Import Finished With Errors';
            $pageMessage = 'Imported ' . $insertedCount . ' new member(s) and updated ' . $updatedCount . ' existing member(s). Some rows still need attention.';
            $errorItems = $errors;
        } else {
            $_SESSION['flash_message'] = 'Import complete: ' . $insertedCount . ' new member(s) added and ' . $updatedCount . ' existing member(s) updated.';
            $_SESSION['flash_type'] = 'success';
            header('Location: admin_members.php');
            exit;
        }
    } else {
        $pageTitle = 'Import Failed';
        $pageMessage = 'The uploaded file could not be opened. Please try again with a valid CSV file.';
        $errorItems = ['Error opening the uploaded file.'];
    }
} else {
    $pageTitle = 'No File Uploaded';
    $pageMessage = 'Please choose a CSV file before starting the import.';
    $errorItems = ['No file was uploaded.'];
}

render_import_page:
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars(bgi_app_name()) ?></title>
    <link rel="stylesheet" href="app.css">
</head>
<body class="app-page page-form">
    <div class="container">
        <a href="add_member.php" class="btn secondary back-btn">Back to Member Import</a>
        <h2><?= htmlspecialchars($pageTitle) ?></h2>
        <p class="page-intro"><?= htmlspecialchars($pageMessage) ?></p>

        <?php if (!empty($errorItems)): ?>
            <div class="message error">
                The import completed with issues. Each item below points to a row that needs attention.
            </div>
            <ul class="import-results">
                <?php foreach ($errorItems as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</body>
</html>
