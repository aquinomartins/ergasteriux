<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../lib/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['error' => 'POST only']);
  exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$email = trim((string)($body['email'] ?? ''));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  http_response_code(400);
  echo json_encode(['error' => 'invalid_email']);
  exit;
}

$ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
$normalizedEmail = mb_strtolower($email, 'UTF-8');

try {
  $pdo = db();

  $limitWindowMinutes = 15;
  $maxPerEmail = 3;
  $maxPerIp = 10;

  $emailRateStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM confirmation_resend_attempts WHERE email = ? AND created_at >= (NOW() - INTERVAL ? MINUTE)'
  );
  $emailRateStmt->execute([$normalizedEmail, $limitWindowMinutes]);

  $ipRateStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM confirmation_resend_attempts WHERE ip_address = ? AND created_at >= (NOW() - INTERVAL ? MINUTE)'
  );
  $ipRateStmt->execute([$ip, $limitWindowMinutes]);

  if ((int)$emailRateStmt->fetchColumn() >= $maxPerEmail || (int)$ipRateStmt->fetchColumn() >= $maxPerIp) {
    http_response_code(429);
    echo json_encode(['error' => 'rate_limited', 'msg' => 'Muitas tentativas. Aguarde alguns minutos e tente novamente.']);
    exit;
  }

  $pdo->prepare('INSERT INTO confirmation_resend_attempts (email, ip_address) VALUES (?, ?)')->execute([$normalizedEmail, $ip]);

  $stmt = $pdo->prepare('SELECT id, name, email, confirmed FROM users WHERE email = ? LIMIT 1');
  $stmt->execute([$normalizedEmail]);
  $user = $stmt->fetch();

  // Evita enumeração de usuários: retorna sucesso mesmo sem conta.
  if (!$user) {
    echo json_encode(['ok' => true, 'msg' => 'Se o e-mail existir, enviaremos um novo link de confirmação.']);
    exit;
  }

  if ((int)($user['confirmed'] ?? 0) === 1) {
    echo json_encode(['ok' => true, 'msg' => 'Seu cadastro já está confirmado.']);
    exit;
  }

  $token = bin2hex(random_bytes(32));

  $pdo->beginTransaction();
  try {
    $pdo->prepare('DELETE FROM user_confirmations WHERE user_id = ?')->execute([(int)$user['id']]);
    $pdo->prepare('INSERT INTO user_confirmations (user_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))')
      ->execute([(int)$user['id'], $token]);
    $pdo->commit();
  } catch (Exception $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    throw $e;
  }

  $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'aquino.bsb.br';
  $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/api/resend_confirmation.php'));
  if ($scriptDir === '/' || $scriptDir === '.') {
    $scriptDir = '';
  }
  $confirmPath = '/' . ltrim($scriptDir . '/confirm.php', '/');
  $link = sprintf('%s://%s%s?token=%s', $scheme, $host, $confirmPath, urlencode($token));

  $subject = 'Novo link de confirmação - AquinoNFT';
  $message = "Olá, {$user['name']}!\n\nUse o link abaixo para confirmar seu cadastro:\n{$link}\n\nEste link expira em 24 horas.\n";
  $headers = "From: noreply@aquino.bsb.br\r\nContent-Type: text/plain; charset=UTF-8\r\n";

  @mail((string)$user['email'], $subject, $message, $headers);

  echo json_encode(['ok' => true, 'msg' => 'Se o e-mail existir, enviaremos um novo link de confirmação.']);
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(['error' => 'cannot_resend', 'detail' => $e->getMessage()]);
}
