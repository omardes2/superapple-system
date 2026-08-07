-- إضافة: عدد الموظفين اللي يستلموا رسالة تحفيزية يوميًا (افتراضيًا 2)
ALTER TABLE settings ADD COLUMN motivation_daily_count INT NOT NULL DEFAULT 2;
