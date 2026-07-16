-- ترقية: صلاحية جزئية للموظفين (إرسال تذكيرات المطالبات المالية فقط)
ALTER TABLE users ADD COLUMN can_send_claims TINYINT(1) NOT NULL DEFAULT 0;
