<?php
include('session_check.php');
include('db.php');

if (!bgi_can_manage_events()) {
    header('Location: ' . bgi_home_path_for_current_user());
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$canDelete = bgi_can_delete();
$isScopedAdmin = !bgi_is_super_admin();
$scopeLabel = bgi_current_scope_label();

$flashMessage = $_SESSION['flash_message'] ?? '';
$flashType    = $_SESSION['flash_type'] ?? 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

$action = trim($_POST['action'] ?? $_GET['action'] ?? '');
$error  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = trim($_POST['csrf_token'] ?? '');
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        $error = 'Invalid request token. Please refresh and try again.';
    } else {
        $name       = trim($_POST['name'] ?? '');
        $latRaw     = trim($_POST['latitude'] ?? '');
        $lngRaw     = trim($_POST['longitude'] ?? '');
        $radiusRaw  = trim($_POST['radius_meters'] ?? '200');
        $idara   = $isScopedAdmin ? bgi_current_scope_idara() : bgi_normalize_scope_value($_POST['idara'] ?? '', BGI_DEFAULT_IDARA);
        $mohalla = $isScopedAdmin ? bgi_current_scope_mohalla() : bgi_normalize_scope_value($_POST['mohalla'] ?? '', BGI_DEFAULT_MOHALLA);

        if ($name === '' || $latRaw === '' || $lngRaw === '') {
            $error = 'Name, latitude, and longitude are required.';
        } elseif (!is_numeric($latRaw) || !is_numeric($lngRaw)) {
            $error = 'Latitude and longitude must be valid numbers.';
        } else {
            $lat    = (float) $latRaw;
            $lng    = (float) $lngRaw;
            $radius = ctype_digit($radiusRaw) ? (int) $radiusRaw : 200;

            if ($action === 'add') {
                $stmt = $conn->prepare(
                    "INSERT INTO event_locations (name, latitude, longitude, radius_meters, idara, mohalla)
                     VALUES (?, ?, ?, ?, ?, ?)"
                );
                if (!$stmt) {
                    $error = 'Unable to prepare insert.';
                } else {
                    $stmt->bind_param('sddiss', $name, $lat, $lng, $radius, $idara, $mohalla);
                    if ($stmt->execute()) {
                        $stmt->close();
                        bgi_set_flash("Location \"$name\" saved.", 'success');
                        header('Location: admin_locations.php');
                        exit;
                    }
                    $error = 'Error: ' . $stmt->error;
                    $stmt->close();
                }
            } elseif ($action === 'edit') {
                $locationId = (int) ($_POST['id'] ?? 0);
                if ($locationId <= 0) {
                    $error = 'Invalid location ID.';
                } else {
                    $stmt = $conn->prepare(
                        "UPDATE event_locations SET name = ?, latitude = ?, longitude = ?, radius_meters = ?, idara = ?, mohalla = ?
                         WHERE id = ?"
                    );
                    if (!$stmt) {
                        $error = 'Unable to prepare update.';
                    } else {
                        $stmt->bind_param('sddissi', $name, $lat, $lng, $radius, $idara, $mohalla, $locationId);
                        if ($stmt->execute()) {
                            $stmt->close();
                            bgi_set_flash("Location \"$name\" updated.", 'success');
                            header('Location: admin_locations.php');
                            exit;
                        }
                        $error = 'Error: ' . $stmt->error;
                        $stmt->close();
                    }
                }
            }
        }
    }
} elseif ($action === 'delete' && $canDelete) {
    $locationId = (int) ($_GET['id'] ?? 0);
    if ($locationId > 0) {
        $stmt = $conn->prepare("DELETE FROM event_locations WHERE id = ?");
        $stmt->bind_param('i', $locationId);
        $stmt->execute();
        $stmt->close();
        bgi_set_flash('Location deleted.', 'success');
        header('Location: admin_locations.php');
        exit;
    }
}

$editLocation = null;
if ($action === 'edit_form') {
    $editId = (int) ($_GET['id'] ?? 0);
    if ($editId > 0) {
        $editStmt = $conn->prepare("SELECT * FROM event_locations WHERE id = ? LIMIT 1");
        $editStmt->bind_param('i', $editId);
        $editStmt->execute();
        $editLocation = $editStmt->get_result()->fetch_assoc();
        $editStmt->close();
    }
}

$locations = bgi_get_event_locations($conn);
$scopeOptions = bgi_get_scope_options($conn);
$idaraOptions = [];
$mohallaOptions = [];
foreach ($scopeOptions as $opt) {
    $idaraOptions[$opt['idara']] = $opt['idara'];
    $mohallaOptions[$opt['mohalla']] = $opt['mohalla'];
}
ksort($idaraOptions);
ksort($mohallaOptions);

mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Saved Locations - <?= htmlspecialchars(bgi_app_name()) ?></title>
    <link rel="stylesheet" href="app.css">
</head>
<body class="app-page page-table">

<div class="topbar">
    <div><strong><?= htmlspecialchars(bgi_app_name()) ?></strong></div>
    <div>
        <a href="dashboard.php" class="back">← Dashboard</a>
        <a href="logout.php" class="logout" style="margin-left:8px;">Logout</a>
    </div>
</div>

<div class="container">
    <div class="title-row">
        <h2>Saved Locations</h2>
        <p class="page-intro">
            Store frequently used event venues here. When creating an event, pick a saved location to auto-fill the geofence coordinates.
            <?= $isScopedAdmin ? ' Showing locations for your scope: ' . htmlspecialchars($scopeLabel) . '.' : '' ?>
        </p>
    </div>

    <div class="action-row">
        <a href="dashboard.php" class="btn secondary">← Back to Dashboard</a>
    </div>

    <?php if ($flashMessage !== ''): ?>
        <div class="flash-message <?= $flashType === 'error' ? 'error' : 'success' ?>"><?= htmlspecialchars($flashMessage) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="message error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Add / Edit form -->
    <div class="form-section" style="background:#f9fbfa;border:1px solid #e5e7eb;border-radius:12px;padding:1.5rem;margin-bottom:2rem;">
        <h3 style="margin:0 0 1rem;"><?= $editLocation ? 'Edit Location' : 'Add New Location' ?></h3>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="<?= $editLocation ? 'edit' : 'add' ?>">
            <?php if ($editLocation): ?>
                <input type="hidden" name="id" value="<?= (int) $editLocation['id'] ?>">
            <?php endif; ?>

            <div style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:1rem;align-items:end;flex-wrap:wrap;">
                <div>
                    <label for="loc_name">Location Name</label>
                    <input type="text" id="loc_name" name="name"
                           value="<?= htmlspecialchars($editLocation['name'] ?? $_POST['name'] ?? '') ?>"
                           placeholder="e.g. Main Hall, Husainiyah" required>
                </div>
                <div>
                    <label for="loc_lat">Latitude</label>
                    <input type="number" id="loc_lat" name="latitude" step="0.0000001" min="-90" max="90"
                           value="<?= htmlspecialchars($editLocation['latitude'] ?? $_POST['latitude'] ?? '') ?>"
                           placeholder="29.3759" required>
                </div>
                <div>
                    <label for="loc_lng">Longitude</label>
                    <input type="number" id="loc_lng" name="longitude" step="0.0000001" min="-180" max="180"
                           value="<?= htmlspecialchars($editLocation['longitude'] ?? $_POST['longitude'] ?? '') ?>"
                           placeholder="47.9774" required>
                </div>
                <div>
                    <label for="loc_radius">Radius (m)</label>
                    <input type="number" id="loc_radius" name="radius_meters" min="50" max="10000"
                           value="<?= htmlspecialchars($editLocation['radius_meters'] ?? $_POST['radius_meters'] ?? '200') ?>"
                           placeholder="200">
                </div>
            </div>

            <?php if (!$isScopedAdmin): ?>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-top:1rem;">
                <div>
                    <label for="loc_idara">Idara</label>
                    <select id="loc_idara" name="idara">
                        <?php foreach ($idaraOptions as $opt): ?>
                            <option value="<?= htmlspecialchars($opt) ?>"
                                <?= ($editLocation['idara'] ?? $_POST['idara'] ?? '') === $opt ? 'selected' : '' ?>>
                                <?= htmlspecialchars($opt) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="loc_mohalla">Mohalla</label>
                    <select id="loc_mohalla" name="mohalla">
                        <?php foreach ($mohallaOptions as $opt): ?>
                            <option value="<?= htmlspecialchars($opt) ?>"
                                <?= ($editLocation['mohalla'] ?? $_POST['mohalla'] ?? '') === $opt ? 'selected' : '' ?>>
                                <?= htmlspecialchars($opt) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <?php endif; ?>

            <p style="font-size:0.8rem;color:#888;margin-top:0.75rem;">
                Find coordinates: open Google Maps, right-click the venue, and copy the latitude and longitude shown at the top of the menu.
            </p>

            <div style="display:flex;gap:1rem;margin-top:1rem;align-items:center;">
                <button type="submit" class="btn"><?= $editLocation ? 'Update Location' : 'Save Location' ?></button>
                <?php if ($editLocation): ?>
                    <a href="admin_locations.php" class="btn secondary">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Locations table -->
    <?php if ($locations): ?>
    <div class="table-wrap">
        <table class="event-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Idara / Mohalla</th>
                    <th>Coordinates</th>
                    <th>Radius</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($locations as $loc): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($loc['name']) ?></strong></td>
                    <td><?= htmlspecialchars($loc['idara'] . ' / ' . $loc['mohalla']) ?></td>
                    <td style="font-family:monospace;font-size:0.85rem;">
                        <?= htmlspecialchars(number_format((float)$loc['latitude'], 7)) ?>,
                        <?= htmlspecialchars(number_format((float)$loc['longitude'], 7)) ?>
                    </td>
                    <td><?= (int) $loc['radius_meters'] ?> m</td>
                    <td>
                        <a href="admin_locations.php?action=edit_form&id=<?= (int)$loc['id'] ?>" class="btn secondary" style="padding:4px 12px;font-size:0.8rem;">Edit</a>
                        <?php if ($canDelete): ?>
                            <a href="admin_locations.php?action=delete&id=<?= (int)$loc['id'] ?>"
                               class="btn danger" style="padding:4px 12px;font-size:0.8rem;margin-left:4px;"
                               onclick="return confirm('Delete this location?')">Delete</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
        <p style="color:#666;text-align:center;padding:2rem;">No saved locations yet. Add the first one above.</p>
    <?php endif; ?>
</div>
</body>
</html>
