<?php
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

require_once '../includes/db.php';

if (!isset($_GET['id'])) {
    header("Location: users.php");
    exit();
}

$id = (int) $_GET['id'];

// Ne törölhesse magát az admin véletlenül
if ($_SESSION['user']['id'] == $id) {
    echo "Nem törölheted saját magadat.";
    exit();
}

// Ellenőrizd, hogy létezik-e a felhasználó
$stmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if (!$result->fetch_assoc()) {
    echo "Felhasználó nem található.";
    exit();
}

// Törlés
$stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();

header("Location: users.php");
exit();