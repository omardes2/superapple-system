-- ترقية: سجل محاولات إرسال واتساب (لمعرفة سبب أي فشل بالإرسال بسهولة لاحقًا)
CREATE TABLE IF NOT EXISTS whatsapp_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  phone VARCHAR(30) DEFAULT NULL,
  message TEXT DEFAULT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  response TEXT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
