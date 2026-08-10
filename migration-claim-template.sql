-- ============================================================
-- Migration: قالب رسالة تذكير المطالبات المالية (قابل للتعديل)
-- آمن: ADD COLUMN فقط، صفر DROP
-- ============================================================

ALTER TABLE settings
  ADD COLUMN claim_reminder_template TEXT DEFAULT NULL;
