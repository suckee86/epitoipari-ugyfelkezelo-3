<?php
// logout.php — biztonságos kijelentkezés, majd vissza az indexre

require_once __DIR__.'/includes/config.php';
require_once __DIR__.'/includes/csrf.php'; // session + CSRF

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // csak POST-ot fogadunk
    http_response_code(405);
    exit('Hibás kérés.');
}

try {
    csrf_check($_POST['csrf'] ?? '');
} catch (Throwable $e) {
    http_response_code(400);
    exit('Érvénytelen kérés.');
}

// Minden session adat törlése
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();

// Vissza a login oldalra
header('Location: ' . BASE_URL . '/index.php');
exit;
