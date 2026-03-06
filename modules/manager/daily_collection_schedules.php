<?php
/**
 * صفحة جداول التحصيل اليومية المتعددة - واجهة التحكم
 * للمدير والمحاسب: إنشاء/تعديل/حذف الجداول، وتحديد من يظهر له كل جدول، وتخصيص التحصيلات المرتبطة بالعملاء.
 * لا تؤثر على رصيد الخزنة أو محفظة المستخدم - للتتبع فقط.
 * ملاحظة: الأخطاء تُسجّل عبر error_log() فقط (إعداد log في php.ini إن لزم).
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

// منع الكاش عند التبديل بين تبويبات الشريط الجانبي لضمان عدم رجوع أي كاش قديم
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Expires: 0');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/path_helper.php';

// الصلاحيات يتحقق منها manager.php / accountant.php قبل تضمين هذا الملف - لا نكرر requireRole لتجنب إعادة التوجيه
$currentUser = null;
$db = null;
try {
    $currentUser = getCurrentUser();
    $db = db();
} catch (Throwable $e) {
    error_log('Daily collection schedules init: ' . $e->getMessage());
    echo '<div class="container-fluid"><div class="alert alert-danger">تعذر تحميل الصفحة. تأكد من تسجيل الدخول.</div></div>';
    return;
}

/** التحقق من وجود جدول باستخدام rawQuery (تجنب مشكلة prepared مع SHOW TABLES في MariaDB) */
function dailyCollectionTableExists($db, $tableName) {
    $safe = str_replace("'", "''", $tableName);
    $r = @$db->rawQuery("SHOW TABLES LIKE '" . $safe . "'");
    $exists = $r && ($r instanceof mysqli_result) && $r->num_rows > 0;
    if ($r && $r instanceof mysqli_result) $r->free();
    return $exists;
}

/**
 * التأكد من وجود جداول التحصيل اليومية (إنشاء الناقص منها فقط)
 */
function ensureDailyCollectionTables($db) {
    $tables = ['daily_collection_schedules', 'daily_collection_schedule_items', 'daily_collection_schedule_assignments', 'daily_collection_daily_records'];
    foreach ($tables as $t) {
        if (dailyCollectionTableExists($db, $t)) continue;
        $migrationPath = __DIR__ . '/../../database/migrations/daily_collection_schedules.sql';
        if (!file_exists($migrationPath)) continue;
        $sql = file_get_contents($migrationPath);
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($statements as $stmt) {
            if ($stmt === '' || strpos($stmt, '--') === 0) continue;
            if (stripos($stmt, 'CREATE TABLE') === false) continue;
            $tableName = null;
            if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`]?(\w+)[`]?/i', $stmt, $m)) $tableName = $m[1];
            if ($tableName && $tableName !== $t) continue;
            try {
                $db->rawQuery($stmt . ';');
            } catch (Throwable $e) {
                error_log('Daily collection migration (' . $tableName . '): ' . $e->getMessage());
            }
            break;
        }
    }
}

/** التحقق من وجود عمود week_days وتشغيل الهجرة إن لزم */
function ensureDailyCollectionWeekDaysColumn($db) {
    $r = @$db->rawQuery("SHOW COLUMNS FROM daily_collection_schedules LIKE 'week_days'");
    $exists = $r && ($r instanceof mysqli_result) && $r->num_rows > 0;
    if ($r && $r instanceof mysqli_result) $r->free();
    if ($exists) return;
    $migrationPath = __DIR__ . '/../../database/migrations/daily_collection_schedules_add_week_days.sql';
    if (!file_exists($migrationPath)) return;
    $sql = trim(file_get_contents($migrationPath));
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt === '' || strpos($stmt, '--') === 0) continue;
        try {
            $db->rawQuery($stmt . ';');
        } catch (Throwable $e) {
            error_log('Daily collection week_days migration: ' . $e->getMessage());
        }
        break;
    }
}

try {
    ensureDailyCollectionTables($db);
    ensureDailyCollectionWeekDaysColumn($db);

    if (!dailyCollectionTableExists($db, 'local_customers')) {
        echo '<div class="container-fluid"><div class="alert alert-warning">جدول العملاء المحليين غير موجود. يرجى استخدام صفحة العملاء المحليين أولاً.</div></div>';
        return;
    }

    foreach (['daily_collection_schedules', 'daily_collection_schedule_items', 'daily_collection_schedule_assignments', 'daily_collection_daily_records'] as $t) {
        if (!dailyCollectionTableExists($db, $t)) {
            echo '<div class="container-fluid"><div class="alert alert-danger">جدول ' . htmlspecialchars($t) . ' غير موجود. يرجى تشغيل ملف <code>database/migrations/daily_collection_schedules.sql</code> من phpMyAdmin أو سطر الأوامر.</div></div>';
            return;
        }
    }
} catch (Throwable $e) {
    error_log('Daily collection schedules load: ' . $e->getMessage());
    echo '<div class="container-fluid"><div class="alert alert-danger">حدث خطأ أثناء تحميل الصفحة. يرجى تشغيل ملف <code>database/migrations/daily_collection_schedules.sql</code> أو مراجعة سجل الأخطاء.</div></div>';
    return;
}

$error = '';
$success = '';
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
// استخدام مسار السكريبت الفعلي (الذي استقبل الطلب) لضمان التوجيه لنفس الصفحة وليس لوجهة أخرى
$scriptPath = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
if ($scriptPath !== '' && strpos($scriptPath, '?') !== false) {
    $scriptPath = strstr($scriptPath, '?', true) ?: $scriptPath;
}
$baseUrl = ($scriptPath !== '') ? $scriptPath : (getDashboardUrl(strtolower($currentUser['role'] ?? 'manager')));
$pageParam = (strpos($scriptPath, 'accountant.php') !== false) ? 'accountant' : 'manager';

// AJAX: بحث العملاء المحليين للإدخال اليدوي (autocomplete)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax']) && $_GET['ajax'] === 'search_local_customers') {
    $q = trim($_GET['q'] ?? '');
    header('Content-Type: application/json; charset=utf-8');
    if ($q === '') {
        echo json_encode(['success' => true, 'customers' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        $list = $db->query(
            "SELECT id, name, phone FROM local_customers WHERE status = 'active' AND (name LIKE ? OR phone LIKE ?) ORDER BY name ASC LIMIT 25",
            [$like, $like]
        );
        echo json_encode(['success' => true, 'customers' => $list ?: []], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'customers' => []], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

try {
if (isset($_SESSION['daily_collection_success'])) {
    $success = $_SESSION['daily_collection_success'];
    unset($_SESSION['daily_collection_success']);
}

// حذف جدول
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_schedule') {
    $id = (int)($_POST['schedule_id'] ?? 0);
    if ($id > 0) {
        $schedule = $db->queryOne("SELECT id, name FROM daily_collection_schedules WHERE id = ?", [$id]);
        if ($schedule) {
            try {
                $db->execute("DELETE FROM daily_collection_schedules WHERE id = ?", [$id]);
                if (function_exists('logAudit')) {
                    logAudit($currentUser['id'], 'daily_collection_schedule_deleted', 'daily_collection_schedules', $id, null, ['name' => $schedule['name']]);
                }
                $_SESSION['daily_collection_success'] = 'تم حذف الجدول بنجاح.';
            } catch (Throwable $e) {
                error_log('Delete daily collection schedule: ' . $e->getMessage());
                $error = 'حدث خطأ أثناء الحذف.';
            }
        } else {
            $error = 'الجدول غير موجود.';
        }
    }
    $redirect = $baseUrl . '?page=daily_collection_schedules&_nocache=' . (string)(time() * 1000);
    if (!headers_sent()) { header('Location: ' . $redirect); exit; }
}

// إنشاء أو تحديث جدول (أيام الأسبوع + عملاء مدينون)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['create_schedule', 'update_schedule'], true)) {
    $name = trim($_POST['name'] ?? '');
    // دعم المفتاح week_days أو week_days[] وقيمة نصية مفصولة بفاصلة
    $weekDaysRaw = isset($_POST['week_days']) ? $_POST['week_days'] : (isset($_POST['week_days[]']) ? $_POST['week_days[]'] : []);
    if (is_string($weekDaysRaw)) {
        $weekDaysRaw = array_filter(array_map('trim', explode(',', $weekDaysRaw)));
    }
    $weekDaysRaw = (array)$weekDaysRaw;
    $weekDays = array_values(array_unique(array_filter(array_map('intval', $weekDaysRaw), function ($d) { return $d >= 0 && $d <= 6; })));
    sort($weekDays);
    $weekDaysStr = $weekDays === [] ? null : implode(',', $weekDays);

    $customerIds = isset($_POST['customer_ids']) ? array_filter(array_map('intval', (array)$_POST['customer_ids'])) : [];
    $amounts = isset($_POST['amounts']) ? (array)$_POST['amounts'] : [];
    $assignUserIds = isset($_POST['assign_user_ids']) ? array_filter(array_map('intval', (array)$_POST['assign_user_ids'])) : [];
    $scheduleId = (int)($_POST['schedule_id'] ?? 0);

    // عند التحديث: إذا لم تُرسل أيام من النموذج، نُبقي على أيام الجدول الحالية (تفادي تعيين أيام افتراضية بالخطأ)
    if ($_POST['action'] === 'update_schedule' && $scheduleId > 0 && empty($weekDays)) {
        $existingSchedule = $db->queryOne("SELECT id, week_days FROM daily_collection_schedules WHERE id = ?", [$scheduleId]);
        if ($existingSchedule && !empty(trim((string)($existingSchedule['week_days'] ?? $existingSchedule['WEEK_DAYS'] ?? '')))) {
            $existingWd = trim((string)($existingSchedule['week_days'] ?? $existingSchedule['WEEK_DAYS'] ?? ''));
            $weekDays = array_values(array_unique(array_filter(array_map('intval', explode(',', $existingWd)), function ($d) { return $d >= 0 && $d <= 6; })));
            sort($weekDays);
            $weekDaysStr = $weekDays === [] ? null : implode(',', $weekDays);
        }
    }

    if ($name === '') {
        $error = 'يرجى إدخال اسم الجدول.';
    } elseif (empty($weekDays)) {
        $error = 'يرجى اختيار يوم واحد على الأقل من أيام الأسبوع.';
    } elseif (empty($customerIds)) {
        $error = 'يرجى اختيار عميل مدين واحد على الأقل.';
    } elseif (empty($assignUserIds)) {
        $error = 'يرجى اختيار مستخدم واحد على الأقل في «إظهار الجدول للمستخدمين» حتى يظهر الجدول للسائقين أو المندوبين أو عمال الإنتاج.';
    } else {
        try {
            $db->beginTransaction();
            if ($_POST['action'] === 'create_schedule') {
                $db->execute("INSERT INTO daily_collection_schedules (name, week_days, created_by) VALUES (?, ?, ?)", [$name, $weekDaysStr, $currentUser['id']]);
                $scheduleId = (int)$db->getLastInsertId();
                if (function_exists('logAudit')) {
                    logAudit($currentUser['id'], 'daily_collection_schedule_created', 'daily_collection_schedules', $scheduleId, null, ['name' => $name]);
                }
            } else {
                if ($scheduleId <= 0) throw new InvalidArgumentException('معرف الجدول غير صحيح.');
                $existing = $db->queryOne("SELECT id FROM daily_collection_schedules WHERE id = ?", [$scheduleId]);
                if (!$existing) throw new InvalidArgumentException('الجدول غير موجود.');
                $db->execute("UPDATE daily_collection_schedules SET name = ?, week_days = ?, updated_at = NOW() WHERE id = ?", [$name, $weekDaysStr, $scheduleId]);
                $db->execute("DELETE FROM daily_collection_schedule_items WHERE schedule_id = ?", [$scheduleId]);
                $db->execute("DELETE FROM daily_collection_schedule_assignments WHERE schedule_id = ?", [$scheduleId]);
            }

            $sortOrder = 0;
            foreach ($customerIds as $i => $cid) {
                if ($cid <= 0) continue;
                $amount = isset($amounts[$cid]) ? (float)str_replace(',', '', $amounts[$cid]) : (isset($amounts[$i]) ? (float)str_replace(',', '', $amounts[$i]) : 0);
                $db->execute(
                    "INSERT INTO daily_collection_schedule_items (schedule_id, local_customer_id, daily_amount, sort_order) VALUES (?, ?, ?, ?)",
                    [$scheduleId, $cid, $amount, $sortOrder++]
                );
            }
            foreach ($assignUserIds as $uid) {
                $uid = (int)$uid;
                if ($uid <= 0) continue;
                $assignedBy = (int)$currentUser['id'];
                $db->execute(
                    "INSERT INTO daily_collection_schedule_assignments (schedule_id, user_id, assigned_by) VALUES (?, ?, ?)",
                    [$scheduleId, $uid, $assignedBy]
                );
            }
            $db->commit();
            if (method_exists($db, 'clearCache')) {
                try { $db->clearCache(); } catch (Throwable $e) { /* ignore */ }
            }
            $_SESSION['daily_collection_success'] = ($_POST['action'] === 'create_schedule') ? 'تم إنشاء الجدول بنجاح. التحصيل يتكرر في الأيام المحددة كل أسبوع.' : 'تم تحديث الجدول بنجاح.';
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('Daily collection schedule save: ' . $e->getMessage());
            $error = 'حدث خطأ: ' . ($e->getMessage() ?: 'يرجى المحاولة مرة أخرى.');
        }
    }
    if (empty($error)) {
        $redirect = $baseUrl . '?page=daily_collection_schedules&_nocache=' . (string)(time() * 1000);
        if (!headers_sent()) { header('Location: ' . $redirect); exit; }
    }
}

// قائمة الجداول (مع أيام الأسبوع)
$schedules = $db->query(
    "SELECT s.id, s.name, s.week_days, s.created_at, u.full_name AS created_by_name
     FROM daily_collection_schedules s
     LEFT JOIN users u ON u.id = s.created_by
     ORDER BY s.created_at DESC"
) ?: [];

$scheduleIds = array_column($schedules, 'id');
$itemsCount = [];
$assignmentsBySchedule = [];
if (!empty($scheduleIds)) {
    $placeholders = implode(',', array_fill(0, count($scheduleIds), '?'));
    $counts = $db->query("SELECT schedule_id, COUNT(*) AS cnt FROM daily_collection_schedule_items WHERE schedule_id IN ($placeholders) GROUP BY schedule_id", $scheduleIds);
    foreach ($counts ?: [] as $row) {
        $itemsCount[$row['schedule_id']] = (int)$row['cnt'];
    }
    $assigns = $db->query(
        "SELECT a.schedule_id, a.user_id, u.full_name, u.role
         FROM daily_collection_schedule_assignments a
         LEFT JOIN users u ON u.id = a.user_id
         WHERE a.schedule_id IN ($placeholders)",
        $scheduleIds
    );
    foreach ($assigns ?: [] as $row) {
        $assignmentsBySchedule[$row['schedule_id']][] = $row;
    }
}

// العملاء المدينون فقط (رصيد > 0) لاختيارهم في جدول التحصيل
$debtorCustomers = $db->query(
    "SELECT id, name, phone, COALESCE(balance, 0) AS balance FROM local_customers WHERE status = 'active' AND (balance IS NOT NULL AND balance > 0) ORDER BY name ASC"
) ?: [];
$assignableUsers = $db->query(
    "SELECT id, full_name, username, role FROM users WHERE status = 'active' AND role IN ('driver', 'sales', 'production') ORDER BY role, full_name, username"
) ?: [];
$roleLabels = ['driver' => 'سائق', 'sales' => 'مندوب مبيعات', 'production' => 'عامل إنتاج'];

$editSchedule = null;
$editItems = [];
$editAssignments = [];
$editWeekDays = [];
if ($editId > 0) {
    $editSchedule = $db->queryOne("SELECT id, name, week_days FROM daily_collection_schedules WHERE id = ?", [$editId]);
    if ($editSchedule) {
        $editWeekDaysRaw = $editSchedule['week_days'] ?? $editSchedule['WEEK_DAYS'] ?? '';
        if ($editWeekDaysRaw !== '' && $editWeekDaysRaw !== null) {
            $editWeekDays = array_values(array_filter(array_map('intval', explode(',', (string)$editWeekDaysRaw)), function ($d) { return $d >= 0 && $d <= 6; }));
        }
        $editItems = $db->query(
            "SELECT si.id, si.local_customer_id, si.daily_amount, lc.name AS customer_name
             FROM daily_collection_schedule_items si
             LEFT JOIN local_customers lc ON lc.id = si.local_customer_id
             WHERE si.schedule_id = ? ORDER BY si.sort_order, si.id",
            [$editId]
        ) ?: [];
        $editAssignments = $db->query("SELECT user_id FROM daily_collection_schedule_assignments WHERE schedule_id = ?", [$editId]);
        $editAssignments = array_column($editAssignments ?: [], 'user_id');
    } else {
        $editId = 0;
    }
}
} catch (Throwable $e) {
    error_log('Daily collection schedules data: ' . $e->getMessage());
    $error = 'حدث خطأ أثناء تحميل البيانات. يرجى تشغيل ملف database/migrations/daily_collection_schedules.sql أو مراجعة سجل الأخطاء.';
    $schedules = [];
    $itemsCount = [];
    $assignmentsBySchedule = [];
    $debtorCustomers = [];
    $assignableUsers = [];
    $roleLabels = ['driver' => 'سائق', 'sales' => 'مندوب مبيعات', 'production' => 'عامل إنتاج'];
    $editSchedule = null;
    $editItems = [];
    $editAssignments = [];
    $editWeekDays = [];
}
?>
<div class="container-fluid">
    <div class="page-header mb-4">
        <h2><i class="bi bi-calendar2-range me-2"></i>جداول التحصيل اليومية المتعددة</h2>
        <p class="text-muted mb-0">إنشاء وتعديل جداول تحصيل يومية (لا تؤثر على الخزنة أو المحفظة)</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-12 col-lg-6 mb-4">
            <div class="card shadow-sm h-100">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="mb-0"><i class="bi bi-plus-circle me-1"></i><?php echo $editId ? 'تعديل الجدول' : 'جدول تحصيل جديد'; ?></h5>
                    <?php if ($editId): ?>
                        <a href="<?php echo $baseUrl; ?>?page=daily_collection_schedules" class="btn btn-outline-secondary btn-sm">إلغاء</a>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <form method="post" action="<?php echo htmlspecialchars($baseUrl . '?page=daily_collection_schedules'); ?>" id="daily-collection-form" novalidate data-no-loading="true" target="_top">
                        <input type="hidden" name="action" value="<?php echo $editId ? 'update_schedule' : 'create_schedule'; ?>">
                        <?php if ($editId): ?><input type="hidden" name="schedule_id" value="<?php echo $editId; ?>"><?php endif; ?>
                        <div class="mb-3">
                            <label class="form-label">اسم الجدول <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" required maxlength="200"
                                   value="<?php echo $editSchedule ? htmlspecialchars($editSchedule['name']) : ''; ?>"
                                   placeholder="مثال: جدول تحصيل منطقة أ">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">أيام التحصيل في الأسبوع <span class="text-danger">*</span></label>
                            <small class="text-muted d-block mb-1">يُعاد التحصيل في الأيام المحددة كل أسبوع (كل شهر/طوال السنة)</small>
                            <div class="d-flex flex-wrap gap-2 gap-md-3" id="week-days-container">
                                <?php
                                $weekDayLabels = [0 => 'الأحد', 1 => 'الإثنين', 2 => 'الثلاثاء', 3 => 'الأربعاء', 4 => 'الخميس', 5 => 'الجمعة', 6 => 'السبت'];
                                foreach ($weekDayLabels as $wd => $label):
                                    $checked = in_array($wd, $editWeekDays, true);
                                ?>
                                    <label class="form-check form-check-inline border rounded px-3 py-2 mb-0">
                                        <input type="checkbox" name="week_days[]" value="<?php echo $wd; ?>" class="form-check-input" <?php echo $checked ? 'checked' : ''; ?>>
                                        <span class="form-check-label"><?php echo $label; ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">العملاء المدينون ومبلغ التحصيل اليومي <span class="text-danger">*</span></label>
                            <small class="text-muted d-block mb-1">اختر العملاء المدينين (رصيد &gt; 0) لضمهم للجدول في الأيام المحددة أعلاه. يمكنك تحديد مبلغ التحصيل اليومي لكل عميل.</small>
                            <?php
                            $editCustomerIds = array_column($editItems, 'local_customer_id');
                            $editAmountsByCustomer = [];
                            foreach ($editItems as $item) {
                                $editAmountsByCustomer[(int)$item['local_customer_id']] = $item['daily_amount'] ?? 0;
                            }
                            ?>
                            <div class="table-responsive border rounded">
                                <table class="table table-sm table-hover mb-0" id="debtor-customers-table">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="text-center" style="width:2.5rem;"><input type="checkbox" id="select-all-debtors" title="تحديد الكل"></th>
                                            <th>العميل</th>
                                            <th>الهاتف</th>
                                            <th class="text-end">الرصيد</th>
                                            <th class="text-end" style="min-width:100px;">مبلغ التحصيل اليومي</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($debtorCustomers)): ?>
                                            <tr><td colspan="5" class="text-center text-muted py-3">لا يوجد عملاء مدينون (رصيد موجب) حالياً. أضف رصيداً للعملاء من صفحة العملاء المحليين.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($debtorCustomers as $c):
                                                $cid = (int)$c['id'];
                                                $selected = in_array($cid, $editCustomerIds, true);
                                                $amount = $editAmountsByCustomer[$cid] ?? '';
                                                $balance = isset($c['balance']) ? (float)$c['balance'] : 0;
                                            ?>
                                                <tr>
                                                    <td class="text-center align-middle">
                                                        <input type="checkbox" name="customer_ids[]" value="<?php echo $cid; ?>" class="form-check-input debtor-check" <?php echo $selected ? 'checked' : ''; ?>>
                                                    </td>
                                                    <td class="align-middle"><?php echo htmlspecialchars($c['name'] ?? ''); ?></td>
                                                    <td class="align-middle"><?php echo htmlspecialchars($c['phone'] ?? '—'); ?></td>
                                                    <td class="text-end align-middle"><?php echo function_exists('formatCurrency') ? formatCurrency($balance) : number_format($balance, 2); ?></td>
                                                    <td class="text-end align-middle">
                                                        <input type="text" name="amounts[<?php echo $cid; ?>]" class="form-control form-control-sm d-inline-block text-end" style="max-width:110px;" placeholder="0" value="<?php echo $amount !== '' ? htmlspecialchars($amount) : ''; ?>">
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">إظهار الجدول للمستخدمين <span class="text-danger">*</span></label>
                            <small class="text-muted d-block mb-1">المستخدمون المحددون يرون هذا الجدول في صفحة «جداول التحصيل» في أيام التحصيل المحددة أعلاه. اختر مستخدمين واحداً أو أكثر.</small>
                            <div class="border rounded p-2 bg-light" style="max-height:200px;overflow-y:auto;">
                                <?php if (empty($assignableUsers)): ?>
                                    <p class="text-muted small mb-0">لا يوجد مستخدمون (سائق / مندوب مبيعات / عامل إنتاج) نشطون. أضف مستخدمين من إدارة المستخدمين.</p>
                                <?php else: ?>
                                    <?php foreach ($assignableUsers as $u): ?>
                                        <label class="d-block mb-1 mb-md-0 py-1 py-md-0">
                                            <input type="checkbox" name="assign_user_ids[]" value="<?php echo (int)$u['id']; ?>" class="form-check-input me-2" <?php echo in_array($u['id'], $editAssignments) ? 'checked' : ''; ?>>
                                            <?php echo htmlspecialchars($u['full_name'] ?: $u['username']); ?> <span class="text-muted">(<?php echo $roleLabels[$u['role']] ?? $u['role']; ?>)</span>
                                        </label>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary"><?php echo $editId ? 'حفظ التعديلات' : 'إنشاء الجدول'; ?></button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="card shadow-sm">
                <div class="card-header"><h5 class="mb-0"><i class="bi bi-list-ul me-1"></i>الجداول الحالية</h5></div>
                <div class="card-body p-0">
                    <?php if (empty($schedules)): ?>
                        <p class="text-muted p-3 mb-0">لا توجد جداول. أنشئ جدولاً جديداً من النموذج.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover table-striped mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>الاسم</th>
                                        <th>أيام التكرار</th>
                                        <th>عدد العملاء</th>
                                        <th>المُعيّنون</th>
                                        <th class="text-end">إجراءات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $weekDayNames = [0 => 'الأحد', 1 => 'الإثنين', 2 => 'الثلاثاء', 3 => 'الأربعاء', 4 => 'الخميس', 5 => 'الجمعة', 6 => 'السبت'];
                                    foreach ($schedules as $s):
                                        $wdList = [];
                                        if (!empty($s['week_days'])) {
                                            foreach (array_map('intval', explode(',', $s['week_days'])) as $wd) {
                                                if (isset($weekDayNames[$wd])) $wdList[] = $weekDayNames[$wd];
                                            }
                                        }
                                        $weekDaysDisplay = $wdList === [] ? '<span class="text-muted">—</span>' : implode('، ', $wdList);
                                    ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($s['name']); ?></td>
                                            <td><?php echo $weekDaysDisplay; ?></td>
                                            <td><?php echo $itemsCount[$s['id']] ?? 0; ?></td>
                                            <td>
                                                <?php
                                                $assigned = $assignmentsBySchedule[$s['id']] ?? [];
                                                if (empty($assigned)) {
                                                    echo '<span class="text-muted">—</span>';
                                                } else {
                                                    echo implode('، ', array_map(function ($a) use ($roleLabels) {
                                                        return htmlspecialchars($a['full_name'] ?: $a['user_id']) . ' (' . ($roleLabels[$a['role']] ?? $a['role']) . ')';
                                                    }, $assigned));
                                                }
                                                ?>
                                            </td>
                                            <td class="text-end">
                                                <a href="<?php echo $baseUrl; ?>?page=daily_collection_schedules&edit=<?php echo $s['id']; ?>" class="btn btn-sm btn-outline-primary me-1" title="تعديل"><i class="bi bi-pencil"></i></a>
                                                <form method="post" class="d-inline" onsubmit="return confirm('حذف هذا الجدول وجميع بنوده وسجلاته؟');">
                                                    <input type="hidden" name="action" value="delete_schedule">
                                                    <input type="hidden" name="schedule_id" value="<?php echo $s['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="حذف"><i class="bi bi-trash"></i></button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
(function() {
    try {
        var u = new URL(window.location.href);
        if (u.searchParams.has('_nocache')) {
            u.searchParams.delete('_nocache');
            var clean = u.pathname + (u.search || '') + (u.hash || '');
            if (window.history && window.history.replaceState) window.history.replaceState({}, '', clean);
        }
    } catch (e) {}

    var selectAllDebtors = document.getElementById('select-all-debtors');
    var debtorChecks = document.querySelectorAll('.debtor-check');
    if (selectAllDebtors && debtorChecks.length) {
        selectAllDebtors.addEventListener('change', function() {
            debtorChecks.forEach(function(cb) { cb.checked = selectAllDebtors.checked; });
        });
    }

    var form = document.getElementById('daily-collection-form');
    if (form) {
        form.addEventListener('submit', function(e) {
            var weekDays = form.querySelectorAll('input[name="week_days[]"]:checked');
            var customers = form.querySelectorAll('input[name="customer_ids[]"]:checked');
            var assignUsers = form.querySelectorAll('input[name="assign_user_ids[]"]:checked');
            if (weekDays.length === 0) {
                e.preventDefault();
                alert('يرجى اختيار يوم واحد على الأقل من أيام الأسبوع.');
                return;
            }
            if (customers.length === 0) {
                e.preventDefault();
                alert('يرجى اختيار عميل مدين واحد على الأقل.');
                return;
            }
            if (assignUsers.length === 0) {
                e.preventDefault();
                alert('يرجى اختيار مستخدم واحد على الأقل في «إظهار الجدول للمستخدمين» حتى يظهر الجدول للمُعينين.');
            }
        });
    }

    (function hideLoadingOnPageLoad() {
        if (typeof window.resetPageLoading === 'function') window.resetPageLoading();
        var go = document.getElementById('global-loading-overlay');
        if (go) go.classList.remove('is-active');
        var aj = document.getElementById('ajax-loading-indicator');
        if (aj) aj.style.display = 'none';
    })();
})();
</script>
