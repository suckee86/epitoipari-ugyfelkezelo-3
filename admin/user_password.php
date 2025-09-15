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
$selfEdit = ($_SESSION['user']['id'] === $id);

$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if (!$user = $result->fetch_assoc()) {
    echo "Felhasználó nem található.";
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $new_password = trim($_POST['new_password']);
    $confirm_password = trim($_POST['confirm_password']);

    if ($new_password === $confirm_password && strlen($new_password) >= 6) {
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->bind_param("si", $hashed, $id);
        $stmt->execute();
        $_SESSION['message'] = "A jelszót sikeresen módosítottad.";
        header("Location: users.php");
        exit();
    } else {
        $error = "A jelszavak nem egyeznek, vagy túl rövidek (min. 6 karakter).";
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
    <title>Jelszó módosítása</title></head>
<body>
<div class="container">
    <h1>Jelszó módosítása: <?= $selfEdit ? '<em>Saját fiók</em>' : htmlspecialchars($user['name']) ?></h1>

    <?php if (isset($error)) echo "<p style='color:red;'>$error</p>"; ?>

    <form method="post">
        <label>Új jelszó:<br>
            <input type="password" name="new_password" required>
        </label><br>
        <label>Új jelszó megerősítése:<br>
            <input type="password" name="confirm_password" required>
        </label><br>
        <button type="submit" name="change_password">Jelszó módosítása</button>
    </form>

    <p><a href="users.php">Vissza a listához</a></p>
</div>
</body>
</html>