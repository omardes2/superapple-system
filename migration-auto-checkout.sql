-- ============================================================
-- Migration: الإغلاق التلقائي للانصراف + تذكير الخروج
-- آمن: ADD COLUMN فقط بقيم افتراضية، صفر DROP
-- auto_checkout_enabled = 0 افتراضيًا (النظام يشتغل زي ما هو لحد ما تفعّله)
-- ============================================================

ALTER TABLE attendance
  ADD COLUMN auto_checkout TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE settings
  ADD COLUMN auto_checkout_enabled TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN checkout_reminder_enabled TINYINT(1) NOT NULL DEFAULT 0;
