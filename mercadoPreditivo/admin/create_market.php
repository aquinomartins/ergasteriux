<?php
// admin/create_market.php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

require_admin();

$errors = [];
$title = '';
$description = '';
$closeAt = '';
$liquidityYes = '0.000000';
$liquidityNo = '0.000000';
$slug = '';
$bValue = '50';

$slugify = static function (string $value): string {
    $slug = mb_strtolower(trim($value), 'UTF-8');
    $slug = preg_replace('/[^a-z0-9]+/u', '-', $slug);
    $slug = trim($slug ?? '', '-');
    return $slug;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $closeAt = trim($_POST['close_at'] ?? '');
    $liquidityYes = trim($_POST['liquidity_yes'] ?? '0');
    $liquidityNo = trim($_POST['liquidity_no'] ?? '0');
    $slug = trim($_POST['slug'] ?? '');
    $bValue = trim($_POST['b'] ?? '50');

    if ($title === '') {
        $errors[] = 'Título é obrigatório.';
    }
    if ($closeAt === '') {
        $errors[] = 'Data de fechamento é obrigatória.';
    }
    if (!is_numeric($liquidityYes) || !is_numeric($liquidityNo)) {
        $errors[] = 'Liquidez inválida.';
    }
    if ($slug === '') {
        $slug = $slugify($title);
    }
    if ($slug === '') {
        $errors[] = 'Slug é obrigatório.';
    } elseif (!preg_match('/^[a-z0-9-]{3,80}$/', $slug)) {
        $errors[] = 'Slug inválido (use letras, números e hífen).';
    }
    if (!is_numeric($bValue) || (float) $bValue <= 0) {
        $errors[] = 'Parâmetro b inválido.';
    }

    if (!$errors) {
        $check = $pdo->prepare('SELECT id FROM pm_markets WHERE slug = ? LIMIT 1');
        $check->execute([$slug]);
        if ($check->fetchColumn()) {
            $errors[] = 'Slug já existe, escolha outro.';
        }
    }

    if (!$errors) {
        $stmt = $pdo->prepare('INSERT INTO pm_markets (slug, title, description, status, close_at, resolved_outcome, b, q_yes, q_no, created_by) VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, ?)');
        $stmt->execute([
            $slug,
            $title,
            $description,
            'open',
            $closeAt !== '' ? $closeAt : null,
            (float) $bValue,
            (float) $liquidityYes,
            (float) $liquidityNo,
            $_SESSION['user_id'] ?? null,
        ]);
        header('Location: /mercadoPreditivo/admin/markets.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Criar Mercado</title>
    <link rel="stylesheet" href="/mercadoPreditivo/assets/styles.css">
</head>
<body>
<div class="container">
    <header class="header">
        <h1>Novo mercado</h1>
        <nav>
            <a href="/mercadoPreditivo/admin/markets.php">Voltar</a>
        </nav>
    </header>

    <?php if ($errors): ?>
        <div class="alert alert-error">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" class="card">
        <label>Slug (URL)
            <input type="text" name="slug" value="<?= e($slug) ?>" placeholder="deixe em branco para gerar automaticamente">
        </label>
        <label>Título
            <input type="text" name="title" value="<?= e($title) ?>" required>
        </label>
        <label>Descrição
            <textarea name="description" rows="4"><?= e($description) ?></textarea>
        </label>
        <label>Data/hora de fechamento
            <input type="datetime-local" name="close_at" value="<?= e($closeAt) ?>" required>
        </label>
        <label>Parâmetro b (liquidez)
            <input type="number" name="b" step="0.01" value="<?= e($bValue) ?>" required>
        </label>
        <label>Liquidez inicial YES (q_yes)
            <input type="number" name="liquidity_yes" step="0.000001" value="<?= e($liquidityYes) ?>" required>
        </label>
        <label>Liquidez inicial NO (q_no)
            <input type="number" name="liquidity_no" step="0.000001" value="<?= e($liquidityNo) ?>" required>
        </label>
        <button type="submit">Criar mercado</button>
    </form>
</div>
</body>
</html>