<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/ledger.php';
require_once __DIR__ . '/../lib/special_liquidity_user.php';
require_once __DIR__ . '/../lib/util.php';
require_login();

$pdo = db();
$uid = current_user_id();

const PREDICTION_CONTRACT_VALUE = 100.0;

function fetch_prediction_outcome(PDO $pdo, int $outcomeId): ?array {
  $stmt = $pdo->prepare('SELECT id, market_id, label FROM prediction_outcomes WHERE id=? LIMIT 1');
  $stmt->execute([$outcomeId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function ensure_prediction_outcome_asset(PDO $pdo, array $outcome): void {
  $assetId = (int)($outcome['id'] ?? 0);
  if ($assetId <= 0) {
    return;
  }

  $check = $pdo->prepare('SELECT id FROM assets WHERE id = ? LIMIT 1');
  $check->execute([$assetId]);
  if ($check->fetchColumn()) {
    return;
  }

  $marketId = (int)($outcome['market_id'] ?? 0);
  $label = (string)($outcome['label'] ?? '');
  $symbol = sprintf('PRED-%d-%d', $marketId > 0 ? $marketId : 0, $assetId);
  $metadata = json_encode([
    'prediction_market_id' => $marketId,
    'prediction_outcome_label' => $label
  ], JSON_UNESCAPED_UNICODE);

  $stmt = $pdo->prepare("INSERT INTO assets (id, type, symbol, parent_asset_id, metadata_json) VALUES (?, 'share', ?, NULL, ?)");
  $stmt->execute([$assetId, $symbol, $metadata]);
}

function fetch_cash_account_id(PDO $pdo, int $userId): ?int {
  $stmt = $pdo->prepare("SELECT id FROM accounts WHERE owner_type='user' AND owner_id=? AND purpose='cash' AND currency='BRL' LIMIT 1");
  $stmt->execute([$userId]);
  $id = $stmt->fetchColumn();
  return $id ? intval($id) : null;
}

function fetch_inventory_account_id(PDO $pdo, int $userId): ?int {
  $stmt = $pdo->prepare("SELECT id FROM accounts WHERE owner_type='user' AND owner_id=? AND purpose='nft_inventory' LIMIT 1");
  $stmt->execute([$userId]);
  $id = $stmt->fetchColumn();
  return $id ? intval($id) : null;
}

function fetch_special_brl_balance(PDO $pdo, int $userId): float {
  $assets = get_special_liquidity_assets($pdo, $userId);
  return isset($assets['brl']) ? (float)$assets['brl'] : 0.0;
}

function fetch_market_cash_account(PDO $pdo): ?int {
  $marketUserId = ensure_market_system_user($pdo);
  return fetch_cash_account_id($pdo, $marketUserId);
}

function clamp_value(float $value, float $min, float $max): float {
  return min(max($value, $min), $max);
}

function fetch_best_order_price(PDO $pdo, int $outcomeId, string $side, string $order): ?float {
  $stmt = $pdo->prepare("SELECT price FROM orders WHERE status='open' AND asset_id=? AND side=? ORDER BY price {$order}, id ASC LIMIT 1");
  $stmt->execute([$outcomeId, $side]);
  $price = $stmt->fetchColumn();
  return $price !== false ? (float)$price : null;
}

function fetch_outcome_mid_price(PDO $pdo, int $outcomeId): ?float {
  $bestBid = fetch_best_order_price($pdo, $outcomeId, 'buy', 'DESC');
  $bestAsk = fetch_best_order_price($pdo, $outcomeId, 'sell', 'ASC');
  $midPrice = null;
  if (is_finite($bestBid) && is_finite($bestAsk)) {
    $midPrice = round((($bestBid + $bestAsk) / 2), 8);
  } elseif (is_finite($bestBid)) {
    $midPrice = $bestBid;
  } elseif (is_finite($bestAsk)) {
    $midPrice = $bestAsk;
  }
  if (!is_finite($midPrice) || $midPrice <= 0) {
    return null;
  }
  return $midPrice;
}

function fetch_market_outcomes(PDO $pdo, int $marketId): array {
  $stmt = $pdo->prepare('SELECT id FROM prediction_outcomes WHERE market_id=? ORDER BY id ASC');
  $stmt->execute([$marketId]);
  return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

function build_prediction_market_snapshot(PDO $pdo, int $marketId): array {
  $contractValue = PREDICTION_CONTRACT_VALUE;
  $outcomeIds = fetch_market_outcomes($pdo, $marketId);
  $prices = [];
  foreach ($outcomeIds as $outcomeId) {
    $prices[$outcomeId] = fetch_outcome_mid_price($pdo, $outcomeId);
  }

  $probabilities = [];
  $outcomeCount = count($outcomeIds);
  if ($outcomeCount === 2) {
    $firstId = $outcomeIds[0];
    $secondId = $outcomeIds[1];
    $priceA = $prices[$firstId] ?? null;
    $priceB = $prices[$secondId] ?? null;
    if (!is_finite($priceA) && !is_finite($priceB)) {
      $probabilities[$firstId] = 0.5;
      $probabilities[$secondId] = 0.5;
    } else {
      if (!is_finite($priceA) && is_finite($priceB)) {
        $priceA = clamp_value($contractValue - $priceB, 0, $contractValue);
      }
      if (!is_finite($priceB) && is_finite($priceA)) {
        $priceB = clamp_value($contractValue - $priceA, 0, $contractValue);
      }
      $prices[$firstId] = $priceA;
      $prices[$secondId] = $priceB;
      $probA = is_finite($priceA) ? clamp_value($priceA / $contractValue, 0, 1) : 0.5;
      $probabilities[$firstId] = $probA;
      $probabilities[$secondId] = 1 - $probA;
    }
  } elseif ($outcomeCount > 0) {
    $sum = 0.0;
    foreach ($prices as $price) {
      if (is_finite($price) && $price > 0) {
        $sum += $price;
      }
    }
    if ($sum <= 0) {
      $equalProb = 1 / $outcomeCount;
      foreach ($outcomeIds as $outcomeId) {
        $probabilities[$outcomeId] = $equalProb;
      }
    } else {
      foreach ($outcomeIds as $outcomeId) {
        $price = $prices[$outcomeId];
        $probabilities[$outcomeId] = is_finite($price) && $price > 0 ? $price / $sum : 0.0;
      }
    }
  }

  $snapshotOutcomes = array_map(function($outcomeId) use ($prices, $probabilities) {
    return [
      'id' => $outcomeId,
      'market_price' => $prices[$outcomeId] ?? null,
      'probability' => $probabilities[$outcomeId] ?? null,
    ];
  }, $outcomeIds);

  return [
    'market_id' => $marketId,
    'contract_value' => $contractValue,
    'outcomes' => $snapshotOutcomes,
  ];
}

function build_prediction_quote(PDO $pdo, int $marketId, int $selectedOutcomeId, float $fallbackPrice, float $qty): array {
  $contractValue = PREDICTION_CONTRACT_VALUE;
  $outcomeIds = fetch_market_outcomes($pdo, $marketId);
  $prices = [];
  foreach ($outcomeIds as $outcomeId) {
    $price = fetch_outcome_mid_price($pdo, $outcomeId);
    if (!is_finite($price) && $outcomeId === $selectedOutcomeId) {
      $price = $fallbackPrice;
    }
    $prices[$outcomeId] = $price;
  }

  $probabilities = [];
  $outcomeCount = count($outcomeIds);
  if ($outcomeCount === 2) {
    $firstId = $outcomeIds[0];
    $secondId = $outcomeIds[1];
    $priceA = $prices[$firstId] ?? null;
    $priceB = $prices[$secondId] ?? null;
    if (!is_finite($priceA) && !is_finite($priceB)) {
      $probabilities[$firstId] = 0.5;
      $probabilities[$secondId] = 0.5;
    } else {
      if (!is_finite($priceA) && is_finite($priceB)) {
        $priceA = clamp_value($contractValue - $priceB, 0, $contractValue);
      }
      if (!is_finite($priceB) && is_finite($priceA)) {
        $priceB = clamp_value($contractValue - $priceA, 0, $contractValue);
      }
      $probA = is_finite($priceA) ? clamp_value($priceA / $contractValue, 0, 1) : 0.5;
      $probabilities[$firstId] = $probA;
      $probabilities[$secondId] = 1 - $probA;
    }
  } elseif ($outcomeCount > 0) {
    $sum = 0.0;
    foreach ($prices as $price) {
      if (is_finite($price) && $price > 0) {
        $sum += $price;
      }
    }
    if ($sum <= 0) {
      $equalProb = 1 / $outcomeCount;
      foreach ($outcomeIds as $outcomeId) {
        $probabilities[$outcomeId] = $equalProb;
      }
    } else {
      foreach ($outcomeIds as $outcomeId) {
        $price = $prices[$outcomeId];
        $probabilities[$outcomeId] = is_finite($price) && $price > 0 ? $price / $sum : 0.0;
      }
    }
  }

  $selectedProbability = $probabilities[$selectedOutcomeId] ?? null;
  $marketPrice = $prices[$selectedOutcomeId] ?? null;
  $totalCost = round($qty * $fallbackPrice, 8);

  return [
    'pricing_model' => 'orderbook_mid_price',
    'contract_value' => $contractValue,
    'unit_price' => $fallbackPrice,
    'market_price' => $marketPrice,
    'total_cost' => $totalCost,
    'probability' => $selectedProbability,
    'probabilities' => $probabilities,
  ];
}

if($_SERVER['REQUEST_METHOD']==='GET'){
  $market_id = isset($_GET['market_id']) ? intval($_GET['market_id']) : null;
  $outcome_id = isset($_GET['outcome_id']) ? intval($_GET['outcome_id']) : null;

  if ($market_id && !$outcome_id) {
    $outcomes = fetch_market_outcomes($pdo, $market_id);
    if (!$outcomes) {
      http_response_code(404);
      echo json_encode(['error' => 'market_not_found']);
      exit;
    }
    echo json_encode(build_prediction_market_snapshot($pdo, $market_id));
    exit;
  }

  if(!$outcome_id){
    http_response_code(400);
    echo json_encode(['error' => 'outcome_required']);
    exit;
  }

  $outcome = fetch_prediction_outcome($pdo, $outcome_id);
  if(!$outcome){
    http_response_code(404);
    echo json_encode(['error' => 'outcome_not_found']);
    exit;
  }
  ensure_prediction_outcome_asset($pdo, $outcome);
  if($market_id && intval($outcome['market_id']) !== $market_id){
    http_response_code(404);
    echo json_encode(['error' => 'outcome_market_mismatch']);
    exit;
  }

  $sql = "SELECT * FROM orders WHERE status='open' AND asset_id=? ORDER BY price DESC, id ASC";
  $st = $pdo->prepare($sql);
  $st->execute([$outcome_id]);
  echo json_encode($st->fetchAll());
  exit;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
  $d = json_decode(file_get_contents('php://input'), true);
  $side = $d['side'] ?? '';
  $outcome_id = isset($d['outcome_id']) ? intval($d['outcome_id']) : null;
  $market_id = isset($d['market_id']) ? intval($d['market_id']) : null;
  $qty = floatval($d['qty'] ?? 0);
  $price = floatval($d['price'] ?? 0);
  $immediate_or_cancel = !empty($d['immediate_or_cancel']);
  $preview = !empty($d['preview']);

  if(!in_array($side, ['buy','sell']) || $qty<=0 || $price<=0){
    http_response_code(400);
    echo json_encode(['error'=>'invalid_order']);
    exit;
  }
  if(!$outcome_id){
    http_response_code(400);
    echo json_encode(['error'=>'outcome_required']);
    exit;
  }

  $outcome = fetch_prediction_outcome($pdo, $outcome_id);
  if(!$outcome){
    http_response_code(404);
    echo json_encode(['error' => 'outcome_not_found']);
    exit;
  }
  ensure_prediction_outcome_asset($pdo, $outcome);
  if($market_id && intval($outcome['market_id']) !== $market_id){
    http_response_code(404);
    echo json_encode(['error' => 'outcome_market_mismatch']);
    exit;
  }

  if ($preview) {
    if ($side !== 'buy') {
      http_response_code(400);
      echo json_encode(['error' => 'preview_only_for_buy']);
      exit;
    }
    if (!$market_id) {
      http_response_code(400);
      echo json_encode(['error' => 'market_required']);
      exit;
    }
    $quote = build_prediction_quote($pdo, $market_id, $outcome_id, $price, $qty);
    echo json_encode(['ok' => true, 'preview' => true] + $quote);
    exit;
  }

  if ($side === 'sell') {
    $ownershipStmt = $pdo->prepare("SELECT p.qty FROM positions p WHERE p.owner_type='user' AND p.owner_id=? AND p.asset_id=? LIMIT 1");
    $ownershipStmt->execute([$uid, $outcome_id]);
    $ownedQty = floatval($ownershipStmt->fetchColumn());
    if ($ownedQty <= 0) {
      http_response_code(403);
      echo json_encode(['error' => 'insufficient_outcome_qty']);
      exit;
    }
    if ($qty - $ownedQty > 0.00000001) {
      http_response_code(400);
      echo json_encode(['error' => 'insufficient_outcome_qty']);
      exit;
    }
  }

  ensure_user_accounts($uid);
  $buyerCashId = fetch_cash_account_id($pdo, $uid);
  if (!$buyerCashId) {
    http_response_code(500);
    echo json_encode(['error' => 'cash_account_missing']);
    exit;
  }
  $marketCashId = fetch_market_cash_account($pdo);
  if (!$marketCashId) {
    http_response_code(500);
    echo json_encode(['error' => 'market_cash_account_missing']);
    exit;
  }
  $marketUserId = MERCADO_PREDITIVO_USER_ID;

  if ($side === 'buy') {
    $balance = fetch_special_brl_balance($pdo, $uid);
    $required = round($qty * $price, 8);
    if ($balance + 1e-8 < $required) {
      http_response_code(400);
      echo json_encode(['error' => 'insufficient_brl']);
      exit;
    }
  }

  if ($side === 'buy') {
    $required = round($qty * $price, 8);
    $pdo->beginTransaction();
    try {
      $balance = fetch_special_brl_balance($pdo, $uid);
      if ($balance + 1e-8 < $required) {
        throw new Exception('insufficient_brl');
      }

      $st = $pdo->prepare("INSERT INTO orders(user_id, side, asset_id, asset_instance_id, qty, price, status) VALUES (?,?,?,?,?,?, 'open')");
      $st->execute([$uid, $side, $outcome_id, null, $qty, $price]);
      $order_id = intval($pdo->lastInsertId());

      $jid = post_journal('prediction_trade', $order_id, 'Compra de aposta previsao', [
        ['account_id' => $marketCashId, 'debit' => $required],
        ['account_id' => $buyerCashId, 'credit' => $required],
      ]);

      adjust_special_liquidity_assets($pdo, $uid, ['brl' => -$required]);
      adjust_special_liquidity_assets($pdo, $marketUserId, ['brl' => $required]);

      $pdo->commit();
    } catch (Exception $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      if ($e->getMessage() === 'insufficient_brl') {
        http_response_code(400);
        echo json_encode(['error' => 'insufficient_brl']);
        exit;
      }
      http_response_code(400);
      echo json_encode(['error' => 'order_create_failed', 'detail' => $e->getMessage()]);
      exit;
    }
  } else {
    $st = $pdo->prepare("INSERT INTO orders(user_id, side, asset_id, asset_instance_id, qty, price, status) VALUES (?,?,?,?,?,?, 'open')");
    $st->execute([$uid, $side, $outcome_id, null, $qty, $price]);
    $order_id = intval($pdo->lastInsertId());
  }

  $remaining = $qty;
  while ($remaining > 0.00000001) {
    if ($side === 'buy') {
      $q = "SELECT * FROM orders WHERE status='open' AND side='sell' AND price<=? AND asset_id=? ORDER BY price ASC, id ASC LIMIT 1";
      $params = [$price, $outcome_id];
    } else {
      $q = "SELECT * FROM orders WHERE status='open' AND side='buy' AND price>=? AND asset_id=? ORDER BY price DESC, id ASC LIMIT 1";
      $params = [$price, $outcome_id];
    }
    $stM = $pdo->prepare($q);
    $stM->execute($params);
    $match = $stM->fetch();
    if(!$match) break;

    $match_qty = min($remaining, floatval($match['qty']));
    $exec_price = floatval($match['price']);
    $total = round($match_qty * $exec_price, 8);

    $pdo->beginTransaction();
    try{
      ensure_user_accounts($uid);
      ensure_user_accounts(intval($match['user_id']));

      $buyer_id = ($side === 'buy') ? $uid : intval($match['user_id']);
      $seller_id = ($side === 'buy') ? intval($match['user_id']) : $uid;

      $buyer_cash = fetch_cash_account_id($pdo, $buyer_id);
      $seller_cash = fetch_cash_account_id($pdo, $seller_id);
      $buyer_inv = fetch_inventory_account_id($pdo, $buyer_id);
      $seller_inv = fetch_inventory_account_id($pdo, $seller_id);

      if (!$buyer_cash || !$seller_cash || !$buyer_inv || !$seller_inv) {
        throw new Exception('account_missing');
      }

      $jid = post_journal('prediction_trade', null, 'Liquidacao de trade previsao', [
        ['account_id' => $seller_cash, 'debit' => $total],
        ['account_id' => $marketCashId, 'credit' => $total],
      ]);

      adjust_special_liquidity_assets($pdo, $marketUserId, ['brl' => -$total]);
      adjust_special_liquidity_assets($pdo, $seller_id, ['brl' => $total]);

      $stmtMV = $pdo->prepare("INSERT INTO asset_moves(journal_id, asset_id, asset_instance_id, qty, from_account_id, to_account_id)
                               VALUES (?,?,?,?,?,?)");
      $stmtMV->execute([$jid, $outcome_id, null, $match_qty, $seller_inv, $buyer_inv]);

      $new_match_qty = floatval($match['qty']) - $match_qty;
      if ($new_match_qty <= 0.00000001) {
        $pdo->prepare("UPDATE orders SET qty=0, status='filled' WHERE id=?")
            ->execute([intval($match['id'])]);
      } else {
        $pdo->prepare("UPDATE orders SET qty=? WHERE id=?")
            ->execute([$new_match_qty, intval($match['id'])]);
      }

      if ($side === 'buy') {
        $pdo->prepare("INSERT INTO trades(buy_order_id, sell_order_id, qty, price, journal_id) VALUES (?,?,?,?,?)")
            ->execute([$order_id, intval($match['id']), $match_qty, $exec_price, $jid]);
      } else {
        $pdo->prepare("INSERT INTO trades(buy_order_id, sell_order_id, qty, price, journal_id) VALUES (?,?,?,?,?)")
            ->execute([intval($match['id']), $order_id, $match_qty, $exec_price, $jid]);
      }

      if ($side === 'buy') {
        $refund = round(($price - $exec_price) * $match_qty, 8);
        if ($refund > 0.00000001) {
          post_journal('prediction_trade', $order_id, 'Reembolso diferenca preco previsao', [
            ['account_id' => $buyer_cash, 'debit' => $refund],
            ['account_id' => $marketCashId, 'credit' => $refund],
          ]);
          adjust_special_liquidity_assets($pdo, $uid, ['brl' => $refund]);
          adjust_special_liquidity_assets($pdo, $marketUserId, ['brl' => -$refund]);
        }
      }

      $pdo->commit();
      $remaining = round($remaining - $match_qty, 8);
    } catch(Exception $e){
      $pdo->rollBack();
      http_response_code(400);
      echo json_encode(['error'=>'settlement_failed','detail'=>$e->getMessage()]);
      exit;
    }
  }

  $filledSomething = ($remaining < $qty);
  if ($filledSomething) {
    if ($remaining <= 0.00000001) {
      $pdo->prepare("UPDATE orders SET qty=0, status='filled' WHERE id=?")
          ->execute([$order_id]);
      $remaining = 0;
    } else {
      $pdo->prepare("UPDATE orders SET qty=? WHERE id=?")
          ->execute([$remaining, $order_id]);
    }
  }

  if ($immediate_or_cancel && $remaining > 0.00000001) {
    if ($side === 'buy') {
      $refund = round($remaining * $price, 8);
      $pdo->beginTransaction();
      try {
        post_journal('prediction_trade', $order_id, 'Cancelamento aposta previsao', [
          ['account_id' => $buyerCashId, 'debit' => $refund],
          ['account_id' => $marketCashId, 'credit' => $refund],
        ]);
        adjust_special_liquidity_assets($pdo, $uid, ['brl' => $refund]);
        adjust_special_liquidity_assets($pdo, $marketUserId, ['brl' => -$refund]);
        $pdo->prepare("UPDATE orders SET qty=0, status='cancelled' WHERE id=?")
            ->execute([$order_id]);
        $pdo->commit();
      } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['error' => 'refund_failed', 'detail' => $e->getMessage()]);
        exit;
      }
    } else {
      $pdo->prepare("UPDATE orders SET qty=0, status='cancelled' WHERE id=?")
          ->execute([$order_id]);
    }
    http_response_code(409);
    echo json_encode(['error' => 'not_filled', 'filled' => $qty - $remaining]);
    exit;
  }

  $updatedBalance = fetch_special_brl_balance($pdo, $uid);
  echo json_encode([
    'ok' => true,
    'order_id' => $order_id,
    'remaining' => $remaining,
    'brl_balance' => $updatedBalance
  ]);
  exit;
}

http_response_code(405);
echo json_encode(['error' => 'method_not_allowed']);