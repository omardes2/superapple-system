-- ترقية: إضافة جدول الأقسام (قائمة مرجعية تُستخدم للاقتراح التلقائي عند إضافة موظف)
-- لا تغيّر عمود department الحالي بجدول users (يبقى يعمل بدون أي تعديل)

CREATE TABLE IF NOT EXISTS departments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ينقل الأقسام الحالية (اللي موجودة كنص بأسماء الموظفين) لقائمة الأقسام الجديدة تلقائيًا
INSERT IGNORE INTO departments (name) SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department != '';
