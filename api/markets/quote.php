<?php

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

$marketId = isset($_GET['market_id']) ? (int)$_GET['market_id'] : market_read_id();
$sideInput = (string)($_GET['side'] ?? '');
$side = market_side_to_legacy($sideInput) ?? strtoupper(trim($sideInput));
$shares = isset($_GET['shares']) ? (int)$_GET['shares'] : 0;

if ($marketId <= 0 || !in_array($side, ['SIM', 'NAO'], true) || $shares <= 0 || $shares > 1000) {
  http_response_code(400);
  echo json_encode(['error' => 'invalid_parameters']);
  exit;
}

$pdo = db();
$market = market_fetch($pdo, $marketId);
if (!$market) {
  http_response_code(404);
  echo json_encode(['error' => 'market_not_found']);
  exit;
}

$quote = market_quote($market, $side, $shares);
$quotePayload = market_contract_aliases($quote);
$quotePayload['side'] = strtolower($side === 'SIM' ? 'yes' : 'no');
$quotePayload['side_legacy'] = $side;
echo json_encode(['ok' => true, 'quote' => $quotePayload]);
