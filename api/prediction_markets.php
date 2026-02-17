<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/market_api_contract.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/prediction_market_service.php';

header('Content-Type: application/json');
market_emit_deprecation('/api/prediction_markets.php', '/api/markets/list.php');

$mode = isset($_GET['mode']) ? strtolower(trim((string)$_GET['mode'])) : '';

if ($mode === 'book') {
  $pdo = db();

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_login();
    require_admin();

    $payload = json_decode(file_get_contents('php://input'), true) ?: [];
    $title = trim((string)($payload['title'] ?? ''));
    $description = trim((string)($payload['description'] ?? ''));
    $category = trim((string)($payload['category'] ?? ''));
    $startsAtRaw = trim((string)($payload['starts_at'] ?? ''));
    $endsAtRaw = trim((string)($payload['ends_at'] ?? ''));
    $outcomesRaw = $payload['outcomes'] ?? [];

    if ($title === '' || $endsAtRaw === '') {
      http_response_code(400);
      echo json_encode(['error' => 'missing_fields']);
      exit;
    }

    try {
      $startsAt = $startsAtRaw !== '' ? new DateTime($startsAtRaw) : new DateTime();
      $endsAt = new DateTime($endsAtRaw);
    } catch (Exception $e) {
      http_response_code(400);
      echo json_encode(['error' => 'invalid_dates']);
      exit;
    }

    if ($endsAt <= $startsAt) {
      http_response_code(400);
      echo json_encode(['error' => 'invalid_date_range']);
      exit;
    }

    $normalizedOutcomes = array_values(array_unique(array_filter(array_map(function($item) {
      return trim((string)$item);
    }, is_array($outcomesRaw) ? $outcomesRaw : []))));

    if (!$normalizedOutcomes) {
      http_response_code(400);
      echo json_encode(['error' => 'missing_outcomes']);
      exit;
    }

    $now = new DateTime();
    $status = $startsAt <= $now ? 'running' : 'draft';

    try {
      $pdo->beginTransaction();
      $stmt = $pdo->prepare(
        'INSERT INTO prediction_markets (title, description, category, starts_at, ends_at, status, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
      );
      $stmt->execute([
        $title,
        $description !== '' ? $description : null,
        $category !== '' ? $category : null,
        $startsAt->format('Y-m-d H:i:s'),
        $endsAt->format('Y-m-d H:i:s'),
        $status,
        current_user_id(),
      ]);
      $marketId = (int)$pdo->lastInsertId();

      $outcomeStmt = $pdo->prepare('INSERT INTO prediction_outcomes (market_id, label) VALUES (?, ?)');
      foreach ($normalizedOutcomes as $label) {
        $outcomeStmt->execute([$marketId, $label]);
      }
      $pdo->commit();
    } catch (Exception $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      http_response_code(500);
      echo json_encode(['error' => 'market_create_failed']);
      exit;
    }

    echo json_encode([
      'ok' => true,
      'market_id' => $marketId,
    ]);
    exit;
  }

  $stmt = $pdo->query('SELECT * FROM prediction_markets ORDER BY ends_at ASC, id DESC');
  $markets = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $marketIds = array_map(fn($market) => (int)$market['id'], $markets);
  $outcomesByMarket = [];

  if ($marketIds) {
    $placeholders = implode(',', array_fill(0, count($marketIds), '?'));
    $outcomeStmt = $pdo->prepare(
      "SELECT id, market_id, label, payout, is_winner
       FROM prediction_outcomes
       WHERE market_id IN ({$placeholders})
       ORDER BY id ASC"
    );
    $outcomeStmt->execute($marketIds);
    while ($row = $outcomeStmt->fetch(PDO::FETCH_ASSOC)) {
      $marketId = (int)$row['market_id'];
      if (!isset($outcomesByMarket[$marketId])) {
        $outcomesByMarket[$marketId] = [];
      }
      $outcomesByMarket[$marketId][] = [
        'id' => (int)$row['id'],
        'label' => $row['label'],
        'payout' => (int)$row['payout'],
        'is_winner' => (bool)$row['is_winner'],
      ];
    }
  }

  $payload = array_map(function($market) use ($outcomesByMarket) {
    $marketId = (int)$market['id'];
    return [
      'id' => $marketId,
      'title' => $market['title'],
      'description' => $market['description'],
      'category' => $market['category'],
      'starts_at' => $market['starts_at'],
      'ends_at' => $market['ends_at'],
      'resolution_date' => $market['resolution_date'],
      'status' => $market['status'],
      'created_by' => (int)$market['created_by'],
      'created_at' => $market['created_at'],
      'outcomes' => $outcomesByMarket[$marketId] ?? [],
    ];
  }, $markets);

  echo json_encode([
    'markets' => $payload,
    'server_time' => gmdate('c'),
    'deprecation' => market_deprecation_payload('/api/prediction_markets.php', '/api/markets/list.php'),
  ]);
  exit;
}

$pdo = db();
$slug = isset($_GET['slug']) ? trim((string)$_GET['slug']) : '';
$userId = current_user_id();

if ($slug !== '') {
  $market = prediction_fetch_market_by_slug($pdo, $slug);
  if (!$market) {
    http_response_code(404);
    echo json_encode(['error' => 'market_not_found']);
    exit;
  }

  echo json_encode([
    'market' => prediction_build_market_detail($pdo, $market, $userId),
    'deprecation' => market_deprecation_payload('/api/prediction_markets.php', '/api/markets/get.php'),
  ]);
  exit;
}

echo json_encode([
  'markets' => prediction_list_markets($pdo, $userId),
  'server_time' => gmdate('c'),
  'deprecation' => market_deprecation_payload('/api/prediction_markets.php', '/api/markets/list.php'),
]);