<?php
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'kivitelezo') {
    header("Location: ../index.php");
    exit();
}

require_once '../includes/db.php';

$stmt = $conn->prepare("SELECT * FROM projects WHERE kivitelezo_id = ? ORDER BY created_at DESC");
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
    <title>Kivitelező – Dashboard</title></head>
<body>
<div class="container">
    <h1>Kivitelező – Projektjeim</h1>
    <ul>
        <li><a href="../common/signature_pad.php">Aláírás rögzítése</a></li>
        <li><a href="../logout.php">Kilépés</a></li>
    </ul>

    <table>
        <tr>
            <th>Cím</th><th>Leírás</th><th>Státusz</th><th>Létrehozva</th>
        </tr>
        <?php while ($row = $result->fetch_assoc()): ?>
            <tr>
                <td><?= htmlspecialchars($row['title']) ?></td>
                <td><?= nl2br(htmlspecialchars($row['description'])) ?></td>
                <td><?= htmlspecialchars($row['status']) ?></td>
                <td><?= $row['created_at'] ?></td>
            </tr>
        <?php endwhile; ?>
    </table>
</div>
</body>
</html>
