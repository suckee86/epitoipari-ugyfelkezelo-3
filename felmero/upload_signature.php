<?php
require_once '../includes/auth.php';

if ($_SESSION['user']['role'] !== 'felmero') {
    header("Location: ../index.php");
    exit();
}

$upload_dir = '../signatures/';
$user_id = $_SESSION['user']['id'];

if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['signature'])) {
    $file = $_FILES['signature'];
    if ($file['type'] === 'image/png') {
        $target = $upload_dir . 'signature_user_' . $user_id . '.png';
        move_uploaded_file($file['tmp_name'], $target);
        $success = true;
    } else {
        $error = 'Csak PNG formátumú fájl engedélyezett.';
    }
}
?>

<!DOCTYPE html>
<html lang="hu">
\1
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta charset="UTF-8">
    <title>Aláírás feltöltése</title></head>
<body>
    <h2>Aláírás feltöltése</h2>
    <?php if (!empty($success)) echo '<p>Sikeresen feltöltve!</p>'; ?>
    <?php if (!empty($error)) echo '<p style="color:red;">' . $error . '</p>'; ?>
    <form method="POST" enctype="multipart/form-data">
        <input type="file" name="signature" accept="image/png" required><br>
        <button type="submit" class="btn btn-dark w-100">Feltöltés</button>
    </form>
</body>
</html>
