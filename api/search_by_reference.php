<?php
/**
 * API: البحث بالرقم المرجعي
 * يبحث في جداول التحصيلات والمعاملات المالية ويعيد رابط التوجيه المناسب
 */

@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
error_reporting(0);

while (ob_get_level() > 0) {
    @ob_end_clean();
}
ob_start();

define('ACCESS_ALLOWED', true);
define('IS_API_REQUEST', true);

function returnJsonResponse(array $data, int $status = 200): void
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, must-revalidate');
    }
    $json = @json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        $json = '{"success":false,"message":"خطأ في تنسيق البيانات"}';
    }
    echo $json;
    exit;
}

try {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/auth.php';

    if (!isLoggedIn()) {
        returnJsonResponse(['success' => false, 'message' => 'غير مصرح لك بالوصول'], 401);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        returnJsonResponse(['success' => false, 'message' => 'طريقة الطلب غير صحيحة'], 405);
    }

    $ref = trim($_GET['ref'] ?? '');
    if ($ref === '') {
        returnJsonResponse(['success' => false, 'message' => 'الرقم المرجعي مطلوب']);
    }

    $currentUser = getCurrentUser();
    $role = strtolower((string)($currentUser['role'] ?? ''));

    $db = db();

    // --- البحث في جدول التحصيلات ---
    $collection = $db->queryOne(
        "SELECT id FROM collections WHERE reference_number = ? LIMIT 1",
        [$ref]
    );

    if ($collection) {
        // تحديد الصفحة المناسبة حسب دور المستخدم
        if ($role === 'sales') {
            $redirectUrl = '/dashboard/sales.php?page=collections&search_reference=' . urlencode($ref);
        } else {
            $redirectUrl = '/dashboard/accountant.php?page=collections&search_reference=' . urlencode($ref);
        }
        returnJsonResponse([
            'success' => true,
            'found'   => true,
            'type'    => 'collection',
            'label'   => 'تحصيل',
            'redirect_url' => $redirectUrl,
        ]);
    }

    // --- البحث في جدول المعاملات المالية ---
    $transaction = $db->queryOne(
        "SELECT id FROM financial_transactions WHERE reference_number = ? LIMIT 1",
        [$ref]
    );

    if ($transaction) {
        $redirectUrl = '/dashboard/manager.php?page=company_cash&search_reference=' . urlencode($ref);
        returnJsonResponse([
            'success' => true,
            'found'   => true,
            'type'    => 'financial_transaction',
            'label'   => 'معاملة مالية',
            'redirect_url' => $redirectUrl,
        ]);
    }

    // لم يُعثر على شيء
    returnJsonResponse([
        'success' => true,
        'found'   => false,
        'message' => 'لم يتم العثور على أي معاملة بالرقم المرجعي: ' . htmlspecialchars($ref, ENT_QUOTES, 'UTF-8'),
    ]);

} catch (Throwable $e) {
    returnJsonResponse(['success' => false, 'message' => 'حدث خطأ أثناء البحث'], 500);
}
