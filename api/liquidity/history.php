<?php
// GET /api/liquidity/history?user_id=...
// Response: { ok: true, data: { events, limit, offset, user_id, pool_id } } or { ok:false, error:"..." }

header('Content-Type: application/json');
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/LiquidityService.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
  exit;
}

$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

try {
  $data = LiquidityService::getHistory(current_user_id(), $userId, $limit, $offset, current_user_is_admin());
  echo json_encode(['ok' => true, 'data' => $data]);
} catch (LiquidityServiceException $e) {
  http_response_code($e->statusCode);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'history_failed']);
}
