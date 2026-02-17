<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/market_api_contract.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/prediction_market_service.php';

require_login();
header('Content-Type: application/json');
market_emit_deprecation('/api/prediction_trade.php', '/api/markets/buy.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['error' => 'method_not_allowed']);
  exit;
}

$payload = json_decode(file_get_contents('php://input'), true) ?: [];
$marketId = isset($payload['market_id']) ? (int)$payload['market_id'] : 0;
$outcome = isset($payload['outcome']) ? strtolower(trim((string)$payload['outcome'])) : '';
$side = isset($payload['side']) ? strtolower(trim((string)$payload['side'])) : '';
$shares = isset($payload['shares']) ? (float)$payload['shares'] : 0.0;

if ($marketId <= 0 || !in_array($outcome, ['yes', 'no'], true) || !in_array($side, ['buy', 'sell'], true) || $shares <= 0) {
  http_response_code(400);
  echo json_encode(['error' => 'invalid_payload']);
  exit;
}

$userId = current_user_id();
if (!$userId) {
  http_response_code(401);
  echo json_encode(['error' => 'not_authenticated']);
  exit;
}

$pdo = db();

try {
  $result = prediction_execute_trade($pdo, $userId, $marketId, $outcome, $side, $shares);
  echo json_encode([
    'ok' => true,
    'trade' => $result['trade'],
    'position' => $result['position'],
    'price_yes' => $result['price_yes'],
    'price_no' => $result['price_no'],
    'balance_brl' => $result['balance_brl'],
    'deprecation' => market_deprecation_payload('/api/prediction_trade.php', '/api/markets/buy.php'),
  ]);
} catch (RuntimeException $e) {
  $message = $e->getMessage();
  $status = 400;
  if ($message === 'market_not_found') {
    $status = 404;
  } elseif ($message === 'market_closed') {
    $status = 409;
  } elseif ($message === 'insufficient_balance' || $message === 'insufficient_position') {
    $status = 422;
  }
  http_response_code($status);
  echo json_encode(['error' => $message]);
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(['error' => 'trade_failed']);
}
