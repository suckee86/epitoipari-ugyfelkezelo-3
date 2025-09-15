<?php
// felmero/dashboard.php — Projektek listája a felmérőnek

require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/csrf.php';

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

// segédfüggvény
function table_exists(mysqli $conn, string $table): bool {
    $sql = "SELECT COUNT(*) AS c
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?";
    $st = $conn->prepare($sql);
    if (!$st) return false;
    $st->bind_param('s', $table);
    $st->execute();
    $res = $st->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $st->close();
    return !empty($row) && (int)$row['c'] > 0;
}

$uid = current_user_id();

// dinamikus al-lekérdezések
$subs = [];
$subs[] = table_exists($conn,'contracting_parties')
    ? "(SELECT COUNT(*) FROM contracting_parties cp WHERE cp.project_id=p.id) AS has_contractor"
    : "0 AS has_contractor";
$subs[] = table_exists($conn,'project_owners')
    ? "(SELECT COUNT(*) FROM project_owners po WHERE po.project_id=p.id) AS owners_count"
    : "0 AS owners_count";
$subs[] = table_exists($conn,'project_buildings')
    ? "(SELECT COUNT(*) FROM project_buildings pb WHERE pb.project_id=p.id) AS has_building"
    : "0 AS has_building";
$subs[] = table_exists($conn,'project_new_heaters')
    ? "(SELECT COUNT(*) FROM project_new_heaters nh WHERE nh.project_id=p.id) AS has_heater"
    : "0 AS has_heater";
$subs[] = table_exists($conn,'project_images')
    ? "(SELECT COUNT(*) FROM project_images pi WHERE pi.project_id=p.id) AS images_count"
    : "0 AS images_count";

// ÚJ: munkatípusok jelenléte
if (table_exists($conn,'project_work_types')) {
    $subs[] = "(SELECT COUNT(*) FROM project_work_types wt WHERE wt.project_id=p.id AND wt.type_code='padlasfodem_szigeteles') AS wt_padlas";
    $subs[] = "(SELECT COUNT(*) FROM project_work_types wt WHERE wt.project_id=p.id AND wt.type_code='futes_korszerusites') AS wt_futes";
    $subs[] = "(SELECT COUNT(*) FROM project_work_types wt WHERE wt.project_id=p.id AND wt.type_code='nyilaszaro_csere') AS wt_nyil";
    $subs[] = "(SELECT COUNT(*) FROM project_work_types wt WHERE wt.project_id=p.id AND wt.type_code='homlokzati_hoszigeteles') AS wt_homlok";
} else {
    $subs[] = "0 AS wt_padlas, 0 AS wt_futes, 0 AS wt_nyil, 0 AS wt_homlok";
}

$select = "
SELECT
  p.id, p.project_name, p.address, p.cadastral_number, p.status,
  p.req_a_csaladi_haz, p.req_b_kiv_reg_szam, p.req_d_szigeteltsg_kikotes,
  p.req_e_kovetelmeny_ok, p.req_f_ketreteg, p.req_g_parareteg,
  ".implode(",\n  ", $subs).",
  p.created_at
FROM projects p
WHERE p.felmero_id = ?
ORDER BY p.id DESC";

$st = $conn->prepare($select);
if (!$st) { http_response_code(500); exit('Adatbázis hiba: '.$conn->error); }
$st->bind_param('i', $uid);
$st->execute();
$res = $st->get_result();
$rows = [];
while ($r = $res->fetch_assoc()) { $rows[] = $r; }
$st->close();

function badge($ok) {
    return $ok ? '<span class="badge text-bg-success">✓</span>' : '<span class="badge text-bg-danger">✗</span>';
}
?>
<!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <title>Projektlista – Felmérő</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="<?=ASSETS_URL?>/bootstrap/bootstrap.min.css">
  <style>
    .table-fit td, .table-fit th { vertical-align: middle; }
    .badges span { margin-right: .25rem; }
    .nowrap { white-space: nowrap; }
    .logout-form { display:inline-block; margin:0; }
    .btn-disabled { pointer-events:none; opacity:.5; }
  </style>
</head>
<body class="container py-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="mb-0">Projektlista</h1>
    <div class="d-flex align-items-center gap-2">
      <a class="btn btn-primary" href="<?=BASE_URL?>/felmero/create_project.php">+ Új projekt</a>
      <form class="logout-form" method="post" action="<?=BASE_URL?>/logout.php">
        <?=csrf_input()?>
        <button class="btn btn-outline-danger">Kilépés</button>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-striped table-hover table-fit mb-0">
          <thead class="table-light">
            <tr>
              <th>ID</th>
              <th>Projekt</th>
              <th>Cím / HRSZ</th>
              <th class="text-center">a–g</th>
              <th class="text-center">Lépések</th>
              <th>Állapot</th>
              <th class="text-end">Műveletek</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="7" class="text-center p-4 text-muted">Még nincs projekt. Katt a „+ Új projekt”-re.</td></tr>
          <?php else: foreach ($rows as $p): 
              $aok = (int)$p['req_a_csaladi_haz'] === 1;
              $bok = trim((string)$p['req_b_kiv_reg_szam']) !== '';
              $dok = (int)$p['req_d_szigeteltsg_kikotes'] === 1;
              $eok = (int)$p['req_e_kovetelmeny_ok'] === 1;
              $fok = (int)$p['req_f_ketreteg'] === 1;
              $gok = (int)$p['req_g_parareteg'] === 1;

              $has_contractor = (int)$p['has_contractor'] > 0;
              $owners_count   = (int)$p['owners_count'];
              $has_building   = (int)$p['has_building'] > 0;
              $has_heater     = (int)$p['has_heater'] > 0;
              $has_images     = (int)($p['images_count'] ?? 0) > 0;

              $wt_padlas = (int)($p['wt_padlas'] ?? 0) > 0;
              $wt_futes  = (int)($p['wt_futes']  ?? 0) > 0;
              $wt_nyil   = (int)($p['wt_nyil']   ?? 0) > 0;
              $wt_homlok = (int)($p['wt_homlok'] ?? 0) > 0;

              $pid = (int)$p['id'];
          ?>
            <tr>
              <td class="nowrap">#<?=$pid?></td>
              <td>
                <div class="fw-semibold"><?=htmlspecialchars($p['project_name'] ?: '—')?></div>
                <div class="text-muted small">Létrehozva: <?=htmlspecialchars($p['created_at'] ?? '')?></div>
              </td>
              <td>
                <div><?=htmlspecialchars($p['address'] ?: '—')?></div>
                <div class="text-muted small">HRSZ: <?=htmlspecialchars($p['cadastral_number'] ?: '—')?></div>
              </td>
              <td class="text-center badges">
                <span title="a) családi ház"><?=badge($aok)?></span>
                <span title="b) kiv. reg. szám"><?=badge($bok)?></span>
                <span title="d) szigeteletlen kiindulás"><?=badge($dok)?></span>
                <span title="e) követelmények"><?=badge($eok)?></span>
                <span title="f) 2 réteg"><?=badge($fok)?></span>
                <span title="g) pára-réteg/fólia"><?=badge($gok)?></span>
              </td>
              <td class="text-center">
                <div class="btn-group btn-group-sm" role="group">
                  <!-- ÚJ: Munkatípusok -->
                  <a class="btn btn-outline-primary <?= ($wt_padlas||$wt_futes||$wt_nyil||$wt_homlok)?'':'btn-warning' ?>"
                     href="<?=BASE_URL?>/felmero/work_types.php?project_id=<?=$pid?>">Munkatípusok</a>

                  <a class="btn btn-outline-primary <?= $has_contractor?'':'btn-warning' ?>"
                     href="<?=BASE_URL?>/felmero/contractor.php?project_id=<?=$pid?>">Szerződő</a>
                  <a class="btn btn-outline-primary <?= $owners_count>0?'':'btn-warning' ?>"
                     href="<?=BASE_URL?>/felmero/owners.php?project_id=<?=$pid?>">Tulaj (<?=$owners_count?>)</a>
                  <a class="btn btn-outline-primary <?= $has_building?'':'btn-warning' ?>"
                     href="<?=BASE_URL?>/felmero/building.php?project_id=<?=$pid?>">Épület</a>

                  <!-- Új hőtermelő csak ha kiválasztott a “Fűtés korszerűsítés” -->
                  <a class="btn btn-outline-primary <?= $wt_futes ? ($has_heater?'':'btn-warning') : 'btn-disabled' ?>"
                     title="<?= $wt_futes ? 'Új hőtermelő megadása' : 'Nincs kiválasztva: Fűtés korszerűsítés' ?>"
                     href="<?= $wt_futes ? BASE_URL.'/felmero/new_heater.php?project_id='.$pid : '#' ?>">Új hőtermelő</a>

                  <a class="btn btn-outline-primary <?= $has_images?'':'btn-warning' ?>"
                     href="<?=BASE_URL?>/felmero/photos.php?project_id=<?=$pid?>">Képek</a>
                </div>
                <?php if ($wt_nyil || $wt_homlok): ?>
                  <div class="small text-muted mt-1">
                    Következő sprint: űrlapok a: <?= $wt_nyil?'Nyílászáró csere':'' ?><?= ($wt_nyil && $wt_homlok)?', ':'' ?><?= $wt_homlok?'Homlokzati hőszigetelés':'' ?> típus(ok)hoz.
                  </div>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge text-bg-<?= ($p['status'] ?? '') ? 'success' : 'secondary' ?>">
                  <?= htmlspecialchars($p['status'] ?: 'vázlat') ?>
                </span>
              </td>
              <td class="text-end">
                <a class="btn btn-sm btn-outline-secondary"
                   href="<?=BASE_URL?>/felmero/contractor.php?project_id=<?=$pid?>">Folytatás →</a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</body>
</html>
