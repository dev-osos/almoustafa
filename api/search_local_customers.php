<?php
/**
 * API: البحث الديناميكي في العملاء المحليين (pagination بدون إعادة تحميل)
 */

define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/path_helper.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'يجب تسجيل الدخول'], JSON_UNESCAPED_UNICODE);
    exit;
}

$currentUser = getCurrentUser();
$currentRole = strtolower((string)($currentUser['role'] ?? ''));
if (!in_array($currentRole, ['accountant', 'manager', 'developer'])) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح لك'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = db();

// Pagination
$isMobile = isset($_SERVER['HTTP_USER_AGENT']) && preg_match('/(android|iphone|ipad|mobile)/i', $_SERVER['HTTP_USER_AGENT']);
$page = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$perPage = $isMobile ? 10 : 20;
$offset = ($page - 1) * $perPage;

// البحث والفلترة
$search = trim($_GET['search'] ?? '');
$debtStatus = $_GET['debt_status'] ?? 'all';
$allowedDebtStatuses = ['all', 'debtor', 'clear'];
if (!in_array($debtStatus, $allowedDebtStatuses, true)) {
    $debtStatus = 'all';
}
$regionFilter = isset($_GET['region_id']) && $_GET['region_id'] !== '' ? (int)$_GET['region_id'] : null;
$balanceFrom = isset($_GET['balance_from']) && $_GET['balance_from'] !== '' ? (float)$_GET['balance_from'] : null;
$balanceTo = isset($_GET['balance_to']) && $_GET['balance_to'] !== '' ? (float)$_GET['balance_to'] : null;
$sortBalance = $_GET['sort_balance'] ?? '';
if (!in_array($sortBalance, ['asc', 'desc'], true)) {
    $sortBalance = '';
}

// بناء استعلام SQL
$sql = "SELECT c.*, u.full_name as created_by_name, r.name as region_name
        FROM local_customers c
        LEFT JOIN users u ON c.created_by = u.id
        LEFT JOIN regions r ON c.region_id = r.id
        WHERE 1=1";

$countSql = "SELECT COUNT(*) as total FROM local_customers WHERE 1=1";
$params = [];
$countParams = [];

if ($debtStatus === 'debtor') {
    $sql .= " AND (c.balance IS NOT NULL AND c.balance > 0)";
    $countSql .= " AND (balance IS NOT NULL AND balance > 0)";
} elseif ($debtStatus === 'clear') {
    $sql .= " AND (c.balance IS NULL OR c.balance <= 0)";
    $countSql .= " AND (balance IS NULL OR balance <= 0)";
}

if ($search) {
    $sql .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.address LIKE ? OR r.name LIKE ? OR c.id LIKE ?
        OR EXISTS (SELECT 1 FROM local_customer_phones lcp WHERE lcp.customer_id = c.id AND lcp.phone LIKE ?)
        OR u.full_name LIKE ?)";
    $countSql .= " AND (name LIKE ? OR phone LIKE ? OR address LIKE ? OR region_id IN (SELECT id FROM regions WHERE name LIKE ?) OR id LIKE ?
        OR EXISTS (SELECT 1 FROM local_customer_phones lcp WHERE lcp.customer_id = local_customers.id AND lcp.phone LIKE ?)
        OR created_by IN (SELECT id FROM users WHERE full_name LIKE ?))";
    $searchParam = '%' . $search . '%';
    for ($i = 0; $i < 7; $i++) { $params[] = $searchParam; }
    for ($i = 0; $i < 7; $i++) { $countParams[] = $searchParam; }
}

if ($regionFilter !== null) {
    $sql .= " AND c.region_id = ?";
    $countSql .= " AND region_id = ?";
    $params[] = $regionFilter;
    $countParams[] = $regionFilter;
}

if ($balanceFrom !== null) {
    $sql .= " AND COALESCE(c.balance, 0) >= ?";
    $countSql .= " AND COALESCE(balance, 0) >= ?";
    $params[] = $balanceFrom;
    $countParams[] = $balanceFrom;
}
if ($balanceTo !== null) {
    $sql .= " AND COALESCE(c.balance, 0) <= ?";
    $countSql .= " AND COALESCE(balance, 0) <= ?";
    $params[] = $balanceTo;
    $countParams[] = $balanceTo;
}

try {
    $totalResult = $db->queryOne($countSql, $countParams);
    $totalCustomers = $totalResult['total'] ?? 0;
    $totalPages = ceil($totalCustomers / $perPage);

    if ($sortBalance === 'asc') {
        $sql .= " ORDER BY COALESCE(c.balance, 0) ASC, c.name ASC LIMIT ? OFFSET ?";
    } elseif ($sortBalance === 'desc') {
        $sql .= " ORDER BY COALESCE(c.balance, 0) DESC, c.name ASC LIMIT ? OFFSET ?";
    } else {
        $sql .= " ORDER BY c.name ASC LIMIT ? OFFSET ?";
    }
    $params[] = $perPage;
    $params[] = $offset;

    $customers = $db->query($sql, $params);

    // جلب أرقام الهواتف
    $customerPhonesMap = [];
    if (!empty($customers)) {
        $customerIds = array_column($customers, 'id');
        if (!empty($customerIds)) {
            $placeholders = implode(',', array_fill(0, count($customerIds), '?'));
            $allPhones = $db->query(
                "SELECT customer_id, phone, is_primary
                 FROM local_customer_phones
                 WHERE customer_id IN ($placeholders)
                 ORDER BY customer_id, is_primary DESC, id ASC",
                $customerIds
            );
            foreach ($allPhones as $phoneRow) {
                $customerId = (int)$phoneRow['customer_id'];
                if (!isset($customerPhonesMap[$customerId])) {
                    $customerPhonesMap[$customerId] = [];
                }
                $customerPhonesMap[$customerId][] = $phoneRow['phone'];
            }
        }
    }

    // بناء HTML للجدول
    ob_start();
    if (empty($customers)): ?>
        <tr>
            <td colspan="6" class="text-center text-muted">لا توجد عملاء محليين</td>
        </tr>
    <?php else: ?>
        <?php foreach ($customers as $customer): ?>
            <tr>
                <td><strong><?php echo (int)$customer['id']; ?></strong></td>
                <td>
                    <strong><?php echo htmlspecialchars($customer['name']); ?></strong>
                    <?php
                    $balanceUpdatedAt = isset($customer['balance_updated_at']) ? trim($customer['balance_updated_at']) : '';
                    if (!empty($balanceUpdatedAt) && function_exists('formatDateTime')): ?>
                        <span class="badge bg-info-subtle text-info mb-1 d-inline-block" style="font-size: 0.7rem;" title="آخر تعديل للرصيد">
                            <i class="bi bi-clock-history me-1"></i><?php echo formatDateTime($balanceUpdatedAt, 'd/m g:i A'); ?>
                        </span>
                    <?php elseif (!empty($balanceUpdatedAt)): ?>
                        <span class="badge bg-info-subtle text-info mb-1 d-inline-block" style="font-size: 0.7rem;" title="آخر تعديل للرصيد">
                            <i class="bi bi-clock-history me-1"></i><?php echo date('d/m g:i A', strtotime($balanceUpdatedAt)); ?>
                        </span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php
                        $customerBalanceValue = isset($customer['balance']) ? (float) $customer['balance'] : 0.0;
                        $balanceBadgeClass = $customerBalanceValue > 0
                            ? 'bg-warning-subtle text-warning'
                            : ($customerBalanceValue < 0 ? 'bg-info-subtle text-info' : 'bg-secondary-subtle text-secondary');
                        $displayBalanceValue = $customerBalanceValue < 0 ? abs($customerBalanceValue) : $customerBalanceValue;
                    ?>
                    <strong><?php echo formatCurrency($displayBalanceValue); ?></strong>
                    <?php if ($customerBalanceValue !== 0.0): ?>
                        <span class="badge <?php echo $balanceBadgeClass; ?> ms-1">
                            <?php echo $customerBalanceValue > 0 ? 'رصيد مدين' : 'رصيد دائن'; ?>
                        </span>
                    <?php endif; ?>
                </td>
                <td><?php echo htmlspecialchars($customer['address'] ?? '-'); ?></td>
                <td><?php echo htmlspecialchars($customer['region_name'] ?? '-'); ?></td>
                <td>
                    <?php
                    $customerBalance = isset($customer['balance']) ? (float)$customer['balance'] : 0.0;
                    $displayBalanceForButton = $customerBalance < 0 ? abs($customerBalance) : $customerBalance;
                    $formattedBalance = formatCurrency($displayBalanceForButton);
                    $rawBalance = number_format($customerBalance, 2, '.', '');
                    $custId = (int)$customer['id'];
                    $custName = htmlspecialchars($customer['name']);
                    $custPhone = htmlspecialchars($customer['phone'] ?? '');
                    $custAddress = htmlspecialchars($customer['address'] ?? '');
                    $rowPhones = $customerPhonesMap[$custId] ?? [];
                    if (empty($rowPhones) && !empty($customer['phone'])) {
                        $rowPhones = [$customer['phone']];
                    }
                    $rowPhonesJson = htmlspecialchars(json_encode(array_values(array_filter(array_map('trim', $rowPhones)))), ENT_QUOTES, 'UTF-8');
                    $hasLocation = isset($customer['latitude'], $customer['longitude']) &&
                        $customer['latitude'] !== null &&
                        $customer['longitude'] !== null;
                    $latValue = $hasLocation ? (float)$customer['latitude'] : null;
                    $lngValue = $hasLocation ? (float)$customer['longitude'] : null;
                    ?>
                    <div class="dropdown table-actions-dropdown" data-bs-boundary="viewport">
                        <button type="button" class="btn btn-sm btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-gear me-1"></i>إجراءات
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                                <button type="button" class="dropdown-item location-capture-btn" data-customer-id="<?php echo $custId; ?>" data-customer-name="<?php echo $custName; ?>">
                                    <i class="bi bi-geo-alt me-2"></i>تحديد الموقع
                                </button>
                            </li>
                            <?php if ($hasLocation): ?>
                            <li>
                                <button type="button" class="dropdown-item location-view-btn" data-customer-id="<?php echo $custId; ?>" data-customer-name="<?php echo $custName; ?>" data-latitude="<?php echo htmlspecialchars(number_format($latValue, 8, '.', '')); ?>" data-longitude="<?php echo htmlspecialchars(number_format($lngValue, 8, '.', '')); ?>">
                                    <i class="bi bi-map me-2"></i>عرض الموقع
                                </button>
                            </li>
                            <?php endif; ?>
                            <li>
                                <button type="button" class="dropdown-item local-customer-phone-btn" onclick="showLocalCustomerPhoneCard(this)" data-customer-name="<?php echo $custName; ?>" data-customer-phones="<?php echo $rowPhonesJson; ?>">
                                    <i class="bi bi-telephone me-2"></i>الهاتف
                                </button>
                            </li>
                            <?php if (in_array($currentRole, ['manager', 'developer', 'accountant', 'sales'], true)): ?>
                            <li>
                                <button type="button" class="dropdown-item" onclick="showEditLocalCustomerModal(this)" data-customer-id="<?php echo $custId; ?>" data-customer-name="<?php echo $custName; ?>" data-customer-phone="<?php echo $custPhone; ?>" data-customer-address="<?php echo $custAddress; ?>" data-customer-region-id="<?php echo (int)($customer['region_id'] ?? 0); ?>" data-customer-balance="<?php echo $rawBalance; ?>">
                                    <i class="bi bi-pencil me-2"></i>تعديل
                                </button>
                            </li>
                            <?php endif; ?>
                            <li>
                                <button type="button" class="dropdown-item" onclick="showCollectPaymentModal(this)" data-customer-id="<?php echo $custId; ?>" data-customer-name="<?php echo $custName; ?>" data-customer-balance="<?php echo $rawBalance; ?>" data-customer-balance-formatted="<?php echo htmlspecialchars($formattedBalance); ?>">
                                    <i class="bi bi-cash-coin me-2"></i>تحصيل
                                </button>
                            </li>
                            <li>
                                <button type="button" class="dropdown-item local-customer-purchase-history-btn" onclick="showLocalCustomerPurchaseHistoryModal(this)" data-customer-id="<?php echo $custId; ?>" data-customer-name="<?php echo $custName; ?>" data-customer-phone="<?php echo $custPhone; ?>" data-customer-address="<?php echo $custAddress; ?>" data-customer-balance="<?php echo $rawBalance; ?>" data-customer-balance-formatted="<?php echo htmlspecialchars($formattedBalance); ?>">
                                    <i class="bi bi-receipt me-2"></i>سجل المشتريات
                                </button>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <button type="button" class="dropdown-item" onclick="showPaperInvoiceModal(this)" data-customer-id="<?php echo $custId; ?>" data-customer-name="<?php echo $custName; ?>">
                                    <i class="bi bi-receipt-cutoff me-2"></i>فاتورة ورقية
                                </button>
                            </li>
                            <?php if ($currentRole === 'manager'): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <button type="button" class="dropdown-item text-danger" onclick="showDeleteLocalCustomerModal(this)" data-customer-id="<?php echo $custId; ?>" data-customer-name="<?php echo $custName; ?>">
                                    <i class="bi bi-trash3 me-2"></i>حذف
                                </button>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php endif;
    $tableRows = ob_get_clean();

    // بناء HTML للـ Pagination
    ob_start();
    if ($totalPages > 1): ?>
    <ul class="pagination justify-content-center">
        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
            <a class="page-link" href="javascript:void(0);" onclick="loadLocalCustomers(<?php echo $page - 1; ?>)">
                <i class="bi bi-chevron-right"></i>
            </a>
        </li>
        <?php
        $startPage = max(1, $page - 2);
        $endPage = min($totalPages, $page + 2);
        if ($startPage > 1): ?>
            <li class="page-item"><a class="page-link" href="javascript:void(0);" onclick="loadLocalCustomers(1)">1</a></li>
            <?php if ($startPage > 2): ?>
                <li class="page-item disabled"><span class="page-link">...</span></li>
            <?php endif; ?>
        <?php endif; ?>
        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                <a class="page-link" href="javascript:void(0);" onclick="loadLocalCustomers(<?php echo $i; ?>)"><?php echo $i; ?></a>
            </li>
        <?php endfor; ?>
        <?php if ($endPage < $totalPages): ?>
            <?php if ($endPage < $totalPages - 1): ?>
                <li class="page-item disabled"><span class="page-link">...</span></li>
            <?php endif; ?>
            <li class="page-item"><a class="page-link" href="javascript:void(0);" onclick="loadLocalCustomers(<?php echo $totalPages; ?>)"><?php echo $totalPages; ?></a></li>
        <?php endif; ?>
        <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
            <a class="page-link" href="javascript:void(0);" onclick="loadLocalCustomers(<?php echo $page + 1; ?>)">
                <i class="bi bi-chevron-left"></i>
            </a>
        </li>
    </ul>
    <?php endif;
    $paginationHtml = ob_get_clean();

    echo json_encode([
        'success' => true,
        'tableRows' => $tableRows,
        'pagination' => $paginationHtml,
        'totalCustomers' => $totalCustomers,
        'currentPage' => $page,
        'totalPages' => $totalPages
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("Search Local Customers Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'حدث خطأ في البحث'], JSON_UNESCAPED_UNICODE);
}
