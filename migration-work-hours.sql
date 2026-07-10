-- ترقية: إضافة وقت نهاية الدوام لكل موظف (بالإضافة لوقت البداية الموجود)
-- نفّذه من phpMyAdmin -> قاعدة بياناتك -> تبويب SQL -> الصق واضغط Go

ALTER TABLE users ADD COLUMN work_end TIME DEFAULT '16:00:00';
