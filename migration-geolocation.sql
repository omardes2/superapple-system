-- ترقية: إضافة إحداثيات الموقع الجغرافي عند تسجيل الحضور
ALTER TABLE attendance ADD COLUMN latitude DECIMAL(10,7) DEFAULT NULL;
ALTER TABLE attendance ADD COLUMN longitude DECIMAL(10,7) DEFAULT NULL;
