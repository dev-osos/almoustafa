<?php
/**
 * API: الحصول على أسماء القوالب للإنتاج
 */

define('ACCESS_ALLOWED', true);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method Not Allowed'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/auth.php';

    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Unauthorized'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $db = db();
    $templates = [];      // أسماء فقط (للتوافقية مع الكود القديم)
    $templatesDetailed = []; // تفاصيل كاملة مع الكود/ID
    $seenNames = [];      // لمنع التكرار

    // جلب القوالب من unified_product_templates إذا كان موجوداً
    try {
        $unifiedTemplatesCheck = $db->queryOne("SHOW TABLES LIKE 'unified_product_templates'");
        if (!empty($unifiedTemplatesCheck)) {
            // فحص وجود عمود template_code
            $hasTemplateCode = !empty($db->queryOne("SHOW COLUMNS FROM unified_product_templates LIKE 'template_code'"));
            $selectCols = $hasTemplateCode ? 'id, product_name, template_code' : 'id, product_name';
            $unifiedTemplates = $db->query("
                SELECT {$selectCols}
                FROM unified_product_templates
                WHERE status = 'active'
                ORDER BY product_name ASC
            ");
            foreach ($unifiedTemplates as $template) {
                $templateName = trim($template['product_name'] ?? '');
                if ($templateName !== '' && !isset($seenNames[$templateName])) {
                    $seenNames[$templateName] = true;
                    $templates[] = $templateName;
                    $templatesDetailed[] = [
                        'id' => (int)$template['id'],
                        'name' => $templateName,
                        'code' => $template['template_code'] ?? null,
                        'type' => 'template'
                    ];
                }
            }
        }
    } catch (Exception $e) {
        error_log('Error fetching unified_product_templates: ' . $e->getMessage());
    }

    // جلب القوالب من product_templates إذا كان موجوداً أيضاً
    try {
        $templatesCheck = $db->queryOne("SHOW TABLES LIKE 'product_templates'");
        if (!empty($templatesCheck)) {
            $hasTemplateCode = !empty($db->queryOne("SHOW COLUMNS FROM product_templates LIKE 'template_code'"));
            $selectCols = $hasTemplateCode ? 'id, product_name, template_code' : 'id, product_name';
            $productTemplates = $db->query("
                SELECT {$selectCols}
                FROM product_templates
                WHERE status = 'active'
                ORDER BY product_name ASC
            ");
            foreach ($productTemplates as $template) {
                $templateName = trim($template['product_name'] ?? '');
                if ($templateName !== '' && !isset($seenNames[$templateName])) {
                    $seenNames[$templateName] = true;
                    $templates[] = $templateName;
                    $templatesDetailed[] = [
                        'id' => (int)$template['id'],
                        'name' => $templateName,
                        'code' => $template['template_code'] ?? null,
                        'type' => 'template'
                    ];
                }
            }
        }
    } catch (Exception $e) {
        error_log('Error fetching product_templates: ' . $e->getMessage());
    }

    // جلب أسماء المنتجات الخارجية من products
    try {
        $hasProductType = $db->queryOne("SHOW COLUMNS FROM products LIKE 'product_type'");
        if (!empty($hasProductType)) {
            $externalProducts = $db->query("
                SELECT id, name
                FROM products
                WHERE product_type = 'external' AND status = 'active' AND name IS NOT NULL AND name != ''
                ORDER BY name ASC
            ");
            foreach ($externalProducts as $row) {
                $name = trim($row['name'] ?? '');
                if ($name !== '' && !isset($seenNames[$name])) {
                    $seenNames[$name] = true;
                    $templates[] = $name;
                    $templatesDetailed[] = [
                        'id' => (int)$row['id'],
                        'name' => $name,
                        'code' => null,
                        'type' => 'external'
                    ];
                }
            }
        }
    } catch (Exception $e) {
        error_log('Error fetching external products for templates: ' . $e->getMessage());
    }

    // جلب الخامات من مخزن الخامات (كل سجل فردي مع كود 4 أرقام ثابت)
    // الكود مشتق من hash الجدول+ID ليكون ثابتاً دون الحاجة لتخزينه
    function makeRawMaterialCode(string $tableKey, int $id): string {
        $hash = abs(crc32($tableKey . '_' . $id));
        return str_pad((string)(($hash % 9000) + 1000), 4, '0', STR_PAD_LEFT);
    }
    function addRawMaterial(string $label, string $tableKey, int $stockId, string $section,
        array &$templates, array &$templatesDetailed, array &$seenNames): void {
        if ($label === '' || isset($seenNames[$label])) return;
        $seenNames[$label] = true;
        $templates[] = $label;
        $templatesDetailed[] = [
            'id'          => 0,
            'name'        => $label,
            'code'        => makeRawMaterialCode($tableKey, $stockId),
            'type'        => 'raw_material',
            'section'     => $section,
            'stock_id'    => $stockId,
            'stock_table' => $tableKey,
        ];
    }
    try {
        // 1. العسل — كل صف له مورد ونوع، يظهر خام + مصفى
        if (!empty($db->queryOne("SHOW TABLES LIKE 'honey_stock'"))) {
            $rows = $db->query("
                SELECT hs.id, hs.honey_variety, s.name AS supplier_name
                FROM honey_stock hs
                LEFT JOIN suppliers s ON hs.supplier_id = s.id
                ORDER BY s.name ASC, hs.honey_variety ASC
            ");
            foreach ($rows as $row) {
                $id      = (int)$row['id'];
                $variety = trim($row['honey_variety'] ?? 'غير محدد');
                $sup     = trim($row['supplier_name'] ?? '');
                $suffix  = $sup !== '' ? ' (' . $sup . ')' : '';
                addRawMaterial('عسل خام - ' . $variety . $suffix,   'honey_stock_raw',      $id, 'العسل', $templates, $templatesDetailed, $seenNames);
                addRawMaterial('عسل مصفى - ' . $variety . $suffix,  'honey_stock_filtered',  $id, 'العسل', $templates, $templatesDetailed, $seenNames);
            }
        }

        // 2. زيت الزيتون — كل صف له مورد
        if (!empty($db->queryOne("SHOW TABLES LIKE 'olive_oil_stock'"))) {
            $rows = $db->query("
                SELECT oos.id, s.name AS supplier_name
                FROM olive_oil_stock oos
                LEFT JOIN suppliers s ON oos.supplier_id = s.id
                ORDER BY s.name ASC
            ");
            foreach ($rows as $row) {
                $id    = (int)$row['id'];
                $sup   = trim($row['supplier_name'] ?? '');
                $label = $sup !== '' ? 'زيت زيتون - ' . $sup : 'زيت زيتون #' . $id;
                addRawMaterial($label, 'olive_oil_stock', $id, 'زيت الزيتون', $templates, $templatesDetailed, $seenNames);
            }
        }

        // 3. شمع العسل — كل صف له مورد
        if (!empty($db->queryOne("SHOW TABLES LIKE 'beeswax_stock'"))) {
            $rows = $db->query("
                SELECT bs.id, s.name AS supplier_name
                FROM beeswax_stock bs
                LEFT JOIN suppliers s ON bs.supplier_id = s.id
                ORDER BY s.name ASC
            ");
            foreach ($rows as $row) {
                $id    = (int)$row['id'];
                $sup   = trim($row['supplier_name'] ?? '');
                $label = $sup !== '' ? 'شمع العسل - ' . $sup : 'شمع العسل #' . $id;
                addRawMaterial($label, 'beeswax_stock', $id, 'شمع العسل', $templates, $templatesDetailed, $seenNames);
            }
        }

        // 4. المكسرات — مفردة (نوع + مورد)
        if (!empty($db->queryOne("SHOW TABLES LIKE 'nuts_stock'"))) {
            $rows = $db->query("
                SELECT ns.id, ns.nut_type, s.name AS supplier_name
                FROM nuts_stock ns
                LEFT JOIN suppliers s ON ns.supplier_id = s.id
                ORDER BY ns.nut_type ASC, s.name ASC
            ");
            foreach ($rows as $row) {
                $id    = (int)$row['id'];
                $type  = trim($row['nut_type'] ?? 'مكسرات');
                $sup   = trim($row['supplier_name'] ?? '');
                $label = $sup !== '' ? $type . ' - ' . $sup : $type . ' #' . $id;
                addRawMaterial($label, 'nuts_stock', $id, 'المكسرات', $templates, $templatesDetailed, $seenNames);
            }
        }

        // 4b. المكسرات — خلطات
        if (!empty($db->queryOne("SHOW TABLES LIKE 'mixed_nuts'"))) {
            $rows = $db->query("
                SELECT mn.id, mn.batch_name, s.name AS supplier_name
                FROM mixed_nuts mn
                LEFT JOIN suppliers s ON mn.supplier_id = s.id
                ORDER BY mn.id ASC
            ");
            foreach ($rows as $row) {
                $id        = (int)$row['id'];
                $batchName = trim($row['batch_name'] ?? '');
                $sup       = trim($row['supplier_name'] ?? '');
                $label     = 'خلطة مكسرات' . ($batchName !== '' ? ': ' . $batchName : ' #' . $id);
                if ($sup !== '') $label .= ' - ' . $sup;
                addRawMaterial($label, 'mixed_nuts', $id, 'المكسرات', $templates, $templatesDetailed, $seenNames);
            }
        }

        // 5. السمسم — كل صف له مورد
        if (!empty($db->queryOne("SHOW TABLES LIKE 'sesame_stock'"))) {
            $rows = $db->query("
                SELECT ss.id, s.name AS supplier_name
                FROM sesame_stock ss
                LEFT JOIN suppliers s ON ss.supplier_id = s.id
                ORDER BY s.name ASC
            ");
            foreach ($rows as $row) {
                $id    = (int)$row['id'];
                $sup   = trim($row['supplier_name'] ?? '');
                $label = $sup !== '' ? 'سمسم - ' . $sup : 'سمسم #' . $id;
                addRawMaterial($label, 'sesame_stock', $id, 'السمسم', $templates, $templatesDetailed, $seenNames);
            }
        }

        // 5b. الطحينة (نفس قسم السمسم)
        if (!empty($db->queryOne("SHOW TABLES LIKE 'tahini_stock'"))) {
            $rows = $db->query("
                SELECT ts.id, s.name AS supplier_name
                FROM tahini_stock ts
                LEFT JOIN suppliers s ON ts.supplier_id = s.id
                ORDER BY s.name ASC
            ");
            foreach ($rows as $row) {
                $id    = (int)$row['id'];
                $sup   = trim($row['supplier_name'] ?? '');
                $label = $sup !== '' ? 'طحينة - ' . $sup : 'طحينة #' . $id;
                addRawMaterial($label, 'tahini_stock', $id, 'السمسم', $templates, $templatesDetailed, $seenNames);
            }
        }

        // 6. البلح — نوع + مورد
        if (!empty($db->queryOne("SHOW TABLES LIKE 'date_stock'"))) {
            $rows = $db->query("
                SELECT ds.id, ds.date_type, s.name AS supplier_name
                FROM date_stock ds
                LEFT JOIN suppliers s ON ds.supplier_id = s.id
                ORDER BY ds.date_type ASC, s.name ASC
            ");
            foreach ($rows as $row) {
                $id    = (int)$row['id'];
                $type  = trim($row['date_type'] ?? 'غير محدد');
                $sup   = trim($row['supplier_name'] ?? '');
                $label = $type !== '' ? $type : 'بلح #' . $id;
                if ($sup !== '') $label .= ' - ' . $sup;
                addRawMaterial($label, 'date_stock', $id, 'البلح', $templates, $templatesDetailed, $seenNames);
            }
        }

        // 7. التلبينات — الجدول الفعلي هو turbines_stock (بالجمع)
        $turbineTable = !empty($db->queryOne("SHOW TABLES LIKE 'turbines_stock'")) ? 'turbines_stock'
                      : (!empty($db->queryOne("SHOW TABLES LIKE 'turbine_stock'"))  ? 'turbine_stock' : '');
        if ($turbineTable !== '') {
            $typeCol = !empty($db->queryOne("SHOW COLUMNS FROM `{$turbineTable}` LIKE 'turbine_type'")) ? 'turbine_type' : 'type';
            $rows = $db->query("
                SELECT ts.id, ts.{$typeCol} AS turbine_type, s.name AS supplier_name
                FROM `{$turbineTable}` ts
                LEFT JOIN suppliers s ON ts.supplier_id = s.id
                ORDER BY ts.{$typeCol} ASC, s.name ASC
            ");
            foreach ($rows as $row) {
                $id    = (int)$row['id'];
                $type  = trim($row['turbine_type'] ?? 'غير محدد');
                $sup   = trim($row['supplier_name'] ?? '');
                $label = $type !== '' ? $type : 'تلبينة #' . $id;
                if ($sup !== '') $label .= ' - ' . $sup;
                addRawMaterial($label, $turbineTable, $id, 'التلبينات', $templates, $templatesDetailed, $seenNames);
            }
        }

        // 8. العطارة — نوع + مورد
        if (!empty($db->queryOne("SHOW TABLES LIKE 'herbal_stock'"))) {
            $rows = $db->query("
                SELECT hs.id, hs.herbal_type, s.name AS supplier_name
                FROM herbal_stock hs
                LEFT JOIN suppliers s ON hs.supplier_id = s.id
                ORDER BY hs.herbal_type ASC, s.name ASC
            ");
            foreach ($rows as $row) {
                $id    = (int)$row['id'];
                $type  = trim($row['herbal_type'] ?? 'غير محدد');
                $sup   = trim($row['supplier_name'] ?? '');
                $label = $type !== '' ? $type : 'عطارة #' . $id;
                if ($sup !== '') $label .= ' - ' . $sup;
                addRawMaterial($label, 'herbal_stock', $id, 'العطاره', $templates, $templatesDetailed, $seenNames);
            }
        }
    } catch (Exception $e) {
        error_log('Error fetching raw materials for product templates: ' . $e->getMessage());
    }

    // ترتيب أبجدياً
    sort($templates);
    $templates = array_values($templates);

    // ترتيب التفاصيل أبجدياً بالاسم
    usort($templatesDetailed, function($a, $b) {
        return strcmp($a['name'], $b['name']);
    });

    echo json_encode([
        'success' => true,
        'templates' => $templates,
        'templates_detailed' => $templatesDetailed
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    error_log('Get product templates API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'templates' => [],
        'templates_detailed' => []
    ], JSON_UNESCAPED_UNICODE);
}
