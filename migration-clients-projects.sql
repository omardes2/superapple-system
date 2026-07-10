-- ترقية قاعدة البيانات: إضافة العملاء والمشاريع
-- نفّذه من phpMyAdmin -> قاعدة بياناتك -> تبويب SQL -> الصق واضغط Go

CREATE TABLE IF NOT EXISTS clients (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  contact_name VARCHAR(150) DEFAULT NULL,
  phone VARCHAR(30) DEFAULT NULL,
  email VARCHAR(150) DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS projects (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT DEFAULT NULL,
  name VARCHAR(150) NOT NULL,
  description TEXT DEFAULT NULL,
  status ENUM('active','completed','on_hold') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE tasks ADD COLUMN project_id INT DEFAULT NULL;
ALTER TABLE tasks ADD CONSTRAINT fk_task_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL;

-- ترقية إضافية: ربط المهمة مباشرة بشركة/عميل (بدون الحاجة لمشروع رسمي)
ALTER TABLE tasks ADD COLUMN client_id INT DEFAULT NULL;
ALTER TABLE tasks ADD CONSTRAINT fk_task_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL;
