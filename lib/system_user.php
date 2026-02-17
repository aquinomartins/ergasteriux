<?php
require_once __DIR__ . '/config.php';

function ensure_system_user_schema(PDO $pdo): void {
  $stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'is_system'"
  );
  $stmt->execute();
  if ((int)$stmt->fetchColumn() > 0) {
    return;
  }

  $pdo->exec('ALTER TABLE users ADD COLUMN is_system TINYINT DEFAULT 0');
}

function ensure_system_user_accounts(PDO $pdo, int $userId): void {
  $defs = [
    ['BRL', 'cash'],
    ['BRL', 'escrow'],
    ['BTC', 'bitcoin_wallet'],
    ['BRL', 'nft_inventory'],
  ];
  $sel = $pdo->prepare("SELECT id FROM accounts WHERE owner_type='user' AND owner_id=? AND currency=? AND purpose=? LIMIT 1");
  $ins = $pdo->prepare("INSERT INTO accounts(owner_type, owner_id, currency, purpose) VALUES('user',?,?,?)");
  foreach ($defs as $def) {
    [$currency, $purpose] = $def;
    $sel->execute([$userId, $currency, $purpose]);
    if (!$sel->fetchColumn()) {
      $ins->execute([$userId, $currency, $purpose]);
    }
  }
}

function ensure_market_system_user(PDO $pdo): int {
  ensure_system_user_schema($pdo);

  $userId = MERCADO_PREDITIVO_USER_ID;
  $stmt = $pdo->prepare('SELECT id, name, email, password_hash FROM users WHERE id = ? LIMIT 1');
  $stmt->execute([$userId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  $hash = password_hash(MERCADO_PREDITIVO_USER_PASSWORD, PASSWORD_BCRYPT);
  if (!$row) {
    $insert = $pdo->prepare(
      'INSERT INTO users (id, name, email, password_hash, confirmed, is_admin, is_system) VALUES (?, ?, ?, ?, 1, 0, 1)'
    );
    $insert->execute([
      $userId,
      MERCADO_PREDITIVO_USER_NAME,
      MERCADO_PREDITIVO_USER_EMAIL,
      $hash,
    ]);
  } else {
    $updates = [];
    $params = [];

    if ((string)($row['name'] ?? '') === '') {
      $updates[] = 'name = ?';
      $params[] = MERCADO_PREDITIVO_USER_NAME;
    }
    if ((string)($row['email'] ?? '') === '') {
      $updates[] = 'email = ?';
      $params[] = MERCADO_PREDITIVO_USER_EMAIL;
    }
    if ((string)($row['password_hash'] ?? '') === '') {
      $updates[] = 'password_hash = ?';
      $params[] = $hash;
    }

    $updates[] = 'confirmed = 1';
    $updates[] = 'is_admin = 0';
    $updates[] = 'is_system = 1';

    $params[] = $userId;
    $pdo->prepare('UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($params);
  }

  ensure_system_user_accounts($pdo, $userId);

  return $userId;
}
