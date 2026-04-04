<?php
/**
 * صفحة مستلزمات الشركة - عمال الإنتاج
 * Company Supplies Page - Production
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

requireRole(['production', 'developer']);

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';

// معالجة POST للتسجيل
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_supplies') {
    try {
        $items = isset($_POST['items']) ? json_decode(stripslashes($_POST['items']), true) : [];
        if (empty($items) || !is_array($items)) {
            $error = 'يرجى إضافة عنصر واحد على الأقل.';
        } else {
            $isValid = true;
            foreach ($items as $item) {
                $name = trim($item['name'] ?? '');
                $quantity = floatval($item['quantity'] ?? 0);
                $price = $item['price'] ?? null;

                if ($name === '' || $quantity <= 0) {
                    $isValid = false;
                    break;
                }

                if ($price !== null && $price !== '' && !is_numeric($price)) {
                    $isValid = false;
                    break;
                }
            }

            if (!$isValid) {
                $error = 'الرجاء التحقق من صحة البيانات المدخلة لكل عنصر.';
            } else {
                $tableExists = $db->queryOne("SHOW TABLES LIKE 'company_supplies'");
                if (empty($tableExists)) {
                    $db->execute(
                        "CREATE TABLE IF NOT EXISTS `company_supplies` (
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
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
                    );
                }

                $db->execute(
                    "INSERT INTO company_supplies (items, status, created_by) VALUES (?, 'pending', ?)",
                    [json_encode($items, JSON_UNESCAPED_UNICODE), $currentUser['id']]
                );

                addAuditLog(
                    $currentUser['id'],
                    'company_supplies_create',
                    'company_supplies',
                    'create',
                    'تم إنشاء مستلزمات من قبل إنتاج',
                    json_encode(['items' => $items], JSON_UNESCAPED_UNICODE)
                );

                $success = 'تم تسجيل المستلزمات بنجاح.';

                // بدون إعادة توجيه للحفاظ على الصفحة وظهور رسالة النجاح مباشرة
                // فقط يتم عرض الرسالة وتحديث الجدول ضمن نفس الطلب
            }
        }
    } catch (Exception $e) {
        error_log('Error saving production supplies: ' . $e->getMessage());
        $error = 'حدث خطأ أثناء حفظ المستلزمات.';
    }
}

// جلب المستلزمات الحالية
$supplies = [];
try {
    $tableExists = $db->queryOne("SHOW TABLES LIKE 'company_supplies'");
    if (!empty($tableExists)) {
        $supplies = $db->query("
            SELECT id, items, status, created_at
            FROM company_supplies
            ORDER BY created_at DESC
            LIMIT 100
        ");
    }
} catch (Exception $e) {
    error_log('Error fetching supplies: ' . $e->getMessage());
}
?>

<style>
.supplies-container {
    direction: rtl;
}

.supply-item-row {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 70px;
    gap: 10px;
    align-items: center;
}

.supply-item-row input,
.supply-item-row button {
    font-size: 13px;
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
    background-color: #607d8b;
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

.action-buttons {
    display: flex;
    gap: 5px;
}

.btn-print {
    padding: 5px 10px;
    font-size: 12px;
    background-color: #007bff;
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
}

.btn-print:hover {
    background-color: #0056b3;
}

/* Responsive styles for mobile */
@media (max-width: 768px) {
    .supply-item-row {
        grid-template-columns: 1fr;
        gap: 12px;
        margin-bottom: 20px;
        padding: 15px;
        background: #f8f9fa;
        border-radius: 8px;
        width: 100%;
        box-sizing: border-box;
    }

    .supply-item-row input,
    .supply-item-row button {
        width: 100%;
        padding: 15px;
        font-size: 16px; /* Prevent zoom on iOS */
        box-sizing: border-box;
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
</style>

<div class="page-header">
    <h2><i class="bi bi-box-seam"></i> مستلزمات الشركة</h2>
</div>

<?php if ($error): ?>
<div class="alert alert-danger">
    <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
</div>
<?php endif; ?>

<?php if ($success): ?>
<div class="alert alert-success">
    <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
</div>
<?php endif; ?>

<div class="alert alert-info">
    <i class="bi bi-info-circle me-2"></i>
    يمكنك تسجيل مستلزمات جديدة وطباعتها. الحالة الافتراضية للمستلزمات من الإنتاج هي "معلق".
</div>

<div class="card">
    <div class="card-header">
        <h5><i class="bi bi-pencil-square"></i> إضافة مستلزمات جديدة</h5>
    </div>
    <div class="card-body supplies-container">
        <form method="POST" id="suppliesForm" data-no-loading="true">
            <input type="hidden" name="action" value="save_supplies">
            <input type="hidden" name="items" id="itemsInput">

            <h6 class="mb-3">قائمة المستلزمات:</h6>
            <div id="itemsContainer"></div>

            <button type="button" class="btn btn-secondary btn-sm" id="addItemBtn">
                <i class="bi bi-plus-circle me-2"></i>إضافة عنصر جديد
            </button>
            <button type="submit" class="btn btn-success btn-sm mt-2">
                <i class="bi bi-save me-2"></i> حفظ المستلزمات
            </button>
        </form>
    </div>
</div>

<div class="supplies-table">
    <?php if (!empty($supplies)): ?>
        <h5 class="mb-3">المستلزمات المسجلة</h5>
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
                            <div class="action-buttons">
                                <button type="button" class="btn-print" onclick="printSupply(<?php echo $supply['id']; ?>, '<?php echo htmlspecialchars($statusLabel); ?>')">
                                    <i class="bi bi-printer"></i> طباعة
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle me-2"></i>
            لا توجد مستلزمات مسجلة حالياً.
        </div>
    <?php endif; ?>
</div>

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

    if (form) form.reset();
    if (container) {
        container.innerHTML = '';
        addItem();
    }
}

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
    itemDiv.className = 'supply-item-row mb-2';
    itemDiv.innerHTML = `
        <input type="text" placeholder="اسم العنصر" class="item-name form-control" data-index="${index}">
        <input type="number" placeholder="الكمية" class="item-quantity form-control" data-index="${index}" step="0.01" min="0">
        <input type="number" placeholder="السعر (اختياري)" class="item-price form-control" data-index="${index}" step="0.01" min="0">
        <button type="button" class="btn btn-danger btn-sm" onclick="removeItem(this)"><i class="bi bi-trash"></i></button>
    `;

    container.appendChild(itemDiv);
}

function removeItem(button) {
    const container = document.getElementById('itemsContainer');
    const row = button.closest('.supply-item-row');
    const rows = container.querySelectorAll('.supply-item-row');

    if (rows.length > 1 && row) {
        row.remove();
    } else {
        alert('يجب أن يكون هناك عنصر واحد على الأقل.');
    }
}

function collectItems() {
    const rows = document.querySelectorAll('.supply-item-row');
    const items = [];

    rows.forEach(row => {
        const nameInput = row.querySelector('.item-name');
        const quantityInput = row.querySelector('.item-quantity');
        const priceInput = row.querySelector('.item-price');

        const name = nameInput ? nameInput.value.trim() : '';
        const quantity = parseFloat(quantityInput ? quantityInput.value : 0);
        const priceRaw = priceInput ? priceInput.value.trim() : '';
        const price = priceRaw === '' ? null : parseFloat(priceRaw);

        if (name !== '' && quantity > 0) {
            items.push({
                name: name,
                quantity: quantity,
                price: price !== null && !Number.isNaN(price) ? price : null
            });
        }
    });

    return items;
}

function handleSuppliesSubmit(e) {
    e.preventDefault();

    const items = collectItems();
    if (items.length === 0) {
        alert('يرجى إضافة عنصر واحد على الأقل وكُلّ بياناته صحيحة.');
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
    const url = '<?php echo getRelativeUrl('print_company_supplies.php'); ?>?id=' + id + '&status=' + encodeURIComponent(status);
    window.open(url, 'print', 'width=800,height=600');
}

if (document.readyState !== 'loading') {
    initializeItems();
} else {
    document.addEventListener('DOMContentLoaded', initializeItems);
}
window.addEventListener('ajaxNavigationComplete', initializeItems);
</script>
