-- ====== CORE ======
CREATE TABLE users (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(160) UNIQUE,
  phone VARCHAR(40) DEFAULT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  confirmed TINYINT DEFAULT 0,
  is_admin TINYINT DEFAULT 0,
  is_system TINYINT DEFAULT 0
);

CREATE TABLE assets (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  type ENUM('bitcoin','nft','share','frame','chassis','gallery_space') NOT NULL,
  symbol VARCHAR(64),                -- p/ BTC: 'BTC'; p/ cotas: código; opcional p/ NFT
  parent_asset_id BIGINT NULL,       -- cota de obra/galeria aponta p/ asset pai
  metadata_json JSON NULL,
  INDEX(parent_asset_id),
  FOREIGN KEY (parent_asset_id) REFERENCES assets(id)
);

CREATE TABLE asset_instances (       -- instâncias únicas: um NFT, um chassis específico etc.
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  asset_id BIGINT NOT NULL,
  chain VARCHAR(32),                 -- 'bitcoin','eth','polygon'...
  contract_addr VARCHAR(128),
  token_id VARCHAR(128),
  serial VARCHAR(64),
  metadata_json JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX(asset_id),
  FOREIGN KEY (asset_id) REFERENCES assets(id)
);

-- Contas do razão
CREATE TABLE accounts (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  owner_type ENUM('user','org') DEFAULT 'user',
  owner_id BIGINT NOT NULL,
  currency VARCHAR(16) NOT NULL,     -- 'BRL','USD','BTC'...
  purpose ENUM('cash','bitcoin_wallet','nft_inventory','fees','revenue','escrow') NOT NULL,
  UNIQUE KEY uniq_owner_cur_purpose(owner_type, owner_id, currency, purpose),
  INDEX(owner_type, owner_id)
);

CREATE TABLE journals (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  occurred_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ref_type ENUM('deposit','withdraw','trade','prize','lease','mint','buy','sell','fee','market_purchase','bid','prediction_trade') NOT NULL,
  ref_id BIGINT NULL,
  memo VARCHAR(255)
);

CREATE TABLE entries (               -- dupla entrada (débito/crédito)
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  journal_id BIGINT NOT NULL,
  account_id BIGINT NOT NULL,
  debit DECIMAL(24,8) NOT NULL DEFAULT 0,
  credit DECIMAL(24,8) NOT NULL DEFAULT 0,
  FOREIGN KEY (journal_id) REFERENCES journals(id) ON DELETE CASCADE,
  FOREIGN KEY (account_id) REFERENCES accounts(id),
  INDEX(journal_id),
  INDEX(account_id)
);

-- Movimentações de ativos (qtds)
CREATE TABLE asset_moves (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  journal_id BIGINT NOT NULL,
  asset_id BIGINT NULL,                 -- para cotas/ativos fungíveis
  asset_instance_id BIGINT NULL,        -- para NFTs/itens únicos
  qty DECIMAL(24,8) NOT NULL DEFAULT 0, -- p/ NFT usar 1.0
  from_account_id BIGINT NULL,
  to_account_id BIGINT NULL,
  FOREIGN KEY (journal_id) REFERENCES journals(id) ON DELETE CASCADE,
  FOREIGN KEY (asset_id) REFERENCES assets(id),
  FOREIGN KEY (asset_instance_id) REFERENCES asset_instances(id),
  FOREIGN KEY (from_account_id) REFERENCES accounts(id),
  FOREIGN KEY (to_account_id) REFERENCES accounts(id),
  INDEX(asset_id),
  INDEX(asset_instance_id)
);

-- Posições derivadas (atualizadas por trigger p/ consultas rápidas)
CREATE TABLE positions (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  owner_type ENUM('user','org') DEFAULT 'user',
  owner_id BIGINT NOT NULL,
  asset_id BIGINT NOT NULL,
  qty DECIMAL(24,8) NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_pos(owner_type, owner_id, asset_id),
  INDEX(owner_type, owner_id),
  FOREIGN KEY (asset_id) REFERENCES assets(id)
);

CREATE TABLE liquidity_game_states (
  user_id BIGINT PRIMARY KEY,
  state_json JSON NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE special_liquidity_assets (
  user_id BIGINT PRIMARY KEY,
  bitcoin DECIMAL(24,8) NOT NULL DEFAULT 0,
  nft INT NOT NULL DEFAULT 0,
  brl DECIMAL(24,2) NOT NULL DEFAULT 0,
  quotas DECIMAL(24,8) NOT NULL DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

DELIMITER $$
CREATE TRIGGER trg_positions_upsert
AFTER INSERT ON asset_moves
FOR EACH ROW
BEGIN
  IF NEW.to_account_id IS NOT NULL THEN
    INSERT INTO positions (owner_type, owner_id, asset_id, qty)
    SELECT a.owner_type, a.owner_id, COALESCE(NEW.asset_id,
           (SELECT asset_id FROM asset_instances WHERE id=NEW.asset_instance_id)), NEW.qty
    FROM accounts a WHERE a.id = NEW.to_account_id
    ON DUPLICATE KEY UPDATE qty = qty + NEW.qty;
  END IF;

  IF NEW.from_account_id IS NOT NULL THEN
    INSERT INTO positions (owner_type, owner_id, asset_id, qty)
    SELECT a.owner_type, a.owner_id, COALESCE(NEW.asset_id,
           (SELECT asset_id FROM asset_instances WHERE id=NEW.asset_instance_id)), -NEW.qty
    FROM accounts a WHERE a.id = NEW.from_account_id
    ON DUPLICATE KEY UPDATE qty = qty - NEW.qty;
  END IF;
END$$
DELIMITER ;

-- ====== MERCADO (leilões / trades) ======
CREATE TABLE orders (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  user_id BIGINT NOT NULL,
  side ENUM('buy','sell') NOT NULL,
  asset_id BIGINT NULL,
  asset_instance_id BIGINT NULL,
  qty DECIMAL(24,8) NOT NULL,
  price DECIMAL(24,8) NOT NULL,
  status ENUM('open','filled','cancelled') DEFAULT 'open',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE trades (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  buy_order_id BIGINT,
  sell_order_id BIGINT,
  qty DECIMAL(24,8) NOT NULL,
  price DECIMAL(24,8) NOT NULL,
  journal_id BIGINT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE auctions (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  seller_id BIGINT NOT NULL,
  asset_id BIGINT NULL,
  asset_instance_id BIGINT NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  reserve_price DECIMAL(24,8) DEFAULT 0,
  status ENUM('draft','running','ended','settled') DEFAULT 'draft',
  FOREIGN KEY (seller_id) REFERENCES users(id)
);

CREATE TABLE bids (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  auction_id BIGINT NOT NULL,
  bidder_id BIGINT NOT NULL,
  amount DECIMAL(24,8) NOT NULL,
  status ENUM('valid','outbid','winner','cancelled') DEFAULT 'valid',
  journal_id BIGINT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (auction_id) REFERENCES auctions(id),
  FOREIGN KEY (bidder_id) REFERENCES users(id)
);

-- ====== MERCADO DE PREVISÕES ======
-- Fluxo:
-- 1) prediction_markets.status='draft' até o criador definir datas e outcomes.
-- 2) Ao iniciar (status='running'), accepts trades/posições nas outcomes.
-- 3) Na resolução (status='resolved'), marcar is_winner=1 e payout=1.
-- 4) Em cancelamento (status='cancelled'), outcomes sem vencedor.
CREATE TABLE prediction_markets (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  title VARCHAR(200) NOT NULL,
  description TEXT,
  category VARCHAR(120),
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  resolution_date DATETIME,
  status ENUM('draft','running','resolved','cancelled') DEFAULT 'draft',
  created_by BIGINT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX(status),
  FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE prediction_outcomes (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  market_id BIGINT NOT NULL,
  label VARCHAR(80) NOT NULL, -- ex.: "SIM", "NÃO"
  payout TINYINT NOT NULL DEFAULT 0, -- 0 ou 1
  is_winner TINYINT NOT NULL DEFAULT 0,
  INDEX(market_id),
  FOREIGN KEY (market_id) REFERENCES prediction_markets(id) ON DELETE CASCADE
);

-- ====== MERCADOS BINÁRIOS LMSR (SIM/NÃO) — 2026 ======
CREATE TABLE markets (
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

CREATE TABLE market_state (
  market_id BIGINT PRIMARY KEY,
  q_sim INT NOT NULL DEFAULT 0,
  q_nao INT NOT NULL DEFAULT 0,
  cost_total DECIMAL(18,8) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY (market_id) REFERENCES markets(id) ON DELETE CASCADE
);

CREATE TABLE market_positions (
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

CREATE TABLE market_trades (
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

CREATE TABLE claims (
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

-- ====== MERCADO PREDITIVO (LEGADO) ======
CREATE TABLE mp_users (
  id INT PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  balance DECIMAL(18,6) NOT NULL DEFAULT 0,
  is_admin TINYINT(1) NOT NULL DEFAULT 0
);

CREATE TABLE mp_markets (
  id INT PRIMARY KEY AUTO_INCREMENT,
  title VARCHAR(255) NOT NULL,
  description TEXT,
  created_at DATETIME NOT NULL,
  close_at DATETIME NOT NULL,
  status ENUM('open', 'closed', 'resolved') NOT NULL DEFAULT 'open',
  result ENUM('yes', 'no') DEFAULT NULL,
  liquidity_yes DECIMAL(18,6) NOT NULL DEFAULT 0,
  liquidity_no DECIMAL(18,6) NOT NULL DEFAULT 0
);

CREATE TABLE mp_positions (
  id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT NOT NULL,
  market_id INT NOT NULL,
  side ENUM('yes', 'no') NOT NULL,
  shares DECIMAL(18,6) NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_position (user_id, market_id, side),
  CONSTRAINT fk_positions_user FOREIGN KEY (user_id) REFERENCES mp_users(id) ON DELETE CASCADE,
  CONSTRAINT fk_positions_market FOREIGN KEY (market_id) REFERENCES mp_markets(id) ON DELETE CASCADE
);

CREATE TABLE mp_transactions (
  id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT NOT NULL,
  market_id INT DEFAULT NULL,
  side ENUM('yes', 'no') DEFAULT NULL,
  shares DECIMAL(18,6) NOT NULL DEFAULT 0,
  price DECIMAL(18,6) NOT NULL DEFAULT 0,
  total_cost DECIMAL(18,6) NOT NULL DEFAULT 0,
  type ENUM('buy', 'payout', 'admin_adjust') NOT NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_transactions_user FOREIGN KEY (user_id) REFERENCES mp_users(id) ON DELETE CASCADE,
  CONSTRAINT fk_transactions_market FOREIGN KEY (market_id) REFERENCES mp_markets(id) ON DELETE SET NULL
);

-- ====== PRODUÇÃO ======
CREATE TABLE works (                   -- Obras
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  asset_instance_id BIGINT NOT NULL,
  title VARCHAR(160) NOT NULL,
  artist_id BIGINT NOT NULL,
  specs_json JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (asset_instance_id) REFERENCES asset_instances(id),
  FOREIGN KEY (artist_id) REFERENCES users(id)
);

CREATE TABLE chassis (                 -- Chassis "carta em branco"
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  asset_instance_id BIGINT NOT NULL,
  size VARCHAR(64),
  material VARCHAR(64),
  status ENUM('blank','used') DEFAULT 'blank',
  FOREIGN KEY (asset_instance_id) REFERENCES asset_instances(id)
);

CREATE TABLE frames (                  -- Molduras
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  asset_instance_id BIGINT NOT NULL,
  size VARCHAR(64),
  material VARCHAR(64),
  status ENUM('free','used') DEFAULT 'free',
  FOREIGN KEY (asset_instance_id) REFERENCES asset_instances(id)
);

CREATE TABLE galleries (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(160) NOT NULL,
  address VARCHAR(200),
  owner_id BIGINT NOT NULL,
  FOREIGN KEY (owner_id) REFERENCES users(id)
);

CREATE TABLE gallery_spaces (          -- espaços de exposição
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  asset_instance_id BIGINT NOT NULL,
  gallery_id BIGINT NOT NULL,
  label VARCHAR(64),
  size VARCHAR(64),
  status ENUM('free','occupied') DEFAULT 'free',
  FOREIGN KEY (asset_instance_id) REFERENCES asset_instances(id),
  FOREIGN KEY (gallery_id) REFERENCES galleries(id)
);

-- ====== PRÊMIOS / ESCOLAS (simples) ======
CREATE TABLE prizes (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  rules_json JSON NULL
);

CREATE TABLE prize_grants (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  prize_id BIGINT NOT NULL,
  user_id BIGINT NOT NULL,
  amount DECIMAL(24,8) DEFAULT 0,
  journal_id BIGINT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE schools (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(160) NOT NULL,
  city VARCHAR(120),
  metadata_json JSON NULL
);