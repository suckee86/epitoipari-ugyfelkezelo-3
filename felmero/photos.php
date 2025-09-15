<?php
// felmero/photos.php — Projekt képfeltöltés kategóriák szerint + törlés + státusz újraszámolás
// Mentési hely: BASE_DIR/uploads/projects/{projectId}/images/{category}/YYYY/MM/*.jpg|png
// DB: project_images (ha nincs, létrehozza; ha hiányzik oszlop, hozzáadja)

require_once __DIR__.'/../includes/config.php';    // BASE_URL, ASSETS_URL, BASE_DIR
require_once __DIR__.'/../includes/db.php';        // $conn (mysqli)
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/csrf.php';
require_once __DIR__.'/../includes/scope.php';     // require_role(), assert_felmero_scope_on_project()
require_once __DIR__.'/../includes/project_status.php'; // table_exists(), column_exists(), recompute_project_status()

require_role(['felmero']);

$projectId = (int)($_GET['project_id'] ?? 0);
if ($projectId <= 0) { http_response_code(400); exit('Hiányzó project_id.'); }
assert_felmero_scope_on_project($projectId);

// ===== Schema: project_images biztosítása (a table_exists/column_exists a project_status.php-ból jön) =====
function ensure_project_images_table(mysqli $conn): void {
    if (!table_exists($conn,'project_images')) {
        $sql = "
        CREATE TABLE `project_images` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `project_id` INT(11) NOT NULL,
            `category` VARCHAR(50) NOT NULL,
            `image_path` VARCHAR(255) NOT NULL,
            `room` VARCHAR(100) NULL,
            `note` VARCHAR(200) NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `pi_proj_idx` (`project_id`),
            CONSTRAINT `pi_proj_fk` FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $conn->query($sql);
    } else {
        $adds = [];
        if (!column_exists($conn,'project_images','category'))   $adds[] = "ADD COLUMN `category` VARCHAR(50) NOT NULL DEFAULT ''";
        if (!column_exists($conn,'project_images','image_path')) $adds[] = "ADD COLUMN `image_path` VARCHAR(255) NOT NULL DEFAULT ''";
        if (!column_exists($conn,'project_images','room'))       $adds[] = "ADD COLUMN `room` VARCHAR(100) NULL";
        if (!column_exists($conn,'project_images','note'))       $adds[] = "ADD COLUMN `note` VARCHAR(200) NULL";
        if (!column_exists($conn,'project_images','created_at')) $adds[] = "ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP";
        if ($adds) { $sql = "ALTER TABLE `project_images` ".implode(', ',$adds); $conn->query($sql); }
    }
}
ensure_project_images_table($conn);

// ===== Kategóriák és limitek =====
$CATS = [
  'exterior_streetno' => ['label' => 'Ingatlan kívülről – utcatábla + házszám', 'max'=>5,  'min'=>1, 'room'=>false, 'note'=>false],
  'side_view'         => ['label' => 'Oldalnézet (formája + tető)',            'max'=>5,  'min'=>1, 'room'=>false, 'note'=>false],
  'facades'           => ['label' => 'Homlokzatok (minden homlokzat külön)',   'max'=>5,  'min'=>1, 'room'=>false, 'note'=>true],
  'heating_emitters'  => ['label' => 'Hőtermelő/Hőleadó – helyiségenként',     'max'=>50, 'min'=>1, 'room'=>true,  'note'=>true],
  'attic'             => ['label' => 'Padlás',                                 'max'=>30, 'min'=>2, 'room'=>false, 'note'=>false],
  'floorplan'         => ['label' => 'Alaprajz befotózva',                      'max'=>5,  'min'=>1, 'room'=>false, 'note'=>false],
  'idcard_front'      => ['label' => 'Lakcímkártya – elől',                     'max'=>1,  'min'=>1, 'room'=>false, 'note'=>false],
  'idcard_back'       => ['label' => 'Lakcímkártya – hátul',                    'max'=>1,  'min'=>1, 'room'=>false, 'note'=>false],
];

// ===== Util =====
function rel_url(string $rel): string { return rtrim(BASE_URL,'/').'/'.ltrim($rel,'/'); }
function save_uploaded_image(array $file, string $category, int $projectId): string {
    // validáció + mentés, visszaad relatív útvonalat BASE_DIR-hez képest
    $maxBytes = 10*1024*1024; // 10 MB
    if ($file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Feltöltési hiba ('.$file['error'].')');
    if ($file['size'] <= 0 || $file['size'] > $maxBytes) throw new RuntimeException('A fájl mérete túl nagy (max 10MB).');

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    $extMap = ['image/jpeg'=>'jpg','image/png'=>'png'];
    if (!isset($extMap[$mime])) throw new RuntimeException('Csak JPG/PNG tölthető fel.');
    $ext = $extMap[$mime];

    $subdir = "uploads/projects/{$projectId}/images/{$category}/".date('Y/m');
    $absDir = rtrim(BASE_DIR,'/')."/{$subdir}";
    if (!is_dir($absDir)) { @mkdir($absDir, 0775, true); }

    $name = bin2hex(random_bytes(8)).'.'.$ext;
    $abs  = "{$absDir}/{$name}";
    if (!move_uploaded_file($file['tmp_name'], $abs)) throw new RuntimeException('Mentési hiba (move_uploaded_file).');

    return "{$subdir}/{$name}"; // relatív
}
function insert_project_image(mysqli $conn, int $projectId, string $category, string $relPath, ?string $room, ?string $note): void {
    $hasFileName = column_exists($conn,'project_images','file_name');
    if ($hasFileName) {
        $sql = "INSERT INTO project_images (project_id, category, image_path, file_name, room, note) VALUES (?,?,?,?,?,?)";
        $st  = $conn->prepare($sql);
        $fileName = basename($relPath);
        if(!$st) throw new RuntimeException('DB hiba (INSERT előkészítés).');
        $st->bind_param('isssss', $projectId, $category, $relPath, $fileName, $room, $note);
    } else {
        $sql = "INSERT INTO project_images (project_id, category, image_path, room, note) VALUES (?,?,?,?,?)";
        $st  = $conn->prepare($sql);
        if(!$st) throw new RuntimeException('DB hiba (INSERT előkészítés).');
        $st->bind_param('issss', $projectId, $category, $relPath, $room, $note);
    }
    if (!$st->execute()) throw new RuntimeException('DB hiba (INSERT): '.$st->error);
    $st->close();
}
function image_rows(mysqli $conn, int $projectId): array {
    $st = $conn->prepare("SELECT id, category, image_path, room, note, created_at, 
                                 ".(column_exists($conn,'project_images','file_name')?'file_name':'\'\' AS file_name')."
                          FROM project_images WHERE project_id=? ORDER BY id DESC");
    $st->bind_param('i',$projectId); $st->execute();
    $res = $st->get_result(); $rows=[];
    while($r=$res->fetch_assoc()){ $rows[]=$r; }
    $st->close();
    return $rows;
}
function delete_image(mysqli $conn, int $id, int $projectId): void {
    $st = $conn->prepare("SELECT image_path, ".(column_exists($conn,'project_images','file_name')?'file_name':'\'\' AS file_name')." FROM project_images WHERE id=? AND project_id=?");
    $st->bind_param('ii',$id,$projectId); $st->execute();
    $row = $st->get_result()->fetch_assoc(); $st->close();
    if (!$row) throw new RuntimeException('Kép nem található.');

    $rel = $row['image_path'] ?? '';
    if ($rel) {
        $abs = rtrim(BASE_DIR,'/').'/'.ltrim($rel,'/');
        if (is_file($abs)) @unlink($abs);
    } elseif (!empty($row['file_name'])) {
        // régi séma fallback
        $abs = rtrim(BASE_DIR,'/')."/uploads/projects/{$projectId}/".basename($row['file_name']);
        if (is_file($abs)) @unlink($abs);
    }

    $del = $conn->prepare("DELETE FROM project_images WHERE id=? AND project_id=?");
    $del->bind_param('ii',$id,$projectId); $del->execute(); $del->close();
}

// ===== Adatok =====
$errors = [];
$successMsg = null;

if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        csrf_check($_POST['csrf'] ?? '');
        $action = $_POST['action'] ?? '';

        if ($action === 'upload') {
            $category = $_POST['category'] ?? '';
            if (!isset($CATS[$category])) throw new RuntimeException('Ismeretlen kategória.');

            // darabszám limit ellenőrzés
            $cntSt = $conn->prepare("SELECT COUNT(*) c FROM project_images WHERE project_id=? AND category=?");
            $cntSt->bind_param('is',$projectId,$category); $cntSt->execute();
            $have = (int)($cntSt->get_result()->fetch_assoc()['c'] ?? 0);
            $cntSt->close();

            $max = (int)$CATS[$category]['max'];
            $files = $_FILES['images'] ?? null;
            if (!$files || !isset($files['name']) || !is_array($files['name'])) throw new RuntimeException('Nincs kiválasztott fájl.');

            $numNew = 0;
            for ($i=0; $i<count($files['name']); $i++) {
                if ($files['error'][$i] !== UPLOAD_ERR_OK) continue; // átugorjuk a hibásakat
                if ($have + $numNew >= $max) break;

                $tmp = [
                    'name'     => $files['name'][$i],
                    'type'     => $files['type'][$i],
                    'tmp_name' => $files['tmp_name'][$i],
                    'error'    => $files['error'][$i],
                    'size'     => $files['size'][$i],
                ];
                $relPath = save_uploaded_image($tmp, $category, $projectId);

                // opcionális room/note
                $room = null; $note = null;
                if (!empty($CATS[$category]['room'])) $room = trim($_POST['room'] ?? '');
                if (!empty($CATS[$category]['note'])) $note = trim($_POST['note'] ?? '');

                insert_project_image($conn, $projectId, $category, $relPath, $room ?: null, $note ?: null);
                $numNew++;
            }

            if ($numNew === 0) {
                $errors[] = 'Nem történt feltöltés (elérted a kategória maximális darabszámát vagy hibás fájlok).';
            } else {
                $successMsg = "Feltöltve: {$numNew} kép.";
            }

            // státusz frissítés
            recompute_project_status($conn, $projectId);

        } elseif ($action === 'delete') {
            $imgId = (int)($_POST['image_id'] ?? 0);
            if ($imgId <= 0) throw new RuntimeException('Hiányzó image_id.');
            delete_image($conn, $imgId, $projectId);
            $successMsg = 'Kép törölve.';

            recompute_project_status($conn, $projectId);

        } else {
            throw new RuntimeException('Ismeretlen művelet.');
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

// Képek betöltése és kategorizálása
$all = image_rows($conn, $projectId);
$byCat = [];
foreach ($all as $r) {
    $byCat[$r['category']][] = $r;
}
?>
<!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <title>Képfeltöltés – Projekt #<?=htmlspecialchars($projectId)?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="<?=ASSETS_URL?>/bootstrap/bootstrap.min.css">
  <style>
    .img-thumb{height:110px;object-fit:cover;border:1px solid #ddd;border-radius:.35rem;}
    .img-grid{display:grid;grid-template-columns:repeat( auto-fill, minmax(140px,1fr) );gap:.75rem;}
    .form-section{padding:1rem;border:1px solid #eee;border-radius:.5rem;margin-bottom:1rem;}
    .muted{color:#6c757d;}
    .cat-header{display:flex;align-items:center;justify-content:space-between;gap:1rem;}
  </style>
</head>
<body class="container py-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="mb-0">Képfeltöltés <small class="text-muted">– Projekt #<?=htmlspecialchars($projectId)?></small></h1>
    <div class="btn-group">
      <a class="btn btn-outline-secondary" href="<?=BASE_URL?>/felmero/new_heater.php?project_id=<?=$projectId?>">← Új hőtermelő</a>
      <a class="btn btn-outline-secondary" href="<?=BASE_URL?>/felmero/dashboard.php">Projektlista</a>
    </div>
  </div>

  <?php if ($successMsg): ?>
    <div class="alert alert-success"><?=$successMsg?></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e): ?><li><?=htmlspecialchars($e)?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <?php foreach ($CATS as $key => $meta):
        $list  = $byCat[$key] ?? [];
        $count = count($list);
        $max   = $meta['max'];
        $min   = $meta['min'];
        $need  = max(0, $min - $count);
        $full  = ($count >= $max);
  ?>
  <div class="form-section">
    <div class="cat-header">
      <h5 class="mb-0"><?=$meta['label']?></h5>
      <div class="muted">Darabszám: <strong><?=$count?></strong> / <?=$max?><?= $min>1 ? " (min. {$min})" : "" ?></div>
    </div>

    <?php if ($list): ?>
      <div class="img-grid my-3">
        <?php foreach ($list as $img):
            $rel = $img['image_path'] ?? '';
            $url = $rel ? rel_url($rel) : '';
            if (!$url && !empty($img['file_name'])) {
                // régi séma fallback: korábbi hely
                $url = rel_url("uploads/projects/{$projectId}/".basename($img['file_name']));
            }
        ?>
        <div class="text-center">
          <?php if ($url): ?>
            <a href="<?=$url?>" target="_blank" title="Megnyitás új lapon">
              <img src="<?=$url?>" class="img-thumb" alt="">
            </a>
          <?php else: ?>
            <div class="img-thumb d-flex align-items-center justify-content-center">?</div>
          <?php endif; ?>
          <?php if (!empty($img['room'])): ?>
            <div class="small mt-1"><span class="muted">Helyiség:</span> <?=htmlspecialchars($img['room'])?></div>
          <?php endif; ?>
          <?php if (!empty($img['note'])): ?>
            <div class="small"><span class="muted">Megj.:</span> <?=htmlspecialchars($img['note'])?></div>
          <?php endif; ?>
          <form method="post" class="mt-2" onsubmit="return confirm('Biztos törlöd a képet?');">
            <?=csrf_input()?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="image_id" value="<?=$img['id']?>">
            <button class="btn btn-sm btn-outline-danger">Törlés</button>
          </form>
        </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="muted my-2">Még nincs kép ezen a kategórián.</p>
    <?php endif; ?>

    <hr>

    <form method="post" enctype="multipart/form-data" class="row g-3">
      <?=csrf_input()?>
      <input type="hidden" name="action" value="upload">
      <input type="hidden" name="category" value="<?=$key?>">

      <?php if (!empty($meta['room'])): ?>
        <div class="col-md-4">
          <label class="form-label">Helyiség megnevezése (opcionális)</label>
          <input class="form-control" name="room" placeholder="pl. nappali">
        </div>
      <?php endif; ?>
      <?php if (!empty($meta['note'])): ?>
        <div class="col-md-4">
          <label class="form-label">Megjegyzés (opcionális)</label>
          <input class="form-control" name="note" placeholder="pl. déli homlokzat">
        </div>
      <?php endif; ?>

      <div class="<?= (!empty($meta['room']) || !empty($meta['note'])) ? 'col-md-4' : 'col-md-6' ?>">
        <label class="form-label">Kiválasztás (JPG/PNG, több is jelölhető)<?= $full ? ' – elérted a maximumot' : '' ?></label>
        <input class="form-control" type="file" name="images[]" accept=".jpg,.jpeg,.png" multiple <?= $full ? 'disabled' : '' ?>>
        <?php if ($need>0): ?><div class="form-text text-danger">Hiányzik még legalább <?=$need?> kép.</div><?php endif; ?>
      </div>
      <div class="<?= (!empty($meta['room']) || !empty($meta['note'])) ? 'col-md-4' : 'col-md-6' ?> d-flex align-items-end">
        <button class="btn btn-primary" <?= $full ? 'disabled' : '' ?>>Feltöltés</button>
      </div>
    </form>
  </div>
  <?php endforeach; ?>

  <div class="alert alert-info mt-4">
    A projekt státusz automatikusan frissül minden feltöltés és törlés után. A „kész” állapothoz a minimális képdarabszámoknak kategóriánként teljesülniük kell.
  </div>
</body>
</html>
