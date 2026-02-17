<?php

declare(strict_types=1);

require_once __DIR__ . '/prediction_lmsr.php';
require_once __DIR__ . '/prediction_accounts.php';

function prediction_refresh_market_status(PDO $pdo, array $market): array {
  if ($market['status'] === 'open' && !empty($market['close_at'])) {
    try {
      $closeAt = new DateTime($market['close_at']);
      if ($closeAt <= new DateTime()) {
        $stmt = $pdo->prepare("UPDATE pm_markets SET status = 'closed' WHERE id = ? AND status = 'open'");
        $stmt->execute([(int)$market['id']]);
        $market['status'] = 'closed';
      }
    } catch (Exception $e) {
      return $market;
    }
  }
  return $market;
}

function prediction_fetch_market_by_id(PDO $pdo, int $marketId, bool $forUpdate = false): ?array {
  $lock = $forUpdate ? ' FOR UPDATE' : '';
  $stmt = $pdo->prepare("SELECT * FROM pm_markets WHERE id = ?{$lock}");
  $stmt->execute([$marketId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    return null;
  }
  return prediction_refresh_market_status($pdo, $row);
}

function prediction_fetch_market_by_slug(PDO $pdo, string $slug): ?array {
  $stmt = $pdo->prepare('SELECT * FROM pm_markets WHERE slug = ? LIMIT 1');
  $stmt->execute([$slug]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    return null;
  }
  return prediction_refresh_market_status($pdo, $row);
}

function prediction_market_prices(array $market): array {
  $b = (float)$market['b'];
  $qYes = (float)$market['q_yes'];
  $qNo = (float)$market['q_no'];
  $priceYes = lmsr_price_yes($b, $qYes, $qNo);
  return [
    'yes' => round($priceYes, 10),
    'no' => round(1 - $priceYes, 10),
  ];
}

function prediction_market_stats(PDO $pdo, int $marketId): array {
  $stats = [
    'total_trades' => 0,
    'volume_brl' => 0.0,
    'open_interest_yes' => 0.0,
    'open_interest_no' => 0.0,
  ];

  $stmt = $pdo->prepare('SELECT COUNT(*) AS total_trades, COALESCE(SUM(ABS(cash_delta_brl)),0) AS volume_brl FROM pm_trades WHERE market_id = ?');
  $stmt->execute([$marketId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($row) {
    $stats['total_trades'] = (int)$row['total_trades'];
    $stats['volume_brl'] = (float)$row['volume_brl'];
  }

  $stmt = $pdo->prepare('SELECT COALESCE(SUM(shares_yes),0) AS shares_yes, COALESCE(SUM(shares_no),0) AS shares_no FROM pm_positions WHERE market_id = ?');
  $stmt->execute([$marketId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($row) {
    $stats['open_interest_yes'] = (float)$row['shares_yes'];
    $stats['open_interest_no'] = (float)$row['shares_no'];
  }

  return $stats;
}

function prediction_user_position(PDO $pdo, int $marketId, int $userId): array {
  $stmt = $pdo->prepare('SELECT shares_yes, shares_no FROM pm_positions WHERE market_id = ? AND user_id = ? LIMIT 1');
  $stmt->execute([$marketId, $userId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    return ['shares_yes' => 0.0, 'shares_no' => 0.0];
  }
  return [
    'shares_yes' => (float)$row['shares_yes'],
    'shares_no' => (float)$row['shares_no'],
  ];
}

function prediction_list_markets(PDO $pdo, ?int $userId): array {
  $stmt = $pdo->query('SELECT * FROM pm_markets ORDER BY close_at IS NULL, close_at ASC, id DESC');
  $rawMarkets = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $markets = array_map(function($market) use ($pdo) {
    return prediction_refresh_market_status($pdo, $market);
  }, $rawMarkets);

  $positionsByMarket = [];
  if ($userId && $markets) {
    $marketIds = array_map(fn($m) => (int)$m['id'], $markets);
    $placeholders = implode(',', array_fill(0, count($marketIds), '?'));
    $posStmt = $pdo->prepare("SELECT market_id, shares_yes, shares_no FROM pm_positions WHERE user_id = ? AND market_id IN ({$placeholders})");
    $posStmt->execute(array_merge([$userId], $marketIds));
    while ($row = $posStmt->fetch(PDO::FETCH_ASSOC)) {
      $positionsByMarket[(int)$row['market_id']] = [
        'shares_yes' => (float)$row['shares_yes'],
        'shares_no' => (float)$row['shares_no'],
      ];
    }
  }

  return array_map(function($market) use ($positionsByMarket) {
    $prices = prediction_market_prices($market);
    $payload = [
      'id' => (int)$market['id'],
      'slug' => $market['slug'],
      'title' => $market['title'],
      'description' => $market['description'],
      'status' => $market['status'],
      'close_at' => $market['close_at'],
      'resolved_outcome' => $market['resolved_outcome'],
      'price_yes' => $prices['yes'],
      'price_no' => $prices['no'],
      'created_at' => $market['created_at'],
    ];
    $marketId = (int)$market['id'];
    if (isset($positionsByMarket[$marketId])) {
      $payload['my_position'] = $positionsByMarket[$marketId];
    }
    return $payload;
  }, $markets);
}

function prediction_build_market_detail(PDO $pdo, array $market, ?int $userId): array {
  $prices = prediction_market_prices($market);
  $detail = [
    'id' => (int)$market['id'],
    'slug' => $market['slug'],
    'title' => $market['title'],
    'description' => $market['description'],
    'status' => $market['status'],
    'close_at' => $market['close_at'],
    'resolved_outcome' => $market['resolved_outcome'],
    'b' => (float)$market['b'],
    'q_yes' => (float)$market['q_yes'],
    'q_no' => (float)$market['q_no'],
    'price_yes' => $prices['yes'],
    'price_no' => $prices['no'],
    'created_by' => $market['created_by'] !== null ? (int)$market['created_by'] : null,
    'created_at' => $market['created_at'],
    'stats' => prediction_market_stats($pdo, (int)$market['id']),
  ];

  if ($userId) {
    $detail['my_position'] = prediction_user_position($pdo, (int)$market['id'], $userId);
  }

  return $detail;
}

function prediction_quote_market(array $market, string $outcome, string $side, float $shares): array {
  return lmsr_quote(
    (float)$market['b'],
    (float)$market['q_yes'],
    (float)$market['q_no'],
    $outcome,
    $side,
    $shares
  );
}

function prediction_execute_trade(PDO $pdo, int $userId, int $marketId, string $outcome, string $side, float $shares): array {
  $startedTx = !$pdo->inTransaction();
  if ($startedTx) {
    $pdo->beginTransaction();
  }

  try {
    $market = prediction_fetch_market_by_id($pdo, $marketId, true);
    if (!$market) {
      throw new RuntimeException('market_not_found');
    }

    if ($market['status'] !== 'open') {
      throw new RuntimeException('market_closed');
    }

    if (!empty($market['close_at'])) {
      $closeAt = new DateTime($market['close_at']);
      if ($closeAt <= new DateTime()) {
        throw new RuntimeException('market_closed');
      }
    }

    $accountId = get_or_create_brl_cash_account($pdo, $userId);
    $balance = get_brl_balance($pdo, $accountId);

    $positionStmt = $pdo->prepare('SELECT shares_yes, shares_no FROM pm_positions WHERE market_id = ? AND user_id = ? FOR UPDATE');
    $positionStmt->execute([$marketId, $userId]);
    $positionRow = $positionStmt->fetch(PDO::FETCH_ASSOC);
    $currentYes = $positionRow ? (float)$positionRow['shares_yes'] : 0.0;
    $currentNo = $positionRow ? (float)$positionRow['shares_no'] : 0.0;

    $quote = prediction_quote_market($market, $outcome, $side, $shares);
    $cashDelta = (float)$quote['cash_delta_brl'];

    if ($side === 'buy') {
      if (($balance + $cashDelta) < -0.00000001) {
        throw new RuntimeException('insufficient_balance');
      }
    } else {
      if ($outcome === 'yes' && ($currentYes + 1e-9) < $shares) {
        throw new RuntimeException('insufficient_position');
      }
      if ($outcome === 'no' && ($currentNo + 1e-9) < $shares) {
        throw new RuntimeException('insufficient_position');
      }
    }

    $qYesNew = (float)$quote['q_yes_new'];
    $qNoNew = (float)$quote['q_no_new'];
    $updateMarket = $pdo->prepare('UPDATE pm_markets SET q_yes = ?, q_no = ? WHERE id = ?');
    $updateMarket->execute([$qYesNew, $qNoNew, $marketId]);

    $deltaYes = 0.0;
    $deltaNo = 0.0;
    if ($outcome === 'yes') {
      $deltaYes = $side === 'buy' ? $shares : -$shares;
    } else {
      $deltaNo = $side === 'buy' ? $shares : -$shares;
    }

    $positionUpdate = $pdo->prepare(
      'INSERT INTO pm_positions (market_id, user_id, shares_yes, shares_no) VALUES (?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE shares_yes = shares_yes + VALUES(shares_yes), shares_no = shares_no + VALUES(shares_no)'
    );
    $positionUpdate->execute([$marketId, $userId, $deltaYes, $deltaNo]);

    $tradeStmt = $pdo->prepare(
      'INSERT INTO pm_trades (market_id, user_id, side, outcome, shares, cash_delta_brl, price_yes_before, price_yes_after)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $tradeStmt->execute([
      $marketId,
      $userId,
      $side,
      $outcome,
      $shares,
      $cashDelta,
      $quote['price_yes_before'],
      $quote['price_yes_after'],
    ]);
    $tradeId = (int)$pdo->lastInsertId();

    $memo = sprintf('prediction_%s market:%d outcome:%s shares:%s delta:%0.8f', $side, $marketId, $outcome, $shares, $cashDelta);
    $journalId = post_entry($pdo, $accountId, $cashDelta, $memo, 'prediction_trade', $tradeId);

    $newPosition = prediction_user_position($pdo, $marketId, $userId);
    $priceYes = lmsr_price_yes((float)$market['b'], $qYesNew, $qNoNew);

    if ($startedTx && $pdo->inTransaction()) {
      $pdo->commit();
    }

    return [
      'trade' => [
        'id' => $tradeId,
        'market_id' => $marketId,
        'side' => $side,
        'outcome' => $outcome,
        'shares' => $shares,
        'cash_delta_brl' => $cashDelta,
        'price_yes_before' => (float)$quote['price_yes_before'],
        'price_yes_after' => (float)$quote['price_yes_after'],
        'journal_id' => $journalId,
      ],
      'position' => $newPosition,
      'price_yes' => round($priceYes, 10),
      'price_no' => round(1 - $priceYes, 10),
      'balance_brl' => round($balance + $cashDelta, 8),
    ];
  } catch (Exception $e) {
    if ($startedTx && $pdo->inTransaction()) {
      $pdo->rollBack();
    }
    throw $e;
  }
}

function prediction_resolve_market(PDO $pdo, int $marketId, string $outcome): array {
  if (!in_array($outcome, ['yes', 'no'], true)) {
    throw new RuntimeException('invalid_outcome');
  }

  $startedTx = !$pdo->inTransaction();
  if ($startedTx) {
    $pdo->beginTransaction();
  }

  try {
    $settledStmt = $pdo->prepare('SELECT id, resolved_outcome FROM pm_settlements WHERE market_id = ? FOR UPDATE');
    $settledStmt->execute([$marketId]);
    $existingSettlement = $settledStmt->fetch(PDO::FETCH_ASSOC);
    if ($existingSettlement) {
      if ($startedTx && $pdo->inTransaction()) {
        $pdo->commit();
      }
      return [
        'status' => 'already_settled',
        'market_id' => $marketId,
        'resolved_outcome' => $existingSettlement['resolved_outcome'],
        'paid_users' => 0,
      ];
    }

    $marketStmt = $pdo->prepare('SELECT id, status, close_at FROM pm_markets WHERE id = ? FOR UPDATE');
    $marketStmt->execute([$marketId]);
    $market = $marketStmt->fetch(PDO::FETCH_ASSOC);
    if (!$market) {
      throw new RuntimeException('market_not_found');
    }
    if ($market['status'] === 'cancelled') {
      throw new RuntimeException('market_cancelled');
    }
    if (!empty($market['close_at'])) {
      $closeAt = new DateTime($market['close_at']);
      if ($closeAt > new DateTime()) {
        throw new RuntimeException('market_not_closed');
      }
    }

    $pdo->prepare("UPDATE pm_markets SET status = 'resolved', resolved_outcome = ? WHERE id = ?")
        ->execute([$outcome, $marketId]);

    $positionsStmt = $pdo->prepare('SELECT user_id, shares_yes, shares_no FROM pm_positions WHERE market_id = ?');
    $positionsStmt->execute([$marketId]);

    $paidUsers = 0;
    while ($row = $positionsStmt->fetch(PDO::FETCH_ASSOC)) {
      $userId = (int)$row['user_id'];
      $sharesWinner = $outcome === 'yes' ? (float)$row['shares_yes'] : (float)$row['shares_no'];
      if ($sharesWinner <= 0) {
        continue;
      }
      $accountId = get_or_create_brl_cash_account($pdo, $userId);
      $memo = sprintf('prediction_payout market:%d outcome:%s shares:%s', $marketId, $outcome, $sharesWinner);
      post_entry($pdo, $accountId, $sharesWinner, $memo, 'prize', $marketId);
      $paidUsers++;
    }

    $insertSettlement = $pdo->prepare('INSERT INTO pm_settlements (market_id, resolved_outcome) VALUES (?, ?)');
    $insertSettlement->execute([$marketId, $outcome]);

    if ($startedTx && $pdo->inTransaction()) {
      $pdo->commit();
    }

    return [
      'status' => 'resolved',
      'market_id' => $marketId,
      'resolved_outcome' => $outcome,
      'paid_users' => $paidUsers,
    ];
  } catch (Exception $e) {
    if ($startedTx && $pdo->inTransaction()) {
      $pdo->rollBack();
    }
    throw $e;
  }
}

function prediction_cancel_market(PDO $pdo, int $marketId): array {
  $startedTx = !$pdo->inTransaction();
  if ($startedTx) {
    $pdo->beginTransaction();
  }

  try {
    $cancelStmt = $pdo->prepare('SELECT id FROM pm_cancellations WHERE market_id = ? FOR UPDATE');
    $cancelStmt->execute([$marketId]);
    if ($cancelStmt->fetchColumn()) {
      if ($startedTx && $pdo->inTransaction()) {
        $pdo->commit();
      }
      return [
        'status' => 'already_cancelled',
        'market_id' => $marketId,
        'refunded_users' => 0,
      ];
    }

    $marketStmt = $pdo->prepare('SELECT id, status, close_at FROM pm_markets WHERE id = ? FOR UPDATE');
    $marketStmt->execute([$marketId]);
    $market = $marketStmt->fetch(PDO::FETCH_ASSOC);
    if (!$market) {
      throw new RuntimeException('market_not_found');
    }
    if ($market['status'] === 'resolved') {
      throw new RuntimeException('market_resolved');
    }

    $refundStmt = $pdo->prepare('SELECT user_id, COALESCE(SUM(cash_delta_brl),0) AS net_delta FROM pm_trades WHERE market_id = ? GROUP BY user_id');
    $refundStmt->execute([$marketId]);

    $refundedUsers = 0;
    while ($row = $refundStmt->fetch(PDO::FETCH_ASSOC)) {
      $userId = (int)$row['user_id'];
      $netDelta = (float)$row['net_delta'];
      if (abs($netDelta) < 0.00000001) {
        continue;
      }
      $refund = -$netDelta;
      $accountId = get_or_create_brl_cash_account($pdo, $userId);
      $memo = sprintf('prediction_refund market:%d net:%0.8f refund:%0.8f', $marketId, $netDelta, $refund);
      post_entry($pdo, $accountId, $refund, $memo, 'prize', $marketId);
      $refundedUsers++;
    }

    $pdo->prepare("UPDATE pm_markets SET status = 'cancelled' WHERE id = ?")
        ->execute([$marketId]);
    $pdo->prepare('INSERT INTO pm_cancellations (market_id) VALUES (?)')
        ->execute([$marketId]);

    if ($startedTx && $pdo->inTransaction()) {
      $pdo->commit();
    }

    return [
      'status' => 'cancelled',
      'market_id' => $marketId,
      'refunded_users' => $refundedUsers,
    ];
  } catch (Exception $e) {
    if ($startedTx && $pdo->inTransaction()) {
      $pdo->rollBack();
    }
    throw $e;
  }
}