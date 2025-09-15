<?php
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

require_once '../includes/db.php';

$search = '';
$where = '';
$params = [];

if (!empty($_GET['q'])) {
    $search = trim($_GET['q']);
    $where = "WHERE p.project_name LIKE ? OR p.client_name LIKE ? OR p.project_code LIKE ?";
    $param = "%" . $search . "%";
    $params = [$param, $param, $param];
}

$sql = "SELECT p.*, u.name AS creator_name FROM projects p
        JOIN users u ON p.created_by = u.id
        $where
        ORDER BY p.created_at DESC";

$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param("sss", ...$params);
}

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
    <title>Projektek listája</title></head>
<body>
<div class="container">
    <h1>Projektek listája</h1>

    <?php if (isset($_SESSION['message'])): ?>
        <p style="color:green;"><?= $_SESSION['message'] ?></p>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>

    <form method="get" style="margin-bottom: 1em;">
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Keresés projekt, ügyfél vagy kód alapján">
        <button type="submit">Keresés</button>
        <?php if (!empty($search)): ?>
            <a href="projects.php">[X] Törlés</a>
        <?php endif; ?>
    </form>

    <p><a href="create_project.php">+ Új projekt létrehozása</a></p>

    <table>
        <tr>
            <th>Azonosító</th>
            <th>Név</th>
            <th>Ügyfél</th>
            <th>Cím</th>
            <th>Űrlap típus</th>
            <th>Munka típusa</th>
            <th>Készítette</th>
            <th>Létrehozva</th>
            <th>Műveletek</th>
        </tr>
        <?php while ($row = $result->fetch_assoc()): ?>
            <tr>
                <td><?= htmlspecialchars($row['project_code']) ?></td>
                <td><?= htmlspecialchars($row['project_name']) ?></td>
                <td><?= htmlspecialchars($row['client_name']) ?></td>
                <td><?= htmlspecialchars($row['address']) ?></td>
                <td><?= htmlspecialchars($row['template_type']) ?></td>
                <td><?= htmlspecialchars($row['project_type']) ?></td>
                <td><?= htmlspecialchars($row['creator_name']) ?></td>
                <td><?= $row['created_at'] ?></td>
                <td>
                    <a href="project_edit.php?id=<?= $row['id'] ?>">Szerkesztés</a> |
                    <a href="project_delete.php?id=<?= $row['id'] ?>" onclick="return confirm('Biztosan törlöd?')">Törlés</a>
                </td>
            </tr>
        <?php endwhile; ?>
    </table>

    <p><a href="dashboard.php">Vissza a vezérlőpulthoz</a></p>
</div>
</body>
</html>
