<?php
/**
 * API: معاينة تأثير اعتماد الفاتورة على المخزون
 * يُستدعى عند فتح نموذج اعتماد الفاتورة لعرض ملخص ما سيُخصم من كل مخزن
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/path_helper.php';

// تنظيف أي output buffers موجودة
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
    $result = buildInventoryImpact($db, $notes);

    echo json_encode(['success' => true, 'data' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
    error_log('task_inventory_preview error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'حدث خطأ: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('task_inventory_preview fatal: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'حدث خطأ فادح'], JSON_UNESCAPED_UNICODE);
}

/**
 * بناء بيانات تأثير الفاتورة على المخزون
 */
function buildInventoryImpact($db, $notes)
{
    // استخراج المنتجات من [PRODUCTS_JSON]
    $products = [];
    if (preg_match('/\[PRODUCTS_JSON\]:\s*(.+?)(?=\n\n\[|\z)/s', $notes, $m)) {
        $decoded = json_decode(trim($m[1]), true);
        if (is_array($decoded)) {
            $products = $decoded;
        }
    }

    if (empty($products)) {
        return ['company_products' => [], 'raw_materials' => [], 'packaging' => []];
    }

    // تحميل qu.json لحساب الكمية الفعلية للشرينك
    $quData = loadQuJsonData($db);

    $companyProducts = [];
    $rawMaterials    = [];
    $packaging       = [];

    foreach ($products as $product) {
        $name     = trim($product['name'] ?? '');
        $qty      = (float)($product['quantity'] ?? 0);
        $unit     = trim($product['unit'] ?? 'قطعة');
        $category = trim($product['category'] ?? '');

        if ($name === '' || $qty <= 0) {
            continue;
        }

        // حساب الكمية الفعلية (تحويل الشرينك إلى قطعة)
        $effectiveQty = $qty;
        if ($unit === 'شرينك' && $category !== '' && !empty($quData)) {
            foreach ($quData as $it) {
                $qt = trim((string)($it['type'] ?? ''));
                $qd = trim((string)($it['description'] ?? ''));
                if ($qt === $category && $qd === 'شرينك') {
                    $effectiveQty = $qty * (float)($it['quantity'] ?? 1);
                    break;
                }
            }
        }
        $displayUnit = ($unit === 'شرينك') ? 'قطعة' : $unit;

        // ====== 1. منتجات الشركة ======
        $productRow = null;
        try {
            $productRow = $db->queryOne(
                "SELECT id, quantity FROM products WHERE name = ? AND status = 'active' LIMIT 1",
                [$name]
            );
        } catch (Exception $e) { /* skip */ }

        if ($productRow) {
            $available = (float)$productRow['quantity'];
            $companyProducts[] = [
                'name'      => $name,
                'needed'    => $effectiveQty,
                'unit'      => $displayUnit,
                'available' => $available,
                'sufficient'=> $available >= $effectiveQty,
            ];
        }

        // ====== 2. البحث عن القالب لاستخراج الخامات وأدوات التعبئة ======
        $templateId = null;
        $isUnified  = false;

        // أولاً: البحث في unified_product_templates
        try {
            $uCheck = $db->queryOne("SHOW TABLES LIKE 'unified_product_templates'");
            if (!empty($uCheck)) {
                $tpl = $db->queryOne(
                    "SELECT id FROM unified_product_templates WHERE product_name = ? LIMIT 1",
                    [$name]
                );
                if ($tpl) {
                    $templateId = (int)$tpl['id'];
                    $isUnified  = true;
                }
            }
        } catch (Exception $e) { /* skip */ }

        // ثانياً: البحث في product_templates
        if (!$templateId) {
            try {
                $ptCheck = $db->queryOne("SHOW TABLES LIKE 'product_templates'");
                if (!empty($ptCheck)) {
                    $tpl = $db->queryOne(
                        "SELECT id FROM product_templates WHERE product_name = ? LIMIT 1",
                        [$name]
                    );
                    if ($tpl) {
                        $templateId = (int)$tpl['id'];
                        $isUnified  = false;
                    }
                }
            } catch (Exception $e) { /* skip */ }
        }

        if (!$templateId) {
            continue; // لا يوجد قالب، نتجاوز
        }

        // ====== 3. الخامات من القالب ======
        if ($isUnified) {
            try {
                $rmCheck = $db->queryOne("SHOW TABLES LIKE 'template_raw_materials'");
                if (!empty($rmCheck)) {
                    $raws = $db->query(
                        "SELECT material_type, material_name, honey_variety, quantity, unit
                         FROM template_raw_materials WHERE template_id = ?",
                        [$templateId]
                    ) ?? [];
                    foreach ($raws as $rm) {
                        $neededQty = (float)$rm['quantity'] * $effectiveQty;
                        $available = getRawMaterialStock($db, $rm['material_type'], $rm['material_name'], $rm['honey_variety'] ?? null);
                        $rawMaterials[] = [
                            'name'      => $rm['material_name'],
                            'type'      => $rm['material_type'],
                            'needed'    => $neededQty,
                            'unit'      => $rm['unit'],
                            'available' => $available,
                            'sufficient'=> ($available !== null) ? ($available >= $neededQty) : null,
                        ];
                    }
                }
            } catch (Exception $e) { /* skip */ }
        } else {
            try {
                $ptrmCheck = $db->queryOne("SHOW TABLES LIKE 'product_template_raw_materials'");
                if (!empty($ptrmCheck)) {
                    $raws = $db->query(
                        "SELECT material_name, quantity_per_unit, unit
                         FROM product_template_raw_materials WHERE template_id = ?",
                        [$templateId]
                    ) ?? [];
                    foreach ($raws as $rm) {
                        $neededQty = (float)$rm['quantity_per_unit'] * $effectiveQty;
                        $rawMaterials[] = [
                            'name'      => $rm['material_name'],
                            'type'      => null,
                            'needed'    => $neededQty,
                            'unit'      => $rm['unit'],
                            'available' => null,
                            'sufficient'=> null,
                        ];
                    }
                }
            } catch (Exception $e) { /* skip */ }
        }

        // ====== 4. أدوات التعبئة من القالب ======
        if ($isUnified) {
            try {
                $tpCheck = $db->queryOne("SHOW TABLES LIKE 'template_packaging'");
                if (!empty($tpCheck)) {
                    $pkgs = $db->query(
                        "SELECT tp.packaging_material_id,
                                COALESCE(tp.packaging_name, pm.name, CONCAT('أداة #', tp.packaging_material_id)) as pname,
                                tp.quantity_per_unit,
                                pm.quantity as available
                         FROM template_packaging tp
                         LEFT JOIN packaging_materials pm ON tp.packaging_material_id = pm.id
                         WHERE tp.template_id = ?",
                        [$templateId]
                    ) ?? [];
                    foreach ($pkgs as $pkg) {
                        $neededQty = (float)$pkg['quantity_per_unit'] * $effectiveQty;
                        $available = ($pkg['available'] !== null) ? (float)$pkg['available'] : null;
                        $packaging[] = [
                            'id'        => (int)$pkg['packaging_material_id'],
                            'name'      => $pkg['pname'],
                            'needed'    => $neededQty,
                            'available' => $available,
                            'sufficient'=> ($available !== null) ? ($available >= $neededQty) : null,
                        ];
                    }
                }
            } catch (Exception $e) { /* skip */ }
        } else {
            try {
                $ptpCheck = $db->queryOne("SHOW TABLES LIKE 'product_template_packaging'");
                if (!empty($ptpCheck)) {
                    $pkgs = $db->query(
                        "SELECT ptp.packaging_material_id, ptp.packaging_name, ptp.quantity_per_unit,
                                pm.quantity as available
                         FROM product_template_packaging ptp
                         LEFT JOIN packaging_materials pm ON ptp.packaging_material_id = pm.id
                         WHERE ptp.template_id = ?",
                        [$templateId]
                    ) ?? [];
                    foreach ($pkgs as $pkg) {
                        $neededQty = (float)$pkg['quantity_per_unit'] * $effectiveQty;
                        $available = ($pkg['available'] !== null) ? (float)$pkg['available'] : null;
                        $packaging[] = [
                            'id'        => (int)$pkg['packaging_material_id'],
                            'name'      => $pkg['packaging_name'],
                            'needed'    => $neededQty,
                            'available' => $available,
                            'sufficient'=> ($available !== null) ? ($available >= $neededQty) : null,
                        ];
                    }
                }
            } catch (Exception $e) { /* skip */ }
        }
    }

    return [
        'company_products' => $companyProducts,
        'raw_materials'    => $rawMaterials,
        'packaging'        => $packaging,
    ];
}

/**
 * قراءة qu.json لتحويل الشرينك إلى قطعة
 */
function loadQuJsonData($db)
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

/**
 * جلب الكمية المتاحة من مخازن الخامات بناءً على نوع الخامة
 */
function getRawMaterialStock($db, $materialType, $materialName, $honeyVariety = null)
{
    try {
        switch ($materialType) {
            case 'honey_raw':
                $row = $db->queryOne("SELECT COALESCE(SUM(raw_honey_quantity), 0) as total FROM honey_stock");
                return (float)($row['total'] ?? 0);

            case 'honey_filtered':
                if ($honeyVariety) {
                    $row = $db->queryOne(
                        "SELECT COALESCE(SUM(filtered_honey_quantity), 0) as total FROM honey_stock WHERE honey_variety = ?",
                        [$honeyVariety]
                    );
                } else {
                    $row = $db->queryOne("SELECT COALESCE(SUM(filtered_honey_quantity), 0) as total FROM honey_stock");
                }
                return (float)($row['total'] ?? 0);

            case 'olive_oil':
                $row = $db->queryOne("SELECT COALESCE(SUM(quantity), 0) as total FROM olive_oil_stock");
                return (float)($row['total'] ?? 0);

            case 'beeswax':
                $row = $db->queryOne("SELECT COALESCE(SUM(weight), 0) as total FROM beeswax_stock");
                return (float)($row['total'] ?? 0);

            case 'derivatives':
                $row = $db->queryOne(
                    "SELECT COALESCE(SUM(weight), 0) as total FROM derivatives_stock WHERE derivative_type = ?",
                    [$materialName]
                );
                return (float)($row['total'] ?? 0);

            case 'nuts':
                $row = $db->queryOne(
                    "SELECT COALESCE(SUM(quantity), 0) as total FROM nuts_stock WHERE nut_type = ?",
                    [$materialName]
                );
                return (float)($row['total'] ?? 0);

            default:
                return null;
        }
    } catch (Exception $e) {
        error_log('getRawMaterialStock error: ' . $e->getMessage());
        return null;
    }
}
