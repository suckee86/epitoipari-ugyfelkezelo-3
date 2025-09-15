<?php
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'felmero') {
    header("Location: ../index.php");
    exit();
}

require_once '../includes/db.php';

$user_id = $_SESSION['user']['id'];

$stmt = $conn->prepare("SELECT p.* FROM projects p JOIN project_assignments a ON p.id = a.project_id WHERE a.user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <link rel="stylesheet" href="/epito3/assets/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="/epito3/assets/css/style.css">
    <meta name="viewport" content="width=device-width, initial-scale=1">
<meta charset="UTF-8">
    <title>Projektjeim – Felmérő</title></head>
<body>
<div class="container">
    <h1>Hozzám rendelt projektek</h1>
    <table>
        <tr>
            <th>ID</th><th>Projekt neve</th><th>Helyszín</th><th>Megrendelő</th><th>Műveletek</th>
        </tr>
        <?php while ($row = $result->fetch_assoc()): ?>
            <tr>
                <td><?= $row['id'] ?></td>
                <td><?= htmlspecialchars($row['title']) ?></td>
                <td><?= htmlspecialchars($row['location']) ?></td>
                <td><?= htmlspecialchars($row['client']) ?></td>
                <td>
                    <a href="project_fill.php?id=<?= $row['id'] ?>">Űrlap kitöltése</a>
                </td>
            </tr>
        <?php endwhile; ?>
    </table>
</div>
</body>
</html>
