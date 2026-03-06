-- إضافة عمود أيام الأسبوع لجداول التحصيل (التحصيل يتكرر في الأيام المحددة كل أسبوع)
-- PHP date('w'): 0=الأحد، 1=الإثنين، ...، 6=السبت. القيم مخزنة بصيغة "0,4,6" مثلاً

SET NAMES utf8mb4;

ALTER TABLE `daily_collection_schedules`
  ADD COLUMN `week_days` VARCHAR(20) DEFAULT NULL COMMENT 'أيام الأسبوع للتحصيل 0-6 مفصولة بفاصلة' AFTER `name`;
