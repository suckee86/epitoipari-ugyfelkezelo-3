<?php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: ../index.php");
    exit();
}

$role = $_SESSION['user']['role'];
$id = $_SESSION['user']['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $img = $_POST['signature'] ?? '';
    if (preg_match('/^data:image\/(png|jpeg);base64,/', $img)) {
        $img = preg_replace('/^data:image\/(png|jpeg);base64,/', '', $img);
        $img = str_replace(' ', '+', $img);
        $data = base64_decode($img);

        if (!is_dir("../signatures")) {
            mkdir("../signatures", 0777, true);
        }

        $path = "../signatures/user_{$id}.png";
        file_put_contents($path, $data);
        
        // Sikeres mentés után visszairányítás
        switch ($role) {
            case 'felmero':
                header("Location: ../felmero/dashboard_felmero.php"); break;
            case 'kivitelezo':
                header("Location: ../kivitelezo/dashboard_kivitelezo.php"); break;
            case 'ellenorzo':
                header("Location: ../ellenorzo/dashboard_ellenorzo.php"); break;
            case 'auditor':
                header("Location: ../auditor/dashboard_auditor.php"); break;
            default:
                header("Location: ../dashboard.php"); break;
        }
        exit();
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
    <title>Aláírás mentése</title><style>
        canvas { border: 2px solid #333; background: #fff; }
    </style>
</head>
<body>
<div class="container">
    <h1>Aláírás rögzítése</h1>
    <form method="post">
        <canvas id="sigCanvas" width="500" height="150"></canvas><br>
        <input type="hidden" name="signature" id="signature">
        <button type="submit" onclick="saveSignature()">Mentés</button>
        <button type="button" onclick="clearCanvas()">Törlés</button>
    </form>
    <p><a href="javascript:history.back()">Vissza</a></p>
</div>
<script>
    const canvas = document.getElementById('sigCanvas');
    const ctx = canvas.getContext('2d');
    let drawing = false;

    canvas.addEventListener('mousedown', () => drawing = true);
    canvas.addEventListener('mouseup', () => drawing = false);
    canvas.addEventListener('mouseout', () => drawing = false);
    canvas.addEventListener('mousemove', draw);

    function draw(e) {
        if (!drawing) return;
        const rect = canvas.getBoundingClientRect();
        ctx.lineWidth = 2;
        ctx.lineCap = 'round';
        ctx.strokeStyle = '#000';
        ctx.lineTo(e.clientX - rect.left, e.clientY - rect.top);
        ctx.stroke();
        ctx.beginPath();
        ctx.moveTo(e.clientX - rect.left, e.clientY - rect.top);
    }

    function clearCanvas() {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.beginPath();
    }

    function saveSignature() {
        const signatureInput = document.getElementById('signature');
        signatureInput.value = canvas.toDataURL();
    }
</script>
</body>
</html>
