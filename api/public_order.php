<?php
/**
 * Public Order API — إنشاء أوردر إنتاج بدون مصادقة جلسة
 * الحماية: API Key في header أو POST
 *
 * POST /api/public_order.php
 *
 * Headers (اختياري بديل عن الـ POST field):
 *   X-API-Key: <key>
 *
 * الحقول المشتركة لجميع الأنواع:
 *   api_key          (string, مطلوب)   — مفتاح الـ API
 *   task_type        (string, مطلوب)   — shop_order | cash_customer | telegraph | shipping_company
 *   customer_name    (string)
 *   customer_phone   (string)
 *   priority         (string)          — low | normal | high | urgent   (افتراضي: normal)
 *   due_date         (string)          — YYYY-MM-DD
 *   details          (string)          — ملاحظات إضافية
 *   order_title      (string)          — عنوان/عنوان الشحن
 *   discount         (float)
 *   advance_payment  (float)
 *   shipping_fees    (float)           — لأوردر المحل وعميل نقدي وشركة شحن
 *   assigned_to[]    (int[])           — IDs العمال المخصصين
 *
 * المنتجات (مصفوفة، يمكن تكرارها):
 *   products[0][name]       (string)
 *   products[0][quantity]   (float)
 *   products[0][unit]       (string)  — قطعة | كرتونة | عبوة | شرينك | دسته | جرام | كيلو
 *   products[0][price]      (float)
 *   products[0][line_total] (float)
 *   products[0][category]   (string)
 *   products[0][item_type]  (string)
 *
 * حقول Telegraph / شركة شحن إضافية:
 *   tg_governorate   (string)
 *   tg_gov_id        (int)
 *   tg_city          (string)
 *   tg_city_id       (int)
 *   tg_weight        (string)
 *   tg_pieces_count  (int)
 *   tg_parcel_desc   (string)
 *
 * استجابة النجاح:
 *   {"success":true,"task_id":123,"task_number":"ORD-123","message":"تم إنشاء الأوردر بنجاح"}
 *
 * استجابة الفشل:
 *   {"success":false,"error":"رسالة الخطأ"}
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/path_helper.php';

// تنظيف output buffer
while (ob_get_level() > 0) {
    @ob_end_clean();
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ─── دالة مساعدة للرد ───────────────────────────────────────────────
function respond(bool $success, array $data = [], int $code = 200): void
{
    http_response_code($code);
    echo json_encode(array_merge(['success' => $success], $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ─── التحقق من الـ API Key ───────────────────────────────────────────
// المفتاح مخزن كـ constant في config أو كـ env var
$validApiKey = defined('PUBLIC_ORDER_API_KEY')
    ? PUBLIC_ORDER_API_KEY
    : (getenv('PUBLIC_ORDER_API_KEY') ?: null);

if (!$validApiKey) {
    respond(false, ['error' => 'لم يتم تكوين مفتاح الـ API في الخادم. أضف PUBLIC_ORDER_API_KEY في config.php'], 500);
}

$suppliedKey = $_SERVER['HTTP_X_API_KEY']
    ?? ($_SERVER['REQUEST_METHOD'] === 'GET' ? ($_GET['api_key'] ?? '') : ($_POST['api_key'] ?? ''));
if (!hash_equals($validApiKey, $suppliedKey)) {
    respond(false, ['error' => 'مفتاح الـ API غير صحيح'], 401);
}

// ─── GET ?action=products — قائمة المنتجات النشطة ───────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = trim($_GET['action'] ?? '');
    if ($action !== 'products') {
        respond(false, ['error' => 'action غير معروف. المسموح: products'], 400);
    }

    try {
        $db       = Database::getInstance();
        $products = [];

        // 1. unified_product_templates
        try {
            $rows = $db->query(
                "SELECT id, product_name FROM unified_product_templates WHERE status = 'active' ORDER BY product_name ASC"
            );
            foreach ($rows as $r) {
                $products[] = ['id' => (int)$r['id'], 'name' => $r['product_name'], 'source' => 'unified_templates'];
            }
        } catch (Exception $e) { /* الجدول قد لا يكون موجوداً */ }

        // 2. product_templates (ما لم يكن موجوداً في unified)
        $existingNames = array_column($products, 'name');
        try {
            $rows = $db->query(
                "SELECT id, product_name FROM product_templates WHERE status = 'active' ORDER BY product_name ASC"
            );
            foreach ($rows as $r) {
                if (!in_array($r['product_name'], $existingNames, true)) {
                    $products[]      = ['id' => (int)$r['id'], 'name' => $r['product_name'], 'source' => 'product_templates'];
                    $existingNames[] = $r['product_name'];
                }
            }
        } catch (Exception $e) {}

        // 3. products (المنتجات المباشرة)
        try {
            $rows = $db->query(
                "SELECT id, name FROM products WHERE status = 'active' ORDER BY name ASC"
            );
            foreach ($rows as $r) {
                if (!in_array($r['name'], $existingNames, true)) {
                    $products[]      = ['id' => (int)$r['id'], 'name' => $r['name'], 'source' => 'products'];
                    $existingNames[] = $r['name'];
                }
            }
        } catch (Exception $e) {}

        // ترتيب أبجدي نهائي
        usort($products, fn($a, $b) => strcmp($a['name'], $b['name']));

        respond(true, ['products' => $products, 'count' => count($products)]);
    } catch (Exception $e) {
        error_log('public_order products list error: ' . $e->getMessage());
        respond(false, ['error' => 'خطأ داخلي في الخادم'], 500);
    }
}

// ─── التحقق من طريقة الطلب ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, ['error' => 'يجب إرسال طلب POST'], 405);
}

// ─── القيم المسموح بها ──────────────────────────────────────────────
$allowedTypes      = ['shop_order', 'cash_customer', 'telegraph', 'shipping_company'];
$allowedPriorities = ['low', 'normal', 'high', 'urgent'];
$allowedUnits      = ['قطعة', 'كرتونة', 'عبوة', 'شرينك', 'دسته', 'جرام', 'كيلو'];
$integerUnits      = ['كيلو', 'قطعة', 'جرام', 'دسته'];

// ─── استخراج الحقول ─────────────────────────────────────────────────
$taskType = trim($_POST['task_type'] ?? '');
if (!in_array($taskType, $allowedTypes, true)) {
    respond(false, ['error' => 'task_type غير صحيح. القيم المسموحة: ' . implode(', ', $allowedTypes)], 422);
}

$priority = $_POST['priority'] ?? 'normal';
$priority = in_array($priority, $allowedPriorities, true) ? $priority : 'normal';

$customerName  = trim($_POST['customer_name']  ?? '');
$customerPhone = trim($_POST['customer_phone'] ?? '');
$details       = trim($_POST['details']        ?? '');
$orderTitle    = trim($_POST['order_title']    ?? '');
$dueDate       = trim($_POST['due_date']       ?? '');

// تحقق بسيط من تنسيق التاريخ
if ($dueDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
    respond(false, ['error' => 'due_date يجب أن يكون بصيغة YYYY-MM-DD'], 422);
}

// حقول Telegraph / شركة شحن
$tgGovernorate = trim($_POST['tg_governorate'] ?? '');
$tgGovId       = isset($_POST['tg_gov_id']) && $_POST['tg_gov_id'] !== '' ? (int)$_POST['tg_gov_id'] : null;
$tgCity        = trim($_POST['tg_city']        ?? '');
$tgCityId      = isset($_POST['tg_city_id'])   && $_POST['tg_city_id'] !== '' ? (int)$_POST['tg_city_id'] : null;
$tgWeight      = trim($_POST['tg_weight']      ?? '');
$tgParcelDesc  = trim($_POST['tg_parcel_desc'] ?? '');
$tgPiecesCount = isset($_POST['tg_pieces_count']) && $_POST['tg_pieces_count'] !== ''
    ? max(0, (int)$_POST['tg_pieces_count']) : 0;

// مبالغ
$shippingFees   = 0.0;
$discount       = 0.0;
$advancePayment = 0.0;

if ($taskType !== 'telegraph' && isset($_POST['shipping_fees']) && $_POST['shipping_fees'] !== '') {
    $shippingFees = max(0.0, (float)str_replace(',', '.', (string)$_POST['shipping_fees']));
}
if (isset($_POST['discount']) && $_POST['discount'] !== '') {
    $discount = max(0.0, (float)str_replace(',', '.', (string)$_POST['discount']));
}
if (isset($_POST['advance_payment']) && $_POST['advance_payment'] !== '') {
    $advancePayment = max(0.0, (float)str_replace(',', '.', (string)$_POST['advance_payment']));
}

// ─── المنتجات ────────────────────────────────────────────────────────
$products = [];
$error    = '';

if (isset($_POST['products']) && is_array($_POST['products'])) {
    foreach ($_POST['products'] as $idx => $productData) {
        $pName = trim($productData['name'] ?? '');
        if ($pName === '') continue;

        $pUnit = trim($productData['unit'] ?? 'قطعة');
        if (!in_array($pUnit, $allowedUnits, true)) $pUnit = 'قطعة';

        $mustBeInt = in_array($pUnit, $integerUnits, true);
        $pQuantity = null;
        $qInput    = isset($productData['quantity']) ? trim((string)$productData['quantity']) : '';

        if ($qInput !== '') {
            $norm = str_replace(',', '.', $qInput);
            if (!is_numeric($norm)) {
                $error = "المنتج [{$idx}]: الكمية يجب أن تكون رقماً";
                break;
            }
            $pQuantity = (float)$norm;
            if ($pQuantity < 0) { $error = "المنتج [{$idx}]: الكمية لا تكون سالبة"; break; }
            if ($mustBeInt && $pQuantity != (int)$pQuantity) {
                $error = "المنتج [{$idx}]: الكمية يجب أن تكون عدداً صحيحاً للوحدة \"{$pUnit}\"";
                break;
            }
            if ($mustBeInt) $pQuantity = (int)$pQuantity;
            if ($pQuantity <= 0) $pQuantity = null;
        }

        $pPrice = null;
        $priceInput = isset($productData['price']) ? trim((string)$productData['price']) : '';
        if ($priceInput !== '' && is_numeric(str_replace(',', '.', $priceInput))) {
            $pPrice = max(0.0, (float)str_replace(',', '.', $priceInput));
        }

        $pLineTotal = null;
        $ltInput = isset($productData['line_total']) ? trim((string)$productData['line_total']) : '';
        if ($ltInput !== '' && is_numeric(str_replace(',', '.', $ltInput))) {
            $pLineTotal = max(0.0, (float)str_replace(',', '.', $ltInput));
        }

        $pCategory = trim($productData['category'] ?? '');

        $products[] = [
            'name'               => $pName,
            'quantity'           => $pQuantity,
            'unit'               => $pUnit,
            'category'           => $pCategory !== '' ? $pCategory : null,
            'effective_quantity' => $pQuantity, // يمكن تحسينه بـ qu.json لاحقاً
            'price'              => $pPrice,
            'line_total'         => $pLineTotal,
            'item_type'          => trim($productData['item_type'] ?? ''),
        ];
    }
}

if ($error !== '') {
    respond(false, ['error' => $error], 422);
}

// ─── قاعدة البيانات ──────────────────────────────────────────────────
try {
    $db = Database::getInstance();

    // المستخدم الافتراضي للـ API: أول مدير نشط
    $apiUser = $db->queryOne(
        "SELECT id, full_name, role FROM users WHERE role = 'manager' AND status = 'active' ORDER BY id ASC LIMIT 1"
    );
    if (!$apiUser) {
        respond(false, ['error' => 'لا يوجد مدير نشط في النظام'], 500);
    }
    $creatorId   = (int)$apiUser['id'];
    $creatorName = $apiUser['full_name'] ?? 'API';

    // العمال المخصصون
    $assignees = $_POST['assigned_to'] ?? [];
    if (!is_array($assignees)) $assignees = [$assignees];
    $assignees = array_values(array_unique(array_filter(array_map('intval', $assignees))));

    // التحقق من صحة IDs العمال
    if (!empty($assignees)) {
        $ph    = implode(',', array_fill(0, count($assignees), '?'));
        $valid = $db->query(
            "SELECT id FROM users WHERE id IN ({$ph}) AND status = 'active'",
            $assignees
        );
        $validIds  = array_column($valid, 'id');
        $assignees = array_values(array_intersect($assignees, array_map('intval', $validIds)));
    }

    // العنوان التلقائي
    $typeLabels = [
        'shop_order'       => 'اوردر محل',
        'cash_customer'    => 'عميل نقدي',
        'telegraph'        => 'تليجراف',
        'shipping_company' => 'شركة شحن',
    ];
    $title           = $typeLabels[$taskType] ?? 'مهمة جديدة';
    $relatedTypeValue = 'manager_' . $taskType;

    // ─── بناء notes ──────────────────────────────────────────────────
    $notesParts = [];
    if ($orderTitle !== '')    $notesParts[] = 'عنوان  :' . $orderTitle;
    if ($tgGovernorate !== '') $notesParts[] = 'المحافظة :' . $tgGovernorate;
    if ($tgCity !== '')        $notesParts[] = 'المدينة :' . $tgCity;
    if ($tgWeight !== '')      $notesParts[] = 'الوزن :' . $tgWeight;
    if ($tgPiecesCount > 0)   $notesParts[] = 'عدد القطع :' . $tgPiecesCount;
    if ($tgParcelDesc !== '')  $notesParts[] = 'وصف البضاعة :' . $tgParcelDesc;
    if ($details !== '')       $notesParts[] = $details;

    if (!empty($products)) {
        $notesParts[] = '[PRODUCTS_JSON]:' . json_encode($products, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $lines = [];
        foreach ($products as $p) {
            $line = 'المنتج: ' . $p['name'];
            if ($p['quantity'] !== null) $line .= ' - الكمية: ' . $p['quantity'];
            $lines[] = $line;
        }
        $notesParts[] = implode("\n", $lines);
    }

    // العمال في notes
    if (!empty($assignees)) {
        $workerRows   = $db->query(
            "SELECT id, full_name FROM users WHERE id IN (" . implode(',', array_fill(0, count($assignees), '?')) . ")",
            $assignees
        );
        $workerNames  = array_column($workerRows, 'full_name');
        $label = count($assignees) > 1 ? 'العمال المخصصون' : 'العامل المخصص';
        $notesParts[] = $label . ': ' . implode(', ', $workerNames)
            . "\n[ASSIGNED_WORKERS_IDS]:" . implode(',', $assignees);
    }

    if ($shippingFees > 0)   $notesParts[] = 'رسوم الشحن :' . $shippingFees;
    if ($discount > 0)       $notesParts[] = 'الخصم :' . $discount;
    if ($advancePayment > 0) $notesParts[] = '[ADVANCE_PAYMENT]:' . $advancePayment;

    $notesValue = !empty($notesParts) ? implode("\n\n", $notesParts) : null;

    // ─── البحث عن template_id / product_id ───────────────────────────
    $templateId  = null;
    $productId   = null;
    $productName = !empty($products) ? $products[0]['name'] : '';

    if ($productName !== '') {
        // unified_product_templates
        $tmpl = $db->queryOne(
            "SELECT id FROM unified_product_templates WHERE product_name = ? AND status = 'active' LIMIT 1",
            [$productName]
        );
        if ($tmpl) {
            $templateId = (int)$tmpl['id'];
        } else {
            // product_templates
            $tmpl = $db->queryOne(
                "SELECT id FROM product_templates WHERE product_name = ? AND status = 'active' LIMIT 1",
                [$productName]
            );
            if ($tmpl) $templateId = (int)$tmpl['id'];
        }

        if (!$templateId) {
            $prod = $db->queryOne(
                "SELECT id FROM products WHERE name = ? AND status = 'active' LIMIT 1",
                [$productName]
            );
            if ($prod) $productId = (int)$prod['id'];
        }
    }

    // ─── الكمية الإجمالية ────────────────────────────────────────────
    $totalQuantity = null;
    $firstUnit     = 'قطعة';
    if (!empty($products)) {
        $firstUnit     = $products[0]['unit'] ?? 'قطعة';
        $totalQuantity = 0;
        foreach ($products as $p) {
            if ($p['quantity'] !== null) $totalQuantity += $p['quantity'];
        }
        if ($totalQuantity <= 0) $totalQuantity = null;
    }

    // ─── INSERT ───────────────────────────────────────────────────────
    $columns      = ['title', 'description', 'created_by', 'priority', 'status', 'related_type', 'task_type'];
    $values       = [$title, $details ?: null, $creatorId, $priority, 'pending', $relatedTypeValue, $taskType];
    $placeholders = ['?', '?', '?', '?', '?', '?', '?'];

    $firstAssignee = !empty($assignees) ? (int)$assignees[0] : 0;
    if ($firstAssignee > 0) {
        $columns[]      = 'assigned_to';
        $values[]       = $firstAssignee;
        $placeholders[] = '?';
    }

    if ($dueDate !== '') {
        $columns[]      = 'due_date';
        $values[]       = $dueDate;
        $placeholders[] = '?';
    }

    if ($notesValue !== null) {
        $columns[]      = 'notes';
        $values[]       = $notesValue;
        $placeholders[] = '?';
    }

    $columns[]      = 'template_id';
    $values[]       = $templateId;
    $placeholders[] = '?';

    $columns[]      = 'product_name';
    $values[]       = $productName !== '' ? $productName : null;
    $placeholders[] = '?';

    if ($productId !== null) {
        $columns[]      = 'product_id';
        $values[]       = $productId;
        $placeholders[] = '?';
    }

    if ($customerName !== '') {
        $columns[]      = 'customer_name';
        $values[]       = $customerName;
        $placeholders[] = '?';
    }

    if ($customerPhone !== '') {
        $columns[]      = 'customer_phone';
        $values[]       = $customerPhone;
        $placeholders[] = '?';
    }

    if ($totalQuantity !== null) {
        $columns[]      = 'quantity';
        $values[]       = $totalQuantity;
        $placeholders[] = '?';
    }

    if (!empty($products)) {
        $columns[]      = 'unit';
        $values[]       = $firstUnit;
        $placeholders[] = '?';
    }

    // tg_gov_id و tg_city_id إذا كانت الأعمدة موجودة
    if ($tgGovId !== null) {
        try {
            $colCheck = $db->queryOne("SHOW COLUMNS FROM tasks LIKE 'tg_gov_id'");
            if ($colCheck) {
                $columns[]      = 'tg_gov_id';
                $values[]       = $tgGovId;
                $placeholders[] = '?';
            }
        } catch (Exception $e) {}
    }
    if ($tgCityId !== null) {
        try {
            $colCheck = $db->queryOne("SHOW COLUMNS FROM tasks LIKE 'tg_city_id'");
            if ($colCheck) {
                $columns[]      = 'tg_city_id';
                $values[]       = $tgCityId;
                $placeholders[] = '?';
            }
        } catch (Exception $e) {}
    }

    $sql    = "INSERT INTO tasks (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
    $result = $db->execute($sql, $values);
    $taskId = (int)($result['insert_id'] ?? 0);

    if ($taskId <= 0) {
        respond(false, ['error' => 'تعذر إنشاء الأوردر في قاعدة البيانات'], 500);
    }

    // ─── Audit Log ───────────────────────────────────────────────────
    logAudit(
        $creatorId,
        'create_production_task_api',
        'tasks',
        $taskId,
        null,
        [
            'task_type'   => $taskType,
            'source'      => 'public_api',
            'assigned_to' => $assignees,
            'priority'    => $priority,
        ]
    );

    // ─── إشعارات العمال ───────────────────────────────────────────────
    $notifMessage = $title;
    if (count($assignees) > 1) {
        $notifMessage .= ' (مشتركة مع ' . (count($assignees) - 1) . ' عامل آخر)';
    }
    foreach ($assignees as $wId) {
        try {
            createNotification(
                $wId,
                'مهمة جديدة من الإدارة',
                $notifMessage,
                'info',
                getDashboardUrl('production') . '?page=tasks'
            );
        } catch (Exception $e) {
            error_log('public_order API notification error: ' . $e->getMessage());
        }
    }

    // ─── إشعار عام لعمال الإنتاج ─────────────────────────────────────
    try {
        $productionUsers = $db->query(
            "SELECT id FROM users WHERE role = 'production' AND status = 'active'"
        );
        $globalMsg = "أوردر جديد #{$taskId} — {$title}";
        if ($customerName !== '') $globalMsg .= " — {$customerName}";
        foreach ($productionUsers as $pu) {
            if (!in_array((int)$pu['id'], $assignees, true)) {
                createNotification(
                    (int)$pu['id'],
                    'أوردر جديد',
                    $globalMsg,
                    'info',
                    getDashboardUrl('production') . '?page=tasks'
                );
            }
        }
    } catch (Exception $e) {
        error_log('public_order API global notification error: ' . $e->getMessage());
    }

    respond(true, [
        'task_id'     => $taskId,
        'task_number' => 'ORD-' . $taskId,
        'message'     => 'تم إنشاء الأوردر بنجاح',
    ]);

} catch (Exception $e) {
    error_log('public_order API error: ' . $e->getMessage());
    respond(false, ['error' => 'خطأ داخلي في الخادم'], 500);
}
