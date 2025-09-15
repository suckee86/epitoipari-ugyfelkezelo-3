<?php
// felmero/owners.php — Tulajdonosok: lista + hozzáadás/szerkesztés/törlés + aláírás (canvas PNG)

require_once __DIR__.'/../includes/config.php';  // BASE_URL, BASE_DIR, SIGNATURES_DIR
require_once __DIR__.'/../includes/db.php';      // $conn (mysqli)
require_once __DIR__.'/../includes/csrf.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/scope.php';   // require_role(), assert_felmero_scope_on_project(), owner_belongs_to_project()
require_once __DIR__.'/../includes/upload.php';  // save_canvas_png()

require_role(['felmero']);

$projectId = (int)($_GET['project_id'] ?? 0);
if ($projectId <= 0) { http_response_code(400); exit('Hiányzó project_id.'); }
assert_felmero_scope_on_project($projectId);

$errors  = [];
$success = null; // 'created' | 'updated' | 'deleted'

// POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_check($_POST['csrf'] ?? '');
        $action = $_POST['action'] ?? '';

        if ($action === 'create_owner') {
            // Limit: max 10 tulaj
            $cnt = $conn->prepare("SELECT COUNT(*) FROM project_owners WHERE project_id=?");
            $cnt->bind_param('i', $projectId);
            $cnt->execute(); $cnt->bind_result($n); $cnt->fetch(); $cnt->close();
            if ((int)$n >= 10) { throw new RuntimeException('Legfeljebb 10 tulajdonos rögzíthető.'); }

            $owner_name     = trim($_POST['owner_name'] ?? '');
            $birth_name     = trim($_POST['birth_name'] ?? '');
            $mailing_addr   = trim($_POST['mailing_addr'] ?? '');
            $permanent_addr = trim($_POST['permanent_addr'] ?? '');
            $id_card        = trim($_POST['id_card'] ?? '');
            $signature_data = $_POST['signature_data'] ?? '';

            if ($owner_name === '') { $errors[] = 'A tulajdonos neve kötelező.'; }
            if ($id_card === '')    { $errors[] = 'A személyi igazolvány száma kötelező.'; }

            $signature_path = null;
            if ($signature_data) {
                $signature_path = save_canvas_png($signature_data, SIGNATURES_DIR); // rel. út
            }

            if (!$errors) {
                // FIGYELEM: a te DB-dben a project_owners mezői bővülhettek.
                // Ha ezek még nincsenek, vedd ki a fölös oszlopokat.
                $ins = $conn->prepare(
                    "INSERT INTO project_owners
                     (project_id, owner_name, birth_name, mailing_addr, permanent_addr, id_card, signature_path)
                     VALUES (?,?,?,?,?,?,?)"
                );
                if (!$ins) { throw new RuntimeException('Adatbázis hiba: '.$conn->error); }
                $ins->bind_param('issssss',
                    $projectId, $owner_name, $birth_name, $mailing_addr, $permanent_addr, $id_card, $signature_path
                );
                if (!$ins->execute()) { throw new RuntimeException('Mentési hiba: '.$ins->error); }
                $ownerId = (int)$conn->insert_id;
                $ins->close();

                // Napló a project_signatures-be —> NÁLAD: owner_index + image_path a helyes mezők
                if ($signature_path) {
                    $ps = $conn->prepare(
                        "INSERT INTO project_signatures (project_id, type, owner_index, image_path)
                         VALUES (?, 'tulajdonos', ?, ?)"
                    );
                    if ($ps) { $ps->bind_param('iis', $projectId, $ownerId, $signature_path); $ps->execute(); $ps->close(); }
                }
                $success = 'created';
				require_once __DIR__.'/../includes/project_status.php';
				recompute_project_status($conn, $projectId);
            }

        } elseif ($action === 'update_owner') {
            $ownerId = (int)($_POST['owner_id'] ?? 0);
            if ($ownerId <= 0 || !owner_belongs_to_project($ownerId, $projectId)) {
                throw new RuntimeException('Érvénytelen tulajdonos.');
            }

            $owner_name     = trim($_POST['owner_name'] ?? '');
            $birth_name     = trim($_POST['birth_name'] ?? '');
            $mailing_addr   = trim($_POST['mailing_addr'] ?? '');
            $permanent_addr = trim($_POST['permanent_addr'] ?? '');
            $id_card        = trim($_POST['id_card'] ?? '');
            $signature_data = $_POST['signature_data'] ?? '';

            if ($owner_name === '') { $errors[] = 'A tulajdonos neve kötelező.'; }
            if ($id_card === '')    { $errors[] = 'A személyi igazolvány száma kötelező.'; }

            // jelenlegi aláírás
            $cur = $conn->prepare("SELECT signature_path FROM project_owners WHERE id=?");
            $cur->bind_param('i', $ownerId);
            $cur->execute();
            $res = $cur->get_result();
            $curRow = $res ? $res->fetch_assoc() : null;
            $cur->close();
            $signature_path = $curRow['signature_path'] ?? null;

            if ($signature_data) {
                // régi törlése FS-en
                if ($signature_path) {
                    $absOld = BASE_DIR . '/' . ltrim($signature_path, '/\\');
                    if (is_file($absOld)) { @unlink($absOld); }
                }
                // új mentése
                $signature_path = save_canvas_png($signature_data, SIGNATURES_DIR);

                // napló a project_signatures-ben (owner_index + image_path)
                $ps = $conn->prepare(
                    "INSERT INTO project_signatures (project_id, type, owner_index, image_path)
                     VALUES (?, 'tulajdonos', ?, ?)"
                );
                if ($ps) { $ps->bind_param('iis', $projectId, $ownerId, $signature_path); $ps->execute(); $ps->close(); }
            }

            if (!$errors) {
                $upd = $conn->prepare(
                    "UPDATE project_owners
                     SET owner_name=?, birth_name=?, mailing_addr=?, permanent_addr=?, id_card=?, signature_path=?
                     WHERE id=?"
                );
                if (!$upd) { throw new RuntimeException('Adatbázis hiba: '.$conn->error); }
                $upd->bind_param('ssssssi',
                    $owner_name, $birth_name, $mailing_addr, $permanent_addr, $id_card, $signature_path, $ownerId
                );
                if (!$upd->execute()) { throw new RuntimeException('Mentési hiba: '.$upd->error); }
                $upd->close();

                $success = 'updated';
				require_once __DIR__.'/../includes/project_status.php';
				recompute_project_status($conn, $projectId);
            }

        } elseif ($action === 'delete_owner') {
            $ownerId = (int)($_POST['owner_id'] ?? 0);
            if ($ownerId <= 0 || !owner_belongs_to_project($ownerId, $projectId)) {
                throw new RuntimeException('Érvénytelen tulajdonos.');
            }

            // tulaj aláírás törlése FS-en
            $cur = $conn->prepare("SELECT signature_path FROM project_owners WHERE id=?");
            $cur->bind_param('i', $ownerId);
            $cur->execute();
            $res = $cur->get_result();
            $curRow = $res ? $res->fetch_assoc() : null;
            $cur->close();

            $rel = $curRow['signature_path'] ?? '';
            $abs = $rel ? BASE_DIR . '/' . ltrim($rel, '/\\') : '';
            if ($rel && is_file($abs)) { @unlink($abs); }

            // project_signatures fájlok törlése FS-en (image_path), és bejegyzések törlése
            $ps = $conn->prepare("SELECT image_path FROM project_signatures WHERE project_id=? AND type='tulajdonos' AND owner_index=?");
            if ($ps) {
                $ps->bind_param('ii', $projectId, $ownerId);
                $ps->execute();
                $rs = $ps->get_result();
                while ($row = $rs->fetch_assoc()) {
                    $rel2 = $row['image_path'] ?? '';
                    $abs2 = $rel2 ? BASE_DIR . '/' . ltrim($rel2, '/\\') : '';
                    if ($rel2 && is_file($abs2)) { @unlink($abs2); }
                }
                $ps->close();
            }
            $delps = $conn->prepare("DELETE FROM project_signatures WHERE project_id=? AND type='tulajdonos' AND owner_index=?");
            if ($delps) { $delps->bind_param('ii', $projectId, $ownerId); $delps->execute(); $delps->close(); }

            // tulaj rekord törlése
            $del = $conn->prepare("DELETE FROM project_owners WHERE id=?");
            if (!$del) { throw new RuntimeException('Adatbázis hiba: '.$conn->error); }
            $del->bind_param('i', $ownerId);
            if (!$del->execute()) { throw new RuntimeException('Törlési hiba: '.$del->error); }
            $del->close();

            $success = 'deleted';
			require_once __DIR__.'/../includes/project_status.php';
			recompute_project_status($conn, $projectId);
        } else {
            throw new RuntimeException('Ismeretlen művelet.');
        }

    } catch (Throwable $e) {
        // magyar hibaüzenet
        $errors[] = $e->getMessage();
    }
}

// Lista
$owners = [];
$st = $conn->prepare("SELECT id, owner_name, birth_name, mailing_addr, permanent_addr, id_card, signature_path, created_at
                      FROM project_owners WHERE project_id=? ORDER BY id ASC");
$st->bind_param('i', $projectId);
$st->execute();
$rs = $st->get_result();
while ($row = $rs->fetch_assoc()) { $owners[] = $row; }
$st->close();

// Szerkesztés
$editOwner = null;
$editId = (int)($_GET['owner_id'] ?? 0);
if ($editId > 0 && owner_belongs_to_project($editId, $projectId)) {
    $q = $conn->prepare("SELECT * FROM project_owners WHERE id=?");
    $q->bind_param('i', $editId);
    $q->execute();
    $res = $q->get_result();
    $editOwner = $res ? $res->fetch_assoc() : null;
    $q->close();
}
?>
<!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <title>Tulajdonosok – Projekt #<?=htmlspecialchars($projectId)?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="<?=ASSETS_URL?>/bootstrap/bootstrap.min.css">
  <style>
    .sig-pad { border:1px solid #ccc; width: 100%; max-width: 420px; height: 160px; touch-action: none; }
    .form-section { padding: 1rem; border: 1px solid #eee; border-radius: .5rem; margin-bottom: 1rem; }
    .table-fit td { vertical-align: middle; }
  </style>
</head>
<body class="container py-4">
  <h1 class="mb-3">Tulajdonosok <small class="text-muted">– Projekt #<?=htmlspecialchars($projectId)?></small></h1>

  <div class="mb-3">
    <a class="btn btn-outline-secondary" href="<?=BASE_URL?>/felmero/contractor.php?project_id=<?=$projectId?>">← Szerződő</a>
    <a class="btn btn-primary" href="<?=BASE_URL?>/felmero/building.php?project_id=<?=$projectId?>">Tovább az Épület adatokhoz →</a>
  </div>

  <?php if ($success === 'created'): ?>
    <div class="alert alert-success">Új tulajdonos mentve.</div>
  <?php elseif ($success === 'updated'): ?>
    <div class="alert alert-success">Tulajdonos frissítve.</div>
  <?php elseif ($success === 'deleted'): ?>
    <div class="alert alert-warning">Tulajdonos törölve.</div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?=htmlspecialchars($e)?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <!-- Lista -->
  <div class="card mb-4">
    <div class="card-header">Rögzített tulajdonosok</div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-striped table-hover table-fit mb-0">
          <thead>
            <tr>
              <th>#</th>
              <th>Név</th>
              <th>Születési név</th>
              <th>Levelezési cím</th>
              <th>Állandó lakcím</th>
              <th>Szem. ig. szám</th>
              <th>Aláírás</th>
              <th class="text-end">Műveletek</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$owners): ?>
              <tr><td colspan="8" class="text-center p-3 text-muted">Nincs rögzített tulajdonos.</td></tr>
            <?php else: foreach ($owners as $o): ?>
              <tr>
                <td><?=htmlspecialchars($o['id'])?></td>
                <td><?=htmlspecialchars($o['owner_name'])?></td>
                <td><?=htmlspecialchars($o['birth_name'])?></td>
                <td><?=htmlspecialchars($o['mailing_addr'])?></td>
                <td><?=htmlspecialchars($o['permanent_addr'])?></td>
                <td><?=htmlspecialchars($o['id_card'])?></td>
                <td>
                  <?php if (!empty($o['signature_path'])): ?>
                    <a href="<?= BASE_URL . '/' . htmlspecialchars($o['signature_path']) ?>" target="_blank">Megnyitás</a>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td class="text-end">
                  <a class="btn btn-sm btn-outline-primary"
                     href="<?=BASE_URL?>/felmero/owners.php?project_id=<?=$projectId?>&owner_id=<?=$o['id']?>">Szerkeszt</a>
                  <form method="post" class="d-inline" onsubmit="return confirm('Biztos törlöd?');">
                    <?=csrf_input()?>
                    <input type="hidden" name="action" value="delete_owner">
                    <input type="hidden" name="owner_id" value="<?=$o['id']?>">
                    <button class="btn btn-sm btn-outline-danger">Törlés</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Új / Szerkesztés űrlap -->
  <div class="form-section">
    <h5><?= $editOwner ? 'Tulajdonos szerkesztése' : 'Új tulajdonos hozzáadása' ?></h5>
    <form method="post" id="ownerForm">
      <?=csrf_input()?>
      <input type="hidden" name="action" value="<?= $editOwner ? 'update_owner' : 'create_owner' ?>">
      <?php if ($editOwner): ?><input type="hidden" name="owner_id" value="<?=$editOwner['id']?>"><?php endif; ?>

      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Tulajdonos neve *</label>
          <input name="owner_name" class="form-control" required value="<?=htmlspecialchars($editOwner['owner_name'] ?? '')?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Születési név</label>
          <input name="birth_name" class="form-control" value="<?=htmlspecialchars($editOwner['birth_name'] ?? '')?>">
        </div>

        <div class="col-md-6">
          <label class="form-label">Levelezési cím</label>
          <input name="mailing_addr" class="form-control" value="<?=htmlspecialchars($editOwner['mailing_addr'] ?? '')?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Állandó lakcím</label>
          <input name="permanent_addr" class="form-control" value="<?=htmlspecialchars($editOwner['permanent_addr'] ?? '')?>">
        </div>

        <div class="col-md-6">
          <label class="form-label">Személyi igazolvány száma *</label>
          <input name="id_card" class="form-control" required value="<?=htmlspecialchars($editOwner['id_card'] ?? '')?>">
        </div>

        <div class="col-12">
          <label class="form-label">Aláírás (digitális pad)</label>
          <div class="mb-2 text-muted">Egérrel vagy érintéssel írd alá a mezőben.</div>
          <canvas id="sig" class="sig-pad"></canvas>
          <div class="mt-2">
            <button type="button" class="btn btn-sm btn-secondary" id="sigClear">Törlés</button>
            <?php if (!empty($editOwner['signature_path'])): ?>
              <a class="btn btn-sm btn-outline-primary" target="_blank"
                 href="<?= BASE_URL . '/' . htmlspecialchars($editOwner['signature_path']) ?>">Jelenlegi megnyitása</a>
            <?php endif; ?>
          </div>
          <input type="hidden" name="signature_data" id="signature_data">
          <div class="form-text">Ha nem írsz új aláírást, a korábbi megmarad.</div>
        </div>
      </div>

      <div class="mt-3">
        <button class="btn btn-primary"><?= $editOwner ? 'Mentés' : 'Hozzáadás' ?></button>
        <?php if ($editOwner): ?>
          <a class="btn btn-outline-secondary" href="<?=BASE_URL?>/felmero/owners.php?project_id=<?=$projectId?>">Mégse</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

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
  document.getElementById('ownerForm').addEventListener('submit', ()=>{ document.getElementById('signature_data').value = canvas.toDataURL('image/png'); });
})();
</script>
</body>
</html>
