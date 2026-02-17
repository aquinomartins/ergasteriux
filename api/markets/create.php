<?php

require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/ledger.php';
require_once __DIR__ . '/../../lib/lmsr.php';
require_once __DIR__ . '/../../lib/market_accounts.php';
require_once __DIR__ . '/../../lib/market_http.php';

require_login();
require_admin();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['error' => 'method_not_allowed']);
  exit;
}

$payload = market_read_json();
$title = trim((string)($payload['title'] ?? ''));
$description = trim((string)($payload['description'] ?? ''));
$riskMax = isset($payload['risk_max']) ? (float)$payload['risk_max'] : null;
$b = isset($payload['b']) ? (float)$payload['b'] : null;
$feeRate = isset($payload['fee_rate']) ? (float)$payload['fee_rate'] : 0.01;
$bufferRate = isset($payload['buffer_rate']) ? (float)$payload['buffer_rate'] : 0.15;
$collateralLocked = isset($payload['collateral_locked']) ? (float)$payload['collateral_locked'] : null;

if ($title === '') {
  http_response_code(400);
  echo json_encode(['error' => 'title_required']);
  exit;
}

if ($b === null) {
  if ($riskMax === null || $riskMax <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_risk_max']);
    exit;
  }
  $b = $riskMax / log(2);
}

if ($b <= 0 || $feeRate < 0 || $bufferRate < 0) {
  http_response_code(400);
  echo json_encode(['error' => 'invalid_parameters']);
  exit;
}

$requiredCollateral = $b * log(2) * (1 + $bufferRate);
if ($collateralLocked === null) {
  $collateralLocked = $requiredCollateral;
}

if ($collateralLocked + 1e-8 < $requiredCollateral) {
  http_response_code(422);
  echo json_encode([
    'error' => 'insufficient_collateral',
    'required' => round($requiredCollateral, 8),
  ]);
  exit;
}

$pdo = db();
$pdo->beginTransaction();
try {
  $stmt = $pdo->prepare(
    "INSERT INTO markets (title, description, b, fee_rate, buffer_rate, collateral_locked, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, 'open', ?, ?)"
  );
  $createdAt = (new DateTime())->format('Y-m-d H:i:s');
  $stmt->execute([
    $title,
    $description,
    round($b, 8),
    round($feeRate, 8),
    round($bufferRate, 8),
    round($collateralLocked, 8),
    current_user_id(),
    $createdAt,
  ]);
  $marketId = (int)$pdo->lastInsertId();

  $initialCost = lmsr_cost(0, 0, $b);
  $stateStmt = $pdo->prepare(
    "INSERT INTO market_state (market_id, q_sim, q_nao, cost_total, updated_at) VALUES (?, 0, 0, ?, ?)"
  );
  $stateStmt->execute([$marketId, round($initialCost, 8), $createdAt]);

  $marketCashId = market_get_or_create_market_account($pdo, $marketId, 'market_cash');
  $marketFeesId = market_get_or_create_market_account($pdo, $marketId, 'market_fees');
  $marketCollateralId = market_get_or_create_market_account($pdo, $marketId, 'market_collateral');
  $marketMakerId = market_system_user_id($pdo);
  $mmCashId = market_get_or_create_user_cash_account($pdo, $marketMakerId);

  // Trava colateral: reduz caixa do market maker e aumenta a conta de colateral do mercado.
  post_journal('prediction_trade', $marketId, 'Colateral LMSR bloqueado', [
    ['account_id' => $marketCollateralId, 'debit' => round($collateralLocked, 8)],
    ['account_id' => $mmCashId, 'credit' => round($collateralLocked, 8)],
  ]);

  $pdo->commit();
  echo json_encode([
    'ok' => true,
    'market_id' => $marketId,
    'market_cash_account' => $marketCashId,
    'market_fees_account' => $marketFeesId,
    'market_collateral_account' => $marketCollateralId,
    'required_collateral' => round($requiredCollateral, 8),
  ]);
} catch (Exception $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  http_response_code(500);
  echo json_encode(['error' => 'market_create_failed']);
}
