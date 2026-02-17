<?php

require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/market_service.php';
require_once __DIR__ . '/../../lib/market_api_contract.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  http_response_code(405);
  echo json_encode(['error' => 'method_not_allowed']);
  exit;
}

$pdo = db();
$stmt = $pdo->query("SELECT m.*, COALESCE(s.q_sim, 0) AS q_sim, COALESCE(s.q_nao, 0) AS q_nao, COALESCE(s.cost_total, 0) AS cost_total, s.updated_at AS state_updated_at FROM markets m LEFT JOIN market_state s ON s.market_id = m.id ORDER BY m.id DESC");
$markets = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$userId = current_user_id();
$positionsByMarket = [];
if ($userId && $markets) {
  $ids = array_map(fn($m) => (int)$m['id'], $markets);
  $placeholders = implode(',', array_fill(0, count($ids), '?'));
  $posStmt = $pdo->prepare("SELECT market_id, side, shares, avg_cost FROM market_positions WHERE user_id = ? AND market_id IN ({$placeholders})");
  $posStmt->execute(array_merge([$userId], $ids));
  while ($row = $posStmt->fetch(PDO::FETCH_ASSOC)) {
    $marketId = (int)$row['market_id'];
    if (!isset($positionsByMarket[$marketId])) {
      $positionsByMarket[$marketId] = [
        'SIM' => ['shares' => 0, 'avg_cost' => 0.0],
        'NAO' => ['shares' => 0, 'avg_cost' => 0.0],
      ];
    }
    $side = $row['side'] === 'NAO' ? 'NAO' : 'SIM';
    $positionsByMarket[$marketId][$side] = [
      'shares' => (int)$row['shares'],
      'avg_cost' => (float)$row['avg_cost'],
    ];
  }
}

$payload = array_map(function($market) use ($positionsByMarket) {
  $prices = market_prices($market);
  $entry = [
    'id' => (int)$market['id'],
    'title' => $market['title'],
    'description' => $market['description'],
    'status' => $market['status'],
    'outcome' => $market['outcome'],
    'b' => (float)$market['b'],
    'q_sim' => (int)$market['q_sim'],
    'q_nao' => (int)$market['q_nao'],
    'p_sim' => $prices['p_sim'],
    'p_nao' => $prices['p_nao'],
    'fee_rate' => (float)$market['fee_rate'],
    'created_at' => $market['created_at'],
  ];
  $marketId = (int)$market['id'];
  if (isset($positionsByMarket[$marketId])) {
    $entry['my_position'] = $positionsByMarket[$marketId];
  }
  return market_contract_aliases($entry);
}, $markets);

echo json_encode(['ok' => true, 'markets' => $payload]);