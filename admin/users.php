<?php
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

require_once '../includes/db.php';

// Új felhasználó beszúrása
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create'])) {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $role = $_POST['role'];
    $active = isset($_POST['active']) ? 1 : 0;

    $stmt = $conn->prepare("INSERT INTO users (name, email, password, role, active) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssi", $name, $email, $password, $role, $active);
    $stmt->execute();
    header("Location: users.php");
    exit();
}

// Felhasználók lekérdezése
$result = $conn->query("SELECT * FROM users ORDER BY id DESC");
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <link rel="stylesheet" href="/epito3/assets/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="/epito3/assets/css/style.css">
    <meta name="viewport" content="width=device-width, initial-scale=1">
<meta charset="UTF-8">
    <title>Felhasználókezelés</title></head>
<body>
<div class="container">
    <h1>Felhasználók kezelése</h1>
    <a href="dashboard.php">Vissza a vezérlőpulthoz</a>

    <h2>Új felhasználó létrehozása</h2>
    <form method="post">
        <label>Név: <input type="text" name="name" required></label><br>
        <label>Email: <input type="email" name="email" required></label><br>
        <label>Jelszó: <input type="password" name="password" required></label><br>
        <label>Szerepkör:
            <select name="role" required>
                <option value="felmero">Felmérő</option>
                <option value="kivitelezo">Kivitelező</option>
                <option value="ellenorzo">Ellenőrző</option>
                <option value="auditor">Auditor</option>
                <option value="admin">Admin</option>
            </select>
        </label><br>
        <label>Aktív: <input type="checkbox" name="active" checked></label><br>
        <button type="submit" name="create">Létrehozás</button>
    </form>

    <h2>Felhasználók listája</h2>
    <table border="1" cellpadding="5">
        <tr>
            <th>ID</th><th>Név</th><th>Email</th><th>Szerepkör</th><th>Aktív</th><th>Műveletek</th>
        </tr>
        <?php while ($row = $result->fetch_assoc()): ?>
            <tr>
                <td><?= $row['id'] ?></td>
                <td><?= htmlspecialchars($row['name']) ?></td>
                <td><?= htmlspecialchars($row['email']) ?></td>
                <td><?= htmlspecialchars($row['role']) ?></td>
                <td><?= $row['active'] ? 'Igen' : 'Nem' ?></td>
                <td>
                    <a href="user_edit.php?id=<?= $row['id'] ?>">Szerkesztés</a> |
                    <a href="user_password.php?id=<?= $row['id'] ?>">Jelszó</a> |
                    <a href="user_delete.php?id=<?= $row['id'] ?>" onclick="return confirm('Biztosan törölni szeretnéd?')">Törlés</a>
                </td>
            </tr>
        <?php endwhile; ?>
    </table>
</div>
</body>
</html>