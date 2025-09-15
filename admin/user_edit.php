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

// Adatok lekérése
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if (!$user = $result->fetch_assoc()) {
    echo "Nincs ilyen felhasználó.";
    exit();
}

// Frissítés
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update'])) {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $role = $_POST['role'];
    $active = isset($_POST['active']) ? 1 : 0;

    $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, role = ?, active = ? WHERE id = ?");
    $stmt->bind_param("sssii", $name, $email, $role, $active, $id);
    $stmt->execute();
    header("Location: users.php");
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
    <title>Felhasználó szerkesztése</title></head>
<body>
<div class="container">
    <h1>Felhasználó szerkesztése</h1>
    <form method="post">
        <label>Név: <input type="text" name="name" value="<?= htmlspecialchars($user['name']) ?>" required></label><br>
        <label>Email: <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required></label><br>
        <label>Szerepkör:
            <select name="role">
                <?php
                $roles = ['felmero' => 'Felmérő', 'kivitelezo' => 'Kivitelező', 'ellenorzo' => 'Ellenőrző', 'auditor' => 'Auditor', 'admin' => 'Admin'];
                foreach ($roles as $key => $label):
                    $selected = $user['role'] === $key ? 'selected' : '';
                    echo "<option value='$key' $selected>$label</option>";
                endforeach;
                ?>
            </select>
        </label><br>
        <label>Aktív: <input type="checkbox" name="active" <?= $user['active'] ? 'checked' : '' ?>></label><br>
        <button type="submit" name="update">Mentés</button>
    </form>
    <p><a href="users.php">Vissza a listához</a></p>
</div>
</body>
</html>