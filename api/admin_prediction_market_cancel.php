<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/market_api_contract.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/prediction_accounts.php';
require_once __DIR__ . '/../lib/prediction_market_service.php';

header('Content-Type: application/json');
market_emit_deprecation('/api/admin_prediction_market_cancel.php', '/api/markets/resolve.php');

require_login();
require_admin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['error' => 'method_not_allowed']);
  exit;
}

$payload = json_decode(file_get_contents('php://input'), true) ?: [];
$marketId = isset($payload['market_id']) ? (int)$payload['market_id'] : 0;

if ($marketId <= 0) {
  http_response_code(400);
  echo json_encode(['error' => 'missing_fields']);
  exit;
}

$pdo = db();
try {
  $result = prediction_cancel_market($pdo, $marketId);
  echo json_encode([
    'ok' => true,
    'status' => $result['status'] ?? 'cancelled',
    'market_id' => $marketId,
    'refunded_users' => $result['refunded_users'] ?? 0,
  ]);
} catch (RuntimeException $e) {
  $message = $e->getMessage();
  $status = 400;
  if ($message === 'market_not_found') {
    $status = 404;
  } elseif ($message === 'market_resolved') {
    $status = 409;
  }
  http_response_code($status);
  echo json_encode(['error' => $message]);
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(['error' => 'cancel_failed']);
}