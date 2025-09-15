<?php
// includes/project_status.php — Projekt státusz automatikus újraszámolása (robosztus verzió)

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

function column_exists(mysqli $conn, string $table, string $column): bool {
    $sql = "SELECT COUNT(*) AS c
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?";
    $st = $conn->prepare($sql);
    if (!$st) return false;
    $st->bind_param('ss', $table, $column);
    $st->execute();
    $res = $st->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $st->close();
    return !empty($row) && (int)$row['c'] > 0;
}

/**
 * Számolja és beírja a projekt státuszát.
 * Visszatér: 'kész' vagy 'draft'
 */
function recompute_project_status(mysqli $conn, int $projectId): string {
    // --- a–g feltételek (projects) ---
    $a = $d = $e = $f = $g = 0; $btxt = '';
    $st = $conn->prepare("SELECT req_a_csaladi_haz, req_b_kiv_reg_szam, req_d_szigeteltsg_kikotes,
                                 req_e_kovetelmeny_ok, req_f_ketreteg, req_g_parareteg
                          FROM projects WHERE id=?");
    $st->bind_param('i', $projectId);
    $st->execute();
    $res = $st->get_result();
    if ($row = $res->fetch_assoc()) {
        $a    = (int)($row['req_a_csaladi_haz'] ?? 0);
        $btxt = (string)($row['req_b_kiv_reg_szam'] ?? '');
        $d    = (int)($row['req_d_szigeteltsg_kikotes'] ?? 0);
        $e    = (int)($row['req_e_kovetelmeny_ok'] ?? 0);
        $f    = (int)($row['req_f_ketreteg'] ?? 0);
        $g    = (int)($row['req_g_parareteg'] ?? 0);
    }
    $st->close();
    $ag_ok = ($a === 1) && ($btxt !== '') && ($d === 1) && ($e === 1) && ($f === 1) && ($g === 1);

    // --- Szerződő + aláírás ---
    $cp_ok = false;
    if (table_exists($conn, 'contracting_parties')) {
        $sigConds = [];
        if (column_exists($conn, 'contracting_parties', 'signature_path')) {
            $sigConds[] = "COALESCE(signature_path,'')<>''";
        }
        if (column_exists($conn, 'contracting_parties', 'signature')) {
            $sigConds[] = "signature IS NOT NULL";
        }

        if ($sigConds) {
            $sql = "SELECT COUNT(*) AS c FROM contracting_parties WHERE project_id=? AND ("
                 . implode(' OR ', $sigConds) . ")";
            $st = $conn->prepare($sql);
            $st->bind_param('i', $projectId);
            $st->execute();
            $r = $st->get_result()->fetch_assoc();
            $st->close();
            $cp_ok = !empty($r) && (int)$r['c'] > 0;
        } elseif (table_exists($conn, 'project_signatures')) {
            // Fallback: napló-tábla (ha a contracting_parties nem tárolja az aláírást)
            $st = $conn->prepare("SELECT COUNT(*) AS c FROM project_signatures
                                  WHERE project_id=? AND type IN ('szerzodo','contractor')");
            $st->bind_param('i', $projectId);
            $st->execute(); $r = $st->get_result()->fetch_assoc(); $st->close();
            $cp_ok = !empty($r) && (int)$r['c'] > 0;
        }
    }

    // --- Tulajdonosok: legalább 1, mind aláírással ---
    $owners_ok = false;
    if (table_exists($conn, 'project_owners')) {
        $hasPath = column_exists($conn, 'project_owners', 'signature_path');
        $hasBlob = column_exists($conn, 'project_owners', 'signature');
        if ($hasPath || $hasBlob) {
            $conds = [];
            if ($hasPath) $conds[] = "COALESCE(signature_path,'')<>''";
            if ($hasBlob) $conds[] = "signature IS NOT NULL";
            $sql = "SELECT COUNT(*) AS total,
                           SUM((".implode(' OR ', $conds).")) AS signed
                    FROM project_owners WHERE project_id=?";
            $st = $conn->prepare($sql);
            $st->bind_param('i', $projectId);
            $st->execute(); $r = $st->get_result()->fetch_assoc(); $st->close();
            $total  = (int)($r['total']  ?? 0);
            $signed = (int)($r['signed'] ?? 0);
            $owners_ok = ($total >= 1) && ($signed === $total);
        } elseif (table_exists($conn, 'project_signatures')) {
            // Fallback: ha az owners tábla nem tárol aláírást oszlopban
            $st = $conn->prepare("SELECT COUNT(DISTINCT owner_index) AS owners,
                                         COUNT(*) AS sigs
                                  FROM project_signatures
                                  WHERE project_id=? AND type='tulajdonos'");
            $st->bind_param('i', $projectId);
            $st->execute(); $r = $st->get_result()->fetch_assoc(); $st->close();
            $owners = (int)($r['owners'] ?? 0);
            $sigs   = (int)($r['sigs']   ?? 0);
            // minimális logika: ha van legalább 1 tulaj és mindnek van bejegyzése
            $owners_ok = ($owners >= 1) && ($sigs >= $owners);
        }
    }

    // --- Épület lap ---
    $building_ok = false;
    if (table_exists($conn, 'project_buildings')) {
        $st = $conn->prepare("SELECT COUNT(*) AS c FROM project_buildings WHERE project_id=?");
        $st->bind_param('i', $projectId);
        $st->execute(); $r = $st->get_result()->fetch_assoc(); $st->close();
        $building_ok = !empty($r) && (int)$r['c'] > 0;
    }

    // --- Új hőtermelő ---
    $heater_ok = false;
    if (table_exists($conn, 'project_new_heaters')) {
        $st = $conn->prepare("SELECT COUNT(*) AS c FROM project_new_heaters WHERE project_id=?");
        $st->bind_param('i', $projectId);
        $st->execute(); $r = $st->get_result()->fetch_assoc(); $st->close();
        $heater_ok = !empty($r) && (int)$r['c'] > 0;
    }

    // --- Fotók (kategóriák szerinti minimumok) ---
    $REQUIRED_IMAGES = [
        'exterior_streetno' => 1, // Ingatlan kívülről – utcatábla + házszám
        'side_view'         => 1, // Oldalnézet
        'facades'           => 1, // Homlokzat(ok)
        'heating_emitters'  => 1, // Hőtermelő/hőleadók
        'attic'             => 2, // Padlás
        'floorplan'         => 1, // Alaprajz
        'idcard_front'      => 1, // Lakcímkártya eleje
        'idcard_back'       => 1, // Lakcímkártya hátulja
    ];
    $images_ok = false;
    if (table_exists($conn, 'project_images')) {
        if (column_exists($conn, 'project_images', 'category')) {
            $st = $conn->prepare("SELECT category, COUNT(*) AS c
                                  FROM project_images WHERE project_id=? GROUP BY category");
            $st->bind_param('i', $projectId);
            $st->execute();
            $map = [];
            $res = $st->get_result();
            while ($row = $res->fetch_assoc()) {
                $map[(string)$row['category']] = (int)$row['c'];
            }
            $st->close();
            $images_ok = true;
            foreach ($REQUIRED_IMAGES as $cat => $min) {
                $have = $map[$cat] ?? 0;
                if ($have < $min) { $images_ok = false; break; }
            }
        } else {
            // Átmeneti fallback: ha nincs kategória oszlop, elég legalább 1 kép
            $st = $conn->prepare("SELECT COUNT(*) AS c FROM project_images WHERE project_id=?");
            $st->bind_param('i', $projectId);
            $st->execute(); $r = $st->get_result()->fetch_assoc(); $st->close();
            $images_ok = !empty($r) && (int)$r['c'] >= 1;
        }
    }

    // --- Összesítés + státusz beírása ---
    $all_ok = $ag_ok && $cp_ok && $owners_ok && $building_ok && $heater_ok && $images_ok;
    $status = $all_ok ? 'kész' : 'draft';

    $upd = $conn->prepare("UPDATE projects SET status=? WHERE id=?");
    $upd->bind_param('si', $status, $projectId);
    $upd->execute();
    $upd->close();

    return $status;
}
