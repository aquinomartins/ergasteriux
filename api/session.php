<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../lib/session.php';
start_app_session();

$logged = isset($_SESSION['uid']);
$response = [
  'logged' => $logged,
  'user' => null,
  'csrf_token' => csrf_token(),
  'is_admin' => !empty($_SESSION['is_admin']),
  'is_special_liquidity_user' => false,
  'special_liquidity_email' => null,
];

if ($logged) {
  $response['user'] = [
    'id' => intval($_SESSION['uid']),
    'name' => $_SESSION['name'] ?? null,
    'email' => $_SESSION['email'] ?? null,
    'avatar_url' => null,
  ];
  $response['user_id'] = intval($_SESSION['uid']);
  $response['name'] = $_SESSION['name'] ?? null;
  $response['email'] = $_SESSION['email'] ?? null;
} else {
  $response['user_id'] = null;
  $response['name'] = null;
  $response['email'] = null;
}

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/special_liquidity_user.php';
$pdo = db();

if ($logged) {
  $response['is_special_liquidity_user'] = is_special_liquidity_user($pdo, $_SESSION['uid']);
}

$response['special_liquidity_email'] = special_liquidity_user_email($pdo);

echo json_encode($response);
