<?php
/**
 * API: معاينة تأثير اعتماد الفاتورة على المخزون
 * لكل منتج في الأوردر: يبحث عنه بالاسم في (products → packaging_materials → مخازن الخامات)
 * ويعرض الكمية المتاحة ومن أين سيُخصم
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/path_helper.php';

while (ob_get_level() > 0) {
    @ob_end_clean();
}

header('Content-Type: application/json; charset=utf-8');

try {
    requireRole(['manager', 'accountant', 'developer']);
    $db = db();

    $taskId = intval($_GET['task_id'] ?? 0);
    if ($taskId <= 0) {
        echo json_encode(['success' => false, 'error' => 'معرف المهمة غير صحيح'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $task = $db->queryOne("SELECT notes FROM tasks WHERE id = ? LIMIT 1", [$taskId]);
    if (!$task) {
        echo json_encode(['success' => false, 'error' => 'المهمة غير موجودة'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $notes = (string)($task['notes'] ?? '');
    $result = buildInventoryPreview($db, $notes);

    echo json_encode(['success' => true, 'data' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
    error_log('task_inventory_preview error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'حدث خطأ: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('task_inventory_preview fatal: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'حدث خطأ فادح'], JSON_UNESCAPED_UNICODE);
}

/**
 * لكل منتج في الأوردر: يبحث بالاسم في المخازن الثلاثة ويُرجع بيانات المعاينة
 */
function buildInventoryPreview($db, $notes)
{
    // استخراج المنتجات — نوقف عند أول سطر جديد (JSON دائماً على سطر واحد)
    $products = [];
    if (preg_match('/(?:\[PRODUCTS_JSON\]|المنتجات)\s*:\s*(\[.+?\])(?=\s*\n|\[ASSIGNED_WORKERS_IDS\]|$)/su', $notes, $m)) {
        $decoded = json_decode(trim($m[1]), true);
        if (is_array($decoded)) {
            $products = $decoded;
        }
    }

    if (empty($products)) {
        return [];
    }

    // qu.json لتحويل الشرينك إلى قطعة
    $quData = loadQuData();

    $rows = [];

    foreach ($products as $product) {
        $name     = trim($product['name'] ?? '');
        $unit     = trim($product['unit'] ?? 'قطعة');
        $category = trim($product['category'] ?? '');

        if ($name === '') {
            continue;
        }

        // حساب الكمية الفعلية
        $rawQty = $product['effective_quantity'] ?? $product['quantity'] ?? null;
        $qty    = ($rawQty !== null) ? (float)$rawQty : 0;

        $effectiveQty = $qty;
        if ($unit === 'شرينك' && $category !== '' && !empty($quData)) {
            foreach ($quData as $it) {
                if (trim((string)($it['type'] ?? '')) === $category &&
                    trim((string)($it['description'] ?? '')) === 'شرينك') {
                    $effectiveQty = $qty * (float)($it['quantity'] ?? 1);
                    break;
                }
            }
        }
        $displayUnit = ($unit === 'شرينك') ? 'قطعة' : $unit;

        // البحث بالاسم في المخازن بالترتيب
        $found = findProductInWarehouses($db, $name);

        $rows[] = [
            'name'      => $name,
            'needed'    => $effectiveQty,
            'unit'      => $displayUnit,
            'source'    => $found['source'],      // 'products' | 'packaging' | 'raw' | null
            'source_label' => $found['label'],    // نص المصدر للعرض
            'available' => $found['available'],   // float أو null
            'sufficient'=> ($found['available'] !== null && $effectiveQty > 0)
                               ? ($found['available'] >= $effectiveQty)
                               : null,
        ];
    }

    return $rows;
}

/**
 * يبحث عن المنتج بالاسم في: products → packaging_materials → honey_stock (بالنوع) → derivatives/nuts
 * يُرجع: ['source'=>..., 'label'=>..., 'available'=>float|null]
 */
function findProductInWarehouses($db, $name)
{
    $notFound = ['source' => null, 'label' => 'غير موجود في المخزون', 'available' => null];

    // ====== 0. قوالب المنتجات (finished_products) ======
    try {
        $fpCheck = $db->queryOne("SHOW TABLES LIKE 'finished_products'");
        $ptCheck = $db->queryOne("SHOW TABLES LIKE 'product_templates'");
        if (!empty($fpCheck) && !empty($ptCheck)) {
            // تحقق من وجود القالب باسمه
            $tpl = $db->queryOne(
                "SELECT id FROM product_templates WHERE TRIM(product_name) = ? AND status = 'active' LIMIT 1",
                [$name]
            );
            if ($tpl !== null) {
                $fpRow = $db->queryOne(
                    "SELECT COALESCE(SUM(fp.quantity_produced), 0) AS total
                     FROM finished_products fp
                     LEFT JOIN products pr ON fp.product_id = pr.id
                     WHERE (TRIM(fp.product_name) = ?
                            OR TRIM(COALESCE(NULLIF(fp.product_name,''), pr.name)) = ?)
                       AND fp.quantity_produced > 0",
                    [$name, $name]
                );
                $available = $fpRow ? (float)$fpRow['total'] : 0.0;
                return [
                    'source'    => 'finished_products',
                    'label'     => 'قوالب المنتجات (المنتجات الجاهزة)',
                    'available' => $available,
                ];
            }
        } elseif (!empty($ptCheck)) {
            // لا يوجد finished_products — الكمية في products
            $tpl = $db->queryOne(
                "SELECT id FROM product_templates WHERE TRIM(product_name) = ? AND status = 'active' LIMIT 1",
                [$name]
            );
            if ($tpl !== null) {
                $row = $db->queryOne(
                    "SELECT COALESCE(SUM(quantity), 0) AS total FROM products WHERE name = ? AND status = 'active'",
                    [$name]
                );
                return [
                    'source'    => 'finished_products',
                    'label'     => 'قوالب المنتجات',
                    'available' => $row ? (float)$row['total'] : 0.0,
                ];
            }
        }
    } catch (Exception $e) { /* skip */ }

    // ====== 1. منتجات الشركة ======
    try {
        $row = $db->queryOne(
            "SELECT quantity FROM products WHERE name = ? LIMIT 1",
            [$name]
        );
        if ($row !== null) {
            return [
                'source'    => 'products',
                'label'     => 'منتجات الشركة',
                'available' => (float)$row['quantity'],
            ];
        }
    } catch (Exception $e) { /* skip */ }

    // ====== 2. مخزن أدوات التعبئة ======
    try {
        $pkgCheck = $db->queryOne("SHOW TABLES LIKE 'packaging_materials'");
        if (!empty($pkgCheck)) {
            $row = $db->queryOne(
                "SELECT quantity FROM packaging_materials WHERE (name = ? OR specifications = ?) AND status = 'active' LIMIT 1",
                [$name, $name]
            );
            if ($row !== null) {
                return [
                    'source'    => 'packaging',
                    'label'     => 'مخزن أدوات التعبئة',
                    'available' => (float)$row['quantity'],
                ];
            }
        }
    } catch (Exception $e) { /* skip */ }

    // ====== 3. مخزن الخامات (بحث مبسط بالاسم) ======

    // عسل — البحث بالنوع في honey_variety
    try {
        $hCheck = $db->queryOne("SHOW TABLES LIKE 'honey_stock'");
        if (!empty($hCheck)) {
            // بحث في الخام
            $row = $db->queryOne(
                "SELECT COALESCE(SUM(raw_honey_quantity), 0) as total FROM honey_stock WHERE honey_variety = ?",
                [$name]
            );
            if ($row && (float)$row['total'] > 0) {
                return [
                    'source'    => 'raw',
                    'label'     => 'مخزن الخامات (عسل خام)',
                    'available' => (float)$row['total'],
                ];
            }
            // بحث في المصفى
            $row = $db->queryOne(
                "SELECT COALESCE(SUM(filtered_honey_quantity), 0) as total FROM honey_stock WHERE honey_variety = ?",
                [$name]
            );
            if ($row && (float)$row['total'] > 0) {
                return [
                    'source'    => 'raw',
                    'label'     => 'مخزن الخامات (عسل مصفى)',
                    'available' => (float)$row['total'],
                ];
            }
        }
    } catch (Exception $e) { /* skip */ }

    // مشتقات — البحث بالنوع
    try {
        $dCheck = $db->queryOne("SHOW TABLES LIKE 'derivatives_stock'");
        if (!empty($dCheck)) {
            $row = $db->queryOne(
                "SELECT COALESCE(SUM(weight), 0) as total FROM derivatives_stock WHERE derivative_type = ?",
                [$name]
            );
            if ($row && (float)$row['total'] > 0) {
                return [
                    'source'    => 'raw',
                    'label'     => 'مخزن الخامات (مشتقات)',
                    'available' => (float)$row['total'],
                ];
            }
        }
    } catch (Exception $e) { /* skip */ }

    // مكسرات — البحث بالنوع
    try {
        $nCheck = $db->queryOne("SHOW TABLES LIKE 'nuts_stock'");
        if (!empty($nCheck)) {
            $row = $db->queryOne(
                "SELECT COALESCE(SUM(quantity), 0) as total FROM nuts_stock WHERE nut_type = ?",
                [$name]
            );
            if ($row && (float)$row['total'] > 0) {
                return [
                    'source'    => 'raw',
                    'label'     => 'مخزن الخامات (مكسرات)',
                    'available' => (float)$row['total'],
                ];
            }
        }
    } catch (Exception $e) { /* skip */ }

    // زيت زيتون (جدول واحد فقط)
    try {
        $oCheck = $db->queryOne("SHOW TABLES LIKE 'olive_oil_stock'");
        if (!empty($oCheck) && (stripos($name, 'زيت') !== false || stripos($name, 'زيتون') !== false)) {
            $row = $db->queryOne("SELECT COALESCE(SUM(quantity), 0) as total FROM olive_oil_stock");
            if ($row && (float)$row['total'] > 0) {
                return [
                    'source'    => 'raw',
                    'label'     => 'مخزن الخامات (زيت زيتون)',
                    'available' => (float)$row['total'],
                ];
            }
        }
    } catch (Exception $e) { /* skip */ }

    return $notFound;
}

function loadQuData()
{
    $paths = [];
    if (defined('ROOT_PATH')) {
        $paths[] = rtrim(ROOT_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'qu.json';
    }
    $paths[] = __DIR__ . '/../qu.json';
    foreach ($paths as $path) {
        if (is_readable($path)) {
            $raw = @file_get_contents($path);
            if ($raw !== false) {
                $decoded = @json_decode($raw, true);
                if (!empty($decoded['t']) && is_array($decoded['t'])) {
                    return $decoded['t'];
                }
            }
        }
    }
    return [];
}
