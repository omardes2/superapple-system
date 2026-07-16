-- ترقية: صفحة المطالبات المالية (مستقلة عن جدول العملاء)
CREATE TABLE IF NOT EXISTS financial_claims (
  id INT AUTO_INCREMENT PRIMARY KEY,
  debtor_name VARCHAR(150) NOT NULL,
  debtor_phone VARCHAR(30) DEFAULT NULL,
  amount DECIMAL(10,2) NOT NULL,
  paid_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  description TEXT DEFAULT NULL,
  due_date DATE DEFAULT NULL,
  created_by INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
