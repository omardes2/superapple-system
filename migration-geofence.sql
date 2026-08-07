-- ============================================================
-- Migration: تقييد تسجيل الدوام بموقع الشركة (Geofencing)
-- آمن: كله ADD COLUMN بقيم افتراضية، صفر DROP، وصفر تأثير على البيانات الحالية
-- ملاحظة: geofence_enabled = 0 افتراضيًا، فالنظام يشتغل زي ما هو
--         بالضبط لحد ما تفعّله بنفسك من لوحة الإعدادات.
-- ============================================================

ALTER TABLE settings
  ADD COLUMN geofence_enabled TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN office_latitude DECIMAL(10,7) DEFAULT NULL,
  ADD COLUMN office_longitude DECIMAL(10,7) DEFAULT NULL,
  ADD COLUMN geofence_radius INT NOT NULL DEFAULT 200;
