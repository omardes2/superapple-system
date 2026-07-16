-- ترقية: صندوق رسائل واتساب الموحّد (صادر ووارد)
CREATE TABLE IF NOT EXISTS whatsapp_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  phone VARCHAR(30) NOT NULL,
  direction ENUM('in','out') NOT NULL,
  message TEXT NOT NULL,
  wa_message_id VARCHAR(120) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE settings ADD COLUMN whatsapp_verify_token VARCHAR(100) DEFAULT NULL;
ALTER TABLE settings ADD COLUMN whatsapp_app_secret VARCHAR(150) DEFAULT NULL;
