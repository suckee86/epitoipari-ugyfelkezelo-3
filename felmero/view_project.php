<?php
/* ===== 1. Jogosultság ellenőrzés ===== */
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'felmero') {
    header("Location: ../index.php");
    exit();
}

require_once '../includes/db.php';

/* ===== 2. Projekt ID, jogosultság ===== */
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: project_list.php");
    exit();
}
$projectID = (int)$_GET['id'];
$userID    = $_SESSION['user']['id'];

$stmt = $conn->prepare(
    "SELECT * FROM projects WHERE id = ? AND created_by = ?");
$stmt->bind_param("ii", $projectID, $userID);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) {
    echo "<p class='text-danger'>Nincs jogosultságod ehhez a projekthez.</p>";
    exit();
}
$project = $res->fetch_assoc();

/* ===== 3. Tulajdonosok + aláírások ===== */
$stmtOwn = $conn->prepare(
   "SELECT owner_name, id_card, signature
      FROM project_owners
     WHERE project_id = ?
     ORDER BY id ASC");
$stmtOwn->bind_param("i", $projectID);
$stmtOwn->execute();
$owners = $stmtOwn->get_result()->fetch_all(MYSQLI_ASSOC);

/* ===== 4. Feltöltött képek ===== */
$stmtImg = $conn->prepare(
   "SELECT file_name FROM project_images WHERE project_id = ?");
$stmtImg->bind_param("i", $projectID);
$stmtImg->execute();
$images = $stmtImg->get_result()->fetch_all(MYSQLI_ASSOC);

/* ===== 5. Checkboxok ===== */
$flags = $project['flags'] ? json_decode($project['flags'], true) : [];
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="utf-8">
    <title>Projekt megtekintés</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/epito3/assets/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="/epito3/assets/css/style.css">
</head>
<body>
<div class="container my-5">

    <!-- ===== Fejléc + navigáció ===== -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0">Projekt részletei</h2>
        <a href="../logout.php" class="btn btn-sm btn-outline-danger">Kilépés</a>
    </div>

    <!-- ===== Projekt adatok ===== -->
    <table class="table table-bordered w-auto">
        <tr><th>Projekt neve</th><td><?= htmlspecialchars($project['project_name']) ?></td></tr>
        <tr><th>Ügyfél neve</th><td><?= htmlspecialchars($project['client_name']) ?></td></tr>
        <tr><th>Cím</th><td><?= htmlspecialchars($project['address']) ?></td></tr>
        <tr><th>Helyrajzi szám</th><td><?= htmlspecialchars($project['cadastral_number']) ?></td></tr>
        <tr><th>Munka típusa</th><td><?= htmlspecialchars($project['template_type']) ?></td></tr>
        <tr><th>Munka tárgya</th><td><?= htmlspecialchars($project['munka_targya']) ?></td></tr>
        <tr><th>Teljesítési határidő</th><td><?= $project['hatarido'] ?: '—' ?></td></tr>
        <tr><th>Létrehozva</th><td><?= $project['created_at'] ?></td></tr>
    </table>

    <!-- ===== Checkbox-pipák ===== -->
    <?php if ($flags): ?>
        <h4 class="mt-5">Megjelölt munkarészek</h4>
        <ul>
            <?php foreach ($flags as $k => $v): if ($v): ?>
                <li><?= ucfirst(str_replace('_',' ', $k)) ?></li>
            <?php endif; endforeach; ?>
        </ul>
    <?php endif; ?>

    <!-- ===== Tulajdonosok + aláírások ===== -->
    <h3 class="mt-5">Tulajdonosok és aláírások</h3>
    <div class="row row-cols-md-2 g-4">
        <?php foreach ($owners as $idx => $o): ?>
            <div class="col">
                <div class="card h-100">
                    <div class="card-body">
                        <h5 class="card-title">
                            <?= $idx === 0 ? 'Fő szerződő' : 'Tulajdonos '.$idx ?>
                        </h5>
                        <p class="mb-1"><strong>Név:</strong> <?= htmlspecialchars($o['owner_name']) ?></p>
                        <p class="mb-3"><strong>SZIG:</strong> <?= htmlspecialchars($o['id_card']) ?></p>
                        <?php if ($o['signature']): ?>
                            <img src="data:image/png;base64,<?= base64_encode($o['signature']) ?>"
                                 alt="Aláírás" class="border rounded w-100"
                                 style="max-height:120px;object-fit:contain">
                        <?php else: ?>
                            <span class="text-muted">Nincs aláírás</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- ===== Feltöltött képek ===== -->
    <?php if ($images): ?>
        <h3 class="mt-5">Feltöltött képek</h3>
        <div class="row row-cols-md-3 g-3">
            <?php foreach ($images as $img): ?>
                <?php $src = "../uploads/projects/$projectID/{$img['file_name']}"; ?>
                <div class="col">
                    <a href="<?= $src ?>" target="_blank">
                        <img src="<?= $src ?>"
                             class="img-thumbnail"
                             style="object-fit:cover;height:140px">
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- ===== Navigáció ===== -->
    <a href="project_list.php" class="btn btn-secondary mt-4">&laquo; Vissza a listához</a>
    <a href="generate_pdf.php?id=<?= $projectID ?>" class="btn btn-dark mt-4">PDF letöltése</a>
</div>
</body>
</html>
