-- Ensure confirmation tokens support creation and expiration controls.

SET @has_created_at := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'user_confirmations'
    AND column_name = 'created_at'
);
SET @sql := IF(
  @has_created_at = 0,
  'ALTER TABLE user_confirmations ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_expires_at := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'user_confirmations'
    AND column_name = 'expires_at'
);
SET @sql := IF(
  @has_expires_at = 0,
  'ALTER TABLE user_confirmations ADD COLUMN expires_at DATETIME NOT NULL DEFAULT (CURRENT_TIMESTAMP + INTERVAL 24 HOUR)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_expires_idx := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'user_confirmations'
    AND index_name = 'idx_user_confirmations_expires_at'
);
SET @sql := IF(
  @has_expires_idx = 0,
  'CREATE INDEX idx_user_confirmations_expires_at ON user_confirmations (expires_at)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS confirmation_resend_attempts (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  email VARCHAR(255) NOT NULL,
  ip_address VARCHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_confirmation_resend_email_created_at (email, created_at),
  INDEX idx_confirmation_resend_ip_created_at (ip_address, created_at)
);
