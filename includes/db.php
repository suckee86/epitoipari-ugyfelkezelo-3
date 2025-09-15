<?php
$servername = "localhost";
$username = "root";
$password = ""; // XAMPP esetén alapértelmezett jelszó
$database = "epitoipari_ugyfelkezelo";

$conn = new mysqli($servername, $username, $password, $database);

if ($conn->connect_error) {
    die("Kapcsolódási hiba: " . $conn->connect_error);
}
?>
