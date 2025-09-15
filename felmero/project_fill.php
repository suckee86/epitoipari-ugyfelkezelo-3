<?php
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'felmero') {
    header("Location: ../index.php");
    exit();
}

require_once '../includes/db.php';

if (!isset($_GET['id'])) {
    header("Location: project_list.php");
    exit();
}

$project_id = (int)$_GET['id'];
$user_id = $_SESSION['user']['id'];

// Ellenőrizze, hogy a felmérő jogosult a projekthez
$stmt = $conn->prepare("SELECT * FROM project_assignments WHERE project_id = ? AND user_id = ?");
$stmt->bind_param("ii", $project_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    echo "Ehhez a projekthez nincs hozzáférésed.";
    exit();
}

// Űrlap feldolgozása
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit'])) {
    $field1 = trim($_POST['field1']);
    $field2 = trim($_POST['field2']);
    $checkbox1 = isset($_POST['checkbox1']) ? 1 : 0;
    $signature_path = '';

    // Aláírás mentése PNG formában
    if (!empty($_POST['signature_data'])) {
        $signature_data = $_POST['signature_data'];
        $signature_data = str_replace('data:image/png;base64,', '', $signature_data);
        $signature_data = base64_decode($signature_data);
        $signature_path = '../uploads/signatures/sign_' . $user_id . '_' . time() . '.png';
        file_put_contents($signature_path, $signature_data);
    }

    // Mentés adatbázisba (vagy ideiglenes mentéshez további feldolgozás)
    // ...

    echo "Űrlap sikeresen elmentve.";
    exit();
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <link rel="stylesheet" href="/epito3/assets/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="/epito3/assets/css/style.css">
    <meta name="viewport" content="width=device-width, initial-scale=1">
<meta charset="UTF-8">
    <title>Űrlap kitöltése</title><script>
    function saveSignature() {
        const canvas = document.getElementById('sign-pad');
        const dataUrl = canvas.toDataURL();
        document.getElementById('signature_data').value = dataUrl;
    }
    </script>
</head>
<body>
<div class="container">
    <h1>Űrlap kitöltése – Projekt ID: <?= $project_id ?></h1>
    <form method="post" onsubmit="saveSignature()">
        <label>Mező 1:<br><input type="text" name="field1" required class="form-control"></label><br>
        <label>Mező 2:<br><input type="text" name="field2" required class="form-control"></label><br>
        <label><input type="checkbox" name="checkbox1"> Jelölőnégyzet 1</label><br>
        <p>Aláírás:</p>
        <canvas id="sign-pad" width="300" height="100" style="border:1px solid #ccc;"></canvas><br>
        <input type="hidden" name="signature_data" id="signature_data">
        <button type="submit" name="submit" class="btn btn-dark w-100">Mentés</button>
    </form>
    <p><a href="project_list.php">Vissza a projektekhez</a></p>
</div>
<script>
// Egyszerű rajzoló
let canvas = document.getElementById('sign-pad');
let ctx = canvas.getContext('2d');
let drawing = false;
canvas.addEventListener('mousedown', () => drawing = true);
canvas.addEventListener('mouseup', () => drawing = false);
canvas.addEventListener('mousemove', (e) => {
    if (!drawing) return;
    const rect = canvas.getBoundingClientRect();
    ctx.lineTo(e.clientX - rect.left, e.clientY - rect.top);
    ctx.stroke();
});
</script>
</body>
</html>
