<?php
// felmero/contractor.php — Szerződő fél adatainak rögzítése/szerkesztése + aláírás (canvas PNG)
// Mindig a projekt gyökerébe mentünk: BASE_DIR/signatures/YYYY/MM/*.png
// Az adatbázisban RELATÍV út: signatures/YYYY/MM/valami.png

require_once __DIR__.'/../includes/config.php';  // BASE_URL, BASE_DIR, SIGNATURES_DIR
require_once __DIR__.'/../includes/db.php';      // $conn (mysqli)
require_once __DIR__.'/../includes/csrf.php';    // csrf_token(), csrf_check(), stb.
require_once __DIR__.'/../includes/auth.php';    // saját auth
require_once __DIR__.'/../includes/scope.php';   // require_role(), assert_felmero_scope_on_project()
require_once __DIR__.'/../includes/upload.php';  // save_canvas_png()

require_role(['felmero']);

$projectId = (int)($_GET['project_id'] ?? 0);
if ($projectId <= 0) { http_response_code(400); exit('Hiányzó project_id.'); }
assert_felmero_scope_on_project($projectId);

// Régi elmentett utak normalizálása
function normalize_rel_signature_path(?string $p): ?string {
    if (!$p) return null;
    $p = str_replace('\\','/', $p);
    $p = ltrim($p, '/');

    // ABSZOLÚT FS út levágása
    $baseFs = rtrim(str_replace('\\','/', BASE_DIR), '/') . '/';
    if (strpos($p, $baseFs) === 0) {
        $p = substr($p, strlen($baseFs));
        $p = ltrim($p, '/');
    }

    // /epito3/ előtag levágása
    $proj = ltrim(BASE_URL, '/'); // epito3
    if (strpos($p, $proj . '/') === 0) {
        $p = substr($p, strlen($proj) + 1);
    }

    // felmero/signatures/ → signatures/
    if (strpos($p, 'felmero/signatures/') === 0) {
        $p = substr($p, strlen('felmero/'));
    }
    return $p;
}

// Meglévő szerződő betöltése
$party = null;
$st = $conn->prepare("SELECT id, project_id, name, birth_name, mothers_name, phone, mailing_addr, permanent_addr,
                             id_card, tax_number, birth_place, birth_date, email, signature_path
                      FROM contracting_parties WHERE project_id=? LIMIT 1");
if (!$st) { http_response_code(500); exit('Adatbázis hiba (prepare).'); }
$st->bind_param('i', $projectId);
$st->execute();
$res = $st->get_result();
if ($res) { $party = $res->fetch_assoc(); }
$st->close();

$errors  = [];
$success = false;

// Mentés
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_check($_POST['csrf'] ?? '');

        $name           = trim($_POST['name'] ?? '');
        $birth_name     = trim($_POST['birth_name'] ?? '');
        $mothers_name   = trim($_POST['mothers_name'] ?? '');
        $phone          = trim($_POST['phone'] ?? '');
        $mailing_addr   = trim($_POST['mailing_addr'] ?? '');
        $permanent_addr = trim($_POST['permanent_addr'] ?? '');
        $id_card        = trim($_POST['id_card'] ?? '');
        $tax_number     = trim($_POST['tax_number'] ?? '');
        $birth_place    = trim($_POST['birth_place'] ?? '');
        $birth_date     = trim($_POST['birth_date'] ?? '');
        $email          = trim($_POST['email'] ?? '');

        if ($name === '') { $errors[] = 'A szerződő neve kötelező.'; }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Érvénytelen email formátum.';
        }
        if ($birth_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth_date)) {
            $errors[] = 'A születési idő formátuma YYYY-MM-DD legyen.';
        }

        // Aláírás (canvas → PNG), relatív út vissza
        $signature_path = normalize_rel_signature_path($party['signature_path'] ?? null);
        if (!empty($_POST['signature_data'])) {
            $signature_path = save_canvas_png($_POST['signature_data'], SIGNATURES_DIR);
        }

        if (!$errors) {
            if ($party) {
                $sql = "UPDATE contracting_parties
                        SET name=?, birth_name=?, mothers_name=?, phone=?, mailing_addr=?, permanent_addr=?,
                            id_card=?, tax_number=?, birth_place=?, birth_date=NULLIF(?, ''), email=?, signature_path=?
                        WHERE id=?";
                $u = $conn->prepare($sql);
                if (!$u) { throw new RuntimeException('Adatbázis hiba (UPDATE előkészítés).'); }
                $u->bind_param(
                    'ssssssssssssi',
                    $name, $birth_name, $mothers_name, $phone, $mailing_addr, $permanent_addr,
                    $id_card, $tax_number, $birth_place, $birth_date, $email, $signature_path,
                    $party['id']
                );
                if (!$u->execute()) { throw new RuntimeException('Mentési hiba (UPDATE): '.$u->error); }
                $u->close();
                $contractorId = (int)$party['id'];
            } else {
                // ⛏️ FIX: a birth_place helyőrzője korábban kimaradt → most javítva
                $sql = "INSERT INTO contracting_parties
                        (project_id, name, birth_name, mothers_name, phone, mailing_addr, permanent_addr,
                         id_card, tax_number, birth_place, birth_date, email, signature_path)
                        VALUES (?,?,?,?,?,?,?,?,?, ?, NULLIF(?, ''), ?, ?)";
                $i = $conn->prepare($sql);
                if (!$i) { throw new RuntimeException('Adatbázis hiba (INSERT előkészítés).'); }
                $i->bind_param(
                    'issssssssssss',
                    $projectId, $name, $birth_name, $mothers_name, $phone, $mailing_addr, $permanent_addr,
                    $id_card, $tax_number, $birth_place, $birth_date, $email, $signature_path
                );
                if (!$i->execute()) { throw new RuntimeException('Mentési hiba (INSERT): '.$i->error); }
                $contractorId = (int)$conn->insert_id;
                $i->close();
            }

            // projects.szerzodo_id frissítése
            $upd = $conn->prepare("UPDATE projects SET szerzodo_id=? WHERE id=?");
            if ($upd) {
                $upd->bind_param('ii', $contractorId, $projectId);
                $upd->execute(); $upd->close();
            }

            // friss betöltés
            $st = $conn->prepare("SELECT * FROM contracting_parties WHERE project_id=? LIMIT 1");
            $st->bind_param('i', $projectId);
            $st->execute();
            $res = $st->get_result();
            $party = $res ? $res->fetch_assoc() : null;
            $st->close();

            $success = true;
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

require_once __DIR__.'/../includes/project_status.php';
recompute_project_status($conn, $projectId);

$sigRel = $party ? normalize_rel_signature_path($party['signature_path'] ?? null) : null;
$sigUrl = $sigRel ? (BASE_URL . '/' . $sigRel) : null;
?>
<!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <title>Szerződő adatai – Projekt #<?=htmlspecialchars($projectId)?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="<?=ASSETS_URL?>/bootstrap/bootstrap.min.css">
  <style>
    .sig-pad { border:1px solid #ccc; width: 100%; max-width: 420px; height: 160px; touch-action: none; }
    .form-section { padding: 1rem; border: 1px solid #eee; border-radius: .5rem; margin-bottom: 1rem; }
  </style>
</head>
<body class="container py-4">
  <h1 class="mb-3">Szerződő adatai <small class="text-muted">– Projekt #<?=htmlspecialchars($projectId)?></small></h1>

  <div class="mb-3">
    <a class="btn btn-outline-secondary" href="<?=BASE_URL?>/felmero/create_project.php">← Új projekt</a>
    <a class="btn btn-primary" href="<?=BASE_URL?>/felmero/owners.php?project_id=<?=$projectId?>">Tovább a Tulajdonosokhoz →</a>
  </div>

  <?php if ($success): ?>
    <div class="alert alert-success">Mentve.</div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?=htmlspecialchars($e)?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <form method="post" class="row g-3" id="contractorForm">
    <?=csrf_input()?>

    <div class="col-12 form-section">
      <h5>Alapadatok</h5>
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Szerződő neve *</label>
          <input name="name" class="form-control" required value="<?=htmlspecialchars($party['name'] ?? '')?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Születési név</label>
          <input name="birth_name" class="form-control" value="<?=htmlspecialchars($party['birth_name'] ?? '')?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Anyja neve</label>
          <input name="mothers_name" class="form-control" value="<?=htmlspecialchars($party['mothers_name'] ?? '')?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Telefonszám</label>
          <input name="phone" class="form-control" value="<?=htmlspecialchars($party['phone'] ?? '')?>">
        </div>

        <div class="col-md-6">
          <label class="form-label">Levelezési cím</label>
          <input name="mailing_addr" class="form-control" value="<?=htmlspecialchars($party['mailing_addr'] ?? '')?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Állandó lakcím</label>
          <input name="permanent_addr" class="form-control" value="<?=htmlspecialchars($party['permanent_addr'] ?? '')?>">
        </div>

        <div class="col-md-4">
          <label class="form-label">Szem. ig. szám</label>
          <input name="id_card" class="form-control" value="<?=htmlspecialchars($party['id_card'] ?? '')?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Adószám</label>
          <input name="tax_number" class="form-control" value="<?=htmlspecialchars($party['tax_number'] ?? '')?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Email</label>
          <input name="email" type="email" class="form-control" value="<?=htmlspecialchars($party['email'] ?? '')?>">
        </div>

        <div class="col-md-6">
          <label class="form-label">Születési hely</label>
          <input name="birth_place" class="form-control" value="<?=htmlspecialchars($party['birth_place'] ?? '')?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Születési idő (YYYY-MM-DD)</label>
          <input name="birth_date" type="date" class="form-control" value="<?=htmlspecialchars($party['birth_date'] ?? '')?>">
        </div>
      </div>
    </div>

    <div class="col-12 form-section">
      <h5>Aláírás</h5>
      <p class="text-muted">Írj alá az alábbi mezőben (egérrel vagy érintéssel).</p>
      <canvas id="sig" class="sig-pad"></canvas>
      <div class="mt-2">
        <button type="button" class="btn btn-sm btn-secondary" id="sigClear">Törlés</button>
        <?php if ($sigUrl): ?>
          <a class="btn btn-sm btn-outline-primary" target="_blank" href="<?=$sigUrl?>">Jelenlegi megnyitása</a>
        <?php endif; ?>
      </div>
      <input type="hidden" name="signature_data" id="signature_data">
    </div>

    <div class="col-12">
      <button class="btn btn-primary">Mentés</button>
      <a class="btn btn-outline-secondary" href="<?=BASE_URL?>/felmero/dashboard.php">Vissza</a>
    </div>
  </form>

<script>
(() => {
  const canvas = document.getElementById('sig');
  const ctx = canvas.getContext('2d');
  const ratio = Math.max(window.devicePixelRatio || 1, 1);
  function resize(){ const w=canvas.clientWidth,h=canvas.clientHeight; canvas.width=w*ratio; canvas.height=h*ratio;
    ctx.setTransform(ratio,0,0,ratio,0,0); ctx.lineWidth=2; ctx.lineJoin='round'; ctx.lineCap='round'; ctx.strokeStyle='#000';
    ctx.clearRect(0,0,w,h); }
  resize(); window.addEventListener('resize', resize);
  let drawing=false,last=null;
  function pos(e){ const r=canvas.getBoundingClientRect(); if(e.touches&&e.touches[0]) return {x:e.touches[0].clientX-r.left,y:e.touches[0].clientY-r.top};
    return {x:e.clientX-r.left,y:e.clientY-r.top}; }
  function start(e){ drawing=true; last=pos(e); }
  function move(e){ if(!drawing) return; const p=pos(e); ctx.beginPath(); ctx.moveTo(last.x,last.y); ctx.lineTo(p.x,p.y); ctx.stroke(); last=p; e.preventDefault(); }
  function end(){ drawing=false; last=null; }
  canvas.addEventListener('mousedown', start); canvas.addEventListener('mousemove', move); window.addEventListener('mouseup', end);
  canvas.addEventListener('touchstart', start, {passive:false}); canvas.addEventListener('touchmove', move, {passive:false}); canvas.addEventListener('touchend', end);
  document.getElementById('sigClear').addEventListener('click', ()=>ctx.clearRect(0,0,canvas.width,canvas.height));
  document.getElementById('contractorForm').addEventListener('submit', ()=>{ document.getElementById('signature_data').value = canvas.toDataURL('image/png'); });
})();
</script>
</body>
</html>
