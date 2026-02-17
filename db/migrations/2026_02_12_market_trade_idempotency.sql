CREATE TABLE IF NOT EXISTS market_trade_idempotency (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  market_id INT NOT NULL,
  idempotency_key VARCHAR(128) NOT NULL,
  trade_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_market_trade_idempotency (user_id, market_id, idempotency_key),
  KEY idx_market_trade_idempotency_trade (trade_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
