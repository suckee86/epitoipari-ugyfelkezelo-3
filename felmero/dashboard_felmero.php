<?php
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'felmero') {
    header("Location: ../index.php");
    exit();
}

require_once '../includes/db.php';

// Csak a saját projektek lekérése
$stmt = $conn->prepare("SELECT * FROM projects WHERE felmero_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $_SESSION['user']['id']);
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
    <title>Felmérő – Dashboard</title></head>
<body>
<div class="container">
    <h1>Felmérő – Saját projektjeim</h1>
    <ul>
        <li><a href="create_project.php">Új projekt létrehozása</a></li>
        <li><a href="../common/signature_pad.php">Aláírás rögzítése</a></li>
        <li><a href="../logout.php">Kilépés</a></li>
    </ul>

    <div class="table-responsive"><table class="table table-striped table-hover">
        <tr>
            <th>Cím</th><th>Leírás</th><th>Státusz</th><th>Létrehozva</th><th>Műveletek</th>
        </tr>
        <?php while ($row = $result->fetch_assoc()): ?>
            <tr>
                <td><?= htmlspecialchars($row['title']) ?></td>
                <td><?= nl2br(htmlspecialchars($row['description'])) ?></td>
                <td><?= htmlspecialchars($row['status']) ?></td>
                <td><?= $row['created_at'] ?></td>
                <td>
                    <a href="view_project.php?id=<?= $row['id'] ?>">Megtekintés</a> |
                    <a href="edit_project.php?id=<?= $row['id'] ?>">Szerkesztés</a>
                </td>
            </tr>
        <?php endwhile; ?>
    </table></div>
</div>
</body>
</html>
