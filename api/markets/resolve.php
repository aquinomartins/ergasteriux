<?php

require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/ledger.php';
require_once __DIR__ . '/../../lib/market_accounts.php';
require_once __DIR__ . '/../../lib/market_http.php';
require_once __DIR__ . '/../../lib/special_liquidity_user.php';
require_once __DIR__ . '/../../lib/market_api_contract.php';

require_login();
require_admin();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['error' => 'method_not_allowed']);
  exit;
}

$payload = market_read_json();
$marketId = isset($payload['market_id']) ? (int)$payload['market_id'] : market_read_id();
$outcomeInput = (string)($payload['outcome'] ?? '');
$outcome = market_side_to_legacy($outcomeInput) ?? strtoupper(trim($outcomeInput));

if ($marketId <= 0 || !in_array($outcome, ['SIM', 'NAO'], true)) {
  http_response_code(400);
  echo json_encode(['error' => 'invalid_payload']);
  exit;
}

$pdo = db();
$pdo->beginTransaction();
try {
  $stmt = $pdo->prepare('SELECT status FROM markets WHERE id = ? FOR UPDATE');
  $stmt->execute([$marketId]);
  $market = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$market) {
    throw new RuntimeException('market_not_found');
  }
  if ($market['status'] !== 'open') {
    throw new RuntimeException('market_not_open');
  }

  $resolvedAt = (new DateTime())->format('Y-m-d H:i:s');
  $upd = $pdo->prepare("UPDATE markets SET status = 'resolved', outcome = ?, resolved_at = ? WHERE id = ?");
  $upd->execute([$outcome, $resolvedAt, $marketId]);

  $posStmt = $pdo->prepare('SELECT user_id, shares FROM market_positions WHERE market_id = ? AND side = ?');
  $posStmt->execute([$marketId, $outcome]);
  $insert = $pdo->prepare(
    "INSERT INTO claims (market_id, user_id, shares_won, amount, status, created_at) VALUES (?, ?, ?, ?, 'pending', ?) ON DUPLICATE KEY UPDATE shares_won = IF(status = 'pending', VALUES(shares_won), shares_won), amount = IF(status = 'pending', VALUES(amount), amount)"
  );

  while ($row = $posStmt->fetch(PDO::FETCH_ASSOC)) {
    $sharesWon = (int)$row['shares'];
    $amount = round($sharesWon * 1.0, 8);
    $insert->execute([$marketId, (int)$row['user_id'], $sharesWon, $amount, $resolvedAt]);
  }

  $claimsStmt = $pdo->prepare('SELECT id, user_id, amount, status FROM claims WHERE market_id = ? AND status = ? FOR UPDATE');
  $claimsStmt->execute([$marketId, 'pending']);
  $claims = $claimsStmt->fetchAll(PDO::FETCH_ASSOC);

  $totalPayout = 0.0;
  foreach ($claims as $claim) {
    $totalPayout += (float)$claim['amount'];
  }

  if ($totalPayout > 0) {
    $marketCashId = market_get_or_create_market_account($pdo, $marketId, 'market_cash');
    $marketCollateralId = market_get_or_create_market_account($pdo, $marketId, 'market_collateral');

    $remainingCash = market_get_balance($pdo, $marketCashId);
    $remainingCollateral = market_get_balance($pdo, $marketCollateralId);
    if ($remainingCash + $remainingCollateral + 1e-8 < $totalPayout) {
      throw new RuntimeException('insufficient_market_balance');
    }

    $paidAt = (new DateTime())->format('Y-m-d H:i:s');
    $updateClaim = $pdo->prepare("UPDATE claims SET status = 'paid', paid_at = ? WHERE id = ?");

    foreach ($claims as $claim) {
      $amount = (float)$claim['amount'];
      if ($amount <= 0 || $claim['status'] !== 'pending') {
        continue;
      }
      $userId = (int)$claim['user_id'];
      $userCashId = market_get_or_create_user_cash_account($pdo, $userId);

      $fromCash = min($remainingCash, $amount);
      $remainingCash = round($remainingCash - $fromCash, 8);
      $fromCollateral = round($amount - $fromCash, 8);
      $remainingCollateral = round($remainingCollateral - $fromCollateral, 8);

      $legs = [
        ['account_id' => $userCashId, 'debit' => round($amount, 8)],
      ];
      if ($fromCash > 0) {
        $legs[] = ['account_id' => $marketCashId, 'credit' => round($fromCash, 8)];
      }
      if ($fromCollateral > 0) {
        $legs[] = ['account_id' => $marketCollateralId, 'credit' => round($fromCollateral, 8)];
      }

      post_journal('prediction_trade', (int)$claim['id'], 'Payout LMSR', $legs);
      adjust_special_liquidity_assets($pdo, $userId, ['brl' => $amount]);

      $updateClaim->execute([$paidAt, (int)$claim['id']]);
    }
  }

  $pdo->commit();
  echo json_encode(['ok' => true, 'market_id' => $marketId, 'outcome' => strtolower($outcome === 'SIM' ? 'yes' : 'no'), 'outcome_legacy' => $outcome, 'paid_users' => count($claims)]);
} catch (RuntimeException $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  $message = $e->getMessage();
  $status = 400;
  if ($message === 'market_not_found') {
    $status = 404;
  } elseif ($message === 'market_not_open') {
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
  echo json_encode(['error' => 'resolve_failed']);
}