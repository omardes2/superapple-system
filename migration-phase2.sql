-- ============================================================
-- Migration: Phase 2 — Notifications enrichment + @Mentions
-- (Batch 1's Workload احتاج صفر تعديلات قاعدة بيانات، فبدأنا هون)
-- آمن بالكامل: كله ADD COLUMN / CREATE TABLE IF NOT EXISTS، صفر DROP
-- ============================================================

-- توسيع جدول الإشعارات: عنوان مختصر، ربط بالعنصر المرتبط، وحالة قراءة حقيقية
ALTER TABLE notifications
  ADD COLUMN title VARCHAR(150) DEFAULT NULL AFTER message,
  ADD COLUMN entity_type VARCHAR(30) DEFAULT NULL AFTER type,
  ADD COLUMN entity_id INT DEFAULT NULL AFTER entity_type,
  ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0 AFTER entity_id,
  ADD COLUMN read_at DATETIME DEFAULT NULL AFTER is_read,
  ADD INDEX idx_notif_user_read (user_id, is_read);

-- منشن بالتعليقات: user_ids صريحة (مو بحث بالنص)، مع منع تكرار نفس المنشن بنفس التعليق
CREATE TABLE IF NOT EXISTS comment_mentions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  comment_id INT NOT NULL,
  user_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (comment_id) REFERENCES task_comments(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY comment_user (comment_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
