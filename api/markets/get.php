<?php

require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/market_http.php';
require_once __DIR__ . '/../../lib/market_service.php';
require_once __DIR__ . '/../../lib/market_api_contract.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  http_response_code(405);
  echo json_encode(['error' => 'method_not_allowed']);
  exit;
}

$marketId = market_read_id();
if ($marketId <= 0) {
  http_response_code(400);
  echo json_encode(['error' => 'invalid_market_id']);
  exit;
}

$pdo = db();
$market = market_fetch($pdo, $marketId);
if (!$market) {
  http_response_code(404);
  echo json_encode(['error' => 'market_not_found']);
  exit;
}

$prices = market_prices($market);
$detail = [
  'id' => (int)$market['id'],
  'title' => $market['title'],
  'description' => $market['description'],
  'status' => $market['status'],
  'outcome' => $market['outcome'],
  'b' => (float)$market['b'],
  'fee_rate' => (float)$market['fee_rate'],
  'buffer_rate' => (float)$market['buffer_rate'],
  'collateral_locked' => (float)$market['collateral_locked'],
  'q_sim' => (int)$market['q_sim'],
  'q_nao' => (int)$market['q_nao'],
  'p_sim' => $prices['p_sim'],
  'p_nao' => $prices['p_nao'],
  'created_at' => $market['created_at'],
  'resolved_at' => $market['resolved_at'],
];

$detail['last_trades'] = market_fetch_last_trades($pdo, $marketId, 10);

$userId = current_user_id();
if ($userId) {
  $detail['my_position'] = market_fetch_position($pdo, $marketId, $userId);
}

echo json_encode(['ok' => true, 'market' => market_contract_aliases($detail)]);
