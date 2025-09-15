<?php
// index.php — Bejelentkezés + redirect: felmero/dashboard.php
define('PUBLIC_ENTRY', true);

require_once __DIR__.'/includes/config.php'; // BASE_URL, ASSETS_URL
require_once __DIR__.'/includes/db.php';     // $conn (mysqli)
require_once __DIR__.'/includes/csrf.php';   // session + csrf
//require_once __DIR__.'/includes/auth.php';   // ha van saját auth, együtt tud élni

// Ha már be van lépve, irány a dashboard
if (!empty($_SESSION['user_id']) || !empty($_SESSION['id']) || !empty($_SESSION['user']['id'])) {
    //header('Location: ' . BASE_URL . '/felmero/dashboard.php');
	header('Location: felmero/dashboard.php');
    exit;
}

// INFO_SCHEMA helper a rugalmas sémakezeléshez
function column_exists(mysqli $conn, string $table, string $col): bool {
    $sql = "SELECT COUNT(*) c FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?";
    $st = $conn->prepare($sql);
    if (!$st) return false;
    $st->bind_param('ss',$table,$col);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $st->close();
    return !empty($r) && (int)$r['c']>0;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_check($_POST['csrf'] ?? '');
        $login = trim($_POST['login'] ?? '');     // email vagy felhasználónév
        $pass  = (string)($_POST['password'] ?? '');

        if ($login === '' || $pass === '') {
            throw new RuntimeException('Kérlek add meg az azonosítót és a jelszót.');
        }

        // Mely oszlopok vannak?
        $hasEmail = column_exists($conn,'users','email');
        $hasUsern = column_exists($conn,'users','username');
        $hasRole  = column_exists($conn,'users','role');
        $hasPH    = column_exists($conn,'users','password_hash');
        $hasP     = column_exists($conn,'users','password');
        $hasJ     = column_exists($conn,'users','jelszo'); // magyar örökség :)

        // SELECT összeállítás (ami nincs, aliasoljuk)
        $selCols = [
            'id',
            $hasEmail ? 'email' : "'' AS email",
            $hasUsern ? 'username' : "'' AS username",
            $hasRole  ? 'role' : "'' AS role",
            $hasPH    ? 'password_hash' : "'' AS password_hash",
            $hasP     ? 'password' : "'' AS password",
            $hasJ     ? 'jelszo' : "'' AS jelszo",
        ];
        $sql = "SELECT ".implode(',', $selCols)." FROM users WHERE ";

        // WHERE az elérhető login-mezők szerint
        $wheres = [];
        $params = [];
        $types  = '';
        if ($hasEmail) { $wheres[] = 'email = ?';    $params[] = $login; $types.='s'; }
        if ($hasUsern) { $wheres[] = 'username = ?'; $params[] = $login; $types.='s'; }
        if (!$wheres) { throw new RuntimeException('A felhasználói azonosító mezők (email/username) hiányoznak a users táblából.'); }
        $sql .= '('.implode(' OR ', $wheres).') LIMIT 1';

        $st = $conn->prepare($sql);
        if (!$st) { throw new RuntimeException('Adatbázis hiba: '.$conn->error); }
        $st->bind_param($types, ...$params);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();

        if (!$row) {
            throw new RuntimeException('Hibás azonosító vagy jelszó.');
        }

        // Jelszó ellenőrzés (prioritás: password_hash → password → jelszo)
        $ok = false;
        $stored = null;

        if ($hasPH && !empty($row['password_hash'])) {
            $stored = $row['password_hash'];
            // Ha modern hash (bcrypt/argon2), password_verify
            if (is_string($stored) && str_starts_with($stored, '$')) {
                $ok = password_verify($pass, $stored);
            }
        }
        if (!$ok && $hasP && !empty($row['password'])) {
            $stored = $row['password'];
            if (is_string($stored) && str_starts_with($stored, '$')) {
                $ok = password_verify($pass, $stored);
            } elseif (is_string($stored) && preg_match('/^[a-f0-9]{32}$/i', $stored)) {
                $ok = (md5($pass) === strtolower($stored)); // örökségi MD5 fallback
            } else {
                $ok = ($pass === $stored); // plain fallback (fejlesztői környezet)
            }
        }
        if (!$ok && $hasJ && !empty($row['jelszo'])) {
            $stored = $row['jelszo'];
            if (is_string($stored) && str_starts_with($stored, '$')) {
                $ok = password_verify($pass, $stored);
            } elseif (is_string($stored) && preg_match('/^[a-f0-9]{32}$/i', $stored)) {
                $ok = (md5($pass) === strtolower($stored));
            } else {
                $ok = ($pass === $stored);
            }
        }

        if (!$ok) {
            throw new RuntimeException('Hibás azonosító vagy jelszó.');
        }

        // Session felépítése (kompat a meglévő shim-ekkel)
        $uid  = (int)$row['id'];
        $role = (string)($row['role'] ?? '');

        $_SESSION['user_id'] = $uid;
        $_SESSION['id']      = $uid;
        $_SESSION['role']    = $role ?: 'felmero';
        $_SESSION['user']    = ['id'=>$uid, 'role'=>($_SESSION['role'])];

        // Siker — irány a felmérő dashboard
        header('Location: felmero/dashboard.php');
        exit;

    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <title>Bejelentkezés</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="<?=ASSETS_URL?>/bootstrap/bootstrap.min.css">
  <style>
    body{min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f7f8fa}
    .login-card{width:100%;max-width:420px}
    .brand{font-weight:700;letter-spacing:.3px}
  </style>
</head>
<body>
  <div class="login-card">
    <div class="card shadow-sm border-0">
      <div class="card-body p-4">
        <div class="text-center mb-3">
          <div class="brand fs-4">Építőipari ügyfélkezelő</div>
          <div class="text-muted">Felmérő belépés</div>
        </div>

        <?php if ($errors): ?>
          <div class="alert alert-danger">
            <ul class="mb-0"><?php foreach($errors as $e): ?><li><?=htmlspecialchars($e)?></li><?php endforeach; ?></ul>
          </div>
        <?php endif; ?>

        <form method="post" novalidate>
          <?=csrf_input()?>
          <div class="mb-3">
            <label class="form-label">Email vagy felhasználónév</label>
            <input class="form-control" name="login" autofocus required placeholder="pl. felmero@ceg.hu">
          </div>
          <div class="mb-3">
            <label class="form-label">Jelszó</label>
            <input class="form-control" type="password" name="password" required>
          </div>
          <div class="d-grid gap-2">
            <button class="btn btn-primary">Belépés</button>
          </div>
        </form>

        <div class="text-center text-muted small mt-3">© <?=date('Y')?> — belső rendszer</div>
      </div>
    </div>
  </div>
</body>
</html>
