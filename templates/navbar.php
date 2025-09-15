<?php if (isset($_SESSION['user'])): ?>
    <nav style="margin-bottom: 20px;">
        <strong>Bejelentkezve mint:</strong> <?= htmlspecialchars($_SESSION['user']['name']) ?> |
        <strong>Szerepkör:</strong> <?= htmlspecialchars($_SESSION['user']['role']) ?> |
        <a href="/logout.php">Kijelentkezés</a>
    </nav>
<?php endif; ?>
