<?php
if (defined('PUBLIC_ENTRY') && PUBLIC_ENTRY === true) {
    return; // ne ellenőrizzen
}

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
//session_start();

if (!isset($_SESSION['user'])) {
    header("Location: ../index.php");
    exit();
}
