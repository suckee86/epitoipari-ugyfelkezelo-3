<?php
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

require_once '../includes/db.php';
require_once '../vendor/autoload.php';

use PhpOffice\PhpWord\TemplateProcessor;

// Legfrissebb aktív sablonok betöltése
$stmt = $conn->prepare("SELECT * FROM templates WHERE active = 1");
$stmt->execute();
$templates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Teszt értékek (ezek később felhasználói inputból jöhetnek)
$values = [
    'projekt_nev' => 'Minta projekt',
    'datum' => date('Y.m.d'),
    'felmero_nev' => 'Kiss Péter',
    'kivitelezo_nev' => 'Szabó Kft.',
    'alairo1' => '../signatures/admin.png',
    'alairo2' => '../signatures/kivitelezo1.png',
    'check_mezo1' => true,
    'check_mezo2' => false
];

$output = null;
if (isset($_GET['type'])) {
    $type = $_GET['type'];
    $tpl = array_filter($templates, fn($t) => $t['type'] === $type);
    if ($tpl) {
        $template = array_values($tpl)[0];
        $doc = new TemplateProcessor("../templates/{$template['filename']}");

        foreach ($values as $key => $val) {
            if (is_bool($val)) {
                $doc->setValue($key, $val ? '☒' : '☐');
            } elseif (str_ends_with($key, 'alairo1') || str_ends_with($key, 'alairo2')) {
                $doc->setImageValue($key, ['path' => $val, 'width' => 100, 'height' => 50]);
            } else {
                $doc->setValue($key, $val);
            }
        }

        $outFile = '../generated/test_' . uniqid() . '.docx';
        $doc->saveAs($outFile);
        $output = $outFile;
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
    <title>Űrlap teszt generálás</title></head>
<body>
<div class="container">
    <h1>Űrlap teszt generálása</h1>

    <ul>
        <?php foreach ($templates as $tpl): ?>
            <li>
                <?= htmlspecialchars($tpl['name']) ?>
                (<a href="form_test.php?type=<?= $tpl['type'] ?>">Generálás</a>)
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if ($output): ?>
        <p>Generált fájl: <a href="<?= $output ?>" download>Kattints ide a letöltéshez</a></p>
    <?php endif; ?>

    <p><a href="dashboard.php">Vissza a főoldalra</a></p>
</div>
</body>
</html>
