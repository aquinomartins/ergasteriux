<?php

require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/ledger.php';
require_once __DIR__ . '/../../lib/market_accounts.php';
require_once __DIR__ . '/../../lib/market_http.php';
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

if ($marketId <= 0) {
  http_response_code(400);
  echo json_encode(['error' => 'invalid_market_id']);
  exit;
}

$pdo = db();
$pdo->beginTransaction();
try {
  $marketStmt = $pdo->prepare('SELECT status, outcome FROM markets WHERE id = ? FOR UPDATE');
  $marketStmt->execute([$marketId]);
  $market = $marketStmt->fetch(PDO::FETCH_ASSOC);
  if (!$market) {
    throw new RuntimeException('market_not_found');
  }
  if ($market['status'] !== 'resolved') {
    throw new RuntimeException('market_not_resolved');
  }

  $userId = current_user_id();
  $claimStmt = $pdo->prepare('SELECT id, shares_won, amount, status FROM claims WHERE market_id = ? AND user_id = ? FOR UPDATE');
  $claimStmt->execute([$marketId, $userId]);
  $claim = $claimStmt->fetch(PDO::FETCH_ASSOC);
  if (!$claim) {
    throw new RuntimeException('claim_not_found');
  }
  if ($claim['status'] === 'paid') {
    throw new RuntimeException('already_paid');
  }

  $amount = (float)$claim['amount'];
  if ($amount <= 0) {
    throw new RuntimeException('invalid_claim');
  }

  $userCashId = market_get_or_create_user_cash_account($pdo, $userId);
  $marketCashId = market_get_or_create_market_account($pdo, $marketId, 'market_cash');
  $marketCollateralId = market_get_or_create_market_account($pdo, $marketId, 'market_collateral');

  $cashBalance = market_get_balance($pdo, $marketCashId);
  $collateralBalance = market_get_balance($pdo, $marketCollateralId);
  if ($cashBalance + $collateralBalance + 1e-8 < $amount) {
    throw new RuntimeException('insufficient_market_balance');
  }

  $fromCash = min($cashBalance, $amount);
  $fromCollateral = round($amount - $fromCash, 8);

  $legs = [
    ['account_id' => $userCashId, 'debit' => round($amount, 8)], // entrada no caixa do usuário (payout)
  ];
  if ($fromCash > 0) {
    $legs[] = ['account_id' => $marketCashId, 'credit' => round($fromCash, 8)]; // saída do caixa do mercado
  }
  if ($fromCollateral > 0) {
    $legs[] = ['account_id' => $marketCollateralId, 'credit' => round($fromCollateral, 8)]; // uso do colateral travado
  }

  post_journal('prediction_trade', (int)$claim['id'], 'Payout LMSR', $legs);
  adjust_special_liquidity_assets($pdo, $userId, ['brl' => $amount]);

  $upd = $pdo->prepare("UPDATE claims SET status = 'paid', paid_at = ? WHERE id = ?");
  $upd->execute([(new DateTime())->format('Y-m-d H:i:s'), (int)$claim['id']]);

  $pdo->commit();
  echo json_encode(['ok' => true, 'amount' => round($amount, 8)]);
} catch (RuntimeException $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  $message = $e->getMessage();
  $status = 400;
  if ($message === 'market_not_found') {
    $status = 404;
  } elseif ($message === 'market_not_resolved') {
    $status = 409;
  } elseif ($message === 'insufficient_market_balance') {
    $status = 422;
  }
  http_response_code($status);
  echo json_encode(['error' => $message]);
} catch (Exception $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  http_response_code(500);
  echo json_encode(['error' => 'claim_failed']);
}