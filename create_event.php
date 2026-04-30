<?php
session_start();
include 'db.php';

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $event_name = $_POST['event_name'];
    $event_date = $_POST['event_date'];
    $reporting_time = $_POST['reporting_time'];

    $sql = "INSERT INTO events (event_name, event_date, reporting_time)
            VALUES ('$event_name', '$event_date', '$reporting_time')";

    if ($conn->query($sql) === TRUE) {
        echo "Event created successfully!";
    } else {
        echo "Error: " . $conn->error;
    }
}
?>

<h2>Create Event</h2>
<form method="POST" action="">
    Event Name: <input type="text" name="event_name" required><br><br>
    Event Date: <input type="date" name="event_date" required><br><br>
    Reporting Time: <input type="time" name="reporting_time" required><br><br>
    <input type="submit" value="Create Event">
</form>
