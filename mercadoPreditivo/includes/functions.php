<?php
// includes/functions.php
// Funções comuns e middleware simples.

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function require_login(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: /public/login.php');
        exit;
    }
}

function require_admin(): void
{
    require_login();
    if (empty($_SESSION['is_admin'])) {
        http_response_code(403);
        echo 'Acesso negado.';
        exit;
    }
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function get_current_user_data(PDO $pdo): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id, name, email, balance, is_admin FROM mp_users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    return $user ?: null;
}

function calculate_prices(array $market): array
{
    $liquidityYes = (float) $market['liquidity_yes'];
    $liquidityNo = (float) $market['liquidity_no'];
    $total = $liquidityYes + $liquidityNo;

    if ($total <= 0) {
        return ['yes' => 0.5, 'no' => 0.5];
    }

    $priceYes = $liquidityNo / $total;
    $priceNo = $liquidityYes / $total;

    return ['yes' => $priceYes, 'no' => $priceNo];
}