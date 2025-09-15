<?php
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

require_once '../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $type = trim($_POST['type']);
    $file = $_FILES['template_file'] ?? null;

    if ($file && $file['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        if (strtolower($ext) !== 'docx') {
            $error = "Csak .docx fájl tölthető fel.";
        } else {
            $newFileName = uniqid() . ".docx";
            move_uploaded_file($file['tmp_name'], "../templates/" . $newFileName);

            // Inaktiválja az eddigi sablonokat az adott típusra
            $stmt = $conn->prepare("UPDATE templates SET active = 0 WHERE type = ?");
            $stmt->bind_param("s", $type);
            $stmt->execute();

            // Új sablon beszúrása
            $stmt = $conn->prepare("INSERT INTO templates (name, filename, type, active) VALUES (?, ?, ?, 1)");
            $stmt->bind_param("sss", $name, $newFileName, $type);
            $stmt->execute();

            $_SESSION['message'] = "Sablon sikeresen feltöltve.";
            header("Location: templates.php");
            exit();
        }
    } else {
        $error = "Hiba történt a fájl feltöltésekor.";
    }
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <link rel="stylesheet" href="/epito3/assets/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="/epito3/assets/css/style.css">
    <meta name="viewport" content="width=device-width, initial-scale=1">
<meta charset="UTF-8">
    <title>Új sablon feltöltése</title></head>
<body>
<div class="container">
    <h1>Új sablon feltöltése</h1>

    <?php if (isset($error)) echo "<p style='color:red;'>$error</p>"; ?>

    <form method="post" enctype="multipart/form-data">
        <label>Sablon megnevezése:<br>
            <input type="text" name="name" required>
        </label><br>
        <label>Sablon típusa:<br>
            <select name="type" required>
                <option value="megallapodas">Megállapodás</option>
                <option value="arajanlat">Árajánlat</option>
            </select>
        </label><br>
        <label>.docx fájl feltöltése:<br>
            <input type="file" name="template_file" accept=".docx" required>
        </label><br>
        <button type="submit">Feltöltés</button>
    </form>

    <p><a href="templates.php">Vissza a sablonokhoz</a></p>
</div>
</body>
</html>
