<?php
// admin/markets.php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../../lib/prediction_market_service.php';

require_admin();

$messages = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $marketId = (int) ($_POST['market_id'] ?? 0);

    if ($action === 'close') {
        $stmt = $pdo->prepare("UPDATE pm_markets SET status = 'closed' WHERE id = ? AND status = 'open'");
        $stmt->execute([$marketId]);
        $messages[] = 'Mercado fechado.';
    }

    if ($action === 'resolve') {
        $result = $_POST['result'] ?? '';
        if (!in_array($result, ['yes', 'no'], true)) {
            $errors[] = 'Resultado inválido.';
        } else {
            try {
                $resultData = prediction_resolve_market($pdo, $marketId, $result);
                $messages[] = $resultData['status'] === 'already_settled'
                    ? 'Mercado já estava resolvido.'
                    : 'Mercado resolvido e pagamentos realizados.';
            } catch (Throwable $e) {
                $errors[] = 'Falha ao resolver mercado.';
            }
        }
    }
}

$stmt = $pdo->query('SELECT * FROM pm_markets ORDER BY created_at DESC');
$rawMarkets = $stmt->fetchAll();
$markets = array_map(function($market) use ($pdo) {
    return prediction_refresh_market_status($pdo, $market);
}, $rawMarkets);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Admin - Mercados</title>
    <link rel="stylesheet" href="/mercadoPreditivo/assets/styles.css">
</head>
<body>
<div class="container">
    <header class="header">
        <h1>Painel Admin - Mercados</h1>
        <nav>
            <a href="/mercadoPreditivo/public/index.php">Voltar ao site</a>
            <a href="/mercadoPreditivo/admin/create_market.php">Criar mercado</a>
        </nav>
    </header>

    <?php if ($messages): ?>
        <div class="alert alert-success">
            <ul>
                <?php foreach ($messages as $msg): ?>
                    <li><?= e($msg) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="alert alert-error">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <section class="card">
        <table>
            <thead>
                <tr>
                    <th>Título</th>
                    <th>Status</th>
                    <th>Fecha em</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($markets as $market): ?>
                    <tr>
                        <td><?= e($market['title']) ?></td>
                        <td><?= e($market['status']) ?></td>
                        <td><?= e($market['close_at']) ?></td>
                        <td class="actions">
                            <?php if ($market['status'] === 'open'): ?>
                                <form method="post">
                                    <input type="hidden" name="market_id" value="<?= (int) $market['id'] ?>">
                                    <input type="hidden" name="action" value="close">
                                    <button type="submit">Fechar</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($market['status'] !== 'resolved'): ?>
                                <form method="post">
                                    <input type="hidden" name="market_id" value="<?= (int) $market['id'] ?>">
                                    <input type="hidden" name="action" value="resolve">
                                    <select name="result" required>
                                        <option value="yes">YES</option>
                                        <option value="no">NO</option>
                                    </select>
                                    <button type="submit">Resolver</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</div>
</body>
</html>