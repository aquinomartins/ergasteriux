<?php
// public/market.php

declare(strict_types=1);

require_once __DIR__ . '/../../lib/auth.php';

$slug = isset($_GET['slug']) ? trim((string)$_GET['slug']) : '';
$userId = current_user_id();
$userName = $_SESSION['name'] ?? null;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Mercado Preditivo</title>
    <link rel="stylesheet" href="/mercadoPreditivo/assets/styles.css">
</head>
<body>
<div class="container" data-market-detail data-market-slug="<?= htmlspecialchars($slug) ?>">
    <header class="header">
        <h1>Mercado de Previsões</h1>
        <nav>
            <a href="/mercadoPreditivo/public/index.php">Mercados</a>
            <?php if ($userId): ?>
                <span>Olá, <?= htmlspecialchars((string)$userName) ?></span>
                <a href="/mercadoPreditivo/public/me.php">Minhas posições</a>
                <a href="/auth/logout.php">Sair</a>
            <?php else: ?>
                <a href="/auth/login.php">Entrar</a>
            <?php endif; ?>
        </nav>
    </header>

    <section class="card">
        <h2 data-market-title>Carregando...</h2>
        <p data-market-description></p>
        <div class="market-meta">
            <span>Status: <strong data-market-status></strong></span>
            <span>Fecha em: <strong data-market-close></strong></span>
        </div>
        <div class="prices">
            <span>YES: <strong data-price-yes></strong></span>
            <span>NO: <strong data-price-no></strong></span>
        </div>
        <div class="positions">
            <span>Minha posição YES: <strong data-position-yes>—</strong></span>
            <span>NO: <strong data-position-no>—</strong></span>
        </div>
        <div class="market-meta">
            <span>Volume BRL: <strong data-market-volume>—</strong></span>
            <span>Total de trades: <strong data-market-trades>—</strong></span>
        </div>
        <div class="market-meta">
            <span>Saldo BRL: <strong data-balance>—</strong></span>
        </div>
    </section>

    <section class="card">
        <h3>Executar trade</h3>
        <form>
            <label>
                Outcome
                <select name="outcome">
                    <option value="yes">YES</option>
                    <option value="no">NO</option>
                </select>
            </label>
            <label>
                Lado
                <select name="side">
                    <option value="buy">Comprar</option>
                    <option value="sell">Vender</option>
                </select>
            </label>
            <label>
                Shares
                <input type="number" name="shares" min="0" step="0.01" required>
            </label>
            <div class="alert" data-quote></div>
            <button type="submit">Confirmar trade</button>
        </form>
        <div data-trade-message></div>
        <?php if (!$userId): ?>
            <p>Você precisa estar logado para operar.</p>
        <?php endif; ?>
    </section>
</div>
<script src="/mercadoPreditivo/assets/prediction.js" defer></script>
</body>
</html>