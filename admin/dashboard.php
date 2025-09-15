<?php
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: ../index.php");
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
    <title>Admin Vezérlőpult</title></head>
<body>
    <div class="container">
        <h1>Üdvözlünk, <?php echo htmlspecialchars($_SESSION['user']['name']); ?>!</h1>
        <h2>Admin vezérlőpult</h2>
        <ul>
            <li><a href="users.php">Felhasználók kezelése</a></li>
            <li><a href="projects.php">Projektek kezelése</a></li>
            <li><a href="templates.php">Sablonok kezelése</a></li>
            <li><a href="../form_select.php">Űrlap beküldés (teszt)</a></li>
            <li><a href="../logout.php">Kijelentkezés</a></li>
        </ul>
    </div>
</body>
</html>
