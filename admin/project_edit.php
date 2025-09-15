<?php
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

require_once '../includes/db.php';

if (!isset($_GET['id'])) {
    header("Location: projects.php");
    exit();
}

$id = (int) $_GET['id'];

// Adatok lekérése
$stmt = $conn->prepare("SELECT * FROM projects WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if (!$project = $result->fetch_assoc()) {
    echo "Projekt nem található.";
    exit();
}

// Frissítés
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update'])) {
    $title = trim($_POST['title']);
    $location = trim($_POST['location']);
    $client = trim($_POST['client']);

    $stmt = $conn->prepare("UPDATE projects SET title = ?, location = ?, client = ? WHERE id = ?");
    $stmt->bind_param("sssi", $title, $location, $client, $id);
    $stmt->execute();
    header("Location: projects.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <link rel="stylesheet" href="/epito3/assets/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="/epito3/assets/css/style.css">
    <meta name="viewport" content="width=device-width, initial-scale=1">
<meta charset="UTF-8">
    <title>Projekt szerkesztése</title></head>
<body>
<div class="container">
    <h1>Projekt szerkesztése</h1>
    <form method="post">
        <label>Projekt neve:<br>
            <input type="text" name="title" value="<?= htmlspecialchars($project['title']) ?>" required>
        </label><br>
        <label>Helyszín:<br>
            <input type="text" name="location" value="<?= htmlspecialchars($project['location']) ?>" required>
        </label><br>
        <label>Megrendelő:<br>
            <input type="text" name="client" value="<?= htmlspecialchars($project['client']) ?>" required>
        </label><br>
        <button type="submit" name="update">Mentés</button>
    </form>
    <p><a href="projects.php">Vissza a projektlistához</a></p>
</div>
</body>
</html>
