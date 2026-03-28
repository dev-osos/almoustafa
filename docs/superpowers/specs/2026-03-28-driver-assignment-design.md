# تصميم ميزة تعيين السائق للأوردرات غير التليجراف

**التاريخ:** 2026-03-28
**الحالة:** معتمد

## الملخص

لجميع أنواع الأوردرات **ماعدا التليجراف**، بعد إكمال المهمة يظهر زر "مع السائق" لعامل الإنتاج/المدير. يختار المستخدم سائقاً من بطاقة صغيرة، ثم يظهر الطلب كبطاقة منبثقة تلقائية للسائق المحدد للموافقة أو الرفض. بعد القبول تتحول المهمة لحالة "مع السائق" ويظهر للسائق خيار "تم التوصيل" فقط.

## قاعدة البيانات

### جدول جديد: `driver_assignments`

```sql
CREATE TABLE driver_assignments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  task_id INT NOT NULL,
  driver_id INT NOT NULL,
  assigned_by INT NOT NULL,
  status ENUM('pending','accepted','rejected') DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  responded_at TIMESTAMP NULL,
  FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE CASCADE
);
```

### تعديل ENUM الحالات في tasks

```sql
ALTER TABLE tasks MODIFY COLUMN status
  ENUM('pending','received','in_progress','completed',
       'with_delegate','with_driver','delivered','returned','cancelled')
  DEFAULT 'pending';
```

## سير العمل

```
مكتمل (completed) — أوردر غير تليجراف
  ↓ عامل إنتاج/مدير يضغط "مع السائق" ويختار سائق
  ↓ → سجل جديد في driver_assignments (status=pending)
  ↓ → المهمة تبقى completed
  ↓
السائق يفتح الصفحة → بطاقة منبثقة تلقائية بالطلبات المعلقة
  ├─ قبول → driver_assignments.status=accepted, responded_at=NOW()
  │         → task.status=with_driver
  │         → المهمة تظهر في جدول السائق مع زر "تم التوصيل" فقط
  └─ رفض → driver_assignments.status=rejected, responded_at=NOW()
           → المهمة تبقى completed
           → يظهر زر "مع السائق" مرة أخرى لعامل الإنتاج

مع السائق (with_driver)
  ↓ السائق يضغط "تم التوصيل"
  ↓ → task.status=delivered
```

### قواعد العمل

- عند وجود `driver_assignment` بحالة `pending` لمهمة معينة، لا يظهر زر "مع السائق" (منع التعيين المزدوج)
- السائق يرى فقط المهام المعيّنة له (`driver_assignments.driver_id = current_user_id`)
- بعد حالة `with_driver`: السائق يملك فقط خيار "تم التوصيل" (لا يوجد "تم الارجاع")
- الزر يظهر فقط للأوردرات غير التليجراف (التليجراف تستخدم "مع المندوب")

## واجهة المستخدم

### 1. زر "مع السائق" (dropdown الإجراءات)

- يظهر لعامل الإنتاج والمدير والسائق
- شرط الظهور: `status === 'completed'` AND `task_type !== 'telegraph'` AND لا يوجد `driver_assignment` معلّق
- أيقونة: `bi-truck` أو `bi-person-badge`

### 2. بطاقة اختيار السائق (popover)

تظهر عند الضغط على "مع السائق":
- قائمة منسدلة بالمستخدمين من نوع `driver`
- زر "تسليم" لإنشاء السجل
- زر "إلغاء" لإغلاق البطاقة

### 3. بطاقة منبثقة للسائق (modal تلقائي)

- تظهر تلقائياً عند فتح صفحة المهام إذا وجدت طلبات `pending`
- تعرض لكل طلب: رقم الأوردر، نوعه، اسم العميل، المنتج والكمية
- زرّا "قبول" و"رفض" لكل طلب

### 4. حالة "مع السائق" في الجدول

- السائق يرى المهام بحالة `with_driver` المعيّنة له فقط (JOIN مع driver_assignments)
- الإجراء الوحيد: "تم التوصيل"
- badge بلون مميز (مثلاً أزرق غامق)

### 5. كارت إحصائيات

- كارت جديد "مع السائق" يضاف لشريط الإحصائيات في عرض السائق

## الملفات المتأثرة

1. **`database/migrations/`** — ملف migration جديد لإنشاء الجدول وتعديل ENUM
2. **`modules/production/tasks.php`** — الملف الرئيسي:
   - Backend: action جديد `with_driver_task`، actions `accept_driver_assignment` و `reject_driver_assignment`
   - Frontend: بطاقة اختيار السائق، بطاقة الموافقة المنبثقة، تعديل عرض الحالات والإجراءات
   - JS: دوال جديدة للبطاقات، تعديل `buildActionsHtml` و `updateTaskRow`
3. **`includes/lang/ar.php`** و **`includes/lang/en.php`** — ترجمات الحالة الجديدة (إن وجدت)
