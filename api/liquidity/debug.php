<?php
// GET /api/liquidity/debug?action=init|check
// Admin only. Returns pool initialization info or consistency checks.

header('Content-Type: application/json');
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/LiquidityService.php';
require_once __DIR__ . '/../../lib/db.php';

require_login();
require_admin();

$action = isset($_GET['action']) ? strtolower(trim($_GET['action'])) : 'check';

try {
  $pdo = db();
  if ($action === 'init') {
    $pool = LiquidityService::ensurePool($pdo);
    echo json_encode(['ok' => true, 'data' => ['pool' => $pool]]);
    exit;
  }

  if ($action !== 'check') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_action']);
    exit;
  }

  $pool = LiquidityService::ensurePool($pdo);
  $poolId = (int)$pool['id'];

  $countStmt = $pdo->prepare("SELECT COUNT(*) FROM liquidity_pool_nfts WHERE pool_id = ? AND status = 'in_pool'");
  $countStmt->execute([$poolId]);
  $nftCount = (int)$countStmt->fetchColumn();

  $negativeStmt = $pdo->query(
    "SELECT a.owner_type, a.owner_id, a.currency, a.purpose, ROUND(COALESCE(SUM(e.debit - e.credit),0),8) AS balance
     FROM accounts a
     LEFT JOIN entries e ON e.account_id = a.id
     GROUP BY a.id
     HAVING balance < 0"
  );
  $negativeAccounts = $negativeStmt->fetchAll(PDO::FETCH_ASSOC);

  $issues = [];
  if ($nftCount !== (int)$pool['total_nfts']) {
    $issues[] = 'pool_nft_count_mismatch';
  }

  echo json_encode([
    'ok' => true,
    'data' => [
      'pool_id' => $poolId,
      'pool_total_nfts' => (int)$pool['total_nfts'],
      'pool_nft_count' => $nftCount,
      'negative_accounts' => $negativeAccounts,
      'issues' => $issues,
    ]
  ]);
} catch (LiquidityServiceException $e) {
  http_response_code($e->statusCode);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'debug_failed']);
}
