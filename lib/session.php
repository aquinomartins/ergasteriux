<?php

function is_https_request(): bool {
  if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
    return true;
  }
  $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
  if ($forwardedProto !== '') {
    return strtolower((string)$forwardedProto) === 'https';
  }
  return (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function start_app_session(): void {
  if (session_status() === PHP_SESSION_ACTIVE) {
    return;
  }

  session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => is_https_request(),
    'httponly' => true,
    'samesite' => 'Lax',
  ]);

  session_start();
}

function csrf_token(): string {
  start_app_session();
  if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['csrf_token'];
}

function csrf_token_from_request(): ?string {
  $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
  if (is_string($headerToken) && $headerToken !== '') {
    return $headerToken;
  }

  if (isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])) {
    return $_POST['csrf_token'];
  }

  return null;
}

function is_mutating_request_method(?string $method = null): bool {
  $requestMethod = strtoupper($method ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
  return in_array($requestMethod, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
}

function csrf_forbidden_response(string $context = 'unknown'): void {
  $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
  $path = $_SERVER['REQUEST_URI'] ?? 'unknown';
  $method = $_SERVER['REQUEST_METHOD'] ?? 'unknown';
  error_log(sprintf('[csrf] invalid token context=%s method=%s path=%s ip=%s', $context, $method, $path, $ip));

  http_response_code(403);
  header('Content-Type: application/json');
  echo json_encode(['error' => 'csrf_invalid']);
  exit;
}

function require_csrf_token(string $context = 'unknown'): void {
  start_app_session();
  $expected = csrf_token();
  $provided = csrf_token_from_request();
  if (!$provided || !hash_equals($expected, $provided)) {
    csrf_forbidden_response($context);
  }
}
