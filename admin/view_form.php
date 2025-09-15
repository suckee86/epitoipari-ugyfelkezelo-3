<?php
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

require_once '../includes/db.php';

if (!isset($_GET['id'])) {
    die("Hiányzó űrlap azonosító.");
}

$form_id = (int) $_GET['id'];

$stmt = $conn->prepare("SELECT f.*, u.name AS felmero_name, p.title AS project_title
                        FROM form_data f
                        JOIN users u ON f.user_id = u.id
                        JOIN projects p ON f.project_id = p.id
                        WHERE f.id = ?");
$stmt->bind_param("i", $form_id);
$stmt->execute();
$result = $stmt->get_result();

if (!$form = $result->fetch_assoc()) {
    die("Nem található a megadott űrlap.");
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <link rel="stylesheet" href="/epito3/assets/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="/epito3/assets/css/style.css">
    <meta name="viewport" content="width=device-width, initial-scale=1">
<meta charset="UTF-8">
    <title>Űrlap részletei</title></head>
<body>
<div class="container">
    <h1>Űrlap részletei</h1>
    <p><strong>Projekt:</strong> <?= htmlspecialchars($form['project_title']) ?></p>
    <p><strong>Felmérő:</strong> <?= htmlspecialchars($form['felmero_name']) ?></p>
    <p><strong>Dátum:</strong> <?= $form['created_at'] ?></p>
    <hr>
    <p><strong>Mező 1:</strong> <?= nl2br(htmlspecialchars($form['field1'])) ?></p>
    <p><strong>Mező 2:</strong> <?= nl2br(htmlspecialchars($form['field2'])) ?></p>
    <p><strong>Checkbox:</strong> <?= $form['checkbox1'] ? '✓ Bejelölve' : '✗ Nincs bejelölve' ?></p>
    <p><strong>Aláírás:</strong><br>
        <?php if ($form['signature_path'] && file_exists($form['signature_path'])): ?>
            <img src="<?= $form['signature_path'] ?>" alt="Aláírás" width="200">
        <?php else: ?>
            <em>Nincs aláírás elérhető.</em>
        <?php endif; ?>
    </p>
    <p><a href="forms.php">Vissza a listához</a></p>
</div>
</body>
</html>