<?php
/**
 * Public Register Customer API — تسجيل عميل محلي جديد بدون جلسة
 * الحماية: API Key في header أو POST/GET
 *
 * POST /api/store_customers_proxy.php
 *
 * Headers (اختياري بديل عن الحقل):
 *   X-API-Key: <key>
 *
 * الحقول:
 *   api_key        (string, مطلوب إذا لم يُرسل في header)
 *   name           (string, مطلوب)   — اسم العميل
 *   phones[]       (string[], اختياري) — أرقام الهاتف (مصفوفة)
 *   phone          (string, اختياري)  — رقم هاتف واحد (بديل عن phones[])
 *   balance        (float, اختياري)   — رصيد/دين العميل (افتراضي 0؛ سالب = رصيد دائن)
 *   address        (string, اختياري)  — العنوان
 *   region_id      (int, اختياري)     — معرّف المنطقة
 *   tg_governorate (string, اختياري)  — اسم المحافظة (تليجراف)
 *   tg_gov_id      (int, اختياري)     — معرّف المحافظة
 *   tg_city        (string, اختياري)  — اسم المدينة (تليجراف)
 *   tg_city_id     (int, اختياري)     — معرّف المدينة
 *   latitude       (float, اختياري)   — خط العرض
 *   longitude      (float, اختياري)   — خط الطول
 *
 * الـ Body يمكن أن يكون JSON أو form-data عادي.
 *
 * استجابة النجاح:
 *   {"success":true,"customer_id":5,"unique_code":"A3B9X","message":"تم تسجيل العميل بنجاح"}
 *
 * استجابة الفشل:
 *   {"success":false,"error":"رسالة الخطأ"}
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/customer_code_generator.php';

// تنظيف أي output قبل الإرسال
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

// ─── دالة الرد ────────────────────────────────────────────────────────
function respond(bool $success, array $data = [], int $code = 200): void
{
    http_response_code($code);
    echo json_encode(array_merge(['success' => $success], $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ─── قبول JSON body أو form-data ─────────────────────────────────────
$input = $_POST;
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}

// ─── التحقق من الـ API Key ────────────────────────────────────────────
$validApiKey = defined('PUBLIC_ORDER_API_KEY')
    ? PUBLIC_ORDER_API_KEY
    : (getenv('PUBLIC_ORDER_API_KEY') ?: null);

if (!$validApiKey) {
    respond(false, ['error' => 'لم يتم تكوين مفتاح الـ API في الخادم'], 500);
}

$suppliedKey = $_SERVER['HTTP_X_API_KEY']
    ?? ($input['api_key'] ?? '');

if (!hash_equals($validApiKey, (string)$suppliedKey)) {
    respond(false, ['error' => 'مفتاح الـ API غير صحيح'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, ['error' => 'يجب استخدام POST'], 405);
}

// ─── استخراج الحقول ───────────────────────────────────────────────────
$name    = trim((string)($input['name'] ?? ''));
$address = trim((string)($input['address'] ?? ''));

// أرقام الهاتف: phones[] أو phone
$phonesRaw = $input['phones'] ?? null;
if (!is_array($phonesRaw)) {
    $phonesRaw = [];
}
$singlePhone = trim((string)($input['phone'] ?? ''));
if ($singlePhone !== '' && !in_array($singlePhone, $phonesRaw, true)) {
    array_unshift($phonesRaw, $singlePhone);
}
$phones = array_values(array_filter(array_map('trim', $phonesRaw)));

// رصيد العميل
$balanceRaw = $input['balance'] ?? 0;
$balance    = is_numeric($balanceRaw) ? round((float)$balanceRaw, 2) : 0.0;

// منطقة
$regionId = isset($input['region_id']) && $input['region_id'] !== '' ? (int)$input['region_id'] : null;

// تليجراف
$tgGovernorate = trim((string)($input['tg_governorate'] ?? ''));
$tgGovId       = isset($input['tg_gov_id']) && $input['tg_gov_id'] !== '' ? (int)$input['tg_gov_id'] : null;
$tgCity        = trim((string)($input['tg_city'] ?? ''));
$tgCityId      = isset($input['tg_city_id']) && $input['tg_city_id'] !== '' ? (int)$input['tg_city_id'] : null;

// موقع جغرافي
$latitude  = isset($input['latitude'])  && $input['latitude']  !== '' ? (float)$input['latitude']  : null;
$longitude = isset($input['longitude']) && $input['longitude'] !== '' ? (float)$input['longitude'] : null;

// ─── التحقق من الحقول المطلوبة ───────────────────────────────────────
if ($name === '') {
    respond(false, ['error' => 'اسم العميل مطلوب'], 422);
}

// ─── الإدراج في قاعدة البيانات ────────────────────────────────────────
try {
    $db = Database::getInstance();

    // التحقق من التكرار (اسم + هاتف أول)
    $primaryPhone = $phones[0] ?? '';
    if ($primaryPhone !== '') {
        $dup = $db->queryOne(
            "SELECT id FROM local_customers WHERE name = ? AND phone = ? LIMIT 1",
            [$name, $primaryPhone]
        );
    } else {
        $dup = $db->queryOne(
            "SELECT id FROM local_customers WHERE name = ? LIMIT 1",
            [$name]
        );
    }
    if ($dup) {
        respond(false, [
            'error'       => 'يوجد عميل مسجل مسبقاً بنفس الاسم' . ($primaryPhone !== '' ? ' ورقم الهاتف' : ''),
            'customer_id' => (int)$dup['id'],
        ], 409);
    }

    // توليد unique_code
    ensureCustomerUniqueCodeColumn('local_customers');
    $uniqueCode = generateUniqueCustomerCode('local_customers');

    // بناء الـ INSERT ديناميكياً
    $cols = ['unique_code', 'name', 'phone', 'balance', 'address', 'status'];
    $vals = [$uniqueCode, $name, $primaryPhone ?: null, $balance, $address ?: null, 'active'];
    $ph   = array_fill(0, count($cols), '?');

    // region_id
    if ($regionId !== null && !empty($db->queryOne("SHOW COLUMNS FROM local_customers LIKE 'region_id'"))) {
        $cols[] = 'region_id'; $vals[] = $regionId; $ph[] = '?';
    }

    // حقول تليجراف
    if (!empty($db->queryOne("SHOW COLUMNS FROM local_customers LIKE 'tg_governorate'"))) {
        if ($tgGovernorate !== '') { $cols[] = 'tg_governorate'; $vals[] = $tgGovernorate; $ph[] = '?'; }
        if ($tgGovId !== null)     { $cols[] = 'tg_gov_id';      $vals[] = $tgGovId;      $ph[] = '?'; }
        if ($tgCity !== '')        { $cols[] = 'tg_city';         $vals[] = $tgCity;        $ph[] = '?'; }
        if ($tgCityId !== null)    { $cols[] = 'tg_city_id';      $vals[] = $tgCityId;      $ph[] = '?'; }
    }

    // موقع جغرافي
    if ($latitude !== null && !empty($db->queryOne("SHOW COLUMNS FROM local_customers LIKE 'latitude'"))) {
        $cols[] = 'latitude'; $vals[] = $latitude; $ph[] = '?';
    }
    if ($longitude !== null && !empty($db->queryOne("SHOW COLUMNS FROM local_customers LIKE 'longitude'"))) {
        $cols[] = 'longitude'; $vals[] = $longitude; $ph[] = '?';
    }
    if ($latitude !== null && $longitude !== null && !empty($db->queryOne("SHOW COLUMNS FROM local_customers LIKE 'location_captured_at'"))) {
        $cols[] = 'location_captured_at'; $vals[] = date('Y-m-d H:i:s'); $ph[] = '?';
    }

    $result     = $db->execute(
        "INSERT INTO local_customers (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $ph) . ")",
        $vals
    );
    $customerId = isset($result['insert_id']) && $result['insert_id'] > 0
        ? (int)$result['insert_id']
        : (int)$db->getLastInsertId();

    if ($customerId <= 0) {
        throw new Exception('فشل الحصول على معرّف العميل بعد الإدراج');
    }

    // حفظ أرقام الهاتف في local_customer_phones
    $firstPhone = true;
    foreach ($phones as $num) {
        if ($num !== '') {
            $db->execute(
                "INSERT INTO local_customer_phones (customer_id, phone, is_primary) VALUES (?, ?, ?)",
                [$customerId, $num, $firstPhone ? 1 : 0]
            );
            $firstPhone = false;
        }
    }

    respond(true, [
        'customer_id' => $customerId,
        'unique_code' => $uniqueCode,
        'message'     => 'تم تسجيل العميل بنجاح',
    ], 201);

} catch (Throwable $e) {
    error_log('store_customers_proxy error: ' . $e->getMessage());
    respond(false, ['error' => 'حدث خطأ أثناء تسجيل العميل. يرجى المحاولة لاحقاً.'], 500);
}
