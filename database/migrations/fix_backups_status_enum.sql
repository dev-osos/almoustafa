-- Migration: إضافة 'completed' إلى ENUM status في جدول backups
-- التاريخ: 2026-01-08
-- الوصف: إصلاح مشكلة عرض حالة النسخ الاحتياطية كـ "فشل" رغم نجاحها

-- تحديث تعريف العمود status ليشمل 'completed'
ALTER TABLE `backups` 
MODIFY COLUMN `status` ENUM('success','failed','completed') DEFAULT 'success';

-- تحديث أي سجلات قد تكون بحالة NULL أو غير صحيحة إلى 'success'
-- (في حال وجود سجلات ناجحة بدون حالة محددة)
UPDATE `backups` 
SET `status` = 'success' 
WHERE `status` IS NULL 
  AND `file_size` > 0 
  AND `filename` != '';
