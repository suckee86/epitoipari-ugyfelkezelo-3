<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';

if ($_SESSION['user']['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

$roles = ['felmero', 'kivitelezo', 'ellenorzo', 'auditor', 'admin'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $role = $_POST['role'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $active = isset($_POST['active']) ? 1 : 0;

    $stmt = $conn->prepare("INSERT INTO users (name, email, role, password, active) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssi", $name, $email, $role, $password, $active);
    $stmt->execute();

    header("Location: users.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="hu">
\1
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta charset="UTF-8">
    <title>Új felhasználó</title></head>
<body>
    <h2>Új felhasználó létrehozása</h2>
    <form method="POST">
        <input type="text" name="name" placeholder="Név" required><br>
        <input type="email" name="email" placeholder="Email" required><br>
        <input type="password" name="password" placeholder="Jelszó" required><br>
        <label>Szerepkör:
            <select name="role" required>
                <?php foreach ($roles as $r): ?>
                    <option value="<?= $r ?>"><?= ucfirst($r) ?></option>
                <?php endforeach; ?>
            </select>
        </label><br>
        <label><input type="checkbox" name="active" checked> Aktív</label><br>
        <button type="submit">Létrehozás</button>
    </form>
</body>
</html>
