<?php
// felmero/new_heater.php — Új hőtermelő adatai (1:1 a projekttel)
// Tárolás: project_new_heaters (ha nincs, létrehozza)

require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/scope.php';
require_once __DIR__.'/../includes/csrf.php';

require_role(['felmero']);

$projectId = (int)($_GET['project_id'] ?? 0);
if ($projectId <= 0) { http_response_code(400); exit('Hiányzó project_id.'); }
assert_felmero_scope_on_project($projectId);

// --- Tábla létrehozása, ha hiányzik ---
function ensure_new_heater_table(mysqli $conn): void {
    $sql = "
    CREATE TABLE IF NOT EXISTS `project_new_heaters` (
      `id` INT(11) NOT NULL AUTO_INCREMENT,
      `project_id` INT(11) NOT NULL,
      `heater_name` VARCHAR(150) NOT NULL,
      `served_heated_area` DECIMAL(9,2) NULL,
      `heater_type` VARCHAR(100) NULL,
      `scop` DECIMAL(5,2) NULL,
      `seer` DECIMAL(5,2) NULL,
      `heating_capacity_kw` DECIMAL(7,2) NULL,
      `cooling_capacity_kw` DECIMAL(7,2) NULL,
      `nominal_electric_power_kw` DECIMAL(7,2) NULL,
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` TIMESTAMP NULL DEFAULT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uniq_project` (`project_id`),
      CONSTRAINT `pnh_proj_fk` FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $conn->query($sql);
}
ensure_new_heater_table($conn);

// --- Helper: vessző helyett pont, üresből NULL ---
function nf(?string $raw): ?float {
    if ($raw === null) return null;
    $s = trim(str_replace("\xC2\xA0", '', $raw));
    if ($s === '') return null;
    $s = str_replace([' ', ','], ['', '.'], $s);
    if (!is_numeric($s)) return null;
    return (float)$s;
}

// Betöltés
function load_heater(mysqli $conn, int $projectId): ?array {
    $st = $conn->prepare("SELECT * FROM project_new_heaters WHERE project_id=? LIMIT 1");
    if (!$st) return null;
    $st->bind_param('i', $projectId);
    $st->execute();
    $res = $st->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $st->close();
    return $row ?: null;
}
$heater  = load_heater($conn, $projectId);
$errors  = [];
$saved   = false;

// Mentés
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_check($_POST['csrf'] ?? '');

        $heater_name  = trim($_POST['heater_name'] ?? '');
        $heater_type  = trim($_POST['heater_type'] ?? '');
        $served_heated_area         = nf($_POST['served_heated_area'] ?? null);
        $scop                       = nf($_POST['scop'] ?? null);
        $seer                       = nf($_POST['seer'] ?? null);
        $heating_capacity_kw        = nf($_POST['heating_capacity_kw'] ?? null);
        $cooling_capacity_kw        = nf($_POST['cooling_capacity_kw'] ?? null);
        $nominal_electric_power_kw  = nf($_POST['nominal_electric_power_kw'] ?? null);

        if ($heater_name === '') { $errors[] = 'Az új hőtermelő megnevezése kötelező.'; }
        foreach ([
            'Ellátott fűtött terület' => $served_heated_area,
            'SCOP' => $scop,
            'SEER' => $seer,
            'Fűtési teljesítmény (kW)' => $heating_capacity_kw,
            'Hűtési teljesítmény (kW)' => $cooling_capacity_kw,
            'Névleges villamos teljesítmény (kW)' => $nominal_electric_power_kw,
        ] as $label => $val) {
            if ($val !== null && $val < 0) { $errors[] = "$label nem lehet negatív."; }
        }

        if (!$errors) {
            if ($heater) {
                $sql = "UPDATE project_new_heaters
                        SET heater_name=?, served_heated_area=?, heater_type=?, scop=?, seer=?,
                            heating_capacity_kw=?, cooling_capacity_kw=?, nominal_electric_power_kw=?, updated_at=NOW()
                        WHERE project_id=?";
                $st = $conn->prepare($sql);
                if (!$st) { throw new RuntimeException('Adatbázis hiba (UPDATE előkészítés).'); }
                $types = 'sdsddddd' . 'i';
                $st->bind_param(
                    $types,
                    $heater_name, $served_heated_area, $heater_type, $scop, $seer,
                    $heating_capacity_kw, $cooling_capacity_kw, $nominal_electric_power_kw,
                    $projectId
                );
                if (!$st->execute()) { throw new RuntimeException('Mentési hiba (UPDATE): '.$st->error); }
                $st->close();
            } else {
                $sql = "INSERT INTO project_new_heaters
                        (project_id, heater_name, served_heated_area, heater_type, scop, seer, heating_capacity_kw, cooling_capacity_kw, nominal_electric_power_kw)
                        VALUES (?,?,?,?,?,?,?,?,?)";
                $st = $conn->prepare($sql);
                if (!$st) { throw new RuntimeException('Adatbázis hiba (INSERT előkészítés).'); }
                $types = 'isdsdddd d';
                $types = 'i' . 's' . 'd' . 's' . 'd' . 'd' . 'd' . 'd' . 'd';
                $st->bind_param(
                    $types,
                    $projectId, $heater_name, $served_heated_area, $heater_type, $scop, $seer,
                    $heating_capacity_kw, $cooling_capacity_kw, $nominal_electric_power_kw
                );
                if (!$st->execute()) { throw new RuntimeException('Mentési hiba (INSERT): '.$st->error); }
                $st->close();
            }

            // Siker
            $heater = load_heater($conn, $projectId);
            $saved = true;
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

// Form érték helper
$h = $heater ?? [];
$val = function($k){ return htmlspecialchars($GLOBALS['h'][$k] ?? ''); };
?>
<!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <title>Új hőtermelő – Projekt #<?=htmlspecialchars($projectId)?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="<?=ASSETS_URL?>/bootstrap/bootstrap.min.css">
  <style>
    .form-section { padding: 1rem; border: 1px solid #eee; border-radius: .5rem; margin-bottom: 1rem; }
    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
    @media (max-width: 768px) { .grid-2 { grid-template-columns: 1fr; } }
  </style>
</head>
<body class="container py-4">
  <h1 class="mb-3">Új hőtermelő <small class="text-muted">– Projekt #<?=htmlspecialchars($projectId)?></small></h1>

  <div class="mb-3 d-flex flex-wrap gap-2">
    <a class="btn btn-outline-secondary" href="<?=BASE_URL?>/felmero/building.php?project_id=<?=$projectId?>">← Épület adatai</a>
    <a class="btn btn-outline-secondary" href="<?=BASE_URL?>/felmero/dashboard.php">Projektlista</a>
    <!-- ÚJ: gyors tovább gomb a képekhez -->
    <a class="btn btn-primary" href="<?=BASE_URL?>/felmero/photos.php?project_id=<?=$projectId?>">Tovább: Képek →</a>
  </div>

  <?php if ($saved): ?>
    <div class="alert alert-success">Mentve.</div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?=htmlspecialchars($e)?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <form method="post" class="row g-3">
    <?=csrf_input()?>

    <div class="col-12 form-section">
      <h5>Alap adatok</h5>
      <div class="grid-2">
        <div>
          <label class="form-label">Új hőtermelő megnevezése *</label>
          <input name="heater_name" class="form-control" required value="<?=$val('heater_name')?>" placeholder="pl. Daikin Altherma 3 H HT 8 kW">
        </div>
        <div>
          <label class="form-label">Új hőtermelő típusa</label>
          <input name="heater_type" class="form-control" value="<?=$val('heater_type')?>" placeholder="pl. levegő-víz hőszivattyú">
        </div>
        <div>
          <label class="form-label">Ellátott fűtött terület (m²)</label>
          <input type="number" step="0.01" name="served_heated_area" class="form-control" value="<?=$val('served_heated_area')?>">
        </div>
        <div>
          <label class="form-label">SCOP</label>
          <input type="number" step="0.01" name="scop" class="form-control" value="<?=$val('scop')?>">
        </div>
        <div>
          <label class="form-label">SEER</label>
          <input type="number" step="0.01" name="seer" class="form-control" value="<?=$val('seer')?>">
        </div>
        <div>
          <label class="form-label">Fűtési teljesítmény (kW)</label>
          <input type="number" step="0.01" name="heating_capacity_kw" class="form-control" value="<?=$val('heating_capacity_kw')?>">
        </div>
        <div>
          <label class="form-label">Hűtési teljesítmény (kW)</label>
          <input type="number" step="0.01" name="cooling_capacity_kw" class="form-control" value="<?=$val('cooling_capacity_kw')?>">
        </div>
        <div>
          <label class="form-label">Névleges villamos teljesítmény (kW)</label>
          <input type="number" step="0.01" name="nominal_electric_power_kw" class="form-control" value="<?=$val('nominal_electric_power_kw')?>">
        </div>
      </div>
    </div>

    <div class="col-12 d-flex flex-wrap gap-2">
      <button class="btn btn-primary">Mentés</button>
      <a class="btn btn-success" href="<?=BASE_URL?>/felmero/photos.php?project_id=<?=$projectId?>">Tovább: Képek →</a>
      <a class="btn btn-outline-secondary" href="<?=BASE_URL?>/felmero/dashboard.php">Vissza a listához</a>
    </div>
  </form>
</body>
</html>
