<?php
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

require_once '../includes/db.php';

// Új projekt létrehozása
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create'])) {
    $title = trim($_POST['title']);
    $location = trim($_POST['location']);
    $client = trim($_POST['client']);

    $stmt = $conn->prepare("INSERT INTO projects (title, location, client) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $title, $location, $client);
    $stmt->execute();
    header("Location: projects.php");
    exit();
}

// Projektlista lekérése
$result = $conn->query("SELECT * FROM projects ORDER BY id DESC");
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <link rel="stylesheet" href="/epito3/assets/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="/epito3/assets/css/style.css">
    <meta name="viewport" content="width=device-width, initial-scale=1">
<meta charset="UTF-8">
    <title>Projektek kezelése</title></head>
<body>
<div class="container">
    <h1>Projektek kezelése</h1>
    <a href="dashboard.php">Vissza a vezérlőpulthoz</a>

    <h2>Új projekt létrehozása</h2>
    <form method="post">
        <label>Projekt neve: <input type="text" name="title" required></label><br>
        <label>Helyszín: <input type="text" name="location" required></label><br>
        <label>Megrendelő: <input type="text" name="client" required></label><br>
        <button type="submit" name="create">Projekt hozzáadása</button>
    </form>

    <h2>Projektlista</h2>
    <table border="1" cellpadding="5">
        <tr>
            <th>ID</th><th>Név</th><th>Helyszín</th><th>Megrendelő</th><th>Műveletek</th>
        </tr>
        <?php while ($row = $result->fetch_assoc()): ?>
            <tr>
                <td><?= $row['id'] ?></td>
                <td><?= htmlspecialchars($row['title']) ?></td>
                <td><?= htmlspecialchars($row['location']) ?></td>
                <td><?= htmlspecialchars($row['client']) ?></td>
                <td>
                    <a href="project_edit.php?id=<?= $row['id'] ?>">Szerkesztés</a> |
                    <a href="project_delete.php?id=<?= $row['id'] ?>" onclick="return confirm('Biztosan törölni szeretnéd?')">Törlés</a>
                </td>
            </tr>
        <?php endwhile; ?>
    </table>
</div>
</body>
</html>