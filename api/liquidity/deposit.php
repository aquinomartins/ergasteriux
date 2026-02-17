<?php
// POST /api/liquidity/deposit
// Payload: {"nft_id":123}
// Response: { ok: true, data: { pool, user, sample_nfts, rules } } or { ok:false, error:"..." }

header('Content-Type: application/json');
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/LiquidityService.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
  exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if ($raw !== '' && $payload === null && json_last_error() !== JSON_ERROR_NONE) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'invalid_json']);
  exit;
}

$nftId = isset($payload['nft_id']) ? (int)$payload['nft_id'] : 0;

try {
  $state = LiquidityService::deposit(current_user_id(), $nftId);
  echo json_encode(['ok' => true, 'data' => $state]);
} catch (LiquidityServiceException $e) {
  http_response_code($e->statusCode);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'deposit_failed']);
}
