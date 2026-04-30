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
    <style>
        /* General reset and fonts */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f4f6f8;
        }
        .navbar {
            background-color: #2E8B57;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        .navbar h1 {
            font-size: 24px;
        }
        .navbar .logout {
            background-color: #ff4d4d;
            padding: 8px 15px;
            border: none;
            border-radius: 5px;
            color: white;
            text-decoration: none;
            font-size: 14px;
        }
        .navbar .logout:hover {
            background-color: #e60000;
        }
        .container {
            max-width: 1100px;
            margin: 40px auto;
            padding: 20px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .header {
            text-align: center;
            margin-bottom: 40px;
        }
        .header h2 {
            font-size: 24px;
            color: #333;
        }
        .event-table {
            width: 100%;
            margin-top: 20px;
            border-collapse: collapse;
        }
        .event-table th, .event-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        .event-table th {
            background-color: #2E8B57;
            color: white;
        }
        .event-table td {
            background-color: #f9f9f9;
        }
        .event-table td a {
            color: #2E8B57;
            text-decoration: none;
            font-weight: bold;
        }
        .event-table td a:hover {
            text-decoration: underline;
        }
        .add-event-btn {
            background-color: #2E8B57;
            color: white;
            padding: 10px 20px;
            font-size: 16px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin-bottom: 20px;
            text-align: center;
            display: block;
            width: 200px;
            margin: 20px auto;
        }
        .add-event-btn:hover {
            background-color: #246B46;
        }
    </style>
</head>
<body class="app-page page-table">

<div class="navbar">
    <h1><?= htmlspecialchars(bgi_app_name()) ?></h1>
    <a href="logout.php" class="logout">Logout</a>
</div>

<style>
  .container {
    max-width: 900px;
    margin: 40px auto;
    background: #fff;
    padding: 25px 30px;
    box-shadow: 0 0 15px rgba(0,0,0,0.1);
    border-radius: 8px;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
  }

  /* First row: title centered */
  .title-row {
    text-align: center;
    margin-bottom: 20px;
  }
  .title-row h2 {
    color: #2E8B57;
    font-weight: 700;
    font-size: 32px;
    margin: 0;
  }

  /* Second row: flex container with back button left, add event right */
  .action-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
  }

  .action-row form, .action-row .add-event-btn {
    margin: 0;
  }

  .action-row form button,
  .action-row .add-event-btn {
    background: #2E8B57;
    color: white;
    padding: 10px 22px;
    border: none;
    cursor: pointer;
    font-size: 16px;
    border-radius: 5px;
    font-weight: 600;
    transition: background-color 0.3s ease;
    text-decoration: none;
  }
  .action-row form button:hover,
  .action-row .add-event-btn:hover {
    background: #246B46;
  }

  /* Event Details heading */
  .event-details {
    font-size: 20px;
    font-weight: 600;
    color: #333;
    margin-bottom: 15px;
  }

  /* Table styling */
  .event-table {
    width: 100%;
    border-collapse: collapse;
  }

  .event-table th, .event-table td {
    border: 1px solid #ddd;
    padding: 12px 15px;
    text-align: left;
  }

  .event-table th {
    background-color: #2E8B57;
    color: white;
    font-weight: 600;
  }

  .event-table tbody tr:nth-child(even) {
    background-color: #f9f9f9;
  }

  .event-table tbody tr:hover {
    background-color: #e6f2e6;
  }

  .flash-message {
    padding: 12px 14px;
    border-radius: 6px;
    margin-bottom: 20px;
  }

  .flash-message.success {
    background: #e6f4ea;
    color: #0f5132;
    border: 1px solid #c7eed1;
  }

  .flash-message.error {
    background: #fdecea;
    color: #842029;
    border: 1px solid #f5c2c7;
  }

  .actions {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .actions form {
    display: inline;
    margin: 0;
  }

  .link-button {
    background: none;
    border: none;
    padding: 0;
    color: #2E8B57;
    cursor: pointer;
    font: inherit;
    font-weight: bold;
    text-decoration: underline;
  }

  .link-button:hover {
    color: #246B46;
  }

  /* Responsive adjustments */
  @media (max-width: 600px) {
    .action-row {
      flex-direction: column;
      gap: 12px;
      align-items: stretch;
    }

    .action-row form button,
    .action-row .add-event-btn {
      width: 100%;
      text-align: center;
      padding: 12px 0;
    }
  }
</style>
<link rel="stylesheet" href="app.css">

<div class="container">

  <!-- First row: Title -->
  <div class="title-row">
    <h2>Manage Events</h2>
    <p class="page-intro">
      <?= $isScopedAdmin
        ? 'Set up and review events only for your assigned scope: ' . htmlspecialchars($scopeLabel) . '.'
        : 'Set up event schedules, review reporting times, and keep event records tidy across all Idara and Mohalla scopes.' ?>
    </p>
  </div>

  <!-- Second row: Back and Add buttons -->
  <div class="action-row">
    <form action="dashboard.php" method="get">
      <button type="submit">&larr; Back to Dashboard</button>
    </form>
    <a href="add_event.php" class="add-event-btn">Add New Event</a>
  </div>

  <!-- Event details heading -->
  <div class="event-details">
    Event Details
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
