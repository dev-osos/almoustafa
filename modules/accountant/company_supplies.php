<?php
/**
 * صفحة مستلزمات الشركة - المحاسب
 * Company Supplies Page - Accountant
 */

if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}


require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/table_styles.php';
require_once __DIR__ . '/../../includes/audit_log.php';

requireRole(['accountant', 'developer']);

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';

// معالجة POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_supplies') {
        try {
            $items = isset($_POST['items']) ? json_decode(stripslashes($_POST['items']), true) : [];
            
            if (empty($items)) {
                $error = 'يرجى إضافة عنصر واحد على الأقل.';
            } else {
                // التحقق من صحة البيانات
                $valid = true;
                foreach ($items as $item) {
                    if (empty($item['name']) || empty($item['quantity'])) {
                        $valid = false;
                        break;
                    }
                    if (!is_numeric($item['quantity']) || $item['quantity'] <= 0) {
                        $valid = false;
                        break;
                    }
                    if (isset($item['price']) && $item['price'] !== '' && !is_numeric($item['price'])) {
                        $valid = false;
                        break;
                    }
                }
                
                if (!$valid) {
                    $error = 'الرجاء التحقق من صحة البيانات المدخلة.';
                } else {
                    // حفظ المستلزمات في قاعدة البيانات
                    $suppliesJson = json_encode($items, JSON_UNESCAPED_UNICODE);
                    $status = $_POST['status'] ?? 'pending';
                    
                    // التحقق من أن الحالة صحيحة
                    if (!in_array($status, ['pending', 'purchased'], true)) {
                        $status = 'pending';
                    }
                    
                    // التحقق من وجود جدول company_supplies
                    $tableExists = $db->queryOne("SHOW TABLES LIKE 'company_supplies'");
                    if (empty($tableExists)) {
                        // إنشاء الجدول
                        $db->execute("
                            CREATE TABLE IF NOT EXISTS `company_supplies` (
                              `id` int(11) NOT NULL AUTO_INCREMENT,
                              `items` longtext NOT NULL,
                              `status` varchar(20) DEFAULT 'pending',
                              `created_by` int(11) NOT NULL,
                              `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
                              `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                              PRIMARY KEY (`id`),
                              KEY `status` (`status`),
                              KEY `created_by` (`created_by`),
                              KEY `created_at` (`created_at`)
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                        ");
                    }
                    
                    // إدراج السجل الجديد
                    $db->execute(
                        "INSERT INTO company_supplies (items, status, created_by) VALUES (?, ?, ?)",
                        [$suppliesJson, $status, $currentUser['id']]
                    );
                    
                    $success = 'تم حفظ المستلزمات بنجاح.';
                    logAudit(
                        $currentUser['id'],
                        'company_supplies_create',
                        'company_supplies',
                        null,
                        null,
                        ['message' => 'تم حفظ مستلزمات جديدة', 'items_count' => count($items), 'status' => $status]
                    );
                }
            }
        } catch (Exception $e) {
            error_log('Error saving supplies: ' . $e->getMessage());
            $error = 'حدث خطأ أثناء حفظ البيانات.';
        }
    }
}

// جلب المستلزمات الحالية
$supplies = [];
try {
    $tableExists = $db->queryOne("SHOW TABLES LIKE 'company_supplies'");
    if (!empty($tableExists)) {
        $supplies = $db->query("
            SELECT id, items, status, created_at, created_by
            FROM company_supplies
            ORDER BY created_at DESC
            LIMIT 100
        ");
    }
} catch (Exception $e) {
    error_log('Error fetching supplies: ' . $e->getMessage());
}

$sessionSuccess = getSuccessMessage();
if ($sessionSuccess) {
    $success = $sessionSuccess;
}

$sessionError = getErrorMessage();
if ($sessionError) {
    $error = $sessionError;
}
?>

<style>
.supplies-container {
    direction: rtl;
}

.supply-item-row {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 50px;
    gap: 10px;
    margin-bottom: 10px;
    align-items: flex-end;
}

.supply-item-row input {
    padding: 8px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}

.supply-item-row button {
    padding: 8px 12px;
    background-color: #dc3545;
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
}

.supply-item-row button:hover {
    background-color: #c82333;
}

.supplies-table {
    margin-top: 30px;
}

.supplies-table table {
    width: 100%;
    border-collapse: collapse;
    background-color: white;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.supplies-table th {
    background-color: #007bff;
    color: white;
    padding: 12px;
    text-align: right;
    border: 1px solid #ddd;
}

.supplies-table td {
    padding: 10px 12px;
    border: 1px solid #ddd;
    text-align: right;
}

.supplies-table tr:nth-child(even) {
    background-color: #f8f9fa;
}

.supplies-table tr:hover {
    background-color: #e9ecef;
}

.status-badge {
    display: inline-block;
    padding: 5px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: bold;
}

.status-pending {
    background-color: #ffc107;
    color: #000;
}

.status-purchased {
    background-color: #28a745;
    color: white;
}

/* Dropdown Actions Styles */
.dropdown-actions {
    position: relative;
    display: inline-block;
}

.btn-dropdown {
    background-color: #6c757d;
    color: white;
    padding: 6px 12px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 13px;
    display: flex;
    align-items: center;
    gap: 5px;
}

.btn-dropdown:hover {
    background-color: #545b62;
}

.dropdown-menu {
    display: none;
    position: absolute;
    background-color: white;
    min-width: 160px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    border-radius: 4px;
    z-index: 1000;
    right: 0;
    top: 100%;
    margin-top: 5px;
    border: 1px solid #ddd;
}

.dropdown-menu.show {
    display: block;
}

.dropdown-menu a {
    color: #333;
    padding: 10px 15px;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    border-bottom: 1px solid #f0f0f0;
    transition: background-color 0.2s;
}

.dropdown-menu a:last-child {
    border-bottom: none;
}

.dropdown-menu a:hover {
    background-color: #f8f9fa;
}

.dropdown-menu a.text-danger {
    color: #dc3545;
}

.dropdown-menu a.text-danger:hover {
    background-color: #f8d7da;
}

.action-buttons {
    display: flex;
    gap: 5px;
}

.action-buttons button {
    padding: 5px 10px;
    font-size: 12px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
}

/* Responsive styles for mobile */
@media (max-width: 768px) {
    .supplies-container,
    .card,
    .card-body,
    form {
        width: 100% !important;
        max-width: 100vw !important;
        overflow-x: hidden !important;
        box-sizing: border-box !important;
    }

    .row {
        margin-left: 0 !important;
        margin-right: 0 !important;
    }

    .col-md-6,
    [class*="col-"] {
        width: 100% !important;
        max-width: 100% !important;
        flex: 0 0 100% !important;
        padding-left: 10px !important;
        padding-right: 10px !important;
    }

    .form-select,
    .form-control,
    select,
    input[type="text"],
    input[type="number"] {
        width: 100% !important;
        max-width: 100% !important;
        font-size: 16px !important;
        box-sizing: border-box !important;
    }

    .supply-item-row {
        display: flex !important;
        flex-direction: column !important;
        gap: 10px !important;
        width: 100% !important;
        padding: 10px !important;
        background: #f8f9fa;
        border-radius: 8px;
        margin-bottom: 10px;
        box-sizing: border-box !important;
    }

    .supply-item-row input,
    .supply-item-row button {
        width: 100% !important;
        max-width: 100% !important;
        padding: 12px !important;
        font-size: 16px;
        box-sizing: border-box !important;
    }

    .supplies-table {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        width: 100%;
        box-sizing: border-box;
    }

    .supplies-table table {
        min-width: 100%;
        width: 100%;
        table-layout: auto;
    }

    .supplies-table th,
    .supplies-table td {
        padding: 8px 6px;
        font-size: 13px;
        white-space: nowrap;
    }

    .action-buttons {
        flex-wrap: wrap;
        gap: 8px;
        flex-direction: column;
    }

    .action-buttons button {
        width: 100%;
        min-width: auto;
        padding: 10px;
        margin-bottom: 5px;
    }

    .status-badge {
        font-size: 11px;
        padding: 4px 6px;
        white-space: nowrap;
    }

    .items-list {
        font-size: 12px;
        padding: 6px;
        max-width: 200px;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .items-list li {
        font-size: 12px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .card {
        margin: 10px;
        width: calc(100% - 20px);
        box-sizing: border-box;
    }

    .card-body {
        padding: 15px;
    }
}

@media (max-width: 480px) {
    .page-header h2 {
        font-size: 1.3rem;
    }

    .card-header h5 {
        font-size: 1rem;
    }
}

.btn-print {
    background-color: #007bff;
    color: white;
}

.btn-print:hover {
    background-color: #0056b3;
}

.btn-status {
    background-color: #6c757d;
    color: white;
}

.btn-status:hover {
    background-color: #545b62;
}

.items-list {
    background-color: #f8f9fa;
    padding: 10px;
    border-radius: 4px;
    margin-top: 5px;
}

.items-list ul {
    margin: 0;
    padding-right: 20px;
}

.items-list li {
    margin: 5px 0;
    font-size: 14px;
}
</style>

<div class="page-header">
    <h2><i class="bi bi-box-seam"></i> مستلزمات الشركة</h2>
</div>

<?php if ($error): ?>
<div class="alert alert-danger" role="alert">
    <i class="bi bi-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
</div>
<?php endif; ?>

<?php if ($success): ?>
<div class="alert alert-success" role="alert">
    <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h5><i class="bi bi-pencil-square"></i> إضافة مستلزمات جديدة</h5>
    </div>
    <div class="card-body">
        <form method="POST" id="suppliesForm" class="supplies-container" data-no-loading="true">
            <input type="hidden" name="action" value="save_supplies">
            <input type="hidden" name="items" id="itemsInput">
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">حالة الإيصال</label>
                    <select name="status" class="form-select" id="statusSelect">
                        <option value="pending">معلق</option>
                        <option value="purchased">تم الشراء</option>
                    </select>
                </div>
            </div>
            
            <h6 class="mb-3">قائمة المستلزمات:</h6>
            <div id="itemsContainer"></div>
            
            <button type="button" class="btn btn-secondary btn-sm" id="addItemBtn">
                <i class="bi bi-plus-circle me-2"></i>إضافة عنصر جديد
            </button>
            
            <div class="mt-3">
                <button type="submit" class="btn btn-success btn-sm">
                    <i class="bi bi-save me-2"></i>حفظ المستلزمات
                </button>
            </div>
        </form>
    </div>
</div>

<?php if (!empty($supplies)): ?>
<div class="supplies-table">
    <h5 class="mt-4 mb-3">المستلزمات المسجلة</h5>
    <table>
        <thead>
            <tr>
                <th>رقم الإيصال</th>
                <th>التاريخ</th>
                <th>الحالة</th>
                <th>المستلزمات</th>
                <th>الإجراءات</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($supplies as $supply): ?>
                <?php
                    $items = json_decode($supply['items'], true);
                    $statusClass = $supply['status'] === 'purchased' ? 'status-purchased' : 'status-pending';
                    $statusLabel = $supply['status'] === 'purchased' ? 'تم الشراء' : 'معلق';
                    $createdAt = date('Y-m-d H:i', strtotime($supply['created_at']));
                ?>
                <tr>
                    <td><?php echo sprintf('%05d', $supply['id']); ?></td>
                    <td><?php echo htmlspecialchars($createdAt); ?></td>
                    <td>
                        <span class="status-badge <?php echo $statusClass; ?>">
                            <?php echo htmlspecialchars($statusLabel); ?>
                        </span>
                    </td>
                    <td>
                        <?php if (is_array($items) && !empty($items)): ?>
                            <div class="items-list">
                                <ul>
                                    <?php foreach ($items as $item): ?>
                                        <li>
                                            <strong><?php echo htmlspecialchars($item['name']); ?></strong> - 
                                            الكمية: <?php echo htmlspecialchars($item['quantity']); ?>
                                            <?php if (!empty($item['price'])): ?>
                                                 - السعر: <?php echo htmlspecialchars($item['price']); ?>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php else: ?>
                            <span class="text-muted">لا توجد بيانات</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="dropdown-actions">
                            <button type="button" class="btn-dropdown" onclick="toggleDropdown(<?php echo $supply['id']; ?>)">
                                <i class="bi bi-three-dots-vertical"></i> إجراءات
                            </button>
                            <div class="dropdown-menu" id="dropdown-<?php echo $supply['id']; ?>">
                                <a href="#" onclick="printSupply(<?php echo $supply['id']; ?>, '<?php echo htmlspecialchars($statusLabel); ?>'); return false;">
                                    <i class="bi bi-printer"></i> طباعة
                                </a>
                                <?php if ($supply['status'] === 'pending'): ?>
                                <a href="#" onclick="updateStatus(<?php echo $supply['id']; ?>); return false;">
                                    <i class="bi bi-check-lg"></i> تحديث الحالة
                                </a>
                                <a href="#" class="text-danger" onclick="deleteSupply(<?php echo $supply['id']; ?>); return false;">
                                    <i class="bi bi-trash"></i> حذف
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<script>
const companySuppliesApiUrl = '<?php echo getRelativeUrl('api/company_supplies_api.php'); ?>';

function showSuppliesMessage(message, type = 'success') {
    const existingAlerts = document.querySelectorAll('.company-supplies-dynamic-alert');
    existingAlerts.forEach((alert) => alert.remove());

    const wrapper = document.createElement('div');
    wrapper.className = `alert alert-${type} company-supplies-dynamic-alert`;
    wrapper.setAttribute('role', 'alert');
    wrapper.innerHTML = `<i class="bi ${type === 'success' ? 'bi-check-circle' : 'bi-exclamation-circle'} me-2"></i>${message}`;

    const pageHeader = document.querySelector('.page-header');
    if (pageHeader && pageHeader.parentNode) {
        pageHeader.parentNode.insertBefore(wrapper, pageHeader.nextSibling);
    }

    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function resetSuppliesForm() {
    const form = document.getElementById('suppliesForm');
    const container = document.getElementById('itemsContainer');
    const statusSelect = document.getElementById('statusSelect');

    if (form) form.reset();
    if (statusSelect) statusSelect.value = 'pending';
    if (container) {
        container.innerHTML = '';
        addItem();
    }
}

// Toggle dropdown menu
function toggleDropdown(id) {
    const dropdown = document.getElementById('dropdown-' + id);
    if (!dropdown) return;
    
    // Close all other open dropdowns
    document.querySelectorAll('.dropdown-menu.show').forEach(function(menu) {
        if (menu.id !== 'dropdown-' + id) {
            menu.classList.remove('show');
        }
    });
    
    // Toggle current dropdown
    dropdown.classList.toggle('show');
}

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('.dropdown-actions')) {
        document.querySelectorAll('.dropdown-menu.show').forEach(function(menu) {
            menu.classList.remove('show');
        });
    }
});

// Prevent dropdown from closing when clicking on menu items
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.dropdown-menu a').forEach(function(item) {
        item.addEventListener('click', function(e) {
            e.stopPropagation();
            // Close dropdown after action
            const menu = this.closest('.dropdown-menu');
            if (menu) menu.classList.remove('show');
        });
    });
});

function initializeItems() {
    const container = document.getElementById('itemsContainer');
    const addBtn = document.getElementById('addItemBtn');

    if (!container || !addBtn) return;

    addBtn.removeEventListener('click', addItem);
    addBtn.addEventListener('click', addItem);

    if (container.children.length === 0) {
        addItem();
    }

    const form = document.getElementById('suppliesForm');
    if (form && !form.dataset.suppliesListenerAttached) {
        form.dataset.suppliesListenerAttached = 'true';
        form.addEventListener('submit', handleSuppliesSubmit);
    }
}

function addItem() {
    const container = document.getElementById('itemsContainer');
    const index = container.children.length;
    
    const itemDiv = document.createElement('div');
    itemDiv.className = 'supply-item-row';
    itemDiv.innerHTML = `
        <input type="text" placeholder="اسم العنصر" class="item-name" data-index="${index}">
        <input type="number" placeholder="الكمية" class="item-quantity" data-index="${index}" step="0.01" min="0">
        <input type="number" placeholder="السعر (اختياري)" class="item-price" data-index="${index}" step="0.01" min="0">
        <button type="button" onclick="removeItem(${index})"><i class="bi bi-trash"></i></button>
    `;
    
    container.appendChild(itemDiv);
}

function removeItem(index) {
    const container = document.getElementById('itemsContainer');
    const items = container.querySelectorAll('.supply-item-row');
    if (items.length > 1) {
        items[index].remove();
    } else {
        alert('يجب أن يكون هناك عنصر واحد على الأقل.');
    }
}

function collectItems() {
    const container = document.getElementById('itemsContainer');
    const items = [];
    
    container.querySelectorAll('.supply-item-row').forEach((row, index) => {
        const name = row.querySelector('.item-name').value.trim();
        const quantity = parseFloat(row.querySelector('.item-quantity').value || 0);
        const price = row.querySelector('.item-price').value.trim();
        
        if (name && quantity > 0) {
            items.push({
                name: name,
                quantity: quantity,
                price: price ? parseFloat(price) : null
            });
        }
    });
    
    return items;
}

function handleSuppliesSubmit(e) {
    e.preventDefault();

    const items = collectItems();
    if (items.length === 0) {
        alert('يرجى إضافة عنصر واحد على الأقل.');
        return;
    }

    document.getElementById('itemsInput').value = JSON.stringify(items);

    const form = document.getElementById('suppliesForm');
    const submitBtn = form.querySelector('[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;

    const formData = new FormData(form);

    fetch(companySuppliesApiUrl, {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
        },
        body: formData,
        credentials: 'same-origin'
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            throw new Error(data.message || 'حدث خطأ أثناء حفظ المستلزمات.');
        }

        showSuppliesMessage(data.message || 'تم حفظ المستلزمات بنجاح.', 'success');
        resetSuppliesForm();
    })
    .catch((error) => {
        showSuppliesMessage(error.message || 'حدث خطأ في الاتصال بالخادم.', 'danger');
    })
    .finally(() => {
        const btn = document.querySelector('#suppliesForm [type="submit"]');
        if (btn) btn.disabled = false;
    });
}

function printSupply(id, status) {
    // فتح نافذة جديدة للطباعة
    const url = '<?php echo getRelativeUrl('print_company_supplies.php'); ?>?id=' + id + '&status=' + encodeURIComponent(status);
    window.open(url, 'print', 'width=800,height=600');
}

function updateStatus(id) {
    if (confirm('هل تريد تغيير حالة الإيصال إلى "تم الشراء"؟')) {
        // سيتم معالجة هذا عبر AJAX API
        fetch('<?php echo getRelativeUrl('api/company_supplies_api.php'); ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                action: 'update_status',
                id: id,
                status: 'purchased'
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('تم تحديث حالة الإيصال بنجاح.');
                location.reload();
            } else {
                alert(data.message || 'حدث خطأ أثناء التحديث.');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('حدث خطأ في الاتصال بالخادم.');
        });
    }
}

function deleteSupply(id) {
    if (!confirm('هل أنت متأكد من حذف الإيصال؟')) return;

    fetch('<?php echo getRelativeUrl('api/company_supplies_api.php'); ?>', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
            action: 'delete_supply',
            id: id
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('تم حذف الإيصال بنجاح.');
            location.reload();
        } else {
            alert(data.message || 'حدث خطأ أثناء الحذف.');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('حدث خطأ في الاتصال بالخادم.');
    });
}

if (document.readyState !== 'loading') {
    initializeItems();
} else {
    document.addEventListener('DOMContentLoaded', initializeItems);
}
window.addEventListener('ajaxNavigationComplete', initializeItems);
</script>
