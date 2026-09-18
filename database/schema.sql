SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(160) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  notify_email VARCHAR(160) DEFAULT NULL,
  notify_on_income TINYINT(1) NOT NULL DEFAULT 1,
  notify_on_expense TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  type ENUM('income','expense','both') NOT NULL DEFAULT 'expense',
  icon VARCHAR(20) NOT NULL DEFAULT '💳',
  color VARCHAR(20) NOT NULL DEFAULT '#2563eb',
  is_ant_expense TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cat_user (user_id,active),
  CONSTRAINT fk_cat_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS concepts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  default_amount DECIMAL(12,2) DEFAULT NULL,
  is_ant_expense TINYINT(1) NOT NULL DEFAULT 0,
  is_quick_access TINYINT(1) NOT NULL DEFAULT 0,
  quick_access_order TINYINT UNSIGNED DEFAULT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY fk_con_cat (category_id),
  KEY idx_con_user (user_id,category_id,active),
  KEY idx_con_quick (user_id,is_quick_access,quick_access_order),
  CONSTRAINT fk_con_cat FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
  CONSTRAINT fk_con_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS finance_migrations (
  migration_key VARCHAR(120) NOT NULL,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (migration_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS financial_accounts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  account_type ENUM('bank','wallet','cash','other') NOT NULL DEFAULT 'bank',
  icon VARCHAR(20) NOT NULL DEFAULT '🏦',
  opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  color VARCHAR(20) NOT NULL DEFAULT '#111827',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(id), KEY idx_fa_user(user_id,active),
  CONSTRAINT fk_fa_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS funds (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  icon VARCHAR(20) NOT NULL DEFAULT '💰',
  color VARCHAR(20) NOT NULL DEFAULT '#6b7280',
  target_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(id), KEY idx_fund_user(user_id,active),
  CONSTRAINT fk_fund_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  type ENUM('income','expense') NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  concept_id INT UNSIGNED DEFAULT NULL,
  account_id INT UNSIGNED DEFAULT NULL,
  fund_id INT UNSIGNED DEFAULT NULL,
  amount DECIMAL(12,2) NOT NULL,
  occurred_at DATETIME NOT NULL,
  description VARCHAR(255) DEFAULT NULL,
  payment_method VARCHAR(50) DEFAULT NULL,
  is_ant_expense TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY fk_tx_cat (category_id),
  KEY fk_tx_con (concept_id),
  KEY idx_tx_account (account_id),
  KEY idx_tx_fund (fund_id),
  KEY idx_tx_user_date (user_id,occurred_at),
  KEY idx_tx_type (user_id,type,occurred_at),
  KEY idx_tx_ant (user_id,is_ant_expense,occurred_at),
  CONSTRAINT fk_tx_cat FOREIGN KEY (category_id) REFERENCES categories(id),
  CONSTRAINT fk_tx_con FOREIGN KEY (concept_id) REFERENCES concepts(id) ON DELETE SET NULL,
  CONSTRAINT fk_tx_account FOREIGN KEY (account_id) REFERENCES financial_accounts(id) ON DELETE SET NULL,
  CONSTRAINT fk_tx_fund FOREIGN KEY (fund_id) REFERENCES funds(id) ON DELETE SET NULL,
  CONSTRAINT fk_tx_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS account_transfers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, user_id INT UNSIGNED NOT NULL, from_account_id INT UNSIGNED NOT NULL, to_account_id INT UNSIGNED NOT NULL, amount DECIMAL(14,2) NOT NULL, occurred_at DATETIME NOT NULL, description VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id), KEY idx_transfer_user_date(user_id,occurred_at), KEY idx_transfer_from(from_account_id), KEY idx_transfer_to(to_account_id),
  CONSTRAINT fk_transfer_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE, CONSTRAINT fk_transfer_from FOREIGN KEY(from_account_id) REFERENCES financial_accounts(id) ON DELETE CASCADE, CONSTRAINT fk_transfer_to FOREIGN KEY(to_account_id) REFERENCES financial_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS account_adjustments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  account_id INT UNSIGNED NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  occurred_at DATETIME NOT NULL,
  note VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  KEY idx_adj_user_date(user_id,occurred_at),
  KEY idx_adj_account(account_id),
  CONSTRAINT fk_adj_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_adj_account FOREIGN KEY(account_id) REFERENCES financial_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fund_allocations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, user_id INT UNSIGNED NOT NULL, fund_id INT UNSIGNED NOT NULL, amount DECIMAL(14,2) NOT NULL, occurred_at DATETIME NOT NULL, source_transaction_id BIGINT UNSIGNED DEFAULT NULL, note VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id), KEY idx_alloc_user_date(user_id,occurred_at), KEY idx_alloc_fund(fund_id), KEY idx_alloc_source(source_transaction_id),
  CONSTRAINT fk_alloc_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE, CONSTRAINT fk_alloc_fund FOREIGN KEY(fund_id) REFERENCES funds(id) ON DELETE CASCADE, CONSTRAINT fk_alloc_source FOREIGN KEY(source_transaction_id) REFERENCES transactions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recurring_payments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  concept_id INT UNSIGNED DEFAULT NULL,
  fund_id INT UNSIGNED DEFAULT NULL,
  name VARCHAR(140) NOT NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  due_day TINYINT UNSIGNED NOT NULL DEFAULT 1,
  icon VARCHAR(20) NOT NULL DEFAULT '📌',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY fk_rec_cat (category_id),
  KEY fk_rec_con (concept_id),
  KEY idx_rec_fund (fund_id),
  KEY idx_rec_user (user_id,active),
  CONSTRAINT fk_rec_cat FOREIGN KEY (category_id) REFERENCES categories(id),
  CONSTRAINT fk_rec_con FOREIGN KEY (concept_id) REFERENCES concepts(id) ON DELETE SET NULL,
  CONSTRAINT fk_rec_fund FOREIGN KEY (fund_id) REFERENCES funds(id) ON DELETE SET NULL,
  CONSTRAINT fk_rec_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recurring_incomes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  concept_id INT UNSIGNED DEFAULT NULL,
  account_id INT UNSIGNED DEFAULT NULL,
  name VARCHAR(140) NOT NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  income_day TINYINT UNSIGNED NOT NULL DEFAULT 1,
  icon VARCHAR(20) NOT NULL DEFAULT '💰',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ri_user_active (user_id,active),
  KEY fk_ri_category (category_id),
  KEY fk_ri_concept (concept_id),
  KEY idx_ri_account (account_id),
  CONSTRAINT fk_ri_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ri_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ri_concept FOREIGN KEY (concept_id) REFERENCES concepts(id) ON DELETE SET NULL,
  CONSTRAINT fk_ri_account FOREIGN KEY (account_id) REFERENCES financial_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monthly_income_expectations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  recurring_id INT UNSIGNED NOT NULL,
  period CHAR(7) NOT NULL,
  due_date DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_month_income (user_id,recurring_id,period),
  KEY idx_mie_user_period (user_id,period),
  KEY idx_mie_recurring (recurring_id),
  CONSTRAINT fk_mie_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_mie_recurring FOREIGN KEY (recurring_id) REFERENCES recurring_incomes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monthly_payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  recurring_id INT UNSIGNED NOT NULL,
  period CHAR(7) NOT NULL,
  due_date DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  amount_overridden TINYINT(1) NOT NULL DEFAULT 0,
  due_date_overridden TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('pending','partial','paid','skipped') NOT NULL DEFAULT 'pending',
  paid_at DATETIME DEFAULT NULL,
  skipped_at DATETIME DEFAULT NULL,
  updated_by_user_id INT UNSIGNED DEFAULT NULL,
  transaction_id BIGINT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_monthly (user_id,recurring_id,period),
  KEY fk_mp_rec (recurring_id),
  KEY fk_mp_tx (transaction_id),
  KEY idx_mp_status (user_id,period,status),
  KEY idx_mp_smart_status (user_id,status,due_date),
  CONSTRAINT fk_mp_rec FOREIGN KEY (recurring_id) REFERENCES recurring_payments(id) ON DELETE CASCADE,
  CONSTRAINT fk_mp_tx FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE SET NULL,
  CONSTRAINT fk_mp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_mp_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monthly_payment_parts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  monthly_payment_id BIGINT UNSIGNED NOT NULL,
  transaction_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  paid_at DATETIME NOT NULL,
  created_by_user_id INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mpp_transaction (transaction_id),
  KEY idx_mpp_payment (monthly_payment_id),
  KEY idx_mpp_user_payment (user_id,monthly_payment_id),
  CONSTRAINT fk_mpp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_mpp_payment FOREIGN KEY (monthly_payment_id) REFERENCES monthly_payments(id) ON DELETE CASCADE,
  CONSTRAINT fk_mpp_tx FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_mpp_actor FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS goals (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  period CHAR(7) NOT NULL,
  name VARCHAR(120) NOT NULL,
  type ENUM('savings','income','expense_limit') NOT NULL DEFAULT 'savings',
  target_amount DECIMAL(12,2) NOT NULL,
  fund_id INT UNSIGNED DEFAULT NULL,
  account_id INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_goal_user_period (user_id,period),
  KEY idx_goal_fund (fund_id),
  KEY idx_goal_account (account_id),
  CONSTRAINT fk_goal_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_goal_fund FOREIGN KEY (fund_id) REFERENCES funds(id) ON DELETE SET NULL,
  CONSTRAINT fk_goal_account FOREIGN KEY (account_id) REFERENCES financial_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS savings_movements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  goal_id INT UNSIGNED NOT NULL,
  fund_id INT UNSIGNED NOT NULL,
  movement_type ENUM('deposit','withdrawal') NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  from_account_id INT UNSIGNED DEFAULT NULL,
  to_account_id INT UNSIGNED DEFAULT NULL,
  account_transfer_id BIGINT UNSIGNED DEFAULT NULL,
  occurred_at DATETIME NOT NULL,
  note VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  KEY idx_sav_user_date(user_id,occurred_at),
  KEY idx_sav_goal(goal_id),
  KEY idx_sav_fund(fund_id),
  CONSTRAINT fk_sav_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_sav_goal FOREIGN KEY(goal_id) REFERENCES goals(id) ON DELETE CASCADE,
  CONSTRAINT fk_sav_fund FOREIGN KEY(fund_id) REFERENCES funds(id) ON DELETE CASCADE,
  CONSTRAINT fk_sav_from FOREIGN KEY(from_account_id) REFERENCES financial_accounts(id) ON DELETE SET NULL,
  CONSTRAINT fk_sav_to FOREIGN KEY(to_account_id) REFERENCES financial_accounts(id) ON DELETE SET NULL,
  CONSTRAINT fk_sav_transfer FOREIGN KEY(account_transfer_id) REFERENCES account_transfers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS realtime_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  event_type VARCHAR(60) NOT NULL,
  payload_json TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ev_user_id (user_id,id),
  CONSTRAINT fk_ev_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  recipient VARCHAR(160) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  status VARCHAR(30) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY fk_email_user (user_id),
  CONSTRAINT fk_email_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;

-- Ahorro estable v6
CREATE TABLE IF NOT EXISTS savings_goal_links (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  goal_id INT UNSIGNED NOT NULL,
  fund_id INT UNSIGNED NOT NULL,
  preferred_account_id INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  UNIQUE KEY uq_sgl_user_goal(user_id,goal_id),
  KEY idx_sgl_fund(user_id,fund_id),
  KEY idx_sgl_account(user_id,preferred_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS savings_ledger (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  goal_id INT UNSIGNED NOT NULL,
  fund_id INT UNSIGNED NOT NULL,
  movement_type VARCHAR(20) NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  from_account_id INT UNSIGNED DEFAULT NULL,
  to_account_id INT UNSIGNED DEFAULT NULL,
  account_transfer_id BIGINT UNSIGNED DEFAULT NULL,
  occurred_at DATETIME NOT NULL,
  note VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  KEY idx_sl_user_date(user_id,occurred_at),
  KEY idx_sl_goal(user_id,goal_id),
  KEY idx_sl_fund(user_id,fund_id),
  KEY idx_sl_accounts(user_id,from_account_id,to_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
