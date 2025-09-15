<?php
// felmero/building.php — Épület adatok + Szigetelendők/Levonandók kezelése

require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/scope.php';
require_once __DIR__.'/../includes/csrf.php';
require_once __DIR__.'/../includes/project_status.php'; // table_exists(), column_exists(), recompute_project_status()

require_role(['felmero']);

$projectId = (int)($_GET['project_id'] ?? 0);
if ($projectId <= 0) { http_response_code(400); exit('Hiányzó project_id.'); }
if (!function_exists('assert_felmero_scope_on_project')) {
    // SHIM, ha a scope.php még nem tartalmazza
    function assert_felmero_scope_on_project(int $projectId): void {
        global $conn;
        $uid = (int)($_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
        $st = $conn->prepare("SELECT COUNT(*) c FROM projects WHERE id=? AND felmero_id=?");
        $st->bind_param('ii',$projectId,$uid); $st->execute();
        $ok = (int)($st->get_result()->fetch_assoc()['c'] ?? 0) > 0;
        $st->close();
        if (!$ok) { http_response_code(403); exit('Nincs jogosultság ehhez a projekthez.'); }
    }
}
assert_felmero_scope_on_project($projectId);

/* =================== SÉMA BIZTOSÍTÁSA =================== */
function ensure_project_buildings_table(mysqli $conn): void {
    // Alaptábla (ha nem létezik)
    if (!table_exists($conn,'project_buildings')) {
        $sql = "
        CREATE TABLE `project_buildings` (
          `id` INT NOT NULL AUTO_INCREMENT,
          `project_id` INT NOT NULL,
          `building_type` VARCHAR(50) NULL,
          `built_year` INT NULL,
          `has_roof_slab` TINYINT(1) NOT NULL DEFAULT 0,
          `roof_slab_year` INT NULL,
          `last_window_replace_year` INT NULL,
          `avg_ceiling_height_m` DECIMAL(5,2) NULL,
          `dhw_units` INT NULL, -- melegvíz ellátó berendezések száma
          `has_wall_insulation` TINYINT(1) NOT NULL DEFAULT 0,
          `wall_insulation_year` INT NULL,
          `heat_generators` TEXT NULL,
          `heat_emitters`  TEXT NULL,
          `wall_thickness_cm` DECIMAL(6,2) NULL,
          `wall_material` VARCHAR(100) NULL,
          `slab_material` VARCHAR(100) NULL,
          `slab_thickness_cm` DECIMAL(6,2) NULL,
          `pv_kw` DECIMAL(8,2) NULL,
          `solar_collector_kw` DECIMAL(8,2) NULL,
          `ventilation_type` VARCHAR(100) NULL,
          `has_heat_recovery` TINYINT(1) NOT NULL DEFAULT 0,
          `building_total_area_m2` DECIMAL(10,2) NULL,
          `heated_area_m2` DECIMAL(10,2) NULL,
          `insulation_total_m2` DECIMAL(10,2) NOT NULL DEFAULT 0,
          `deduction_total_m2` DECIMAL(10,2) NOT NULL DEFAULT 0,
          `insulation_net_m2` DECIMAL(10,2) NOT NULL DEFAULT 0,
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP NULL DEFAULT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uniq_project` (`project_id`),
          CONSTRAINT `pb_proj_fk` FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $conn->query($sql);
    } else {
        // Hiányzó oszlopok pótlása
        $defs = [
          'building_type' => "ADD COLUMN `building_type` VARCHAR(50) NULL",
          'built_year' => "ADD COLUMN `built_year` INT NULL",
          'has_roof_slab' => "ADD COLUMN `has_roof_slab` TINYINT(1) NOT NULL DEFAULT 0",
          'roof_slab_year' => "ADD COLUMN `roof_slab_year` INT NULL",
          'last_window_replace_year' => "ADD COLUMN `last_window_replace_year` INT NULL",
          'avg_ceiling_height_m' => "ADD COLUMN `avg_ceiling_height_m` DECIMAL(5,2) NULL",
          'dhw_units' => "ADD COLUMN `dhw_units` INT NULL",
          'has_wall_insulation' => "ADD COLUMN `has_wall_insulation` TINYINT(1) NOT NULL DEFAULT 0",
          'wall_insulation_year' => "ADD COLUMN `wall_insulation_year` INT NULL",
          'heat_generators' => "ADD COLUMN `heat_generators` TEXT NULL",
          'heat_emitters' => "ADD COLUMN `heat_emitters` TEXT NULL",
          'wall_thickness_cm' => "ADD COLUMN `wall_thickness_cm` DECIMAL(6,2) NULL",
          'wall_material' => "ADD COLUMN `wall_material` VARCHAR(100) NULL",
          'slab_material' => "ADD COLUMN `slab_material` VARCHAR(100) NULL",
          'slab_thickness_cm' => "ADD COLUMN `slab_thickness_cm` DECIMAL(6,2) NULL",
          'pv_kw' => "ADD COLUMN `pv_kw` DECIMAL(8,2) NULL",
          'solar_collector_kw' => "ADD COLUMN `solar_collector_kw` DECIMAL(8,2) NULL",
          'ventilation_type' => "ADD COLUMN `ventilation_type` VARCHAR(100) NULL",
          'has_heat_recovery' => "ADD COLUMN `has_heat_recovery` TINYINT(1) NOT NULL DEFAULT 0",
          'building_total_area_m2' => "ADD COLUMN `building_total_area_m2` DECIMAL(10,2) NULL",
          'heated_area_m2' => "ADD COLUMN `heated_area_m2` DECIMAL(10,2) NULL",
          'insulation_total_m2' => "ADD COLUMN `insulation_total_m2` DECIMAL(10,2) NOT NULL DEFAULT 0",
          'deduction_total_m2' => "ADD COLUMN `deduction_total_m2` DECIMAL(10,2) NOT NULL DEFAULT 0",
          'insulation_net_m2' => "ADD COLUMN `insulation_net_m2` DECIMAL(10,2) NOT NULL DEFAULT 0",
          'updated_at' => "ADD COLUMN `updated_at` TIMESTAMP NULL DEFAULT NULL"
        ];
        $adds = [];
        foreach ($defs as $col => $ddl) {
            if (!column_exists($conn,'project_buildings',$col)) $adds[] = $ddl;
        }
        if ($adds) { $conn->query("ALTER TABLE `project_buildings` ".implode(', ', $adds)); }
    }
}
ensure_project_buildings_table($conn);

// Részletező táblák: szigetelendők és levonandók
function ensure_building_area_tables(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS `project_building_insulations` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `project_id` INT NOT NULL,
        `structure_name` VARCHAR(150) NOT NULL,
        `thickness_mm` DECIMAL(7,2) NULL,
        `r_value_m2k_w` DECIMAL(8,3) NULL,
        `area_m2` DECIMAL(10,2) NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `pbi_proj_idx` (`project_id`),
        CONSTRAINT `pbi_proj_fk` FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    $conn->query("CREATE TABLE IF NOT EXISTS `project_building_deductions` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `project_id` INT NOT NULL,
        `part_name` VARCHAR(150) NOT NULL,
        `length_cm` DECIMAL(9,2) NULL,
        `width_cm` DECIMAL(9,2) NULL,
        `area_m2` DECIMAL(10,3) NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `pbd_proj_idx` (`project_id`),
        CONSTRAINT `pbd_proj_fk` FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
}
ensure_building_area_tables($conn);

/* =================== ADATBETÖLTÉS / SEGÉDEK =================== */
function nf_build(?string $raw): ?float {
    if ($raw === null) return null;
    $s = trim(str_replace("\xC2\xA0",'',$raw));
    if ($s==='') return null;
    $s = str_replace([' ', ','], ['', '.'], $s);
    if (!is_numeric($s)) return null;
    return (float)$s;
}
function ni(?string $raw): ?int {
    $raw = trim((string)$raw);
    if ($raw === '') return null;
    if (!is_numeric($raw)) return null;
    return (int)$raw;
}
function load_building(mysqli $conn, int $projectId): ?array {
    $st = $conn->prepare("SELECT * FROM project_buildings WHERE project_id=? LIMIT 1");
    $st->bind_param('i',$projectId); $st->execute();
    $res = $st->get_result(); $row = $res ? $res->fetch_assoc() : null;
    $st->close(); return $row ?: null;
}
function load_insulations(mysqli $conn, int $projectId): array {
    $st = $conn->prepare("SELECT id, structure_name, thickness_mm, r_value_m2k_w, area_m2
                          FROM project_building_insulations WHERE project_id=? ORDER BY id ASC");
    $st->bind_param('i',$projectId); $st->execute();
    $res = $st->get_result(); $rows=[]; while($r=$res->fetch_assoc()) $rows[]=$r;
    $st->close(); return $rows;
}
function load_deductions(mysqli $conn, int $projectId): array {
    $st = $conn->prepare("SELECT id, part_name, length_cm, width_cm, area_m2
                          FROM project_building_deductions WHERE project_id=? ORDER BY id ASC");
    $st->bind_param('i',$projectId); $st->execute();
    $res = $st->get_result(); $rows=[]; while($r=$res->fetch_assoc()) $rows[]=$r;
    $st->close(); return $rows;
}

$building = load_building($conn, $projectId);
$insRows  = load_insulations($conn, $projectId);
$dedRows  = load_deductions($conn, $projectId);

$errors = [];
$saved  = false;

/* =================== MENTÉS =================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_check($_POST['csrf'] ?? '');

        // Alap mezők
        $building_type = trim($_POST['building_type'] ?? '');
        $built_year    = ni($_POST['built_year'] ?? null);
        $has_roof_slab = isset($_POST['has_roof_slab']) ? 1 : 0;
        $roof_slab_year= ni($_POST['roof_slab_year'] ?? null);
        $last_win_year = ni($_POST['last_window_replace_year'] ?? null);
        $avg_h_m       = nf_build($_POST['avg_ceiling_height_m'] ?? null);
        $dhw_units     = ni($_POST['dhw_units'] ?? null);
        $has_wall_ins  = isset($_POST['has_wall_insulation']) ? 1 : 0;
        $wall_ins_year = ni($_POST['wall_insulation_year'] ?? null);

        $heat_gen_arr  = array_values(array_filter((array)($_POST['heat_generators'] ?? []), 'strlen'));
        $heat_emit_arr = array_values(array_filter((array)($_POST['heat_emitters']  ?? []), 'strlen'));
        $heat_generators = $heat_gen_arr ? json_encode($heat_gen_arr, JSON_UNESCAPED_UNICODE) : null;
        $heat_emitters   = $heat_emit_arr ? json_encode($heat_emit_arr, JSON_UNESCAPED_UNICODE) : null;

        $wall_thick_cm  = nf_build($_POST['wall_thickness_cm'] ?? null);
        $wall_material  = trim($_POST['wall_material'] ?? '');
        $slab_material  = trim($_POST['slab_material'] ?? '');
        $slab_thick_cm  = nf_build($_POST['slab_thickness_cm'] ?? null);

        $pv_kw          = nf_build($_POST['pv_kw'] ?? null);
        $sc_kw          = nf_build($_POST['solar_collector_kw'] ?? null);
        $vent_type      = trim($_POST['ventilation_type'] ?? '');
        $has_hr         = isset($_POST['has_heat_recovery']) ? 1 : 0;

        $total_area_m2  = nf_build($_POST['building_total_area_m2'] ?? null);
        $heated_area_m2 = nf_build($_POST['heated_area_m2'] ?? null);

        // Szigetelendők összeg (POST-ból, hogy 1 lépésben tudjuk menteni)
        $sumIns = 0.0;
        if (!empty($_POST['ins_area_m2']) && is_array($_POST['ins_area_m2'])) {
            foreach ($_POST['ins_area_m2'] as $v) {
                $a = nf_build($v) ?? 0.0;
                if ($a > 0) $sumIns += $a;
            }
        }
        // Levonandók összeg
        $sumDed = 0.0;
        if (!empty($_POST['ded_length_cm']) && is_array($_POST['ded_length_cm'])) {
            $len = $_POST['ded_length_cm'];
            $wid = $_POST['ded_width_cm'] ?? [];
            $n = max(count($len), count($wid));
            for ($i=0; $i<$n; $i++) {
                $L = nf_build($len[$i] ?? null) ?? 0.0;
                $W = nf_build($wid[$i] ?? null) ?? 0.0;
                $area = ($L > 0 && $W > 0) ? ($L * $W) / 10000.0 : 0.0;
                if ($area > 0) $sumDed += $area;
            }
        }
        $net = max(0.0, $sumIns - $sumDed);

        $conn->begin_transaction();

        if ($building) {
            // UPDATE
            $sql = "UPDATE project_buildings SET
                building_type=?,
                built_year=?,
                has_roof_slab=?,
                roof_slab_year=?,
                last_window_replace_year=?,
                avg_ceiling_height_m=?,
                dhw_units=?,
                has_wall_insulation=?,
                wall_insulation_year=?,
                heat_generators=?,
                heat_emitters=?,
                wall_thickness_cm=?,
                wall_material=?,
                slab_material=?,
                slab_thickness_cm=?,
                pv_kw=?,
                solar_collector_kw=?,
                ventilation_type=?,
                has_heat_recovery=?,
                building_total_area_m2=?,
                heated_area_m2=?,
                insulation_total_m2=?,
                deduction_total_m2=?,
                insulation_net_m2=?,
                updated_at=NOW()
              WHERE project_id=?";
            $st = $conn->prepare($sql);
            if (!$st) throw new RuntimeException('Adatbázis hiba (UPDATE előkészítés).');

            $types = "siiiidiiissdssdddsidddddi";
            $st->bind_param(
                $types,
                $building_type,
                $built_year,
                $has_roof_slab,
                $roof_slab_year,
                $last_win_year,
                $avg_h_m,
                $dhw_units,
                $has_wall_ins,
                $wall_ins_year,
                $heat_generators,
                $heat_emitters,
                $wall_thick_cm,
                $wall_material,
                $slab_material,
                $slab_thick_cm,
                $pv_kw,
                $sc_kw,
                $vent_type,
                $has_hr,
                $total_area_m2,
                $heated_area_m2,
                $sumIns,
                $sumDed,
                $net,
                $projectId
            );
            if (!$st->execute()) throw new RuntimeException('Mentési hiba (UPDATE): '.$st->error);
            $st->close();

        } else {
            // INSERT
            $sql = "INSERT INTO project_buildings
              (project_id, building_type, built_year, has_roof_slab, roof_slab_year, last_window_replace_year,
               avg_ceiling_height_m, dhw_units, has_wall_insulation, wall_insulation_year,
               heat_generators, heat_emitters,
               wall_thickness_cm, wall_material, slab_material, slab_thickness_cm,
               pv_kw, solar_collector_kw, ventilation_type, has_heat_recovery,
               building_total_area_m2, heated_area_m2,
               insulation_total_m2, deduction_total_m2, insulation_net_m2)
              VALUES (?,?,?,?,?,?,?,?,?, ?,?,?, ?,?,?, ?,?,?, ?,?, ?,?,?, ?,?)";
            $st = $conn->prepare($sql);
            if (!$st) throw new RuntimeException('Adatbázis hiba (INSERT előkészítés).');

            $types = "siiiidiiissdssdddsidddddi";
            $st->bind_param(
                $types,
                $projectId,
                $building_type,
                $built_year,
                $has_roof_slab,
                $roof_slab_year,
                $last_win_year,
                $avg_h_m,
                $dhw_units,
                $has_wall_ins,
                $wall_ins_year,
                $heat_generators,
                $heat_emitters,
                $wall_thick_cm,
                $wall_material,
                $slab_material,
                $slab_thick_cm,
                $pv_kw,
                $sc_kw,
                $vent_type,
                $has_hr,
                $total_area_m2,
                $heated_area_m2,
                $sumIns,
                $sumDed,
                $net
            );
            if (!$st->execute()) throw new RuntimeException('Mentési hiba (INSERT): '.$st->error);
            $st->close();

            $building = load_building($conn, $projectId); // friss állapot
        }

        // Részlettáblák újraírása
        // 1) tisztítás
        $st = $conn->prepare("DELETE FROM project_building_insulations WHERE project_id=?");
        $st->bind_param('i',$projectId); $st->execute(); $st->close();
        $st = $conn->prepare("DELETE FROM project_building_deductions WHERE project_id=?");
        $st->bind_param('i',$projectId); $st->execute(); $st->close();

        // 2) szigetelendők beírása
        $ins_name = $_POST['ins_name'] ?? [];
        $ins_th   = $_POST['ins_thickness_mm'] ?? [];
        $ins_r    = $_POST['ins_r_value'] ?? [];
        $ins_area = $_POST['ins_area_m2'] ?? [];
        if (is_array($ins_name)) {
            $insStmt = $conn->prepare("INSERT INTO project_building_insulations
                (project_id, structure_name, thickness_mm, r_value_m2k_w, area_m2) VALUES (?,?,?,?,?)");
            foreach ($ins_name as $i => $nm) {
                $nm = trim((string)$nm);
                if ($nm==='') continue;
                $th = nf_build($ins_th[$i] ?? null);
                $rv = nf_build($ins_r[$i] ?? null);
                $ar = nf_build($ins_area[$i] ?? null) ?? 0.0;
                if ($ar < 0) $ar = 0.0;
                $insStmt->bind_param('issdd', $projectId, $nm, $th, $rv, $ar);
                if (!$insStmt->execute()) throw new RuntimeException('Szigetelendők mentési hiba: '.$insStmt->error);
            }
            $insStmt->close();
        }

        // 3) levonandók beírása
        $ded_name = $_POST['ded_name'] ?? [];
        $ded_len  = $_POST['ded_length_cm'] ?? [];
        $ded_w    = $_POST['ded_width_cm'] ?? [];
        if (is_array($ded_name)) {
            $dedStmt = $conn->prepare("INSERT INTO project_building_deductions
                (project_id, part_name, length_cm, width_cm, area_m2) VALUES (?,?,?,?,?)");
            $N = max(count($ded_name), count($ded_len), count($ded_w));
            for ($i=0; $i<$N; $i++) {
                $pn = trim((string)($ded_name[$i] ?? ''));
                if ($pn==='') continue;
                $L = nf_build($ded_len[$i] ?? null) ?? 0.0;
                $W = nf_build($ded_w[$i] ?? null) ?? 0.0;
                if ($L < 0) $L = 0.0; if ($W < 0) $W = 0.0;
                $area = ($L * $W) / 10000.0;
                $dedStmt->bind_param('issdd', $projectId, $pn, $L, $W, $area);
                if (!$dedStmt->execute()) throw new RuntimeException('Levonandók mentési hiba: '.$dedStmt->error);
            }
            $dedStmt->close();
        }

        $conn->commit();
        $saved = true;

        // státusz újraszámolás
        recompute_project_status($conn, $projectId);

        // friss betöltés
        $building = load_building($conn, $projectId);
        $insRows  = load_insulations($conn, $projectId);
        $dedRows  = load_deductions($conn, $projectId);

    } catch (Throwable $e) {
        $conn->rollback();
        $errors[] = $e->getMessage();
    }
}

/* =================== FORM MEGJELENÍTÉS =================== */
$val = function($key, $default='') use ($building) {
    return htmlspecialchars((string)($building[$key] ?? $default));
};
// checkbox helper
function checked_arr(?array $arr, string $key): string {
    return (is_array($arr) && in_array($key, $arr, true)) ? 'checked' : '';
}
// JSON visszafejtés a checkboxokhoz
$genSel  = $building && !empty($building['heat_generators']) ? json_decode($building['heat_generators'], true) : [];
$emitSel = $building && !empty($building['heat_emitters'])   ? json_decode($building['heat_emitters'], true)   : [];

// opciók
$GEN = [
  'gazkazan' => 'Gázkazán',
  'szilard_kazan' => 'Szilárdtüzelésű kazán',
  'olajkazan' => 'Olajkazán',
  'elektromos_kazan' => 'Elektromos kazán',
  'hoszivattyu' => 'Hőszivattyú',
  'split_klima' => 'Split klíma',
  'elektromos_panel' => 'Elektromos panel/radiátor',
  'infra_hosugarzo' => 'Infra hősugárzó',
  'kondenzacios_kazan' => 'Kondenzációs kazán',
  'gazkonvektor' => 'Gázkonvektor',
  'egyeb' => 'Egyéb'
];
$EMIT = [
  'radiator' => 'Radiátor',
  'padlofutes' => 'Padlófűtés',
  'gazkonvektor' => 'Gázkonvektor',
  'split_klima' => 'Split klíma',
  'fal_mennyezetfutes' => 'Fal/mennyezetfűtés',
  'elektromos_panel' => 'Elektromos panel/radiátor',
  'infra_hosugarzo' => 'Infra hősugárzó',
  'egyeb' => 'Egyéb'
];
?>
<!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <title>Épület adatok – Projekt #<?=htmlspecialchars($projectId)?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="<?=ASSETS_URL?>/bootstrap/bootstrap.min.css">
  <style>
    .form-section{padding:1rem;border:1px solid #eee;border-radius:.5rem;margin-bottom:1rem}
    .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
    @media (max-width: 992px){.grid-2{grid-template-columns:1fr}}
    table.table-sm input{height:calc(1.5em + .5rem + 2px);padding:.25rem .5rem}
  </style>
</head>
<body class="container py-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="mb-0">Épület adatok <small class="text-muted">– Projekt #<?=htmlspecialchars($projectId)?></small></h1>
    <div class="btn-group">
      <a class="btn btn-outline-secondary" href="<?=BASE_URL?>/felmero/owners.php?project_id=<?=$projectId?>">← Tulajdonosok</a>
      <a class="btn btn-outline-secondary" href="<?=BASE_URL?>/felmero/new_heater.php?project_id=<?=$projectId?>">Tovább: Új hőtermelő →</a>
      <a class="btn btn-outline-secondary" href="<?=BASE_URL?>/felmero/dashboard.php">Projektlista</a>
    </div>
  </div>

  <?php if ($saved): ?>
    <div class="alert alert-success">Mentve.</div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?=htmlspecialchars($e)?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <form method="post">
    <?=csrf_input()?>

    <!-- Alap adatok -->
    <div class="form-section">
      <h5>Épület alapadatok</h5>
      <div class="grid-2">
        <div>
          <label class="form-label">Épület típusa</label>
          <select name="building_type" class="form-select">
            <?php
              $opts = ['Családi ház','Ikerház','Társasház'];
              $cur = $building['building_type'] ?? '';
              foreach ($opts as $o) {
                $sel = ($o===$cur)?'selected':'';
                echo "<option value=\"".htmlspecialchars($o)."\" $sel>".htmlspecialchars($o)."</option>";
              }
            ?>
          </select>
        </div>
        <div>
          <label class="form-label">Építés éve</label>
          <input type="number" name="built_year" class="form-control" min="1800" max="<?=date('Y')?>" value="<?=$val('built_year')?>">
        </div>
        <div>
          <label class="form-label">Zárófödémmel rendelkezik?</label><br>
          <input type="checkbox" class="form-check-input" id="has_roof_slab" name="has_roof_slab" <?= (isset($building['has_roof_slab']) && (int)$building['has_roof_slab']===1)?'checked':'' ?>>
          <label for="has_roof_slab" class="form-check-label">Igen</label>
        </div>
        <div>
          <label class="form-label">Zárófödém megvalósulás éve</label>
          <input type="number" name="roof_slab_year" class="form-control" min="1800" max="<?=date('Y')?>" value="<?=$val('roof_slab_year')?>">
        </div>
        <div>
          <label class="form-label">Legutóbbi nyílászáró csere éve</label>
          <input type="number" name="last_window_replace_year" class="form-control" min="1900" max="<?=date('Y')?>" value="<?=$val('last_window_replace_year')?>">
        </div>
        <div>
          <label class="form-label">Átlagos belmagasság (m)</label>
          <input type="number" step="0.01" name="avg_ceiling_height_m" class="form-control" value="<?=$val('avg_ceiling_height_m')?>">
        </div>
        <div>
          <label class="form-label">Melegvíz-ellátó berendezések száma</label>
          <input type="number" name="dhw_units" class="form-control" min="0" value="<?=$val('dhw_units')?>">
        </div>
        <div>
          <label class="form-label">Külső fal szigetelés?</label><br>
          <input type="checkbox" class="form-check-input" id="has_wall_ins" name="has_wall_insulation" <?= (isset($building['has_wall_insulation']) && (int)$building['has_wall_insulation']===1)?'checked':'' ?>>
          <label for="has_wall_ins" class="form-check-label">Igen</label>
        </div>
        <div>
          <label class="form-label">Külső fal szigetelés éve</label>
          <input type="number" name="wall_insulation_year" class="form-control" min="1900" max="<?=date('Y')?>" value="<?=$val('wall_insulation_year')?>">
        </div>
      </div>
    </div>

    <!-- Hőtermelő / Hőleadó -->
    <div class="form-section">
      <h5>Hőtermelő berendezés(ek)</h5>
      <div class="row">
        <?php foreach ($GEN as $k=>$label): ?>
          <div class="col-md-3">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="heat_generators[]" id="gen_<?=$k?>" value="<?=$k?>" <?=checked_arr($genSel,$k)?>>
              <label class="form-check-label" for="gen_<?=$k?>"><?=$label?></label>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <h5 class="mt-3">Hőleadó berendezés(ek)</h5>
      <div class="row">
        <?php foreach ($EMIT as $k=>$label): ?>
          <div class="col-md-3">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="heat_emitters[]" id="emit_<?=$k?>" value="<?=$k?>" <?=checked_arr($emitSel,$k)?>>
              <label class="form-check-label" for="emit_<?=$k?>"><?=$label?></label>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Szerkezet / anyagok -->
    <div class="form-section">
      <h5>Szerkezet / Anyagok</h5>
      <div class="grid-2">
        <div>
          <label class="form-label">Külső falazat vastagsága (cm)</label>
          <input type="number" step="0.01" name="wall_thickness_cm" class="form-control" value="<?=$val('wall_thickness_cm')?>">
        </div>
        <div>
          <label class="form-label">Külső falazat anyaga</label>
          <input name="wall_material" class="form-control" value="<?=$val('wall_material')?>">
        </div>
        <div>
          <label class="form-label">Födém anyaga</label>
          <input name="slab_material" class="form-control" value="<?=$val('slab_material')?>">
        </div>
        <div>
          <label class="form-label">Födém vastagsága (cm)</label>
          <input type="number" step="0.01" name="slab_thickness_cm" class="form-control" value="<?=$val('slab_thickness_cm')?>">
        </div>
      </div>
    </div>

    <!-- Gépek / szellőzés -->
    <div class="form-section">
      <h5>Gépek / Szellőzés</h5>
      <div class="grid-2">
        <div>
          <label class="form-label">Napelem teljesítmény (kW)</label>
          <input type="number" step="0.01" name="pv_kw" class="form-control" value="<?=$val('pv_kw')?>">
        </div>
        <div>
          <label class="form-label">Napkollektor teljesítmény (kW)</label>
          <input type="number" step="0.01" name="solar_collector_kw" class="form-control" value="<?=$val('solar_collector_kw')?>">
        </div>
        <div>
          <label class="form-label">Szellőztető rendszer típusa</label>
          <input name="ventilation_type" class="form-control" value="<?=$val('ventilation_type')?>">
        </div>
        <div>
          <label class="form-label">Hővisszanyerő?</label><br>
          <input type="checkbox" class="form-check-input" id="has_hr" name="has_heat_recovery" <?= (isset($building['has_heat_recovery']) && (int)$building['has_heat_recovery']===1)?'checked':'' ?>>
          <label for="has_hr" class="form-check-label">Igen</label>
        </div>
      </div>
    </div>

    <!-- Területek -->
    <div class="form-section">
      <h5>Területek</h5>
      <div class="grid-2">
        <div>
          <label class="form-label">Épület teljes alapterülete (m²)</label>
          <input type="number" step="0.01" name="building_total_area_m2" class="form-control" value="<?=$val('building_total_area_m2')?>">
        </div>
        <div>
          <label class="form-label">Fűtött terület mennyisége (m²)</label>
          <input type="number" step="0.01" name="heated_area_m2" class="form-control" value="<?=$val('heated_area_m2')?>">
        </div>
      </div>
    </div>

    <!-- ================ HELYRAJZ ÉS TERÜLET ADATOK – SZIGETELENDŐK/LEVONANDÓK ================ -->
    <div class="form-section">
      <h5 class="mb-3">Helyrajz és terület adatok</h5>

      <!-- SZIGETELENDŐK -->
      <h6 class="mt-1">Szigetelendők</h6>
      <div class="table-responsive">
        <table class="table table-sm align-middle" id="tblIns">
          <thead class="table-light">
            <tr>
              <th style="min-width:240px;">Épületszerkezet megnevezése</th>
              <th style="min-width:140px;">Utólagos szigetelés vastagság (mm)</th>
              <th style="min-width:180px;">Hőellenállás R (m²K/W)</th>
              <th style="min-width:140px;">Szigetelt felület (m²)</th>
              <th class="text-end" style="width:1%"></th>
            </tr>
          </thead>
          <tbody>
          <?php
            $hasIns = false;
            foreach ($insRows as $r): $hasIns=true; ?>
              <tr>
                <td><input name="ins_name[]" class="form-control" value="<?=htmlspecialchars($r['structure_name'])?>"></td>
                <td><input name="ins_thickness_mm[]" type="number" step="1" class="form-control" value="<?=htmlspecialchars($r['thickness_mm'])?>"></td>
                <td><input name="ins_r_value[]" type="number" step="0.001" class="form-control" value="<?=htmlspecialchars($r['r_value_m2k_w'])?>"></td>
                <td><input name="ins_area_m2[]" type="number" step="0.01" class="form-control ins-area" value="<?=htmlspecialchars($r['area_m2'])?>"></td>
                <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" onclick="rowDel(this)">×</button></td>
              </tr>
          <?php endforeach; if (!$hasIns): ?>
              <tr>
                <td><input name="ins_name[]" class="form-control" placeholder="pl. padlásfödém"></td>
                <td><input name="ins_thickness_mm[]" type="number" step="1" class="form-control" placeholder="pl. 200"></td>
                <td><input name="ins_r_value[]" type="number" step="0.001" class="form-control" placeholder="pl. 5.13"></td>
                <td><input name="ins_area_m2[]" type="number" step="0.01" class="form-control ins-area" placeholder="pl. 120"></td>
                <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" onclick="rowDel(this)">×</button></td>
              </tr>
          <?php endif; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="3" class="text-end fw-semibold">Összes szigetelendő (m²):</td>
              <td><input id="sumIns" class="form-control" readonly value="<?= $val('insulation_total_m2') ?>"></td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>
      <button type="button" class="btn btn-sm btn-outline-primary mb-4" onclick="rowAdd('tblIns')">+ Sor hozzáadása</button>

      <!-- LEVONANDÓK -->
      <h6>Levonandók</h6>
      <div class="table-responsive">
        <table class="table table-sm align-middle" id="tblDed">
          <thead class="table-light">
            <tr>
              <th style="min-width:240px;">Szerkezet megnevezése</th>
              <th style="min-width:120px;">Hossz (cm)</th>
              <th style="min-width:120px;">Szélesség (cm)</th>
              <th style="min-width:140px;">Terület (m²)</th>
              <th class="text-end" style="width:1%"></th>
            </tr>
          </thead>
          <tbody>
          <?php
            $hasDed = false;
            foreach ($dedRows as $r): $hasDed=true; ?>
              <tr>
                <td><input name="ded_name[]" class="form-control" value="<?=htmlspecialchars($r['part_name'])?>"></td>
                <td><input name="ded_length_cm[]" type="number" step="0.1" class="form-control ded-l" value="<?=htmlspecialchars($r['length_cm'])?>"></td>
                <td><input name="ded_width_cm[]"  type="number" step="0.1" class="form-control ded-w" value="<?=htmlspecialchars($r['width_cm'])?>"></td>
                <td><input class="form-control ded-area" value="<?=htmlspecialchars($r['area_m2'])?>" readonly></td>
                <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" onclick="rowDel(this)">×</button></td>
              </tr>
          <?php endforeach; if (!$hasDed): ?>
              <tr>
                <td><input name="ded_name[]" class="form-control" placeholder="pl. kémény"></td>
                <td><input name="ded_length_cm[]" type="number" step="0.1" class="form-control ded-l" placeholder="pl. 50"></td>
                <td><input name="ded_width_cm[]"  type="number" step="0.1" class="form-control ded-w" placeholder="pl. 50"></td>
                <td><input class="form-control ded-area" readonly></td>
                <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" onclick="rowDel(this)">×</button></td>
              </tr>
          <?php endif; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="3" class="text-end fw-semibold">Összes levonandó (m²):</td>
              <td><input id="sumDed" class="form-control" readonly value="<?= $val('deduction_total_m2') ?>"></td>
              <td></td>
            </tr>
            <tr>
              <td colspan="3" class="text-end fw-bold">Nettó szigetelendő felület (m²):</td>
              <td><input id="sumNet" class="form-control fw-bold" readonly value="<?= $val('insulation_net_m2') ?>"></td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>
      <button type="button" class="btn btn-sm btn-outline-primary" onclick="rowAdd('tblDed')">+ Sor hozzáadása</button>
    </div>

    <div class="d-flex flex-wrap gap-2">
      <button class="btn btn-primary">Mentés</button>
      <a class="btn btn-success" href="<?=BASE_URL?>/felmero/new_heater.php?project_id=<?=$projectId?>">Tovább: Új hőtermelő →</a>
      <a class="btn btn-outline-secondary" href="<?=BASE_URL?>/felmero/dashboard.php">Vissza a listához</a>
    </div>
  </form>

<script>
// Dinamikus sor-kezelés + valós idejű összegzés
function rowAdd(tblId){
  const tbody = document.querySelector('#'+tblId+' tbody');
  const isIns = (tblId==='tblIns');
  const tr = document.createElement('tr');
  tr.innerHTML = isIns
    ? `<td><input name="ins_name[]" class="form-control" placeholder="pl. padlásfödém"></td>
       <td><input name="ins_thickness_mm[]" type="number" step="1" class="form-control"></td>
       <td><input name="ins_r_value[]" type="number" step="0.001" class="form-control"></td>
       <td><input name="ins_area_m2[]" type="number" step="0.01" class="form-control ins-area"></td>
       <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" onclick="rowDel(this)">×</button></td>`
    : `<td><input name="ded_name[]" class="form-control" placeholder="pl. padlás feljáró"></td>
       <td><input name="ded_length_cm[]" type="number" step="0.1" class="form-control ded-l"></td>
       <td><input name="ded_width_cm[]"  type="number" step="0.1" class="form-control ded-w"></td>
       <td><input class="form-control ded-area" readonly></td>
       <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" onclick="rowDel(this)">×</button></td>`;
  tbody.appendChild(tr);
  wireUp();
}
function rowDel(btn){
  const tr = btn.closest('tr'); tr.parentNode.removeChild(tr); recalc();
}
function wireUp(){
  document.querySelectorAll('.ins-area').forEach(el => el.removeEventListener('input', recalc));
  document.querySelectorAll('.ded-l,.ded-w').forEach(el => el.removeEventListener('input', recalc));
  document.querySelectorAll('.ins-area').forEach(el => el.addEventListener('input', recalc));
  document.querySelectorAll('.ded-l,.ded-w').forEach(el => el.addEventListener('input', recalc));
  recalc();
}
function valNum(el){ let v=(el?.value||'').replace(',','.'); let n=parseFloat(v); return isFinite(n)?n:0; }
function recalc(){
  let sIns=0;
  document.querySelectorAll('#tblIns .ins-area').forEach(el => { sIns += Math.max(0,valNum(el)); });
  let sDed=0;
  document.querySelectorAll('#tblDed tbody tr').forEach(tr=>{
    const L=valNum(tr.querySelector('.ded-l'));
    const W=valNum(tr.querySelector('.ded-w'));
    const A=(L*W)/10000.0;
    const out=tr.querySelector('.ded-area'); if(out) out.value= A>0 ? A.toFixed(3) : '';
    if (A>0) sDed+=A;
  });
  const net=Math.max(0,sIns-sDed);
  const si=document.getElementById('sumIns'); if(si) si.value = sIns? sIns.toFixed(2):'';
  const sd=document.getElementById('sumDed'); if(sd) sd.value = sDed? sDed.toFixed(3):'';
  const sn=document.getElementById('sumNet'); if(sn) sn.value = net? net.toFixed(2):'';
}
document.addEventListener('DOMContentLoaded', wireUp);
</script>
</body>
</html>
