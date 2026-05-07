<?php
include('session_check.php');

// Database connection
include('db.php');

if (!bgi_can_manage_events()) {
    header('Location: ' . bgi_home_path_for_current_user());
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$canDelete = bgi_can_delete();

$flashMessage = $_SESSION['flash_message'] ?? '';
$flashType = $_SESSION['flash_type'] ?? 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

$isScopedAdmin = !bgi_is_super_admin();
$scopeLabel = bgi_current_scope_label();

if (bgi_is_mohalla_admin()) {
    $eventStmt = $conn->prepare("SELECT * FROM events WHERE mohalla = ? ORDER BY event_date DESC, reporting_time DESC");
    $scopeMohalla = bgi_current_scope_mohalla();
    $eventStmt->bind_param("s", $scopeMohalla);
    $eventStmt->execute();
    $result = $eventStmt->get_result();
} elseif ($isScopedAdmin) {
    $eventStmt = $conn->prepare("SELECT * FROM events WHERE idara = ? AND mohalla = ? ORDER BY event_date DESC, reporting_time DESC");
    $scopeIdara = bgi_current_scope_idara();
    $scopeMohalla = bgi_current_scope_mohalla();
    $eventStmt->bind_param("ss", $scopeIdara, $scopeMohalla);
    $eventStmt->execute();
    $result = $eventStmt->get_result();
} else {
    $result = mysqli_query($conn, "SELECT * FROM events ORDER BY event_date DESC, reporting_time DESC");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Events - <?= htmlspecialchars(bgi_app_name()) ?></title>
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
    <h2>Manage Events</h2>
    <p class="page-intro">
      <?= $isScopedAdmin
        ? 'Set up and review events only for your assigned scope: ' . htmlspecialchars($scopeLabel) . '.'
        : 'Set up event schedules, review reporting times, and keep event records tidy across all Idara and Mohalla scopes.' ?>
    </p>
  </div>

  <div class="action-row">
    <a href="dashboard.php" class="btn secondary">← Back to Dashboard</a>
    <a href="add_event.php" class="btn">Add New Event</a>
  </div>

  <?php if ($flashMessage !== ''): ?>
    <div class="flash-message <?= $flashType === 'error' ? 'error' : 'success' ?>">
      <?= htmlspecialchars($flashMessage) ?>
    </div>
  <?php endif; ?>

  <?php if (!$canDelete): ?>
    <div class="flash-message">
      Delete actions are reserved for the main admin account. Your current event scope is <?= htmlspecialchars($scopeLabel) ?>.
    </div>
  <?php endif; ?>

  <!-- Your events table -->
  <div class="table-wrap">
    <table class="event-table">
      <thead>
        <tr>
          <th>Event Code</th>
          <th>Event Name</th>
          <th>Idara</th>
          <th>Mohalla</th>
          <th>Event Date</th>
          <th>Reporting Time</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
              <?php
              if (mysqli_num_rows($result) > 0) {
                  while ($row = mysqli_fetch_assoc($result)) {
                      echo '<tr>';
                      echo '<td>' . htmlspecialchars($row['event_code'] ?? '') . '</td>';
                      echo '<td>' . htmlspecialchars($row['event_name']) . '</td>';
                      echo '<td>' . htmlspecialchars($row['idara']) . '</td>';
                      echo '<td>' . htmlspecialchars($row['mohalla']) . '</td>';
                      echo '<td>' . htmlspecialchars($row['event_date']) . '</td>';
                      echo '<td>' . htmlspecialchars($row['reporting_time']) . '</td>';
                      echo '<td><div class="actions"><a href="edit_event.php?id=' . intval($row['id']) . '">Edit</a>';
                      if ($canDelete) {
                          echo '<form method="POST" action="delete_event.php" onsubmit="return confirm(\'Are you sure you want to delete this event?\')">
                                  <input type="hidden" name="id" value="' . intval($row['id']) . '">
                                  <input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') . '">
                                  <button type="submit" class="link-button">Delete</button>
                                </form>';
                      }
                      echo '</div></td>';
                      echo '</tr>';
                  }
              } else {
                  echo '<tr><td colspan="7">No events found.</td></tr>';
              }
              ?>
          </tbody>
      </table>
  </div>
</div>

</body>
</html>

<?php
if (isset($eventStmt) && $eventStmt instanceof mysqli_stmt) {
    $eventStmt->close();
}
// Close database connection
mysqli_close($conn);
?>
