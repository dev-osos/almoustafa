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
