-- فهارس لتحسين أداء صفحة الأوردرات (production_tasks)
-- يجب تنفيذها مرة واحدة على قاعدة البيانات

-- الفهرس الرئيسي: يغطي استعلامات الفلترة والترتيب
ALTER TABLE tasks ADD INDEX idx_tasks_created_by_status_created_at (created_by, status, created_at);

-- فهرس للبحث بالعميل
ALTER TABLE tasks ADD INDEX idx_tasks_customer_name (customer_name(100));
ALTER TABLE tasks ADD INDEX idx_tasks_customer_phone (customer_phone(20));

-- فهرس للبحث بنوع المهمة والربط
ALTER TABLE tasks ADD INDEX idx_tasks_related (related_type, related_id);
ALTER TABLE tasks ADD INDEX idx_tasks_task_type (task_type);

-- فهرس لتاريخ التسليم
ALTER TABLE tasks ADD INDEX idx_tasks_due_date (due_date);
