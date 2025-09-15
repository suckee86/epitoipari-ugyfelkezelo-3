<?php
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'ellenorzo') {
    header("Location: ../index.php");
    exit();
}

require_once '../includes/db.php';

$stmt = $conn->prepare("SELECT p.*, u.name AS felmero_nev, ku.name AS kivitelezo_nev FROM projects p
    JOIN users u ON p.felmero_id = u.id
    JOIN users ku ON p.kivitelezo_id = ku.id
    ORDER BY p.created_at DESC");
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
    <title>Ellenőrző – Projektek</title></head>
<body>
<div class="container">
    <h1>Ellenőrizendő projektek</h1>
    <ul>
        <li><a href="../common/signature_pad.php">Aláírás rögzítése</a></li>
        <li><a href="../logout.php">Kilépés</a></li>
    </ul>

    <table>
        <tr>
            <th>Cím</th><th>Felmérő</th><th>Kivitelező</th><th>Leírás</th><th>Státusz</th><th>Dátum</th><th>Jóváhagyás</th>
        </tr>
        <?php while ($row = $result->fetch_assoc()): ?>
            <tr>
                <td><?= htmlspecialchars($row['title']) ?></td>
                <td><?= htmlspecialchars($row['felmero_nev']) ?></td>
                <td><?= htmlspecialchars($row['kivitelezo_nev']) ?></td>
                <td><?= nl2br(htmlspecialchars($row['description'])) ?></td>
                <td><?= htmlspecialchars($row['status']) ?></td>
                <td><?= $row['created_at'] ?></td>
                <td>
                    <?php if ($row['status'] === 'kesz'): ?>
                        <form method="post" action="update_check.php">
                            <input type="hidden" name="project_id" value="<?= $row['id'] ?>">
                            <select name="review">
                                <option value="elfogadva">Elfogadva</option>
                                <option value="elutasitva">Elutasítva</option>
                            </select><br>
                            <textarea name="comment" rows="2" cols="20" placeholder="Megjegyzés..."><?= htmlspecialchars($row['ellenorzo_comment'] ?? '') ?></textarea><br>
                            <button type="submit">Mentés</button>
                        </form>
                    <?php else: ?>
                        <em>Nem végleges</em>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endwhile; ?>
    </table>
</div>
</body>
</html>
