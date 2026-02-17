<?php
require_once __DIR__ . '/../lib/db.php';
$pdo = db();

$token = trim($_GET['token'] ?? '');
if ($token === '' || !preg_match('/^[a-f0-9]{64}$/i', $token)) {
  http_response_code(400);
  echo "<h2>Token inválido.</h2>";
  exit;
}

$validStmt = $pdo->prepare(
  'SELECT id, user_id FROM user_confirmations WHERE token = ? AND NOW() <= expires_at LIMIT 1'
);
$validStmt->execute([$token]);
$row = $validStmt->fetch();

if (!$row) {
  $existsStmt = $pdo->prepare('SELECT id FROM user_confirmations WHERE token = ? LIMIT 1');
  $existsStmt->execute([$token]);
  $exists = $existsStmt->fetch();

  if ($exists) {
    http_response_code(410);
    echo "<h2>Link expirado.</h2><p>Solicite um novo e-mail de confirmação para concluir o cadastro.</p>";
  } else {
    echo "<h2>Token não encontrado ou já utilizado.</h2>";
  }
  exit;
}

$user_id = intval($row['user_id']);
$pdo->beginTransaction();
try {
  $pdo->prepare('UPDATE users SET confirmed = 1 WHERE id = ?')->execute([$user_id]);
  $pdo->prepare('DELETE FROM user_confirmations WHERE id = ?')->execute([(int)$row['id']]);
  $pdo->commit();
} catch (Exception $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  throw $e;
}

echo "<h2>✅ Cadastro confirmado com sucesso!</h2><p>Você já pode fazer login normalmente.</p>";
