<?php
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'kivitelezo') {
    header("Location: ../index.php");
    exit();
}

require_once '../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $project_id = (int) $_POST['project_id'];
    $new_status = trim($_POST['status']);

    $stmt = $conn->prepare("UPDATE projects SET status = ? WHERE id = ? AND kivitelezo_id = ?");
    $stmt->bind_param("sii", $new_status, $project_id, $_SESSION['user']['id']);
    $stmt->execute();

    header("Location: dashboard_kivitelezo.php");
    exit();
}

// Ha nem POST-tal érkezett
header("Location: dashboard_kivitelezo.php");
exit();
