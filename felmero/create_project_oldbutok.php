<?php
// felmero/create_project.php (MYSQLI FIX)
// Új projekt létrehozása felmérő által → a rekord a bejelentkezett felmérőhöz kötődik.
// Mentés után átirányítás a Szerződő oldalra.

require_once __DIR__.'/../includes/config.php'; // BASE_URL, ASSETS_URL
require_once __DIR__.'/../includes/db.php';      // $conn = new mysqli(...)
require_once __DIR__.'/../includes/csrf.php';    // session + csrf
require_once __DIR__.'/../includes/auth.php';    // a meglévő auth-od

// --- SHIM: ha nincs current_user_id()/require_role(), pótoljuk --- //
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
// ------------------------------------------------------------------ //

require_role(['felmero']); // csak felmérő hozhat létre projektet

$errors = [];

// POST feldolgozás (projekt létrehozása)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_check($_POST['csrf'] ?? '');

        $project_name     = trim($_POST['project_name'] ?? '');
        $client_name      = trim($_POST['client_name'] ?? '');
        $address          = trim($_POST['address'] ?? '');
        $cadastral_number = trim($_POST['cadastral_number'] ?? '');

        if ($project_name === '')     { $errors[] = 'A projekt neve/megnevezése kötelező.'; }
        if ($address === '')          { $errors[] = 'Az ingatlan címe kötelező.'; }
        if ($cadastral_number === '') { $errors[] = 'A helyrajzi szám kötelező.'; }

        // A projects táblában több NOT NULL mező is van (template_type, project_type, project_code, created_by).
        // Biztonságos kezdőértékeket adunk:
        $template_type = 'felmeres'; // kiinduló jelölés; később átírható
        $project_type  = '';         // üres is megengedett nálad
        $project_code  = '';         // üres is megengedett nálad

        if (!$errors) {
            // mysqli prepared statement
            $sql = "INSERT INTO projects
                    (project_name, client_name, address, cadastral_number,
                     template_type, project_type, project_code,
                     created_by, felmero_id, status)
                    VALUES (?,?,?,?,?,?,?,?,?, 'draft')";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException('Előkészítési hiba: '.$conn->error);
            }
            $created_by = current_user_id();
            $felmero_id = $created_by;

            // s = string, i = integer
            $stmt->bind_param(
                'sssssssii',
                $project_name,
                $client_name,
                $address,
                $cadastral_number,
                $template_type,
                $project_type,
                $project_code,
                $created_by,
                $felmero_id
            );

            if (!$stmt->execute()) {
                throw new RuntimeException('Végrehajtási hiba: '.$stmt->error);
            }

            $successId = (int)$conn->insert_id;

            // átirányítás a Szerződő rögzítésére
            header('Location: ' . BASE_URL . '/felmero/contractor.php?project_id='.$successId);
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
    .form-section { padding: 1rem; border: 1px solid #eee; border-radius: .5rem; margin-bottom: 1rem; }
  </style>
</head>
<body class="container py-4">
  <h1 class="mb-3">Új projekt létrehozása</h1>
  <p class="text-muted">Ez csak az alap rekordot hozza létre. A részletes adatokat a következő lépésekben viszed fel (Szerződő, Tulajdonosok, Épület stb.).</p>

  <?php if ($errors): ?>
    <div class="alert alert-danger">
      <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?=htmlspecialchars($e)?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <form method="post" class="row g-3">
    <?=csrf_input()?>

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

    <div class="col-12">
      <button class="btn btn-primary">Projekt létrehozása</button>
      <a class="btn btn-outline-secondary" href="<?=BASE_URL?>/felmero/dashboard.php">Mégse</a>
    </div>
  </form>

  <hr class="my-4">
  <div class="text-muted">
    <strong>Lépések a létrehozás után:</strong>
    1) Szerződő adatai és aláírás → 2) Tulajdonosok és aláírások → 3) Épület adatok → 4) Új hőtermelő → 5) Képfeltöltés.
  </div>
</body>
</html>
