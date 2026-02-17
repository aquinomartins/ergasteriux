-- LMSR prediction markets (binary)
CREATE TABLE IF NOT EXISTS markets (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  title VARCHAR(255) NOT NULL,
  description TEXT,
  b DECIMAL(18,8) NOT NULL,
  fee_rate DECIMAL(10,8) NOT NULL DEFAULT 0.01,
  buffer_rate DECIMAL(10,8) NOT NULL DEFAULT 0.15,
  collateral_locked DECIMAL(18,8) NOT NULL,
  status ENUM('open','resolved','closed') DEFAULT 'open',
  outcome ENUM('SIM','NAO') NULL,
  created_by BIGINT NOT NULL,
  created_at DATETIME NOT NULL,
  resolved_at DATETIME NULL,
  INDEX(status),
  INDEX(created_by),
  FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS market_state (
  market_id BIGINT PRIMARY KEY,
  q_sim INT NOT NULL DEFAULT 0,
  q_nao INT NOT NULL DEFAULT 0,
  cost_total DECIMAL(18,8) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY (market_id) REFERENCES markets(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS market_positions (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  market_id BIGINT NOT NULL,
  user_id BIGINT NOT NULL,
  side ENUM('SIM','NAO') NOT NULL,
  shares INT NOT NULL,
  avg_cost DECIMAL(18,8) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uniq_market_user_side (market_id, user_id, side),
  INDEX(market_id),
  INDEX(user_id),
  FOREIGN KEY (market_id) REFERENCES markets(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS market_trades (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  market_id BIGINT NOT NULL,
  user_id BIGINT NOT NULL,
  side ENUM('SIM','NAO') NOT NULL,
  shares INT NOT NULL,
  delta_cost DECIMAL(18,8) NOT NULL,
  fee DECIMAL(18,8) NOT NULL,
  total_paid DECIMAL(18,8) NOT NULL,
  p_sim_before DECIMAL(18,10),
  p_sim_after DECIMAL(18,10),
  q_sim_before INT,
  q_nao_before INT,
  q_sim_after INT,
  q_nao_after INT,
  created_at DATETIME NOT NULL,
  INDEX(market_id),
  INDEX(user_id),
  FOREIGN KEY (market_id) REFERENCES markets(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS claims (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  market_id BIGINT NOT NULL,
  user_id BIGINT NOT NULL,
  shares_won INT NOT NULL,
  amount DECIMAL(18,8) NOT NULL,
  status ENUM('pending','paid') DEFAULT 'pending',
  created_at DATETIME NOT NULL,
  paid_at DATETIME NULL,
  UNIQUE KEY uniq_market_user (market_id, user_id),
  INDEX(market_id),
  INDEX(user_id),
  FOREIGN KEY (market_id) REFERENCES markets(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

ALTER TABLE accounts
  ADD COLUMN IF NOT EXISTS market_id BIGINT NULL,
  ADD COLUMN IF NOT EXISTS user_id BIGINT NULL;

ALTER TABLE entries
  ADD COLUMN IF NOT EXISTS memo VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS ref_type VARCHAR(50) NULL,
  ADD COLUMN IF NOT EXISTS ref_id BIGINT NULL,
  ADD COLUMN IF NOT EXISTS created_at DATETIME NULL;