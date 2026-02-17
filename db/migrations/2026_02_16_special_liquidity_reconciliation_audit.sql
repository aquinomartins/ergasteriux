-- Auditoria periódica entre razão contábil (entries/accounts) e espelho special_liquidity_assets.
-- Fonte de verdade para compras do mercado: saldo de entries da conta BRL/cash do usuário.
-- special_liquidity_assets.brl deve ser mantida por processo derivado (assíncrono) e reconciliada por este relatório.

CREATE TABLE IF NOT EXISTS special_liquidity_reconciliation_audit (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  user_id BIGINT NOT NULL,
  ledger_brl DECIMAL(24,8) NOT NULL,
  mirror_brl DECIMAL(24,8) NOT NULL,
  delta_brl DECIMAL(24,8) NOT NULL,
  audited_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_slra_user_audited (user_id, audited_at),
  INDEX idx_slra_audited (audited_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO special_liquidity_reconciliation_audit (user_id, ledger_brl, mirror_brl, delta_brl)
SELECT
  u.id AS user_id,
  ROUND(COALESCE(SUM(e.debit - e.credit), 0), 8) AS ledger_brl,
  ROUND(COALESCE(sla.brl, 0), 8) AS mirror_brl,
  ROUND(COALESCE(SUM(e.debit - e.credit), 0) - COALESCE(sla.brl, 0), 8) AS delta_brl
FROM users u
LEFT JOIN accounts a
  ON a.owner_type = 'user'
 AND a.owner_id = u.id
 AND a.currency = 'BRL'
 AND a.purpose = 'cash'
LEFT JOIN entries e
  ON e.account_id = a.id
LEFT JOIN special_liquidity_assets sla
  ON sla.user_id = u.id
GROUP BY u.id, sla.brl
HAVING ABS(ROUND(COALESCE(SUM(e.debit - e.credit), 0) - COALESCE(sla.brl, 0), 8)) > 0.00000001;

-- Consulta rápida para jobs/cron (sem persistir histórico):
--
-- SELECT
--   u.id AS user_id,
--   ROUND(COALESCE(SUM(e.debit - e.credit), 0), 8) AS ledger_brl,
--   ROUND(COALESCE(sla.brl, 0), 8) AS mirror_brl,
--   ROUND(COALESCE(SUM(e.debit - e.credit), 0) - COALESCE(sla.brl, 0), 8) AS delta_brl
-- FROM users u
-- LEFT JOIN accounts a
--   ON a.owner_type = 'user'
--  AND a.owner_id = u.id
--  AND a.currency = 'BRL'
--  AND a.purpose = 'cash'
-- LEFT JOIN entries e
--   ON e.account_id = a.id
-- LEFT JOIN special_liquidity_assets sla
--   ON sla.user_id = u.id
-- GROUP BY u.id, sla.brl
-- HAVING ABS(ROUND(COALESCE(SUM(e.debit - e.credit), 0) - COALESCE(sla.brl, 0), 8)) > 0.00000001;
