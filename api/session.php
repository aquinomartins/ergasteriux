<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../lib/session.php';
start_app_session();
$logged = isset($_SESSION['uid']);
$response = [
  'logged' => $logged,
  'user_id' => $logged ? intval($_SESSION['uid']) : null,
  'name' => $logged ? ($_SESSION['name'] ?? null) : null,
  'email' => $logged ? ($_SESSION['email'] ?? null) : null,
  'is_admin' => !empty($_SESSION['is_admin']),
  'csrf_token' => csrf_token()
];

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/special_liquidity_user.php';
$pdo = db();

if ($logged) {
  $response['is_special_liquidity_user'] = is_special_liquidity_user($pdo, $_SESSION['uid']);
} else {
  $response['is_special_liquidity_user'] = false;
}

$response['special_liquidity_email'] = special_liquidity_user_email($pdo);

echo json_encode($response);