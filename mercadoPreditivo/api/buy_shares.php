<?php
// api/buy_shares.php (compat)

declare(strict_types=1);

require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/prediction_market_service.php';
require_once __DIR__ . '/../../lib/market_api_contract.php';

header('Content-Type: application/json');
market_emit_deprecation('/mercadoPreditivo/api/buy_shares.php', '/api/markets/buy.php');
require_login();

$marketId = (int) ($_POST['market_id'] ?? 0);
$outcome = isset($_POST['side']) ? strtolower(trim((string)$_POST['side'])) : '';
$shares = (float) ($_POST['shares'] ?? 0);

if (!in_array($outcome, ['yes', 'no'], true) || $shares <= 0 || $marketId <= 0) {
  http_response_code(422);
  echo json_encode(['error' => 'Dados inválidos.']);
  exit;
}

$pdo = db();
$userId = current_user_id();

try {
  $result = prediction_execute_trade($pdo, $userId, $marketId, $outcome, 'buy', $shares);
  echo json_encode([
    'balance' => $result['balance_brl'],
    'prices' => [
      'yes' => $result['price_yes'],
      'no' => $result['price_no'],
    ],
    'position' => [
      'yes' => $result['position']['shares_yes'],
      'no' => $result['position']['shares_no'],
    ],
    'deprecation' => market_deprecation_payload('/mercadoPreditivo/api/buy_shares.php', '/api/markets/buy.php'),
  ]);
} catch (RuntimeException $e) {
  http_response_code(400);
  echo json_encode(['error' => $e->getMessage()]);
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(['error' => 'trade_failed']);
}