-- database.sql
-- Script de criação das tabelas do Mercado Preditivo

CREATE TABLE mp_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    balance DECIMAL(18,6) NOT NULL DEFAULT 0,
    is_admin TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mp_markets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    created_at DATETIME NOT NULL,
    close_at DATETIME NOT NULL,
    status ENUM('open', 'closed', 'resolved') NOT NULL DEFAULT 'open',
    result ENUM('yes', 'no') DEFAULT NULL,
    liquidity_yes DECIMAL(18,6) NOT NULL DEFAULT 0,
    liquidity_no DECIMAL(18,6) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mp_positions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    market_id INT NOT NULL,
    side ENUM('yes', 'no') NOT NULL,
    shares DECIMAL(18,6) NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_position (user_id, market_id, side),
    CONSTRAINT fk_positions_user FOREIGN KEY (user_id) REFERENCES mp_users(id) ON DELETE CASCADE,
    CONSTRAINT fk_positions_market FOREIGN KEY (market_id) REFERENCES mp_markets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mp_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;