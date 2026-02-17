<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/market_api_contract.php';
require_once __DIR__ . '/../lib/prediction_market_service.php';

header('Content-Type: application/json');
market_emit_deprecation('/api/prediction_quote.php', '/api/markets/quote.php');

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

$pdo = db();
$market = prediction_fetch_market_by_id($pdo, $marketId);
if (!$market) {
  http_response_code(404);
  echo json_encode(['error' => 'market_not_found']);
  exit;
}

if ($market['status'] !== 'open') {
  http_response_code(409);
  echo json_encode(['error' => 'market_closed']);
  exit;
}

if (!empty($market['close_at'])) {
  $closeAt = new DateTime($market['close_at']);
  if ($closeAt <= new DateTime()) {
    http_response_code(409);
    echo json_encode(['error' => 'market_closed']);
    exit;
  }
}

$quote = prediction_quote_market($market, $outcome, $side, $shares);

echo json_encode([
  'ok' => true,
  'cash_delta_brl' => $quote['cash_delta_brl'],
  'price_yes_before' => $quote['price_yes_before'],
  'price_yes_after' => $quote['price_yes_after'],
  'deprecation' => market_deprecation_payload('/api/prediction_quote.php', '/api/markets/quote.php'),
]);
