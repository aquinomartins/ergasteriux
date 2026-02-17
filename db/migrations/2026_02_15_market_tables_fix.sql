-- Fix LMSR tables to avoid name collisions with core positions/trades
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
