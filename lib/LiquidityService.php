<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ledger.php';
require_once __DIR__ . '/util.php';
require_once __DIR__ . '/special_liquidity_user.php';

class LiquidityServiceException extends Exception {
  public int $statusCode;
  public function __construct(string $message, int $statusCode = 400) {
    parent::__construct($message);
    $this->statusCode = $statusCode;
  }
}

class LiquidityService {
  private const POOL_TABLE = 'liquidity_pool';
  private const POOL_RULES_TABLE = 'liquidity_pool_rules';
  private const POOL_NFTS_TABLE = 'liquidity_pool_nfts';
  private const POSITIONS_TABLE = 'liquidity_positions';
  private const EVENTS_TABLE = 'liquidity_events';

  private const DEFAULT_POOL_NAME = 'Piscina NFT/BTC';

  private const DEFAULT_DEPOSIT_BTC_REWARD = 10.0;
  private const DEFAULT_WITHDRAW_BTC_COST = 11.0;
  private const DEFAULT_WITHDRAW_BRL_COST = 2000.0;

  private const SHARE_ASSET_SYMBOL = 'LP-SHARE';

  public static function getState(int $userId): array {
    $pdo = db();
    $pool = self::ensurePool($pdo);
    $poolId = (int)$pool['id'];
    $rules = self::getPoolRules($pdo, $poolId);

    ensure_user_accounts($userId);
    $btcAccount = self::ensureAccount($pdo, 'user', $userId, 'BTC', 'bitcoin_wallet');
    $brlAccount = self::ensureAccount($pdo, 'user', $userId, 'BRL', 'cash');

    $sharesStmt = $pdo->prepare(
      "SELECT shares FROM " . self::POSITIONS_TABLE . " WHERE pool_id = ? AND user_id = ? LIMIT 1"
    );
    $sharesStmt->execute([$poolId, $userId]);
    $shares = (float)($sharesStmt->fetchColumn() ?: 0);

    $sampleStmt = $pdo->prepare(
      "SELECT nft_id FROM " . self::POOL_NFTS_TABLE . " WHERE pool_id = ? AND status = 'in_pool' ORDER BY deposited_at DESC LIMIT 10"
    );
    $sampleStmt->execute([$poolId]);
    $samples = array_map('intval', array_column($sampleStmt->fetchAll(PDO::FETCH_ASSOC), 'nft_id'));

    return [
      'pool' => [
        'id' => $poolId,
        'name' => $pool['name'],
        'total_nfts' => (int)$pool['total_nfts'],
        'total_shares' => (float)$pool['total_shares'],
        'btc_reserve' => (float)$pool['btc_reserve'],
        'brl_reserve' => (float)$pool['brl_reserve'],
        'price_btc' => (float)$rules['withdraw_btc_cost'],
        'price_brl' => (float)$rules['withdraw_brl_cost'],
      ],
      'user' => [
        'shares' => $shares,
        'btc_balance' => self::getAccountBalance($pdo, $btcAccount),
        'brl_balance' => self::getAccountBalance($pdo, $brlAccount),
      ],
      'special_assets' => get_special_liquidity_assets($pdo, $userId),
      'sample_nfts' => $samples,
      'rules' => [
        'deposit_btc_reward' => (float)$rules['deposit_btc_reward'],
        'withdraw_btc_cost' => (float)$rules['withdraw_btc_cost'],
        'withdraw_brl_cost' => (float)$rules['withdraw_brl_cost'],
      ]
    ];
  }

  public static function deposit(int $userId, int $nftId): array {
    if ($nftId <= 0) {
      throw new LiquidityServiceException('invalid_nft_id', 422);
    }

    $pdo = db();
    $pool = self::ensurePool($pdo);
    $poolId = (int)$pool['id'];

    ensure_user_accounts($userId);
    $userBtc = self::ensureAccount($pdo, 'user', $userId, 'BTC', 'bitcoin_wallet');
    $userNft = self::ensureAccount($pdo, 'user', $userId, 'BRL', 'nft_inventory');
    $userShare = self::ensureShareAccount($pdo, $userId);

    $poolBtc = self::ensureAccount($pdo, 'org', $poolId, 'BTC', 'bitcoin_wallet');
    $poolNft = self::ensureAccount($pdo, 'org', $poolId, 'BRL', 'nft_inventory');

    $pdo->beginTransaction();
    try {
      $poolRow = self::lockPool($pdo, $poolId);
      $rules = self::getPoolRules($pdo, $poolId, true);
      $rewardBtc = (float)$rules['deposit_btc_reward'];

      $assetStmt = $pdo->prepare('SELECT id, asset_id FROM asset_instances WHERE id = ? FOR UPDATE');
      $assetStmt->execute([$nftId]);
      $instance = $assetStmt->fetch(PDO::FETCH_ASSOC);
      if (!$instance) {
        throw new LiquidityServiceException('nft_not_found', 404);
      }
      $assetId = (int)$instance['asset_id'];

      $poolNftStmt = $pdo->prepare(
        "SELECT id, status FROM " . self::POOL_NFTS_TABLE . " WHERE pool_id = ? AND nft_id = ? FOR UPDATE"
      );
      $poolNftStmt->execute([$poolId, $nftId]);
      $poolNftRow = $poolNftStmt->fetch(PDO::FETCH_ASSOC);
      if ($poolNftRow && $poolNftRow['status'] === 'in_pool') {
        throw new LiquidityServiceException('nft_already_in_pool', 409);
      }

      $ownerStmt = $pdo->prepare(
        "SELECT qty FROM positions WHERE owner_type='user' AND owner_id = ? AND asset_id = ? FOR UPDATE"
      );
      $ownerStmt->execute([$userId, $assetId]);
      $qty = (float)$ownerStmt->fetchColumn();
      if ($qty < 1) {
        throw new LiquidityServiceException('nft_not_owned', 409);
      }

      self::lockAccounts($pdo, [$userBtc, $userNft, $userShare, $poolBtc, $poolNft]);

      $memo = sprintf('Liquidity deposit NFT #%d', $nftId);
      $journalId = post_journal('deposit', null, $memo, [
        ['account_id' => $userBtc, 'debit' => $rewardBtc],
        ['account_id' => $poolBtc, 'credit' => $rewardBtc],
      ]);

      move_asset($journalId, null, $nftId, 1, $userNft, $poolNft);

      $shareAssetId = self::ensureShareAsset($pdo);
      move_asset($journalId, $shareAssetId, null, 1, null, $userShare);

      if ($poolNftRow) {
        $updateNft = $pdo->prepare(
          "UPDATE " . self::POOL_NFTS_TABLE . " SET status='in_pool', deposited_by_user_id = ?, deposited_at = NOW(), withdrawn_at = NULL WHERE id = ?"
        );
        $updateNft->execute([$userId, $poolNftRow['id']]);
      } else {
        $insertNft = $pdo->prepare(
          "INSERT INTO " . self::POOL_NFTS_TABLE . " (pool_id, nft_id, deposited_by_user_id, status, deposited_at) VALUES (?,?,?,?,NOW())"
        );
        $insertNft->execute([$poolId, $nftId, $userId, 'in_pool']);
      }

      $posStmt = $pdo->prepare(
        "INSERT INTO " . self::POSITIONS_TABLE . " (pool_id, user_id, shares, created_at, updated_at) VALUES (?,?,?,NOW(),NOW())
         ON DUPLICATE KEY UPDATE shares = shares + VALUES(shares), updated_at = NOW()"
      );
      $posStmt->execute([$poolId, $userId, 1]);

      $poolUpdate = $pdo->prepare(
        "UPDATE " . self::POOL_TABLE . " SET total_nfts = total_nfts + 1, total_shares = total_shares + 1,
         btc_reserve = btc_reserve - ?, updated_at = NOW() WHERE id = ?"
      );
      $poolUpdate->execute([$rewardBtc, $poolId]);

      $eventStmt = $pdo->prepare(
        "INSERT INTO " . self::EVENTS_TABLE . " (pool_id, user_id, event_type, nft_id, btc_amount, brl_amount, shares_delta, memo, created_at)
         VALUES (?,?,?,?,?,?,?, ?, NOW())"
      );
      $eventStmt->execute([$poolId, $userId, 'deposit', $nftId, $rewardBtc, 0, 1, $memo]);

      adjust_special_liquidity_assets($pdo, $userId, [
        'bitcoin' => $rewardBtc,
        'nft' => -1,
        'quotas' => 1
      ]);

      $pdo->commit();
      return self::getState($userId);
    } catch (Exception $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $e;
    }
  }

  public static function withdraw(int $userId, ?int $nftId, ?string $payCurrency): array {
    $pdo = db();
    $pool = self::ensurePool($pdo);
    $poolId = (int)$pool['id'];

    ensure_user_accounts($userId);
    $userBtc = self::ensureAccount($pdo, 'user', $userId, 'BTC', 'bitcoin_wallet');
    $userBrl = self::ensureAccount($pdo, 'user', $userId, 'BRL', 'cash');
    $userNft = self::ensureAccount($pdo, 'user', $userId, 'BRL', 'nft_inventory');
    $userShare = self::ensureShareAccount($pdo, $userId);

    $poolBtc = self::ensureAccount($pdo, 'org', $poolId, 'BTC', 'bitcoin_wallet');
    $poolBrl = self::ensureAccount($pdo, 'org', $poolId, 'BRL', 'cash');
    $poolNft = self::ensureAccount($pdo, 'org', $poolId, 'BRL', 'nft_inventory');

    $currency = strtoupper(trim($payCurrency ?? 'BTC'));
    if (!in_array($currency, ['BTC', 'BRL'], true)) {
      throw new LiquidityServiceException('invalid_payment_currency', 422);
    }

    $pdo->beginTransaction();
    try {
      $poolRow = self::lockPool($pdo, $poolId);
      $rules = self::getPoolRules($pdo, $poolId, true);
      $btcCost = (float)$rules['withdraw_btc_cost'];
      $brlCost = (float)$rules['withdraw_brl_cost'];

      $posStmt = $pdo->prepare(
        "SELECT shares FROM " . self::POSITIONS_TABLE . " WHERE pool_id = ? AND user_id = ? FOR UPDATE"
      );
      $posStmt->execute([$poolId, $userId]);
      $shares = (float)$posStmt->fetchColumn();
      if ($shares < 1) {
        throw new LiquidityServiceException('insufficient_shares', 409);
      }

      if ($nftId !== null && $nftId <= 0) {
        throw new LiquidityServiceException('invalid_nft_id', 422);
      }

      $nftRow = null;
      if ($nftId !== null) {
        $nftStmt = $pdo->prepare(
          "SELECT id, nft_id FROM " . self::POOL_NFTS_TABLE . " WHERE pool_id = ? AND nft_id = ? AND status = 'in_pool' FOR UPDATE"
        );
        $nftStmt->execute([$poolId, $nftId]);
        $nftRow = $nftStmt->fetch(PDO::FETCH_ASSOC);
      } else {
        $nftStmt = $pdo->prepare(
          "SELECT id, nft_id FROM " . self::POOL_NFTS_TABLE . " WHERE pool_id = ? AND status = 'in_pool' ORDER BY deposited_at ASC LIMIT 1 FOR UPDATE"
        );
        $nftStmt->execute([$poolId]);
        $nftRow = $nftStmt->fetch(PDO::FETCH_ASSOC);
      }

      if (!$nftRow) {
        throw new LiquidityServiceException('nft_unavailable', 409);
      }

      $selectedNftId = (int)$nftRow['nft_id'];

      self::lockAccounts($pdo, [$userBtc, $userBrl, $userNft, $userShare, $poolBtc, $poolBrl, $poolNft]);

      $specialAssets = get_special_liquidity_assets($pdo, $userId);
      if ($currency === 'BTC') {
        $userBalance = (float)($specialAssets['bitcoin'] ?? 0);
        if ($userBalance + 1e-8 < $btcCost) {
          throw new LiquidityServiceException('insufficient_btc', 409);
        }
      } else {
        $userBalance = (float)($specialAssets['brl'] ?? 0);
        if ($userBalance + 1e-8 < $brlCost) {
          throw new LiquidityServiceException('insufficient_brl', 409);
        }
      }

      $memo = sprintf('Liquidity withdraw NFT #%d', $selectedNftId);
      if ($currency === 'BTC') {
        $journalId = post_journal('withdraw', null, $memo, [
          ['account_id' => $userBtc, 'debit' => $btcCost],
          ['account_id' => $poolBtc, 'credit' => $btcCost],
        ]);
      } else {
        $journalId = post_journal('withdraw', null, $memo, [
          ['account_id' => $userBrl, 'debit' => $brlCost],
          ['account_id' => $poolBrl, 'credit' => $brlCost],
        ]);
      }

      move_asset($journalId, null, $selectedNftId, 1, $poolNft, $userNft);

      $shareAssetId = self::ensureShareAsset($pdo);
      move_asset($journalId, $shareAssetId, null, 1, $userShare, null);

      $updateNft = $pdo->prepare(
        "UPDATE " . self::POOL_NFTS_TABLE . " SET status='withdrawn', withdrawn_at = NOW() WHERE id = ?"
      );
      $updateNft->execute([$nftRow['id']]);

      $posUpdate = $pdo->prepare(
        "UPDATE " . self::POSITIONS_TABLE . " SET shares = shares - 1, updated_at = NOW() WHERE pool_id = ? AND user_id = ?"
      );
      $posUpdate->execute([$poolId, $userId]);

      $poolUpdate = $pdo->prepare(
        "UPDATE " . self::POOL_TABLE . " SET total_nfts = total_nfts - 1, total_shares = total_shares - 1,
         btc_reserve = btc_reserve + ?, brl_reserve = brl_reserve + ?, updated_at = NOW() WHERE id = ?"
      );
      $poolUpdate->execute([
        $currency === 'BTC' ? $btcCost : 0,
        $currency === 'BRL' ? $brlCost : 0,
        $poolId
      ]);

      $eventStmt = $pdo->prepare(
        "INSERT INTO " . self::EVENTS_TABLE . " (pool_id, user_id, event_type, nft_id, btc_amount, brl_amount, shares_delta, memo, created_at)
         VALUES (?,?,?,?,?,?,?, ?, NOW())"
      );
      $eventStmt->execute([
        $poolId,
        $userId,
        'withdraw',
        $selectedNftId,
        $currency === 'BTC' ? $btcCost : 0,
        $currency === 'BRL' ? $brlCost : 0,
        -1,
        $memo
      ]);

      $assetDelta = [
        'nft' => 1,
        'quotas' => -1
      ];
      if ($currency === 'BTC') {
        $assetDelta['bitcoin'] = -$btcCost;
      } else {
        $assetDelta['brl'] = -$brlCost;
      }
      adjust_special_liquidity_assets($pdo, $userId, $assetDelta);

      $pdo->commit();
      return self::getState($userId);
    } catch (Exception $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $e;
    }
  }

  public static function getHistory(int $viewerId, ?int $targetUserId, int $limit, int $offset, bool $isAdmin): array {
    $pdo = db();
    $pool = self::ensurePool($pdo);
    $poolId = (int)$pool['id'];

    $userId = $targetUserId ?? $viewerId;
    if ($userId !== $viewerId && !$isAdmin) {
      throw new LiquidityServiceException('forbidden', 403);
    }

    $limit = max(1, min($limit, 100));
    $offset = max(0, $offset);

    $stmt = $pdo->prepare(
      "SELECT id, pool_id, user_id, event_type, nft_id, btc_amount, brl_amount, shares_delta, memo, created_at
       FROM " . self::EVENTS_TABLE . " WHERE pool_id = ? AND user_id = ?
       ORDER BY created_at DESC LIMIT ? OFFSET ?"
    );
    $stmt->bindValue(1, $poolId, PDO::PARAM_INT);
    $stmt->bindValue(2, $userId, PDO::PARAM_INT);
    $stmt->bindValue(3, $limit, PDO::PARAM_INT);
    $stmt->bindValue(4, $offset, PDO::PARAM_INT);
    $stmt->execute();

    return [
      'events' => $stmt->fetchAll(PDO::FETCH_ASSOC),
      'limit' => $limit,
      'offset' => $offset,
      'user_id' => $userId,
      'pool_id' => $poolId,
    ];
  }

  public static function ensurePool(PDO $pdo): array {
    $stmt = $pdo->prepare("SELECT * FROM " . self::POOL_TABLE . " ORDER BY id ASC LIMIT 1");
    $stmt->execute();
    $pool = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($pool) {
      return $pool;
    }

    $insert = $pdo->prepare(
      "INSERT INTO " . self::POOL_TABLE . " (name, total_nfts, total_shares, btc_reserve, brl_reserve) VALUES (?,?,?,?,?)"
    );
    $insert->execute([self::DEFAULT_POOL_NAME, 0, 0, 0, 0]);
    $poolId = (int)$pdo->lastInsertId();

    $rulesInsert = $pdo->prepare(
      "INSERT INTO " . self::POOL_RULES_TABLE . " (pool_id, deposit_btc_reward, withdraw_btc_cost, withdraw_brl_cost)
       VALUES (?,?,?,?)"
    );
    $rulesInsert->execute([
      $poolId,
      self::DEFAULT_DEPOSIT_BTC_REWARD,
      self::DEFAULT_WITHDRAW_BTC_COST,
      self::DEFAULT_WITHDRAW_BRL_COST,
    ]);

    $stmt = $pdo->prepare("SELECT * FROM " . self::POOL_TABLE . " WHERE id = ?");
    $stmt->execute([$poolId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
  }

  public static function getPoolRules(PDO $pdo, int $poolId, bool $forUpdate = false): array {
    $suffix = $forUpdate ? ' FOR UPDATE' : '';
    $stmt = $pdo->prepare(
      "SELECT deposit_btc_reward, withdraw_btc_cost, withdraw_brl_cost FROM " . self::POOL_RULES_TABLE . " WHERE pool_id = ?" . $suffix
    );
    $stmt->execute([$poolId]);
    $rules = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($rules) {
      return $rules;
    }
    $insert = $pdo->prepare(
      "INSERT INTO " . self::POOL_RULES_TABLE . " (pool_id, deposit_btc_reward, withdraw_btc_cost, withdraw_brl_cost)\n       VALUES (?,?,?,?)"
    );
    $insert->execute([
      $poolId,
      self::DEFAULT_DEPOSIT_BTC_REWARD,
      self::DEFAULT_WITHDRAW_BTC_COST,
      self::DEFAULT_WITHDRAW_BRL_COST,
    ]);
    $stmt->execute([$poolId]);
    $rules = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($rules) {
      return $rules;
    }
    return [
      'deposit_btc_reward' => self::DEFAULT_DEPOSIT_BTC_REWARD,
      'withdraw_btc_cost' => self::DEFAULT_WITHDRAW_BTC_COST,
      'withdraw_brl_cost' => self::DEFAULT_WITHDRAW_BRL_COST,
    ];
  }

  public static function ensureShareAsset(PDO $pdo): int {
    $stmt = $pdo->prepare("SELECT id FROM assets WHERE type='share' AND symbol = ? LIMIT 1");
    $stmt->execute([self::SHARE_ASSET_SYMBOL]);
    $existing = $stmt->fetchColumn();
    if ($existing) {
      return (int)$existing;
    }
    $insert = $pdo->prepare("INSERT INTO assets(type, symbol, metadata_json) VALUES('share', ?, NULL)");
    $insert->execute([self::SHARE_ASSET_SYMBOL]);
    return (int)$pdo->lastInsertId();
  }

  private static function lockPool(PDO $pdo, int $poolId): array {
    $stmt = $pdo->prepare("SELECT * FROM " . self::POOL_TABLE . " WHERE id = ? FOR UPDATE");
    $stmt->execute([$poolId]);
    $pool = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$pool) {
      throw new LiquidityServiceException('pool_not_found', 500);
    }
    return $pool;
  }

  private static function ensureAccount(PDO $pdo, string $ownerType, int $ownerId, string $currency, string $purpose): int {
    $stmt = $pdo->prepare(
      "SELECT id FROM accounts WHERE owner_type = ? AND owner_id = ? AND currency = ? AND purpose = ? LIMIT 1"
    );
    $stmt->execute([$ownerType, $ownerId, $currency, $purpose]);
    $accountId = $stmt->fetchColumn();
    if ($accountId) {
      return (int)$accountId;
    }
    $insert = $pdo->prepare(
      "INSERT INTO accounts(owner_type, owner_id, currency, purpose) VALUES (?,?,?,?)"
    );
    $insert->execute([$ownerType, $ownerId, $currency, $purpose]);
    return (int)$pdo->lastInsertId();
  }

  private static function ensureShareAccount(PDO $pdo, int $userId): int {
    return self::ensureAccount($pdo, 'user', $userId, 'SHARE', 'escrow');
  }

  private static function lockAccounts(PDO $pdo, array $accountIds): void {
    $stmt = $pdo->prepare('SELECT id FROM accounts WHERE id = ? FOR UPDATE');
    foreach ($accountIds as $accountId) {
      $stmt->execute([(int)$accountId]);
    }
  }

  private static function getAccountBalance(PDO $pdo, int $accountId): float {
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(debit - credit),0) FROM entries WHERE account_id = ?');
    $stmt->execute([$accountId]);
    return (float)$stmt->fetchColumn();
  }
}