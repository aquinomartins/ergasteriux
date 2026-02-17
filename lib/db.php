<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/system_user.php';
function db() {
  static $pdo = null;
  if ($pdo) return $pdo;

  $host = env_or_default('DB_HOST', 'localhost');
  $dbname = env_or_default('DB_NAME', 'oftalmol_fase4');
  $user = env_or_default('DB_USER', 'oftalmol_aquino');
  $pass = env_or_default('DB_PASS', '#Fend721fine170');

  $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
  $pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
  ]);
  ensure_prediction_schema($pdo);
  ensure_mp_prediction_schema($pdo);
  ensure_market_system_user($pdo);
  return $pdo;
}

function ensure_prediction_schema(PDO $pdo): void {
  $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
  $check->execute(['prediction_markets']);
  if ((int)$check->fetchColumn() > 0) {
    return;
  }

  $pdo->exec("CREATE TABLE IF NOT EXISTS prediction_markets (
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
  )");

  $pdo->exec("CREATE TABLE IF NOT EXISTS prediction_outcomes (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    market_id BIGINT NOT NULL,
    label VARCHAR(80) NOT NULL,
    payout TINYINT NOT NULL DEFAULT 0,
    is_winner TINYINT NOT NULL DEFAULT 0,
    INDEX(market_id),
    FOREIGN KEY (market_id) REFERENCES prediction_markets(id) ON DELETE CASCADE
  )");
}

function ensure_mp_prediction_schema(PDO $pdo): void {
  $pdo->exec("CREATE TABLE IF NOT EXISTS mp_users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    balance DECIMAL(18,6) NOT NULL DEFAULT 0,
    is_admin TINYINT(1) NOT NULL DEFAULT 0
  )");

  $pdo->exec("CREATE TABLE IF NOT EXISTS mp_markets (
    id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    created_at DATETIME NOT NULL,
    close_at DATETIME NOT NULL,
    status ENUM('open', 'closed', 'resolved') NOT NULL DEFAULT 'open',
    result ENUM('yes', 'no') DEFAULT NULL,
    liquidity_yes DECIMAL(18,6) NOT NULL DEFAULT 0,
    liquidity_no DECIMAL(18,6) NOT NULL DEFAULT 0
  )");

  $pdo->exec("CREATE TABLE IF NOT EXISTS mp_positions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    market_id INT NOT NULL,
    side ENUM('yes', 'no') NOT NULL,
    shares DECIMAL(18,6) NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_position (user_id, market_id, side),
    CONSTRAINT fk_positions_user FOREIGN KEY (user_id) REFERENCES mp_users(id) ON DELETE CASCADE,
    CONSTRAINT fk_positions_market FOREIGN KEY (market_id) REFERENCES mp_markets(id) ON DELETE CASCADE
  )");

  $pdo->exec("CREATE TABLE IF NOT EXISTS mp_transactions (
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
  )");
}
