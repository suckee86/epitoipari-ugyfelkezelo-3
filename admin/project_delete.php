<?php
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

require_once '../includes/db.php';

if (!isset($_GET['id'])) {
    header("Location: projects.php");
    exit();
}

$id = (int) $_GET['id'];

// Ellenőrizd, hogy létezik-e a projekt
$stmt = $conn->prepare("SELECT id FROM projects WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if (!$result->fetch_assoc()) {
    echo "Projekt nem található.";
    exit();
}

// Törlés
$stmt = $conn->prepare("DELETE FROM projects WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();

header("Location: projects.php");
exit();
