<?php
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'auditor') {
    header("Location: ../index.php");
    exit();
}

require_once '../includes/db.php';

function countByStatus($conn, $status) {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM projects WHERE status = ?");
    $stmt->bind_param("s", $status);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    return $count;
}

$totalProjects = $conn->query("SELECT COUNT(*) FROM projects")->fetch_row()[0];
$approved = $conn->query("SELECT COUNT(*) FROM projects WHERE ellenorzo_status = 'elfogadva'")->fetch_row()[0];
$rejected = $conn->query("SELECT COUNT(*) FROM projects WHERE ellenorzo_status = 'elutasitva'")->fetch_row()[0];

$statuses = ['uj', 'kitoltve', 'kesz'];
$statusCounts = [];
foreach ($statuses as $s) {
    $statusCounts[$s] = countByStatus($conn, $s);
}

$latest = $conn->query("SELECT title, created_at FROM projects WHERE ellenorzo_status = 'elfogadva' ORDER BY created_at DESC LIMIT 5");
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <link rel="stylesheet" href="/epito3/assets/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="/epito3/assets/css/style.css">
    <meta name="viewport" content="width=device-width, initial-scale=1">
<meta charset="UTF-8">
    <title>Auditor – Statisztika</title></head>
<body>
<div class="container">
    <h1>Projektstatisztika</h1>
    <ul>
        <li>Összes projekt: <?= $totalProjects ?></li>
        <li>Jóváhagyott projektek: <?= $approved ?></li>
        <li>Elutasított projektek: <?= $rejected ?></li>
        <?php foreach ($statusCounts as $k => $v): ?>
            <li><?= ucfirst($k) ?> státusz: <?= $v ?></li>
        <?php endforeach; ?>
    </ul>

    <h2>Legutóbbi jóváhagyott projektek</h2>
    <ul>
        <?php while ($row = $latest->fetch_assoc()): ?>
            <li><?= htmlspecialchars($row['title']) ?> – <?= $row['created_at'] ?></li>
        <?php endwhile; ?>
    </ul>

    <p><a href="dashboard_auditor.php">Vissza a projektekhez</a> | <a href="../logout.php">Kilépés</a></p>
</div>
</body>
</html>
