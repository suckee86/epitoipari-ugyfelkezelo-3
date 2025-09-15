<?php
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'ellenorzo') {
    header("Location: ../index.php");
    exit();
}

require_once '../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $project_id = (int) $_POST['project_id'];
    $review = $_POST['review'];
    $comment = trim($_POST['comment'] ?? '');

    if (in_array($review, ['elfogadva', 'elutasitva'])) {
        $stmt = $conn->prepare("UPDATE projects SET ellenorzo_status = ?, ellenorzo_comment = ? WHERE id = ?");
        $stmt->bind_param("ssi", $review, $comment, $project_id);
        $stmt->execute();
    }

    header("Location: dashboard_ellenorzo.php");
    exit();
}

header("Location: dashboard_ellenorzo.php");
exit();
