<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/market_api_contract.php';
require_once __DIR__ . '/../lib/db.php';

require_login();
header('Content-Type: application/json');
market_emit_deprecation('/api/prediction_positions.php', '/api/markets/get.php');

try {
  $pdo = db();
  $userId = current_user_id();
  if (!$userId) {
    http_response_code(401);
    echo json_encode(['error' => 'not_authenticated']);
    exit;
  }

  $marketId = isset($_GET['market_id']) ? (int)$_GET['market_id'] : 0;
  $params = [$userId];
  $marketFilter = '';
  if ($marketId > 0) {
    $marketFilter = ' AND m.id = ?';
    $params[] = $marketId;
  }

  $stmt = $pdo->prepare(
    "SELECT m.id AS market_id, m.title, m.category, m.ends_at, m.status,
            o.id AS outcome_id, o.label AS outcome_label,
            p.qty
     FROM positions p
     INNER JOIN prediction_outcomes o ON o.id = p.asset_id
     INNER JOIN prediction_markets m ON m.id = o.market_id
     WHERE p.owner_type = 'user'
       AND p.owner_id = ?
       AND p.qty <> 0
       {$marketFilter}
     ORDER BY m.ends_at ASC, m.id DESC, o.id ASC"
  );
  $stmt->execute($params);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $positions = array_map(function($row) {
    return [
      'market_id' => (int)$row['market_id'],
      'market_title' => $row['title'],
      'category' => $row['category'],
      'ends_at' => $row['ends_at'],
      'status' => $row['status'],
      'outcome_id' => (int)$row['outcome_id'],
      'outcome_label' => $row['outcome_label'],
      'qty' => (float)$row['qty'],
    ];
  }, $rows);

  echo json_encode([
    'positions' => $positions
  ]);
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(['error' => 'prediction_positions_failed']);
}