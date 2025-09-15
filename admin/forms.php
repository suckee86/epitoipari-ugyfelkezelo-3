<?php
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

require_once '../includes/db.php';

$stmt = $conn->prepare("SELECT f.*, u.name AS felmero_name, p.title AS project_title FROM form_data f
    JOIN users u ON f.user_id = u.id
    JOIN projects p ON f.project_id = p.id
    ORDER BY f.created_at DESC");
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
    <title>Űrlapok – Admin</title></head>
<body>
<div class="container">
    <h1>Beküldött űrlapok</h1>
    <table>
        <tr>
            <th>ID</th><th>Projekt</th><th>Felmérő</th><th>Dátum</th><th>Műveletek</th>
        </tr>
        <?php while ($row = $result->fetch_assoc()): ?>
            <tr>
                <td><?= $row['id'] ?></td>
                <td><?= htmlspecialchars($row['project_title']) ?></td>
                <td><?= htmlspecialchars($row['felmero_name']) ?></td>
                <td><?= $row['created_at'] ?></td>
                <td>
                    <a href="../felmero/generate_pdf.php?id=<?= $row['id'] ?>" target="_blank">PDF letöltés</a> |
                    <a href="view_form.php?id=<?= $row['id'] ?>">Részletek</a>
                </td>
            </tr>
        <?php endwhile; ?>
    </table>
    <p><a href="dashboard.php">Vissza a vezérlőpultra</a></p>
</div>
</body>
</html>
