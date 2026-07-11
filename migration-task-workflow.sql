-- ترقية: نظام سير عمل المهام الكامل (حالات، تصنيف، تعليقات، استلام/إنجاز)

ALTER TABLE tasks ADD COLUMN status ENUM('new','in_progress','done') NOT NULL DEFAULT 'new';
ALTER TABLE tasks ADD COLUMN category VARCHAR(100) DEFAULT NULL;

ALTER TABLE task_assignees ADD COLUMN accepted TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE task_assignees ADD COLUMN accepted_at DATETIME DEFAULT NULL;

CREATE TABLE IF NOT EXISTS task_comments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  task_id INT NOT NULL,
  user_id INT NOT NULL,
  message TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- تحديث المهام القديمة الموجودة لتصبح متسقة مع النظام الجديد
UPDATE tasks t SET status = 'done' WHERE t.id IN (
  SELECT task_id FROM (
    SELECT task_id, MIN(done) AS all_done FROM task_assignees GROUP BY task_id
  ) x WHERE x.all_done = 1
);
UPDATE task_assignees SET accepted = 1, accepted_at = COALESCE(completed_at, NOW()) WHERE done = 1;
