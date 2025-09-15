<?php
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'felmero') {
    header("Location: ../index.php");
    exit();
}

require_once '../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $project_id = (int) $_POST['project_id'];
    $user_id = $_SESSION['user']['id'];

    $field1 = trim($_POST['field1']);
    $field2 = trim($_POST['field2']);
    $checkbox1 = isset($_POST['checkbox1']) ? 1 : 0;
    $signature_path = '';

    if (!empty($_POST['signature_data'])) {
        $signature_data = $_POST['signature_data'];
        $signature_data = str_replace('data:image/png;base64,', '', $signature_data);
        $signature_data = base64_decode($signature_data);

        $signature_dir = '../uploads/signatures/';
        if (!is_dir($signature_dir)) mkdir($signature_dir, 0777, true);

        $signature_path = $signature_dir . 'sign_' . $user_id . '_' . time() . '.png';
        file_put_contents($signature_path, $signature_data);
    }

    $stmt = $conn->prepare("INSERT INTO form_data (project_id, user_id, field1, field2, checkbox1, signature_path, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
    $stmt->bind_param("iissis", $project_id, $user_id, $field1, $field2, $checkbox1, $signature_path);

    if ($stmt->execute()) {
        header("Location: project_list.php?success=1");
    } else {
        echo "Hiba történt az adatok mentésekor.";
    }
    exit();
} else {
    header("Location: project_list.php");
    exit();
}
