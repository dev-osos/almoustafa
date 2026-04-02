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

requireRole(['production', 'developer']);

$currentUser = getCurrentUser();
$db = db();

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
</style>

<div class="page-header">
    <h2><i class="bi bi-box-seam"></i> مستلزمات الشركة</h2>
</div>

<div class="alert alert-info">
    <i class="bi bi-info-circle me-2"></i>
    يمكنك عرض المستلزمات المطلوبة وطباعتها. إذا كانت الحالة "معلق" فهذا يعني أنها في انتظار الشراء.
</div>

<div class="supplies-table">
    <?php if (!empty($supplies)): ?>
        <h5 class="mb-3">المستلزمات المسجلة</h5>
        <table>
            <thead>
                <tr>
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
function printSupply(id, status) {
    // فتح نافذة جديدة للطباعة
    const url = '<?php echo getRelativeUrl('print_company_supplies.php'); ?>?id=' + id + '&status=' + encodeURIComponent(status);
    window.open(url, 'print', 'width=800,height=600');
}
</script>
