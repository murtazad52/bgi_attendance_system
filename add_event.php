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

// Ensure event_assignments table exists
mysqli_query($conn,
    "CREATE TABLE IF NOT EXISTS event_assignments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        event_id INT NOT NULL,
        member_id INT NOT NULL,
        its_id VARCHAR(20) NOT NULL,
        member_name VARCHAR(255) DEFAULT '',
        idara VARCHAR(100) DEFAULT '',
        mohalla VARCHAR(100) DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY idx_event_id (event_id),
        KEY idx_its_id (its_id),
        UNIQUE KEY unique_event_member (event_id, member_id)
    )"
);

$scopeOptions = bgi_get_scope_options($conn);
$savedLocations = bgi_get_event_locations($conn);
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

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

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

    $csrfToken = trim($_POST['csrf_token'] ?? '');
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        $error = 'Invalid request token. Please refresh the page and try again.';
    } elseif ($event_name === '' || $event_date === '' || $reporting_time === '') {
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

        $geoLat = trim($_POST['latitude'] ?? '');
        $geoLng = trim($_POST['longitude'] ?? '');
        $geoRadius = trim($_POST['radius_meters'] ?? '');
        $geoLatVal = $geoLat !== '' && is_numeric($geoLat) ? (float) $geoLat : null;
        $geoLngVal = $geoLng !== '' && is_numeric($geoLng) ? (float) $geoLng : null;
        $geoRadiusVal = $geoRadius !== '' && ctype_digit($geoRadius) ? (int) $geoRadius : 200;

        if (!isset($error)) {
            mysqli_begin_transaction($conn);

            try {
                $createdEvents = [];
                $createdEventIds = []; // maps "idara||mohalla" => event_id
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
                    if ($geoLatVal !== null && $geoLngVal !== null) {
                        $insertStmt = $conn->prepare(
                            "INSERT INTO events (event_name, event_code, idara, mohalla, event_date, reporting_time, latitude, longitude, radius_meters)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                        );
                        if (!$insertStmt) {
                            throw new Exception('Unable to prepare event creation.');
                        }
                        $insertStmt->bind_param("ssssssddi", $event_name, $eventCode, $targetIdara, $targetMohalla, $event_date, $reporting_time, $geoLatVal, $geoLngVal, $geoRadiusVal);
                    } else {
                        $insertStmt = $conn->prepare(
                            "INSERT INTO events (event_name, event_code, idara, mohalla, event_date, reporting_time)
                             VALUES (?, ?, ?, ?, ?, ?)"
                        );
                        if (!$insertStmt) {
                            throw new Exception('Unable to prepare event creation.');
                        }
                        $insertStmt->bind_param("ssssss", $event_name, $eventCode, $targetIdara, $targetMohalla, $event_date, $reporting_time);
                    }

                    if (!$insertStmt->execute()) {
                        throw new Exception($insertStmt->error);
                    }
                    $newEventId = (int) $conn->insert_id;
                    $insertStmt->close();

                    $createdEventIds[$targetIdara . '||' . $targetMohalla] = $newEventId;
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

                    // Save member khidmat assignments for each newly created event
                    foreach ($createdEventIds as $evScope => $newEventId) {
                        [$evIdara, $evMohalla] = explode('||', $evScope, 2);

                        // Determine which member IDs to assign:
                        // If a single-scope form submitted checked_members[], use those; otherwise assign all members of the scope.
                        $submittedMembers = isset($_POST['checked_members']) && is_array($_POST['checked_members'])
                            ? $_POST['checked_members']
                            : null;

                        if ($submittedMembers !== null && count($targetPairs) === 1) {
                            // Single-scope creation: use admin-selected members
                            foreach ($submittedMembers as $mItsId) {
                                $mItsId = trim((string) $mItsId);
                                if (!preg_match('/^\d{8}$/', $mItsId)) continue;
                                $mLookup = $conn->prepare("SELECT id, member_name FROM members WHERE its_id = ? AND idara = ? AND mohalla = ? LIMIT 1");
                                if (!$mLookup) continue;
                                $mLookup->bind_param('sss', $mItsId, $evIdara, $evMohalla);
                                $mLookup->execute();
                                $mRow = $mLookup->get_result()->fetch_assoc();
                                $mLookup->close();
                                if (!$mRow) continue;
                                $aStmt = $conn->prepare("INSERT IGNORE INTO event_assignments (event_id, member_id, its_id, member_name, idara, mohalla) VALUES (?,?,?,?,?,?)");
                                if (!$aStmt) continue;
                                $aStmt->bind_param('iissss', $newEventId, $mRow['id'], $mItsId, $mRow['member_name'], $evIdara, $evMohalla);
                                $aStmt->execute();
                                $aStmt->close();
                            }
                        } else {
                            // Multi-scope or no selection: assign ALL members of this scope
                            $allMembersRes = $conn->query(
                                "SELECT id, its_id, member_name FROM members
                                 WHERE idara = '" . mysqli_real_escape_string($conn, $evIdara) . "'
                                   AND mohalla = '" . mysqli_real_escape_string($conn, $evMohalla) . "'"
                            );
                            if ($allMembersRes) {
                                while ($mRow = $allMembersRes->fetch_assoc()) {
                                    $aStmt = $conn->prepare("INSERT IGNORE INTO event_assignments (event_id, member_id, its_id, member_name, idara, mohalla) VALUES (?,?,?,?,?,?)");
                                    if (!$aStmt) continue;
                                    $aStmt->bind_param('iissss', $newEventId, $mRow['id'], $mRow['its_id'], $mRow['member_name'], $evIdara, $evMohalla);
                                    $aStmt->execute();
                                    $aStmt->close();
                                }
                            }
                        }
                    }

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
    <link rel="stylesheet" href="app.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <style>
        #event-map-preview { height: 280px; border-radius: 10px; border: 1px solid #d7e0db; margin-top: 0.75rem; display: none; }
    </style>
</head>
<body class="app-page page-form">

<div class="topbar">
    <div><strong><?= htmlspecialchars(bgi_app_name()) ?></strong></div>
    <div>
        <a href="admin_events.php" class="back">← Manage Events</a>
        <a href="logout.php" class="logout" style="margin-left:8px;">Logout</a>
    </div>
</div>

<div class="form-container">
    <h2>Add New Event</h2>
    <p class="page-intro">
        <?= $isPairScopedAdmin
            ? 'Create a new event inside your current scope: ' . htmlspecialchars(bgi_current_scope_label()) . '.'
            : ($isMohallaAdmin
                ? 'Choose one Idara inside your assigned Mohalla, or leave Idara empty to create separate linked event rows for every Idara in ' . htmlspecialchars(bgi_current_scope_mohalla()) . '.'
                : 'Choose one Idara, one Mohalla, or both. One selection creates separate linked event rows across matching scopes, each with its own event code.') ?>
    </p>

    <?php
    if (isset($error) && $error !== '') {
        echo '<div class="message error">' . htmlspecialchars($error) . '</div>';
    }
    ?>

    <form method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
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

        <hr style="margin:1.5rem 0;border:none;border-top:1px solid #e5e7eb;">

        <!-- Khidmat Member Assignment -->
        <h3 style="margin:0 0 0.25rem;font-size:1rem;">Khidmat Assignment</h3>
        <p style="margin:0 0 0.75rem;font-size:0.85rem;color:#666;">
            Select which members are assigned for khidmat at this event. By default all members of the selected Idara &amp; Mohalla are pre-selected.
        </p>
        <div id="member-assignment-wrap" style="display:none;">
            <div style="display:flex;align-items:center;gap:1rem;margin-bottom:0.5rem;">
                <button type="button" id="btn-select-all" class="btn secondary" style="padding:0.3rem 0.9rem;font-size:0.82rem;" onclick="setAllMembers(true)">Select All</button>
                <button type="button" id="btn-deselect-all" class="btn secondary" style="padding:0.3rem 0.9rem;font-size:0.82rem;" onclick="setAllMembers(false)">Deselect All</button>
                <span id="member-count-label" style="font-size:0.83rem;color:#555;"></span>
            </div>
            <div id="member-list" style="max-height:280px;overflow-y:auto;border:1px solid #d7e0db;border-radius:8px;padding:0.5rem 0.75rem;background:#fafafa;">
                <div style="color:#888;font-size:0.85rem;padding:0.5rem 0;" id="member-loading">Loading members…</div>
            </div>
        </div>
        <div id="member-assignment-hint" style="font-size:0.83rem;color:#888;">
            Select an Idara and Mohalla above to load members.
        </div>

        <hr style="margin:1.5rem 0;border:none;border-top:1px solid #e5e7eb;">
        <h3 style="margin:0 0 0.25rem;font-size:1rem;">Geofence (Optional)</h3>
        <p style="margin:0 0 1rem;font-size:0.85rem;color:#666;">
            Set a location so the mobile app can detect remote check-ins when members are outside the event area.
        </p>

        <?php if ($savedLocations): ?>
        <label for="saved_location_picker">Pick a Saved Location</label>
        <select id="saved_location_picker" style="margin-bottom:0.75rem;">
            <option value="">— Enter manually —</option>
            <?php foreach ($savedLocations as $sl): ?>
                <option value="<?= htmlspecialchars((string)(float)$sl['latitude']) ?>"
                        data-lng="<?= htmlspecialchars((string)(float)$sl['longitude']) ?>"
                        data-radius="<?= (int)$sl['radius_meters'] ?>">
                    <?= htmlspecialchars($sl['name']) ?>
                    (<?= htmlspecialchars($sl['idara'] . ' / ' . $sl['mohalla']) ?>)
                </option>
            <?php endforeach; ?>
        </select>
        <script>
        document.getElementById('saved_location_picker').addEventListener('change', function () {
            var opt = this.options[this.selectedIndex];
            if (this.value) {
                document.getElementById('latitude').value  = this.value;
                document.getElementById('longitude').value = opt.dataset.lng;
                document.getElementById('radius_meters').value = opt.dataset.radius;
            } else {
                document.getElementById('latitude').value  = '';
                document.getElementById('longitude').value = '';
                document.getElementById('radius_meters').value = '200';
            }
        });
        </script>
        <?php endif; ?>

        <div style="display:flex;gap:1rem;flex-wrap:wrap;">
            <div style="flex:1;min-width:160px;">
                <label for="latitude">Latitude</label>
                <input type="number" id="latitude" name="latitude" step="0.0000001" min="-90" max="90"
                       value="<?= htmlspecialchars($_POST['latitude'] ?? '') ?>"
                       placeholder="e.g. 29.3759">
            </div>
            <div style="flex:1;min-width:160px;">
                <label for="longitude">Longitude</label>
                <input type="number" id="longitude" name="longitude" step="0.0000001" min="-180" max="180"
                       value="<?= htmlspecialchars($_POST['longitude'] ?? '') ?>"
                       placeholder="e.g. 47.9774">
            </div>
            <div style="flex:1;min-width:120px;">
                <label for="radius_meters">Radius (meters)</label>
                <input type="number" id="radius_meters" name="radius_meters" min="50" max="10000"
                       value="<?= htmlspecialchars($_POST['radius_meters'] ?? '200') ?>"
                       placeholder="200">
            </div>
        </div>
        <div id="event-map-preview"></div>
        <p style="font-size:0.8rem;color:#888;margin-top:0.5rem;">
            <a href="admin_locations.php" style="color:#176b53;">Manage saved locations →</a>
        </p>

        <button type="submit" class="btn">Add Event</button>
    </form>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
(function () {
    var map = null, marker = null, circle = null;

    function initMap(lat, lng, radius) {
        var el = document.getElementById('event-map-preview');
        el.style.display = 'block';

        if (!map) {
            map = L.map('event-map-preview').setView([lat, lng], 15);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap', maxZoom: 19
            }).addTo(map);
        }

        if (marker) map.removeLayer(marker);
        if (circle) map.removeLayer(circle);

        var icon = L.divIcon({
            className: '',
            html: '<div style="width:16px;height:16px;background:#1B5B49;border:3px solid #E6C760;border-radius:50%;box-shadow:0 2px 5px rgba(0,0,0,0.3)"></div>',
            iconSize: [16, 16], iconAnchor: [8, 8]
        });
        marker = L.marker([lat, lng], { icon: icon }).addTo(map).bindPopup('Event location').openPopup();
        circle = L.circle([lat, lng], { radius: radius, color: '#1B5B49', weight: 2, fillOpacity: 0.1 }).addTo(map);
        map.setView([lat, lng], 15);
        setTimeout(function () { map.invalidateSize(); }, 50);
    }

    function tryUpdate() {
        var lat = parseFloat(document.getElementById('latitude').value);
        var lng = parseFloat(document.getElementById('longitude').value);
        var radius = parseInt(document.getElementById('radius_meters').value) || 200;
        if (!isNaN(lat) && !isNaN(lng) && lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180) {
            initMap(lat, lng, radius);
        }
    }

    var debounce;
    ['latitude', 'longitude'].forEach(function (id) {
        document.getElementById(id).addEventListener('input', function () {
            clearTimeout(debounce);
            debounce = setTimeout(tryUpdate, 500);
        });
    });
    document.getElementById('radius_meters').addEventListener('input', function () {
        if (circle) circle.setRadius(parseInt(this.value) || 200);
    });

    // Also fire when saved-location picker changes (it already fills the fields)
    var picker = document.getElementById('saved_location_picker');
    if (picker) {
        picker.addEventListener('change', function () {
            setTimeout(tryUpdate, 50);
        });
    }
    tryUpdate();
})();
</script>

<script>
// ── Khidmat member assignment ─────────────────────────────────────────────────
(function () {
    var currentIdara   = '';
    var currentMohalla = '';
    var loadTimer      = null;

    function getSelectedIdara() {
        var el = document.getElementById('idara');
        if (!el) return '<?= htmlspecialchars(addslashes($selectedIdara)) ?>';
        return (el.tagName === 'SELECT') ? el.value : (el.value || '');
    }
    function getSelectedMohalla() {
        var el = document.getElementById('mohalla');
        if (!el) return '<?= htmlspecialchars(addslashes($selectedMohalla)) ?>';
        return (el.tagName === 'SELECT') ? el.value : (el.value || '');
    }

    function loadMembers(idara, mohalla) {
        if (idara === '' || mohalla === '') {
            document.getElementById('member-assignment-wrap').style.display = 'none';
            document.getElementById('member-assignment-hint').style.display  = '';
            return;
        }
        document.getElementById('member-assignment-wrap').style.display = '';
        document.getElementById('member-assignment-hint').style.display  = 'none';
        document.getElementById('member-loading').style.display = '';

        var url = 'get_scope_members.php?idara=' + encodeURIComponent(idara) + '&mohalla=' + encodeURIComponent(mohalla);
        fetch(url)
            .then(function (r) { return r.json(); })
            .then(function (data) { renderMembers(data.members || []); })
            .catch(function () {
                document.getElementById('member-list').innerHTML =
                    '<div style="color:#c00;padding:0.5rem 0;">Could not load members.</div>';
            });
    }

    function renderMembers(members) {
        var list = document.getElementById('member-list');
        document.getElementById('member-loading').style.display = 'none';

        if (members.length === 0) {
            list.innerHTML = '<div style="color:#888;padding:0.5rem 0;">No members found for this scope.</div>';
            updateCount(); return;
        }

        var html = '';
        members.forEach(function (m) {
            html += '<label style="display:flex;align-items:center;gap:0.5rem;padding:0.3rem 0;cursor:pointer;">' +
                '<input type="checkbox" name="checked_members[]" value="' + esc(m.its_id) + '" checked onchange="updateCount()">' +
                '<span style="font-size:0.88rem;">' + esc(m.member_name) +
                ' <span style="color:#888;font-size:0.8rem;">(' + esc(m.its_id) + ')</span></span></label>';
        });
        list.innerHTML = html;
        updateCount();
    }

    window.updateCount = function () {
        var all     = document.querySelectorAll('#member-list input[type=checkbox]');
        var checked = document.querySelectorAll('#member-list input[type=checkbox]:checked');
        var label   = document.getElementById('member-count-label');
        if (label) label.textContent = checked.length + ' / ' + all.length + ' selected';
    };
    window.setAllMembers = function (state) {
        document.querySelectorAll('#member-list input[type=checkbox]').forEach(function (cb) { cb.checked = state; });
        updateCount();
    };

    function esc(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function onScopeChange() {
        var idara   = getSelectedIdara();
        var mohalla = getSelectedMohalla();
        if (idara === currentIdara && mohalla === currentMohalla) return;
        currentIdara = idara; currentMohalla = mohalla;
        clearTimeout(loadTimer);
        loadTimer = setTimeout(function () { loadMembers(idara, mohalla); }, 200);
    }

    ['idara', 'mohalla'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el && el.tagName === 'SELECT') el.addEventListener('change', onScopeChange);
    });

    // Auto-load on page ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', onScopeChange);
    } else {
        onScopeChange();
    }
})();
</script>

</div>

</body>
</html>

<?php
// Close database connection
mysqli_close($conn);
?>
