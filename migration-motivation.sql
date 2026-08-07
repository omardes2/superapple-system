-- ============================================================
-- Migration: الرسائل التحفيزية اليومية للموظفين
-- آمن بالكامل: CREATE TABLE IF NOT EXISTS + ADD COLUMN، صفر DROP
-- ============================================================

CREATE TABLE IF NOT EXISTS motivation_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  message TEXT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- سجل الإرسال: يمنع تكرار نفس الرسالة لنفس الموظف بنفس اليوم،
-- وكمان يخلينا نعرف أي رسائل استلمها كل موظف سابقًا لتفادي التكرار السريع
CREATE TABLE IF NOT EXISTS motivation_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  message_id INT DEFAULT NULL,
  sent_date DATE NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (message_id) REFERENCES motivation_messages(id) ON DELETE SET NULL,
  UNIQUE KEY user_day (user_id, sent_date),
  INDEX idx_user_msg (user_id, message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- إعداد التفعيل والتوقيت (كم دقيقة بعد تسجيل الحضور تُرسل الرسالة)
ALTER TABLE settings
  ADD COLUMN motivation_enabled TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN motivation_delay_minutes INT NOT NULL DEFAULT 60;
