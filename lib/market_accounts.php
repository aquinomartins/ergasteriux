<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/system_user.php';

function market_get_balance(PDO $pdo, int $accountId): float {
  $stmt = $pdo->prepare('SELECT ROUND(COALESCE(SUM(e.debit - e.credit),0),8) FROM entries e WHERE e.account_id = ?');
  $stmt->execute([$accountId]);
  $balance = $stmt->fetchColumn();
  return $balance !== false ? (float)$balance : 0.0;
}

function market_get_or_create_user_cash_account(PDO $pdo, int $userId): int {
  $stmt = $pdo->prepare("SELECT id FROM accounts WHERE owner_type='user' AND owner_id=? AND currency='BRL' AND purpose='cash' LIMIT 1");
  $stmt->execute([$userId]);
  $id = $stmt->fetchColumn();
  if ($id) {
    return (int)$id;
  }

  $insert = $pdo->prepare(
    "INSERT INTO accounts (owner_type, owner_id, currency, purpose, user_id, market_id) VALUES ('user', ?, 'BRL', 'cash', ?, NULL)"
  );
  $insert->execute([$userId, $userId]);
  return (int)$pdo->lastInsertId();
}

function market_get_or_create_market_account(PDO $pdo, int $marketId, string $purpose): int {
  $purposeMap = [
    'market_cash' => 'cash',
    'market_fees' => 'fees',
    'market_collateral' => 'escrow',
  ];
  $dbPurpose = $purposeMap[$purpose] ?? $purpose;
  $stmt = $pdo->prepare("SELECT id FROM accounts WHERE market_id=? AND currency='BRL' AND purpose=? LIMIT 1");
  $stmt->execute([$marketId, $dbPurpose]);
  $id = $stmt->fetchColumn();
  if ($id) {
    return (int)$id;
  }

  $insert = $pdo->prepare(
    "INSERT INTO accounts (owner_type, owner_id, currency, purpose, market_id) VALUES ('org', ?, 'BRL', ?, ?)"
  );
  $insert->execute([$marketId, $dbPurpose, $marketId]);
  return (int)$pdo->lastInsertId();
}

function market_system_user_id(PDO $pdo): int {
  return ensure_market_system_user($pdo);
}