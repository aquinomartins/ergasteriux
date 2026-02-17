<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../lib/session.php';
start_app_session();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['error' => 'POST only']);
  exit;
}
require_csrf_token('logout');
$_SESSION = [];
session_destroy();
echo json_encode(['ok'=>true]);