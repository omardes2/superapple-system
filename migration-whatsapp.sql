-- ملف ترقية لقاعدة بيانات موجودة مسبقًا (نظام سوبر آبل)
-- نفّذه مرة وحدة فقط من phpMyAdmin -> اختر قاعدة بياناتك -> تبويب SQL -> الصق الكود واضغط Go
-- (لا يمسح أي بيانات موجودة، فقط يضيف 3 أعمدة جديدة لجدول الإعدادات)

ALTER TABLE settings ADD COLUMN whatsapp_phone_id VARCHAR(50) DEFAULT NULL;
ALTER TABLE settings ADD COLUMN whatsapp_token TEXT DEFAULT NULL;
ALTER TABLE settings ADD COLUMN whatsapp_template VARCHAR(100) DEFAULT 'hello_world';
