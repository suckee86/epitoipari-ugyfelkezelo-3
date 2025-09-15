<?php
// felmero/work_types.php — Munkatípusok kiválasztása projektenként (több választás engedélyezett)
// Mentés után: TOVÁBB a flow következő lépésére (Szerződő)

require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/scope.php';
require_once __DIR__.'/../includes/csrf.php';
require_once __DIR__.'/../includes/project_status.php'; // table_exists(), recompute_project_status()

require_role(['felmero']);

$projectId = (int)($_GET['project_id'] ?? 0);
if ($projectId <= 0) { http_response_code(400); exit('Hiányzó project_id.'); }
assert_felmero_scope_on_project($projectId);

// 1) Tábla biztosítása
function ensure_work_types_table(mysqli $conn): void {
    if (!table_exists($conn,'project_work_types')) {
        $sql = "CREATE TABLE `project_work_types` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `project_id` INT NOT NULL,
            `type_code` VARCHAR(50) NOT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_proj_type` (`project_id`,`type_code`),
            KEY `pwt_proj_idx` (`project_id`),
            CONSTRAINT `pwt_proj_fk` FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $conn->query($sql);
    }
}
ensure_work_types_table($conn);

// 2) Kódok és címkék
$TYPES = [
    'padlasfodem_szigeteles'   => 'Padlásfödém szigetelés',
    'futes_korszerusites'      => 'Fűtés korszerűsítés',
    'nyilaszaro_csere'         => 'Nyílászáró csere',
    'homlokzati_hoszigeteles'  => 'Homlokzati hőszigetelés',
];

// 3) Betöltés
function load_types(mysqli $conn, int $projectId): array {
    $st = $conn->prepare("SELECT type_code FROM project_work_types WHERE project_id=?");
    $st->bind_param('i',$projectId); $st->execute();
    $res = $st->get_result(); $set = [];
    while ($r = $res->fetch_assoc()) { $set[$r['type_code']] = true; }
    $st->close(); return $set;
}
$selected = load_types($conn, $projectId);

$errors = [];

// 4) Mentés → azonnali redirect a következő lépésre (Szerződő)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_check($_POST['csrf'] ?? '');
        $sel = $_POST['types'] ?? [];
        if (!is_array($sel)) $sel = [];

        // Normalizálás + valid
        $clean = [];
        foreach ($sel as $code) {
            $code = trim((string)$code);
            if ($code !== '' && isset($TYPES[$code])) $clean[$code] = true;
        }

        $conn->begin_transaction();
        $del = $conn->prepare("DELETE FROM project_work_types WHERE project_id=?");
        $del->bind_param('i',$projectId); $del->execute(); $del->close();

        if ($clean) {
            $ins = $conn->prepare("INSERT INTO project_work_types (project_id, type_code) VALUES (?,?)");
            foreach (array_keys($clean) as $code) {
                $ins->bind_param('is',$projectId,$code);
                if (!$ins->execute()) throw new RuntimeException('Mentési hiba (INSERT): '.$ins->error);
            }
            $ins->close();
        }
        $conn->commit();

        // státusz frissítés (ha a jövőben feltételeket kötünk hozzá)
        recompute_project_status($conn, $projectId);

        // FLOW: tovább a Szerződő oldalra
        header('Location: ' . BASE_URL . '/felmero/contractor.php?project_id='.$projectId.'&from=work_types');
        exit;

    } catch (Throwable $e) {
        $conn->rollback();
        $errors[] = $e->getMessage();
        // ha hiba, maradunk ezen az oldalon és kiírjuk
        $selected = load_types($conn, $projectId);
    }
}
?>
<!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <title>Munkatípusok – Projekt #<?=htmlspecialchars($projectId)?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="<?=ASSETS_URL?>/bootstrap/bootstrap.min.css">
  <style>
    .form-section{padding:1rem;border:1px solid #eee;border-radius:.5rem;margin-bottom:1rem}
  </style>
</head>
<body class="container py-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="mb-0">Munkatípusok <small class="text-muted">– Projekt #<?=htmlspecialchars($projectId)?></small></h1>
    <div class="btn-group">
      <a class="btn btn-outline-secondary" href="<?=BASE_URL?>/felmero/contractor.php?project_id=<?=$projectId?>">Szerződő →</a>
      <a class="btn btn-outline-secondary" href="<?=BASE_URL?>/felmero/dashboard.php">Projektlista</a>
    </div>
  </div>

  <?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?=htmlspecialchars($e)?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <form method="post" class="form-section">
    <?=csrf_input()?>
    <p class="text-muted">Válaszd ki, milyen munkatípus(ok) tartoznak ehhez a projekthez. Több is jelölhető.</p>
    <div class="row">
      <?php foreach ($TYPES as $code => $label): ?>
        <div class="col-md-6">
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" name="types[]" id="t_<?=$code?>" value="<?=$code?>" <?= isset($selected[$code]) ? 'checked' : '' ?>>
            <label for="t_<?=$code?>" class="form-check-label"><?=$label?></label>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="d-flex flex-wrap gap-2 mt-3">
      <button class="btn btn-primary">Mentés és tovább: Szerződő →</button>
      <a class="btn btn-outline-secondary" href="<?=BASE_URL?>/felmero/dashboard.php">Vissza a listához</a>
    </div>
  </form>

  <div class="alert alert-info mt-3">
    Tipp: ha be van jelölve a <strong>Fűtés korszerűsítés</strong>, az „Új hőtermelő” lépés is aktív lesz később.
  </div>
</body>
</html>
