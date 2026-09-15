-- Mi Dinero · Pagos mensuales inteligentes
-- Esta migración también se aplica automáticamente desde FinanceSchema::ensure().

ALTER TABLE monthly_payments
  ADD COLUMN IF NOT EXISTS paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER amount,
  ADD COLUMN IF NOT EXISTS amount_overridden TINYINT(1) NOT NULL DEFAULT 0 AFTER paid_amount,
  ADD COLUMN IF NOT EXISTS due_date_overridden TINYINT(1) NOT NULL DEFAULT 0 AFTER amount_overridden,
  ADD COLUMN IF NOT EXISTS skipped_at DATETIME DEFAULT NULL AFTER paid_at,
  ADD COLUMN IF NOT EXISTS updated_by_user_id INT UNSIGNED DEFAULT NULL AFTER skipped_at,
  ADD COLUMN IF NOT EXISTS updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

ALTER TABLE monthly_payments
  MODIFY status ENUM('pending','partial','paid','skipped') NOT NULL DEFAULT 'pending';

CREATE TABLE IF NOT EXISTS monthly_payment_parts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  monthly_payment_id BIGINT UNSIGNED NOT NULL,
  transaction_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  paid_at DATETIME NOT NULL,
  created_by_user_id INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  UNIQUE KEY uq_mpp_transaction(transaction_id),
  KEY idx_mpp_payment(monthly_payment_id),
  KEY idx_mpp_user_payment(user_id,monthly_payment_id),
  CONSTRAINT fk_mpp_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_mpp_payment FOREIGN KEY(monthly_payment_id) REFERENCES monthly_payments(id) ON DELETE CASCADE,
  CONSTRAINT fk_mpp_tx FOREIGN KEY(transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_mpp_actor FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE monthly_payments mp
LEFT JOIN transactions t ON t.id=mp.transaction_id AND t.user_id=mp.user_id
SET mp.paid_amount=CASE
  WHEN mp.status='paid' AND mp.paid_amount=0 THEN COALESCE(t.amount,mp.amount)
  ELSE mp.paid_amount END
WHERE mp.status='paid';

INSERT IGNORE INTO monthly_payment_parts
  (user_id,monthly_payment_id,transaction_id,amount,paid_at,created_by_user_id,created_at)
SELECT mp.user_id,mp.id,mp.transaction_id,
       COALESCE(t.amount,mp.amount),
       COALESCE(mp.paid_at,t.occurred_at,mp.created_at),
       COALESCE(t.created_by_user_id,t.user_id),
       COALESCE(t.created_at,mp.created_at)
FROM monthly_payments mp
JOIN transactions t ON t.id=mp.transaction_id AND t.user_id=mp.user_id
WHERE mp.status='paid' AND mp.transaction_id IS NOT NULL;
