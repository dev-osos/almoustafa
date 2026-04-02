<?php
/**
 * صفحة مستلزمات الشركة - المدير والمحاسب
 * Company Supplies Page - Manager
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

requireRole(['manager', 'accountant', 'developer']);

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
                    addAuditLog(
                        $currentUser['id'],
                        'company_supplies_create',
                        'company_supplies',
                        'create',
                        'تم حفظ مستلزمات جديدة',
                        json_encode(['items_count' => count($items), 'status' => $status])
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
        <form method="POST" id="suppliesForm" class="supplies-container">
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
                <th>التاريخ</th>
                <th>الحالة</th>
                <th>عدد العناصر</th>
                <th>الإجراءات</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($supplies as $supply): ?>
                <?php
                    $items = json_decode($supply['items'], true);
                    $itemCount = is_array($items) ? count($items) : 0;
                    $statusClass = $supply['status'] === 'purchased' ? 'status-purchased' : 'status-pending';
                    $statusLabel = $supply['status'] === 'purchased' ? 'تم الشراء' : 'معلق';
                    $createdAt = date('Y-m-d H:i', strtotime($supply['created_at']));
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($createdAt); ?></td>
                    <td>
                        <span class="status-badge <?php echo $statusClass; ?>">
                            <?php echo htmlspecialchars($statusLabel); ?>
                        </span>
                    </td>
                    <td><?php echo $itemCount; ?></td>
                    <td>
                        <div class="action-buttons">
                            <button type="button" class="btn-print" onclick="printSupply(<?php echo $supply['id']; ?>, '<?php echo htmlspecialchars($statusLabel); ?>')">
                                <i class="bi bi-printer"></i> طباعة
                            </button>
                            <?php if ($supply['status'] === 'pending' && in_array($currentUser['role'], ['manager', 'accountant'])): ?>
                            <button type="button" class="btn-status" onclick="updateStatus(<?php echo $supply['id']; ?>)">
                                <i class="bi bi-check-lg"></i> تحديث
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<script>
function initializeItems() {
    const container = document.getElementById('itemsContainer');
    const addBtn = document.getElementById('addItemBtn');
    
    addBtn.addEventListener('click', addItem);
    
    // إضافة عنصر واحد افتراضياً
    if (container.children.length === 0) {
        addItem();
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

document.getElementById('suppliesForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const items = collectItems();
    
    if (items.length === 0) {
        alert('يرجى إضافة عنصر واحد على الأقل.');
        return;
    }
    
    document.getElementById('itemsInput').value = JSON.stringify(items);
    this.submit();
});

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

// تهيئة الصفحة
document.addEventListener('DOMContentLoaded', initializeItems);
</script>
