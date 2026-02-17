<?php
// api/get_market.php (compat)

declare(strict_types=1);

require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/prediction_market_service.php';
require_once __DIR__ . '/../../lib/market_api_contract.php';
require_once __DIR__ . '/../../lib/prediction_accounts.php';

header('Content-Type: application/json');
market_emit_deprecation('/mercadoPreditivo/api/get_market.php', '/api/markets/get.php');

$marketId = (int) ($_GET['id'] ?? 0);

if ($marketId <= 0) {
  http_response_code(400);
  echo json_encode(['error' => 'Mercado não encontrado.']);
  exit;
}

$pdo = db();
$market = prediction_fetch_market_by_id($pdo, $marketId);

if (!$market) {
  http_response_code(404);
  echo json_encode(['error' => 'Mercado não encontrado.']);
  exit;
}

$prices = prediction_market_prices($market);
$userId = current_user_id();
$position = ['shares_yes' => 0.0, 'shares_no' => 0.0];
$balance = null;

if ($userId) {
  $position = prediction_user_position($pdo, $marketId, $userId);
  $accountId = get_or_create_brl_cash_account($pdo, $userId);
  $balance = get_brl_balance($pdo, $accountId);
}

$response = [
  'market' => [
    'id' => (int)$market['id'],
    'title' => $market['title'],
    'description' => $market['description'],
    'status' => $market['status'],
    'close_at' => $market['close_at'],
    'result' => $market['resolved_outcome'],
  ],
  'prices' => [
    'yes' => $prices['yes'],
    'no' => $prices['no'],
  ],
  'position' => [
    'yes' => $position['shares_yes'],
    'no' => $position['shares_no'],
  ],
];

if ($balance !== null) {
  $response['balance'] = $balance;
}

$response['deprecation'] = market_deprecation_payload('/mercadoPreditivo/api/get_market.php', '/api/markets/get.php');
echo json_encode($response);