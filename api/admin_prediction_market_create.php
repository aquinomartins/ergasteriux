<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/market_api_contract.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/prediction_market_service.php';

header('Content-Type: application/json');
market_emit_deprecation('/api/admin_prediction_market_create.php', '/api/markets/create.php');

require_login();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['error' => 'method_not_allowed']);
  exit;
}

$payload = json_decode(file_get_contents('php://input'), true) ?: [];
$slug = isset($payload['slug']) ? strtolower(trim((string)$payload['slug'])) : '';
$title = isset($payload['title']) ? trim((string)$payload['title']) : '';
$description = isset($payload['description']) ? trim((string)$payload['description']) : null;
$closeAtRaw = isset($payload['close_at']) ? trim((string)$payload['close_at']) : '';
$bValue = isset($payload['b']) ? (float)$payload['b'] : 0.0;

if ($slug === '' || $title === '' || $bValue <= 0) {
  http_response_code(400);
  echo json_encode(['error' => 'missing_fields']);
  exit;
}

if (!preg_match('/^[a-z0-9-]{3,80}$/', $slug)) {
  http_response_code(400);
  echo json_encode(['error' => 'invalid_slug']);
  exit;
}

$closeAt = null;
if ($closeAtRaw !== '') {
  try {
    $closeAt = new DateTime($closeAtRaw);
  } catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_close_at']);
    exit;
  }
  if ($closeAt <= new DateTime()) {
    http_response_code(400);
    echo json_encode(['error' => 'close_at_in_past']);
    exit;
  }
}

$pdo = db();

$check = $pdo->prepare('SELECT id FROM pm_markets WHERE slug = ? LIMIT 1');
$check->execute([$slug]);
if ($check->fetchColumn()) {
  http_response_code(409);
  echo json_encode(['error' => 'slug_exists']);
  exit;
}

$qYes = isset($payload['q_yes']) ? (float)$payload['q_yes'] : 0.0;
$qNo = isset($payload['q_no']) ? (float)$payload['q_no'] : 0.0;
if ($qYes < 0 || $qNo < 0) {
  http_response_code(400);
  echo json_encode(['error' => 'invalid_initial_liquidity']);
  exit;
}

$stmt = $pdo->prepare('INSERT INTO pm_markets (slug, title, description, status, close_at, resolved_outcome, b, q_yes, q_no, created_by)
                        VALUES (?, ?, ?, "open", ?, NULL, ?, ?, ?, ?)');
$stmt->execute([
  $slug,
  $title,
  $description,
  $closeAt ? $closeAt->format('Y-m-d H:i:s') : null,
  $bValue,
  $qYes,
  $qNo,
  current_user_id(),
]);

$marketId = (int)$pdo->lastInsertId();
$market = prediction_fetch_market_by_id($pdo, $marketId);

echo json_encode([
  'ok' => true,
  'market_id' => $marketId,
  'slug' => $slug,
  'market' => $market ? prediction_build_market_detail($pdo, $market, current_user_id()) : null,
]);