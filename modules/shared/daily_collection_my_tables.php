<?php
/**
 * جداول التحصيل اليومية - واجهة المستخدم (سائق، مندوب مبيعات، عامل إنتاج)
 * تعرض الجداول المخصصة للمستخدم مع تمييز "تم التحصيل" و "قيد التحصيل" وأزرار إجراءات.
 * لا تؤثر على رصيد الخزنة أو محفظة المستخدم - للتتبع فقط.
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
require_once __DIR__ . '/../../includes/path_helper.php';

requireRole(['driver', 'sales', 'production', 'manager', 'accountant', 'developer']);

$currentUser = getCurrentUser();
if (!$currentUser || empty($currentUser['id'])) {
    echo '<div class="container-fluid"><div class="alert alert-warning">يجب تسجيل الدخول لعرض جداول التحصيل.</div></div>';
    return;
}
$userId = (int)$currentUser['id'];
$db = db();

// التأكد من وجود الجداول
$tableCheck = $db->queryOne("SHOW TABLES LIKE 'daily_collection_schedules'");
if (empty($tableCheck)) {
    echo '<div class="container-fluid"><div class="alert alert-warning">جداول التحصيل غير مفعّلة بعد. يرجى تشغيل migration أو طلب تفعيلها من المدير.</div></div>';
    return;
}
// التأكد من وجود عمود week_days (أيام الأسبوع) إن وُجدت الجداول
$colCheck = @$db->rawQuery("SHOW COLUMNS FROM daily_collection_schedules LIKE 'week_days'");
$hasWeekDays = $colCheck && ($colCheck instanceof mysqli_result) && $colCheck->num_rows > 0;
if ($colCheck && $colCheck instanceof mysqli_result) $colCheck->free();
if (!$hasWeekDays) {
    try {
        $db->rawQuery("ALTER TABLE daily_collection_schedules ADD COLUMN week_days VARCHAR(20) DEFAULT NULL COMMENT 'أيام الأسبوع 0-6 مفصولة بفاصلة' AFTER name");
    } catch (Throwable $e) {
        error_log('Daily collection week_days column: ' . $e->getMessage());
    }
}

$today = date('Y-m-d');
$todayWeekday = (int)date('w'); // 0=الأحد .. 6=السبت
$isControlRole = in_array(strtolower(getCurrentUser()['role'] ?? ''), ['manager', 'accountant', 'developer'], true);
// للمستخدم المعيّن: اليوم الافتراضي = اليوم الحالي؛ إعادة توجيه لربط اليوم في الرابط إن لزم
if (!$isControlRole && !isset($_GET['day']) && !isset($_GET['date'])) {
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    if ($script !== '' && !headers_sent()) {
        header('Location: ' . $script . '?page=daily_collection_my_tables&day=' . $todayWeekday);
        exit;
    }
}
// اختيار اليوم إما من day=0..6 أو من date؛ الافتراضي = اليوم الحالي
$selectedDay = isset($_GET['day']) ? max(0, min(6, (int)$_GET['day'])) : $todayWeekday;
if (isset($_GET['date']) && $_GET['date'] !== '') {
    $viewDate = date('Y-m-d', strtotime($_GET['date']));
} else {
    // حساب تاريخ يوم الأسبوع المحدد ضمن أسبوع اليوم الحالي
    $diff = $selectedDay - $todayWeekday;
    if ($diff < 0) $diff += 7;
    $viewDate = date('Y-m-d', strtotime("+{$diff} days"));
}
$success = '';
$error = '';

// معالجة تسجيل التحصيل أو إلغائه (المدير/المحاسب/المطور يمكنهم تعديل أي بند؛ غيرهم فقط المعينون)
$isControlRole = in_array(strtolower(getCurrentUser()['role'] ?? ''), ['manager', 'accountant', 'developer'], true);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['item_id'])) {
    $itemId = (int)$_POST['item_id'];
    $recordDate = isset($_POST['record_date']) ? date('Y-m-d', strtotime($_POST['record_date'])) : $today;
    $action = $_POST['action'];

    if ($isControlRole) {
        $item = $db->queryOne(
            "SELECT si.id, si.schedule_id, si.local_customer_id FROM daily_collection_schedule_items si WHERE si.id = ?",
            [$itemId]
        );
    } else {
        $item = $db->queryOne(
            "SELECT si.id, si.schedule_id, si.local_customer_id
             FROM daily_collection_schedule_items si
             INNER JOIN daily_collection_schedule_assignments a ON a.schedule_id = si.schedule_id AND a.user_id = ?
             WHERE si.id = ?",
            [$userId, $itemId]
        );
    }
    if (!$item) {
        $error = $isControlRole ? 'البند غير موجود.' : 'البند غير موجود أو غير مخصص لك.';
    } else {
        if ($action === 'mark_collected') {
            $existing = $db->queryOne("SELECT id, status FROM daily_collection_daily_records WHERE schedule_item_id = ? AND record_date = ?", [$itemId, $recordDate]);
            if ($existing) {
                $db->execute("UPDATE daily_collection_daily_records SET status = 'collected', collected_at = NOW(), collected_by = ? WHERE id = ?", [$userId, $existing['id']]);
            } else {
                $db->execute(
                    "INSERT INTO daily_collection_daily_records (schedule_item_id, record_date, status, collected_at, collected_by) VALUES (?, ?, 'collected', NOW(), ?)",
                    [$itemId, $recordDate, $userId]
                );
            }
            $success = 'تم تسجيل التحصيل لهذا اليوم.';
        } elseif ($action === 'mark_pending') {
            $db->execute("UPDATE daily_collection_daily_records SET status = 'pending', collected_at = NULL, collected_by = NULL WHERE schedule_item_id = ? AND record_date = ?", [$itemId, $recordDate]);
            $success = 'تم إرجاع الحالة إلى قيد التحصيل.';
        }
    }
    if (!headers_sent() && (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => empty($error), 'message' => $error ?: $success]);
        exit;
    }
}

// فلتر الحالة وفلتر اسم الجدول
$statusFilter = isset($_GET['status']) && in_array($_GET['status'], ['collected', 'pending'], true) ? $_GET['status'] : 'all';
$scheduleFilter = isset($_GET['schedule_id']) && $_GET['schedule_id'] !== '' ? (int)$_GET['schedule_id'] : null;

// الجداول المخصصة للمستخدم الحالي (أو كل الجداول للمدير/المحاسب) — مع فلترة حسب يوم الأسبوع للتاريخ المعروض
$viewDayOfWeek = (int)date('w', strtotime($viewDate)); // 0=الأحد .. 6=السبت
$schedulesBeforeDateFilter = [];
if ($isControlRole) {
    $schedules = $db->query(
        "SELECT s.id, s.name, s.week_days FROM daily_collection_schedules s ORDER BY s.name ASC"
    ) ?: [];
} else {
    // جلب التخصيصات أولاً ثم الجداول لضمان ظهور الجدول للمُعينين
    $assignedScheduleIds = $db->query(
        "SELECT a.schedule_id FROM daily_collection_schedule_assignments a WHERE a.user_id = ?",
        [$userId]
    ) ?: [];
    $assignedScheduleIds = array_values(array_unique(array_column($assignedScheduleIds, 'schedule_id')));
    $schedules = [];
    if (!empty($assignedScheduleIds)) {
        $placeholders = implode(',', array_fill(0, count($assignedScheduleIds), '?'));
        $schedules = $db->query(
            "SELECT s.id, s.name, s.week_days FROM daily_collection_schedules s WHERE s.id IN ($placeholders) ORDER BY s.name ASC",
            $assignedScheduleIds
        ) ?: [];
    }
    $schedulesBeforeDateFilter = $schedules;
}
// عرض الجدول فقط في التواريخ التي تطابق أيامه (إن وُجد week_days). إذا week_days فارغ = عرض كل يوم للتوافق مع الجداول القديمة
$schedules = array_filter($schedules, function ($s) use ($viewDayOfWeek) {
    $wd = $s['week_days'] ?? '';
    if ($wd === '' || $wd === null) return true;
    $days = array_map('intval', explode(',', $wd));
    return in_array($viewDayOfWeek, $days, true);
});
$schedules = array_values($schedules);
$hasAssignedSchedulesButNoneForThisDate = !$isControlRole && count($schedulesBeforeDateFilter) > 0 && count($schedules) === 0;
$viewDayName = [0 => 'الأحد', 1 => 'الإثنين', 2 => 'الثلاثاء', 3 => 'الأربعاء', 4 => 'الخميس', 5 => 'الجمعة', 6 => 'السبت'][$viewDayOfWeek] ?? '';

// بناء قائمة مسطحة من كل البنود مع اسم الجدول والحالة (فقط للجداول المطبقة على تاريخ العرض)
$allItems = [];
foreach ($schedules as $s) {
    $sql = "SELECT si.id AS item_id, si.schedule_id, si.daily_amount, lc.id AS customer_id, lc.name AS customer_name
            FROM daily_collection_schedule_items si
            LEFT JOIN local_customers lc ON lc.id = si.local_customer_id
            WHERE si.schedule_id = ? ORDER BY si.sort_order, si.id";
    $items = $db->query($sql, [$s['id']]) ?: [];
    $itemIds = array_column($items, 'item_id');
    $records = [];
    if (!empty($itemIds)) {
        $ph = implode(',', array_fill(0, count($itemIds), '?'));
        $paramsR = array_merge($itemIds, [$viewDate]);
        $rows = $db->query("SELECT schedule_item_id, status, collected_at FROM daily_collection_daily_records WHERE schedule_item_id IN ($ph) AND record_date = ?", $paramsR);
        foreach ($rows ?: [] as $r) {
            $records[$r['schedule_item_id']] = $r;
        }
    }
    foreach ($items as $it) {
        $rec = $records[$it['item_id']] ?? null;
        $status = ($rec['status'] ?? 'pending') === 'collected' ? 'collected' : 'pending';
        if ($statusFilter !== 'all' && $status !== $statusFilter) continue;
        if ($scheduleFilter !== null && $s['id'] != $scheduleFilter) continue;
        $allItems[] = [
            'schedule_id' => $s['id'],
            'schedule_name' => $s['name'],
            'item_id' => $it['item_id'],
            'customer_name' => $it['customer_name'] ?? '—',
            'daily_amount' => $it['daily_amount'],
            'status' => $status,
            'record' => $rec
        ];
    }
}

// الترقيم (pagination)
$perPage = 15;
$totalItems = count($allItems);
$page = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$totalPages = $totalItems > 0 ? (int)ceil($totalItems / $perPage) : 1;
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$itemsPage = array_slice($allItems, $offset, $perPage);

$queryBase = ['page' => 'daily_collection_my_tables', 'day' => $selectedDay];
$queryBaseWithDate = ['page' => 'daily_collection_my_tables', 'date' => $viewDate];
if ($statusFilter !== 'all') {
    $queryBase['status'] = $queryBaseWithDate['status'] = $statusFilter;
}
if ($scheduleFilter !== null) {
    $queryBase['schedule_id'] = $queryBaseWithDate['schedule_id'] = $scheduleFilter;
}
$weekDayNames = [0 => 'الأحد', 1 => 'الإثنين', 2 => 'الثلاثاء', 3 => 'الأربعاء', 4 => 'الخميس', 5 => 'الجمعة', 6 => 'السبت'];
$paginationBase = ($isControlRole && isset($_GET['date']) && $_GET['date'] !== '') ? $queryBaseWithDate : $queryBase;

$baseUrl = getDashboardUrl();
$dashboardScript = 'driver.php';
if (strpos($_SERVER['PHP_SELF'] ?? '', 'accountant.php') !== false) $dashboardScript = 'accountant.php';
elseif (strpos($_SERVER['PHP_SELF'] ?? '', 'manager.php') !== false) $dashboardScript = 'manager.php';
elseif (strpos($_SERVER['PHP_SELF'] ?? '', 'production.php') !== false) $dashboardScript = 'production.php';
elseif (strpos($_SERVER['PHP_SELF'] ?? '', 'sales.php') !== false) $dashboardScript = 'sales.php';
$pageName = 'daily_collection_my_tables';
?>
<div class="container-fluid">
    <div class="page-header mb-4">
        <h2><i class="bi bi-calendar2-range me-2"></i>جداول التحصيل اليومية</h2>
        <p class="text-muted mb-0">عرض وتحديث حالة التحصيل اليومي (لا يؤثر على الخزنة أو المحفظة)</p>
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

    <?php if ($isControlRole): ?>
        <form method="get" action="" id="daily-collection-filters" class="mb-4">
            <input type="hidden" name="page" value="daily_collection_my_tables">
            <div class="row g-2 align-items-end flex-wrap">
                <div class="col-auto">
                    <label class="form-label small mb-0">التاريخ</label>
                    <input type="date" name="date" class="form-control form-control-sm" style="max-width:160px" value="<?php echo htmlspecialchars($viewDate); ?>">
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-0">الحالة</label>
                    <select name="status" class="form-select form-select-sm" style="max-width:160px">
                        <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>الكل</option>
                        <option value="collected" <?php echo $statusFilter === 'collected' ? 'selected' : ''; ?>>تم التحصيل</option>
                        <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>قيد التحصيل</option>
                    </select>
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-0">اسم الجدول</label>
                    <select name="schedule_id" class="form-select form-select-sm" style="max-width:200px">
                        <option value="">الكل</option>
                        <?php foreach ($schedules as $sch): ?>
                            <option value="<?php echo (int)$sch['id']; ?>" <?php echo $scheduleFilter === (int)$sch['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($sch['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i>عرض</button>
                </div>
            </div>
        </form>
    <?php else: ?>
        <!-- واجهة أيام الأسبوع للمستخدم المعيّن: الافتراضي = اليوم الحالي -->
        <div class="mb-4">
            <p class="text-muted small mb-2">اختر يوم الأسبوع لعرض تحصيلاته. اليوم الحالي: <strong><?php echo $weekDayNames[$todayWeekday]; ?></strong></p>
            <div class="d-flex flex-wrap gap-2 g-2" role="tablist" id="week-days-tabs">
                <?php for ($d = 0; $d <= 6; $d++):
                    $isActive = ($d === $selectedDay);
                    $q = ['page' => 'daily_collection_my_tables', 'day' => $d];
                    $href = '?' . http_build_query($q);
                ?>
                    <a href="<?php echo htmlspecialchars($href); ?>" class="btn <?php echo $isActive ? 'btn-primary' : 'btn-outline-secondary'; ?> week-day-btn position-relative" data-day="<?php echo $d; ?>">
                        <?php echo $weekDayNames[$d]; ?>
                        <?php if ($d === $todayWeekday): ?><span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-info" style="font-size:0.6rem;">اليوم</span><?php endif; ?>
                    </a>
                <?php endfor; ?>
            </div>
            <p class="text-muted small mt-2 mb-0">عرض تحصيلات <strong><?php echo $weekDayNames[$selectedDay]; ?></strong> — <?php echo $viewDate; ?></p>
        </div>
    <?php endif; ?>

    <?php if ($hasAssignedSchedulesButNoneForThisDate): ?>
        <div class="alert alert-warning sticky-top shadow-sm mb-0" style="z-index: 1030;">
            <i class="bi bi-calendar-event me-2"></i>
            <strong>لا توجد جداول للتحصيل في هذا اليوم.</strong><br>
            اليوم المحدد هو <strong><?php echo $weekDayNames[$selectedDay]; ?></strong> (<?php echo $viewDate; ?>). الجداول المخصصة لك تظهر فقط في أيام التحصيل المحددة لكل جدول.<br>
            <span class="d-block mt-2">اختر يوماً آخر من أزرار الأيام أعلاه.</span>
        </div>
    <?php elseif (empty($schedules)): ?>
        <div class="alert alert-info">لا توجد جداول مخصصة لك. تواصل مع المدير أو المحاسب لربطك بجدول تحصيل.</div>
    <?php elseif ($totalItems === 0): ?>
        <?php if ($isControlRole): ?>
            <div class="alert alert-info">لا توجد بنود تطابق الفلتر المحدد.</div>
        <?php else: ?>
            <div class="alert alert-info py-4 text-center">
                <i class="bi bi-inbox display-6 text-muted d-block mb-2"></i>
                <strong>لا يوجد تحصيلات لهذا اليوم</strong><br>
                <span class="text-muted">لا توجد بنود تحصيل لـ <?php echo $weekDayNames[$selectedDay]; ?> (<?php echo $viewDate; ?>). غيّر اليوم من الأزرار أعلاه.</span>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="card shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="mb-0"><i class="bi bi-table me-1"></i><?php echo $isControlRole ? 'بنود التحصيل' : 'تحصيلات ' . $weekDayNames[$selectedDay]; ?></h5>
                <span class="text-muted small"><?php echo $totalItems; ?> بند</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>الجدول</th>
                                <th>العميل</th>
                                <th>مبلغ التحصيل اليومي</th>
                                <th>الحالة</th>
                                <?php if (!$isControlRole): ?>
                                    <th class="text-end">إجراءات</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($itemsPage as $it): ?>
                                <tr class="<?php echo $it['status'] === 'collected' ? 'table-success' : ''; ?>">
                                    <td><?php echo htmlspecialchars($it['schedule_name']); ?></td>
                                    <td><?php echo htmlspecialchars($it['customer_name']); ?></td>
                                    <td><?php echo function_exists('formatCurrency') ? formatCurrency($it['daily_amount']) : number_format($it['daily_amount'], 2); ?></td>
                                    <td>
                                        <?php if ($it['status'] === 'collected'): ?>
                                            <span class="badge bg-success">تم التحصيل</span>
                                            <?php if (!empty($it['record']['collected_at'])): ?>
                                                <small class="text-muted d-block"><?php echo date('H:i', strtotime($it['record']['collected_at'])); ?></small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">قيد التحصيل</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($it['status'] !== 'collected'): ?>
                                        <form method="post" class="d-inline form-daily-collection-action" data-item="<?php echo $it['item_id']; ?>" data-date="<?php echo $viewDate; ?>">
                                            <input type="hidden" name="record_date" value="<?php echo $viewDate; ?>">
                                            <input type="hidden" name="item_id" value="<?php echo $it['item_id']; ?>">
                                            <input type="hidden" name="action" value="mark_collected">
                                            <button type="submit" class="btn btn-sm btn-success">تم التحصيل</button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php if ($totalPages > 1): ?>
            <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="small text-muted">صفحة <?php echo $page; ?> من <?php echo $totalPages; ?></span>
                <nav aria-label="ترقيم البنود">
                    <ul class="pagination pagination-sm mb-0">
                        <?php
                        $q = $paginationBase;
                        if ($page > 1):
                            $q['p'] = $page - 1;
                        ?>
                        <li class="page-item"><a class="page-link" href="?<?php echo http_build_query($q); ?>"><i class="bi bi-chevron-right"></i></a></li>
                        <?php endif; ?>
                        <?php
                        $start = max(1, $page - 2);
                        $end = min($totalPages, $page + 2);
                        for ($i = $start; $i <= $end; $i++):
                            $q['p'] = $i;
                        ?>
                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>"><a class="page-link" href="?<?php echo http_build_query($q); ?>"><?php echo $i; ?></a></li>
                        <?php endfor; ?>
                        <?php if ($page < $totalPages): $q['p'] = $page + 1; ?>
                        <li class="page-item"><a class="page-link" href="?<?php echo http_build_query($q); ?>"><i class="bi bi-chevron-left"></i></a></li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<script>
(function() {
    var picker = document.getElementById('view-date-picker');
    if (picker) {
        picker.addEventListener('change', function() {
            var url = new URL(window.location.href);
            url.searchParams.set('date', this.value);
            window.location.href = url.toString();
        });
    }
    document.querySelectorAll('.form-daily-collection-action').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            if (window.location.protocol !== 'file:' && typeof fetch === 'function') {
                e.preventDefault();
                var fd = new FormData(form);
                fetch(window.location.href, {
                    method: 'POST',
                    body: fd,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                }).then(function(r) { return r.json(); }).then(function(data) {
                    if (data.success) window.location.reload();
                    else if (data.message) alert(data.message);
                }).catch(function() { form.submit(); });
            }
        });
    });
})();
</script>
