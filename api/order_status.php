<?php
/**
 * Public Order Status API — حالة الطلب بدون مصادقة
 *
 * GET /api/order_status.php?order_id=123
 *
 * الحقول في الـ Request:
 *   order_id  (int, مطلوب) — رقم الطلب
 *
 * استجابة النجاح:
 * {
 *   "success": true,
 *   "order": {
 *     "id": 123,
 *     "title": "أوردر محل - أحمد",
 *     "type": "shop_order",
 *     "type_label": "اوردر محل",
 *     "status": "in_progress",
 *     "status_label": "قيد التنفيذ",
 *     "customer_name": "أحمد محمد",
 *     "customer_phone": "01012345678",
 *     "created_at": "2025-04-27 10:30:00",
 *     "due_date": "2025-04-28",
 *     "total_amount": 250.00,
 *     "tg_shipment_code": "TG-001234"
 *   }
 * }
 *
 * استجابة الفشل:
 *   {"success": false, "error": "رسالة الخطأ"}
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

while (ob_get_level() > 0) {
    @ob_end_clean();
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ─── دالة الرد ────────────────────────────────────────────────────────
function respond(bool $success, array $data = [], int $code = 200): void
{
    http_response_code($code);
    echo json_encode(array_merge(['success' => $success], $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ─── قراءة المعامل ────────────────────────────────────────────────────
$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;

if ($orderId <= 0) {
    respond(false, ['error' => 'رقم الطلب مطلوب ويجب أن يكون رقماً صحيحاً أكبر من صفر'], 422);
}

// ─── تسميات الحالات والأنواع ──────────────────────────────────────────
$statusLabels = [
    'pending'               => 'معلقة',
    'received'              => 'مستلمة',
    'in_progress'           => 'قيد التنفيذ',
    'completed'             => 'مكتملة',
    'with_delegate'         => 'مع المندوب',
    'with_driver'           => 'مع السائق',
    'with_shipping_company' => 'مع شركة الشحن',
    'delivered'             => 'تم التوصيل',
    'returned'              => 'تم الارجاع',
    'cancelled'             => 'ملغاة',
];

$typeLabels = [
    'shop_order'       => 'اوردر محل',
    'cash_customer'    => 'عميل نقدي',
    'telegraph'        => 'تليجراف',
    'shipping_company' => 'شركة شحن',
    'general'          => 'مهمة عامة',
    'production'       => 'إنتاج منتج',
    'quality'          => 'مهمة جودة',
    'maintenance'      => 'صيانة',
];

// ─── جلب بيانات الطلب ────────────────────────────────────────────────
try {
    $db = Database::getInstance();

    $task = $db->queryOne(
        "SELECT id, title, status, task_type, related_type,
                customer_name, customer_phone,
                created_at, due_date,
                total_amount, notes
         FROM tasks
         WHERE id = ?
         LIMIT 1",
        [$orderId]
    );

    if (!$task) {
        respond(false, ['error' => 'لم يتم العثور على طلب بهذا الرقم'], 404);
    }

    // تحديد نوع الطلب الفعلي (related_type يتفوق على task_type)
    $taskType = $task['task_type'] ?? 'general';
    $relatedType = $task['related_type'] ?? '';
    if (strpos($relatedType, 'manager_') === 0) {
        $taskType = substr($relatedType, 8);
    }

    // استخراج رقم الشحنة من الملاحظات
    $tgCode = null;
    if (!empty($task['notes']) && preg_match('/\[TG_CODE\]:\s*([^\n]+)/', $task['notes'], $m)) {
        $tgCode = trim($m[1]);
    }

    $status = $task['status'] ?? 'pending';

    respond(true, [
        'order' => [
            'id'               => (int)$task['id'],
            'title'            => $task['title'] ?? '',
            'type'             => $taskType,
            'type_label'       => $typeLabels[$taskType] ?? $taskType,
            'status'           => $status,
            'status_label'     => $statusLabels[$status] ?? $status,
            'customer_name'    => $task['customer_name'] ?? '',
            'customer_phone'   => $task['customer_phone'] ?? '',
            'created_at'       => $task['created_at'] ?? '',
            'due_date'         => $task['due_date'] ?? null,
            'total_amount'     => $task['total_amount'] !== null ? (float)$task['total_amount'] : null,
            'tg_shipment_code' => $tgCode,
        ],
    ]);

} catch (Throwable $e) {
    error_log('order_status API error: ' . $e->getMessage());
    respond(false, ['error' => 'حدث خطأ أثناء جلب بيانات الطلب'], 500);
}
