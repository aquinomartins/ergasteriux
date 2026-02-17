<?php
// public/dashboard.php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$user = get_current_user_data($pdo);

$stmt = $pdo->prepare('SELECT t.*, m.title FROM mp_transactions t LEFT JOIN mp_markets m ON m.id = t.market_id WHERE t.user_id = ? ORDER BY t.created_at DESC');
$stmt->execute([$user['id']]);
$mp_transactions = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT p.*, m.title FROM mp_positions p INNER JOIN mp_markets m ON m.id = p.market_id WHERE p.user_id = ? ORDER BY m.close_at ASC');
$stmt->execute([$user['id']]);
$mp_positions = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Dashboard - Mercado Preditivo</title>
    <link rel="stylesheet" href="/assets/styles.css">
</head>
<body>
<div class="container">
    <header class="header">
        <h1>Dashboard</h1>
        <nav>
            <a href="/public/index.php">Mercados</a>
            <a href="/public/logout.php">Sair</a>
        </nav>
    </header>

    <section class="card">
        <h2>Saldo atual</h2>
        <p><?= number_format((float) $user['balance'], 2, ',', '.') ?> créditos</p>
    </section>

    <section class="card">
        <h2>Suas posições</h2>
        <?php if ($mp_positions): ?>
            <table>
                <thead>
                    <tr>
                        <th>Mercado</th>
                        <th>Lado</th>
                        <th>Shares</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($mp_positions as $position): ?>
                        <tr>
                            <td><?= e($position['title']) ?></td>
                            <td><?= strtoupper(e($position['side'])) ?></td>
                            <td><?= number_format((float) $position['shares'], 4, ',', '.') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>Nenhuma posição aberta.</p>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>Histórico de transações</h2>
        <?php if ($mp_transactions): ?>
            <table>
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Mercado</th>
                        <th>Tipo</th>
                        <th>Lado</th>
                        <th>Shares</th>
                        <th>Preço</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($mp_transactions as $tx): ?>
                        <tr>
                            <td><?= e($tx['created_at']) ?></td>
                            <td><?= e($tx['title'] ?? 'N/A') ?></td>
                            <td><?= e($tx['type']) ?></td>
                            <td><?= strtoupper(e($tx['side'] ?? '-')) ?></td>
                            <td><?= number_format((float) $tx['shares'], 4, ',', '.') ?></td>
                            <td><?= number_format((float) $tx['price'], 4, ',', '.') ?></td>
                            <td><?= number_format((float) $tx['total_cost'], 4, ',', '.') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>Sem transações registradas.</p>
        <?php endif; ?>
    </section>
</div>
</body>
</html>