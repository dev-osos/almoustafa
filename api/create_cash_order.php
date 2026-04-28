<?php
/**
 * Public Cash Customer Order API — إنشاء أوردر "عميل نقدي" بدون مصادقة
 *
 * POST /api/create_cash_order.php
 * Body: JSON أو application/x-www-form-urlencoded أو multipart/form-data
 *
 * ─── المدخلات ────────────────────────────────────────────────────────
 *
 * بيانات العميل:
 *   customer_name    (string)               — اسم العميل
 *   customer_phone   (string)               — رقم الهاتف
 *
 * تفاصيل الطلب:
 *   order_title      (string)               — عنوان / عنوان التوصيل
 *   due_date         (string YYYY-MM-DD)    — تاريخ التسليم المتوقع
 *   priority         (string)               — low | normal | high | urgent  (افتراضي: normal)
 *   notes            (string)               — ملاحظات إضافية  ← الاسم المفضّل
 *   details          (string)               — نفس notes (بديل للتوافق)
 *
 * المبالغ:
 *   shipping_fees    (float)                — رسوم الشحن
 *   discount         (float)                — الخصم (يُطرح من الإجمالي)
 *   advance_payment  (float)                — المبلغ المدفوع مقدماً (يُعرض في الإيصال)
 *
 * المنتجات (مصفوفة — يجب إضافة منتج واحد على الأقل):
 *   products[0][name]        (string, مطلوب) — اسم المنتج
 *   products[0][quantity]    (float)          — الكمية
 *   products[0][unit]        (string)         — قطعة|كرتونة|عبوة|شرينك|دسته|جرام|كيلو
 *   products[0][price]       (float)          — سعر الوحدة
 *   products[0][line_total]  (float)          — إجمالي السطر (الكمية × السعر)
 *   products[0][category]    (string)         — التصنيف
 *   products[0][item_type]   (string)         — نوع العنصر
 *
 * العمال (اختياري):
 *   assigned_to[]    (int[])                — معرّفات عمال الإنتاج
 *
 * ─── الاستجابات ──────────────────────────────────────────────────────
 *
 * نجاح (201):
 *   {
 *     "success": true,
 *     "order_id": 47,
 *     "order_number": "ORD-47",
 *     "message": "تم إنشاء الأوردر بنجاح"
 *   }
 *
 * فشل (4xx/5xx):
 *   { "success": false, "error": "رسالة الخطأ" }
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/path_helper.php';

while (ob_get_level() > 0) {
    @ob_end_clean();
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ─── دالة الرد ────────────────────────────────────────────────────────
function respond(bool $success, array $data = [], int $code = 200): void
{
    http_response_code($code);
    echo json_encode(
        array_merge(['success' => $success], $data),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, ['error' => 'يجب إرسال طلب POST'], 405);
}

// ─── قبول JSON أو form-data ───────────────────────────────────────────
$input = $_POST;
$ct = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($ct, 'application/json') !== false) {
    $decoded = json_decode(file_get_contents('php://input'), true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}

// ─── ثوابت ────────────────────────────────────────────────────────────
$taskType        = 'cash_customer';
$allowedPriorities = ['low', 'normal', 'high', 'urgent'];
$allowedUnits      = ['قطعة', 'كرتونة', 'عبوة', 'شرينك', 'دسته', 'جرام', 'كيلو'];
$integerUnits      = ['كيلو', 'قطعة', 'جرام', 'دسته'];

// ─── استخراج الحقول ───────────────────────────────────────────────────
$customerName  = trim((string)($input['customer_name']  ?? ''));
$customerPhone = trim((string)($input['customer_phone'] ?? ''));
$orderTitle    = trim((string)($input['order_title']    ?? ''));
// notes و details مترادفان — notes له الأولوية
$details       = trim((string)($input['notes'] ?? $input['details'] ?? ''));
$dueDate       = trim((string)($input['due_date']       ?? ''));

$priority = $input['priority'] ?? 'normal';
if (!in_array($priority, $allowedPriorities, true)) $priority = 'normal';

if ($dueDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
    respond(false, ['error' => 'due_date يجب أن يكون بصيغة YYYY-MM-DD مثال: 2025-05-01'], 422);
}

$shippingFees   = isset($input['shipping_fees'])   && $input['shipping_fees']   !== '' ? max(0.0, (float)str_replace(',', '.', (string)$input['shipping_fees']))   : 0.0;
$discount       = isset($input['discount'])        && $input['discount']        !== '' ? max(0.0, (float)str_replace(',', '.', (string)$input['discount']))        : 0.0;
$advancePayment = isset($input['advance_payment']) && $input['advance_payment'] !== '' ? max(0.0, (float)str_replace(',', '.', (string)$input['advance_payment'])) : 0.0;

// ─── المنتجات ─────────────────────────────────────────────────────────
$products = [];
$error    = '';

$rawProducts = $input['products'] ?? [];
if (!is_array($rawProducts)) {
    respond(false, ['error' => 'products يجب أن يكون مصفوفة'], 422);
}

if (empty($rawProducts)) {
    respond(false, ['error' => 'يجب إضافة منتج واحد على الأقل في products'], 422);
}

foreach ($rawProducts as $idx => $productData) {
    if (!is_array($productData)) {
        $error = "المنتج [{$idx}]: البيانات يجب أن تكون object/array";
        break;
    }

    $pName = trim((string)($productData['name'] ?? ''));
    if ($pName === '') continue; // تخطي الإدخالات الفارغة

    $pUnit = trim((string)($productData['unit'] ?? 'قطعة'));
    if (!in_array($pUnit, $allowedUnits, true)) $pUnit = 'قطعة';

    $mustBeInt = in_array($pUnit, $integerUnits, true);
    $pQuantity = null;
    $qInput    = trim((string)($productData['quantity'] ?? ''));

    if ($qInput !== '') {
        $norm = str_replace(',', '.', $qInput);
        if (!is_numeric($norm)) {
            $error = "المنتج [{$idx}] ({$pName}): الكمية يجب أن تكون رقماً";
            break;
        }
        $pQuantity = (float)$norm;
        if ($pQuantity < 0) {
            $error = "المنتج [{$idx}] ({$pName}): الكمية لا يمكن أن تكون سالبة";
            break;
        }
        if ($mustBeInt && $pQuantity != (int)$pQuantity) {
            $error = "المنتج [{$idx}] ({$pName}): الكمية يجب أن تكون عدداً صحيحاً للوحدة \"{$pUnit}\"";
            break;
        }
        if ($mustBeInt) $pQuantity = (int)$pQuantity;
        if ($pQuantity <= 0) $pQuantity = null;
    }

    $pPrice = null;
    $priceIn = trim((string)($productData['price'] ?? ''));
    if ($priceIn !== '' && is_numeric(str_replace(',', '.', $priceIn))) {
        $pPrice = max(0.0, (float)str_replace(',', '.', $priceIn));
    }

    $pLineTotal = null;
    $ltIn = trim((string)($productData['line_total'] ?? ''));
    if ($ltIn !== '' && is_numeric(str_replace(',', '.', $ltIn))) {
        $pLineTotal = max(0.0, (float)str_replace(',', '.', $ltIn));
    }

    $products[] = [
        'name'               => $pName,
        'quantity'           => $pQuantity,
        'unit'               => $pUnit,
        'category'           => trim((string)($productData['category'] ?? '')) ?: null,
        'effective_quantity' => $pQuantity,
        'price'              => $pPrice,
        'line_total'         => $pLineTotal,
        'item_type'          => trim((string)($productData['item_type'] ?? '')),
    ];
}

if ($error !== '') {
    respond(false, ['error' => $error], 422);
}

if (empty($products)) {
    respond(false, ['error' => 'لم يتم إدخال أي منتج صالح (تحقق من حقل name في كل منتج)'], 422);
}

// ─── قاعدة البيانات ───────────────────────────────────────────────────
try {
    $db = Database::getInstance();

    // المستخدم الافتراضي: أول مدير نشط
    $apiUser = $db->queryOne(
        "SELECT id FROM users WHERE role = 'manager' AND status = 'active' ORDER BY id ASC LIMIT 1"
    );
    if (!$apiUser) {
        respond(false, ['error' => 'لا يوجد مستخدم نشط في النظام'], 500);
    }
    $creatorId = (int)$apiUser['id'];

    // العمال المخصصون (اختياري)
    $assignees = $input['assigned_to'] ?? [];
    if (!is_array($assignees)) $assignees = [$assignees];
    $assignees = array_values(array_unique(array_filter(array_map('intval', $assignees))));
    if (!empty($assignees)) {
        $ph    = implode(',', array_fill(0, count($assignees), '?'));
        $valid = $db->query("SELECT id FROM users WHERE id IN ({$ph}) AND status = 'active'", $assignees);
        $assignees = array_values(array_intersect($assignees, array_map('intval', array_column($valid, 'id'))));
    }

    // ─── بناء notes ──────────────────────────────────────────────────
    $notesParts = [];
    if ($orderTitle !== '') $notesParts[] = 'عنوان  :' . $orderTitle;
    if ($details !== '')    $notesParts[] = $details;

    $notesParts[] = '[PRODUCTS_JSON]:' . json_encode($products, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $lines = [];
    foreach ($products as $p) {
        $line = 'المنتج: ' . $p['name'];
        if ($p['quantity'] !== null) $line .= ' - الكمية: ' . $p['quantity'];
        $lines[] = $line;
    }
    $notesParts[] = implode("\n", $lines);

    if (!empty($assignees)) {
        $workerRows  = $db->query(
            "SELECT id, full_name FROM users WHERE id IN (" . implode(',', array_fill(0, count($assignees), '?')) . ")",
            $assignees
        );
        $workerNames = array_column($workerRows, 'full_name');
        $label       = count($assignees) > 1 ? 'العمال المخصصون' : 'العامل المخصص';
        $notesParts[] = $label . ': ' . implode(', ', $workerNames)
            . "\n[ASSIGNED_WORKERS_IDS]:" . implode(',', $assignees);
    }

    if ($shippingFees > 0)   $notesParts[] = 'رسوم الشحن :' . $shippingFees;
    if ($discount > 0)       $notesParts[] = 'الخصم :' . $discount;
    if ($advancePayment > 0) $notesParts[] = '[ADVANCE_PAYMENT]:' . $advancePayment;

    $notesValue = implode("\n\n", $notesParts);

    // ─── template_id / product_id من اسم المنتج الأول ────────────────
    $templateId  = null;
    $productId   = null;
    $productName = $products[0]['name'];

    try {
        $tmpl = $db->queryOne(
            "SELECT id FROM unified_product_templates WHERE product_name = ? AND status = 'active' LIMIT 1",
            [$productName]
        );
        if ($tmpl) {
            $templateId = (int)$tmpl['id'];
        } else {
            $tmpl = $db->queryOne(
                "SELECT id FROM product_templates WHERE product_name = ? AND status = 'active' LIMIT 1",
                [$productName]
            );
            if ($tmpl) $templateId = (int)$tmpl['id'];
        }
    } catch (Exception $e) {}

    if (!$templateId) {
        try {
            $prod = $db->queryOne(
                "SELECT id FROM products WHERE name = ? AND status = 'active' LIMIT 1",
                [$productName]
            );
            if ($prod) $productId = (int)$prod['id'];
        } catch (Exception $e) {}
    }

    // ─── إجمالي الكمية والوحدة ───────────────────────────────────────
    $firstUnit     = $products[0]['unit'] ?? 'قطعة';
    $totalQuantity = null;
    $sumQty        = 0.0;
    foreach ($products as $p) {
        if ($p['quantity'] !== null) $sumQty += $p['quantity'];
    }
    if ($sumQty > 0) $totalQuantity = $sumQty;

    // ─── حساب total_amount ───────────────────────────────────────────
    $subtotal = 0.0;
    foreach ($products as $p) {
        if ($p['line_total'] !== null) {
            $subtotal += $p['line_total'];
        } elseif ($p['quantity'] !== null && $p['price'] !== null) {
            $subtotal += round($p['quantity'] * $p['price'], 2);
        }
    }
    $totalAmount = max(0.0, $subtotal + $shippingFees - $discount);

    // ─── INSERT ──────────────────────────────────────────────────────
    $title            = 'عميل نقدي' . ($customerName !== '' ? ' - ' . $customerName : '');
    $relatedTypeValue = 'manager_cash_customer';

    $cols = ['title', 'description', 'created_by', 'priority', 'status', 'related_type', 'task_type', 'notes', 'template_id', 'product_name'];
    $vals = [$title, $details ?: null, $creatorId, $priority, 'pending', $relatedTypeValue, $taskType, $notesValue, $templateId, $productName];
    $ph   = array_fill(0, count($cols), '?');

    if (!empty($assignees)) {
        $cols[] = 'assigned_to'; $vals[] = (int)$assignees[0]; $ph[] = '?';
    }
    if ($dueDate !== '') {
        $cols[] = 'due_date'; $vals[] = $dueDate; $ph[] = '?';
    }
    if ($productId !== null) {
        $cols[] = 'product_id'; $vals[] = $productId; $ph[] = '?';
    }
    if ($customerName !== '') {
        $cols[] = 'customer_name'; $vals[] = $customerName; $ph[] = '?';
    }
    if ($customerPhone !== '') {
        $cols[] = 'customer_phone'; $vals[] = $customerPhone; $ph[] = '?';
    }
    if ($totalQuantity !== null) {
        $cols[] = 'quantity'; $vals[] = $totalQuantity; $ph[] = '?';
        $cols[] = 'unit';     $vals[] = $firstUnit;      $ph[] = '?';
    }
    if ($totalAmount > 0) {
        $cols[] = 'total_amount'; $vals[] = $totalAmount; $ph[] = '?';
    }

    $result = $db->execute(
        "INSERT INTO tasks (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $ph) . ")",
        $vals
    );
    $taskId = (int)($result['insert_id'] ?? 0);

    if ($taskId <= 0) {
        respond(false, ['error' => 'تعذر إنشاء الأوردر في قاعدة البيانات'], 500);
    }

    // ─── Audit Log ───────────────────────────────────────────────────
    try {
        logAudit($creatorId, 'create_cash_order_api', 'tasks', $taskId, null, [
            'task_type'   => $taskType,
            'source'      => 'public_api',
            'customer'    => $customerName,
            'products_count' => count($products),
        ]);
    } catch (Exception $e) {}

    // ─── إشعارات عمال الإنتاج ────────────────────────────────────────
    $notifTitle = 'أوردر جديد';
    $notifBody  = "أوردر #{$taskId} — عميل نقدي" . ($customerName !== '' ? " — {$customerName}" : '');
    try {
        $productionUsers = $db->query(
            "SELECT id FROM users WHERE role = 'production' AND status = 'active'"
        );
        foreach ($productionUsers as $pu) {
            createNotification(
                (int)$pu['id'],
                $notifTitle,
                $notifBody,
                'info',
                getDashboardUrl('production') . '?page=tasks'
            );
        }
    } catch (Exception $e) {
        error_log('create_cash_order notification error: ' . $e->getMessage());
    }

    // ─── الرد النهائي ────────────────────────────────────────────────
    respond(true, [
        'order_id'     => $taskId,
        'order_number' => 'ORD-' . $taskId,
        'message'      => 'تم إنشاء الأوردر بنجاح',
    ], 201);

} catch (Throwable $e) {
    error_log('create_cash_order API error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    respond(false, ['error' => 'حدث خطأ داخلي في الخادم. يرجى المحاولة لاحقاً.'], 500);
}
