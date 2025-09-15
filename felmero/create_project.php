<?php
// felmero/create_project.php — Új projekt létrehozása + a–g mezők mentése
// Mentés után: work_types.php

require_once __DIR__.'/../includes/config.php'; // BASE_URL, ASSETS_URL
require_once __DIR__.'/../includes/db.php';      // $conn (mysqli)
require_once __DIR__.'/../includes/csrf.php';    // session + csrf
require_once __DIR__.'/../includes/auth.php';    // require_role stb.

// --- SHIM-ek, ha a környezetben nincs meg minden ---
if (!function_exists('current_user_id')) {
    function current_user_id(): int {
        if (!empty($_SESSION['user_id']))       return (int)$_SESSION['user_id'];
        if (!empty($_SESSION['id']))            return (int)$_SESSION['id'];
        if (!empty($_SESSION['user']['id']))    return (int)$_SESSION['user']['id'];
        return 0;
    }
}
if (!function_exists('current_user_role')) {
    function current_user_role(): ?string {
        if (!empty($_SESSION['role']))          return (string)$_SESSION['role'];
        if (!empty($_SESSION['user']['role']))  return (string)$_SESSION['user']['role'];
        return null;
    }
}
if (!function_exists('require_role')) {
    function require_role(array $allowed): void {
        $uid  = current_user_id();
        $role = current_user_role();
        if ($uid <= 0 || !$role || !in_array($role, $allowed, true)) {
            http_response_code(403);
            exit('Hozzáférés megtagadva (felmérő kell).');
        }
    }
}
require_role(['felmero']);

// --- Segéd: oszlop-létezés ellenőrzése (INFORMATION_SCHEMA) ---
if (!function_exists('column_exists')) {
    function column_exists(mysqli $conn, string $table, string $col): bool {
        $sql = "SELECT COUNT(*) c
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?";
        $st = $conn->prepare($sql);
        if (!$st) return false;
        $st->bind_param('ss', $table, $col);
        $st->execute();
        $res = $st->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $st->close();
        return !empty($row) && (int)$row['c'] > 0;
    }
}

// --- A szükséges a–g oszlopok biztosítása a projects táblában ---
function ensure_project_req_columns(mysqli $conn): void {
    $adds = [];
    if (!column_exists($conn, 'projects', 'req_a_csaladi_haz'))         $adds[] = "ADD COLUMN `req_a_csaladi_haz` TINYINT(1) NOT NULL DEFAULT 0";
    if (!column_exists($conn, 'projects', 'req_b_kiv_reg_szam'))        $adds[] = "ADD COLUMN `req_b_kiv_reg_szam` VARCHAR(100) NOT NULL DEFAULT ''";
    if (!column_exists($conn, 'projects', 'req_d_szigeteltsg_kikotes'))  $adds[] = "ADD COLUMN `req_d_szigeteltsg_kikotes` TINYINT(1) NOT NULL DEFAULT 0";
    if (!column_exists($conn, 'projects', 'req_e_kovetelmeny_ok'))      $adds[] = "ADD COLUMN `req_e_kovetelmeny_ok` TINYINT(1) NOT NULL DEFAULT 0";
    if (!column_exists($conn, 'projects', 'req_f_ketreteg'))            $adds[] = "ADD COLUMN `req_f_ketreteg` TINYINT(1) NOT NULL DEFAULT 0";
    if (!column_exists($conn, 'projects', 'req_g_parareteg'))           $adds[] = "ADD COLUMN `req_g_parareteg` TINYINT(1) NOT NULL DEFAULT 0";
    if ($adds) {
        $sql = "ALTER TABLE `projects` ".implode(', ', $adds);
        $conn->query($sql); // szándékosan nem prepared (DDL)
    }
}
ensure_project_req_columns($conn);

// --- Validálás/mentés ---
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_check($_POST['csrf'] ?? '');

        $project_name     = trim($_POST['project_name'] ?? '');
        $client_name      = trim($_POST['client_name'] ?? '');
        $address          = trim($_POST['address'] ?? '');
        $cadastral_number = trim($_POST['cadastral_number'] ?? '');

        // a–g mezők
        $req_a = isset($_POST['req_a_csaladi_haz']) ? 1 : 0; // kötelező: legyen pipa
        $req_b = trim($_POST['req_b_kiv_reg_szam'] ?? '');   // kötelező: nem üres
        $req_d = isset($_POST['req_d_szigeteltsg_kikotes']) ? 1 : 0;
        $req_e = isset($_POST['req_e_kovetelmeny_ok']) ? 1 : 0;
        $req_f = isset($_POST['req_f_ketreteg']) ? 1 : 0;
        $req_g = isset($_POST['req_g_parareteg']) ? 1 : 0;

        // Kötelezők
        if ($project_name === '')     { $errors[] = 'A projekt megnevezése kötelező.'; }
        if ($address === '')          { $errors[] = 'Az ingatlan címe kötelező.'; }
        if ($cadastral_number === '') { $errors[] = 'A helyrajzi szám kötelező.'; }

        // a–g kötelező logika
        if ($req_a !== 1) { $errors[] = 'Az a) feltételt kötelező elfogadni.'; }
        if ($req_b === ''){ $errors[] = 'A b) Vállalkozó regisztrációs szám megadása kötelező.'; }
        if ($req_d !== 1) { $errors[] = 'A d) feltételt kötelező elfogadni.'; }
        if ($req_e !== 1) { $errors[] = 'Az e) feltételt kötelező elfogadni.'; }
        if ($req_f !== 1) { $errors[] = 'Az f) feltételt kötelező elfogadni.'; }
        if ($req_g !== 1) { $errors[] = 'A g) feltételt kötelező elfogadni.'; }

        // Alap defaults
        $template_type = 'felmeres';
        $project_type  = '';
        $project_code  = '';

        if (!$errors) {
            $sql = "INSERT INTO projects
                    (project_name, client_name, address, cadastral_number,
                     template_type, project_type, project_code,
                     req_a_csaladi_haz, req_b_kiv_reg_szam, req_d_szigeteltsg_kikotes,
                     req_e_kovetelmeny_ok, req_f_ketreteg, req_g_parareteg,
                     created_by, felmero_id, status)
                    VALUES (?,?,?,?,?,?,?,
                            ?,?,?, ?,?,?,
                            ?,?, 'draft')";
            $st = $conn->prepare($sql);
            if (!$st) { throw new RuntimeException('Előkészítési hiba: '.$conn->error); }

            $created_by = current_user_id();
            $felmero_id = $created_by;

            // típusok: s s s s s s s i s i i i i i i  (15 paraméter)
            $types = 'sssssssisiiiiii';
            $st->bind_param(
                $types,
                $project_name,
                $client_name,
                $address,
                $cadastral_number,
                $template_type,
                $project_type,
                $project_code,
                $req_a,
                $req_b,
                $req_d,
                $req_e,
                $req_f,
                $req_g,
                $created_by,
                $felmero_id
            );

            if (!$st->execute()) {
                throw new RuntimeException('Végrehajtási hiba: '.$st->error);
            }
            $st->close();

            $projectId = (int)$conn->insert_id;

            // Új flow: rögtön Munkatípusok
            header('Location: ' . BASE_URL . '/felmero/work_types.php?project_id='.$projectId);
            exit;
        }
    } catch (Throwable $e) {
        $errors[] = 'Mentési hiba: '.$e->getMessage();
    }
}
?>
<!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <title>Új projekt létrehozása</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="<?=ASSETS_URL?>/bootstrap/bootstrap.min.css">
  <style>
    .form-section{padding:1rem;border:1px solid #eee;border-radius:.5rem;margin-bottom:1rem}
  </style>
</head>
<body class="container py-4">
  <h1 class="mb-3">Új projekt létrehozása</h1>
  <p class="text-muted">Hozd létre az alap rekordot. A „Munkatípusok” jelölése a mentés után következik.</p>

  <?php if ($errors): ?>
    <div class="alert alert-danger">
      <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?=htmlspecialchars($e)?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <form method="post" class="row g-3">
    <?=csrf_input()?>

    <!-- Alap adatok -->
    <div class="col-12 form-section">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Projekt megnevezése *</label>
          <input name="project_name" class="form-control" required
                 placeholder="Pl. Energetikai felmérés – XY utca 12."
                 value="<?=htmlspecialchars($_POST['project_name'] ?? '')?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Szerződő neve (opcionális most)</label>
          <input name="client_name" class="form-control"
                 placeholder="Pl. Kovács Anna"
                 value="<?=htmlspecialchars($_POST['client_name'] ?? '')?>">
        </div>

        <div class="col-md-8">
          <label class="form-label">Ingatlan címe *</label>
          <input name="address" class="form-control" required
                 placeholder="Pl. 1234 Budapest, Minta u. 5."
                 value="<?=htmlspecialchars($_POST['address'] ?? '')?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Helyrajzi szám *</label>
          <input name="cadastral_number" class="form-control" required
                 placeholder="Pl. 1234/5"
                 value="<?=htmlspecialchars($_POST['cadastral_number'] ?? '')?>">
        </div>
      </div>
    </div>

    <!-- a–g nyilatkozatok -->
    <div class="col-12 form-section">
      <h5 class="mb-3">Jogszabályi megfelelőség (a–g)</h5>

      <div class="form-check mb-2">
        <input class="form-check-input" type="checkbox" id="req_a" name="req_a_csaladi_haz" required <?= isset($_POST['req_a_csaladi_haz'])?'checked':'' ?>>
        <label class="form-check-label" for="req_a">
          a) Családi ház (a program feltételeinek megfelelő épülettípus)
        </label>
      </div>

      <div class="mb-2">
        <label class="form-label" for="req_b">b) Vállalkozó regisztrációs száma *</label>
        <input class="form-control" id="req_b" name="req_b_kiv_reg_szam" required
               placeholder="Pl. V-123456" value="<?=htmlspecialchars($_POST['req_b_kiv_reg_szam'] ?? '')?>">
      </div>

      <div class="form-check mb-2">
        <input class="form-check-input" type="checkbox" id="req_d" name="req_d_szigeteltsg_kikotes" required <?= isset($_POST['req_d_szigeteltsg_kikotes'])?'checked':'' ?>>
        <label class="form-check-label" for="req_d">
          d) Kiinduló szerkezet szigeteletlen / a kiírásnak megfelelő
        </label>
      </div>

      <div class="form-check mb-2">
        <input class="form-check-input" type="checkbox" id="req_e" name="req_e_kovetelmeny_ok" required <?= isset($_POST['req_e_kovetelmeny_ok'])?'checked':'' ?>>
        <label class="form-check-label" for="req_e">
          e) Követelményeknek való megfelelés biztosítható
        </label>
      </div>

      <div class="form-check mb-2">
        <input class="form-check-input" type="checkbox" id="req_f" name="req_f_ketreteg" required <?= isset($_POST['req_f_ketreteg'])?'checked':'' ?>>
        <label class="form-check-label" for="req_f">
          f) Kétrétegű szigetelés (kiírás szerint)
        </label>
      </div>

      <div class="form-check">
        <input class="form-check-input" type="checkbox" id="req_g" name="req_g_parareteg" required <?= isset($_POST['req_g_parareteg'])?'checked':'' ?>>
        <label class="form-check-label" for="req_g">
          g) Pára-réteg / fólia megfelelően kialakítva
        </label>
      </div>
    </div>

    <div class="col-12 d-flex gap-2">
      <button class="btn btn-primary">Projekt létrehozása</button>
      <a class="btn btn-outline-secondary" href="<?=BASE_URL?>/felmero/dashboard.php">Mégse</a>
    </div>
  </form>

  <hr class="my-4">
  <div class="text-muted">
    <strong>Lépések utána:</strong>
    0) Munkatípusok → 1) Szerződő → 2) Tulajdonosok → 3) Épület → 4) Új hőtermelő (ha releváns) → 5) Képek.
  </div>
</body>
</html>
