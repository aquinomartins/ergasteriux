<?php
// public/login.php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$errors = [];
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare('SELECT id, password_hash, is_admin FROM mp_users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        $errors[] = 'Credenciais inválidas.';
    } else {
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['is_admin'] = (int) $user['is_admin'];
        header('Location: /public/index.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Login - Mercado Preditivo</title>
    <link rel="stylesheet" href="/assets/styles.css">
</head>
<body>
<div class="container">
    <h1>Entrar</h1>
    <?php if (!empty($_GET['registered'])): ?>
        <div class="alert alert-success">Cadastro realizado! Faça login.</div>
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
    <form method="post" class="card">
        <label>Email
            <input type="email" name="email" value="<?= e($email) ?>" required>
        </label>
        <label>Senha
            <input type="password" name="password" required>
        </label>
        <button type="submit">Entrar</button>
    </form>
    <p>Não tem conta? <a href="/public/register.php">Cadastre-se</a></p>
</div>
</body>
</html>