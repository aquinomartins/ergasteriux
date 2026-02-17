<?php

require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/ledger.php';
require_once __DIR__ . '/../../lib/market_accounts.php';
require_once __DIR__ . '/../../lib/market_http.php';
require_once __DIR__ . '/../../lib/market_service.php';
require_once __DIR__ . '/../../lib/market_api_contract.php';
require_once __DIR__ . '/../../lib/special_liquidity_user.php';

require_login();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['error' => 'method_not_allowed']);
  exit;
}

$payload = market_read_json();
$marketId = isset($payload['market_id']) ? (int)$payload['market_id'] : market_read_id();
$sideInput = (string)($payload['side'] ?? '');
$side = market_side_to_legacy($sideInput) ?? strtoupper(trim($sideInput));
$shares = isset($payload['shares']) ? (int)$payload['shares'] : 0;
$idempotencyKey = trim((string)($payload['idempotency_key'] ?? ''));

if ($marketId <= 0 || !in_array($side, ['SIM', 'NAO'], true) || $shares <= 0 || $shares > 1000) {
  http_response_code(400);
  echo json_encode(['error' => 'invalid_payload']);
  exit;
}


if ($idempotencyKey !== '' && strlen($idempotencyKey) > 128) {
  http_response_code(400);
  echo json_encode(['error' => 'invalid_idempotency_key']);
  exit;
}
$pdo = db();
$balanceContext = null;
$specialBalanceBefore = 0.0;
$balanceSource = 'entries';
$pdo->beginTransaction();
market_ensure_trade_idempotency_table($pdo);
try {
  $userId = current_user_id();
  $market = market_fetch($pdo, $marketId, true);

  if ($idempotencyKey !== '') {
    $idemStmt = $pdo->prepare('SELECT trade_id FROM market_trade_idempotency WHERE user_id = ? AND market_id = ? AND idempotency_key = ? LIMIT 1 FOR UPDATE');
    $idemStmt->execute([$userId, $marketId, $idempotencyKey]);
    $idem = $idemStmt->fetch(PDO::FETCH_ASSOC);
    if ($idem && !empty($idem['trade_id'])) {
      $tradeLookup = $pdo->prepare('SELECT id, side, delta_cost, fee, total_paid, p_sim_before, p_sim_after, q_sim_after, q_nao_after, created_at FROM market_trades WHERE id = ? AND user_id = ? AND market_id = ? LIMIT 1');
      $tradeLookup->execute([(int)$idem['trade_id'], $userId, $marketId]);
      $existingTrade = $tradeLookup->fetch(PDO::FETCH_ASSOC);
      if ($existingTrade) {
        $pdo->commit();
        $existingResponse = [
          'ok' => true,
          'trade_id' => (int)$existingTrade['id'],
          'side' => strtolower($existingTrade['side'] === 'SIM' ? 'yes' : 'no'),
          'side_legacy' => $existingTrade['side'],
          'delta_cost' => (float)$existingTrade['delta_cost'],
          'fee' => (float)$existingTrade['fee'],
          'total_paid' => (float)$existingTrade['total_paid'],
          'p_sim_before' => (float)$existingTrade['p_sim_before'],
          'p_sim_after' => (float)$existingTrade['p_sim_after'],
          'q_sim' => (int)$existingTrade['q_sim_after'],
          'q_nao' => (int)$existingTrade['q_nao_after'],
          'idempotent_replay' => true,
        ];
        echo json_encode(market_contract_aliases($existingResponse));
        exit;
      }
    }
  }
  if (!$market) {
    throw new RuntimeException('market_not_found');
  }
  if ($market['status'] !== 'open') {
    throw new RuntimeException('market_closed');
  }

  $quote = market_quote($market, $side, $shares);
  if ($quote['delta_cost'] <= 0) {
    throw new RuntimeException('invalid_trade');
  }

  $userCashId = market_get_or_create_user_cash_account($pdo, $userId);
  $lockAcc = $pdo->prepare('SELECT id FROM accounts WHERE id = ? FOR UPDATE');
  $lockAcc->execute([$userCashId]);
  $ledgerBalance = market_get_balance($pdo, $userCashId);
  $specialAssets = get_special_liquidity_assets($pdo, $userId);
  $specialBalanceBefore = isset($specialAssets['brl']) ? (float)$specialAssets['brl'] : 0.0;

  $availableBalance = $ledgerBalance;
  if ($specialBalanceBefore > $availableBalance) {
    $availableBalance = $specialBalanceBefore;
    $balanceSource = 'special_liquidity_assets';
  }

  if ($availableBalance + 1e-8 < $quote['total']) {
    $balanceContext = [
      'available_brl' => round($availableBalance, 8),
      'required_brl' => round((float)$quote['total'], 8),
      'accounting_source' => $balanceSource,
      'entries_balance_brl' => round($ledgerBalance, 8),
      'special_assets_balance_brl' => round($specialBalanceBefore, 8),
    ];
    throw new RuntimeException('insufficient_balance');
  }

  $marketCashId = market_get_or_create_market_account($pdo, $marketId, 'market_cash');
  $marketFeesId = market_get_or_create_market_account($pdo, $marketId, 'market_fees');

  $tradeStmt = $pdo->prepare(
    "INSERT INTO market_trades (market_id, user_id, side, shares, delta_cost, fee, total_paid, p_sim_before, p_sim_after, q_sim_before, q_nao_before, q_sim_after, q_nao_after, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
  );
  $tradeStmt->execute([
    $marketId,
    $userId,
    $side,
    $shares,
    $quote['delta_cost'],
    $quote['fee'],
    $quote['total'],
    $quote['p_sim_before'],
    $quote['p_sim_after'],
    (int)$market['q_sim'],
    (int)$market['q_nao'],
    $quote['q_sim_after'],
    $quote['q_nao_after'],
    (new DateTime())->format('Y-m-d H:i:s'),
  ]);
  $tradeId = (int)$pdo->lastInsertId();

  $posStmt = $pdo->prepare('SELECT shares, avg_cost FROM market_positions WHERE market_id = ? AND user_id = ? AND side = ? FOR UPDATE');
  $posStmt->execute([$marketId, $userId, $side]);
  $position = $posStmt->fetch(PDO::FETCH_ASSOC);

  $prevShares = $position ? (int)$position['shares'] : 0;
  $prevAvg = $position ? (float)$position['avg_cost'] : 0.0;
  $newShares = $prevShares + $shares;
  $newAvg = $newShares > 0 ? (($prevAvg * $prevShares) + $quote['delta_cost']) / $newShares : 0.0;

  if ($position) {
    $upd = $pdo->prepare('UPDATE market_positions SET shares = ?, avg_cost = ?, updated_at = ? WHERE market_id = ? AND user_id = ? AND side = ?');
    $upd->execute([$newShares, round($newAvg, 8), (new DateTime())->format('Y-m-d H:i:s'), $marketId, $userId, $side]);
  } else {
    $ins = $pdo->prepare('INSERT INTO market_positions (market_id, user_id, side, shares, avg_cost, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $now = (new DateTime())->format('Y-m-d H:i:s');
    $ins->execute([$marketId, $userId, $side, $newShares, round($newAvg, 8), $now, $now]);
  }

  $stateStmt = $pdo->prepare('UPDATE market_state SET q_sim = ?, q_nao = ?, cost_total = ?, updated_at = ? WHERE market_id = ?');
  $stateStmt->execute([
    $quote['q_sim_after'],
    $quote['q_nao_after'],
    round(lmsr_cost($quote['q_sim_after'], $quote['q_nao_after'], (float)$market['b']), 8),
    (new DateTime())->format('Y-m-d H:i:s'),
    $marketId,
  ]);

  // Lançamentos contábeis: o usuário paga total, o mercado recebe o custo e a taxa separada.
  post_journal('prediction_trade', $tradeId, 'Compra LMSR', [
    ['account_id' => $marketCashId, 'debit' => round($quote['delta_cost'], 8)], // entrada no caixa do mercado
    ['account_id' => $marketFeesId, 'debit' => round($quote['fee'], 8)], // taxa contabilizada separadamente
    ['account_id' => $userCashId, 'credit' => round($quote['total'], 8)], // saída do caixa do usuário
  ]);

  if ($balanceSource === 'special_liquidity_assets') {
    adjust_special_liquidity_assets($pdo, $userId, ['brl' => -round((float)$quote['total'], 2)]);
  }

  if ($idempotencyKey !== '') {
    $idemWrite = $pdo->prepare('INSERT INTO market_trade_idempotency (user_id, market_id, idempotency_key, trade_id, created_at) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE trade_id = VALUES(trade_id)');
    $idemWrite->execute([$userId, $marketId, $idempotencyKey, $tradeId, (new DateTime())->format('Y-m-d H:i:s')]);
  }

  $pdo->commit();
  $response = [
    'ok' => true,
    'trade_id' => $tradeId,
    'side' => strtolower($side === 'SIM' ? 'yes' : 'no'),
    'side_legacy' => $side,
    'delta_cost' => $quote['delta_cost'],
    'fee' => $quote['fee'],
    'total_paid' => $quote['total'],
    'p_sim_before' => $quote['p_sim_before'],
    'p_sim_after' => $quote['p_sim_after'],
    'q_sim' => $quote['q_sim_after'],
    'q_nao' => $quote['q_nao_after'],
    'balance_source' => $balanceSource,
    'balance_brl_before' => round($balanceSource === 'special_liquidity_assets' ? $specialBalanceBefore : $ledgerBalance, 8),
    'balance_brl_after' => round(($balanceSource === 'special_liquidity_assets' ? $specialBalanceBefore : $ledgerBalance) - (float)$quote['total'], 8),
  ];
  echo json_encode(market_contract_aliases($response));
} catch (RuntimeException $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  $message = $e->getMessage();
  $status = 400;
  if ($message === 'market_not_found') {
    $status = 404;
  } elseif ($message === 'market_closed') {
    $status = 409;
  } elseif ($message === 'insufficient_balance') {
    $status = 422;
  }
  http_response_code($status);
  if ($message === 'insufficient_balance') {
    echo json_encode([
      'error' => $message,
      'balance_source' => $balanceSource,
      'details' => $balanceContext,
    ]);
  } else {
    echo json_encode(['error' => $message]);
  }
} catch (Exception $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  http_response_code(500);
  echo json_encode(['error' => 'trade_failed']);
}
