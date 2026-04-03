# Driver Assignment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow production workers to assign completed non-telegraph orders to specific drivers, who must accept before the order appears in their list.

**Architecture:** New `driver_assignments` table tracks pending/accepted/rejected assignments. Task status gains `with_driver`. Driver sees auto-popup modal with pending assignments on page load. After acceptance, task moves to `with_driver` and driver can mark as delivered.

**Tech Stack:** PHP (raw mysqli), Bootstrap 5 modals, vanilla JS fetch API

**Spec:** `docs/superpowers/specs/2026-03-28-driver-assignment-design.md`

---

### Task 1: Database Migration — Create `driver_assignments` table and extend status ENUM

**Files:**
- Create: `database/migrations/create_driver_assignments.php`
- Modify: `modules/production/tasks.php:77-84` (runtime ENUM migration)

- [ ] **Step 1: Create migration file**

Create `database/migrations/create_driver_assignments.php`:

```php
<?php
defined('ACCESS_ALLOWED') or define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/../../includes/db.php';
$db = Database::getInstance()->getConnection();
$db->query("CREATE TABLE IF NOT EXISTS driver_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL,
    driver_id INT NOT NULL,
    assigned_by INT NOT NULL,
    status ENUM('pending','accepted','rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    responded_at TIMESTAMP NULL,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_da_task (task_id),
    INDEX idx_da_driver_status (driver_id, status)
)");
echo "driver_assignments table created.\n";
```

- [ ] **Step 2: Update runtime ENUM migration in tasks.php**

At line 82, update the existing ENUM to include `with_driver`:

```php
$db->execute("ALTER TABLE tasks MODIFY COLUMN status ENUM('pending','received','in_progress','completed','with_delegate','with_driver','delivered','returned','cancelled') DEFAULT 'pending'");
```

After line 84, add runtime check for `with_driver` and `driver_assignments` table:

```php
$statusColumn2 = $db->queryOne("SHOW COLUMNS FROM tasks LIKE 'status'");
if (!empty($statusColumn2['Type'])) {
    $typeStr2 = (string) $statusColumn2['Type'];
    if (stripos($typeStr2, 'with_driver') === false) {
        $db->execute("ALTER TABLE tasks MODIFY COLUMN status ENUM('pending','received','in_progress','completed','with_delegate','with_driver','delivered','returned','cancelled') DEFAULT 'pending'");
        error_log('Extended tasks.status ENUM with with_driver');
    }
}
$daTable = $db->queryOne("SHOW TABLES LIKE 'driver_assignments'");
if (empty($daTable)) {
    $db->execute("CREATE TABLE driver_assignments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        task_id INT NOT NULL,
        driver_id INT NOT NULL,
        assigned_by INT NOT NULL,
        status ENUM('pending','accepted','rejected') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        responded_at TIMESTAMP NULL,
        INDEX idx_da_task (task_id),
        INDEX idx_da_driver_status (driver_id, status)
    )");
    error_log('Created driver_assignments table');
}
```

- [ ] **Step 3: Commit**

```bash
git add database/migrations/create_driver_assignments.php modules/production/tasks.php
git commit -m "feat: add driver_assignments table and with_driver status ENUM"
```

---

### Task 2: Backend — Add `assign_to_driver`, `accept_driver_assignment`, `reject_driver_assignment` actions

**Files:**
- Modify: `modules/production/tasks.php:687-712` (action handlers in tasksHandleAction)

- [ ] **Step 1: Add `assign_to_driver` action**

After the `with_delegate_task` elseif block (after line 699), before `} else {` on line 700, add:

```php
} elseif ($action === 'assign_to_driver') {
    if (!$isManager && !$isProduction) {
        throw new RuntimeException('غير مصرح لك بتنفيذ هذا الإجراء');
    }
    if (($task['status'] ?? '') !== 'completed') {
        throw new RuntimeException('يمكن تعيين سائق للمهام المكتملة فقط');
    }
    $backendTaskType = (strpos((string)($task['related_type'] ?? ''), 'manager_') === 0) ? substr((string)$task['related_type'], 8) : ($task['task_type'] ?? 'general');
    if ($backendTaskType === 'telegraph') {
        throw new RuntimeException('أوردرات التليجراف تستخدم "مع المندوب"');
    }
    $existingAssignment = $db->queryOne("SELECT id FROM driver_assignments WHERE task_id = ? AND status = 'pending'", [$taskId]);
    if ($existingAssignment) {
        throw new RuntimeException('يوجد تعيين سائق معلق لهذه المهمة بالفعل');
    }
    $driverId = isset($input['driver_id']) ? (int) $input['driver_id'] : 0;
    if ($driverId <= 0) {
        throw new RuntimeException('يجب اختيار سائق');
    }
    $driver = $db->queryOne("SELECT id, full_name FROM users WHERE id = ? AND role = 'driver' AND status = 'active'", [$driverId]);
    if (!$driver) {
        throw new RuntimeException('السائق غير موجود أو غير نشط');
    }
    $db->execute(
        "INSERT INTO driver_assignments (task_id, driver_id, assigned_by, status, created_at) VALUES (?, ?, ?, 'pending', NOW())",
        [$taskId, $driverId, $currentUser['id']]
    );
    logAudit($currentUser['id'], 'assign_to_driver', 'tasks', $taskId, null, ['driver_id' => $driverId]);
    try {
        $taskTitle = tasksSafeString($task['title'] ?? ('مهمة #' . $taskId));
        createNotification($driverId, 'طلب تسليم جديد', 'تم تعيينك لتسليم "' . $taskTitle . '". يرجى الموافقة أو الرفض.', 'info', getDashboardUrl('driver') . '?page=tasks');
    } catch (Throwable $e) {
        error_log('Driver assignment notification error: ' . $e->getMessage());
    }
    $result['success'] = 'تم تعيين السائق بنجاح. بانتظار موافقته.';
    break;
```

**Important:** This `break` exits the switch before `$statusMap` processing (line 714), so the task status stays `completed`.

- [ ] **Step 2: Add `accept_driver_assignment` and `reject_driver_assignment` cases**

Add as separate top-level cases in the switch (before the `default:` case):

```php
case 'accept_driver_assignment':
case 'reject_driver_assignment':
    if (!$isDriver && !$isManager) {
        throw new RuntimeException('غير مصرح لك بتنفيذ هذا الإجراء');
    }
    $assignmentId = isset($input['assignment_id']) ? (int) $input['assignment_id'] : 0;
    if ($assignmentId <= 0) {
        throw new RuntimeException('معرف التعيين غير صحيح');
    }
    $assignment = $db->queryOne("SELECT da.*, t.title AS task_title FROM driver_assignments da JOIN tasks t ON da.task_id = t.id WHERE da.id = ? AND da.status = 'pending'", [$assignmentId]);
    if (!$assignment) {
        throw new RuntimeException('التعيين غير موجود أو تم الرد عليه مسبقاً');
    }
    if (!$isManager && (int) $assignment['driver_id'] !== (int) $currentUser['id']) {
        throw new RuntimeException('هذا التعيين ليس موجهاً لك');
    }
    if ($action === 'accept_driver_assignment') {
        $db->execute("UPDATE driver_assignments SET status = 'accepted', responded_at = NOW() WHERE id = ?", [$assignmentId]);
        $db->execute("UPDATE tasks SET status = 'with_driver' WHERE id = ?", [(int) $assignment['task_id']]);
        logAudit($currentUser['id'], 'accept_driver_assignment', 'driver_assignments', $assignmentId, null, ['task_id' => $assignment['task_id']]);
        $result['success'] = 'تم قبول الطلب بنجاح';
        $result['new_status'] = 'with_driver';
    } else {
        $db->execute("UPDATE driver_assignments SET status = 'rejected', responded_at = NOW() WHERE id = ?", [$assignmentId]);
        logAudit($currentUser['id'], 'reject_driver_assignment', 'driver_assignments', $assignmentId, null, ['task_id' => $assignment['task_id']]);
        try {
            $taskTitle = tasksSafeString($assignment['task_title'] ?? ('مهمة #' . $assignment['task_id']));
            $driverName = $currentUser['full_name'] ?? $currentUser['username'] ?? 'السائق';
            createNotification((int) $assignment['assigned_by'], 'رفض تسليم', $driverName . ' رفض تسليم "' . $taskTitle . '". يمكنك تعيين سائق آخر.', 'warning', getDashboardUrl('production') . '?page=tasks');
        } catch (Throwable $e) {
            error_log('Driver rejection notification error: ' . $e->getMessage());
        }
        $result['success'] = 'تم رفض الطلب';
    }
    break;
```

- [ ] **Step 3: Update `change_status` handler**

At line 759, add `with_driver` to `$validStatuses`:
```php
$validStatuses = ['pending', 'received', 'in_progress', 'completed', 'with_delegate', 'with_driver', 'delivered', 'returned', 'cancelled'];
```

At line 766, add `with_driver` to driver allowed statuses:
```php
$driverAllowedStatuses = ['with_delegate', 'with_driver', 'delivered', 'returned'];
```

At line 777, add `with_driver` to completed_at statuses:
```php
$setParts[] = in_array($status, ['completed', 'with_delegate', 'with_driver', 'delivered', 'returned'], true) ? 'completed_at = NOW()' : 'completed_at = NULL';
```

- [ ] **Step 4: Commit**

```bash
git add modules/production/tasks.php
git commit -m "feat: add assign_to_driver, accept/reject driver assignment backend actions"
```

---

### Task 3: Backend — Update driver task filtering to respect assignments

**Files:**
- Modify: `modules/production/tasks.php:1029-1037` (driver WHERE conditions)

- [ ] **Step 1: Update driver filtering logic**

Replace lines 1029-1037 with:

```php
// السائق يرى: مكتملة، مع المندوب، تم التوصيل، تم الارجاع + مع السائق (المعينة له فقط)
if ($isDriver) {
    $driverAllowedStatuses = ['completed', 'with_delegate', 'with_driver', 'delivered', 'returned'];
    if ($statusFilter === 'with_driver') {
        $whereConditions[] = "t.status = 'with_driver'";
        $whereConditions[] = "t.id IN (SELECT task_id FROM driver_assignments WHERE driver_id = ? AND status = 'accepted')";
        $params[] = $currentUser['id'];
    } elseif ($statusFilter !== '' && in_array($statusFilter, $driverAllowedStatuses, true)) {
        $whereConditions[] = 't.status = ?';
        $params[] = $statusFilter;
    } else {
        $whereConditions[] = "(t.status IN ('completed', 'with_delegate', 'delivered', 'returned') OR (t.status = 'with_driver' AND t.id IN (SELECT task_id FROM driver_assignments WHERE driver_id = ? AND status = 'accepted')))";
        $params[] = $currentUser['id'];
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add modules/production/tasks.php
git commit -m "feat: filter with_driver tasks to show only assigned-to-current-driver"
```

---

### Task 4: Data Preparation — Fetch drivers list and pending assignments

**Files:**
- Modify: `modules/production/tasks.php` (~line 1398, after users query)
- Modify: `modules/production/tasks.php` (~line 1179, after tasks query)
- Modify: `modules/production/tasks.php` (~line 1479, before stats)

- [ ] **Step 1: Fetch drivers list**

After the existing users query (line 1398):
```php
$users = $db->query("SELECT id, full_name FROM users WHERE status = 'active' AND role = 'production' ORDER BY full_name");
```

Add:
```php
$drivers = $db->query("SELECT id, full_name FROM users WHERE status = 'active' AND role = 'driver' ORDER BY full_name");
if (!is_array($drivers)) $drivers = [];
```

- [ ] **Step 2: Batch-fetch pending driver assignments**

After `$tasks = $db->query($taskSql, $queryParams);` (around line 1179), add:

```php
$pendingDriverAssignments = [];
if (!empty($tasks)) {
    $taskIds = array_map(function ($t) { return (int) $t['id']; }, $tasks);
    $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
    $daRows = $db->query("SELECT task_id FROM driver_assignments WHERE task_id IN ($placeholders) AND status = 'pending'", $taskIds);
    if (is_array($daRows)) {
        foreach ($daRows as $daRow) {
            $pendingDriverAssignments[(int) $daRow['task_id']] = true;
        }
    }
}
```

- [ ] **Step 3: Fetch pending assignments for driver auto-popup**

Before the stats section (around line 1479), add:

```php
$pendingDriverRequests = [];
if ($isDriver) {
    $pendingDriverRequests = $db->query(
        "SELECT da.id AS assignment_id, da.task_id, da.created_at AS assigned_at,
                t.title, t.customer_name, t.product_name, t.quantity, t.unit, t.task_type, t.related_type,
                uAssign.full_name AS assigned_by_name
         FROM driver_assignments da
         JOIN tasks t ON da.task_id = t.id
         LEFT JOIN users uAssign ON da.assigned_by = uAssign.id
         WHERE da.driver_id = ? AND da.status = 'pending'
         ORDER BY da.created_at DESC",
        [$currentUser['id']]
    );
    if (!is_array($pendingDriverRequests)) $pendingDriverRequests = [];
}
```

- [ ] **Step 4: Add `$canAssignDriver` in task row rendering**

At line 1945 (after `$canWithDelegate`), add:

```php
$canAssignDriver = ($isManager || $isProduction) && ($task['status'] ?? '') === 'completed' && $canWithDelegateType !== 'telegraph' && empty($pendingDriverAssignments[(int) $task['id']]);
```

- [ ] **Step 5: Commit**

```bash
git add modules/production/tasks.php
git commit -m "feat: fetch drivers, pending assignments, and compute canAssignDriver"
```

---

### Task 5: Frontend — "مع السائق" button and driver selection modal

**Files:**
- Modify: `modules/production/tasks.php:1964-1966` (dropdown)
- Modify: `modules/production/tasks.php` (~line 2163, after orderReceiptModal)

- [ ] **Step 1: Add dropdown item**

After the `$canWithDelegate` button (line 1965), add:

```php
<?php if ($canAssignDriver): ?>
    <li><button type="button" class="dropdown-item" onclick="openDriverAssignModal(<?php echo $taskIdInt; ?>)"><i class="bi bi-truck me-2"></i>مع السائق</button></li>
<?php endif; ?>
```

- [ ] **Step 2: Add driver selection modal HTML**

After line 2163 (orderReceiptModal close), add:

```html
<!-- مودال تعيين سائق -->
<div class="modal fade" id="driverAssignModal" tabindex="-1" aria-labelledby="driverAssignModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="driverAssignModalLabel"><i class="bi bi-truck me-2"></i>تسليم للسائق</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="driverAssignTaskId" value="">
                <div class="mb-3">
                    <label for="driverSelect" class="form-label">اختر السائق</label>
                    <select class="form-select" id="driverSelect">
                        <option value="">-- اختر سائق --</option>
                        <?php foreach ($drivers as $drv): ?>
                            <option value="<?php echo (int) $drv['id']; ?>"><?php echo tasksHtml($drv['full_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">إلغاء</button>
                <button type="button" class="btn btn-info btn-sm text-white" onclick="submitDriverAssignment()">تسليم</button>
            </div>
        </div>
    </div>
</div>
```

- [ ] **Step 3: Commit**

```bash
git add modules/production/tasks.php
git commit -m "feat: add driver assignment button and selection modal"
```

---

### Task 6: Frontend — Driver pending requests auto-popup modal

**Files:**
- Modify: `modules/production/tasks.php` (after driver assign modal from Task 5)

- [ ] **Step 1: Add pending requests modal HTML**

After the driver assign modal, add:

```php
<?php if ($isDriver && !empty($pendingDriverRequests)): ?>
<div class="modal fade" id="pendingDriverRequestsModal" tabindex="-1" aria-labelledby="pendingDriverRequestsLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title" id="pendingDriverRequestsLabel">
                    <i class="bi bi-bell me-2"></i>طلبات بانتظار موافقتك (<?php echo count($pendingDriverRequests); ?>)
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body p-0">
                <div class="list-group list-group-flush">
                    <?php foreach ($pendingDriverRequests as $req):
                        $reqType = (strpos((string)($req['related_type'] ?? ''), 'manager_') === 0) ? substr((string)$req['related_type'], 8) : ($req['task_type'] ?? 'general');
                        $reqTypeLabels = ['shop_order' => 'اوردر محل', 'cash_customer' => 'عميل نقدي', 'telegraph' => 'تليجراف', 'shipping_company' => 'شركة شحن', 'general' => 'مهمة عامة', 'production' => 'إنتاج منتج'];
                    ?>
                    <div class="list-group-item" id="driverRequest-<?php echo (int) $req['assignment_id']; ?>">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <strong>أوردر #<?php echo (int) $req['task_id']; ?></strong>
                                <span class="badge bg-secondary ms-1"><?php echo tasksHtml($reqTypeLabels[$reqType] ?? $reqType); ?></span>
                            </div>
                            <small class="text-muted"><?php echo tasksHtml($req['assigned_by_name'] ?? ''); ?></small>
                        </div>
                        <?php if (!empty($req['customer_name'])): ?>
                            <p class="mb-1 text-muted"><i class="bi bi-person me-1"></i><?php echo tasksHtml($req['customer_name']); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($req['product_name'])): ?>
                            <p class="mb-2 text-muted"><i class="bi bi-box me-1"></i><?php echo tasksHtml($req['product_name']); ?> &times; <?php echo tasksHtml($req['quantity'] ?? ''); ?> <?php echo tasksHtml($req['unit'] ?? ''); ?></p>
                        <?php endif; ?>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-success btn-sm flex-fill" onclick="respondDriverRequest(<?php echo (int) $req['assignment_id']; ?>, 'accept')">
                                <i class="bi bi-check-lg me-1"></i>قبول
                            </button>
                            <button type="button" class="btn btn-outline-danger btn-sm flex-fill" onclick="respondDriverRequest(<?php echo (int) $req['assignment_id']; ?>, 'reject')">
                                <i class="bi bi-x-lg me-1"></i>رفض
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">إغلاق</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
```

- [ ] **Step 2: Commit**

```bash
git add modules/production/tasks.php
git commit -m "feat: add driver pending requests auto-popup modal HTML"
```

---

### Task 7: Frontend JS — All new JavaScript functions

**Files:**
- Modify: `modules/production/tasks.php` (JS section, after submitTaskAction ~line 2722)

- [ ] **Step 1: Add all JS functions**

After the `submitTaskAction` function, add:

```javascript
// === Driver Assignment Functions ===

window.openDriverAssignModal = function(taskId) {
    document.getElementById('driverAssignTaskId').value = taskId;
    document.getElementById('driverSelect').value = '';
    var modal = new bootstrap.Modal(document.getElementById('driverAssignModal'));
    modal.show();
};

window.submitDriverAssignment = function() {
    var taskId = parseInt(document.getElementById('driverAssignTaskId').value, 10) || 0;
    var driverId = parseInt(document.getElementById('driverSelect').value, 10) || 0;
    if (!taskId || !driverId) {
        if (typeof window.showToast === 'function') {
            window.showToast('يجب اختيار سائق', 'danger');
        } else {
            alert('يجب اختيار سائق');
        }
        return;
    }
    var formData = new FormData();
    formData.append('action', 'assign_to_driver');
    formData.append('task_id', taskId);
    formData.append('driver_id', driverId);
    var url = window.location.href;
    if (url.indexOf('page=tasks') === -1) {
        url = url + (url.indexOf('?') !== -1 ? '&' : '?') + 'page=tasks';
    }
    fetch(url, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        var modal = bootstrap.Modal.getInstance(document.getElementById('driverAssignModal'));
        if (modal) modal.hide();
        if (data.success) {
            if (typeof window.showToast === 'function') {
                window.showToast(data.success, 'success');
            } else {
                alert(data.success);
            }
        } else if (data.error) {
            if (typeof window.showToast === 'function') {
                window.showToast(data.error, 'danger');
            } else {
                alert(data.error);
            }
        }
    })
    .catch(function(err) {
        console.error('submitDriverAssignment error:', err);
        alert('حدث خطأ أثناء تعيين السائق');
    });
};

window.respondDriverRequest = function(assignmentId, response) {
    var action = response === 'accept' ? 'accept_driver_assignment' : 'reject_driver_assignment';
    var formData = new FormData();
    formData.append('action', action);
    formData.append('assignment_id', assignmentId);
    var url = window.location.href;
    if (url.indexOf('page=tasks') === -1) {
        url = url + (url.indexOf('?') !== -1 ? '&' : '?') + 'page=tasks';
    }
    fetch(url, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    })
    .then(function(resp) { return resp.json(); })
    .then(function(data) {
        if (data.success) {
            var card = document.getElementById('driverRequest-' + assignmentId);
            if (card) card.remove();
            var remaining = document.querySelectorAll('#pendingDriverRequestsModal .list-group-item').length;
            var titleEl = document.getElementById('pendingDriverRequestsLabel');
            if (titleEl) {
                titleEl.textContent = 'طلبات بانتظار موافقتك (' + remaining + ')';
            }
            if (remaining === 0) {
                var modal = bootstrap.Modal.getInstance(document.getElementById('pendingDriverRequestsModal'));
                if (modal) modal.hide();
            }
            if (typeof window.showToast === 'function') {
                window.showToast(data.success, response === 'accept' ? 'success' : 'warning');
            }
            if (response === 'accept') {
                setTimeout(function() { window.location.reload(); }, 800);
            }
        } else if (data.error) {
            if (typeof window.showToast === 'function') {
                window.showToast(data.error, 'danger');
            } else {
                alert(data.error);
            }
        }
    })
    .catch(function(err) {
        console.error('respondDriverRequest error:', err);
        alert('حدث خطأ');
    });
};
```

- [ ] **Step 2: Add auto-show for pending requests modal**

In the JS section, add (using PHP conditional):

```php
<?php if ($isDriver && !empty($pendingDriverRequests)): ?>
document.addEventListener('DOMContentLoaded', function() {
    var pendingModal = document.getElementById('pendingDriverRequestsModal');
    if (pendingModal) {
        var modal = new bootstrap.Modal(pendingModal);
        modal.show();
    }
});
<?php endif; ?>
```

- [ ] **Step 3: Commit**

```bash
git add modules/production/tasks.php
git commit -m "feat: add JS for driver assignment, accept/reject, and auto-popup"
```

---

### Task 8: Frontend — Update stats, labels, and `buildActionsHtml` for `with_driver`

**Files:**
- Modify: `modules/production/tasks.php` — multiple locations

- [ ] **Step 1: Add `with_driver` to stats**

At stats array (~line 1509), add after `with_delegate` line:
```php
'with_driver' => $buildStatsQuery("status = 'with_driver'"),
```

- [ ] **Step 2: Add stats card**

After `with_delegate` stats card (~line 1665), add:
```php
<div class="col-6 col-md-4 col-lg-2">
    <a href="<?php echo $filterBaseUrl . (strpos($filterBaseUrl, '?') !== false ? '&' : '?'); ?>status=with_driver" class="text-decoration-none">
        <div class="card <?php echo $statusFilter === 'with_driver' ? 'bg-primary text-white' : 'border-primary'; ?> text-center h-100">
            <div class="card-body p-2">
                <h5 class="<?php echo $statusFilter === 'with_driver' ? 'text-white' : 'text-primary'; ?> mb-0"><?php echo $stats['with_driver']; ?></h5>
                <small class="<?php echo $statusFilter === 'with_driver' ? 'text-white-50' : 'text-muted'; ?>">مع السائق</small>
            </div>
        </div>
    </a>
</div>
```

- [ ] **Step 3: Add status label**

At `$statusLabel` array (~line 1850), add:
```php
'with_driver' => 'مع السائق',
```

- [ ] **Step 4: Add delivery action for with_driver tasks**

After `$canDeliverReturn` (~line 1946), add:
```php
$canDeliverAsDriver = $isDriver && ($task['status'] ?? '') === 'with_driver';
```

In the dropdown (~line 1970), after the existing deliver/return block, add:
```php
<?php if ($canDeliverAsDriver): ?>
    <li><button type="button" class="dropdown-item" onclick="submitTaskAction('deliver_task', <?php echo $taskIdInt; ?>)"><i class="bi bi-truck me-2"></i>تم التوصيل</button></li>
<?php endif; ?>
```

- [ ] **Step 5: Update JS status maps and buildActionsHtml**

In `statusLabelMap` (~line 2597), add:
```javascript
'with_driver': 'مع السائق',
```

In `statusClassMap` (~line 2605), add:
```javascript
'with_driver': 'primary',
```

In `buildActionsHtml` (~line 2628), after the existing deliver/return block, add:
```javascript
if (flags.isDriver && newStatus === 'with_driver') {
    html += '<button type="button" class="btn btn-outline-success btn-sm" onclick="submitTaskAction(\'deliver_task\', ' + taskId + ')"><i class="bi bi-truck me-1"></i>تم التوصيل</button>';
}
```

In `statusText` map (~line 2740), add:
```javascript
'with_driver': 'مع السائق',
```

- [ ] **Step 6: Update overdue filter and driver stats base**

At line 1021 (overdue exclusion), add `with_driver`:
```php
$whereConditions[] = "t.status NOT IN ('completed','with_delegate','with_driver','delivered','returned','cancelled')";
```

At line 1481 (driver stats base), add `with_driver`:
```php
$statsBaseConditions = ["status IN ('completed', 'with_delegate', 'with_driver', 'delivered', 'returned')"];
```

- [ ] **Step 7: Commit**

```bash
git add modules/production/tasks.php
git commit -m "feat: add with_driver to stats, labels, actions, and JS rendering"
```
