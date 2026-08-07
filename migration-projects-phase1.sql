-- =========================================================
-- ترقية المرحلة الأولى: المشاريع + سير مراجعة المهام + المرفقات
-- =========================================================
-- ⚠️ مهم جدًا قبل التنفيذ:
--   1) خذ نسخة احتياطية كاملة لقاعدة البيانات أولًا (phpMyAdmin → تصدير).
--   2) نفّذ هذا الملف دفعة واحدة من تبويب SQL.
--   3) لو ظهر خطأ "Duplicate column" أو "Duplicate key" على سطر معيّن،
--      معناها إنه اتنفذ قبل جزئيًا — احذف هداك السطر بس وكمّل الباقي.
--   4) هذا الملف مبني على آخر نسخة معروفة من schema.sql بالمستودع؛
--      لو عندك جدول تم تعديله يدويًا سابقًا، راجع الأعمدة الموجودة فعليًا
--      بجدولك قبل التنفيذ (Structure tab) لتفادي أي تعارض.
-- =========================================================

-- ---------- 1) توسيع جدول projects الموجود (بدون حذف أو إعادة إنشاء) ----------
ALTER TABLE projects
  ADD COLUMN manager_id INT DEFAULT NULL AFTER client_id,
  ADD COLUMN start_date DATE DEFAULT NULL,
  ADD COLUMN due_date DATE DEFAULT NULL,
  ADD COLUMN progress_manual TINYINT UNSIGNED DEFAULT NULL COMMENT 'نسبة يدوية تتجاوز الحساب التلقائي إن وُجدت',
  ADD COLUMN default_requires_review TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'هل مهام هذا المشروع تحتاج مراجعة افتراضيًا',
  ADD COLUMN notes TEXT DEFAULT NULL;

ALTER TABLE projects
  MODIFY status ENUM('new','active','on_hold','completed','cancelled') NOT NULL DEFAULT 'new';

ALTER TABLE projects
  ADD CONSTRAINT fk_project_manager FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE projects ADD INDEX idx_project_client (client_id);
ALTER TABLE projects ADD INDEX idx_project_manager (manager_id);

-- ---------- 2) أعضاء فريق المشروع ----------
CREATE TABLE IF NOT EXISTS project_members (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  user_id INT NOT NULL,
  added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY proj_user (project_id, user_id),
  INDEX idx_pm_project (project_id),
  INDEX idx_pm_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- 3) سجل نشاط المشروع ----------
CREATE TABLE IF NOT EXISTS project_activity (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  user_id INT DEFAULT NULL,
  action VARCHAR(50) NOT NULL,
  description TEXT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  INDEX idx_pa_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- 4) المرفقات (Polymorphic: بدون FK مباشر على العنصر المرتبط) ----------
CREATE TABLE IF NOT EXISTS attachments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  entity_type ENUM('task','project','client') NOT NULL,
  entity_id INT NOT NULL,
  file_name VARCHAR(255) NOT NULL COMMENT 'الاسم الأصلي للعرض فقط',
  file_path VARCHAR(255) DEFAULT NULL COMMENT 'اسم داخلي عشوائي على السيرفر (NULL لو رابط خارجي)',
  file_type VARCHAR(100) DEFAULT NULL,
  file_size BIGINT UNSIGNED DEFAULT NULL,
  link_url VARCHAR(500) DEFAULT NULL COMMENT 'لروابط Google Drive الخارجية بدل رفع ملف',
  version_group VARCHAR(50) DEFAULT NULL COMMENT 'يربط نسخ نفس الملف ببعض',
  version_label VARCHAR(30) DEFAULT NULL,
  uploaded_by INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_attach_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- 5) توسيع جدول tasks لسير المراجعة (Backward-Compatible) ----------
-- ملاحظة: القيم الحالية (new/in_progress/done) تبقى صالحة تلقائيًا ضمن القائمة الموسّعة.
ALTER TABLE tasks
  MODIFY status ENUM('new','in_progress','ready_for_review','changes_requested','done') NOT NULL DEFAULT 'new';

ALTER TABLE tasks
  ADD COLUMN requires_review TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'المهام الحالية تبقى 0 تلقائيًا لعدم كسر سير العمل القديم',
  ADD COLUMN review_note TEXT DEFAULT NULL COMMENT 'آخر ملاحظة مراجعة فقط للعرض السريع — المصدر التاريخي task_status_log';

ALTER TABLE tasks ADD INDEX idx_task_project (project_id);

-- ---------- 6) سجل انتقالات حالة المهمة (المصدر التاريخي الرسمي) ----------
CREATE TABLE IF NOT EXISTS task_status_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  task_id INT NOT NULL,
  from_status VARCHAR(30) DEFAULT NULL,
  to_status VARCHAR(30) NOT NULL,
  changed_by INT DEFAULT NULL,
  note TEXT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_tsl_task (task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
