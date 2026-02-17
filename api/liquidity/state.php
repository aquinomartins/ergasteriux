<?php
// GET /api/liquidity/state
// Response: { ok: true, data: { pool, user, sample_nfts, rules } } or { ok:false, error:"..." }

header('Content-Type: application/json');
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/LiquidityService.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
  exit;
}

try {
  $state = LiquidityService::getState(current_user_id());
  echo json_encode(['ok' => true, 'data' => $state]);
} catch (LiquidityServiceException $e) {
  http_response_code($e->statusCode);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'state_failed']);
}
