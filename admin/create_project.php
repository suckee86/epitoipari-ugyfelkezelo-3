<?php
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

require_once '../includes/db.php';

function generate_project_code($client_name, $type_text) {
    $monogram = strtoupper(substr(str_replace([' ', '-'], '', $client_name), 0, 3));
    $type_abbrev = strtoupper(iconv('UTF-8', 'ASCII//TRANSLIT', substr($type_text, 0, 3)));
    $date_part = date('Ymd');
    return "{$date_part}-{$monogram}-{$type_abbrev}";
}

$project_types = [
    "Padlásfödém szigetelés",
    "Fűtéskorszerűsítés",
    "Nyílászáró csere",
    "Homlokzat szigetelés"
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $project_name = trim($_POST['project_name']);
    $client_name = trim($_POST['client_name']);
    $address = trim($_POST['address']);
    $template_type = trim($_POST['template_type']);
    $project_type = trim($_POST['project_type']);
    $created_by = $_SESSION['user']['id'];

    if ($project_name && $client_name && $address && $template_type && in_array($project_type, $project_types)) {
        $project_code = generate_project_code($client_name, $project_type);
        $stmt = $conn->prepare("INSERT INTO projects (project_name, client_name, address, template_type, project_type, project_code, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssi", $project_name, $client_name, $address, $template_type, $project_type, $project_code, $created_by);
        $stmt->execute();
        $_SESSION['message'] = "Projekt létrehozva. Azonosító: $project_code";
        header("Location: projects.php");
        exit();
    } else {
        $error = "Kérlek, tölts ki minden mezőt helyesen.";
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
    <title>Projekt létrehozása</title></head>
<body>
<div class="container">
    <h1>Új projekt létrehozása</h1>

    <?php if (isset($error)) echo "<p style='color:red;'>$error</p>"; ?>

    <form method="post">
        <label>Projekt neve:<br>
            <input type="text" name="project_name" required>
        </label><br>
        <label>Ügyfél neve:<br>
            <input type="text" name="client_name" required>
        </label><br>
        <label>Cím:<br>
            <input type="text" name="address" required>
        </label><br>
        <label>Űrlap típusa:<br>
            <select name="template_type" required>
                <option value="">-- Válassz --</option>
                <option value="arajanlat">Árajánlat</option>
                <option value="megallapodas">Megállapodás</option>
            </select>
        </label><br>
        <label>Munka típusa:<br>
            <select name="project_type" required>
                <option value="">-- Válassz --</option>
                <?php foreach ($project_types as $type): ?>
                    <option value="<?= $type ?>"><?= $type ?></option>
                <?php endforeach; ?>
            </select>
        </label><br><br>
        <button type="submit">Projekt létrehozása</button>
    </form>

    <p><a href="projects.php">Vissza a projektekhez</a></p>
</div>
</body>
</html>
