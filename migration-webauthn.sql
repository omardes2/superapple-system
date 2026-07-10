-- ترقية: نظام بصمة الحضور الحقيقية (WebAuthn / Face ID / Touch ID / بصمة الأندرويد)
CREATE TABLE IF NOT EXISTS webauthn_credentials (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  credential_id VARCHAR(255) NOT NULL UNIQUE,
  public_key TEXT NOT NULL,
  sign_count INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
