<?php
// felmero/building.php — Épület adatok rögzítése/szerkesztése (projekt-specifikus 1 rekord)
// A fájl a project_buildings táblát automatikusan létrehozza, ha nincs.
// UI: bootstrap, magyar üzenetek, reszponzív, feltételes mező-megjelenítés.

require_once __DIR__.'/../includes/config.php';   // BASE_URL, ASSETS_URL, BASE_DIR, stb.
require_once __DIR__.'/../includes/db.php';       // $conn (mysqli)
require_once __DIR__.'/../includes/auth.php';     // beléptetés
require_once __DIR__.'/../includes/scope.php';    // require_role(), assert_felmero_scope_on_project()
require_once __DIR__.'/../includes/csrf.php';     // csrf_input(), csrf_check()

require_role(['felmero']);

$projectId = (int)($_GET['project_id'] ?? 0);
if ($projectId <= 0) { http_response_code(400); exit('Hiányzó project_id.'); }
assert_felmero_scope_on_project($projectId);

// --- Segéd: tábla létrehozása, ha nem létezik ---
function ensure_buildings_table(mysqli $conn): void {
    $sql = "
    CREATE TABLE IF NOT EXISTS `project_buildings` (
      `id` INT(11) NOT NULL AUTO_INCREMENT,
      `project_id` INT(11) NOT NULL,
      `building_type` VARCHAR(32) NOT NULL,                -- csaladi_haz | ikerhaz | tarsashaz
      `year_built` SMALLINT NULL,
      `has_roof_slab` TINYINT(1) NOT NULL DEFAULT 0,
      `roof_slab_year` SMALLINT NULL,
      `window_replacement_year` SMALLINT NULL,
      `avg_ceiling_height` DECIMAL(4,2) NULL,
      `hot_water_appliance_count` INT NULL,
      `external_wall_insulated` TINYINT(1) NOT NULL DEFAULT 0,
      `external_wall_insulation_year` SMALLINT NULL,
      `heat_generators` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin
         NULL CHECK (JSON_VALID(`heat_generators`)),
      `heat_emitters` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin
         NULL CHECK (JSON_VALID(`heat_emitters`)),
      `ext_wall_thickness` VARCHAR(50) NULL,
      `ext_wall_material` VARCHAR(100) NULL,
      `slab_material` VARCHAR(100) NULL,
      `slab_thickness` VARCHAR(50) NULL,
      `pv_power_kw` DECIMAL(7,2) NULL,
      `solar_thermal_power_kw` DECIMAL(7,2) NULL,
      `ventilation_type` VARCHAR(100) NULL,
      `heat_recovery` TINYINT(1) NOT NULL DEFAULT 0,
      `building_address` VARCHAR(255) NULL,
      `cadastral_number` VARCHAR(50) NULL,
      `total_floor_area` DECIMAL(9,2) NULL,
      `heated_area` DECIMAL(9,2) NULL,
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` TIMESTAMP NULL DEFAULT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uniq_project` (`project_id`),
      CONSTRAINT `pb_proj_fk` FOREIGN KEY (`project_id`)
          REFERENCES `projects`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    // projects tábla a dumpban biztosan létezik. :contentReference[oaicite:1]{index=1}
    $conn->query($sql);
}
ensure_buildings_table($conn);

// --- Konstansok / opciók ---
$BUILDING_TYPES = [
    'csaladi_haz' => 'Családi ház',
    'ikerhaz'     => 'Ikerház',
    'tarsashaz'   => 'Társasház',
];
$YESNO = ['1' => 'Igen', '0' => 'Nem'];

$HEAT_GENERATORS = [
    'gazkazan'            => 'Gázkazán',
    'szilard_kazan'       => 'Szilárdtüzelésű kazán',
    'olajkazan'           => 'Olajkazán',
    'elektromos_kazan'    => 'Elektromos kazán',
    'hoszivattyu'         => 'Hőszivattyú',
    'split_klima'         => 'Split klíma',
    'elektromos_panel'    => 'Elektromos panel/radiátor',
    'infra_hosugarzo'     => 'Infra hősugárzó',
    'kondenzacios_kazan'  => 'Kondenzációs kazán',
    'gazkonvektor'        => 'Gázkonvektor',
    'egyeb'               => 'Egyéb',
];

$HEAT_EMITTERS = [
    'radiator'            => 'Radiátor',
    'padlofutes'          => 'Padlófűtés',
    'gazkonvektor'        => 'Gázkonvektor',
    'split_klima'         => 'Split klíma',
    'fal_mennyezetfutes'  => 'Fal/mennyezetfűtés',
    'elektromos_panel'    => 'Elektromos panel/radiátor',
    'infra_hosugarzo'     => 'Infra hősugárzó',
    'egyeb'               => 'Egyéb',
];

// --- Betöltés (ha van) ---
function load_building(mysqli $conn, int $projectId): ?array {
    $st = $conn->prepare("SELECT * FROM project_buildings WHERE project_id=? LIMIT 1");
    if (!$st) return null;
    $st->bind_param('i', $projectId);
    $st->execute();
    $res = $st->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $st->close();
    return $row ?: null;
}

$building = load_building($conn, $projectId);
$errors = [];
$saved  = false;

// --- Mentés ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_check($_POST['csrf'] ?? '');

        // Olvasás + tisztítás
        $building_type = $_POST['building_type'] ?? '';
        if (!isset($BUILDING_TYPES[$building_type])) {
            $errors[] = 'Érvénytelen épülettípus.';
        }

        $year_built = trim($_POST['year_built'] ?? '');
        $year_built = ($year_built !== '' ? (int)$year_built : null);

        $has_roof_slab = $_POST['has_roof_slab'] ?? '0';
        $has_roof_slab = ($has_roof_slab === '1') ? 1 : 0;

        $roof_slab_year = trim($_POST['roof_slab_year'] ?? '');
        $roof_slab_year = $has_roof_slab ? ($roof_slab_year !== '' ? (int)$roof_slab_year : null) : null;

        $window_replacement_year = trim($_POST['window_replacement_year'] ?? '');
        $window_replacement_year = ($window_replacement_year !== '' ? (int)$window_replacement_year : null);

        $avg_ceiling_height = trim($_POST['avg_ceiling_height'] ?? '');
        $avg_ceiling_height = ($avg_ceiling_height !== '' ? (float)$avg_ceiling_height : null);

        $hot_water_appliance_count = trim($_POST['hot_water_appliance_count'] ?? '');
        $hot_water_appliance_count = ($hot_water_appliance_count !== '' ? (int)$hot_water_appliance_count : null);

        $external_wall_insulated = $_POST['external_wall_insulated'] ?? '0';
        $external_wall_insulated = ($external_wall_insulated === '1') ? 1 : 0;

        $external_wall_insulation_year = trim($_POST['external_wall_insulation_year'] ?? '');
        $external_wall_insulation_year = $external_wall_insulated ? ($external_wall_insulation_year !== '' ? (int)$external_wall_insulation_year : null) : null;

        $heat_generators = array_values(array_intersect(array_keys($HEAT_GENERATORS), (array)($_POST['heat_generators'] ?? [])));
        $heat_emitters   = array_values(array_intersect(array_keys($HEAT_EMITTERS),   (array)($_POST['heat_emitters']   ?? [])));
        $heat_generators_json = json_encode($heat_generators, JSON_UNESCAPED_UNICODE);
        $heat_emitters_json   = json_encode($heat_emitters,   JSON_UNESCAPED_UNICODE);

        $ext_wall_thickness = trim($_POST['ext_wall_thickness'] ?? '');
        $ext_wall_material  = trim($_POST['ext_wall_material'] ?? '');
        $slab_material      = trim($_POST['slab_material'] ?? '');
        $slab_thickness     = trim($_POST['slab_thickness'] ?? '');

        $pv_power_kw             = trim($_POST['pv_power_kw'] ?? '');
        $pv_power_kw             = ($pv_power_kw !== '' ? (float)$pv_power_kw : null);

        $solar_thermal_power_kw  = trim($_POST['solar_thermal_power_kw'] ?? '');
        $solar_thermal_power_kw  = ($solar_thermal_power_kw !== '' ? (float)$solar_thermal_power_kw : null);

        $ventilation_type = trim($_POST['ventilation_type'] ?? '');

        $heat_recovery = $_POST['heat_recovery'] ?? '0';
        $heat_recovery = ($heat_recovery === '1') ? 1 : 0;

        $building_address = trim($_POST['building_address'] ?? '');
        $cadastral_number = trim($_POST['cadastral_number'] ?? '');
        $total_floor_area = trim($_POST['total_floor_area'] ?? '');
        $total_floor_area = ($total_floor_area !== '' ? (float)$total_floor_area : null);
        $heated_area      = trim($_POST['heated_area'] ?? '');
        $heated_area      = ($heated_area !== '' ? (float)$heated_area : null);

        // Pár egyszerű validáció (nem okoskodunk túl)
        $nowYear = (int)date('Y');
        foreach ([
            'Építés éve' => $year_built,
            'Zárófödém megvalósulás éve' => $roof_slab_year,
            'Legutóbbi nyílászáró csere éve' => $window_replacement_year,
            'Külső fal szigetelés éve' => $external_wall_insulation_year,
        ] as $label => $y) {
            if ($y !== null && ($y < 1850 || $y > $nowYear)) {
                $errors[] = "$label irreális.";
            }
        }

        if (!$errors) {
            if ($building) {
                $sql = "UPDATE project_buildings
                        SET building_type=?, year_built=?, has_roof_slab=?, roof_slab_year=?, window_replacement_year=?,
                            avg_ceiling_height=?, hot_water_appliance_count=?, external_wall_insulated=?, external_wall_insulation_year=?,
                            heat_generators=?, heat_emitters=?, ext_wall_thickness=?, ext_wall_material=?, slab_material=?, slab_thickness=?,
                            pv_power_kw=?, solar_thermal_power_kw=?, ventilation_type=?, heat_recovery=?, building_address=?, cadastral_number=?,
                            total_floor_area=?, heated_area=?, updated_at=NOW()
                        WHERE project_id=?";
                $st = $conn->prepare($sql);
                if (!$st) { throw new RuntimeException('Adatbázis hiba (UPDATE előkészítés).'); }
                $st->bind_param(
                    'siiii diiissss sddsi ssddi',
                    // PHP 8.2: írjuk ki ténylegesen helyesen a típusokat:
                    // A 'bind_param' string: s i i i i d i i i s s s s s s d d s i s s d d i
                    // Könnyebb külön bontani:
                );
            }
            // A fenti bind_param string kézzel megírva hibalehetőséges. Rakjuk inkább darabokban:

            $params = [
                $building_type, $year_built, $has_roof_slab, $roof_slab_year, $window_replacement_year,
                $avg_ceiling_height, $hot_water_appliance_count, $external_wall_insulated, $external_wall_insulation_year,
                $heat_generators_json, $heat_emitters_json, $ext_wall_thickness, $ext_wall_material, $slab_material, $slab_thickness,
                $pv_power_kw, $solar_thermal_power_kw, $ventilation_type, $heat_recovery, $building_address, $cadastral_number,
                $total_floor_area, $heated_area
            ];

            if ($building) {
                $sql = "UPDATE project_buildings
                        SET building_type=?, year_built=?, has_roof_slab=?, roof_slab_year=?, window_replacement_year=?,
                            avg_ceiling_height=?, hot_water_appliance_count=?, external_wall_insulated=?, external_wall_insulation_year=?,
                            heat_generators=?, heat_emitters=?, ext_wall_thickness=?, ext_wall_material=?, slab_material=?, slab_thickness=?,
                            pv_power_kw=?, solar_thermal_power_kw=?, ventilation_type=?, heat_recovery=?, building_address=?, cadastral_number=?,
                            total_floor_area=?, heated_area=?, updated_at=NOW()
                        WHERE project_id=?";
                $st = $conn->prepare($sql);
                if (!$st) { throw new RuntimeException('Adatbázis hiba (UPDATE előkészítés).'); }

                // típus string összeállítása
                $types = 'siiiidiiissssssddsi ssdd'; // ezt most inkább generáljuk programból
                // Generáljuk pontosan:
                $types = 's'; // building_type
                $types .= 'i'; // year_built
                $types .= 'i'; // has_roof_slab
                $types .= 'i'; // roof_slab_year
                $types .= 'i'; // window_replacement_year
                $types .= 'd'; // avg_ceiling_height
                $types .= 'i'; // hot_water_appliance_count
                $types .= 'i'; // external_wall_insulated
                $types .= 'i'; // external_wall_insulation_year
                $types .= 's'; // heat_generators_json
                $types .= 's'; // heat_emitters_json
                $types .= 's'; // ext_wall_thickness
                $types .= 's'; // ext_wall_material
                $types .= 's'; // slab_material
                $types .= 's'; // slab_thickness
                $types .= 'd'; // pv_power_kw
                $types .= 'd'; // solar_thermal_power_kw
                $types .= 's'; // ventilation_type
                $types .= 'i'; // heat_recovery
                $types .= 's'; // building_address
                $types .= 's'; // cadastral_number
                $types .= 'd'; // total_floor_area
                $types .= 'd'; // heated_area
                $types .= 'i'; // WHERE project_id

                $params_with_where = array_merge($params, [$projectId]);
                $st->bind_param($types, ...$params_with_where);
                if (!$st->execute()) { throw new RuntimeException('Mentési hiba (UPDATE): '.$st->error); }
                $st->close();
            } else {
                $sql = "INSERT INTO project_buildings
                        (project_id, building_type, year_built, has_roof_slab, roof_slab_year, window_replacement_year,
                         avg_ceiling_height, hot_water_appliance_count, external_wall_insulated, external_wall_insulation_year,
                         heat_generators, heat_emitters, ext_wall_thickness, ext_wall_material, slab_material, slab_thickness,
                         pv_power_kw, solar_thermal_power_kw, ventilation_type, heat_recovery, building_address, cadastral_number,
                         total_floor_area, heated_area)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
                $st = $conn->prepare($sql);
                if (!$st) { throw new RuntimeException('Adatbázis hiba (INSERT előkészítés).'); }

                $types = 'isiiiidiiissssssddsi sdd'; // generáljuk biztosra:
                $types = 'i'; // project_id
                $types .= 's'; // building_type
                $types .= 'i'; // year_built
                $types .= 'i'; // has_roof_slab
                $types .= 'i'; // roof_slab_year
                $types .= 'i'; // window_replacement_year
                $types .= 'd'; // avg_ceiling_height
                $types .= 'i'; // hot_water_appliance_count
                $types .= 'i'; // external_wall_insulated
                $types .= 'i'; // external_wall_insulation_year
                $types .= 's'; // heat_generators_json
                $types .= 's'; // heat_emitters_json
                $types .= 's'; // ext_wall_thickness
                $types .= 's'; // ext_wall_material
                $types .= 's'; // slab_material
                $types .= 's'; // slab_thickness
                $types .= 'd'; // pv_power_kw
                $types .= 'd'; // solar_thermal_power_kw
                $types .= 's'; // ventilation_type
                $types .= 'i'; // heat_recovery
                $types .= 's'; // building_address
                $types .= 's'; // cadastral_number
                $types .= 'd'; // total_floor_area
                $types .= 'd'; // heated_area

                $st->bind_param($types, $projectId, ...$params);
                if (!$st->execute()) { throw new RuntimeException('Mentési hiba (INSERT): '.$st->error); }
                $st->close();
            }

            // Újratöltés
            $building = load_building($conn, $projectId);
            $saved = true;
			require_once __DIR__.'/../includes/project_status.php';
			recompute_project_status($conn, $projectId);
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

// --- Helper a checkboxokhoz ---
function checked($cond): string { return $cond ? 'checked' : ''; }
function sel($a,$b): string { return ((string)$a === (string)$b) ? 'selected' : ''; }

// Értékek a formhoz
$b = $building ?? [];
$val = function($k, $def='') use ($b) { return htmlspecialchars($b[$k] ?? $def); };

// JSON → tömb
$selGen = $building && !empty($building['heat_generators']) ? json_decode($building['heat_generators'], true) : [];
$selEmt = $building && !empty($building['heat_emitters'])   ? json_decode($building['heat_emitters'], true)   : [];
$selGen = is_array($selGen) ? $selGen : [];
$selEmt = is_array($selEmt) ? $selEmt : [];
?>
<!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <title>Épület adatai – Projekt #<?=htmlspecialchars($projectId)?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="<?=ASSETS_URL?>/bootstrap/bootstrap.min.css">
  <style>
    .form-section { padding: 1rem; border: 1px solid #eee; border-radius: .5rem; margin-bottom: 1rem; }
    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
    @media (max-width: 768px) { .grid-2 { grid-template-columns: 1fr; } }
  </style>
</head>
<body class="container py-4">
  <h1 class="mb-3">Épület adatai <small class="text-muted">– Projekt #<?=htmlspecialchars($projectId)?></small></h1>

  <div class="mb-3">
    <a class="btn btn-outline-secondary" href="<?=BASE_URL?>/felmero/owners.php?project_id=<?=$projectId?>">← Tulajdonosok</a>
    <a class="btn btn-primary" href="<?=BASE_URL?>/felmero/new_heater.php?project_id=<?=$projectId?>">Tovább: Új hőtermelő →</a>
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
          <label class="form-label">Épület típusa *</label>
          <select name="building_type" class="form-select" required>
            <?php foreach ($BUILDING_TYPES as $key=>$label): ?>
              <option value="<?=$key?>" <?=sel($key, $b['building_type'] ?? '')?>><?=$label?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label">Építés éve</label>
          <input type="number" name="year_built" class="form-control" min="1850" max="<?=date('Y')?>" value="<?=$val('year_built')?>">
        </div>
        <div>
          <label class="form-label">Zárófödémmel rendelkezik?</label>
          <select name="has_roof_slab" id="has_roof_slab" class="form-select">
            <option value="1" <?=sel('1',$b['has_roof_slab']??'0')?>>Igen</option>
            <option value="0" <?=sel('0',$b['has_roof_slab']??'0')?>>Nem</option>
          </select>
        </div>
        <div id="roof_slab_year_wrap">
          <label class="form-label">Zárófödém megvalósulás éve</label>
          <input type="number" name="roof_slab_year" id="roof_slab_year" class="form-control" min="1850" max="<?=date('Y')?>" value="<?=$val('roof_slab_year')?>">
        </div>

        <div>
          <label class="form-label">Legutóbbi nyílászáró csere éve</label>
          <input type="number" name="window_replacement_year" class="form-control" min="1900" max="<?=date('Y')?>" value="<?=$val('window_replacement_year')?>">
        </div>
        <div>
          <label class="form-label">Átlagos belmagasság (m)</label>
          <input type="number" step="0.01" name="avg_ceiling_height" class="form-control" value="<?=$val('avg_ceiling_height')?>">
        </div>
        <div>
          <label class="form-label">Melegvíz ellátó berendezések száma</label>
          <input type="number" name="hot_water_appliance_count" class="form-control" min="0" value="<?=$val('hot_water_appliance_count')?>">
        </div>
        <div>
          <label class="form-label">Külső fal szigeteléssel rendelkezik?</label>
          <select name="external_wall_insulated" id="external_wall_insulated" class="form-select">
            <option value="1" <?=sel('1',$b['external_wall_insulated']??'0')?>>Igen</option>
            <option value="0" <?=sel('0',$b['external_wall_insulated']??'0')?>>Nem</option>
          </select>
        </div>
        <div id="ext_ins_year_wrap">
          <label class="form-label">Külső fal szigetelés éve</label>
          <input type="number" name="external_wall_insulation_year" id="external_wall_insulation_year" class="form-control" min="1960" max="<?=date('Y')?>" value="<?=$val('external_wall_insulation_year')?>">
        </div>
      </div>
    </div>

    <div class="col-12 form-section">
      <h5>Hőtermelő berendezés(ek)</h5>
      <div class="row">
        <?php foreach ($HEAT_GENERATORS as $k=>$label): ?>
          <div class="col-6 col-md-4">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="heat_generators[]" id="hg_<?=$k?>" value="<?=$k?>" <?=checked(in_array($k,$selGen, true))?>>
              <label class="form-check-label" for="hg_<?=$k?>"><?=$label?></label>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="col-12 form-section">
      <h5>Hőleadó berendezés(ek)</h5>
      <div class="row">
        <?php foreach ($HEAT_EMITTERS as $k=>$label): ?>
          <div class="col-6 col-md-4">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="heat_emitters[]" id="he_<?=$k?>" value="<?=$k?>" <?=checked(in_array($k,$selEmt, true))?>>
              <label class="form-check-label" for="he_<?=$k?>"><?=$label?></label>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="col-12 form-section">
      <h5>Szerkezet</h5>
      <div class="grid-2">
        <div>
          <label class="form-label">Külső falazat vastagsága</label>
          <input name="ext_wall_thickness" class="form-control" value="<?=$val('ext_wall_thickness')?>" placeholder="pl. 38 cm">
        </div>
        <div>
          <label class="form-label">Külső falazat anyaga</label>
          <input name="ext_wall_material" class="form-control" value="<?=$val('ext_wall_material')?>" placeholder="pl. tégla, pórusbeton...">
        </div>
        <div>
          <label class="form-label">Födém anyaga</label>
          <input name="slab_material" class="form-control" value="<?=$val('slab_material')?>" placeholder="pl. vasbeton, fa gerendás...">
        </div>
        <div>
          <label class="form-label">Födém vastagsága</label>
          <input name="slab_thickness" class="form-control" value="<?=$val('slab_thickness')?>" placeholder="pl. 20 cm">
        </div>
      </div>
    </div>

    <div class="col-12 form-section">
      <h5>Gépi rendszerek</h5>
      <div class="grid-2">
        <div>
          <label class="form-label">Napelem teljesítmény (kW)</label>
          <input type="number" step="0.01" name="pv_power_kw" class="form-control" value="<?=$val('pv_power_kw')?>">
        </div>
        <div>
          <label class="form-label">Napkollektor teljesítmény (kW)</label>
          <input type="number" step="0.01" name="solar_thermal_power_kw" class="form-control" value="<?=$val('solar_thermal_power_kw')?>">
        </div>
        <div>
          <label class="form-label">Szellőztető rendszer típusa</label>
          <input name="ventilation_type" class="form-control" value="<?=$val('ventilation_type')?>" placeholder="pl. központi hővisszanyerős">
        </div>
        <div>
          <label class="form-label">Hővisszanyerő?</label>
          <select name="heat_recovery" class="form-select">
            <option value="1" <?=sel('1',$b['heat_recovery']??'0')?>>Igen</option>
            <option value="0" <?=sel('0',$b['heat_recovery']??'0')?>>Nem</option>
          </select>
        </div>
      </div>
    </div>

    <div class="col-12 form-section">
      <h5>Helyrajzi és terület adatok</h5>
      <div class="grid-2">
        <div>
          <label class="form-label">Beruházásra igénybevett épület címe</label>
          <input name="building_address" class="form-control" value="<?=$val('building_address')?>">
        </div>
        <div>
          <label class="form-label">Helyrajzi szám</label>
          <input name="cadastral_number" class="form-control" value="<?=$val('cadastral_number')?>">
        </div>
        <div>
          <label class="form-label">Épület teljes alapterülete (m²)</label>
          <input type="number" step="0.01" name="total_floor_area" class="form-control" value="<?=$val('total_floor_area')?>">
        </div>
        <div>
          <label class="form-label">Fűtött terület (m²)</label>
          <input type="number" step="0.01" name="heated_area" class="form-control" value="<?=$val('heated_area')?>">
        </div>
      </div>
    </div>

    <div class="col-12">
      <button class="btn btn-primary">Mentés</button>
      <a class="btn btn-outline-secondary" href="<?=BASE_URL?>/felmero/dashboard.php">Vissza a listához</a>
    </div>
  </form>

<script>
(function(){
  function toggleRoof(){
    var s = document.getElementById('has_roof_slab').value;
    document.getElementById('roof_slab_year_wrap').style.display = (s === '1') ? '' : 'none';
    if (s !== '1') { document.getElementById('roof_slab_year').value = ''; }
  }
  function toggleExtIns(){
    var s = document.getElementById('external_wall_insulated').value;
    document.getElementById('ext_ins_year_wrap').style.display = (s === '1') ? '' : 'none';
    if (s !== '1') { document.getElementById('external_wall_insulation_year').value = ''; }
  }
  document.getElementById('has_roof_slab').addEventListener('change', toggleRoof);
  document.getElementById('external_wall_insulated').addEventListener('change', toggleExtIns);
  toggleRoof(); toggleExtIns();
})();
</script>

</body>
</html>
