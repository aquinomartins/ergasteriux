<?php
// seed.php
// Script para inserir dados iniciais.

declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';

$adminEmail = 'admin@ergasterio.com.br';
$adminPassword = 'admin123';

$stmt = $pdo->prepare('SELECT id FROM mp_users WHERE email = ?');
$stmt->execute([$adminEmail]);

if (!$stmt->fetch()) {
    $hash = password_hash($adminPassword, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('INSERT INTO mp_users (name, email, password_hash, balance, is_admin) VALUES (?, ?, ?, ?, 1)');
    $stmt->execute(['Administrador', $adminEmail, $hash, '10000.000000']);
}

$stmt = $pdo->query('SELECT COUNT(*) FROM mp_markets');
$count = (int) $stmt->fetchColumn();

if ($count === 0) {
    $stmt = $pdo->prepare('INSERT INTO mp_markets (title, description, created_at, close_at, status, liquidity_yes, liquidity_no) VALUES (?, ?, NOW(), ?, ?, ?, ?)');
    $stmt->execute([
        'Vai chover em Brasília amanhã?',
        'Mercado exemplo para testar o fluxo.',
        date('Y-m-d H:i:s', strtotime('+2 days')),
        'open',
        '100.000000',
        '100.000000',
    ]);

    $stmt->execute([
        'O Brasil ganhará o próximo jogo?',
        'Outro mercado de demonstração.',
        date('Y-m-d H:i:s', strtotime('+5 days')),
        'open',
        '120.000000',
        '80.000000',
    ]);
}

echo "Seed concluído.\n";