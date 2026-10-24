-- Расходы из выписки хостера (var_expense)
CREATE TABLE IF NOT EXISTS billing_expenses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  external_id VARCHAR(64) UNIQUE,
  description TEXT,
  expense_date DATE NOT NULL,
  amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  amount_without_discount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  account_ref VARCHAR(128) NULL,
  provider_name VARCHAR(255) NULL,
  matched_node_id INT NULL,
  matched_domain_id INT NULL,
  matched_payment_id INT NULL,
  match_type ENUM('auto','manual','none') DEFAULT 'none',
  raw_data JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_expense_date (expense_date),
  INDEX idx_account_ref (account_ref),
  INDEX idx_matched_node (matched_node_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Домены
CREATE TABLE IF NOT EXISTS domains (
  id INT AUTO_INCREMENT PRIMARY KEY,
  external_id VARCHAR(64) NULL,
  name VARCHAR(255) NOT NULL UNIQUE,
  zone VARCHAR(32) NULL,
  project VARCHAR(255) NULL,
  registrar VARCHAR(255) NULL,
  provider_id INT NULL,
  provider_name VARCHAR(255) NULL,
  node_id INT NULL,
  expires_at DATE NULL,
  price_per_year DECIMAL(10,2) NULL,
  currency VARCHAR(3) DEFAULT 'RUB',
  auto_renew TINYINT(1) DEFAULT 1,
  status ENUM('active','expired','unknown') DEFAULT 'active',
  account_ref VARCHAR(128) NULL,
  notes TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_domain_name (name),
  INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- История импортов
CREATE TABLE IF NOT EXISTS billing_imports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  type ENUM('payments','expenses','domains') NOT NULL,
  filename VARCHAR(255) NULL,
  imported_by INT NULL,
  total_rows INT DEFAULT 0,
  imported_rows INT DEFAULT 0,
  matched_rows INT DEFAULT 0,
  status ENUM('pending','done','error') DEFAULT 'pending',
  report JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Связь расходов с платежами если нужно
ALTER TABLE billing_expenses
  ADD CONSTRAINT fk_expense_node FOREIGN KEY (matched_node_id) REFERENCES nodes(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_expense_domain FOREIGN KEY (matched_domain_id) REFERENCES domains(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_expense_payment FOREIGN KEY (matched_payment_id) REFERENCES payments(id) ON DELETE SET NULL;
