<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_input(): string {
    return '<input type="hidden" name="csrf" value="'.htmlspecialchars(csrf_token()).'">';
}

function csrf_check(string $token): void {
    $ok = hash_equals($_SESSION['csrf'] ?? '', $token ?? '');
    if (!$ok) {
        http_response_code(419);
        exit('CSRF hiba.');
    }
}
