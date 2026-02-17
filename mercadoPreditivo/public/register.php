<?php
// public/register.php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$errors = [];
$name = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($name === '') {
        $errors[] = 'Nome é obrigatório.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email inválido.';
    }
    if (strlen($password) < 6) {
        $errors[] = 'Senha deve ter ao menos 6 caracteres.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Confirmação de senha não confere.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare('SELECT id FROM mp_users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $errors[] = 'Este email já está cadastrado.';
        }
    }

    if (!$errors) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO mp_users (name, email, password_hash, balance, is_admin) VALUES (?, ?, ?, ?, 0)');
        $stmt->execute([$name, $email, $hash, '1000.000000']);
        header('Location: /public/login.php?registered=1');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Cadastro - Mercado Preditivo</title>
    <link rel="stylesheet" href="/assets/styles.css">
</head>
<body>
<div class="container">
    <h1>Criar conta</h1>
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
        <label>Nome
            <input type="text" name="name" value="<?= e($name) ?>" required>
        </label>
        <label>Email
            <input type="email" name="email" value="<?= e($email) ?>" required>
        </label>
        <label>Senha
            <input type="password" name="password" required>
        </label>
        <label>Confirmar senha
            <input type="password" name="confirm_password" required>
        </label>
        <button type="submit">Cadastrar</button>
    </form>
    <p>Já tem conta? <a href="/public/login.php">Entrar</a></p>
</div>
</body>
</html>