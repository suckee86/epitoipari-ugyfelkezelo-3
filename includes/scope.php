<?php
// includes/scope.php — mysqli alapú scope guard és helper függvények felmérőkhöz

require_once __DIR__.'/config.php';
require_once __DIR__.'/db.php';     // $conn (mysqli)
require_once __DIR__.'/auth.php';   // saját auth-od (session/role)

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// --- SHIM-ek: ha az auth-odban hiányoznak ezek, pótoljuk őket ---
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
// ------------------------------------------------------------------

/**
 * Ellenőrzi, hogy a bejelentkezett felmérő hozzáfér-e a megadott projekthez.
 * 403-at dob, ha nem.
 */
function assert_felmero_scope_on_project(int $projectId): void {
    global $conn;
    $uid = current_user_id();

    if ($projectId <= 0 || $uid <= 0) {
        http_response_code(403);
        exit('Hozzáférés megtagadva.');
    }
    $st = $conn->prepare("SELECT id FROM projects WHERE id=? AND felmero_id=? LIMIT 1");
    if (!$st) { http_response_code(500); exit('DB hiba (prepare).'); }
    $st->bind_param('ii', $projectId, $uid);
    $st->execute();
    $st->store_result();
    if ($st->num_rows === 0) {
        http_response_code(403);
        exit('Hozzáférés megtagadva.');
    }
    $st->close();
}

/**
 * Helper: adott tulaj (owner_id) tényleg az adott projekthez tartozik-e?
 */
function owner_belongs_to_project(int $ownerId, int $projectId): bool {
    global $conn;
    $st = $conn->prepare("SELECT 1 FROM project_owners WHERE id=? AND project_id=?");
    if (!$st) return false;
    $st->bind_param('ii', $ownerId, $projectId);
    $st->execute();
    $st->store_result();
    $ok = ($st->num_rows > 0);
    $st->close();
    return $ok;
}
?>