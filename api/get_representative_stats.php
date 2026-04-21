<?php
/**
 * API: جلب إحصائيات المندوب (الإجمالي والشهري)
 */

define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/path_helper.php';

header('Content-Type: application/json; charset=utf-8');

// التحقق من تسجيل الدخول
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'يجب تسجيل الدخول'], JSON_UNESCAPED_UNICODE);
    exit;
}

// التحقق من الصلاحيات
$currentUser = getCurrentUser();
if (!in_array($currentUser['role'], ['manager', 'developer', 'accountant'])) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح لك'], JSON_UNESCAPED_UNICODE);
    exit;
}

$repId = isset($_GET['rep_id']) ? (int)$_GET['rep_id'] : 0;
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');

if ($repId <= 0) {
    echo json_encode(['success' => false, 'message' => 'المندوب غير محدد'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = db();
    
    // 1. جلب بيانات المندوب الأساسية
    $repData = $db->queryOne("SELECT id, full_name, username, status FROM users WHERE id = ?", [$repId]);
    if (!$repData) {
        echo json_encode(['success' => false, 'message' => 'المندوب غير موجود'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2. إحصائيات العملاء الإجمالية (الحالية)
    $customerStats = $db->queryOne(
        "SELECT 
            COUNT(*) AS total_customers,
            COALESCE(SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END), 0) AS total_debt,
            COALESCE(SUM(CASE WHEN balance > 0 THEN 1 ELSE 0 END), 0) AS debtor_count
        FROM customers
        WHERE rep_id = ? OR created_by = ?",
        [$repId, $repId]
    );

    // 3. إجمالي التحصيلات (كل الأوقات)
    $hasCollectionsStatus = !empty($db->queryOne("SHOW COLUMNS FROM collections LIKE 'status'"));
    $totalCollectionsSql = "SELECT COALESCE(SUM(amount), 0) AS total FROM collections WHERE collected_by = ?";
    if ($hasCollectionsStatus) {
        $totalCollectionsSql .= " AND status IN ('pending', 'approved')";
    }
    $totalCollections = (float)($db->queryOne($totalCollectionsSql, [$repId])['total'] ?? 0);

    // 4. إجمالي المرتجعات (كل الأوقات)
    $hasReturnsTable = !empty($db->queryOne("SHOW TABLES LIKE 'returns'"));
    $totalReturns = 0.0;
    if ($hasReturnsTable) {
        $totalReturnsSql = "SELECT COALESCE(SUM(refund_amount), 0) AS total FROM returns WHERE sales_rep_id = ? AND status IN ('approved', 'processed', 'completed')";
        $totalReturns = (float)($db->queryOne($totalReturnsSql, [$repId])['total'] ?? 0);
    }

    // 5. رصيد المحفظة
    $walletBalance = 0.0;
    $hasWalletTable = !empty($db->queryOne("SHOW TABLES LIKE 'user_wallet_transactions'"));
    if ($hasWalletTable) {
        $credits = $db->queryOne("SELECT COALESCE(SUM(amount), 0) AS total FROM user_wallet_transactions WHERE user_id = ? AND type IN ('deposit', 'custody_add')", [$repId]);
        $debits = $db->queryOne("SELECT COALESCE(SUM(amount), 0) AS total FROM user_wallet_transactions WHERE user_id = ? AND type IN ('withdrawal', 'custody_retrieve')", [$repId]);
        $walletBalance = (float)($credits['total'] ?? 0) - (float)($debits['total'] ?? 0);
    }

    // 6. الإحصائيات الشهرية
    $monthStart = sprintf("%04d-%02d-01 00:00:00", $year, $month);
    $monthEnd = date("Y-m-t 23:59:59", strtotime($monthStart));

    // تحصيلات الشهر
    $monthlyCollectionsSql = "SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) as count FROM collections WHERE collected_by = ? AND created_at BETWEEN ? AND ?";
    if ($hasCollectionsStatus) {
        $monthlyCollectionsSql .= " AND status IN ('pending', 'approved')";
    }
    $monthlyCollectionsRows = $db->queryOne($monthlyCollectionsSql, [$repId, $monthStart, $monthEnd]);
    $monthlyCollections = (float)($monthlyCollectionsRows['total'] ?? 0);
    $monthlyCollectionsCount = (int)($monthlyCollectionsRows['count'] ?? 0);

    // مرتجعات الشهر
    $monthlyReturns = 0.0;
    $monthlyReturnsCount = 0;
    if ($hasReturnsTable) {
        $monthlyReturnsRows = $db->queryOne(
            "SELECT COALESCE(SUM(refund_amount), 0) AS total, COUNT(*) as count FROM returns WHERE sales_rep_id = ? AND status IN ('approved', 'processed', 'completed') AND created_at BETWEEN ? AND ?",
            [$repId, $monthStart, $monthEnd]
        );
        $monthlyReturns = (float)($monthlyReturnsRows['total'] ?? 0);
        $monthlyReturnsCount = (int)($monthlyReturnsRows['count'] ?? 0);
    }

    // عملاء جدد في الشهر
    $newCustomersCount = (int)($db->queryOne(
        "SELECT COUNT(*) AS count FROM customers WHERE (rep_id = ? OR created_by = ?) AND created_at BETWEEN ? AND ?",
        [$repId, $repId, $monthStart, $monthEnd]
    )['count'] ?? 0);

    echo json_encode([
        'success' => true,
        'rep_name' => $repData['full_name'] ?? $repData['username'],
        'overall' => [
            'total_customers' => (int)$customerStats['total_customers'],
            'total_debt' => (float)$customerStats['total_debt'],
            'debtor_count' => (int)$customerStats['debtor_count'],
            'total_collections' => $totalCollections,
            'total_returns' => $totalReturns,
            'wallet_balance' => $walletBalance
        ],
        'monthly' => [
            'month' => $month,
            'year' => $year,
            'month_name' => getArabicMonthName($month),
            'collections' => $monthlyCollections,
            'collections_count' => $monthlyCollectionsCount,
            'returns' => $monthlyReturns,
            'returns_count' => $monthlyReturnsCount,
            'new_customers' => $newCustomersCount
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

function getArabicMonthName($month) {
    $months = [
        1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل',
        5 => 'مايو', 6 => 'يونيو', 7 => 'يوليو', 8 => 'أغسطس',
        9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر'
    ];
    return $months[(int)$month] ?? '';
}
