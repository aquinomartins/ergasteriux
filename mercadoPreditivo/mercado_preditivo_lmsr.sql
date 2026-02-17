-- Mercado preditivo LMSR (YES/NO)

CREATE TABLE pm_markets (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  slug VARCHAR(80) UNIQUE,
  title VARCHAR(180) NOT NULL,
  description TEXT,
  status ENUM('open','closed','resolved','cancelled') DEFAULT 'open',
  close_at DATETIME NULL,
  resolved_outcome ENUM('yes','no') NULL,
  b DECIMAL(18,6) NOT NULL,
  q_yes DECIMAL(18,6) NOT NULL DEFAULT 0,
  q_no DECIMAL(18,6) NOT NULL DEFAULT 0,
  created_by BIGINT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE pm_positions (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  market_id BIGINT NOT NULL,
  user_id BIGINT NOT NULL,
  shares_yes DECIMAL(18,6) NOT NULL DEFAULT 0,
  shares_no DECIMAL(18,6) NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_market_user (market_id, user_id),
  INDEX(user_id),
  INDEX(market_id)
);

CREATE TABLE pm_trades (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  market_id BIGINT NOT NULL,
  user_id BIGINT NOT NULL,
  side ENUM('buy','sell') NOT NULL,
  outcome ENUM('yes','no') NOT NULL,
  shares DECIMAL(18,6) NOT NULL,
  cash_delta_brl DECIMAL(18,6) NOT NULL,
  price_yes_before DECIMAL(18,10) NOT NULL,
  price_yes_after DECIMAL(18,10) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE pm_settlements (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  market_id BIGINT NOT NULL UNIQUE,
  resolved_outcome ENUM('yes','no') NOT NULL,
  settled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE pm_cancellations (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  market_id BIGINT NOT NULL UNIQUE,
  cancelled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
