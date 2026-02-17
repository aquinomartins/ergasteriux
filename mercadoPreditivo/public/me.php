<?php
// public/me.php

declare(strict_types=1);

require_once __DIR__ . '/../../lib/auth.php';

$userId = current_user_id();
$userName = $_SESSION['name'] ?? null;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Minhas posições</title>
    <link rel="stylesheet" href="/mercadoPreditivo/assets/styles.css">
</head>
<body>
<div class="container" data-positions>
    <header class="header">
        <h1>Minhas posições</h1>
        <nav>
            <a href="/mercadoPreditivo/public/index.php">Mercados</a>
            <?php if ($userId): ?>
                <span>Olá, <?= htmlspecialchars((string)$userName) ?></span>
                <a href="/auth/logout.php">Sair</a>
            <?php else: ?>
                <a href="/auth/login.php">Entrar</a>
            <?php endif; ?>
        </nav>
    </header>
    <?php if (!$userId): ?>
        <p>Você precisa estar logado para ver suas posições.</p>
    <?php endif; ?>
</div>
<script src="/mercadoPreditivo/assets/prediction.js" defer></script>
</body>
</html>
