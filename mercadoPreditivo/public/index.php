<?php
// public/index.php

declare(strict_types=1);

require_once __DIR__ . '/../../lib/auth.php';

$userId = current_user_id();
$userName = $_SESSION['name'] ?? null;
$isAdmin = !empty($_SESSION['is_admin']);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Mercado Preditivo</title>
    <link rel="stylesheet" href="/mercadoPreditivo/assets/styles.css">
</head>
<body>
<div class="container">
    <header class="header">
        <h1>Mercado de Previsões</h1>
        <nav>
            <?php if ($userId): ?>
                <span>Olá, <?= htmlspecialchars((string)$userName) ?></span>
                <a href="/mercadoPreditivo/public/me.php">Minhas posições</a>
                <?php if ($isAdmin): ?>
                    <a href="/mercadoPreditivo/admin">Admin</a>
                <?php endif; ?>
                <a href="/auth/logout.php">Sair</a>
            <?php else: ?>
                <a href="/auth/login.php">Entrar</a>
            <?php endif; ?>
        </nav>
    </header>

    <section class="market-list" data-market-list></section>
</div>
<script src="/mercadoPreditivo/assets/prediction.js" defer></script>
</body>
</html>