<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';

if ($_SESSION['user']['role'] !== 'felmero') {
    header("Location: ../index.php");
    exit();
}

$user_id = $_SESSION['user']['id'];

$stmt = $conn->prepare("SELECT * FROM projects WHERE created_by = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="hu">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/epito3/assets/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="/epito3/assets/css/style.css">

<meta name="viewport" content="width=device-width, initial-scale=1">
<meta charset="UTF-8">
    <title>Projektjeim</title></head>
<body>
<a href="../logout.php" class="btn btn-sm btn-outline-danger">
    Kilépés
</a>

    <div class="container my-5">
    <h2 class="mb-4">Felmérési projektek</h2>
    <a href="create_project.php" class="btn btn-dark mb-3">+ Új projekt létrehozása</a>
    <div class="table-responsive"><table class="table table-striped table-hover">
        <thead>
            <tr>
                <th>Projekt neve</th>
                <th>Létrehozva</th>
                <th>Művelet</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($row = $result->fetch_assoc()): ?>
            <tr>
                <td><?= htmlspecialchars($row['project_name']) ?></td>
                <td><?= $row['created_at'] ?></td>
                <td>
                    <a href="view_project.php?id=<?= $row['id'] ?>">Megtekintés</a> |
                    <a href="generate_pdf.php?id=<?= $row['id'] ?>">PDF</a>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table></div>
    </div>
</body>
</html>

</head>