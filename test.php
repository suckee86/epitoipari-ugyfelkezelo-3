<?php
$input = 'admin123';
$hash = '$2y$10$usqG/Nh.F1sKtZK3umtoIut1dOB3PbUwBz8o1us0Rm6YAHl3A8ENq'; // amit az SQL-ben is használtunk

if (password_verify($input, $hash)) {
    echo "✔️ A jelszó egyezik!";
} else {
    echo "❌ Nem egyezik!";
}
?>