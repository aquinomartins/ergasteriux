<?php

declare(strict_types=1);

function get_or_create_brl_cash_account(PDO $pdo, int $userId): int {
  $stmt = $pdo->prepare("SELECT id FROM accounts WHERE owner_type='user' AND owner_id=? AND currency='BRL' AND purpose='cash' LIMIT 1");
  $stmt->execute([$userId]);
  $id = $stmt->fetchColumn();
  if ($id) {
    return (int)$id;
  }

  $insert = $pdo->prepare("INSERT INTO accounts (owner_type, owner_id, currency, purpose) VALUES ('user', ?, 'BRL', 'cash')");
  $insert->execute([$userId]);
  return (int)$pdo->lastInsertId();
}

function get_prediction_house_account(PDO $pdo): int {
  $stmt = $pdo->prepare("SELECT id FROM accounts WHERE owner_type='org' AND owner_id=0 AND currency='BRL' AND purpose='escrow' LIMIT 1");
  $stmt->execute();
  $id = $stmt->fetchColumn();
  if ($id) {
    return (int)$id;
  }
  $insert = $pdo->prepare("INSERT INTO accounts (owner_type, owner_id, currency, purpose) VALUES ('org', 0, 'BRL', 'escrow')");
  $insert->execute();
  return (int)$pdo->lastInsertId();
}

function get_brl_balance(PDO $pdo, int $accountId): float {
  $stmt = $pdo->prepare('SELECT ROUND(COALESCE(SUM(e.debit - e.credit),0),8) FROM entries e WHERE e.account_id = ?');
  $stmt->execute([$accountId]);
  $balance = $stmt->fetchColumn();
  return $balance !== false ? (float)$balance : 0.0;
}

function post_entry(PDO $pdo, int $accountId, float $deltaBrl, string $memo, string $refType, int $refId): int {
  $deltaBrl = round($deltaBrl, 8);
  $houseAccount = get_prediction_house_account($pdo);

  $stmt = $pdo->prepare('INSERT INTO journals (ref_type, ref_id, memo) VALUES (?, ?, ?)');
  $stmt->execute([$refType, $refId, $memo]);
  $journalId = (int)$pdo->lastInsertId();

  $userDebit = 0.0;
  $userCredit = 0.0;
  $houseDebit = 0.0;
  $houseCredit = 0.0;

  if ($deltaBrl >= 0) {
    $userDebit = $deltaBrl;
    $houseCredit = $deltaBrl;
  } else {
    $amount = abs($deltaBrl);
    $userCredit = $amount;
    $houseDebit = $amount;
  }

  $entryStmt = $pdo->prepare('INSERT INTO entries (journal_id, account_id, debit, credit) VALUES (?, ?, ?, ?)');
  $entryStmt->execute([$journalId, $accountId, $userDebit, $userCredit]);
  $entryStmt->execute([$journalId, $houseAccount, $houseDebit, $houseCredit]);

  return $journalId;
}
