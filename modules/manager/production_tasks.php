<?php
/**
 * صفحة إرسال المهام لقسم الإنتاج
 */


$isGetTaskForEdit = ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_task_for_edit' && isset($_GET['task_id']);
$isGetTaskReceipt = ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_task_receipt' && isset($_GET['task_id']);
$isDraftAjaxAction = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && in_array($_POST['action'] ?? '', ['save_task_draft', 'load_task_draft', 'delete_task_draft'], true);

// منع الكاش عند التبديل بين الأوردرات/الحسابات لضمان عدم رجوع أي كاش قديم
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Expires: 0');
}
if (!$isGetTaskForEdit && !$isGetTaskReceipt && !$isDraftAjaxAction && !headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

// منع انقطاع التحميل على الأجهزة أو الشبكات البطيئة (timeout / memory)
if (function_exists('set_time_limit')) {
    @set_time_limit(120);
}
if (function_exists('ini_set')) {
    $cur = @ini_get('memory_limit');
    if ($cur !== false && $cur !== '-1') {
        $curBytes = $cur === '' ? 0 : (int) $cur;
        $suffix = strtolower(substr(trim($cur), -1));
        if ($suffix === 'g') $curBytes *= 1024 * 1024 * 1024;
        elseif ($suffix === 'm') $curBytes *= 1024 * 1024;
        elseif ($suffix === 'k') $curBytes *= 1024;
        if ($curBytes > 0 && $curBytes < 256 * 1024 * 1024) {
            @ini_set('memory_limit', '256M');
        }
    }
}

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/table_styles.php';

if (!function_exists('getTasksRetentionLimit')) {
    function getTasksRetentionLimit(): int {
        if (defined('TASKS_RETENTION_MAX_ROWS')) {
            $value = (int) TASKS_RETENTION_MAX_ROWS;
            if ($value > 0) {
                return $value;
            }
        }
        return 100;
    }
}

if (!function_exists('enforceTasksRetentionLimit')) {
    function enforceTasksRetentionLimit($dbInstance = null, int $maxRows = 100) {
        $maxRows = (int) $maxRows;
        if ($maxRows < 1) {
            $maxRows = 100;
        }

        try {
            if ($dbInstance === null) {
                $dbInstance = db();
            }

            if (!$dbInstance) {
                return false;
            }

            $totalRow = $dbInstance->queryOne("SELECT COUNT(*) AS total FROM tasks");
            $total = isset($totalRow['total']) ? (int) $totalRow['total'] : 0;

            if ($total <= $maxRows) {
                return true;
            }

            $toDelete = $total - $maxRows;
            $batchSize = 100;

            while ($toDelete > 0) {
                $currentBatch = min($batchSize, $toDelete);

                // حذف المهام الأقدم فقط، مع استثناء المهام المُنشأة في آخر دقيقة لمنع حذف المهام الجديدة
                $oldest = $dbInstance->query(
                    "SELECT id FROM tasks 
                     WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 MINUTE)
                     ORDER BY created_at ASC, id ASC 
                     LIMIT ?",
                    [$currentBatch]
                );

                if (empty($oldest)) {
                    break;
                }

                $ids = array_map('intval', array_column($oldest, 'id'));
                $ids = array_filter($ids, static function ($id) {
                    return $id > 0;
                });

                if (empty($ids)) {
                    break;
                }

                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $dbInstance->execute(
                    "DELETE FROM tasks WHERE id IN ($placeholders)",
                    $ids
                );

                $deleted = count($ids);
                $toDelete -= $deleted;

                if ($deleted < $currentBatch) {
                    break;
                }
            }

            return true;
        } catch (Throwable $e) {
            error_log('Tasks retention enforce error: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('managerEnsureTasksStatusEnum')) {
    function managerEnsureTasksStatusEnum($db): void
    {
        try {
            $statusCol = $db->queryOne("SHOW COLUMNS FROM tasks LIKE 'status'");
            $statusType = strtolower((string) ($statusCol['Type'] ?? ''));
            // إذا كان العمود VARCHAR فهو يقبل أي قيمة — لا حاجة لتعديل
            if ($statusType === '' || strpos($statusType, 'varchar') !== false) {
                return;
            }
            // العمود ENUM — تحقق إن كانت القيمة الجديدة موجودة
            if (stripos($statusType, 'with_shipping_company') === false) {
                try {
                    $db->execute("ALTER TABLE tasks MODIFY COLUMN status ENUM('pending','in_progress','completed','with_delegate','with_driver','with_shipping_company','delivered','returned','cancelled') DEFAULT 'pending'");
                } catch (Throwable $enumError) {
                    // فشل تعديل ENUM — تحويل إلى VARCHAR يقبل أي قيمة
                    error_log('managerEnsureTasksStatusEnum ENUM alter failed, converting to VARCHAR: ' . $enumError->getMessage());
                    try {
                        $db->execute("ALTER TABLE tasks MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'pending'");
                    } catch (Throwable $varcharError) {
                        error_log('managerEnsureTasksStatusEnum VARCHAR alter also failed: ' . $varcharError->getMessage());
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('managerEnsureTasksStatusEnum error: ' . $e->getMessage());
        }
    }
}

requireRole(['manager', 'accountant', 'developer', 'sales']);

$db = db();
$currentUser = getCurrentUser();
$error = '';
$success = '';
$tasksRetentionLimit = getTasksRetentionLimit();

// تحديد نوع المستخدم
$isAccountant = ($currentUser['role'] ?? '') === 'accountant';
$isManager = ($currentUser['role'] ?? '') === 'manager';
$isDeveloper = ($currentUser['role'] ?? '') === 'developer';
$isSales = ($currentUser['role'] ?? '') === 'sales';
$canPrintTasks = $isAccountant || $isManager || $isDeveloper;

// جلب سجل الأسعار السابقة لمنتج معين لعميل معين (API endpoint)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_customer_price_history' && ($isAccountant || $isManager || $isDeveloper)) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    ob_start();
    try {
        $customerId  = isset($_GET['customer_id'])  ? intval($_GET['customer_id'])               : 0;
        $productName = isset($_GET['product_name']) ? trim((string)$_GET['product_name'])        : '';
        header('Content-Type: application/json; charset=utf-8');
        if ($customerId <= 0 || $productName === '') {
            echo json_encode(['success' => false, 'suggestions' => []], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $tasks = $db->query(
            "SELECT id, created_at, notes FROM tasks WHERE local_customer_id = ? ORDER BY created_at DESC LIMIT 40",
            [$customerId]
        );
        $suggestions = [];
        $seen = [];
        foreach ($tasks as $task) {
            $notes = (string)($task['notes'] ?? '');
            if (!preg_match('/(?:\[PRODUCTS_JSON\]|المنتجات)\s*:\s*(\[.+?\])(?=\s*\n|\[ASSIGNED_WORKERS_IDS\]|$)/su', $notes, $m)) continue;
            $decoded = json_decode(trim($m[1]), true);
            if (!is_array($decoded)) continue;
            foreach ($decoded as $p) {
                $pName = trim((string)($p['name'] ?? ''));
                if ($pName === '') continue;
                // مطابقة جزئية (المنتج المطلوب يحتوي على اسم المنتج المخزن أو العكس)
                if (mb_stripos($pName, $productName) === false && mb_stripos($productName, $pName) === false) continue;
                $price = (isset($p['price']) && is_numeric($p['price'])) ? (float)$p['price'] : null;
                $unit  = trim((string)($p['unit'] ?? 'قطعة')) ?: 'قطعة';
                if ($price === null || $price <= 0) continue;
                $key = round($price, 2) . '_' . $unit;
                if (isset($seen[$key])) continue;
                $seen[$key]  = true;
                $suggestions[] = [
                    'price'   => $price,
                    'unit'    => $unit,
                    'date'    => date('Y-m-d', strtotime($task['created_at'])),
                    'task_id' => (int)$task['id'],
                ];
                if (count($suggestions) >= 5) break 2;
            }
        }
        echo json_encode(['success' => true, 'suggestions' => $suggestions], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Exception $e) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => $e->getMessage(), 'suggestions' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// إرسال أول بايت للمتصفح فوراً (خاصة كروم) لتفادي "This site can't be reached" بسبب تأخر الاستجابة
if (ob_get_level() && function_exists('flush')) {
    @flush();
}

// جلب القوالب (templates) لعرضها في القائمة المنسدلة - بدون SHOW TABLES
$productTemplates = [];
$_ptHasUnifiedTemplates = false;
$_ptHasProductTemplates = false;
try {
    $productTemplates = $db->query("
        SELECT DISTINCT product_name
        FROM unified_product_templates
        WHERE status = 'active'
        ORDER BY product_name ASC
    ");
    $_ptHasUnifiedTemplates = true;
} catch (Exception $e) {
    // الجدول غير موجود
}
if (empty($productTemplates)) {
    try {
        $productTemplates = $db->query("
            SELECT DISTINCT product_name
            FROM product_templates
            WHERE status = 'active'
            ORDER BY product_name ASC
        ");
        $_ptHasProductTemplates = true;
    } catch (Exception $e) {
        // الجدول غير موجود
    }
} else {
    // تحقق إن كان product_templates موجوداً أيضاً (للاستخدام لاحقاً)
    try {
        $db->queryOne("SELECT 1 FROM product_templates LIMIT 1");
        $_ptHasProductTemplates = true;
    } catch (Exception $e) {}
}

// تحميل تصنيفات شرينك من qu.json (لحقل التصنيف والكمية الفعلية للخصم)
$quDataForTask = [];
$quCategoriesForTask = [];
$quJsonPath = defined('ROOT_PATH') ? (rtrim(ROOT_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'qu.json') : (__DIR__ . '/../../qu.json');
if (is_readable($quJsonPath)) {
    $quRaw = @file_get_contents($quJsonPath);
    if ($quRaw !== false) {
        $decoded = @json_decode($quRaw, true);
        if (!empty($decoded['t']) && is_array($decoded['t'])) {
            $quDataForTask = $decoded['t'];
            foreach ($quDataForTask as $item) {
                if (!empty($item['type'])) {
                    $quCategoriesForTask[] = [
                        'type' => trim((string)$item['type']),
                        'quantity' => isset($item['quantity']) ? (float)$item['quantity'] : 1,
                        'description' => isset($item['description']) ? trim((string)$item['description']) : '',
                    ];
                }
            }
        }
    }
}

// جلب قوالب المنتجات مع الكميات المتاحة (نفس استعلام company_products.php تماماً)
$productTemplatesForTask = [];
try {
    $hasFPTable = !empty($db->queryOne("SHOW TABLES LIKE 'finished_products'"));
    $ptExists   = !empty($db->queryOne("SHOW TABLES LIKE 'product_templates'"));
    if ($ptExists) {
        if ($hasFPTable) {
            $productTemplatesForTask = $db->query("
                SELECT pt.id, pt.product_name,
                       COALESCE((
                           SELECT SUM(fp2.quantity_produced)
                           FROM finished_products fp2
                           LEFT JOIN products pr2 ON fp2.product_id = pr2.id
                           WHERE (
                               TRIM(fp2.product_name) = TRIM(pt.product_name)
                               OR TRIM(COALESCE(NULLIF(fp2.product_name,''), pr2.name)) = TRIM(pt.product_name)
                           )
                           AND fp2.quantity_produced > 0
                       ), 0) AS available_qty
                FROM product_templates pt
                WHERE pt.status = 'active'
                ORDER BY pt.product_name ASC
            ");
        } else {
            $productTemplatesForTask = $db->query("
                SELECT pt.id, pt.product_name,
                       COALESCE((SELECT SUM(p.quantity) FROM products p WHERE p.name = pt.product_name AND p.status = 'active' AND (p.product_type = 'internal' OR p.product_type IS NULL)), 0) AS available_qty
                FROM product_templates pt
                WHERE pt.status = 'active'
                ORDER BY pt.product_name ASC
            ");
        }
    }
} catch (Exception $e) {
    error_log('Error fetching product templates for task form: ' . $e->getMessage());
}

// جلب قائمة العملاء المحليين
$localCustomersForDropdown = [];
try {
    // migration checks تعمل مرة واحدة فقط في الجلسة
    if (empty($_SESSION['_pt_migrated_local_customers'])) {
        $t = $db->queryOne("SHOW TABLES LIKE 'local_customers'");
        if (!empty($t)) {
            $hasTgGov = $db->queryOne("SHOW COLUMNS FROM local_customers LIKE 'tg_governorate'");
            if (empty($hasTgGov)) {
                $db->execute("ALTER TABLE local_customers ADD COLUMN tg_governorate VARCHAR(100) DEFAULT NULL AFTER address");
                $db->execute("ALTER TABLE local_customers ADD COLUMN tg_gov_id INT DEFAULT NULL AFTER tg_governorate");
                $db->execute("ALTER TABLE local_customers ADD COLUMN tg_city VARCHAR(100) DEFAULT NULL AFTER tg_gov_id");
                $db->execute("ALTER TABLE local_customers ADD COLUMN tg_city_id INT DEFAULT NULL AFTER tg_city");
            }
        }
        $_SESSION['_pt_migrated_local_customers'] = 1;
    }
    try {
        $rows = $db->query("SELECT id, name, address, tg_governorate, tg_gov_id, tg_city, tg_city_id, phone FROM local_customers WHERE status = 'active' ORDER BY name ASC");
    } catch (Exception $e) {
        // phone column might not exist
        $rows = $db->query("SELECT id, name, address, tg_governorate, tg_gov_id, tg_city, tg_city_id FROM local_customers WHERE status = 'active' ORDER BY name ASC");
    }
    if (!empty($rows)) {
        foreach ($rows as $r) {
            $localCustomersForDropdown[] = [
                'id'             => (int)$r['id'],
                'name'           => trim((string)($r['name'] ?? '')),
                'phone'          => trim((string)($r['phone'] ?? '')),
                'phones'         => [],
                'address'        => trim((string)($r['address'] ?? '')),
                'tg_governorate' => trim((string)($r['tg_governorate'] ?? '')),
                'tg_gov_id'      => $r['tg_gov_id'] ? (int)$r['tg_gov_id'] : null,
                'tg_city'        => trim((string)($r['tg_city'] ?? '')),
                'tg_city_id'     => $r['tg_city_id'] ? (int)$r['tg_city_id'] : null,
            ];
        }
    }
} catch (Throwable $e) {
    error_log('production_tasks local_customers: ' . $e->getMessage());
    $localCustomersForDropdown = [];
}

// قائمة عملاء المندوبين (مثل صفحة الأسعار المخصصة)
$repCustomersForTask = [];
try {
    if ($isSales) {
        // المندوب يرى عملاءه فقط
        $repCustomersForTask = $db->query("
            SELECT c.id, c.name, c.phone,
                   ? AS rep_name
            FROM customers c
            WHERE c.status = 'active'
              AND (c.rep_id = ? OR c.created_by = ?)
            ORDER BY c.name ASC
            LIMIT 500
        ", [$currentUser['full_name'] ?? '', $currentUser['id'], $currentUser['id']]);
    } else {
        $repCustomersForTask = $db->query("
            SELECT c.id, c.name, c.phone,
                   COALESCE(rep1.full_name, rep2.full_name) AS rep_name
            FROM customers c
            LEFT JOIN users rep1 ON c.rep_id = rep1.id AND rep1.role = 'sales'
            LEFT JOIN users rep2 ON c.created_by = rep2.id AND rep2.role = 'sales'
            WHERE c.status = 'active'
              AND ((c.rep_id IS NOT NULL AND c.rep_id IN (SELECT id FROM users WHERE role = 'sales'))
                   OR (c.created_by IS NOT NULL AND c.created_by IN (SELECT id FROM users WHERE role = 'sales')))
            ORDER BY c.name ASC
            LIMIT 500
        ");
    }
    $repCustomersForTask = array_map(function ($r) {
        return [
            'id' => (int)$r['id'],
            'name' => trim((string)($r['name'] ?? '')),
            'phone' => trim((string)($r['phone'] ?? '')),
            'rep_name' => trim((string)($r['rep_name'] ?? '')),
        ];
    }, $repCustomersForTask);
} catch (Throwable $e) {
    error_log('production_tasks rep customers: ' . $e->getMessage());
    $repCustomersForTask = [];
}

// قائمة شركات الشحن (لاعتماد الفاتورة عند نوع الأوردر تليجراف/شركة شحن)
$shippingCompaniesForDropdown = [];
try {
    $rows = $db->query("SELECT id, name FROM shipping_companies WHERE status = 'active' ORDER BY name ASC");
    foreach ($rows ?: [] as $r) {
        $shippingCompaniesForDropdown[] = ['id' => (int)$r['id'], 'name' => trim((string)($r['name'] ?? ''))];
    }
} catch (Throwable $e) {
    // الجدول أو العمود غير موجود
}

/**
 * Migration checks - تعمل مرة واحدة فقط في الجلسة لتجنب ~20 استعلام SHOW على كل تحميل صفحة
 */
$hasStatusChangedBy = false;
$columns = array_column($db->query("SHOW COLUMNS FROM tasks") ?: [], 'Field');
$columnsMap = array_flip($columns);
$hasStatusChangedBy = isset($columnsMap['status_changed_by']);
managerEnsureTasksStatusEnum($db);

if (empty($_SESSION['_pt_migrations_done'])) {
    try {
        $tableCheck = $db->queryOne("SHOW TABLES LIKE 'tasks'");
        if (empty($tableCheck)) {
            $db->execute("
                CREATE TABLE IF NOT EXISTS `tasks` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `title` varchar(255) NOT NULL,
                  `description` text DEFAULT NULL,
                  `assigned_to` int(11) DEFAULT NULL,
                  `created_by` int(11) NOT NULL,
                  `priority` enum('low','normal','high','urgent') DEFAULT 'normal',
                  `status` enum('pending','received','in_progress','completed','delivered','returned','cancelled') DEFAULT 'pending',
                  `due_date` date DEFAULT NULL,
                  `completed_at` timestamp NULL DEFAULT NULL,
                  `received_at` timestamp NULL DEFAULT NULL,
                  `started_at` timestamp NULL DEFAULT NULL,
                  `related_type` varchar(50) DEFAULT NULL,
                  `related_id` int(11) DEFAULT NULL,
                  `product_id` int(11) DEFAULT NULL,
                  `quantity` decimal(10,2) DEFAULT NULL,
                  `notes` text DEFAULT NULL,
                  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `assigned_to` (`assigned_to`),
                  KEY `created_by` (`created_by`),
                  KEY `status` (`status`),
                  KEY `priority` (`priority`),
                  KEY `due_date` (`due_date`),
                  KEY `product_id` (`product_id`),
                  CONSTRAINT `tasks_ibfk_1` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
                  CONSTRAINT `tasks_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
                  CONSTRAINT `tasks_ibfk_3` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        // إضافة الأعمدة الناقصة دفعة واحدة
        $columns = array_column($db->query("SHOW COLUMNS FROM tasks") ?: [], 'Field');
        $columnsMap = array_flip($columns);

        $hasStatusChangedBy = isset($columnsMap['status_changed_by']);

        if (!isset($columnsMap['template_id'])) {
            $db->execute("ALTER TABLE tasks ADD COLUMN template_id int(11) NULL AFTER product_id");
            try { $db->execute("ALTER TABLE tasks ADD KEY template_id (template_id)"); } catch (Exception $e) {}
        }
        if (!isset($columnsMap['product_name'])) {
            $db->execute("ALTER TABLE tasks ADD COLUMN product_name VARCHAR(255) NULL AFTER template_id");
        }
        if (!isset($columnsMap['unit'])) {
            $db->execute("ALTER TABLE tasks ADD COLUMN unit VARCHAR(50) NULL DEFAULT 'قطعة' AFTER quantity");
        }
        if (!isset($columnsMap['customer_name'])) {
            $db->execute("ALTER TABLE tasks ADD COLUMN customer_name VARCHAR(255) NULL AFTER unit");
        }
        if (!isset($columnsMap['customer_phone'])) {
            $db->execute("ALTER TABLE tasks ADD COLUMN customer_phone VARCHAR(50) NULL AFTER customer_name");
        }
        if (!isset($columnsMap['receipt_print_count'])) {
            $db->execute("ALTER TABLE tasks ADD COLUMN receipt_print_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER notes");
        }
        if (!isset($columnsMap['local_customer_id'])) {
            $db->execute("ALTER TABLE tasks ADD COLUMN local_customer_id INT(11) NULL AFTER customer_phone");
        }
        if (!isset($columnsMap['total_amount'])) {
            $db->execute("ALTER TABLE tasks ADD COLUMN total_amount DECIMAL(15,2) NULL AFTER local_customer_id");
        }
        if (!isset($columnsMap['updated_at'])) {
            $db->execute("ALTER TABLE tasks ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL AFTER created_at");
        }
        if (!isset($columnsMap['status_changed_by'])) {
            $db->execute("ALTER TABLE tasks ADD COLUMN status_changed_by INT(11) NULL AFTER updated_at");
        }
    } catch (Exception $e) {
        error_log('Manager task page migration error: ' . $e->getMessage());
    }

    // جدول سجل مشتريات الأوردرات المعتمدة
    try {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `customer_task_purchases` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `local_customer_id` int(11) NOT NULL,
              `task_id` int(11) NOT NULL,
              `task_number` varchar(100) NOT NULL,
              `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
              `task_date` date NOT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `task_id_unique` (`task_id`),
              KEY `local_customer_id` (`local_customer_id`),
              KEY `task_date` (`task_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {}

    // أعمدة إضافية لجدول الفواتير الورقية
    try {
        $t = $db->queryOne("SHOW TABLES LIKE 'shipping_company_paper_invoices'");
        if (!empty($t)) {
            $shipCols = array_column($db->query("SHOW COLUMNS FROM shipping_company_paper_invoices") ?: [], 'Field');
            $shipColsMap = array_flip($shipCols);
            if (!isset($shipColsMap['net_amount'])) {
                $db->execute("ALTER TABLE shipping_company_paper_invoices ADD COLUMN net_amount DECIMAL(15,2) NULL COMMENT 'صافي سعر الطرد' AFTER total_amount");
            }
            if (!isset($shipColsMap['task_id'])) {
                $db->execute("ALTER TABLE shipping_company_paper_invoices ADD COLUMN task_id INT(11) NULL COMMENT 'ربط بأوردر الإنتاج' AFTER net_amount");
                $db->execute("ALTER TABLE shipping_company_paper_invoices ADD UNIQUE KEY task_id_unique (task_id)");
            }
        }
    } catch (Exception $e) {}

    $_SESSION['_pt_migrations_done'] = 1;
}

// إنشاء جدول المسودات بشكل مستقل (خارج session-gate لضمان التنفيذ دائماً)
if (empty($_SESSION['_pt_drafts_table_done'])) {
    try {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `task_drafts` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `created_by` int(11) NOT NULL,
              `draft_name` varchar(255) NULL,
              `draft_data` longtext NOT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `created_by` (`created_by`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $_SESSION['_pt_drafts_table_done'] = 1;
    } catch (Exception $e) {
        error_log('task_drafts migration error: ' . $e->getMessage());
    }
}

/**
 * حساب الإجمالي النهائي للأوردر كما في إيصال الأوردر (من المنتجات + رسوم الشحن - الخصم)
 */
function getTaskReceiptTotalFromNotes($notes)
{
    $notes = (string)$notes;
    $products = [];
    if (preg_match('/(?:\[PRODUCTS_JSON\]|المنتجات)\s*:\s*(\[.+?\])(?=\s*\n|\[ASSIGNED_WORKERS_IDS\]|$)/su', $notes, $m)) {
        $decoded = json_decode(trim($m[1]), true);
        if (is_array($decoded)) {
            $products = $decoded;
        }
    }
    $grandTotal = 0.0;
    foreach ($products as $p) {
        $lineTotal = null;
        if (isset($p['line_total']) && $p['line_total'] !== '' && $p['line_total'] !== null && is_numeric($p['line_total'])) {
            $lineTotal = (float)$p['line_total'];
        } elseif (isset($p['quantity']) && (float)$p['quantity'] > 0 && isset($p['price']) && ($p['price'] !== '' && $p['price'] !== null) && is_numeric($p['price'])) {
            $lineTotal = round((float)$p['quantity'] * (float)$p['price'], 2);
        }
        if ($lineTotal !== null) {
            $grandTotal += $lineTotal;
        }
    }
    $shipping = 0.0;
    if (preg_match('/\[SHIPPING_FEES\]:\s*([0-9.]+)/', $notes, $m)
        || preg_match('/رسوم\s*الشحن\s*:\s*([0-9.]+)/u', $notes, $m)) {
        $shipping = (float)$m[1];
    }
    $discount = 0.0;
    if (preg_match('/\[DISCOUNT\]:\s*([0-9.]+)/', $notes, $m)
        || preg_match('/الخصم\s*:\s*([0-9.]+)/u', $notes, $m)) {
        $discount = (float)$m[1];
    }
    $advancePayment = 0.0;
    if (preg_match('/\[ADVANCE_PAYMENT\]:\s*([0-9.]+)/', $notes, $m)) {
        $advancePayment = (float)$m[1];
    }
    return round($grandTotal + $shipping - $discount - $advancePayment, 2);
}

/**
 * حساب الإجمالي النهائي لأوردر تليجراف (يطابق ما يُطبع في الإيصال).
 * الإجمالي = إجمالي المنتجات - تكلفة التوصيل (TelegraphEx)
 */
function getTelegraphReceiptTotal($task, $db)
{
    $notes = (string)($task['notes'] ?? '');
    // حساب إجمالي المنتجات
    $products = [];
    if (preg_match('/(?:\[PRODUCTS_JSON\]|المنتجات)\s*:\s*(\[.+?\])(?=\s*\n|\[ASSIGNED_WORKERS_IDS\]|$)/su', $notes, $m)) {
        $decoded = json_decode(trim($m[1]), true);
        if (is_array($decoded)) $products = $decoded;
    }
    $grandTotal = 0.0;
    foreach ($products as $p) {
        $lineTotal = null;
        if (isset($p['line_total']) && $p['line_total'] !== '' && $p['line_total'] !== null && is_numeric($p['line_total'])) {
            $lineTotal = (float)$p['line_total'];
        } elseif (isset($p['quantity']) && (float)$p['quantity'] > 0 && isset($p['price']) && ($p['price'] !== '' && $p['price'] !== null) && is_numeric($p['price'])) {
            $lineTotal = round((float)$p['quantity'] * (float)$p['price'], 2);
        }
        if ($lineTotal !== null) $grandTotal += $lineTotal;
    }
    // حساب الخصم
    $discount = 0.0;
    if (preg_match('/\[DISCOUNT\]:\s*([0-9.]+)/', $notes, $m2)
        || preg_match('/الخصم\s*:\s*([0-9.]+)/u', $notes, $m2)) {
        $discount = (float)$m2[1];
    }
    // جلب بيانات العميل (المحافظة والمدينة)
    $tgGovId = 0;
    $tgCityId = 0;
    $localCustomerId = isset($task['local_customer_id']) ? (int)$task['local_customer_id'] : 0;
    $localLookup = null;
    if ($localCustomerId > 0) {
        try {
            $localLookup = $db->queryOne("SELECT tg_gov_id, tg_city_id FROM local_customers WHERE id = ? LIMIT 1", [$localCustomerId]);
        } catch (Exception $e) {}
    }
    if (!$localLookup && !empty($task['customer_name'])) {
        try {
            $localLookup = $db->queryOne("SELECT tg_gov_id, tg_city_id FROM local_customers WHERE name = ? LIMIT 1", [trim($task['customer_name'])]);
        } catch (Exception $e) {}
    }
    if ($localLookup) {
        $tgGovId = (int)($localLookup['tg_gov_id'] ?? 0);
        $tgCityId = (int)($localLookup['tg_city_id'] ?? 0);
    }
    // جلب الوزن من notes
    $tgWeight = 1;
    if (preg_match('/\[TG_WEIGHT\]\s*:\s*([^\n]+)/', $notes, $m3)
        || preg_match('/الوزن\s*:\s*([^\n]+)/u', $notes, $m3)) {
        $w = (float)trim((string)($m3[1] ?? 0));
        if ($w > 0) $tgWeight = $w;
    }
    // حساب تكلفة التوصيل عبر TelegraphEx API
    $priceForFees = max(0, $grandTotal - $discount);
    if ($tgGovId <= 0 || $tgCityId <= 0) {
        return round($grandTotal - $discount, 2);
    }
    $calcUrl = getAbsoluteUrl(
        'api/tg_calc_fees.php?price=' . urlencode((string)$priceForFees) .
        '&recipientZoneId=' . urlencode((string)$tgGovId) .
        '&recipientSubzoneId=' . urlencode((string)$tgCityId) .
        '&weight=' . urlencode((string)$tgWeight)
    );
    $raw = @file_get_contents($calcUrl);
    if ($raw === false || $raw === '') {
        return round($grandTotal - $discount, 2);
    }
    $data = json_decode($raw, true);
    $fees = $data['data']['calculateShipmentFees'] ?? null;
    if (!$fees) {
        return round($grandTotal - $discount, 2);
    }
    $deliveryCost = (float)($fees['delivery'] ?? 0) + (float)($fees['weight'] ?? 0) + (float)($fees['collection'] ?? 0);
    $advancePaymentTg = 0.0;
    if (preg_match('/\[ADVANCE_PAYMENT\]:\s*([0-9.]+)/', $notes, $mAdv)) {
        $advancePaymentTg = (float)$mAdv[1];
    }
    return round($grandTotal - $deliveryCost - $advancePaymentTg, 2);
}

/**
 * خصم كميات منتجات الأوردر من المخزون (يُستدعى عند اعتماد الفاتورة فقط).
 * يقرأ [PRODUCTS_JSON] من notes ويخصم effective_quantity من:
 *   1. products.quantity  (منتجات الشركة)
 *   2. مخزن الخامات (عبر القالب: template_raw_materials أو product_template_raw_materials)
 *   3. مخزن أدوات التعبئة (عبر القالب: template_packaging أو product_template_packaging)
 * @param object $db
 * @param string $notes محتوى notes للمهمة
 * @throws InvalidArgumentException عند عدم كفاية الكمية المتاحة
 */
function deductTaskProductsFromStock($db, $notes)
{
    $notes = (string)$notes;
    if ($notes === '') {
        return;
    }
    if (!preg_match('/(?:\[PRODUCTS_JSON\]|المنتجات)\s*:\s*(\[.+?\])(?=\s*\n|\[ASSIGNED_WORKERS_IDS\]|$)/su', $notes, $m)) {
        return;
    }
    $products = json_decode(trim($m[1]), true);
    if (!is_array($products)) {
        return;
    }
    $quJsonPath = defined('ROOT_PATH') ? (rtrim(ROOT_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'qu.json') : (__DIR__ . '/../../qu.json');
    $quData = [];
    if (is_readable($quJsonPath)) {
        $quRaw = @file_get_contents($quJsonPath);
        if ($quRaw !== false) {
            $decoded = @json_decode($quRaw, true);
            if (!empty($decoded['t']) && is_array($decoded['t'])) {
                $quData = $decoded['t'];
            }
        }
    }
    foreach ($products as $product) {
        $effectiveQty = isset($product['effective_quantity']) ? (float)$product['effective_quantity'] : null;
        if ($effectiveQty === null && isset($product['quantity'])) {
            $qty = (float)$product['quantity'];
            $unit = trim($product['unit'] ?? 'قطعة');
            $category = trim($product['category'] ?? '');
            if ($unit === 'شرينك' && $category !== '' && !empty($quData)) {
                foreach ($quData as $it) {
                    $qt = isset($it['type']) ? trim((string)$it['type']) : '';
                    $qd = isset($it['description']) ? trim((string)$it['description']) : '';
                    if ($qt === $category && $qd === 'شرينك') {
                        $multiplier = isset($it['quantity']) ? (float)$it['quantity'] : 1;
                        $effectiveQty = $qty * $multiplier;
                        break;
                    }
                }
            }
            if ($effectiveQty === null) {
                $effectiveQty = $qty;
            }
        }
        if ($effectiveQty === null || $effectiveQty <= 0) {
            continue;
        }
        $name = trim($product['name'] ?? '');
        if ($name === '') {
            continue;
        }

        // ====== 0. خصم من قوالب المنتجات (finished_products) ======
        try {
            $hasFP = !empty($db->queryOne("SHOW TABLES LIKE 'finished_products'"));
            $hasPT = !empty($db->queryOne("SHOW TABLES LIKE 'product_templates'"));
            if ($hasFP && $hasPT) {
                $tpl = $db->queryOne(
                    "SELECT id FROM product_templates WHERE TRIM(product_name) = ? AND status = 'active' LIMIT 1",
                    [$name]
                );
                if ($tpl !== null) {
                    _deductFromRowsWaterfall(
                        $db,
                        "SELECT fp.id, fp.quantity_produced AS qty
                         FROM finished_products fp
                         LEFT JOIN products pr ON fp.product_id = pr.id
                         WHERE (TRIM(fp.product_name) = ?
                                OR TRIM(COALESCE(NULLIF(fp.product_name,''), pr.name)) = ?)
                           AND fp.quantity_produced > 0
                         ORDER BY fp.quantity_produced DESC",
                        [$name, $name],
                        "UPDATE finished_products SET quantity_produced = quantity_produced - ? WHERE id = ?",
                        $effectiveQty
                    );
                    continue;
                }
            }
        } catch (Exception $e) {
            error_log('deductTaskProductsFromStock finished_products error (' . $name . '): ' . $e->getMessage());
        }

        // ====== 1. خصم من منتجات الشركة ======
        try {
            $row = $db->queryOne(
                "SELECT id FROM products WHERE name = ? LIMIT 1 FOR UPDATE",
                [$name]
            );
            if ($row !== null) {
                $db->execute("UPDATE products SET quantity = quantity - ? WHERE id = ?", [$effectiveQty, (int)$row['id']]);
                continue;
            }
        } catch (Exception $e) {
            error_log('deductTaskProductsFromStock products error (' . $name . '): ' . $e->getMessage());
        }

        // ====== 2. خصم من مخزن أدوات التعبئة ======
        try {
            $pkgCheck = $db->queryOne("SHOW TABLES LIKE 'packaging_materials'");
            if (!empty($pkgCheck)) {
                $row = $db->queryOne(
                    "SELECT id FROM packaging_materials WHERE (name = ? OR specifications = ?) AND status = 'active' LIMIT 1 FOR UPDATE",
                    [$name, $name]
                );
                if ($row !== null) {
                    $db->execute("UPDATE packaging_materials SET quantity = quantity - ?, updated_at = NOW() WHERE id = ?", [$effectiveQty, (int)$row['id']]);
                    continue;
                }
            }
        } catch (Exception $e) {
            error_log('deductTaskProductsFromStock packaging error (' . $name . '): ' . $e->getMessage());
        }

        // ====== 3. مخزن الخامات — بحث بالاسم المركب والنوع المستخرج ======
        // الأسماء تأتي بصيغة: "نوع - مورد" مثل "كمون - محمد" أو "جوز #5"
        // نأخذ الجزء الأول قبل " - " لأنه النوع الفعلي
        $rawNameClean = preg_replace('/\s*\([^)]*\)\s*$/u', '', $name); // أزل قوس المورد
        $rawNameClean = preg_replace('/\s*#\d+\s*$/u', '', $rawNameClean); // أزل رقم #
        $rawNameClean = trim($rawNameClean);
        $rawTypeParts = explode(' - ', $rawNameClean, 2);
        $rawType = trim($rawTypeParts[0]); // الجزء الأول = النوع الفعلي

        // عسل خام
        try {
            $hCheck = $db->queryOne("SHOW TABLES LIKE 'honey_stock'");
            if (!empty($hCheck)) {
                // استخراج النوع من "عسل خام - سدر"
                if (mb_strpos($name, 'عسل خام') !== false) {
                    $variety = preg_replace('/^عسل خام\s*-\s*/u', '', $rawNameClean);
                    $variety = trim($variety);
                    $tot = $db->queryOne("SELECT COALESCE(SUM(raw_honey_quantity),0) as t FROM honey_stock WHERE TRIM(honey_variety) = ?", [$variety]);
                    if ($tot && (float)$tot['t'] > 0) {
                        _deductFromRowsWaterfall($db, "SELECT id, raw_honey_quantity as qty FROM honey_stock WHERE TRIM(honey_variety) = ? AND raw_honey_quantity > 0 ORDER BY raw_honey_quantity DESC", [$variety], "UPDATE honey_stock SET raw_honey_quantity = raw_honey_quantity - ? WHERE id = ?", $effectiveQty);
                        continue;
                    }
                }
                // عسل مصفى
                if (mb_strpos($name, 'عسل مصفى') !== false) {
                    $variety = preg_replace('/^عسل مصفى\s*-\s*/u', '', $rawNameClean);
                    $variety = trim($variety);
                    $tot = $db->queryOne("SELECT COALESCE(SUM(filtered_honey_quantity),0) as t FROM honey_stock WHERE TRIM(honey_variety) = ?", [$variety]);
                    if ($tot && (float)$tot['t'] > 0) {
                        _deductFromRowsWaterfall($db, "SELECT id, filtered_honey_quantity as qty FROM honey_stock WHERE TRIM(honey_variety) = ? AND filtered_honey_quantity > 0 ORDER BY filtered_honey_quantity DESC", [$variety], "UPDATE honey_stock SET filtered_honey_quantity = filtered_honey_quantity - ? WHERE id = ?", $effectiveQty);
                        continue;
                    }
                }
                // بحث عام بالنوع المستخرج
                $tot = $db->queryOne("SELECT COALESCE(SUM(raw_honey_quantity),0) as t FROM honey_stock WHERE TRIM(honey_variety) = ?", [$rawType]);
                if ($tot && (float)$tot['t'] > 0) {
                    _deductFromRowsWaterfall($db, "SELECT id, raw_honey_quantity as qty FROM honey_stock WHERE TRIM(honey_variety) = ? AND raw_honey_quantity > 0 ORDER BY raw_honey_quantity DESC", [$rawType], "UPDATE honey_stock SET raw_honey_quantity = raw_honey_quantity - ? WHERE id = ?", $effectiveQty);
                    continue;
                }
                $tot = $db->queryOne("SELECT COALESCE(SUM(filtered_honey_quantity),0) as t FROM honey_stock WHERE TRIM(honey_variety) = ?", [$rawType]);
                if ($tot && (float)$tot['t'] > 0) {
                    _deductFromRowsWaterfall($db, "SELECT id, filtered_honey_quantity as qty FROM honey_stock WHERE TRIM(honey_variety) = ? AND filtered_honey_quantity > 0 ORDER BY filtered_honey_quantity DESC", [$rawType], "UPDATE honey_stock SET filtered_honey_quantity = filtered_honey_quantity - ? WHERE id = ?", $effectiveQty);
                    continue;
                }
            }
        } catch (Exception $e) {
            error_log('deductTaskProductsFromStock honey error (' . $name . '): ' . $e->getMessage());
        }

        // زيت زيتون
        try {
            $oCheck = $db->queryOne("SHOW TABLES LIKE 'olive_oil_stock'");
            if (!empty($oCheck) && (mb_strpos($name, 'زيت') !== false || mb_strpos($name, 'زيتون') !== false)) {
                $tot = $db->queryOne("SELECT COALESCE(SUM(quantity),0) as t FROM olive_oil_stock");
                if ($tot && (float)$tot['t'] > 0) {
                    _deductFromRowsWaterfall($db, "SELECT id, quantity as qty FROM olive_oil_stock WHERE quantity > 0 ORDER BY quantity DESC", [], "UPDATE olive_oil_stock SET quantity = quantity - ? WHERE id = ?", $effectiveQty);
                    continue;
                }
            }
        } catch (Exception $e) {
            error_log('deductTaskProductsFromStock olive_oil error (' . $name . '): ' . $e->getMessage());
        }

        // شمع العسل
        try {
            $bwCheck = $db->queryOne("SHOW TABLES LIKE 'beeswax_stock'");
            if (!empty($bwCheck) && mb_strpos($name, 'شمع') !== false) {
                $tot = $db->queryOne("SELECT COALESCE(SUM(weight),0) as t FROM beeswax_stock");
                if ($tot && (float)$tot['t'] > 0) {
                    _deductFromRowsWaterfall($db, "SELECT id, weight as qty FROM beeswax_stock WHERE weight > 0 ORDER BY weight DESC", [], "UPDATE beeswax_stock SET weight = weight - ? WHERE id = ?", $effectiveQty);
                    continue;
                }
            }
        } catch (Exception $e) {
            error_log('deductTaskProductsFromStock beeswax error (' . $name . '): ' . $e->getMessage());
        }

        // مكسرات (مطابقة بالاسم الكامل ثم بالنوع المستخرج)
        try {
            $nCheck = $db->queryOne("SHOW TABLES LIKE 'nuts_stock'");
            if (!empty($nCheck)) {
                $tot = $db->queryOne("SELECT COALESCE(SUM(quantity),0) as t FROM nuts_stock WHERE TRIM(nut_type) = ?", [$name]);
                if (!$tot || (float)$tot['t'] == 0) {
                    $tot = $db->queryOne("SELECT COALESCE(SUM(quantity),0) as t FROM nuts_stock WHERE TRIM(nut_type) = ?", [$rawType]);
                }
                if ($tot && (float)$tot['t'] > 0) {
                    $searchType = (float)$db->queryOne("SELECT COALESCE(SUM(quantity),0) as t FROM nuts_stock WHERE TRIM(nut_type) = ?", [$name])['t'] > 0 ? $name : $rawType;
                    _deductFromRowsWaterfall($db, "SELECT id, quantity as qty FROM nuts_stock WHERE TRIM(nut_type) = ? AND quantity > 0 ORDER BY quantity DESC", [$searchType], "UPDATE nuts_stock SET quantity = quantity - ? WHERE id = ?", $effectiveQty);
                    continue;
                }
            }
        } catch (Exception $e) {
            error_log('deductTaskProductsFromStock nuts error (' . $name . '): ' . $e->getMessage());
        }

        // خلطة مكسرات
        try {
            $mnCheck = $db->queryOne("SHOW TABLES LIKE 'mixed_nuts'");
            if (!empty($mnCheck) && mb_strpos($name, 'خلطة') !== false) {
                $tot = $db->queryOne("SELECT COALESCE(SUM(total_quantity),0) as t FROM mixed_nuts");
                if ($tot && (float)$tot['t'] > 0) {
                    _deductFromRowsWaterfall($db, "SELECT id, total_quantity as qty FROM mixed_nuts WHERE total_quantity > 0 ORDER BY total_quantity DESC", [], "UPDATE mixed_nuts SET total_quantity = total_quantity - ? WHERE id = ?", $effectiveQty);
                    continue;
                }
            }
        } catch (Exception $e) {
            error_log('deductTaskProductsFromStock mixed_nuts error (' . $name . '): ' . $e->getMessage());
        }

        // سمسم
        try {
            $ssCheck = $db->queryOne("SHOW TABLES LIKE 'sesame_stock'");
            if (!empty($ssCheck) && mb_strpos($name, 'سمسم') !== false) {
                $tot = $db->queryOne("SELECT COALESCE(SUM(quantity),0) as t FROM sesame_stock");
                if ($tot && (float)$tot['t'] > 0) {
                    _deductFromRowsWaterfall($db, "SELECT id, quantity as qty FROM sesame_stock WHERE quantity > 0 ORDER BY quantity DESC", [], "UPDATE sesame_stock SET quantity = quantity - ? WHERE id = ?", $effectiveQty);
                    continue;
                }
            }
        } catch (Exception $e) {
            error_log('deductTaskProductsFromStock sesame error (' . $name . '): ' . $e->getMessage());
        }

        // طحينة
        try {
            $thCheck = $db->queryOne("SHOW TABLES LIKE 'tahini_stock'");
            if (!empty($thCheck) && mb_strpos($name, 'طحينة') !== false) {
                $tot = $db->queryOne("SELECT COALESCE(SUM(quantity),0) as t FROM tahini_stock");
                if ($tot && (float)$tot['t'] > 0) {
                    _deductFromRowsWaterfall($db, "SELECT id, quantity as qty FROM tahini_stock WHERE quantity > 0 ORDER BY quantity DESC", [], "UPDATE tahini_stock SET quantity = quantity - ? WHERE id = ?", $effectiveQty);
                    continue;
                }
            }
        } catch (Exception $e) {
            error_log('deductTaskProductsFromStock tahini error (' . $name . '): ' . $e->getMessage());
        }

        // بلح
        try {
            $dtCheck = $db->queryOne("SHOW TABLES LIKE 'date_stock'");
            if (!empty($dtCheck)) {
                $tot = $db->queryOne("SELECT COALESCE(SUM(quantity),0) as t FROM date_stock WHERE TRIM(date_type) = ?", [$name]);
                if (!$tot || (float)$tot['t'] == 0) {
                    $tot = $db->queryOne("SELECT COALESCE(SUM(quantity),0) as t FROM date_stock WHERE TRIM(date_type) = ?", [$rawType]);
                }
                if ($tot && (float)$tot['t'] > 0) {
                    $dtSearch = (float)$db->queryOne("SELECT COALESCE(SUM(quantity),0) as t FROM date_stock WHERE TRIM(date_type) = ?", [$name])['t'] > 0 ? $name : $rawType;
                    _deductFromRowsWaterfall($db, "SELECT id, quantity as qty FROM date_stock WHERE TRIM(date_type) = ? AND quantity > 0 ORDER BY quantity DESC", [$dtSearch], "UPDATE date_stock SET quantity = quantity - ? WHERE id = ?", $effectiveQty);
                    continue;
                }
            }
        } catch (Exception $e) {
            error_log('deductTaskProductsFromStock date error (' . $name . '): ' . $e->getMessage());
        }

        // تلبينات
        try {
            $turbTable = !empty($db->queryOne("SHOW TABLES LIKE 'turbines_stock'")) ? 'turbines_stock'
                       : (!empty($db->queryOne("SHOW TABLES LIKE 'turbine_stock'")) ? 'turbine_stock' : '');
            if ($turbTable !== '') {
                $typeCol = !empty($db->queryOne("SHOW COLUMNS FROM `{$turbTable}` LIKE 'turbine_type'")) ? 'turbine_type' : 'type';
                $hasQty  = !empty($db->queryOne("SHOW COLUMNS FROM `{$turbTable}` LIKE 'quantity'"));
                if ($hasQty) {
                    $tot = $db->queryOne("SELECT COALESCE(SUM(quantity),0) as t FROM `{$turbTable}` WHERE TRIM(`{$typeCol}`) = ?", [$name]);
                    if (!$tot || (float)$tot['t'] == 0) {
                        $tot = $db->queryOne("SELECT COALESCE(SUM(quantity),0) as t FROM `{$turbTable}` WHERE TRIM(`{$typeCol}`) = ?", [$rawType]);
                    }
                    if ($tot && (float)$tot['t'] > 0) {
                        $tSearch = (float)$db->queryOne("SELECT COALESCE(SUM(quantity),0) as t FROM `{$turbTable}` WHERE TRIM(`{$typeCol}`) = ?", [$name])['t'] > 0 ? $name : $rawType;
                        _deductFromRowsWaterfall($db, "SELECT id, quantity as qty FROM `{$turbTable}` WHERE TRIM(`{$typeCol}`) = ? AND quantity > 0 ORDER BY quantity DESC", [$tSearch], "UPDATE `{$turbTable}` SET quantity = quantity - ? WHERE id = ?", $effectiveQty);
                        continue;
                    }
                }
            }
        } catch (Exception $e) {
            error_log('deductTaskProductsFromStock turbine error (' . $name . '): ' . $e->getMessage());
        }

        // عطارة / أعشاب
        try {
            $hbCheck = $db->queryOne("SHOW TABLES LIKE 'herbal_stock'");
            if (!empty($hbCheck)) {
                $tot = $db->queryOne("SELECT COALESCE(SUM(quantity),0) as t FROM herbal_stock WHERE TRIM(herbal_type) = ?", [$name]);
                if (!$tot || (float)$tot['t'] == 0) {
                    $tot = $db->queryOne("SELECT COALESCE(SUM(quantity),0) as t FROM herbal_stock WHERE TRIM(herbal_type) = ?", [$rawType]);
                }
                if ($tot && (float)$tot['t'] > 0) {
                    $hbSearch = (float)$db->queryOne("SELECT COALESCE(SUM(quantity),0) as t FROM herbal_stock WHERE TRIM(herbal_type) = ?", [$name])['t'] > 0 ? $name : $rawType;
                    _deductFromRowsWaterfall($db, "SELECT id, quantity as qty FROM herbal_stock WHERE TRIM(herbal_type) = ? AND quantity > 0 ORDER BY quantity DESC", [$hbSearch], "UPDATE herbal_stock SET quantity = quantity - ? WHERE id = ?", $effectiveQty);
                    continue;
                }
            }
        } catch (Exception $e) {
            error_log('deductTaskProductsFromStock herbal error (' . $name . '): ' . $e->getMessage());
        }

        // مشتقات
        try {
            $dCheck = $db->queryOne("SHOW TABLES LIKE 'derivatives_stock'");
            if (!empty($dCheck)) {
                $tot = $db->queryOne("SELECT COALESCE(SUM(weight),0) as t FROM derivatives_stock WHERE TRIM(derivative_type) = ?", [$name]);
                if (!$tot || (float)$tot['t'] == 0) {
                    $tot = $db->queryOne("SELECT COALESCE(SUM(weight),0) as t FROM derivatives_stock WHERE TRIM(derivative_type) = ?", [$rawType]);
                }
                if ($tot && (float)$tot['t'] > 0) {
                    $dvSearch = (float)$db->queryOne("SELECT COALESCE(SUM(weight),0) as t FROM derivatives_stock WHERE TRIM(derivative_type) = ?", [$name])['t'] > 0 ? $name : $rawType;
                    _deductFromRowsWaterfall($db, "SELECT id, weight as qty FROM derivatives_stock WHERE TRIM(derivative_type) = ? AND weight > 0 ORDER BY weight DESC", [$dvSearch], "UPDATE derivatives_stock SET weight = weight - ? WHERE id = ?", $effectiveQty);
                    continue;
                }
            }
        } catch (Exception $e) {
            error_log('deductTaskProductsFromStock derivatives error (' . $name . '): ' . $e->getMessage());
        }

        error_log('deductTaskProductsFromStock: المنتج «' . $name . '» غير موجود في أي مخزن');
    }
}

/**
 * خصم كمية من مجموعة صفوف بترتيب الأكبر أولاً (Waterfall)
 * يخصم من كل صف حتى يكتمل المطلوب
 */
function _deductFromRowsWaterfall($db, $selectSql, $selectParams, $updateSql, $neededQty)
{
    $rows = $db->query($selectSql, $selectParams) ?? [];
    $remaining = $neededQty;
    foreach ($rows as $row) {
        if ($remaining <= 0) break;
        $available = (float)$row['qty'];
        if ($available <= 0) continue;
        $deduct = min($remaining, $available);
        $db->execute($updateSql, [$deduct, (int)$row['id']]);
        $remaining -= $deduct;
    }
}

/**
 * تحميل بيانات المستخدمين
 */
$productionUsers = [];

try {
    $productionUsers = $db->query("
        SELECT id, full_name
        FROM users
        WHERE status = 'active' AND role = 'production'
        ORDER BY full_name
    ");
} catch (Exception $e) {
    error_log('Manager task page users query error: ' . $e->getMessage());
}

$allowedTypes = ['shop_order', 'cash_customer', 'telegraph', 'shipping_company'];
$allowedPriorities = ['low', 'normal', 'high', 'urgent'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_production_task') {
        $taskType = $_POST['task_type'] ?? 'shop_order';
        $taskType = in_array($taskType, $allowedTypes, true) ? $taskType : 'shop_order';

        $title = trim($_POST['title'] ?? '');
        $details = trim($_POST['details'] ?? '');
        $orderTitle    = trim($_POST['order_title'] ?? '');
        $tgGovernorate = trim($_POST['tg_governorate'] ?? '');
        $tgGovId       = isset($_POST['tg_gov_id']) && $_POST['tg_gov_id'] !== '' ? (int)$_POST['tg_gov_id'] : null;
        $tgCity        = trim($_POST['tg_city'] ?? '');
        $tgCityId      = isset($_POST['tg_city_id']) && $_POST['tg_city_id'] !== '' ? (int)$_POST['tg_city_id'] : null;
        $tgWeight      = trim($_POST['tg_weight'] ?? '');
        $tgParcelDesc  = trim($_POST['tg_parcel_desc'] ?? '');
        $countedInput  = trim((string)($_POST['tg_pieces_count'] ?? ''));
        $priority = $_POST['priority'] ?? 'normal';
        $priority = in_array($priority, $allowedPriorities, true) ? $priority : 'normal';
        $dueDate = $_POST['due_date'] ?? '';
        $customerName = trim($_POST['customer_name'] ?? '');
        $customerPhone = trim($_POST['customer_phone'] ?? '');
        $localCustomerIdForTask = isset($_POST['local_customer_id']) ? (int)$_POST['local_customer_id'] : 0;
        if ($localCustomerIdForTask <= 0) {
            $localCustomerIdForTask = null;
        }
        $repCustomerIdForTask = isset($_POST['rep_customer_id']) ? (int)$_POST['rep_customer_id'] : 0;
        if ($repCustomerIdForTask <= 0) {
            $repCustomerIdForTask = null;
        }
        $assignees = $_POST['assigned_to'] ?? [];
        $shippingFees = 0;
        if ($taskType !== 'telegraph' && isset($_POST['shipping_fees']) && $_POST['shipping_fees'] !== '') {
            $shippingFees = (float) str_replace(',', '.', (string) $_POST['shipping_fees']);
            if ($shippingFees < 0) $shippingFees = 0;
        }
        $discount = 0;
        if (isset($_POST['discount']) && $_POST['discount'] !== '') {
            $discount = (float) str_replace(',', '.', (string) $_POST['discount']);
            if ($discount < 0) $discount = 0;
        }
        $advancePayment = 0;
        if (isset($_POST['advance_payment']) && $_POST['advance_payment'] !== '') {
            $advancePayment = (float) str_replace(',', '.', (string) $_POST['advance_payment']);
            if ($advancePayment < 0) $advancePayment = 0;
        }
        $autoApproveInvoice = isset($_POST['auto_approve_invoice']) && $_POST['auto_approve_invoice'] == '1';

        // تحميل qu.json لحساب الكمية الفعلية للخصم عند الوحدة = شرينك
        $quDataForDeduction = [];
        $quJsonPathForPost = defined('ROOT_PATH') ? (rtrim(ROOT_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'qu.json') : (__DIR__ . '/../../qu.json');
        if (is_readable($quJsonPathForPost)) {
            $quRaw = @file_get_contents($quJsonPathForPost);
            if ($quRaw !== false) {
                $decoded = @json_decode($quRaw, true);
                if (!empty($decoded['t']) && is_array($decoded['t'])) {
                    $quDataForDeduction = $decoded['t'];
                }
            }
        }

        // الحصول على المنتجات المتعددة
        $products = [];
        if (isset($_POST['products']) && is_array($_POST['products'])) {
            foreach ($_POST['products'] as $productData) {
                $productName = trim($productData['name'] ?? '');
                $productQuantityInput = isset($productData['quantity']) ? trim((string)$productData['quantity']) : '';
                $productCategory = trim($productData['category'] ?? '');
                
                if ($productName === '') {
                    continue; // تخطي المنتجات الفارغة
                }
                
                $productQuantity = null;
                $productUnit = trim($productData['unit'] ?? 'قطعة');
                $allowedUnits = ['قطعة', 'كرتونة', 'عبوة', 'شرينك', 'دسته', 'جرام', 'كيلو'];
                if (!in_array($productUnit, $allowedUnits, true)) {
                    $productUnit = 'قطعة'; // القيمة الافتراضية
                }
                
                // الوحدات التي يجب أن تكون أرقام صحيحة فقط
                $integerUnits = ['كيلو', 'قطعة', 'جرام', 'دسته'];
                $mustBeInteger = in_array($productUnit, $integerUnits, true);
                
                if ($productQuantityInput !== '') {
                    $normalizedQuantity = str_replace(',', '.', $productQuantityInput);
                    if (is_numeric($normalizedQuantity)) {
                        $productQuantity = (float)$normalizedQuantity;
                        
                        // التحقق من أن الكمية رقم صحيح للوحدات المحددة
                        if ($mustBeInteger && $productQuantity != (int)$productQuantity) {
                            $error = 'الكمية يجب أن تكون رقماً صحيحاً للوحدة "' . $productUnit . '".';
                            break;
                        }
                        
                        if ($productQuantity < 0) {
                            $error = 'لا يمكن أن تكون الكمية سالبة.';
                            break;
                        }
                        
                        // تحويل إلى رقم صحيح للوحدات المحددة
                        if ($mustBeInteger) {
                            $productQuantity = (int)$productQuantity;
                        }
                    } else {
                        $error = 'يرجى إدخال كمية صحيحة.';
                        break;
                    }
                }
                
                if ($productQuantity !== null && $productQuantity <= 0) {
                    $productQuantity = null;
                }
                
                // الكمية الفعلية للخصم: إذا الوحدة = شرينك والتصنيف محدد، ضرب الكمية في quantity من qu.json
                $effectiveQuantity = $productQuantity;
                if ($productQuantity !== null && $productUnit === 'شرينك' && $productCategory !== '') {
                    foreach ($quDataForDeduction as $quItem) {
                        $qt = isset($quItem['type']) ? trim((string)$quItem['type']) : '';
                        $qd = isset($quItem['description']) ? trim((string)$quItem['description']) : '';
                        if ($qt === $productCategory && $qd === 'شرينك') {
                            $multiplier = isset($quItem['quantity']) ? (float)$quItem['quantity'] : 1;
                            $effectiveQuantity = $productQuantity * $multiplier;
                            break;
                        }
                    }
                }
                
                $productPrice = null;
                $priceInput = isset($productData['price']) ? trim((string)$productData['price']) : '';
                if ($priceInput !== '' && is_numeric(str_replace(',', '.', $priceInput))) {
                    $productPrice = (float)str_replace(',', '.', $priceInput);
                    if ($productPrice < 0) {
                        $productPrice = null;
                    }
                }
                $productLineTotal = null;
                $lineTotalInput = isset($productData['line_total']) ? trim((string)$productData['line_total']) : '';
                if ($lineTotalInput !== '' && is_numeric(str_replace(',', '.', $lineTotalInput))) {
                    $productLineTotal = (float)str_replace(',', '.', $lineTotalInput);
                    if ($productLineTotal < 0) {
                        $productLineTotal = null;
                    }
                }
                $products[] = [
                    'name' => $productName,
                    'quantity' => $productQuantity,
                    'unit' => $productUnit,
                    'category' => $productCategory !== '' ? $productCategory : null,
                    'effective_quantity' => $effectiveQuantity,
                    'price' => $productPrice,
                    'line_total' => $productLineTotal,
                    'item_type' => trim($productData['item_type'] ?? '')
                ];
            }
        }
        
        // للتوافق مع الكود القديم: إذا لم تكن هناك منتجات في المصفوفة، جرب الحقول القديمة
        if (empty($products)) {
            $productName = trim($_POST['product_name'] ?? '');
            $productQuantityInput = isset($_POST['product_quantity']) ? trim((string)$_POST['product_quantity']) : '';
            
            if ($productName !== '') {
                $productQuantity = null;
                if ($productQuantityInput !== '') {
                    $normalizedQuantity = str_replace(',', '.', $productQuantityInput);
                    if (is_numeric($normalizedQuantity)) {
                        $productQuantity = (float)$normalizedQuantity;
                        if ($productQuantity < 0) {
                            $error = 'لا يمكن أن تكون الكمية سالبة.';
                        }
                    } else {
                        $error = 'يرجى إدخال كمية صحيحة.';
                    }
                }
                
                if ($productQuantity !== null && $productQuantity <= 0) {
                    $productQuantity = null;
                }
                
                if ($productName !== '' && !$error) {
                    $products[] = [
                        'name' => $productName,
                        'quantity' => $productQuantity,
                        'price' => null
                    ];
                }
            }
        }

        if (!is_array($assignees)) {
            $assignees = [$assignees];
        }

        $assignees = array_unique(array_filter(array_map('intval', $assignees)));
        $allowedAssignees = array_map(function ($user) {
            return (int)($user['id'] ?? 0);
        }, $productionUsers);
        $assignees = array_values(array_intersect($assignees, $allowedAssignees));

        $counted = 0;
        if ($countedInput !== '') {
            $normalizedCounted = str_replace(',', '.', $countedInput);
            if (!is_numeric($normalizedCounted) || (float)$normalizedCounted <= 0) {
                $error = 'عدد القطع يجب أن يكون أكبر من صفر.';
            } else {
                $counted = max(1, (int)ceil((float)$normalizedCounted));
            }
        }
        if ($counted <= 0) {
            $autoPiecesCount = 0;
            foreach ($products as $productForCount) {
                $qtyForCount = isset($productForCount['quantity']) ? (float)$productForCount['quantity'] : 0;
                if ($qtyForCount > 0) {
                    $autoPiecesCount += $qtyForCount;
                }
            }
            $counted = max(1, (int)ceil($autoPiecesCount));
        }

        if ($taskType === 'telegraph' && $error === '') {
            $normalizedTgWeight = str_replace(',', '.', $tgWeight);
            $missingTelegraphFields = [];

            if ($customerName === '') $missingTelegraphFields[] = 'اسم العميل';
            if ($customerPhone === '') $missingTelegraphFields[] = 'رقم العميل';
            if ($orderTitle === '') $missingTelegraphFields[] = 'العنوان';
            if ($tgGovernorate === '') $missingTelegraphFields[] = 'المحافظة';
            if ($tgCity === '') $missingTelegraphFields[] = 'المدينة';
            if ($tgWeight === '' || !is_numeric($normalizedTgWeight) || (float)$normalizedTgWeight <= 0) $missingTelegraphFields[] = 'الوزن';
            if ($tgParcelDesc === '') $missingTelegraphFields[] = 'وصف الطرد';

            if (!empty($missingTelegraphFields)) {
                $error = 'في أوردر التليجراف يجب تعبئة الحقول التالية: ' . implode(' - ', $missingTelegraphFields);
            } elseif (($tgGovId ?? 0) <= 0 || ($tgCityId ?? 0) <= 0) {
                $error = 'يرجى اختيار المحافظة والمدينة من القوائم المتاحة.';
            }
        }

        if ($error !== '') {
            // تم ضبط رسالة الخطأ أعلاه (مثل التحقق من الكمية)
        } elseif ($dueDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
            $error = 'صيغة تاريخ الاستحقاق غير صحيحة.';
        } else {
            try {
                $db->beginTransaction();

                // إذا كانت المهمة تحتوي على بيانات عميل غير مسجل في العملاء المحليين، إضافته إلى local_customers
                if ($customerName !== '') {
                    $localCustomersTable = $db->queryOne("SHOW TABLES LIKE 'local_customers'");
                    if (!empty($localCustomersTable)) {
                        $existingLocal = $db->queryOne("SELECT id FROM local_customers WHERE name = ?", [$customerName]);
                        if (empty($existingLocal)) {
                            require_once __DIR__ . '/../../includes/customer_code_generator.php';
                            ensureCustomerUniqueCodeColumn('local_customers');
                            $newCustomerUniqueCode = generateUniqueCustomerCode('local_customers');
                            if ($taskType === 'telegraph') {
                                $insertRes = $db->execute(
                                    "INSERT INTO local_customers (unique_code, name, phone, address, tg_governorate, tg_gov_id, tg_city, tg_city_id, balance, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 'active', ?)",
                                    [
                                        $newCustomerUniqueCode,
                                        $customerName,
                                        $customerPhone !== '' ? $customerPhone : null,
                                        $orderTitle !== '' ? $orderTitle : null,
                                        $tgGovernorate !== '' ? $tgGovernorate : null,
                                        $tgGovId,
                                        $tgCity !== '' ? $tgCity : null,
                                        $tgCityId,
                                        $currentUser['id'] ?? null,
                                    ]
                                );
                                $localCustomerIdForTask = (int)($insertRes['insert_id'] ?? 0);
                            } else {
                                $insertRes = $db->execute(
                                    "INSERT INTO local_customers (unique_code, name, phone, address, balance, status, created_by) VALUES (?, ?, ?, NULL, 0, 'active', ?)",
                                    [
                                        $newCustomerUniqueCode,
                                        $customerName,
                                        $customerPhone !== '' ? $customerPhone : null,
                                        $currentUser['id'] ?? null,
                                    ]
                                );
                                $localCustomerIdForTask = (int)($insertRes['insert_id'] ?? 0);
                            }
                        } elseif ($taskType === 'telegraph') {
                            // تحديث بيانات التليجراف للعميل الموجود
                            $localCustomerIdForTask = (int)($existingLocal['id'] ?? 0);
                            $db->execute(
                                "UPDATE local_customers SET tg_governorate = ?, tg_gov_id = ?, tg_city = ?, tg_city_id = ?, address = COALESCE(NULLIF(?, ''), address) WHERE id = ?",
                                [
                                    $tgGovernorate !== '' ? $tgGovernorate : null,
                                    $tgGovId,
                                    $tgCity !== '' ? $tgCity : null,
                                    $tgCityId,
                                    $orderTitle,
                                    (int)$existingLocal['id'],
                                ]
                            );
                        } elseif (!empty($existingLocal)) {
                            // عميل موجود لكن ليس telegraph: فقط اربط task بالعميل
                            $localCustomerIdForTask = (int)($existingLocal['id'] ?? 0);
                        }
                    }
                }

                // إذا كان المندوب وأدخل اسم عميل غير موجود في قائمة عملائه → حفظه في customers
                // لكن فقط إذا لم يكن العميل مسجلاً مسبقاً كعميل محلي (local_customer)
                if ($isSales && $customerName !== '' && $repCustomerIdForTask === null) {
                    // تحقق أولاً إذا كان العميل موجوداً في local_customers
                    $isLocalCustomer = $db->queryOne(
                        "SELECT id FROM local_customers WHERE name = ? LIMIT 1",
                        [$customerName]
                    );
                    if (empty($isLocalCustomer)) {
                        $existingRepCustomer = $db->queryOne(
                            "SELECT id FROM customers WHERE name = ? AND (rep_id = ? OR created_by = ?) LIMIT 1",
                            [$customerName, $currentUser['id'], $currentUser['id']]
                        );
                        if (empty($existingRepCustomer)) {
                            $db->execute(
                                "INSERT INTO customers (name, phone, rep_id, created_by, status) VALUES (?, ?, ?, ?, 'active')",
                                [
                                    $customerName,
                                    $customerPhone !== '' ? $customerPhone : null,
                                    $currentUser['id'],
                                    $currentUser['id'],
                                ]
                            );
                            $repCustomerIdForTask = (int)$db->getLastInsertId();
                        } else {
                            $repCustomerIdForTask = (int)$existingRepCustomer['id'];
                        }
                    }
                }

                $relatedTypeValue = 'manager_' . $taskType;

                if ($title === '') {
                    $typeLabels = [
                        'shop_order' => 'اوردر محل',
                        'cash_customer' => 'عميل نقدي',
                        'telegraph' => 'تليجراف',
                        'shipping_company' => 'شركة شحن'
                    ];
                    $title = $typeLabels[$taskType] ?? 'مهمة جديدة';
                }

                // الحصول على أسماء العمال المختارين
                $assigneeNames = [];
                foreach ($assignees as $assignedId) {
                    foreach ($productionUsers as $user) {
                        if ((int)$user['id'] === $assignedId) {
                            $assigneeNames[] = $user['full_name'];
                            break;
                        }
                    }
                }

                // لا يتم خصم الكمية من المخزون هنا — يتم الخصم عند اعتماد الفاتورة فقط

                // إنشاء مهمة واحدة فقط مع حفظ جميع العمال
                $columns = ['title', 'description', 'created_by', 'priority', 'status', 'related_type', 'status_changed_by'];
                $values = [$title, $details ?: null, $currentUser['id'], $priority, 'pending', $relatedTypeValue, $currentUser['id']];
                $placeholders = ['?', '?', '?', '?', '?', '?', '?'];

                if (!$hasStatusChangedBy) {
                    array_pop($columns);
                    array_pop($values);
                    array_pop($placeholders);
                }

                // وضع أول عامل في assigned_to للتوافق مع الكود الحالي
                $firstAssignee = !empty($assignees) ? (int)$assignees[0] : 0;
                if ($firstAssignee > 0) {
                    $columns[] = 'assigned_to';
                    $values[] = $firstAssignee;
                    $placeholders[] = '?';
                }

                if ($dueDate) {
                    $columns[] = 'due_date';
                    $values[] = $dueDate;
                    $placeholders[] = '?';
                }

                // حفظ المنتجات في notes بصيغة JSON
                $notesParts = [];
                if ($orderTitle !== '') {
                    $notesParts[] = 'عنوان  :' . $orderTitle;
                }
                if ($tgGovernorate !== '') {
                    $notesParts[] = 'المحافظة :' . $tgGovernorate;
                }
                if ($tgCity !== '') {
                    $notesParts[] = 'المدينة :' . $tgCity;
                }
                if ($tgWeight !== '') {
                    $notesParts[] = 'الوزن :' . $tgWeight;
                }
                if ($counted > 0) {
                    $notesParts[] = 'عدد القطع :' . $counted;
                }
                if ($tgParcelDesc !== '') {
                    $notesParts[] = 'وصف البضاعة :' . $tgParcelDesc;
                }
                if ($details) {
                    $notesParts[] = $details;
                }

                // حفظ المنتجات المتعددة في notes بصيغة JSON
                if (!empty($products)) {
                    $productsJson = json_encode($products, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $notesParts[] = '[PRODUCTS_JSON]:' . $productsJson;
                    
                    // أيضاً حفظ بصيغة نصية للتوافق مع الكود القديم
                    $productInfoLines = [];
                    foreach ($products as $product) {
                        $productInfo = 'المنتج: ' . $product['name'];
                        if ($product['quantity'] !== null) {
                            $productInfo .= ' - الكمية: ' . $product['quantity'];
                        }
                        $productInfoLines[] = $productInfo;
                    }
                    if (!empty($productInfoLines)) {
                        $notesParts[] = implode("\n", $productInfoLines);
                    }
                }
                
                // حفظ أول منتج في الحقول القديمة للتوافق
                $firstProduct = !empty($products) ? $products[0] : null;
                $productName = $firstProduct['name'] ?? '';
                $productQuantity = $firstProduct['quantity'] ?? null;
                
                // البحث عن template_id و product_id من اسم المنتج الأول - نفس طريقة customer_orders
                $templateId = null;
                $productId = null;
                if ($productName !== '') {
                    $templateName = trim($productName);
                    
                    // أولاً: البحث عن القالب بالاسم في unified_product_templates (النشطة أولاً)
                    try {
                        $unifiedCheck = $db->queryOne("SHOW TABLES LIKE 'unified_product_templates'");
                        if (!empty($unifiedCheck)) {
                            // البحث في القوالب النشطة أولاً
                            $template = $db->queryOne(
                                "SELECT id FROM unified_product_templates WHERE (product_name = ? OR CONCAT('قالب #', id) = ?) AND status = 'active' LIMIT 1",
                                [$templateName, $templateName]
                            );
                            if ($template) {
                                $templateId = (int)$template['id'];
                            } else {
                                // إذا لم يُعثر عليه في النشطة، البحث في جميع القوالب (بما في ذلك غير النشطة)
                                $template = $db->queryOne(
                                    "SELECT id FROM unified_product_templates WHERE (product_name = ? OR CONCAT('قالب #', id) = ?) LIMIT 1",
                                    [$templateName, $templateName]
                                );
                                if ($template) {
                                    $templateId = (int)$template['id'];
                                }
                            }
                        }
                    } catch (Exception $e) {
                        error_log('Error searching unified_product_templates: ' . $e->getMessage());
                    }
                    
                    // ثانياً: إذا لم يُعثر عليه، البحث في product_templates
                    if (!$templateId) {
                        try {
                            $productTemplatesCheck = $db->queryOne("SHOW TABLES LIKE 'product_templates'");
                            if (!empty($productTemplatesCheck)) {
                                // البحث في القوالب النشطة أولاً
                                $template = $db->queryOne(
                                    "SELECT id FROM product_templates WHERE (product_name = ? OR CONCAT('قالب #', id) = ?) AND status = 'active' LIMIT 1",
                                    [$templateName, $templateName]
                                );
                                if ($template) {
                                    $templateId = (int)$template['id'];
                                } else {
                                    // إذا لم يُعثر عليه في النشطة، البحث في جميع القوالب (بما في ذلك غير النشطة)
                                    $template = $db->queryOne(
                                        "SELECT id FROM product_templates WHERE (product_name = ? OR CONCAT('قالب #', id) = ?) LIMIT 1",
                                        [$templateName, $templateName]
                                    );
                                    if ($template) {
                                        $templateId = (int)$template['id'];
                                    }
                                }
                            }
                        } catch (Exception $e) {
                            error_log('Error searching product_templates: ' . $e->getMessage());
                        }
                    }
                    
                    // ثالثاً: إذا لم يُعثر على template_id، البحث عن product_id في products
                    if (!$templateId) {
                        try {
                            $product = $db->queryOne(
                                "SELECT id FROM products WHERE name = ? AND status = 'active' LIMIT 1",
                                [$templateName]
                            );
                            if ($product) {
                                $productId = (int)$product['id'];
                            }
                        } catch (Exception $e) {
                            error_log('Error searching products: ' . $e->getMessage());
                        }
                    }
                }
                
                // حفظ قائمة العمال في notes
                if (count($assignees) > 1) {
                    $assigneesInfo = 'العمال المخصصون: ' . implode(', ', $assigneeNames);
                    $assigneesInfo .= "\n[ASSIGNED_WORKERS_IDS]:" . implode(',', $assignees);
                    $notesParts[] = $assigneesInfo;
                } elseif (count($assignees) === 1) {
                    $assigneesInfo = 'العامل المخصص: ' . ($assigneeNames[0] ?? '');
                    $assigneesInfo .= "\n[ASSIGNED_WORKERS_IDS]:" . $assignees[0];
                    $notesParts[] = $assigneesInfo;
                }
                
                // حفظ رسوم الشحن والخصم والمدفوع مقدماً في notes لعرضها في الإيصال
                if ($shippingFees > 0) {
                    $notesParts[] = 'رسوم الشحن :' . $shippingFees;
                }
                if ($discount > 0) {
                    $notesParts[] = 'الخصم :' . $discount;
                }
                if ($advancePayment > 0) {
                    $notesParts[] = '[ADVANCE_PAYMENT]:' . $advancePayment;
                }

                $notesValue = !empty($notesParts) ? implode("\n\n", $notesParts) : null;
                if ($notesValue) {
                    $columns[] = 'notes';
                    $values[] = $notesValue;
                    $placeholders[] = '?';
                }

                // حفظ template_id و product_name و product_id - نفس طريقة customer_orders
                // حفظ template_id (حتى لو كان null) لضمان حفظ product_name بشكل صحيح
                // عندما template_id = null، يجب أن يتم حفظ product_name لضمان عرضه في الجدول
                $columns[] = 'template_id';
                $values[] = $templateId; // يمكن أن يكون null
                $placeholders[] = '?';
                
                // حفظ product_name دائماً (حتى لو كان null أو فارغاً) لضمان الاتساق
                // هذا يضمن عرض اسم القالب في الجدول حتى لو فشل JOIN مع جداول القوالب أو كان template_id = null
                // نفس الطريقة المستخدمة في production/tasks.php (السطر 502-519)
                // نحفظ product_name دائماً لضمان الاتساق بين قاعدة البيانات و audit log
                $columns[] = 'product_name';
                $values[] = ($productName !== '') ? $productName : null; // حفظ null إذا كان فارغاً
                $placeholders[] = '?';
                
                // حفظ product_id إذا تم العثور عليه
                if ($productId !== null && $productId > 0) {
                    $columns[] = 'product_id';
                    $values[] = $productId;
                    $placeholders[] = '?';
                }

                if ($customerName !== '') {
                    $columns[] = 'customer_name';
                    $values[] = $customerName;
                    $placeholders[] = '?';
                }

                if ($customerPhone !== '') {
                    $columns[] = 'customer_phone';
                    $values[] = $customerPhone;
                    $placeholders[] = '?';
                }

                if ($localCustomerIdForTask !== null && $localCustomerIdForTask > 0) {
                    $columns[] = 'local_customer_id';
                    $values[] = $localCustomerIdForTask;
                    $placeholders[] = '?';
                }

                // حفظ الكمية الإجمالية (من أول منتج أو مجموع الكميات)
                $totalQuantity = null;
                $firstUnit = 'قطعة'; // القيمة الافتراضية
                if (!empty($products)) {
                    $totalQuantity = 0;
                    $firstUnit = $products[0]['unit'] ?? 'قطعة';
                    foreach ($products as $product) {
                        if ($product['quantity'] !== null) {
                            $totalQuantity += $product['quantity'];
                        }
                    }
                    if ($totalQuantity > 0) {
                        $columns[] = 'quantity';
                        $values[] = $totalQuantity;
                        $placeholders[] = '?';
                    }
                } elseif ($productQuantity !== null) {
                    $columns[] = 'quantity';
                    $values[] = $productQuantity;
                    $placeholders[] = '?';
                }
                
                // حفظ الوحدة (من أول منتج)
                if (!empty($products)) {
                    $columns[] = 'unit';
                    $values[] = $firstUnit;
                    $placeholders[] = '?';
                } elseif (!empty($_POST['unit'])) {
                    $unit = trim($_POST['unit'] ?? 'قطعة');
                    $allowedUnits = ['قطعة', 'كرتونة', 'عبوة', 'شرينك', 'دسته', 'جرام', 'كيلو'];
                    if (!in_array($unit, $allowedUnits, true)) {
                        $unit = 'قطعة';
                    }
                    $columns[] = 'unit';
                    $values[] = $unit;
                    $placeholders[] = '?';
                }

                $sql = "INSERT INTO tasks (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
                $result = $db->execute($sql, $values);
                $taskId = $result['insert_id'] ?? 0;

                if ($taskId <= 0) {
                    throw new Exception('تعذر إنشاء المهمة.');
                }

                logAudit(
                    $currentUser['id'],
                    'create_production_task',
                    'tasks',
                    $taskId,
                    null,
                    [
                        'task_type' => $taskType,
                        'assigned_to' => $assignees,
                        'assigned_count' => count($assignees),
                        'priority' => $priority,
                        'due_date' => $dueDate,
                        'product_name' => $productName ?: null,
                        'quantity' => $productQuantity
                    ]
                );

                // إرسال إشعارات لجميع العمال المختارين
                $notificationTitle = 'مهمة جديدة من الإدارة';
                $notificationMessage = $title;
                if (count($assignees) > 1) {
                    $notificationMessage .= ' (مشتركة مع ' . (count($assignees) - 1) . ' عامل آخر)';
                }

                foreach ($assignees as $assignedId) {
                    try {
                        createNotification(
                            $assignedId,
                            $notificationTitle,
                            $notificationMessage,
                            'info',
                            getDashboardUrl('production') . '?page=tasks'
                        );
                    } catch (Exception $notificationException) {
                        error_log('Manager task notification error: ' . $notificationException->getMessage());
                    }
                }

                // إرسال إشعار واحد لعمال الإنتاج والمدير والمحاسب (يظهر في شريط الإشعارات) مع اسم المنشئ
                try {
                    $creatorName = $currentUser['full_name'] ?? $currentUser['name'] ?? 'غير معروف';
                    $taskSummary = $title;
                    if (!empty($products)) {
                        $first = $products[0];
                        $taskSummary .= ' - ' . ($first['name'] ?? '');
                        if (isset($first['quantity']) && $first['quantity'] !== null) {
                            $taskSummary .= ' ' . ($first['quantity']) . ' ' . (isset($first['unit']) ? $first['unit'] : 'قطعة');
                        }
                    }
                    $orderNotifTitle = 'أوردر جديد';
                    $orderNotifMessage = $taskSummary . ' - أنشأه: ' . $creatorName;
                    $rolesLinks = [
                        'production' => getDashboardUrl('production') . '?page=tasks',
                        'manager'    => getDashboardUrl('manager') . '?page=production_tasks',
                        'accountant' => getDashboardUrl('accountant') . '?page=production_tasks',
                    ];
                    $notifiedIds = [];
                    foreach (['production', 'manager', 'accountant'] as $role) {
                        $users = $db->query("SELECT id FROM users WHERE role = ? AND status = 'active'", [$role]);
                        foreach ($users as $u) {
                            $uid = (int) ($u['id'] ?? 0);
                            if ($uid > 0 && !isset($notifiedIds[$uid])) {
                                $notifiedIds[$uid] = true;
                                createNotification($uid, $orderNotifTitle, $orderNotifMessage, 'info', $rolesLinks[$role], true);
                            }
                        }
                    }
                } catch (Throwable $e) {
                    error_log('Manager order notification error: ' . $e->getMessage());
                }

                $db->commit();

                // تطبيق حد الاحتفاظ بعد الالتزام لضمان عدم حذف المهمة الجديدة
                // يتم استدعاؤه بعد الالتزام لمنع أي مشاكل في المعاملة
                enforceTasksRetentionLimit($db, $tasksRetentionLimit);

                // ===== إرسال شحنة TelegraphEx تلقائياً عند إنشاء اوردر تليجراف =====
                $tgShipmentMsg = '';
                if ($taskType === 'telegraph') {
                    if (empty($customerPhone)) {
                        $tgShipmentMsg = ' ⚠ لم يتم تسجيل الشحنة في TelegraphEx: رقم هاتف العميل مطلوب';
                    } else
                    try {
                        $tgGovId  = isset($_POST['tg_gov_id']) ? (int)$_POST['tg_gov_id'] : 0;
                        $tgCityId = isset($_POST['tg_city_id']) ? (int)$_POST['tg_city_id'] : 0;

                        // حساب الإجمالي النهائي (مجموع line_total للمنتجات + شحن - خصم)
                        $tgSubtotal = 0;
                        if (!empty($products)) {
                            foreach ($products as $p) {
                                $lt = $p['line_total'] ?? null;
                                if ($lt !== null && is_numeric($lt)) {
                                    $tgSubtotal += (float)$lt;
                                } elseif (isset($p['quantity']) && (float)$p['quantity'] > 0 && isset($p['price']) && is_numeric($p['price'])) {
                                    $tgSubtotal += round((float)$p['quantity'] * (float)$p['price'], 2);
                                }
                            }
                        }
                        $tgFinalTotal = max(0, $tgSubtotal + $shippingFees - $discount);
                        $tgWeightVal  = ($tgWeight !== '') ? (float)str_replace(',', '.', $tgWeight) : 0;

                        $tgPayload = json_encode([
                            'operationName' => 'SaveShipment',
                            'variables' => [
                                'input' => [
                                    'recipientName'      => $customerName ?: '',
                                    'paymentTypeCode'    => 'COLC',
                                    'priceTypeCode'      => 'INCLD',
                                    'recipientZoneId'    => $tgGovId,
                                    'recipientSubzoneId' => $tgCityId,
                                    'recipientPhone'     => '',
                                    'recipientMobile'    => $customerPhone ?: '',
                                    'recipientAddress'   => $orderTitle ?: '',
                                    'senderName'         => 'شركة البركة لتجارة المواد الغذائية',
                                    'senderPhone'        => '01203630363',
                                    'senderMobile'       => '01003533905',
                                    'senderZoneId'       => 1,
                                    'senderSubzoneId'    => 346,
                                    'senderAddress'      => 'ش اسوان متفرع من شارع ريدمبكس العجمي ابو يوسف',
                                    'description'        => $tgParcelDesc ?: '',
                                    'notes'              => $details ?: '',
                                    'refNumber'          => (string)$taskId,
                                    'typeCode'           => 'FDP',
                                    'openableCode'       => 'Y',
                                    'serviceId'          => 1,
                                    'weight'             => $tgWeightVal,
                                    'piecesCount'        => $counted,
                                    'price'              => $tgFinalTotal,
                                    'size'               => ['length' => 0, 'height' => 0, 'width' => 0],
                                ],
                            ],
                            'query' => 'mutation SaveShipment($input: ShipmentInput!) { saveShipment(input: $input) { id date code recipientName description piecesCount recipientAddress amount totalAmount allDueFees inWarehouse recipientZone { id name } customer { id name code } recipientSubzone { id name } shipmentProducts { id price quantity type product { id name weight } } } }',
                        ], JSON_UNESCAPED_UNICODE);

                        $ch = curl_init('https://system.telegraphex.com:8443/graphql');
                        curl_setopt_array($ch, [
                            CURLOPT_POST           => true,
                            CURLOPT_POSTFIELDS     => $tgPayload,
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_TIMEOUT        => 15,
                            CURLOPT_HTTPHEADER     => [
                                'Content-Type: application/json',
                                'Authorization: Bearer 245467|m90rxf6dkwYyeku570WIGKSuyhkZr1Kt2ehSUQVLf862e568',
                                'Accept: */*',
                                'x-app-version: 5.2.2',
                                'x-client-name: Mac OS-Safari',
                                'x-client-type: WEB',
                            ],
                        ]);

                        $tgResult   = curl_exec($ch);
                        $tgHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        $tgCurlErr  = curl_error($ch);
                        curl_close($ch);

                        if ($tgCurlErr) {
                            error_log('TelegraphEx shipment cURL error: ' . $tgCurlErr);
                            $tgShipmentMsg = ' ⚠ فشل الاتصال بـ TelegraphEx: ' . $tgCurlErr;
                        } else {
                            $tgResponse = json_decode($tgResult, true);
                            if (isset($tgResponse['data']['saveShipment']['code'])) {
                                $tgCode = $tgResponse['data']['saveShipment']['code'];
                                $tgShipmentMsg = ' ✅ تم تسجيل الشحنة في TelegraphEx برقم: ' . $tgCode;
                            } else {
                                $tgErrors = $tgResponse['errors'] ?? [];
                                $tgErrMsg = !empty($tgErrors) ? ($tgErrors[0]['message'] ?? 'خطأ غير معروف') : 'استجابة غير متوقعة';
                                // محاولة استخراج تفاصيل حقل التحقق من extensions
                                $tgExtensions = $tgErrors[0]['extensions'] ?? [];
                                $tgViolations = $tgExtensions['constraintViolations']
                                    ?? $tgExtensions['validationErrors']
                                    ?? [];
                                if (!empty($tgViolations) && is_array($tgViolations)) {
                                    $tgViolationMsgs = array_map(fn($v) => ($v['field'] ?? '') . ': ' . ($v['message'] ?? ''), $tgViolations);
                                    $tgErrMsg .= ' [' . implode(' | ', $tgViolationMsgs) . ']';
                                }
                                error_log('TelegraphEx shipment error: ' . $tgResult);
                                $tgShipmentMsg = ' ⚠ فشل تسجيل الشحنة في TelegraphEx: ' . $tgErrMsg;
                            }
                        }
                    } catch (Throwable $tgEx) {
                        error_log('TelegraphEx shipment exception: ' . $tgEx->getMessage());
                        $tgShipmentMsg = ' ⚠ خطأ أثناء تسجيل الشحنة في TelegraphEx';
                    }
                }

                // اعتماد الفاتورة تلقائياً إذا تم تفعيل الخيار
                $autoApproveMsg = '';
                if ($autoApproveInvoice && $taskId > 0 && !in_array($taskType, ['telegraph', 'shipping_company'], true)) {
                    try {
                        // حساب الإجمالي النهائي
                        $autoSubtotal = 0;
                        foreach ($products as $p) {
                            $lt = $p['line_total'] ?? null;
                            if ($lt !== null && is_numeric($lt)) {
                                $autoSubtotal += (float)$lt;
                            } elseif (isset($p['quantity']) && (float)($p['quantity'] ?? 0) > 0 && isset($p['price']) && is_numeric($p['price'] ?? null)) {
                                $autoSubtotal += round((float)$p['quantity'] * (float)$p['price'], 2);
                            }
                        }
                        // المبلغ الكلي (بدون خصم المدفوع مقدماً) — يُحفظ في tasks.total_amount
                        $autoGrossTotal = max(0, $autoSubtotal + $shippingFees - $discount);
                        // المبلغ الصافي الذي يُضاف لرصيد العميل (بعد خصم المدفوع مقدماً)
                        $autoNetAmount  = max(0, $autoGrossTotal - $advancePayment);

                        // تحديد العميل المحلي
                        $autoCustId = (int)($localCustomerIdForTask ?? 0);
                        if ($autoCustId <= 0) {
                            $autoName  = trim((string)($customerName ?? ''));
                            $autoPhone = trim((string)($customerPhone ?? ''));
                            if ($autoName !== '' || $autoPhone !== '') {
                                $autoMatch = $db->queryOne(
                                    "SELECT id FROM local_customers WHERE status = 'active' AND (name = ? OR phone = ?) LIMIT 1",
                                    [$autoName, $autoPhone !== '' ? $autoPhone : $autoName]
                                );
                                $autoCustId = $autoMatch ? (int)$autoMatch['id'] : 0;
                            }
                        }

                        if ($autoCustId > 0) {
                            $alreadyApproved = $db->queryOne("SELECT id FROM customer_task_purchases WHERE task_id = ?", [$taskId]);
                            if (!$alreadyApproved) {
                                $taskNotesRowAuto = $db->queryOne("SELECT notes FROM tasks WHERE id = ? LIMIT 1", [$taskId]);

                                $db->beginTransaction();
                                deductTaskProductsFromStock($db, $taskNotesRowAuto['notes'] ?? '');
                                $taskNumberAuto = '#' . $taskId;
                                $taskDateAuto   = date('Y-m-d');
                                // يُسجَّل في سجل المشتريات بالمبلغ الصافي (بعد المدفوع مقدماً)
                                $db->execute(
                                    "INSERT INTO customer_task_purchases (local_customer_id, task_id, task_number, total_amount, task_date) VALUES (?, ?, ?, ?, ?)",
                                    [$autoCustId, $taskId, $taskNumberAuto, $autoNetAmount, $taskDateAuto]
                                );
                                // يُضاف للرصيد فقط المبلغ الصافي
                                $db->execute(
                                    "UPDATE local_customers SET balance = COALESCE(balance, 0) + ? WHERE id = ?",
                                    [$autoNetAmount, $autoCustId]
                                );
                                $db->execute(
                                    "UPDATE tasks SET total_amount = ?, local_customer_id = ?, status = 'delivered' WHERE id = ?",
                                    [$autoGrossTotal, $autoCustId, $taskId]
                                );
                                // المدفوع مقدماً مُحتسَب ضمن autoNetAmount (لا يُضاف للرصيد)
                                // لا حاجة لإدخال منفصل — الرصيد الصافي فقط هو ما يُضاف أعلاه
                                $db->commit();
                                logAudit(
                                    $currentUser['id'],
                                    'auto_approve_task_invoice',
                                    'tasks',
                                    $taskId,
                                    null,
                                    ['local_customer_id' => $autoCustId, 'gross_total' => $autoGrossTotal, 'advance_payment' => $advancePayment, 'net_amount' => $autoNetAmount]
                                );
                                $autoApproveMsg = ' ✅ تم اعتماد الفاتورة تلقائياً وتغيير حالة الطلب إلى "تم التوصيل".';
                                if ($advancePayment > 0) {
                                    $autoApproveMsg .= ' المدفوع مقدماً (' . number_format($advancePayment, 2) . ' ج.م) مُخصوم من رصيد العميل.';
                                }
                            }
                        } else {
                            $autoApproveMsg = ' ⚠ لم يتم اعتماد الفاتورة تلقائياً: العميل غير مسجل في العملاء المحليين.';
                        }
                    } catch (Throwable $autoEx) {
                        if (isset($db) && $db->inTransaction()) $db->rollBack();
                        error_log('Auto approve invoice error: ' . $autoEx->getMessage() . ' in ' . $autoEx->getFile() . ':' . $autoEx->getLine());
                        $autoApproveMsg = ' ⚠ حدث خطأ أثناء اعتماد الفاتورة تلقائياً: ' . $autoEx->getMessage();
                    }
                }

                // التوجيه إلى صفحة طباعة إيصال الأوردر مع فتح نافذة الطباعة تلقائياً (معاينة المتصفح)
                $successMessage = count($assignees) > 0
                    ? 'تم إرسال المهمة بنجاح إلى ' . count($assignees) . ' من عمال الإنتاج.'
                    : 'تم إرسال المهمة بنجاح.';
                $successMessage .= $tgShipmentMsg . $autoApproveMsg;
                $userRole = in_array($currentUser['role'] ?? '', ['accountant', 'sales'], true) ? ($currentUser['role'] ?? 'manager') : 'manager';
                preventDuplicateSubmission($successMessage, ['page' => 'production_tasks'], null, $userRole);
                exit; // منع تنفيذ باقي الكود بعد إعادة التوجيه
            } catch (Exception $e) {
                $db->rollback();
                error_log('Manager production task creation error: ' . $e->getMessage());
                $error = ($e instanceof InvalidArgumentException) ? $e->getMessage() : 'حدث خطأ أثناء إنشاء المهام. يرجى المحاولة مرة أخرى.';
            }
        }
    } elseif ($action === 'save_task_draft') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        try {
            $draftData = [];
            $allowedDraftFields = ['task_type','priority','due_date','customer_name','local_customer_id','customer_phone','customer_type_radio_task','tg_governorate','tg_gov_id','tg_city','tg_city_id','tg_weight','tg_pieces_count','tg_parcel_desc','order_title','shipping_fees','discount','details'];
            foreach ($allowedDraftFields as $f) {
                if (isset($_POST[$f])) $draftData[$f] = $_POST[$f];
            }
            if (isset($_POST['products_json']) && is_string($_POST['products_json'])) {
                $decodedProds = json_decode($_POST['products_json'], true);
                if (is_array($decodedProds)) {
                    $draftData['products'] = array_values($decodedProds);
                }
            } elseif (isset($_POST['products']) && is_array($_POST['products'])) {
                ksort($_POST['products'], SORT_NUMERIC);
                $draftData['products'] = array_values($_POST['products']);
            }
            $customerName = trim($_POST['customer_name'] ?? '');
            $taskType     = trim($_POST['task_type'] ?? '');
            $typeLabels   = ['shop_order' => 'محل', 'cash_customer' => 'عميل نقدي', 'telegraph' => 'تليجراف', 'shipping_company' => 'شحن'];
            $draftName = ($customerName !== '' ? $customerName : 'بدون عميل') . ' - ' . ($typeLabels[$taskType] ?? $taskType) . ' - ' . date('d/m H:i');

            $draftId = intval($_POST['draft_id'] ?? 0);
            if ($draftId > 0) {
                $existing = $db->queryOne("SELECT id FROM task_drafts WHERE id = ? AND created_by = ? LIMIT 1", [$draftId, $currentUser['id']]);
                if ($existing) {
                    $db->execute("UPDATE task_drafts SET draft_name = ?, draft_data = ?, updated_at = NOW() WHERE id = ?", [$draftName, json_encode($draftData, JSON_UNESCAPED_UNICODE), $draftId]);
                    echo json_encode(['success' => true, 'draft_id' => $draftId, 'draft_name' => $draftName], JSON_UNESCAPED_UNICODE);
                    exit;
                }
            }
            $res = $db->execute("INSERT INTO task_drafts (created_by, draft_name, draft_data) VALUES (?, ?, ?)", [$currentUser['id'], $draftName, json_encode($draftData, JSON_UNESCAPED_UNICODE)]);
            $newId = $res['insert_id'] ?? 0;
            echo json_encode(['success' => true, 'draft_id' => $newId, 'draft_name' => $draftName], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'تعذر حفظ المسودة'], JSON_UNESCAPED_UNICODE);
        }
        exit;

    } elseif ($action === 'load_task_draft') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        $draftId = intval($_POST['draft_id'] ?? 0);
        if ($draftId <= 0) { echo json_encode(['success' => false, 'error' => 'معرف غير صحيح'], JSON_UNESCAPED_UNICODE); exit; }
        try {
            $draft = $db->queryOne("SELECT * FROM task_drafts WHERE id = ? AND created_by = ? LIMIT 1", [$draftId, $currentUser['id']]);
            if (!$draft) { echo json_encode(['success' => false, 'error' => 'المسودة غير موجودة'], JSON_UNESCAPED_UNICODE); exit; }
            $data = json_decode($draft['draft_data'], true) ?: [];
            if (isset($data['products']) && is_array($data['products'])) {
                $data['products'] = array_values($data['products']);
            }
            echo json_encode(['success' => true, 'data' => $data, 'draft_id' => $draftId, 'draft_name' => $draft['draft_name']], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'خطأ في تحميل المسودة'], JSON_UNESCAPED_UNICODE);
        }
        exit;

    } elseif ($action === 'delete_task_draft') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        $draftId = intval($_POST['draft_id'] ?? 0);
        if ($draftId <= 0) { echo json_encode(['success' => false, 'error' => 'معرف غير صحيح'], JSON_UNESCAPED_UNICODE); exit; }
        try {
            $db->execute("DELETE FROM task_drafts WHERE id = ? AND created_by = ?", [$draftId, $currentUser['id']]);
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'تعذر حذف المسودة'], JSON_UNESCAPED_UNICODE);
        }
        exit;

    } elseif ($action === 'update_task_status') {
        $taskId = intval($_POST['task_id'] ?? 0);
        $newStatus = trim($_POST['status'] ?? '');

        if ($taskId <= 0) {
            $error = 'معرف المهمة غير صحيح.';
        } elseif (!in_array($newStatus, ['pending', 'completed', 'with_delegate', 'with_driver', 'with_shipping_company', 'delivered', 'returned', 'cancelled'], true)) {
            $error = 'حالة المهمة غير صحيحة.';
        } else {
            try {
                $db->beginTransaction();

                // السماح للمحاسب والمدير بتغيير حالة أي مهمة، والمندوب فقط مهام with_delegate → delivered
                $isAccountant = ($currentUser['role'] ?? '') === 'accountant';
                $isManager = ($currentUser['role'] ?? '') === 'manager';
                $isSalesUser = ($currentUser['role'] ?? '') === 'sales';

                if (!$isAccountant && !$isManager && !$isSalesUser) {
                    throw new Exception('غير مصرح لك بتغيير حالة المهام.');
                }

                // التحقق من وجود المهمة
                $task = $db->queryOne(
                    "SELECT id, title, status FROM tasks WHERE id = ? LIMIT 1",
                    [$taskId]
                );

                if (!$task) {
                    throw new Exception('المهمة غير موجودة.');
                }

                // المندوب: يسمح فقط بتغيير with_delegate → delivered
                if ($isSalesUser) {
                    if (($task['status'] ?? '') !== 'with_delegate') {
                        throw new Exception('لا يمكنك تغيير حالة هذه المهمة.');
                    }
                    if ($newStatus !== 'delivered') {
                        throw new Exception('يمكنك فقط تغيير الحالة إلى "تم التوصيل".');
                    }
                }

                // تحديث الحالة
                $updateFields = ['status = ?'];
                $updateValues = [$newStatus];
                
                // إضافة timestamps حسب الحالة
                if (in_array($newStatus, ['completed', 'with_delegate', 'with_shipping_company', 'delivered', 'returned'], true)) {
                    $updateFields[] = 'completed_at = NOW()';
                } elseif ($newStatus === 'in_progress') {
                    $updateFields[] = 'started_at = NOW()';
                } elseif ($newStatus === 'received') {
                    $updateFields[] = 'received_at = NOW()';
                }
                
                $updateFields[] = 'updated_at = NOW()';
                
                if ($hasStatusChangedBy) {
                    $updateFields[] = 'status_changed_by = ?';
                    $updateValues[] = $currentUser['id'];
                }
                
                $sql = "UPDATE tasks SET " . implode(', ', $updateFields) . " WHERE id = ?";
                $updateValues[] = $taskId;
                
                $db->execute($sql, $updateValues);

                // التحقق من أن الحالة خُزِّنت فعلاً (قد لا يدعم ENUM القيمة)
                $verifyTask = $db->queryOne("SELECT status FROM tasks WHERE id = ?", [$taskId]);
                if (($verifyTask['status'] ?? '') !== $newStatus) {
                    throw new Exception('تعذر تطبيق الحالة الجديدة. يرجى تحديث قاعدة البيانات عبر تشغيل migration أو التواصل مع الدعم الفني.');
                }

                logAudit(
                    $currentUser['id'],
                    'update_task_status',
                    'tasks',
                    $taskId,
                    ['old_status' => $task['status']],
                    ['new_status' => $newStatus, 'title' => $task['title']]
                );

                $db->commit();

                // إذا كان الطلب AJAX، أعد JSON مباشرةً
                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                    while (ob_get_level() > 0) ob_end_clean();
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => true, 'status' => $newStatus], JSON_UNESCAPED_UNICODE);
                    exit;
                }

                // استخدام preventDuplicateSubmission لإعادة التوجيه مع cache-busting
                $successMessage = 'تم تحديث حالة المهمة بنجاح.';
                // تحديد role بناءً على المستخدم الحالي
                $userRole = in_array($currentUser['role'] ?? '', ['accountant', 'sales'], true) ? ($currentUser['role'] ?? 'manager') : 'manager';
                preventDuplicateSubmission($successMessage, ['page' => 'production_tasks'], null, $userRole);
                exit; // منع تنفيذ باقي الكود بعد إعادة التوجيه
            } catch (Exception $updateError) {
                $db->rollBack();
                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                    while (ob_get_level() > 0) ob_end_clean();
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => false, 'error' => $updateError->getMessage()], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                $error = 'تعذر تحديث حالة المهمة: ' . $updateError->getMessage();
            }
        }
    } elseif ($action === 'approve_task_invoice' && ($isAccountant || $isManager)) {
        $taskId = (int)($_POST['task_id'] ?? 0);
        $totalAmount = isset($_POST['total_amount']) ? (float)str_replace(',', '.', trim((string)$_POST['total_amount'])) : 0;
        $approveForShipping = (int)($_POST['approve_for_shipping'] ?? 0) === 1;
        $shippingCompanyId = $approveForShipping ? (int)($_POST['shipping_company_id'] ?? 0) : 0;
        $netParcelPrice = $approveForShipping && isset($_POST['net_parcel_price']) ? (float)str_replace(',', '.', trim((string)$_POST['net_parcel_price'])) : null;

        if ($taskId <= 0) {
            $error = 'معرف المهمة غير صحيح.';
        } elseif ($approveForShipping) {
            if ($shippingCompanyId <= 0) {
                $error = 'يرجى اختيار شركة الشحن.';
            } elseif ($netParcelPrice === null || $netParcelPrice === '') {
                $error = 'يرجى إدخال صافي سعر الطرد.';
            } else {
                try {
                    $task = $db->queryOne("SELECT id, created_at FROM tasks WHERE id = ? LIMIT 1", [$taskId]);
                    if (!$task) {
                        $error = 'المهمة غير موجودة.';
                    } else {
                        $company = $db->queryOne("SELECT id, name FROM shipping_companies WHERE id = ?", [$shippingCompanyId]);
                        if (!$company) {
                            $error = 'شركة الشحن غير موجودة.';
                        } else {
                            $paperTable = $db->queryOne("SHOW TABLES LIKE 'shipping_company_paper_invoices'");
                            if (empty($paperTable)) {
                                $error = 'جدول الفواتير الورقية لشركات الشحن غير متوفر.';
                            } else {
                                $alreadyApproved = $db->queryOne("SELECT id FROM customer_task_purchases WHERE task_id = ?", [$taskId]);
                                if ($alreadyApproved) {
                                    $error = 'تم اعتماد هذا الأوردر مسبقاً كعميل محلي.';
                                } else {
                                    $hasTaskIdCol = $db->queryOne("SHOW COLUMNS FROM shipping_company_paper_invoices LIKE 'task_id'");
                                    $alreadyInPaper = false;
                                    if (!empty($hasTaskIdCol)) {
                                        $existing = $db->queryOne("SELECT id FROM shipping_company_paper_invoices WHERE task_id = ?", [$taskId]);
                                        $alreadyInPaper = !empty($existing);
                                    }
                                    if ($alreadyInPaper) {
                                        $error = 'تم اعتماد هذا الأوردر مسبقاً في سجل الفواتير الورقية لشركة الشحن.';
                                    } else {
                                        $db->beginTransaction();
                                        $taskNotesRow = $db->queryOne("SELECT notes FROM tasks WHERE id = ? LIMIT 1", [$taskId]);
                                        deductTaskProductsFromStock($db, $taskNotesRow['notes'] ?? '');
                                        $invoiceNumber = 'أوردر #' . $taskId;
                                        $hasNetCol = $db->queryOne("SHOW COLUMNS FROM shipping_company_paper_invoices LIKE 'net_amount'");
                                        if (!empty($hasNetCol) && !empty($hasTaskIdCol)) {
                                            $db->execute(
                                                "INSERT INTO shipping_company_paper_invoices (shipping_company_id, invoice_number, total_amount, net_amount, task_id, created_by) VALUES (?, ?, ?, ?, ?, ?)",
                                                [$shippingCompanyId, $invoiceNumber, $totalAmount, $netParcelPrice, $taskId, $currentUser['id']]
                                            );
                                        } else {
                                            $db->execute(
                                                "INSERT INTO shipping_company_paper_invoices (shipping_company_id, invoice_number, total_amount, created_by) VALUES (?, ?, ?, ?)",
                                                [$shippingCompanyId, $invoiceNumber, $totalAmount, $currentUser['id']]
                                            );
                                        }
                                        $paperId = (int)$db->getLastInsertId();
                                        $balanceCol = $db->queryOne("SHOW COLUMNS FROM shipping_companies LIKE 'updated_by'");
                                        $balanceSql = "UPDATE shipping_companies SET balance = COALESCE(balance, 0) + ?";
                                        $balanceParams = [$netParcelPrice];
                                        if (!empty($balanceCol)) {
                                            $balanceSql .= ", updated_by = ?, updated_at = NOW()";
                                            $balanceParams[] = $currentUser['id'];
                                        }
                                        $balanceParams[] = $shippingCompanyId;
                                        $db->execute($balanceSql . " WHERE id = ?", $balanceParams);
                                        $db->execute("UPDATE tasks SET total_amount = ? WHERE id = ?", [$totalAmount, $taskId]);
                                        $db->commit();
                                        logAudit($currentUser['id'], 'approve_task_invoice_shipping', 'tasks', $taskId, null, ['shipping_company_id' => $shippingCompanyId, 'net_parcel_price' => $netParcelPrice]);
                                        $userRole = in_array($currentUser['role'] ?? '', ['accountant', 'sales'], true) ? ($currentUser['role'] ?? 'manager') : 'manager';
                                        preventDuplicateSubmission('تم اعتماد الفاتورة: تمت إضافة الأوردر لسجل الفواتير الورقية لشركة الشحن وإضافة صافي سعر الطرد لديونها.', ['page' => 'production_tasks'], null, $userRole);
                                        exit;
                                    }
                                }
                            }
                        }
                    }
                } catch (Exception $e) {
                    if (isset($db) && $db->inTransaction()) {
                        $db->rollBack();
                    }
                    $error = ($e instanceof InvalidArgumentException) ? $e->getMessage() : 'تعذر اعتماد الفاتورة: ' . $e->getMessage();
                }
            }
        } else {
            try {
                $task = $db->queryOne(
                    "SELECT id, customer_name, customer_phone, local_customer_id, created_at FROM tasks WHERE id = ? LIMIT 1",
                    [$taskId]
                );
                if (!$task) {
                    $error = 'المهمة غير موجودة.';
                } else {
                    $custId = (int)($task['local_customer_id'] ?? 0);
                    if ($custId <= 0) {
                        $name = trim((string)($task['customer_name'] ?? ''));
                        $phone = trim((string)($task['customer_phone'] ?? ''));
                        if ($name !== '' || $phone !== '') {
                            $match = $db->queryOne(
                                "SELECT id FROM local_customers WHERE status = 'active' AND (name = ? OR phone = ?) LIMIT 1",
                                [$name, $phone !== '' ? $phone : $name]
                            );
                            $custId = $match ? (int)$match['id'] : 0;
                        }
                    }
                    if ($custId <= 0) {
                        $error = 'لم يتم العثور على عميل محلي مطابق للاسم/الهاتف المكتوب في الأوردر. يرجى تسجيل العميل في العملاء المحليين أولاً.';
                    } else {
                        $existing = $db->queryOne("SELECT id FROM customer_task_purchases WHERE task_id = ?", [$taskId]);
                        if ($existing) {
                            $error = 'تم اعتماد هذا الأوردر مسبقاً في سجل المشتريات.';
                        } else {
                            $db->beginTransaction();
                            $taskNotesRow = $db->queryOne("SELECT notes FROM tasks WHERE id = ? LIMIT 1", [$taskId]);
                            $taskNotes = $taskNotesRow['notes'] ?? '';
                            deductTaskProductsFromStock($db, $taskNotes);
                            $taskNumber = '#' . $taskId;
                            $taskDate = date('Y-m-d', strtotime($task['created_at'] ?? 'now'));
                            $db->execute(
                                "INSERT INTO customer_task_purchases (local_customer_id, task_id, task_number, total_amount, task_date) VALUES (?, ?, ?, ?, ?)",
                                [$custId, $taskId, $taskNumber, $totalAmount, $taskDate]
                            );
                            $db->execute(
                                "UPDATE local_customers SET balance = COALESCE(balance, 0) + ? WHERE id = ?",
                                [$totalAmount, $custId]
                            );
                            $db->execute(
                                "UPDATE tasks SET total_amount = ?, local_customer_id = ? WHERE id = ?",
                                [$totalAmount, $custId, $taskId]
                            );
                            $db->commit();
                            logAudit(
                                $currentUser['id'],
                                'approve_task_invoice',
                                'tasks',
                                $taskId,
                                null,
                                ['local_customer_id' => $custId, 'total_amount' => $totalAmount]
                            );

                            // استخراج المدفوع مقدماً من notes
                            $advancePaid = 0.0;
                            if (preg_match('/\[ADVANCE_PAYMENT\]:\s*([0-9.]+)/', $taskNotes, $advM)) {
                                $advancePaid = (float)$advM[1];
                            }

                            $userRole = in_array($currentUser['role'] ?? '', ['accountant', 'sales'], true) ? ($currentUser['role'] ?? 'manager') : 'manager';

                            if ($advancePaid > 0) {
                                // جلب بيانات العميل وبيانات العميل المحدثة
                                $custRow = $db->queryOne("SELECT name, phone, balance FROM local_customers WHERE id = ?", [$custId]);
                                $custName  = $custRow['name'] ?? ($task['customer_name'] ?? '');
                                $custPhone = $custRow['phone'] ?? ($task['customer_phone'] ?? '');
                                $newBalance = (float)($custRow['balance'] ?? $totalAmount);
                                $remaining  = max(0, $totalAmount - $advancePaid);

                                $collectionInfo = json_encode([
                                    'customer_name'  => $custName,
                                    'customer_phone' => $custPhone,
                                    'order_number'   => $taskId,
                                    'total_amount'   => $totalAmount,
                                    'advance_paid'   => $advancePaid,
                                    'remaining'      => $remaining,
                                    'new_balance'    => $newBalance,
                                ], JSON_UNESCAPED_UNICODE);

                                preventDuplicateSubmission(
                                    'تم اعتماد الفاتورة بنجاح.',
                                    ['page' => 'production_tasks', 'collection_info' => urlencode(base64_encode($collectionInfo))],
                                    null,
                                    $userRole
                                );
                            } else {
                                preventDuplicateSubmission('تم اعتماد الفاتورة: تمت إضافة الأوردر لسجل مشتريات العميل وإضافة المبلغ لرصيده المدين.', ['page' => 'production_tasks'], null, $userRole);
                            }
                            exit;
                        }
                    }
                }
            } catch (Exception $e) {
                if (isset($db) && $db->inTransaction()) {
                    $db->rollBack();
                }
                $error = ($e instanceof InvalidArgumentException) ? $e->getMessage() : 'تعذر اعتماد الفاتورة: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'bulk_approve_task_invoice' && ($isAccountant || $isManager)) {
        $taskIdsRaw = $_POST['task_ids'] ?? [];
        if (!is_array($taskIdsRaw)) $taskIdsRaw = explode(',', (string)$taskIdsRaw);
        $taskIds = array_values(array_filter(array_map('intval', $taskIdsRaw)));
        if (empty($taskIds)) {
            $error = 'لم يتم تحديد أي أوردرات.';
        } else {
            $ph = implode(',', array_fill(0, count($taskIds), '?'));
            $tasks = $db->query(
                "SELECT id, customer_name, customer_phone, local_customer_id, created_at, notes, task_type, related_type FROM tasks WHERE id IN ($ph)",
                $taskIds
            );
            $successCount = 0;
            $skippedShipping = 0;
            $errors = [];
            foreach (($tasks ?: []) as $bulkTask) {
                $tid = (int)$bulkTask['id'];
                $totalAmount = getTaskReceiptTotalFromNotes($bulkTask['notes'] ?? '');
                $relType = $bulkTask['related_type'] ?? '';
                $dispType = (strpos($relType, 'manager_') === 0) ? substr($relType, 8) : ($bulkTask['task_type'] ?? 'general');
                if ($dispType === 'telegraph' || $dispType === 'shipping_company') {
                    $skippedShipping++;
                    continue;
                }
                $existingApproval = $db->queryOne("SELECT id FROM customer_task_purchases WHERE task_id = ?", [$tid]);
                if ($existingApproval) {
                    $errors[] = "أوردر #$tid: تم اعتماده مسبقاً.";
                    continue;
                }
                $custId = (int)($bulkTask['local_customer_id'] ?? 0);
                if ($custId <= 0) {
                    $bName  = trim((string)($bulkTask['customer_name']  ?? ''));
                    $bPhone = trim((string)($bulkTask['customer_phone'] ?? ''));
                    if ($bName !== '' || $bPhone !== '') {
                        $match = $db->queryOne(
                            "SELECT id FROM local_customers WHERE status = 'active' AND (name = ? OR phone = ?) LIMIT 1",
                            [$bName, $bPhone !== '' ? $bPhone : $bName]
                        );
                        $custId = $match ? (int)$match['id'] : 0;
                    }
                }
                if ($custId <= 0) {
                    $errors[] = "أوردر #$tid: لم يتم العثور على عميل محلي مطابق.";
                    continue;
                }
                try {
                    $db->beginTransaction();
                    deductTaskProductsFromStock($db, $bulkTask['notes'] ?? '');
                    $taskNumber = '#' . $tid;
                    $taskDate   = date('Y-m-d', strtotime($bulkTask['created_at'] ?? 'now'));
                    $db->execute(
                        "INSERT INTO customer_task_purchases (local_customer_id, task_id, task_number, total_amount, task_date) VALUES (?, ?, ?, ?, ?)",
                        [$custId, $tid, $taskNumber, $totalAmount, $taskDate]
                    );
                    $db->execute(
                        "UPDATE local_customers SET balance = COALESCE(balance, 0) + ? WHERE id = ?",
                        [$totalAmount, $custId]
                    );
                    $db->execute(
                        "UPDATE tasks SET total_amount = ?, local_customer_id = ? WHERE id = ?",
                        [$totalAmount, $custId, $tid]
                    );
                    $db->commit();
                    logAudit($currentUser['id'], 'approve_task_invoice', 'tasks', $tid, null, ['local_customer_id' => $custId, 'total_amount' => $totalAmount]);
                    $successCount++;
                } catch (Exception $e) {
                    if (isset($db) && $db->inTransaction()) $db->rollBack();
                    $errors[] = "أوردر #$tid: " . $e->getMessage();
                }
            }
            $msg = "تم اعتماد {$successCount} فاتورة بنجاح.";
            if ($skippedShipping > 0) $msg .= " ({$skippedShipping} أوردر شحن يتطلب اعتماداً فردياً).";
            if (!empty($errors)) $msg .= ' ملاحظات: ' . implode(' | ', $errors);
            $userRole = in_array($currentUser['role'] ?? '', ['accountant', 'sales'], true) ? ($currentUser['role'] ?? 'manager') : 'manager';
            preventDuplicateSubmission($msg, ['page' => 'production_tasks'], null, $userRole);
            exit;
        }
    } elseif ($action === 'cancel_task') {
        $taskId = intval($_POST['task_id'] ?? 0);

                if ($taskId <= 0) {
                    $error = 'معرف المهمة غير صحيح.';
                } else {
                    try {
                        $db->beginTransaction();

                        // السماح للمحاسب والمدير بحذف أي مهمة
                        $isAccountant = ($currentUser['role'] ?? '') === 'accountant';
                        $isManager = ($currentUser['role'] ?? '') === 'manager';
                        
                        if ($isAccountant || $isManager) {
                            // المحاسب والمدير يمكنهم حذف أي مهمة
                            $task = $db->queryOne(
                                "SELECT id, title, status FROM tasks WHERE id = ? LIMIT 1",
                                [$taskId]
                            );
                        } else {
                            // المستخدمون الآخرون يمكنهم حذف المهام التي أنشأوها فقط
                            $task = $db->queryOne(
                                "SELECT id, title, status FROM tasks WHERE id = ? AND created_by = ? LIMIT 1",
                                [$taskId, $currentUser['id']]
                            );
                        }

                        if (!$task) {
                            if ($isAccountant || $isManager) {
                                throw new Exception('المهمة غير موجودة.');
                            } else {
                                throw new Exception('المهمة غير موجودة أو ليست من إنشائك.');
                            }
                        }

                // حذف المهمة بدلاً من تغيير الحالة إلى cancelled
                $db->execute(
                    "DELETE FROM tasks WHERE id = ?",
                    [$taskId]
                );

                // تعليم الإشعارات القديمة كمقروءة
                $db->execute(
                    "UPDATE notifications SET `read` = 1 WHERE message = ? AND type IN ('info','success','warning')",
                    [$task['title']]
                );

                logAudit(
                    $currentUser['id'],
                    'cancel_task',
                    'tasks',
                    $taskId,
                    null,
                    ['title' => $task['title']]
                );

                $db->commit();
                
                // استخدام preventDuplicateSubmission لإعادة التوجيه مع cache-busting
                $successMessage = 'تم حذف المهمة بنجاح.';
                // تحديد role بناءً على المستخدم الحالي
                $userRole = in_array($currentUser['role'] ?? '', ['accountant', 'sales'], true) ? ($currentUser['role'] ?? 'manager') : 'manager';
                preventDuplicateSubmission($successMessage, ['page' => 'production_tasks'], null, $userRole);
                exit; // منع تنفيذ باقي الكود بعد إعادة التوجيه
            } catch (Exception $cancelError) {
                $db->rollBack();
                $error = 'تعذر إلغاء المهمة: ' . $cancelError->getMessage();
            }
        }
    } elseif ($action === 'update_task') {
        $taskId = intval($_POST['task_id'] ?? 0);
        if ($taskId <= 0) {
            $error = 'معرف المهمة غير صحيح.';
        } elseif (!($isAccountant || $isManager)) {
            $error = 'غير مصرح بتعديل المهمة.';
        } else {
            try {
                $task = $db->queryOne("SELECT id, customer_name, customer_phone, local_customer_id FROM tasks WHERE id = ? LIMIT 1", [$taskId]);
                if (!$task) {
                    $error = 'المهمة غير موجودة.';
                } else {
                    $taskType = $_POST['task_type'] ?? 'shop_order';
                    $taskType = in_array($taskType, $allowedTypes, true) ? $taskType : 'shop_order';
                    $priority = $_POST['priority'] ?? 'normal';
                    $priority = in_array($priority, $allowedPriorities, true) ? $priority : 'normal';
                    $dueDate = trim($_POST['due_date'] ?? '') ?: null;
                    $customerName = trim($_POST['customer_name'] ?? '') ?: null;
                    $customerPhone = trim($_POST['customer_phone'] ?? '') ?: null;
                    $details = trim($_POST['details'] ?? '') ?: null;
                    $orderTitle    = trim($_POST['order_title'] ?? '');
                    $tgGovernorate = trim($_POST['tg_governorate'] ?? '');
                    $tgGovId       = isset($_POST['tg_gov_id']) && $_POST['tg_gov_id'] !== '' ? (int)$_POST['tg_gov_id'] : null;
                    $tgCity        = trim($_POST['tg_city'] ?? '');
                    $tgCityId      = isset($_POST['tg_city_id']) && $_POST['tg_city_id'] !== '' ? (int)$_POST['tg_city_id'] : null;
                    $tgWeight      = trim($_POST['tg_weight'] ?? '');
                    $tgParcelDesc  = trim($_POST['tg_parcel_desc'] ?? '');
                    $countedInput  = trim((string)($_POST['tg_pieces_count'] ?? ''));
                    $assignees = isset($_POST['assigned_to']) && is_array($_POST['assigned_to'])
                        ? array_filter(array_map('intval', $_POST['assigned_to']))
                        : [];
                    $shippingFees = 0;
                    if ($taskType !== 'telegraph' && isset($_POST['shipping_fees']) && $_POST['shipping_fees'] !== '') {
                        $shippingFees = (float) str_replace(',', '.', (string) $_POST['shipping_fees']);
                        if ($shippingFees < 0) $shippingFees = 0;
                    }
                    $discount = 0;
                    if (isset($_POST['discount']) && $_POST['discount'] !== '') {
                        $discount = (float) str_replace(',', '.', (string) $_POST['discount']);
                        if ($discount < 0) $discount = 0;
                    }
                    $advancePayment = 0;
                    if (isset($_POST['advance_payment']) && $_POST['advance_payment'] !== '') {
                        $advancePayment = (float) str_replace(',', '.', (string) $_POST['advance_payment']);
                        if ($advancePayment < 0) $advancePayment = 0;
                    }
                    $products = [];
                    if (isset($_POST['products']) && is_array($_POST['products'])) {
                        foreach ($_POST['products'] as $p) {
                            $name = trim($p['name'] ?? '');
                            if ($name === '') continue;
                            $qty = isset($p['quantity']) && $p['quantity'] !== '' ? (float)str_replace(',', '.', $p['quantity']) : null;
                            $unit = in_array(trim($p['unit'] ?? 'قطعة'), ['قطعة','كرتونة','عبوة','شرينك','دسته','جرام','كيلو'], true) ? trim($p['unit']) : 'قطعة';
                            $price = isset($p['price']) && $p['price'] !== '' ? (float)str_replace(',', '.', $p['price']) : null;
                            $lineTotal = isset($p['line_total']) && $p['line_total'] !== '' ? (float)str_replace(',', '.', $p['line_total']) : null;
                            $products[] = ['name' => $name, 'quantity' => $qty, 'unit' => $unit, 'price' => $price, 'line_total' => $lineTotal, 'item_type' => trim($p['item_type'] ?? '')];
                        }
                    }
                    $counted = 0;
                    if ($countedInput !== '') {
                        $normalizedCounted = str_replace(',', '.', $countedInput);
                        if (is_numeric($normalizedCounted) && (float)$normalizedCounted > 0) {
                            $counted = max(1, (int)ceil((float)$normalizedCounted));
                        }
                    }
                    if ($counted <= 0) {
                        $autoPiecesCount = 0;
                        foreach ($products as $productForCount) {
                            $qtyForCount = isset($productForCount['quantity']) ? (float)$productForCount['quantity'] : 0;
                            if ($qtyForCount > 0) $autoPiecesCount += $qtyForCount;
                        }
                        $counted = max(1, (int)ceil($autoPiecesCount));
                    }

                    $notesParts = [];
                    if ($orderTitle !== '') {
                        $notesParts[] = 'عنوان  :' . $orderTitle;
                    }
                    if ($tgGovernorate !== '') {
                        $notesParts[] = 'المحافظة :' . $tgGovernorate;
                    }
                    if ($tgCity !== '') {   
                        $notesParts[] = 'المدينة :' . $tgCity;
                    }
                    if ($tgWeight !== '') {
                        $notesParts[] = 'الوزن :' . $tgWeight;
                    }
                    if ($counted > 0) {
                        $notesParts[] = 'عدد القطع :' . $counted;
                    }
                    if ($tgParcelDesc !== '') {
                        $notesParts[] = 'وصف البضاعة :' . $tgParcelDesc;
                    }
                    if ($details) $notesParts[] = $details;
                    if (!empty($products)) {
                        $productsJson = json_encode($products, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        $notesParts[] = '[PRODUCTS_JSON]:' . $productsJson;
                        $lines = [];
                        foreach ($products as $p) {
                            $lines[] = 'المنتج: ' . $p['name'] . ($p['quantity'] !== null ? ' - الكمية: ' . $p['quantity'] : '');
                        }
                        $notesParts[] = implode("\n", $lines);
                    }
                    $assigneeNames = [];
                    foreach ($assignees as $aid) {
                        $u = $db->queryOne("SELECT full_name FROM users WHERE id = ?", [$aid]);
                        if ($u) $assigneeNames[] = $u['full_name'];
                    }
                    if (count($assignees) > 1) {
                        $notesParts[] = 'العمال المخصصون: ' . implode(', ', $assigneeNames) . "\n[ASSIGNED_WORKERS_IDS]:" . implode(',', $assignees);
                    } elseif (count($assignees) === 1) {
                        $notesParts[] = 'العامل المخصص: ' . ($assigneeNames[0] ?? '') . "\n[ASSIGNED_WORKERS_IDS]:" . $assignees[0];
                    }
                    if ($shippingFees > 0) {
                        $notesParts[] = 'رسوم الشحن :' . $shippingFees;
                    }
                    if ($discount > 0) {
                        $notesParts[] = 'الخصم :' . $discount;
                    }
                    if ($advancePayment > 0) {
                        $notesParts[] = '[ADVANCE_PAYMENT]:' . $advancePayment;
                    }
                    $notesValue = !empty($notesParts) ? implode("\n\n", $notesParts) : null;
                    $firstProduct = !empty($products) ? $products[0] : null;
                    $productName = $firstProduct['name'] ?? null;
                    $quantity = $firstProduct['quantity'] ?? null;
                    $unit = $firstProduct['unit'] ?? 'قطعة';
                    if (!empty($products)) {
                        $q = 0;
                        foreach ($products as $p) {
                            if ($p['quantity'] !== null) $q += $p['quantity'];
                        }
                        $quantity = $q > 0 ? $q : null;
                    }
                    $templateId = null;
                    $productId = null;
                    if ($productName) {
                        $tn = trim($productName);
                        $t = $db->queryOne("SELECT id FROM unified_product_templates WHERE (product_name = ? OR CONCAT('قالب #', id) = ?) AND status = 'active' LIMIT 1", [$tn, $tn]);
                        if ($t) $templateId = (int)$t['id'];
                        if (!$templateId) {
                            $t = $db->queryOne("SELECT id FROM product_templates WHERE (product_name = ? OR CONCAT('قالب #', id) = ?) AND status = 'active' LIMIT 1", [$tn, $tn]);
                            if ($t) $templateId = (int)$t['id'];
                        }
                        if (!$templateId) {
                            $p = $db->queryOne("SELECT id FROM products WHERE name = ? AND status = 'active' LIMIT 1", [$tn]);
                            if ($p) $productId = (int)$p['id'];
                        }
                    }
                    $firstAssignee = !empty($assignees) ? (int)$assignees[0] : 0;
                    $relatedType = 'manager_' . $taskType;
                    // إذا تغير اسم أو هاتف العميل نُصفّر local_customer_id لإعادة ربطه صحيحاً عند الاعتماد
                    $oldName  = trim((string)($task['customer_name'] ?? ''));
                    $oldPhone = trim((string)($task['customer_phone'] ?? ''));
                    $newName  = $customerName ?? '';
                    $newPhone = $customerPhone ?? '';
                    $customerChanged = ($newName !== $oldName || $newPhone !== $oldPhone);
                    $newLocalCustomerId = $customerChanged ? null : ($task['local_customer_id'] ?: null);
                    $db->execute(
                        "UPDATE tasks SET task_type = ?, related_type = ?, priority = ?, due_date = ?, customer_name = ?, customer_phone = ?,
                         notes = ?, product_name = ?, quantity = ?, unit = ?, template_id = ?, product_id = ?, assigned_to = ?, local_customer_id = ?
                         WHERE id = ?",
                        [
                            $taskType, $relatedType, $priority, $dueDate, $customerName, $customerPhone,
                            $notesValue, $productName, $quantity, $unit, $templateId, $productId ?: null, $firstAssignee ?: null,
                            $newLocalCustomerId,
                            $taskId
                        ]
                    );
                    // تحديث بيانات التليجراف في سجل العميل عند تعديل الأوردر
                    if ($taskType === 'telegraph' && $customerName) {
                        $localCustomersTable2 = $db->queryOne("SHOW TABLES LIKE 'local_customers'");
                        if (!empty($localCustomersTable2)) {
                            $custIdToUpdate = $newLocalCustomerId;
                            if (!$custIdToUpdate && $customerName) {
                                $found = $db->queryOne("SELECT id FROM local_customers WHERE name = ?", [$customerName]);
                                if ($found) $custIdToUpdate = (int)$found['id'];
                            }
                            if ($custIdToUpdate) {
                                $db->execute(
                                    "UPDATE local_customers SET tg_governorate = ?, tg_gov_id = ?, tg_city = ?, tg_city_id = ?, address = COALESCE(NULLIF(?, ''), address) WHERE id = ?",
                                    [
                                        $tgGovernorate !== '' ? $tgGovernorate : null,
                                        $tgGovId,
                                        $tgCity !== '' ? $tgCity : null,
                                        $tgCityId,
                                        $orderTitle,
                                        $custIdToUpdate,
                                    ]
                                );
                                // ربط العميل بالأوردر إذا كان local_customer_id فارغاً (مثلاً بعد تغيير اسم العميل)
                                if (!$newLocalCustomerId) {
                                    $db->execute("UPDATE tasks SET local_customer_id = ? WHERE id = ?", [$custIdToUpdate, $taskId]);
                                }
                            }
                        }
                    }
                    $successMessage = 'تم تعديل الأوردر بنجاح.';
                    $userRole = in_array($currentUser['role'] ?? '', ['accountant', 'sales'], true) ? ($currentUser['role'] ?? 'manager') : 'manager';
                    preventDuplicateSubmission($successMessage, ['page' => 'production_tasks', '_refresh' => time()], null, $userRole);
                    exit;
                }
            } catch (Exception $e) {
                $error = 'تعذر تعديل المهمة: ' . $e->getMessage();
            }
        }
    }
}

// جلب بيانات المهمة للتعديل (AJAX) — المدير والمحاسب والمطور
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_task_for_edit' && ($isAccountant || $isManager || $isDeveloper)) {
    $taskId = isset($_GET['task_id']) ? intval($_GET['task_id']) : 0;
    if ($taskId > 0) {
        $task = $db->queryOne("SELECT id, task_type, related_type, priority, due_date, customer_name, customer_phone, notes, description, product_name, quantity, unit FROM tasks WHERE id = ?", [$taskId]);
        if ($task) {
            $displayType = (strpos($task['related_type'] ?? '', 'manager_') === 0) ? substr($task['related_type'], 8) : ($task['task_type'] ?? 'shop_order');
            $notes = (string)($task['notes'] ?? '');
            $products = [];
            $assignees = [];
            // استخراج المنتجات — دعم الصيغتين: [PRODUCTS_JSON] و المنتجات :
            if (preg_match('/(?:\[PRODUCTS_JSON\]|المنتجات)\s*:\s*(\[.+?\])(?=\s*\n|\[ASSIGNED_WORKERS_IDS\]|$)/su', $notes, $m)) {
                $jsonStr = trim($m[1]);
                $decoded = json_decode($jsonStr, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $p) {
                        $products[] = [
                            'name' => trim((string)($p['name'] ?? '')),
                            'quantity' => isset($p['quantity']) ? (is_numeric($p['quantity']) ? (float)$p['quantity'] : null) : null,
                            'unit' => trim((string)($p['unit'] ?? 'قطعة')) ?: 'قطعة',
                            'price' => isset($p['price']) && $p['price'] !== '' && $p['price'] !== null ? (is_numeric($p['price']) ? (float)$p['price'] : null) : null,
                            'line_total' => isset($p['line_total']) && $p['line_total'] !== '' && $p['line_total'] !== null && is_numeric($p['line_total']) ? (float)$p['line_total'] : null,
                            'item_type' => trim((string)($p['item_type'] ?? ''))
                        ];
                    }
                }
            }
            // إذا لم نجد JSON، استخراج من النص "المنتج: X - الكمية: Y"
            if (empty($products) && preg_match_all('/المنتج:\s*([^\n]+?)(?:\s*-\s*الكمية:\s*([0-9.]+))?/u', $notes, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $products[] = [
                        'name' => trim($m[1]),
                        'quantity' => isset($m[2]) ? (float)$m[2] : null,
                        'unit' => trim((string)($task['unit'] ?? 'قطعة')) ?: 'قطعة',
                        'price' => null,
                        'line_total' => null
                    ];
                }
            }
            // إذا لم نجد منتجات، استخدام الحقول من الجدول
            if (empty($products)) {
                $pn = trim((string)($task['product_name'] ?? ''));
                $qty = isset($task['quantity']) ? (is_numeric($task['quantity']) ? (float)$task['quantity'] : null) : null;
                if ($pn !== '' || $qty !== null) {
                    $products[] = [
                        'name' => $pn,
                        'quantity' => $qty,
                        'unit' => trim((string)($task['unit'] ?? 'قطعة')) ?: 'قطعة',
                        'price' => null,
                        'line_total' => null
                    ];
                }
            }
            $shippingFees = 0;
            if (preg_match('/رسوم الشحن\s*:\s*([0-9.]+)/u', $notes, $m)) {
                $shippingFees = (float)$m[1];
            }
            $discount = 0;
            if (preg_match('/الخصم\s*:\s*([0-9.]+)/u', $notes, $m)) {
                $discount = (float)$m[1];
            }
            $orderTitle = '';
            if (preg_match('/عنوان\s*:\s*([^\n]+)/u', $notes, $m)) {
                $orderTitle = trim($m[1]);
            }
            $tgGovernorate = '';
            if (preg_match('/المحافظة\s*:\s*([^\n]+)/u', $notes, $m)) {
                $tgGovernorate = trim($m[1]);
            }
            $tgCity = '';
            if (preg_match('/المدينة\s*:\s*([^\n]+)/u', $notes, $m)) {
                $tgCity = trim($m[1]);
            }
            $tgWeight = '';
            if (preg_match('/الوزن\s*:\s*([^\n]+)/u', $notes, $m)) {
                $tgWeight = trim($m[1]);
            }
            $tgParcelDesc = '';
            if (preg_match('/وصف البضاعة\s*:\s*([^\n]+)/u', $notes, $m)) {
                $tgParcelDesc = trim($m[1]);
            }
            
            $tgPiecesCount = 0;
            foreach ($products as $pCount) {
                $qtyForCount = isset($pCount['quantity']) ? (float)$pCount['quantity'] : 0;
                if ($qtyForCount > 0) {
                    $tgPiecesCount += $qtyForCount;
                }
            }
            $tgPiecesCount = max(1, (int)ceil($tgPiecesCount));
            if (preg_match('/عدد القطع\s*:\s*([0-9.]+)/u', $notes, $m)) {
                $tgPiecesCount = max(1, (int)ceil((float)$m[1]));
            }
            $advancePayment = 0;
            if (preg_match('/\[ADVANCE_PAYMENT\]:\s*([0-9.]+)/', $notes, $m)) {
                $advancePayment = (float)$m[1];
            }
            // استخراج العمال المخصصين
            if (preg_match('/\[ASSIGNED_WORKERS_IDS\]\s*:\s*([0-9,\s]+)/', $notes, $m)) {
                $assignees = array_filter(array_map('intval', preg_split('/[\s,]+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY)));
            }
            // استخراج التفاصيل (الوصف) — استخدام description أولاً ثم تنظيف notes
            $details = trim((string)($task['description'] ?? ''));
            if ($details === '') {
                $details = $notes;
                $details = preg_replace('/\[PRODUCTS_JSON\][\s\S]*?(?=\s*\n\s*\n|\[ASSIGNED_WORKERS_IDS\]|$)/', '', $details);
                $details = preg_replace('/المنتجات\s*:\s*\[.*?\](?=\s*\n|$)/su', '', $details);
                $details = preg_replace('/\[ASSIGNED_WORKERS_IDS\]\s*:[^\n]*/', '', $details);
                $details = preg_replace('/العمال المخصصون:[^\n]*/', '', $details);
                $details = preg_replace('/العامل المخصص:[^\n]*/', '', $details);
                $details = preg_replace('/المنتج:\s*[^\n]+/m', '', $details);
                $details = preg_replace('/رسوم الشحن\s*:\s*[0-9.]+/u', '', $details);
                $details = preg_replace('/الخصم\s*:\s*[0-9.]+/u', '', $details);
                $details = preg_replace('/\[ADVANCE_PAYMENT\]:\s*[0-9.]+/', '', $details);
                $details = preg_replace('/عنوان\s*:\s*[^\n]+/u', '', $details);
                $details = preg_replace('/المحافظة\s*:\s*[^\n]+/u', '', $details);
                $details = preg_replace('/المدينة\s*:\s*[^\n]+/u', '', $details);
                $details = preg_replace('/الوزن\s*:\s*[^\n]+/u', '', $details);
                $details = preg_replace('/عدد القطع\s*:\s*[^\n]+/u', '', $details);
                $details = preg_replace('/وصف البضاعة\s*:\s*[^\n]+/u', '', $details);
                $details = preg_replace('/\n\s*\n\s*\n+/', "\n\n", trim($details));
            }
            // تنسيق تاريخ الاستحقاق لـ input type="date" (YYYY-MM-DD)
            $dueDate = $task['due_date'] ?? '';
            if ($dueDate !== '' && $dueDate !== null) {
                $dueDate = trim((string)$dueDate);
                if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $dueDate, $d)) {
                    $dueDate = $d[1] . '-' . $d[2] . '-' . $d[3];
                }
            } else {
                $dueDate = '';
            }
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'success' => true,
                'task' => [
                    'id' => (int)$task['id'],
                    'task_type' => $displayType,
                    'priority' => $task['priority'] ?? 'normal',
                    'due_date' => $dueDate,
                    'customer_name' => trim((string)($task['customer_name'] ?? '')),
                    'customer_phone' => trim((string)($task['customer_phone'] ?? '')),
                    'details' => $details,
                    'products' => $products,
                    'assignees' => array_values($assignees),
                    'shipping_fees' => $shippingFees,
                    'discount' => $discount,
                    'advance_payment' => $advancePayment,
                    'order_title' => $orderTitle,
                    'tg_governorate' => $tgGovernorate,
                    'tg_city' => $tgCity,
                    'tg_weight' => $tgWeight,
                    'tg_pieces_count' => $tgPiecesCount,
                    'tg_parcel_desc' => $tgParcelDesc
                ]
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false]);
    exit;
}

// إيصال مختصر للمهمة: رقم الأوردر (إن وُجد) + المنتجات والكميات فقط
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_task_receipt' && isset($_GET['task_id'])) {
    $tid = (int)$_GET['task_id'];
    if ($tid > 0) {
        $task = $db->queryOne("SELECT id, related_type, related_id, notes, product_name, quantity, unit FROM tasks WHERE id = ?", [$tid]);
        if ($task) {
            $orderNumber = null;
            $items = [];
            $hasOrder = !empty($task['related_type']) && (string)$task['related_type'] === 'customer_order' && !empty($task['related_id']);
            $orderId = $hasOrder ? (int)$task['related_id'] : 0;
            if ($orderId > 0) {
                $orderTableCheck = $db->queryOne("SHOW TABLES LIKE 'customer_orders'");
                if (!empty($orderTableCheck)) {
                    $order = $db->queryOne("SELECT order_number FROM customer_orders WHERE id = ?", [$orderId]);
                    if ($order) {
                        $orderNumber = $order['order_number'] ?? (string)$orderId;
                        $itemsTable = 'order_items';
                        $itemsCheck = $db->queryOne("SHOW TABLES LIKE 'customer_order_items'");
                        if (!empty($itemsCheck)) $itemsTable = 'customer_order_items';
                        $rows = $db->query("SELECT oi.*, COALESCE(oi.product_name, p.name) AS display_name FROM {$itemsTable} oi LEFT JOIN products p ON oi.product_id = p.id WHERE oi.order_id = ? ORDER BY oi.id", [$orderId]);
                        foreach ($rows as $row) {
                            $items[] = ['product_name' => $row['display_name'] ?? $row['product_name'] ?? '-', 'quantity' => $row['quantity'] ?? 0, 'unit' => $row['unit'] ?? 'قطعة'];
                        }
                    }
                }
            }
            if (empty($items)) {
                $notes = (string)($task['notes'] ?? '');
                $products = [];
                if (preg_match('/(?:\[PRODUCTS_JSON\]|المنتجات)\s*:\s*(\[.+?\])(?=\s*\n|\[ASSIGNED_WORKERS_IDS\]|$)/su', $notes, $m)) {
                    $decoded = json_decode(trim($m[1]), true);
                    if (is_array($decoded)) {
                        foreach ($decoded as $p) {
                            $products[] = ['name' => trim((string)($p['name'] ?? '')), 'quantity' => isset($p['quantity']) ? (float)$p['quantity'] : null, 'unit' => trim((string)($p['unit'] ?? 'قطعة')) ?: 'قطعة'];
                        }
                    }
                }
                if (empty($products) && preg_match_all('/المنتج:\s*([^\n]+?)(?:\s*-\s*الكمية:\s*([0-9.]+))?/u', $notes, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $m) {
                        $products[] = ['name' => trim($m[1]), 'quantity' => isset($m[2]) ? (float)$m[2] : null, 'unit' => trim((string)($task['unit'] ?? 'قطعة')) ?: 'قطعة'];
                    }
                }
                if (empty($products)) {
                    $pn = trim((string)($task['product_name'] ?? ''));
                    $qty = isset($task['quantity']) ? (float)$task['quantity'] : null;
                    if ($pn !== '' || $qty !== null) {
                        $products[] = ['name' => $pn, 'quantity' => $qty, 'unit' => trim((string)($task['unit'] ?? 'قطعة')) ?: 'قطعة'];
                    }
                }
                foreach ($products as $p) {
                    $items[] = ['product_name' => $p['name'] ?: '-', 'quantity' => $p['quantity'] ?? 0, 'unit' => $p['unit'] ?? 'قطعة'];
                }
            }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true, 'order_number' => $orderNumber, 'task_id' => (int)$task['id'], 'items' => $items], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false]);
    exit;
}

// جلب سجل مشتريات العميل الكامل (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_customer_purchase_history' && ($isAccountant || $isManager || $isDeveloper)) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    ob_start();
    try {
        $customerId = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
        if ($customerId <= 0) {
            ob_end_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'orders' => []], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $taskIds = [];
        $ctpMap  = [];

        // جلب من customer_task_purchases
        try {
            $ctpCheck = $db->queryOne("SHOW TABLES LIKE 'customer_task_purchases'");
            if (!empty($ctpCheck)) {
                $ctpRows = $db->query(
                    "SELECT task_id, task_number, total_amount, task_date FROM customer_task_purchases WHERE local_customer_id = ? ORDER BY task_date DESC, id DESC LIMIT 50",
                    [$customerId]
                );
                foreach ($ctpRows as $r) {
                    $tid2 = (int)$r['task_id'];
                    $taskIds[] = $tid2;
                    $ctpMap[$tid2] = $r;
                }
            }
        } catch (Throwable $ignored) {}

        // أيضاً الأوردرات المرتبطة مباشرة
        try {
            $directRows = $db->query(
                "SELECT id FROM tasks WHERE local_customer_id = ? ORDER BY created_at DESC LIMIT 50",
                [$customerId]
            );
            foreach ($directRows as $r) {
                $taskIds[] = (int)$r['id'];
            }
        } catch (Throwable $ignored) {}

        $taskIds = array_values(array_unique($taskIds));
        $orders  = [];

        if (!empty($taskIds)) {
            $placeholders = implode(',', array_fill(0, count($taskIds), '?'));

            // استخدام عمود آمن — total_amount قد لا يوجد في نسخ قديمة
            $hasTotalCol = $db->queryOne("SHOW COLUMNS FROM tasks LIKE 'total_amount'");
            $selectTotal = !empty($hasTotalCol) ? ', total_amount' : '';

            $rows = $db->query(
                "SELECT id, title, created_at, notes{$selectTotal} FROM tasks WHERE id IN ({$placeholders}) ORDER BY created_at DESC",
                $taskIds
            );

            foreach ($rows as $task) {
                $tid   = (int)$task['id'];
                $notes = (string)($task['notes'] ?? '');
                $products = [];

                if (preg_match('/(?:\[PRODUCTS_JSON\]|المنتجات)\s*:\s*(\[.+?\])(?=\s*\n|\[ASSIGNED_WORKERS_IDS\]|$)/su', $notes, $m)) {
                    $decoded = json_decode(trim($m[1]), true);
                    if (is_array($decoded)) {
                        foreach ($decoded as $p) {
                            $pName = trim((string)($p['name'] ?? ''));
                            if ($pName === '') continue;
                            $products[] = [
                                'name'       => $pName,
                                'quantity'   => isset($p['quantity']) && is_numeric($p['quantity']) ? (float)$p['quantity'] : null,
                                'unit'       => trim((string)($p['unit'] ?? 'قطعة')) ?: 'قطعة',
                                'price'      => isset($p['price']) && is_numeric($p['price']) ? (float)$p['price'] : null,
                                'line_total' => isset($p['line_total']) && is_numeric($p['line_total']) ? (float)$p['line_total'] : null,
                            ];
                        }
                    }
                }

                $taskNum = isset($ctpMap[$tid]) ? ($ctpMap[$tid]['task_number'] ?? null) : null;
                $total   = isset($ctpMap[$tid])
                    ? (float)($ctpMap[$tid]['total_amount'] ?? 0)
                    : (isset($task['total_amount']) ? (float)$task['total_amount'] : 0);
                $date    = isset($ctpMap[$tid])
                    ? ($ctpMap[$tid]['task_date'] ?? substr($task['created_at'], 0, 10))
                    : substr($task['created_at'], 0, 10);

                $orders[] = [
                    'task_id'     => $tid,
                    'task_number' => $taskNum,
                    'title'       => trim((string)($task['title'] ?? '')),
                    'date'        => $date,
                    'total'       => $total,
                    'products'    => $products,
                ];
            }
        }

        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'orders' => $orders], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => $e->getMessage(), 'orders' => []], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// قراءة رسائل النجاح/الخطأ من session بعد redirect
applyPRGPattern($error, $success);

// قراءة بيانات التحصيل المدفوع مقدماً (إن وجدت)
$collectionNotice = null;
if (!empty($_GET['collection_info'])) {
    try {
        $decoded = json_decode(base64_decode(urldecode($_GET['collection_info'])), true);
        if (is_array($decoded) && isset($decoded['advance_paid']) && $decoded['advance_paid'] > 0) {
            $collectionNotice = $decoded;
        }
    } catch (Exception $e) {}
}

/**
 * إحصائيات سريعة للمهام التي أنشأها المدير والمحاسب
 * المحاسب والمدير يرون جميع المهام التي أنشأها أي منهما
 */

// جلب معرفات المديرين والمحاسبين ومندوبي المبيعات مرة واحدة فقط (تُستخدم لاحقاً في الإحصائيات والقائمة)
$adminIds = [];
$adminPlaceholders = '';
if ($isAccountant || $isManager) {
    $adminUsers = $db->query("
        SELECT id FROM users
        WHERE role IN ('manager', 'accountant', 'sales') AND status = 'active'
    ");
    $adminIds = array_map(function($user) {
        return (int)$user['id'];
    }, $adminUsers);
    if (!empty($adminIds)) {
        $adminPlaceholders = implode(',', array_fill(0, count($adminIds), '?'));
    }
}

$statsTemplate = [
    'total' => 0,
    'pending' => 0,
    'received' => 0,
    'in_progress' => 0,
    'completed' => 0,
    'with_delegate' => 0,
    'with_driver' => 0,
    'with_shipping_company' => 0,
    'delivered' => 0,
    'returned' => 0,
    'cancelled' => 0
];

$stats = $statsTemplate;
try {
    if ($isAccountant || $isManager) {
        if (!empty($adminIds)) {
            $counts = $db->query("
                SELECT status, COUNT(*) as total
                FROM tasks
                WHERE created_by IN ($adminPlaceholders)
                AND status != 'cancelled'
                GROUP BY status
            ", $adminIds);
        } else {
            $counts = [];
        }
    } elseif ($isSales) {
        $counts = $db->query("
            SELECT status, COUNT(*) as total
            FROM tasks
            WHERE (created_by = ? OR status = 'with_delegate')
            AND status != 'cancelled'
            GROUP BY status
        ", [$currentUser['id']]);
    } else {
        $counts = $db->query("
            SELECT status, COUNT(*) as total
            FROM tasks
            WHERE created_by = ?
            AND status != 'cancelled'
            GROUP BY status
        ", [$currentUser['id']]);
    }

    foreach ($counts as $row) {
        $statusKey = $row['status'] ?? '';
        if (isset($stats[$statusKey])) {
            $stats[$statusKey] = (int)$row['total'];
        }
    }
    // حساب الإجمالي من مجموع الحالات (أدق من COUNT المنفرد ويتجنب truncation في بعض بيئات MySQL/PHP)
    $stats['total'] = (int)$stats['pending'] + (int)$stats['received'] + (int)$stats['in_progress']
        + (int)$stats['completed'] + (int)$stats['with_delegate'] + (int)$stats['with_driver'] + (int)$stats['with_shipping_company'] + (int)$stats['delivered'] + (int)$stats['returned'];
} catch (Exception $e) {
    error_log('Manager task stats error: ' . $e->getMessage());
}

// تحميل مسودات المستخدم الحالي
$taskDrafts = [];
try {
    $taskDrafts = $db->query("SELECT id, draft_name, created_at, updated_at FROM task_drafts WHERE created_by = ? ORDER BY COALESCE(updated_at, created_at) DESC LIMIT 50", [$currentUser['id']]) ?: [];
} catch (Exception $e) { $taskDrafts = []; }

$recentTasks = [];
$statusStyles = [
    'pending' => ['class' => 'warning', 'label' => 'معلقة'],
    'completed' => ['class' => 'success', 'label' => 'مكتملة'],
    'with_delegate' => ['class' => 'info', 'label' => 'مع المندوب'],
    'with_driver' => ['class' => 'primary', 'label' => 'مع السائق'],
    'with_shipping_company' => ['class' => 'warning', 'label' => 'مع شركة الشحن'],
    'delivered' => ['class' => 'success', 'label' => 'تم التوصيل'],
    'returned' => ['class' => 'secondary', 'label' => 'تم الارجاع'],
    'cancelled' => ['class' => 'danger', 'label' => 'ملغاة']
];

// طلب تفاصيل الأوردر لعرضها في المودال (إيصال الأوردر)
if (!empty($_GET['get_order_receipt']) && isset($_GET['order_id'])) {
    $orderId = (int) $_GET['order_id'];
    if ($orderId > 0) {
        $orderTableCheck = $db->queryOne("SHOW TABLES LIKE 'customer_orders'");
        if (!empty($orderTableCheck)) {
            $order = $db->queryOne(
                "SELECT o.*, c.name AS customer_name, c.phone AS customer_phone, c.address AS customer_address
                 FROM customer_orders o
                 LEFT JOIN customers c ON o.customer_id = c.id
                 WHERE o.id = ?",
                [$orderId]
            );
            if ($order) {
                $itemsTable = 'order_items';
                $itemsCheck = $db->queryOne("SHOW TABLES LIKE 'customer_order_items'");
                if (!empty($itemsCheck)) {
                    $itemsTable = 'customer_order_items';
                }
                $items = $db->query(
                    "SELECT oi.*, COALESCE(oi.product_name, p.name) AS display_name FROM {$itemsTable} oi LEFT JOIN products p ON oi.product_id = p.id WHERE oi.order_id = ? ORDER BY oi.id",
                    [$orderId]
                );
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => true,
                    'order' => [
                        'order_number' => $order['order_number'] ?? '',
                        'customer_name' => $order['customer_name'] ?? '-',
                        'customer_phone' => $order['customer_phone'] ?? '',
                        'customer_address' => $order['customer_address'] ?? '',
                        'order_date' => $order['order_date'] ?? '',
                        'delivery_date' => $order['delivery_date'] ?? '',
                        'total_amount' => $order['total_amount'] ?? 0,
                        'notes' => $order['notes'] ?? '',
                    ],
                    'items' => array_map(function ($row) {
                        return [
                            'product_name' => $row['display_name'] ?? $row['product_name'] ?? '-',
                            'quantity' => $row['quantity'] ?? 0,
                            'unit' => $row['unit'] ?? 'قطعة',
                        ];
                    }, $items),
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'الطلب غير موجود']);
    exit;
}

// Pagination لجدول آخر المهام
// Pagination
$tasksPageNum = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$tasksPerPage = 40;
$totalRecentTasks = 0;
$totalRecentPages = 1;

// Filter by status
$statusFilter = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$statusCondition = "";
$statusParams = [];

if ($statusFilter && $statusFilter !== 'all') {
    $statusCondition = "AND t.status = ?";
    $statusParams[] = $statusFilter;
}

// البحث المتقدم والفلترة لجدول آخر المهام
$filterTaskId = isset($_GET['task_id']) ? trim((string)$_GET['task_id']) : '';
$filterCustomer = isset($_GET['search_customer']) ? trim((string)$_GET['search_customer']) : '';
$filterOrderId = isset($_GET['search_order_id']) ? trim((string)$_GET['search_order_id']) : '';
$filterTaskType = isset($_GET['task_type']) ? trim((string)$_GET['task_type']) : '';
$filterDueFrom = isset($_GET['due_date_from']) ? trim((string)$_GET['due_date_from']) : '';
$filterDueTo = isset($_GET['due_date_to']) ? trim((string)$_GET['due_date_to']) : '';
$filterOrderDateFrom = isset($_GET['order_date_from']) ? trim((string)$_GET['order_date_from']) : '';
$filterOrderDateTo = isset($_GET['order_date_to']) ? trim((string)$_GET['order_date_to']) : '';
$filterSearchText = isset($_GET['search_text']) ? trim((string)$_GET['search_text']) : '';

$searchConditions = '';
$searchParams = [];

if ($filterTaskId !== '') {
    $taskIdInt = (int) $filterTaskId;
    if ($taskIdInt > 0) {
        $searchConditions .= " AND t.id = ?";
        $searchParams[] = $taskIdInt;
    }
}
if ($filterCustomer !== '') {
    $searchConditions .= " AND (t.customer_name LIKE ? OR t.customer_phone LIKE ?)";
    $customerLike = '%' . $filterCustomer . '%';
    $searchParams[] = $customerLike;
    $searchParams[] = $customerLike;
}
if ($filterOrderId !== '') {
    $orderIdInt = (int) $filterOrderId;
    if ($orderIdInt > 0) {
        $searchConditions .= " AND t.related_type = 'customer_order' AND t.related_id = ?";
        $searchParams[] = $orderIdInt;
    }
}
if ($filterTaskType !== '') {
    $searchConditions .= " AND (t.task_type = ? OR t.related_type = CONCAT('manager_', ?))";
    $searchParams[] = $filterTaskType;
    $searchParams[] = $filterTaskType;
}
if ($filterDueFrom !== '') {
    $searchConditions .= " AND t.due_date >= ?";
    $searchParams[] = $filterDueFrom;
}
if ($filterDueTo !== '') {
    $searchConditions .= " AND t.due_date <= ?";
    $searchParams[] = $filterDueTo;
}
if ($filterOrderDateFrom !== '') {
    $searchConditions .= " AND t.created_at >= ?";
    $searchParams[] = $filterOrderDateFrom . ' 00:00:00';
}
if ($filterOrderDateTo !== '') {
    $searchConditions .= " AND t.created_at <= ?";
    $searchParams[] = $filterOrderDateTo . ' 23:59:59';
}
if ($filterSearchText !== '') {
    $searchConditions .= " AND (t.title LIKE ? OR t.notes LIKE ? OR t.customer_name LIKE ? OR t.customer_phone LIKE ?)";
    $textLike = '%' . $filterSearchText . '%';
    $searchParams[] = $textLike;
    $searchParams[] = $textLike;
    $searchParams[] = $textLike;
    $searchParams[] = $textLike;
}

try {
    // جلب عدد المهام الإجمالي (للتقسيم) - يستخدم adminIds المحسوبة مسبقاً
    if ($isAccountant || $isManager) {
        if (!empty($adminIds)) {
            $countParams = array_merge($adminIds, $statusParams, $searchParams);

            $totalRow = $db->queryOne("
                SELECT COUNT(*) AS total FROM tasks t
                WHERE t.created_by IN ($adminPlaceholders) AND t.status != 'cancelled' $statusCondition $searchConditions
            ", $countParams);
            $totalRecentTasks = isset($totalRow['total']) ? (int)$totalRow['total'] : 0;
        }
    } elseif ($isSales) {
        $countParams = array_merge([$currentUser['id']], $statusParams, $searchParams);
        $totalRow = $db->queryOne("
            SELECT COUNT(*) AS total FROM tasks t
            WHERE (t.created_by = ? OR t.status = 'with_delegate') AND t.status != 'cancelled' $statusCondition $searchConditions
        ", $countParams);
        $totalRecentTasks = isset($totalRow['total']) ? (int)$totalRow['total'] : 0;
    } else {
        $countParams = array_merge([$currentUser['id']], $statusParams, $searchParams);
        $totalRow = $db->queryOne("
            SELECT COUNT(*) AS total FROM tasks t
            WHERE t.created_by = ? AND t.status != 'cancelled' $statusCondition $searchConditions
        ", $countParams);
        $totalRecentTasks = isset($totalRow['total']) ? (int)$totalRow['total'] : 0;
    }
    
    $totalRecentPages = max(1, (int)ceil($totalRecentTasks / $tasksPerPage));
    $tasksPageNum = min($tasksPageNum, $totalRecentPages);
    $tasksOffset = ($tasksPageNum - 1) * $tasksPerPage;

    // جلب المهام المحدثة مع التقسيم - يستخدم adminIds المحسوبة مسبقاً
    if ($isAccountant || $isManager) {
        if (!empty($adminIds)) {
            $queryParams = array_merge($adminIds, $statusParams, $searchParams, [$tasksPerPage, $tasksOffset]);

            $selectFields = "t.id, t.title, t.status, t.priority, t.due_date, t.created_at,
                   t.quantity, t.unit, t.customer_name, t.customer_phone, t.notes, t.product_id, t.related_type, t.related_id, t.task_type,
                   t.local_customer_id, t.total_amount,
                   COALESCE(t.receipt_print_count, 0) AS receipt_print_count,
                   u.full_name AS assigned_name, t.assigned_to,
                   uCreator.full_name AS creator_name, t.created_by,
                   uCreator.role AS creator_role";
            $joins = "LEFT JOIN users u ON t.assigned_to = u.id
                LEFT JOIN users uCreator ON t.created_by = uCreator.id";
            if ($hasStatusChangedBy) {
                $selectFields .= ", uStatus.full_name AS status_changed_by_name, t.status_changed_by";
                $joins .= " LEFT JOIN users uStatus ON t.status_changed_by = uStatus.id";
            }

            $recentTasks = $db->query("
                SELECT $selectFields
                FROM tasks t
                $joins
                WHERE t.created_by IN ($adminPlaceholders)
                AND t.status != 'cancelled'
                $statusCondition
                $searchConditions
                ORDER BY t.created_at DESC, t.id DESC
                LIMIT ? OFFSET ?
            ", $queryParams);
        } else {
            $recentTasks = [];
        }
    } elseif ($isSales) {
        // المندوب: أوردراته هو + أي أوردر حالته with_delegate
        $queryParams = array_merge([$currentUser['id']], $statusParams, $searchParams, [$tasksPerPage, $tasksOffset]);

        $selectFields = "t.id, t.title, t.status, t.priority, t.due_date, t.created_at,
               t.quantity, t.unit, t.customer_name, t.customer_phone, t.notes, t.product_id, t.related_type, t.related_id, t.task_type,
               t.local_customer_id, t.total_amount,
               COALESCE(t.receipt_print_count, 0) AS receipt_print_count,
               u.full_name AS assigned_name, t.assigned_to,
               uCreator.full_name AS creator_name, t.created_by,
               uCreator.role AS creator_role";
        $joins = "LEFT JOIN users u ON t.assigned_to = u.id
               LEFT JOIN users uCreator ON t.created_by = uCreator.id";
        if ($hasStatusChangedBy) {
            $selectFields .= ", uStatus.full_name AS status_changed_by_name, t.status_changed_by";
            $joins .= " LEFT JOIN users uStatus ON t.status_changed_by = uStatus.id";
        }

        $recentTasks = $db->query("
            SELECT $selectFields
            FROM tasks t
            $joins
            WHERE (t.created_by = ? OR t.status = 'with_delegate')
            AND t.status != 'cancelled'
            $statusCondition
            $searchConditions
            ORDER BY t.created_at DESC, t.id DESC
            LIMIT ? OFFSET ?
        ", $queryParams);
    } else {
        // للمستخدمين الآخرين، عرض المهام التي أنشأوها فقط
        $queryParams = array_merge([$currentUser['id']], $statusParams, $searchParams, [$tasksPerPage, $tasksOffset]);

        $selectFields = "t.id, t.title, t.status, t.priority, t.due_date, t.created_at,
               t.quantity, t.unit, t.customer_name, t.customer_phone, t.notes, t.product_id, t.related_type, t.related_id, t.task_type,
               t.local_customer_id, t.total_amount,
               COALESCE(t.receipt_print_count, 0) AS receipt_print_count,
               u.full_name AS assigned_name, t.assigned_to";
        $joins = "LEFT JOIN users u ON t.assigned_to = u.id";
        if ($hasStatusChangedBy) {
            $selectFields .= ", uStatus.full_name AS status_changed_by_name, t.status_changed_by";
            $joins .= " LEFT JOIN users uStatus ON t.status_changed_by = uStatus.id";
        }

        $recentTasks = $db->query("
            SELECT $selectFields
            FROM tasks t
            $joins
            WHERE t.created_by = ?
            AND t.status != 'cancelled'
            $statusCondition
            $searchConditions
            ORDER BY t.created_at DESC, t.id DESC
            LIMIT ? OFFSET ?
        ", $queryParams);
    }

    // معرفات المهام المعتمدة (المضافة لسجل المشتريات) لعرض/إخفاء زر اعتماد الفاتورة
    $approvedTaskIds = [];
    if (!empty($recentTasks)) {
        $taskIdsForApproved = array_values(array_filter(array_map(function($t) { return (int)($t['id'] ?? 0); }, $recentTasks)));
        if (!empty($taskIdsForApproved)) {
            $ph = implode(',', array_fill(0, count($taskIdsForApproved), '?'));
            $approvedRows = $db->query("SELECT task_id FROM customer_task_purchases WHERE task_id IN ($ph)", $taskIdsForApproved);
            $approvedTaskIds = array_column($approvedRows ?: [], 'task_id');
            try {
                $shippingApproved = $db->query("SELECT task_id FROM shipping_company_paper_invoices WHERE task_id IN ($ph) AND task_id IS NOT NULL", $taskIdsForApproved);
                foreach ($shippingApproved ?: [] as $row) {
                    $tid = (int)($row['task_id'] ?? 0);
                    if ($tid > 0 && !in_array($tid, $approvedTaskIds, true)) {
                        $approvedTaskIds[] = $tid;
                    }
                }
            } catch (Exception $e) {
                // الجدول أو العمود غير موجود - تجاهل
            }
        }
    }
    
    // === تجميع البيانات الإضافية بـ batch بدلاً من N+1 ===

    // 1) تجميع كل worker IDs و product IDs من المهام
    $allWorkerIds = [];
    $allProductIds = [];
    $allCreatorIds = [];
    foreach ($recentTasks as $task) {
        $notes = $task['notes'] ?? '';
        if (preg_match('/\[ASSIGNED_WORKERS_IDS\]:\s*([0-9,]+)/', $notes, $matches)) {
            foreach (array_filter(array_map('intval', explode(',', $matches[1]))) as $wid) {
                $allWorkerIds[$wid] = true;
            }
        }
        if (!empty($task['product_id'])) {
            $allProductIds[(int)$task['product_id']] = true;
        }
        if (!isset($task['creator_name']) && isset($task['created_by'])) {
            $allCreatorIds[(int)$task['created_by']] = true;
        } elseif (isset($task['created_by']) && !isset($task['creator_role'])) {
            $allCreatorIds[(int)$task['created_by']] = true;
        }
    }

    // 2) جلب أسماء العمال دفعة واحدة
    $workerNames = [];
    if (!empty($allWorkerIds)) {
        $wids = array_keys($allWorkerIds);
        $wph = implode(',', array_fill(0, count($wids), '?'));
        $wRows = $db->query("SELECT id, full_name FROM users WHERE id IN ($wph)", $wids);
        foreach ($wRows as $w) {
            $workerNames[(int)$w['id']] = $w['full_name'];
        }
    }

    // 3) جلب أسماء المنتجات دفعة واحدة
    $productNames = [];
    if (!empty($allProductIds)) {
        $pids = array_keys($allProductIds);
        $pph = implode(',', array_fill(0, count($pids), '?'));
        $pRows = $db->query("SELECT id, name FROM products WHERE id IN ($pph)", $pids);
        foreach ($pRows as $p) {
            $productNames[(int)$p['id']] = $p['name'];
        }
    }

    // 4) جلب بيانات المنشئين الناقصة دفعة واحدة
    $creatorData = [];
    if (!empty($allCreatorIds)) {
        $cids = array_keys($allCreatorIds);
        $cph = implode(',', array_fill(0, count($cids), '?'));
        $cRows = $db->query("SELECT id, full_name, role FROM users WHERE id IN ($cph)", $cids);
        foreach ($cRows as $c) {
            $creatorData[(int)$c['id']] = $c;
        }
    }

    // 5) كاش فحص جداول القوالب مرة واحدة
    $hasUnifiedTemplates = $_ptHasUnifiedTemplates;
    $hasProductTemplates = $_ptHasProductTemplates;

    // 6) تجميع أسماء المنتجات المستخرجة من notes للبحث في القوالب دفعة واحدة
    $tempProductNamesForTemplates = [];
    foreach ($recentTasks as $task) {
        $pid = (int)($task['product_id'] ?? 0);
        if ($pid > 0 && isset($productNames[$pid])) {
            continue; // لديه اسم من products
        }
        $notes = $task['notes'] ?? '';
        $tempName = null;
        if (!empty($notes)) {
            if (preg_match('/المنتج:\s*(.+?)\s*-\s*الكمية:/i', $notes, $m)) {
                $tempName = trim($m[1] ?? '');
            }
            if (empty($tempName) && preg_match('/المنتج:\s*(.+?)(?:\n|$)/i', $notes, $m2)) {
                $tempName = trim($m2[1] ?? '');
            }
            if (empty($tempName) && preg_match('/المنتج:\s*(.+?)(?:\s*-\s*|$)/i', $notes, $m3)) {
                $tempName = trim($m3[1] ?? '');
            }
            if (!empty($tempName)) {
                $tempName = trim(trim($tempName), '-');
                $tempName = trim($tempName);
                if (!empty($tempName)) {
                    $tempProductNamesForTemplates[$tempName] = true;
                }
            }
        }
    }

    // 7) البحث في القوالب دفعة واحدة
    $validTemplateNames = [];
    if (!empty($tempProductNamesForTemplates)) {
        $tNames = array_keys($tempProductNamesForTemplates);
        $tph = implode(',', array_fill(0, count($tNames), '?'));
        if ($hasUnifiedTemplates) {
            $tRows = $db->query("SELECT DISTINCT product_name FROM unified_product_templates WHERE product_name IN ($tph) AND status = 'active'", $tNames);
            foreach ($tRows as $tr) {
                $validTemplateNames[trim($tr['product_name'])] = trim($tr['product_name']);
            }
        }
        // البحث في product_templates للأسماء غير الموجودة في unified
        $missingNames = array_diff($tNames, array_keys($validTemplateNames));
        if (!empty($missingNames) && $hasProductTemplates) {
            $mNames = array_values($missingNames);
            $mph = implode(',', array_fill(0, count($mNames), '?'));
            $mRows = $db->query("SELECT DISTINCT product_name FROM product_templates WHERE product_name IN ($mph) AND status = 'active'", $mNames);
            foreach ($mRows as $mr) {
                $validTemplateNames[trim($mr['product_name'])] = trim($mr['product_name']);
            }
        }
    }

    // 8) تطبيق البيانات المجمعة على كل مهمة
    foreach ($recentTasks as &$task) {
        $notes = $task['notes'] ?? '';
        $allWorkers = [];

        // العمال
        if (preg_match('/\[ASSIGNED_WORKERS_IDS\]:\s*([0-9,]+)/', $notes, $matches)) {
            $workerIds = array_filter(array_map('intval', explode(',', $matches[1])));
            foreach ($workerIds as $wid) {
                if (isset($workerNames[$wid])) {
                    $allWorkers[] = $workerNames[$wid];
                }
            }
        }
        if (empty($allWorkers) && !empty($task['assigned_name'])) {
            $allWorkers[] = $task['assigned_name'];
        }

        // المنتج
        $extractedProductName = null;
        $tempProductName = null;
        $pid = (int)($task['product_id'] ?? 0);
        if ($pid > 0 && isset($productNames[$pid])) {
            $tempProductName = trim($productNames[$pid]);
        }
        if (empty($tempProductName) && !empty($notes)) {
            if (preg_match('/المنتج:\s*(.+?)\s*-\s*الكمية:/i', $notes, $productMatches)) {
                $tempProductName = trim($productMatches[1] ?? '');
            }
            if (empty($tempProductName) && preg_match('/المنتج:\s*(.+?)(?:\n|$)/i', $notes, $productMatches2)) {
                $tempProductName = trim($productMatches2[1] ?? '');
            }
            if (empty($tempProductName) && preg_match('/المنتج:\s*(.+?)(?:\s*-\s*|$)/i', $notes, $productMatches3)) {
                $tempProductName = trim($productMatches3[1] ?? '');
            }
            if (!empty($tempProductName)) {
                $tempProductName = trim(trim($tempProductName), '-');
                $tempProductName = trim($tempProductName);
            }
        }
        if (!empty($tempProductName)) {
            $extractedProductName = $validTemplateNames[$tempProductName] ?? $tempProductName;
        }

        $task['all_workers'] = $allWorkers;
        $task['workers_count'] = count($allWorkers);
        $task['extracted_product_name'] = $extractedProductName;

        // حساب الإجمالي النهائي - التلغراف يُحسب لاحقاً عبر AJAX لتجنب HTTP calls أثناء التحميل
        $taskDisplayType = (strpos($task['related_type'] ?? '', 'manager_') === 0) ? substr($task['related_type'], 8) : ($task['task_type'] ?? 'general');
        if ($taskDisplayType === 'telegraph') {
            // حساب مبدئي من notes بدون HTTP call - التحديث الدقيق يتم عبر AJAX
            $task['receipt_total'] = getTaskReceiptTotalFromNotes($task['notes'] ?? '');
            $task['_needs_telegraph_calc'] = true;
        } else {
            $task['receipt_total'] = getTaskReceiptTotalFromNotes($task['notes'] ?? '');
        }

        // إضافة creator_name و creator_role من البيانات المجمعة
        $cid = (int)($task['created_by'] ?? 0);
        if (!isset($task['creator_name']) && isset($creatorData[$cid])) {
            $task['creator_name'] = $creatorData[$cid]['full_name'];
            $task['creator_role'] = $creatorData[$cid]['role'];
        } elseif (isset($task['created_by']) && !isset($task['creator_role']) && isset($creatorData[$cid])) {
            $task['creator_role'] = $creatorData[$cid]['role'];
        }
    }
    unset($task);
} catch (Exception $e) {
    error_log('Manager recent tasks error: ' . $e->getMessage());
}

// =========================
// صفحة طباعة للبيانات (محدد/حسب فترة)
// =========================
$isSelectedExport = isset($_GET['export_recent_tasks_print']) && (string)$_GET['export_recent_tasks_print'] === '1';
$isPeriodExport = isset($_GET['export_recent_tasks_print_period']) && (string)$_GET['export_recent_tasks_print_period'] === '1';
if ($isSelectedExport || $isPeriodExport) {
    if (!$canPrintTasks) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'غير مصرح بتصدير البيانات';
        exit;
    }

    $selectedIds = [];
    if ($isSelectedExport && !empty($_GET['ids'])) {
        $rawIds = preg_split('/\s*,\s*/', (string)$_GET['ids'], -1, PREG_SPLIT_NO_EMPTY);
        if (is_array($rawIds)) {
            $selectedIds = array_values(array_filter(array_map('intval', $rawIds), function($v) {
                return $v > 0;
            }));
        }
    }

    $filtersForExport = [
        'status' => $statusFilter,
        'task_id' => $filterTaskId,
        'search_customer' => $filterCustomer,
        'task_type' => $filterTaskType,
        'due_date_from' => $filterDueFrom,
        'due_date_to' => $filterDueTo,
        'order_date_from' => $filterOrderDateFrom,
        'order_date_to' => $filterOrderDateTo,
        'search_text' => $filterSearchText,
    ];
    $filtersForExport = array_filter($filtersForExport, function($v) {
        return $v !== '' && $v !== null;
    });

    $exportRows = [];

    $tasksToPrint = $recentTasks;

    // عند التصدير حسب الفترة: جلب كل الطلبات في نطاق التواريخ (بدون Pagination)
    if ($isPeriodExport) {
        $exportLimit = 5000; // حد آمن للتصدير
        // في وضع "حسب الفترة" نريد كل الطلبات داخل نطاق تاريخ الطلب فقط
        $periodWhere = '';
        $periodParams = [];
        if ($filterOrderDateFrom !== '') {
            $periodWhere .= " AND DATE(t.created_at) >= ?";
            $periodParams[] = $filterOrderDateFrom;
        }
        if ($filterOrderDateTo !== '') {
            $periodWhere .= " AND DATE(t.created_at) <= ?";
            $periodParams[] = $filterOrderDateTo;
        }
        if ($isAccountant || $isManager) {
            $adminUsers = $db->query("
                SELECT id FROM users
                WHERE role IN ('manager', 'accountant') AND status = 'active'
            ");
            $adminIds = !empty($adminUsers) ? array_map(function($user) { return (int)$user['id']; }, $adminUsers) : [];
            if (!empty($adminIds)) {
                $placeholders = implode(',', array_fill(0, count($adminIds), '?'));
                $queryParamsExport = array_merge($adminIds, $periodParams, [$exportLimit]);
                $tasksToPrint = $db->query("
                    SELECT t.id, t.title, t.status, t.due_date, t.created_at,
                           t.quantity, t.unit, t.customer_name, t.customer_phone, t.notes,
                           t.product_id, t.related_type, t.related_id, t.task_type,
                           t.total_amount
                    FROM tasks t
                    WHERE t.created_by IN ($placeholders)
                    AND t.status != 'cancelled'
                    $periodWhere
                    ORDER BY t.created_at DESC, t.id DESC
                    LIMIT ?
                ", $queryParamsExport);
            } else {
                $tasksToPrint = [];
            }
        } else {
            $queryParamsExport = array_merge([$currentUser['id']], $periodParams, [$exportLimit]);
            $tasksToPrint = $db->query("
                SELECT t.id, t.title, t.status, t.due_date, t.created_at,
                       t.quantity, t.unit, t.customer_name, t.customer_phone, t.notes,
                       t.product_id, t.related_type, t.related_id, t.task_type,
                       t.total_amount
                FROM tasks t
                WHERE t.created_by = ?
                AND t.status != 'cancelled'
                $periodWhere
                ORDER BY t.created_at DESC, t.id DESC
                LIMIT ?
            ", $queryParamsExport);
        }
    }

    foreach ($tasksToPrint as $task) {
        $taskId = (int)($task['id'] ?? 0);
        if ($taskId <= 0) continue;
        if (!empty($selectedIds) && !in_array($taskId, $selectedIds, true)) continue;

        $orderDate = '';
        if (!empty($task['created_at'])) {
            $orderDate = date('Y-m-d', strtotime((string)$task['created_at']));
        }

        $customerName = trim((string)($task['customer_name'] ?? ''));
        $customerPhone = trim((string)($task['customer_phone'] ?? ''));
        $customer = $customerName !== '' ? $customerName : ($customerPhone !== '' ? $customerPhone : '-');

        // تفاصيل الأوردر: منتجات فقط (من [PRODUCTS_JSON] أو سطور "المنتج:"), مع شحن/خصم إن وُجد
        $notes = (string)($task['notes'] ?? '');
        $detailsLines = [];

        $products = [];
        if (preg_match('/(?:\[PRODUCTS_JSON\]|المنتجات)\s*:\s*(\[.+?\])(?=\s*\n|\[ASSIGNED_WORKERS_IDS\]|$)/su', $notes, $m)) {
            $decoded = json_decode(trim($m[1]), true);
            if (is_array($decoded)) {
                foreach ($decoded as $p) {
                    $pName = trim((string)($p['name'] ?? ''));
                    if ($pName === '') continue;
                    $products[] = [
                        'name' => $pName,
                        'quantity' => isset($p['quantity']) && is_numeric($p['quantity']) ? (float)$p['quantity'] : null,
                        'unit' => trim((string)($p['unit'] ?? 'قطعة')) ?: 'قطعة',
                    ];
                }
            }
        }

        if (empty($products) && preg_match_all('/المنتج:\s*([^\n]+?)(?:\s*-\s*الكمية:\s*([0-9.]+))?/u', $notes, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $products[] = [
                    'name' => trim((string)($m[1] ?? '')),
                    'quantity' => isset($m[2]) && $m[2] !== '' ? (float)$m[2] : null,
                    'unit' => trim((string)($task['unit'] ?? 'قطعة')) ?: 'قطعة',
                ];
            }
        }

        foreach ($products as $p) {
            $pName = trim((string)($p['name'] ?? '-'));
            $q = $p['quantity'] ?? null;
            $unit = trim((string)($p['unit'] ?? 'قطعة'));

            if ($q === null) {
                $detailsLines[] = $pName;
            } else {
                $qNum = is_numeric($q) ? (float)$q : 0;
                $qStr = rtrim(rtrim(number_format($qNum, 2, '.', ''), '0'), '.');
                $detailsLines[] = $pName . ' - ' . $qStr . ' ' . $unit;
            }
        }

        // شحن/خصم (اختياري)
        $shippingFees = 0.0;
        if (preg_match('/(?:\[SHIPPING_FEES\]|رسوم الشحن)\s*:\s*([0-9.]+)/u', $notes, $m)) $shippingFees = (float)$m[1];

        $discount = 0.0;
        if (preg_match('/(?:\[DISCOUNT\]|الخصم)\s*:\s*([0-9.]+)/u', $notes, $m)) $discount = (float)$m[1];

        if ($shippingFees > 0) $detailsLines[] = 'الشحن: ' . number_format($shippingFees, 2, '.', '');
        if ($discount > 0) $detailsLines[] = 'الخصم: ' . number_format($discount, 2, '.', '');

        $details = trim(implode("\n", $detailsLines));
        if ($details === '') {
            // fallback بسيط لو مفيش منتجات
            $details = trim($notes);
        }

        $finalTotal = getTaskReceiptTotalFromNotes($task['notes'] ?? '');
        $finalTotalStr = number_format((float)$finalTotal, 2, '.', '');

        $exportRows[] = [
            'تاريخ الطلب' => $orderDate,
            'رقم الطلب' => (string)$taskId,
            'اسم العميل' => $customer,
            'تفاصيل الاوردر' => $details,
            'الاجمالي النهائي' => $finalTotalStr,
        ];
    }

    if (empty($exportRows)) {
        $exportRows[] = [
            'تاريخ الطلب' => '',
            'رقم الطلب' => '',
            'اسم العميل' => '',
            'تفاصيل الاوردر' => 'لا توجد بيانات للتصدير',
            'الاجمالي النهائي' => '',
        ];
    }

    $headers = ['تاريخ الطلب', 'رقم الطلب', 'اسم العميل', 'تفاصيل الاوردر', 'الاجمالي النهائي'];

    // إصدار CSV كملف داخل reports ثم تنزيله عبر api/download_csv.php
    // (مطابق لطريقة إصدار تقارير ملفات CSV في النظام)
    $mode = $isPeriodExport ? '-الفتره-' : 'selected';
    $fileName = 'فواتير السيستم - ' . $mode . '-' . date('Y-m-d_His') . '.csv';

    $exportsDir = rtrim(REPORTS_PATH, '/\\') . DIRECTORY_SEPARATOR . 'exports';
    if (!is_dir($exportsDir)) {
        @mkdir($exportsDir, 0755, true);
    }

    $filePath = $exportsDir . DIRECTORY_SEPARATOR . $fileName;
    $out = @fopen($filePath, 'w');
    if ($out === false) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'فشل في إنشاء ملف CSV للتصدير';
        exit;
    }

    // BOM لدعم العربية في Excel
    fwrite($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($out, $headers);
    foreach ($exportRows as $row) {
        $line = [];
        foreach ($headers as $h) {
            $line[] = isset($row[$h]) ? (string)$row[$h] : '';
        }
        fputcsv($out, $line);
    }

    fclose($out);

    // المسار النسبي لـ reports المطلوب بواسطة api/download_csv.php
    $relativePath = 'reports/exports/' . $fileName;
    $downloadPath = getAbsoluteUrl('api/download_csv.php?file=' . urlencode($relativePath));
    header('Location: ' . $downloadPath);
    exit;
}

// بناء معاملات الرابط للفلترة والبحث (للاستخدام في التصفح والروابط)
$recentTasksQueryParams = ['page' => 'production_tasks'];
if ($statusFilter !== '') $recentTasksQueryParams['status'] = $statusFilter;
if ($filterTaskId !== '') $recentTasksQueryParams['task_id'] = $filterTaskId;
if ($filterCustomer !== '') $recentTasksQueryParams['search_customer'] = $filterCustomer;
if ($filterOrderId !== '') $recentTasksQueryParams['search_order_id'] = $filterOrderId;
if ($filterTaskType !== '') $recentTasksQueryParams['task_type'] = $filterTaskType;
if ($filterDueFrom !== '') $recentTasksQueryParams['due_date_from'] = $filterDueFrom;
if ($filterDueTo !== '') $recentTasksQueryParams['due_date_to'] = $filterDueTo;
if ($filterOrderDateFrom !== '') $recentTasksQueryParams['order_date_from'] = $filterOrderDateFrom;
if ($filterOrderDateTo !== '') $recentTasksQueryParams['order_date_to'] = $filterOrderDateTo;
if ($filterSearchText !== '') $recentTasksQueryParams['search_text'] = $filterSearchText;
$recentTasksQueryString = http_build_query($recentTasksQueryParams, '', '&', PHP_QUERY_RFC3986);

?>

<script>
(function() {
    'use strict';
    // تنظيف معاملات cache-bust القديمة من شريط العنوان (إن وجدت)
    var urlParams = new URLSearchParams(window.location.search);
    var changed = false;
    ['_t', '_nocache', '_v', '_refresh'].forEach(function(p) {
        if (urlParams.has(p)) {
            urlParams.delete(p);
            changed = true;
        }
    });
    if (changed) {
        var qs = urlParams.toString();
        var cleanUrl = window.location.pathname + (qs ? '?' + qs : '') + (window.location.hash || '');
        window.history.replaceState({}, '', cleanUrl);
    }
    // إعادة تحميل الصفحة عند الرجوع من bfcache لضمان بيانات حديثة
    window.addEventListener('pageshow', function(event) {
        if (event.persisted) {
            window.location.reload();
        }
    });
    // إخفاء شاشة التحميل العامة عند تحميل الصفحة (مهم عند الفلترة بتاريخ الطلب أو غيرها)
    if (typeof window.resetPageLoading === 'function') window.resetPageLoading();

    // منع الضغط المزدوج على بطاقات الفلترة (يسبب فقدان الجلسة بسبب طلبات متزامنة)
    var filterCardsClicked = false;
    document.addEventListener('click', function(e) {
        var cardLink = e.target.closest('.row.g-2.mb-3 a[href*="page=production_tasks"]');
        if (!cardLink) return;
        if (filterCardsClicked) {
            e.preventDefault();
            e.stopPropagation();
            return;
        }
        filterCardsClicked = true;
        setTimeout(function() { filterCardsClicked = false; }, 3000);
    }, true);
})();
</script>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-1"><i class="bi bi-list-task me-2"></i> الاوردرات  </h2>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" id="errorAlert" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" id="successAlert" role="alert">
            <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($collectionNotice): ?>
    <!-- Collapsible Collection Notice Card -->
    <div class="card shadow-sm mb-3">
        <div class="card-header bg-success text-white d-flex justify-content-between align-items-center py-2">
            <h6 class="mb-0 fw-semibold"><i class="bi bi-cash-coin me-2"></i>Collection Success</h6>
            <button type="button" class="btn btn-sm btn-outline-light toggle-cards-btn" data-target="collectionNoticeCard" data-bs-toggle="tooltip" title="Toggle card">
                <i class="bi bi-chevron-up toggle-icon"></i>
            </button>
        </div>
        <div class="card-body p-0 collapse show" id="collectionNoticeCardCollapse">
            <div class="card border-0 mb-0 collection-notice-card" id="collectionNoticeCard">
        <div class="card-header d-flex justify-content-between align-items-center py-2" style="background: linear-gradient(135deg,#16a34a,#22c55e); color:#fff;">
            <span class="fw-bold"><i class="bi bi-cash-coin me-2"></i>تم التحصيل بنجاح</span>
            <button type="button" class="btn-close btn-close-white btn-sm" onclick="document.getElementById('collectionNoticeCard').remove()"></button>
        </div>
        <div class="card-body py-3">
            <div class="row g-2">
                <div class="col-6 col-md-3">
                    <div class="text-muted small mb-1"><i class="bi bi-person me-1"></i>العميل</div>
                    <div class="fw-bold"><?php echo htmlspecialchars($collectionNotice['customer_name'] ?? '—'); ?></div>
                    <?php if (!empty($collectionNotice['customer_phone'])): ?>
                    <div class="text-muted small"><?php echo htmlspecialchars($collectionNotice['customer_phone']); ?></div>
                    <?php endif; ?>
                </div>
                <div class="col-6 col-md-3">
                    <div class="text-muted small mb-1"><i class="bi bi-hash me-1"></i>رقم الأوردر</div>
                    <div class="fw-bold">#<?php echo intval($collectionNotice['order_number']); ?></div>
                    <div class="text-muted small">إجمالي: <?php echo number_format((float)($collectionNotice['total_amount'] ?? 0), 2); ?> ج.م</div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="text-muted small mb-1"><i class="bi bi-wallet2 me-1"></i>المدفوع مقدماً</div>
                    <div class="fw-bold text-success"><?php echo number_format((float)($collectionNotice['advance_paid'] ?? 0), 2); ?> ج.م</div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="text-muted small mb-1"><i class="bi bi-hourglass-split me-1"></i>المتبقي للتحصيل</div>
                    <?php $rem = (float)($collectionNotice['remaining'] ?? 0); ?>
                    <div class="fw-bold <?php echo $rem > 0 ? 'text-danger' : 'text-success'; ?>">
                        <?php echo number_format($rem, 2); ?> ج.م
                    </div>
                    <div class="text-muted small">rpn: <?php echo number_format((float)($collectionNotice['new_balance'] ?? 0), 2); ?> .</div>
                </div>
            </div>
        </div>
    </div>
    </div>
    </div>
    </div>
    <?php endif; ?>

    <!-- Collapsible Status Filter Cards -->
    <div class="card shadow-sm mb-3">
        <div class="card-header bg-light d-flex justify-content-between align-items-center py-2">
            <h6 class="mb-0 fw-semibold"><i class="bi bi-funnel me-2"></i> الاحصائيات</h6>
            <button type="button" class="btn btn-sm btn-outline-secondary toggle-cards-btn" data-target="statusFilterCards" data-bs-toggle="tooltip" title="Toggle cards">
                <i class="bi bi-chevron-up toggle-icon"></i>
            </button>
        </div>
        <div class="card-body p-0 collapse show" id="statusFilterCardsCollapse">
            <div class="row g-2 p-3" id="statusFilterCards">
        <div class="col-6 col-sm-3 col-md-3">
            <a href="?page=production_tasks" class="text-decoration-none status-filter-card" data-status="all">
                <div class="card <?php echo $statusFilter === '' || $statusFilter === 'all' ? 'bg-primary text-white' : 'border-primary'; ?> h-100">
                    <div class="card-body text-center py-2 px-2">
                        <div class="<?php echo $statusFilter === '' || $statusFilter === 'all' ? 'text-white-50' : 'text-muted'; ?> small mb-1">إجمالي الاوردرات</div>
                        <div class="fs-5 <?php echo $statusFilter === '' || $statusFilter === 'all' ? 'text-white' : 'text-primary'; ?> fw-semibold" style="min-width: 3em; display: inline-block; overflow: visible;" title="إجمالي: <?php echo (int)$stats['total']; ?>"><?php echo (int)$stats['total']; ?></div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6 col-sm-3 col-md-3">
            <a href="?page=production_tasks&status=pending" class="text-decoration-none status-filter-card" data-status="pending">
                <div class="card <?php echo $statusFilter === 'pending' ? 'bg-warning text-dark' : 'border-warning'; ?> h-100">
                    <div class="card-body text-center py-2 px-2">
                        <div class="<?php echo $statusFilter === 'pending' ? 'text-dark-50' : 'text-muted'; ?> small mb-1">معلقة</div>
                        <div class="fs-5 <?php echo $statusFilter === 'pending' ? 'text-dark' : 'text-warning'; ?> fw-semibold"><?php echo $stats['pending']; ?></div>
                    </div>
                </div>
            </a>
        </div>

        <div class="col-6 col-sm-3 col-md-3">
            <a href="?page=production_tasks&status=completed" class="text-decoration-none status-filter-card" data-status="completed">
                <div class="card <?php echo $statusFilter === 'completed' ? 'bg-success text-white' : 'border-success'; ?> h-100">
                    <div class="card-body text-center py-2 px-2">
                        <div class="<?php echo $statusFilter === 'completed' ? 'text-white-50' : 'text-muted'; ?> small mb-1">مكتملة</div>
                        <div class="fs-5 <?php echo $statusFilter === 'completed' ? 'text-white' : 'text-success'; ?> fw-semibold"><?php echo $stats['completed']; ?></div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6 col-sm-3 col-md-3">
            <a href="?page=production_tasks&status=with_delegate" class="text-decoration-none status-filter-card" data-status="with_delegate">
                <div class="card <?php echo $statusFilter === 'with_delegate' ? 'bg-info text-white' : 'border-info'; ?> h-100">
                    <div class="card-body text-center py-2 px-2">
                        <div class="<?php echo $statusFilter === 'with_delegate' ? 'text-white-50' : 'text-muted'; ?> small mb-1">مع المندوب</div>
                        <div class="fs-5 <?php echo $statusFilter === 'with_delegate' ? 'text-white' : 'text-info'; ?> fw-semibold"><?php echo $stats['with_delegate']; ?></div>
                    </div>
                </div>
            </a>
        </div>

        <div class="col-6 col-sm-3 col-md-3">
            <a href="?page=production_tasks&status=with_shipping_company" class="text-decoration-none status-filter-card" data-status="with_shipping_company">
                <div class="card <?php echo $statusFilter === 'with_shipping_company' ? 'bg-warning text-dark' : 'border-warning'; ?> h-100">
                    <div class="card-body text-center py-2 px-2">
                        <div class="<?php echo $statusFilter === 'with_shipping_company' ? 'text-dark' : 'text-muted'; ?> small mb-1">مع شركة الشحن</div>
                        <div class="fs-5 <?php echo $statusFilter === 'with_shipping_company' ? 'text-dark' : 'text-warning'; ?> fw-semibold"><?php echo $stats['with_shipping_company']; ?></div>
                    </div>
                </div>
            </a>
        </div>

        <div class="col-6 col-sm-3 col-md-3">
            <a href="?page=production_tasks&status=with_driver" class="text-decoration-none status-filter-card" data-status="with_driver">
                <div class="card <?php echo $statusFilter === 'with_driver' ? 'bg-info text-white' : 'border-info'; ?> h-100">
                    <div class="card-body text-center py-2 px-2">
                        <div class="<?php echo $statusFilter === 'with_driver' ? 'text-white-50' : 'text-muted'; ?> small mb-1">مع السائق</div>
                        <div class="fs-5 <?php echo $statusFilter === 'with_driver' ? 'text-white' : 'text-info'; ?> fw-semibold"><?php echo $stats['with_driver']; ?></div>
                    </div>
                </div>
            </a>
        </div>
        
        <div class="col-6 col-sm-3 col-md-3">
            <a href="?page=production_tasks&status=delivered" class="text-decoration-none status-filter-card" data-status="delivered">
                <div class="card <?php echo $statusFilter === 'delivered' ? 'bg-success text-white' : 'border-success'; ?> h-100">
                    <div class="card-body text-center py-2 px-2">
                        <div class="<?php echo $statusFilter === 'delivered' ? 'text-white-50' : 'text-muted'; ?> small mb-1">تم التوصيل</div>
                        <div class="fs-5 <?php echo $statusFilter === 'delivered' ? 'text-white' : 'text-success'; ?> fw-semibold"><?php echo $stats['delivered']; ?></div>
                    </div>
                </div>
            </a>
        </div> 
        <div class="col-6 col-sm-3 col-md-3">
            <a href="?page=production_tasks&status=returned" class="text-decoration-none status-filter-card" data-status="returned">
                <div class="card <?php echo $statusFilter === 'returned' ? 'bg-secondary text-white' : 'border-secondary'; ?> h-100">
                    <div class="card-body text-center py-2 px-2">
                        <div class="<?php echo $statusFilter === 'returned' ? 'text-white-50' : 'text-muted'; ?> small mb-1">تم الارجاع</div>
                        <div class="fs-5 <?php echo $statusFilter === 'returned' ? 'text-white' : 'text-secondary'; ?> fw-semibold"><?php echo $stats['returned']; ?></div>
                    </div>
                </div>
            </a>
        </div>
            </div>
        </div>
    </div>

    <div class="d-inline-flex gap-2 mb-3">
        <button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#createTaskFormCollapse" aria-expanded="false" aria-controls="createTaskFormCollapse">
            <i class="bi bi-plus-circle me-1"></i> إنشاء أوردر جديد
        </button>
        <button class="btn btn-warning" type="button" data-bs-toggle="collapse" data-bs-target="#duplicateOrderCollapse" aria-expanded="false" aria-controls="duplicateOrderCollapse">
            <i class="bi bi-copy me-1"></i> تكرار أوردر
        </button>
    </div>

    <div class="collapse mb-2" id="duplicateOrderCollapse">
        <div class="card shadow-sm border-warning">
            <div class="card-header bg-warning bg-opacity-25">
                <h5 class="mb-0"><i class="bi bi-copy me-2"></i>تكرار أوردر موجود</h5>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">أدخل رقم الأوردر الموجود لفتح نموذج إنشاء أوردر جديد بنفس البيانات تماماً</p>
                <div class="row g-2 align-items-end">
                    <div class="col-auto">
                        <label class="form-label fw-semibold">رقم الأوردر</label>
                        <input type="number" class="form-control" id="duplicateOrderIdInput" placeholder="مثال: 123" min="1" style="width:170px;">
                    </div>
                    <div class="col-auto">
                        <button type="button" class="btn btn-warning" onclick="duplicateOrderById()">
                            <i class="bi bi-copy me-1"></i>تكرار
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="collapse" id="createTaskFormCollapse">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-plus-circle me-2"></i>إنشاء أوردر جديد</h5>
            </div>
            <div class="card-body">
                <form method="post" action="?page=production_tasks" id="createTaskForm">
                    <input type="hidden" name="action" value="create_production_task">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">نوع الاوردر</label>
                            <select class="form-select" name="task_type" id="taskTypeSelect" required>
                                <option value="shop_order">اوردر محل</option>
                                <option value="cash_customer">عميل نقدي</option>
                                <option value="telegraph">تليجراف</option>
                                <option value="shipping_company">شركة شحن</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">الأولوية</label>
                            <select class="form-select" name="priority">
                                <option value="normal" selected>عادية</option>
                                <option value="urgent">عاجلة</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">تاريخ التسليم</label>
                            <input type="date" class="form-control" name="due_date" value="">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">العميل</label>
                            <div class="customer-type-wrap d-flex flex-wrap gap-3 mb-2">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="customer_type_radio_task" id="ct_task_local" value="local" checked>
                                    <label class="form-check-label" for="ct_task_local">عميل محلي</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="customer_type_radio_task" id="ct_task_rep" value="rep">
                                    <label class="form-check-label" for="ct_task_rep">عميل مندوب</label>
                                </div>
                            </div>
                            <input type="hidden" name="customer_name" id="submit_customer_name" value="">
                            <div id="customer_select_local_task" class="customer-select-block mb-2">
                                <div class="search-wrap position-relative">
                                    <input type="text" id="local_customer_search_task" class="form-control form-control-sm" placeholder="اكتب للبحث أو أدخل اسم عميل جديد..." autocomplete="off">
                                    <input type="hidden" id="local_customer_id_task" name="local_customer_id" value="">
                                    <div id="local_customer_dropdown_task" class="search-dropdown-task d-none"></div>
                                </div>
                            </div>
                            <div id="customer_select_rep_task" class="customer-select-block mb-2 d-none">
                                <div class="search-wrap position-relative">
                                    <input type="text" id="rep_customer_search_task" class="form-control form-control-sm" placeholder="اكتب للبحث أو أدخل اسم عميل جديد..." autocomplete="off">
                                    <input type="hidden" id="rep_customer_id_task" value="">
                                    <div id="rep_customer_dropdown_task" class="search-dropdown-task d-none"></div>
                                </div>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small">رقم العميل</label>
                                <input type="text" name="customer_phone" id="submit_customer_phone" class="form-control form-control-sm" placeholder="رقم الهاتف" dir="ltr" value="">
                            </div>
                            <small class="form-text text-muted d-block">اختر عميلاً مسجلاً أو اكتب اسماً جديداً—يُحفظ تلقائياً كعميل جديد إن لم يكن مسجلاً</small>
                        </div>
                        <div class="col-md-3 d-none" id="createGovWrap">
                            <label class="form-label">المحافظة</label>
                            <div class="gov-autocomplete-wrap position-relative">
                                <input type="text" class="form-control gov-search-input" id="createGovSearch" placeholder="ابحث عن محافظة..." autocomplete="off">
                                <input type="hidden" name="tg_governorate" id="createGov">
                                <input type="hidden" name="tg_gov_id" id="createGovId">
                                <div class="gov-dropdown d-none"></div>
                            </div>
                        </div>
                        <div class="col-md-3 d-none" id="createCityWrap">
                            <label class="form-label">المدينة</label>
                            <div class="city-autocomplete-wrap position-relative">
                                <input type="text" class="form-control city-search-input" id="createCitySearch" placeholder="ابحث عن مدينة..." autocomplete="off">
                                <input type="hidden" name="tg_city" id="createCity">
                                <input type="hidden" name="tg_city_id" id="createCityId">
                                <div class="city-dropdown d-none"></div>
                            </div>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">العنوان</label>
                            <input type="text" class="form-control" name="order_title" id="createOrderTitle" placeholder="عنوان التوصيل أو عنوان مميز يظهر في الإيصال">
                        </div>

                        <div class="col-12">
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="viewCustomerHistoryBtn" disabled title="اختر عميلاً أولاً">
                                <i class="bi bi-clock-history me-1"></i>عرض سجل مشتريات العميل
                            </button>
                        </div>
                        <div class="col-12" id="customerPurchaseHistoryCard" style="display:none;">
                            <div class="card border-primary border-opacity-25 shadow-sm mt-1">
                                <div class="card-header bg-light d-flex justify-content-between align-items-center py-2 px-3">
                                    <span class="fw-semibold small"><i class="bi bi-clock-history me-1 text-primary"></i>سجل مشتريات: <span id="historyCardCustomerName"></span></span>
                                    <button type="button" class="btn-close btn-sm" id="closeHistoryCard" aria-label="إغلاق"></button>
                                </div>
                                <div class="card-body p-2" style="max-height:350px; overflow-y:auto;">
                                    <div class="text-center py-3 text-muted" id="customerHistoryLoading" style="display:none;">
                                        <div class="spinner-border spinner-border-sm" role="status"></div>
                                        <p class="mt-1 mb-0 small">جاري تحميل السجل...</p>
                                    </div>
                                    <div id="customerHistoryContent"></div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12" id="productsSection">
                            <label class="form-label fw-bold">المنتجات والكميات</label>
                            <div id="productsContainer">
                                <div class="product-row mb-3 p-3 border rounded" data-product-index="0">
                                    <div class="row g-2">
                                        <div class="col-12 col-md-3">
                                            <label class="form-label small">النوع</label>
                                            <select class="form-select form-select-sm product-type-selector mb-1" name="products[0][item_type]">
                                                <option value="">— اختر النوع —</option>
                                                <option value="external">منتجات خارجية</option>
                                                <option value="template">🏭 منتجات المصنع</option>
                                                <option value="second_grade">فرز تاني</option>
                                                <option value="raw_material">خامات</option>
                                                <option value="packaging">أدوات تعبئة</option>
                                            </select>
                                            <div class="product-name-wrap position-relative">
                                                <input type="text" class="form-control product-name-input" name="products[0][name]" placeholder="اختر من القائمة" autocomplete="off" required>
                                                <div class="product-template-dropdown d-none"></div>
                                            </div>
                                            <div class="template-picker d-none mt-2"></div>
                                        </div>
                                        <div class="col-6 col-md-2">
                                            <label class="form-label small">الكمية</label>
                                            <input type="number" class="form-control product-quantity-input" name="products[0][quantity]" step="1" min="0" placeholder="0" id="product-quantity-0" required>
                                            <small class="product-effective-qty-hint text-muted d-none" id="product-effective-qty-hint-0"></small>
                                        </div>
                                        <div class="col-6 col-md-2">
                                            <div class="category-wrap d-none">
                                                <label class="form-label small">التصنيف</label>
                                                <select class="form-select form-select-sm product-category-input" name="products[0][category]" id="product-category-0">
                                                    <option value="">— اختر التصنيف —</option>
                                                    <?php foreach ($quCategoriesForTask as $qc): ?>
                                                    <option value="<?php echo htmlspecialchars($qc['type'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($qc['type'], ENT_QUOTES, 'UTF-8'); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="raw-qty-wrap">
                                                <label class="form-label small text-info">الكمية المتاحة</label>
                                                <div class="raw-material-qty-value fw-semibold text-info">—</div>
                                            </div>
                                        </div>
                                        <div class="col-6 col-md-2">
                                            <label class="form-label small">الوحدة</label>
                                            <select class="form-select form-select-sm product-unit-input" name="products[0][unit]" id="product-unit-0" onchange="updateQuantityStep(0)">
                                                <option value="قطعة" selected>قطعة</option>
                                            </select>
                                        </div>
                                        <div class="col-6 col-md-2">
                                            <label class="form-label small">السعر</label>
                                            <input type="number" class="form-control product-price-input" name="products[0][price]" step="0.001" min="0" placeholder="0.00" id="product-price-0" required>
                                        </div>
                                        <div class="col-6 col-md-2">
                                            <label class="form-label small">الإجمالي</label>
                                            <div class="input-group input-group-sm">
                                                <input type="number" class="form-control product-line-total-input" name="products[0][line_total]" step="0.01" min="0" placeholder="0.00" id="product-line-total-0" title="الإجمالي = الكمية × السعر حسب الوحدة">
                                                <span class="input-group-text">ج.م</span>
                                            </div>
                                        </div>
                                        <div class="col-6 col-md-1 d-flex align-items-end">
                                            <button type="button" class="btn btn-danger btn-sm w-100 remove-product-btn" style="display: none;">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <datalist id="templateSuggestions"></datalist>
                            <button type="button" class="btn btn-outline-primary btn-sm mt-2" id="addProductBtn">
                                <i class="bi bi-plus-circle me-1"></i>إضافة منتج آخر
                            </button>
                            <div class="row g-2 mt-2 d-none" id="createTgParcelWrap">
                                <div class="col-md-3">
                                    <label class="form-label">عدد القطع</label>
                                    <input type="number" class="form-control" name="tg_pieces_count" id="createTgPiecesCount" step="1" min="1" placeholder="1" value="1">
                                    <small class="text-muted">يُحسب تلقائياً ويمكن تعديله يدوياً</small>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">الوزن (كجم)</label>
                                    <input type="number" class="form-control" name="tg_weight" id="createTgWeight" step="0.01" min="0.01" placeholder="0.00">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">وصف الطرد</label>
                                    <input type="text" class="form-control" name="tg_parcel_desc" id="createTgParcelDesc" placeholder="مثال: 3 كراتين عسل نحل...">
                                </div>
                            </div>

                        </div>
                        <div class="col-12 mt-2">
                            <label class="form-label"> ملاحظات </label>
                            <textarea class="form-control" name="details" rows="3" placeholder=""></textarea>
                        </div>
                        <div class="col-12 col-md-6 col-lg-4 mt-2" id="createShippingFeesWrap">
                            <label class="form-label" for="createTaskShippingFees">الشحن</label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="shipping_fees" id="createTaskShippingFees" step="0.01" min="0" placeholder="0.00" value="0">
                                <span class="input-group-text">ج.م</span>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-lg-4 mt-2">
                            <label class="form-label" for="createTaskDiscount">خصم</label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="discount" id="createTaskDiscount" step="0.01" min="0" placeholder="0.00" value="0">
                                <span class="input-group-text">ج.م</span>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-lg-4 mt-2">
                            <label class="form-label" for="createTaskAdvancePayment">المدفوع مقدماً</label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="advance_payment" id="createTaskAdvancePayment" step="0.01" min="0" placeholder="0.00" value="0">
                                <span class="input-group-text">ج.م</span>
                            </div>
                        </div>
                        <div class="col-12 mt-3">
                            <div class="card bg-light border-primary border-opacity-25" id="createTaskTotalSummaryCard">
                                <div class="card-body py-3">
                                    <h6 class="card-title mb-2"><i class="bi bi-calculator me-2"></i>ملخص الإجمالي النهائي</h6>
                                    <div class="row g-2 small">
                                        <div class="col-6 col-md-3">
                                            <span class="text-muted">إجمالي المنتجات:</span>
                                            <strong class="d-block" id="createTaskSubtotalDisplay">0.00 ج.م</strong>
                                        </div>
                                        <div class="col-6 col-md-3" id="createTaskShippingCol">
                                            <span class="text-muted">رسوم الشحن:</span>
                                            <strong class="d-block" id="createTaskShippingDisplay">0.00 ج.م</strong>
                                        </div>
                                        <div class="col-6 col-md-3 d-none" id="createTaskDeliveryCostCol">
                                            <span class="text-muted">تكلفة التوصيل (TelegraphEx):</span>
                                            <strong class="d-block text-info" id="createTaskDeliveryCostDisplay">
                                                <span class="spinner-border spinner-border-sm d-none" id="createTaskDeliveryCostSpinner"></span>
                                                <span id="createTaskDeliveryCostValue">—</span>
                                            </strong>
                                        </div>
                                        <div class="col-6 col-md-3 d-none" id="createTaskReturnCostCol">
                                            <span class="text-muted">رسوم الإرجاع:</span>
                                            <strong class="d-block text-warning" id="createTaskReturnCostValue">—</strong>
                                        </div>
                                        <div class="col-6 col-md-3">
                                            <span class="text-muted">الخصم:</span>
                                            <strong class="d-block" id="createTaskDiscountDisplay">0.00 ج.م</strong>
                                        </div>
                                        <div class="col-6 col-md-3">
                                            <span class="text-muted">الإجمالي النهائي:</span>
                                            <strong class="d-block fs-5 text-success" id="createTaskFinalTotalDisplay">0.00 ج.م</strong>
                                        </div>
                                        <div class="col-6 col-md-3" id="createTaskAdvancePaymentCol" style="display:none;">
                                            <span class="text-muted">المدفوع مقدماً:</span>
                                            <strong class="d-block text-primary" id="createTaskAdvancePaymentDisplay">0.00 ج.م</strong>
                                        </div>
                                        <div class="col-6 col-md-3" id="createTaskRemainingCol" style="display:none;">
                                            <span class="text-muted">المتبقي:</span>
                                            <strong class="d-block fs-5 text-danger" id="createTaskRemainingDisplay">0.00 ج.م</strong>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-4 gap-2 flex-wrap">
                        <div class="form-check form-switch ms-1">
                            <input class="form-check-input" type="checkbox" name="auto_approve_invoice" id="autoApproveInvoice" value="1">
                            <label class="form-check-label fw-semibold text-success" for="autoApproveInvoice">
                                <i class="bi bi-check-circle me-1"></i>اعتماد الفاتورة تلقائياً وتغيير حالة الطلب إلى "تم التوصيل"
                            </label>
                        </div>
                        <div class="d-flex gap-2">
                            <input type="hidden" id="currentDraftId" name="current_draft_id" value="">
                            <button type="button" class="btn btn-outline-secondary" id="saveDraftBtn"><i class="bi bi-floppy me-1"></i>حفظ كمسودة</button>
                            <button type="submit" id="createTaskSubmitBtn" class="btn btn-primary" disabled><i class="bi bi-send-check me-1"></i>إرسال المهمة</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="collapse mb-3" id="editTaskFormCollapse">
        <div class="card shadow-sm">
            <div class="card-header bg-secondary text-white">
                <h5 class="mb-0"><i class="bi bi-pencil-square me-2"></i>تعديل الاوردر</h5>
            </div>
            <div class="card-body">
                <form method="post" action="?page=production_tasks" id="editTaskForm">
                    <input type="hidden" name="action" value="update_task">
                    <input type="hidden" name="task_id" id="editTaskId">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">نوع الاوردر</label>
                            <select class="form-select" name="task_type" id="editTaskType" required>
                                <option value="shop_order">اوردر محل</option>
                                <option value="cash_customer">عميل نقدي</option>
                                <option value="telegraph">تليجراف</option>
                                <option value="shipping_company">شركة شحن</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">الأولوية</label>
                            <select class="form-select" name="priority" id="editPriority">
                                <option value="low">منخفضة</option>
                                <option value="normal" selected>عادية</option>
                                <option value="high">مرتفعة</option>
                                <option value="urgent">عاجلة</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">تاريخ الاستحقاق</label>
                            <input type="date" class="form-control" name="due_date" id="editDueDate">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">العميل</label>
                            <div class="customer-type-wrap d-flex flex-wrap gap-3 mb-2">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="customer_type_radio_edit" id="ct_edit_local" value="local" checked>
                                    <label class="form-check-label" for="ct_edit_local">عميل محلي</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="customer_type_radio_edit" id="ct_edit_rep" value="rep">
                                    <label class="form-check-label" for="ct_edit_rep">عميل مندوب</label>
                                </div>
                            </div>
                            <input type="hidden" name="customer_name" id="edit_submit_customer_name" value="">
                            <div id="customer_select_local_edit" class="customer-select-block mb-2">
                                <div class="search-wrap position-relative">
                                    <input type="text" id="local_customer_search_edit" class="form-control form-control-sm" placeholder="اكتب للبحث أو أدخل اسم عميل جديد..." autocomplete="off">
                                    <input type="hidden" id="local_customer_id_edit" name="local_customer_id" value="">
                                    <div id="local_customer_dropdown_edit" class="search-dropdown-task d-none"></div>
                                </div>
                            </div>
                            <div id="customer_select_rep_edit" class="customer-select-block mb-2 d-none">
                                <div class="search-wrap position-relative">
                                    <input type="text" id="rep_customer_search_edit" class="form-control form-control-sm" placeholder="اكتب للبحث أو أدخل اسم عميل جديد..." autocomplete="off">
                                    <input type="hidden" id="rep_customer_id_edit" value="">
                                    <div id="rep_customer_dropdown_edit" class="search-dropdown-task d-none"></div>
                                </div>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small">رقم العميل</label>
                                <input type="text" name="customer_phone" id="edit_submit_customer_phone" class="form-control form-control-sm" placeholder="رقم الهاتف" dir="ltr" value="">
                            </div>
                            <small class="form-text text-muted d-block">اختر عميلاً مسجلاً أو اكتب اسماً جديداً—يُحفظ تلقائياً كعميل جديد إن لم يكن مسجلاً</small>
                        </div>
                        <div class="col-md-3 d-none" id="editGovWrap">
                            <label class="form-label">المحافظة</label>
                            <div class="gov-autocomplete-wrap position-relative">
                                <input type="text" class="form-control gov-search-input" id="editGovSearch" placeholder="ابحث عن محافظة..." autocomplete="off">
                                <input type="hidden" name="tg_governorate" id="editGov">
                                <input type="hidden" name="tg_gov_id" id="editGovId">
                                <div class="gov-dropdown d-none"></div>
                            </div>
                        </div>
                        <div class="col-md-3 d-none" id="editCityWrap">
                            <label class="form-label">المدينة</label>
                            <div class="city-autocomplete-wrap position-relative">
                                <input type="text" class="form-control city-search-input" id="editCitySearch" placeholder="ابحث عن مدينة..." autocomplete="off">
                                <input type="hidden" name="tg_city" id="editCity">
                                <input type="hidden" name="tg_city_id" id="editCityId">
                                <div class="city-dropdown d-none"></div>
                            </div>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">العنوان</label>
                            <input type="text" class="form-control" name="order_title" id="editOrderTitle" placeholder="عنوان التوصيل أو عنوان مميز يظهر في الإيصال">
                        </div>

                        <div class="col-12">
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="editViewCustomerHistoryBtn" disabled title="اختر عميلاً أولاً">
                                <i class="bi bi-clock-history me-1"></i>عرض سجل مشتريات العميل
                            </button>
                        </div>
                        <div class="col-12" id="editCustomerPurchaseHistoryCard" style="display:none;">
                            <div class="card border-primary border-opacity-25 shadow-sm mt-1">
                                <div class="card-header bg-light d-flex justify-content-between align-items-center py-2 px-3">
                                    <span class="fw-semibold small"><i class="bi bi-clock-history me-1 text-primary"></i>سجل مشتريات: <span id="editHistoryCardCustomerName"></span></span>
                                    <button type="button" class="btn-close btn-sm" id="editCloseHistoryCard" aria-label="إغلاق"></button>
                                </div>
                                <div class="card-body p-2" style="max-height:350px; overflow-y:auto;">
                                    <div class="text-center py-3 text-muted" id="editCustomerHistoryLoading" style="display:none;">
                                        <div class="spinner-border spinner-border-sm" role="status"></div>
                                        <p class="mt-1 mb-0 small">جاري تحميل السجل...</p>
                                    </div>
                                    <div id="editCustomerHistoryContent"></div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12" id="editProductsSection">
                            <label class="form-label fw-bold">المنتجات والكميات</label>
                            <div id="editProductsContainer"></div>
                            <button type="button" class="btn btn-outline-primary btn-sm mt-2" id="editAddProductBtn">
                                <i class="bi bi-plus-circle me-1"></i>إضافة منتج آخر
                            </button>
                            <div class="row g-2 mt-2 d-none" id="editTgParcelWrap">
                                <div class="col-md-4">
                                    <label class="form-label">الوزن (كجم)</label>
                                    <input type="number" class="form-control" name="tg_weight" id="editTgWeight" step="0.01" min="0" placeholder="0.00">
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label">وصف الطرد</label>
                                    <input type="text" class="form-control" name="tg_parcel_desc" id="editTgParcelDesc" placeholder="مثال: 3 كراتين عسل نحل...">
                                </div>
                            </div>
                        </div>
                        <div class="col-12 mt-2">
                            <label class="form-label"> ملاحظات </label>
                            <textarea class="form-control" name="details" id="editDetails" rows="3" placeholder=""></textarea>
                        </div>
                        <div class="col-12 col-md-6 col-lg-4 mt-2" id="editShippingFeesWrap">
                            <label class="form-label" for="editTaskShippingFees">الشحن</label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="shipping_fees" id="editTaskShippingFees" step="0.01" min="0" placeholder="0.00" value="0">
                                <span class="input-group-text">ج.م</span>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-lg-4 mt-2">
                            <label class="form-label" for="editTaskDiscount">خصم</label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="discount" id="editTaskDiscount" step="0.01" min="0" placeholder="0.00" value="0">
                                <span class="input-group-text">ج.م</span>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-lg-4 mt-2">
                            <label class="form-label" for="editTaskAdvancePayment">المدفوع مقدماً</label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="advance_payment" id="editTaskAdvancePayment" step="0.01" min="0" placeholder="0.00" value="0">
                                <span class="input-group-text">ج.م</span>
                            </div>
                        </div>
                        <div class="col-12 mt-3">
                            <div class="card bg-light border-primary border-opacity-25" id="editTaskTotalSummaryCard">
                                <div class="card-body py-3">
                                    <h6 class="card-title mb-2"><i class="bi bi-calculator me-2"></i>ملخص الإجمالي النهائي</h6>
                                    <div class="row g-2 small">
                                        <div class="col-6 col-md-3">
                                            <span class="text-muted">إجمالي المنتجات:</span>
                                            <strong class="d-block" id="editTaskSubtotalDisplay">0.00 ج.م</strong>
                                        </div>
                                        <div class="col-6 col-md-3" id="editTaskShippingCol">
                                            <span class="text-muted">رسوم الشحن:</span>
                                            <strong class="d-block" id="editTaskShippingDisplay">0.00 ج.م</strong>
                                        </div>
                                        <div class="col-6 col-md-3 d-none" id="editTaskDeliveryCostCol">
                                            <span class="text-muted">تكلفة التوصيل (TelegraphEx):</span>
                                            <strong class="d-block text-info" id="editTaskDeliveryCostDisplay">
                                                <span class="spinner-border spinner-border-sm d-none" id="editTaskDeliveryCostSpinner"></span>
                                                <span id="editTaskDeliveryCostValue">—</span>
                                            </strong>
                                        </div>
                                        <div class="col-6 col-md-3 d-none" id="editTaskReturnCostCol">
                                            <span class="text-muted">رسوم الإرجاع:</span>
                                            <strong class="d-block text-warning" id="editTaskReturnCostValue">—</strong>
                                        </div>
                                        <div class="col-6 col-md-3">
                                            <span class="text-muted">الخصم:</span>
                                            <strong class="d-block" id="editTaskDiscountDisplay">0.00 ج.م</strong>
                                        </div>
                                        <div class="col-6 col-md-3">
                                            <span class="text-muted">الإجمالي النهائي:</span>
                                            <strong class="d-block fs-5 text-success" id="editTaskFinalTotalDisplay">0.00 ج.م</strong>
                                        </div>
                                        <div class="col-6 col-md-3" id="editTaskAdvancePaymentCol" style="display:none;">
                                            <span class="text-muted">المدفوع مقدماً:</span>
                                            <strong class="d-block text-primary" id="editTaskAdvancePaymentDisplay">0.00 ج.م</strong>
                                        </div>
                                        <div class="col-6 col-md-3" id="editTaskRemainingCol" style="display:none;">
                                            <span class="text-muted">المتبقي:</span>
                                            <strong class="d-block fs-5 text-danger" id="editTaskRemainingDisplay">0.00 ج.م</strong>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex justify-content-end mt-4 gap-2">
                        <button type="button" class="btn btn-outline-secondary" onclick="closeEditTaskCard()"><i class="bi bi-x-circle me-1"></i>إلغاء</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i>حفظ التعديلات</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if (!empty($taskDrafts)): ?>
    <div class="card shadow-sm mt-4" id="taskDraftsCard">
        <div class="card-header bg-warning bg-opacity-10 d-flex justify-content-between align-items-center">
            <h5 class="mb-0 text-warning"><i class="bi bi-floppy me-2"></i>المسودات (<span id="draftsCount"><?php echo count($taskDrafts); ?></span>)</h5>
        </div>
        <div class="card-body p-0">
            <ul class="list-group list-group-flush" id="draftsList">
                <?php foreach ($taskDrafts as $draft): ?>
                <li class="list-group-item task-draft-item" id="draft-item-<?php echo (int)$draft['id']; ?>">
                    <div class="task-draft-row">
                        <div class="task-draft-info">
                            <div class="task-draft-title">
                                <i class="bi bi-file-earmark-text text-warning me-2"></i>
                                <strong><?php echo htmlspecialchars($draft['draft_name'] ?? 'مسودة', ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                            <div class="task-draft-meta text-muted small">
                                <?php
                                    $draftDate = $draft['updated_at'] ?? $draft['created_at'];
                                    echo $draftDate ? 'آخر تحديث: ' . date('d/m/Y H:i', strtotime($draftDate)) : '';
                                ?>
                            </div>
                        </div>
                        <div class="task-draft-actions">
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="loadDraft(<?php echo (int)$draft['id']; ?>)">
                                <i class="bi bi-pencil-square me-1"></i>استكمال
                            </button>
                            <button type="button" class="btn btn-outline-danger btn-sm" onclick="deleteDraft(<?php echo (int)$draft['id']; ?>)">
                                <i class="bi bi-trash me-1"></i>حذف
                            </button>
                        </div>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php else: ?>
    <div id="taskDraftsCard" style="display:none;" class="card shadow-sm mt-4">
        <div class="card-header bg-warning bg-opacity-10 d-flex justify-content-between align-items-center">
            <h5 class="mb-0 text-warning"><i class="bi bi-floppy me-2"></i>المسودات (<span id="draftsCount">0</span>)</h5>
        </div>
        <div class="card-body p-0">
            <ul class="list-group list-group-flush" id="draftsList"></ul>
        </div>
    </div>
    <?php endif; ?>

    <div class="card shadow-sm mt-4">
        <div class="card-header bg-light d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>قائمة الأوردرات</h5>
            <div class="d-flex align-items-center gap-2">
                <?php if ($canPrintTasks): ?>
                <button type="button" class="btn btn-outline-primary btn-sm" id="printSelectedReceiptsBtn" title="طباعة إيصالات الأوردرات المحددة" disabled>
                    <i class="bi bi-printer me-1"></i>طباعة المحدد (<span id="selectedCount">0</span>)
                </button>
                <?php if ($isAccountant || $isManager): ?>
                <button type="button" class="btn btn-outline-success btn-sm" id="approveSelectedBtn" title="اعتماد الفواتير المحددة" disabled onclick="openBulkApproveCard()">
                    <i class="bi bi-check2-circle me-1"></i>اعتماد المحدد (<span id="approveSelectedCount">0</span>)
                </button>
                <?php endif; ?>

                <button type="button" class="btn btn-outline-info btn-sm" id="exportSelectedExcelBtn" title="تصدير CSV حسب الفترة">
                    <i class="bi bi-file-earmark-spreadsheet me-1"></i>شيت فواتير 
                </button>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body p-0">
            <!-- بحث وفلترة جدول آخر المهام -->
            <div class="card-header bg-light d-flex justify-content-between align-items-center py-2">
                <h6 class="mb-0 fw-semibold"><i class="bi bi-search me-2"></i> البحث والفلترة</h6>
                <button type="button" class="btn btn-sm btn-outline-secondary toggle-cards-btn" data-target="filterSection" data-bs-toggle="tooltip" title="Toggle filter section">
                    <i class="bi bi-chevron-up toggle-icon"></i>
                </button>
            </div>
            <div class="card-body p-0 collapse show" id="filterSectionCollapse">
                <div class="p-3 border-bottom bg-light">
                <form method="get" action="" id="recentTasksFilterForm" class="recent-tasks-filter-form" data-no-loading="true">
                    <input type="hidden" name="page" value="production_tasks">
                    <?php if ($statusFilter !== ''): ?>
                    <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php endif; ?>
                    <div class="row g-2">
                        <div class="col-12 col-md-4 col-lg-2">
                            <label class="form-label small mb-0">بحث سريع</label>
                            <input type="text" name="search_text" id="recentTasksSearchText" class="form-control form-control-sm recent-tasks-dynamic-filter" placeholder="نص في العنوان، الملاحظات، العميل..." value="<?php echo htmlspecialchars($filterSearchText, ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-5 col-md-3 col-lg-2">
                            <label class="form-label small mb-0">رقم الاوردر</label>
                            <input type="text" name="task_id" id="recentTasksFilterTaskId" class="form-control form-control-sm" placeholder="#" value="<?php echo htmlspecialchars($filterTaskId, ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-1 col-md-1 col-lg-1 align-self-end">
                            <button type="submit" class="btn btn-primary btn-sm w-100" title="بحث">
                                <i class="bi bi-search"></i>
                            </button>
                        </div>
                        <div class="col-6 col-md-4 col-lg-2">
                            <label class="form-label small mb-0">اسم العميل / هاتف</label>
                            <input type="text" name="search_customer" id="recentTasksFilterCustomer" class="form-control form-control-sm recent-tasks-dynamic-filter" placeholder="اسم أو رقم" value="<?php echo htmlspecialchars($filterCustomer, ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-6 col-md-4 col-lg-2">
                            <label class="form-label small mb-0">نوع الاوردر</label>
                            <select name="task_type" id="recentTasksFilterTaskType" class="form-select form-select-sm recent-tasks-dynamic-filter">
                                <option value="">— الكل —</option>
                                <option value="shop_order" <?php echo $filterTaskType === 'shop_order' ? 'selected' : ''; ?>>اوردر محل</option>
                                <option value="cash_customer" <?php echo $filterTaskType === 'cash_customer' ? 'selected' : ''; ?>>عميل نقدي</option>
                                <option value="telegraph" <?php echo $filterTaskType === 'telegraph' ? 'selected' : ''; ?>>تليجراف</option>
                                <option value="shipping_company" <?php echo $filterTaskType === 'shipping_company' ? 'selected' : ''; ?>>شركة شحن</option>
                            </select>
                        </div>
                        <div class="col-6 col-md-4 col-lg-2">
                            <label class="form-label small mb-0">تاريخ تسليم من</label>
                            <input type="date" name="due_date_from" id="recentTasksFilterDueFrom" class="form-control form-control-sm recent-tasks-dynamic-filter" value="<?php echo htmlspecialchars($filterDueFrom, ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-6 col-md-4 col-lg-2">
                            <label class="form-label small mb-0">تاريخ تسليم إلى</label>
                            <input type="date" name="due_date_to" id="recentTasksFilterDueTo" class="form-control form-control-sm recent-tasks-dynamic-filter" value="<?php echo htmlspecialchars($filterDueTo, ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-6 col-md-4 col-lg-2">
                            <label class="form-label small mb-0">تاريخ الطلب من</label>
                            <input type="date" name="order_date_from" id="recentTasksFilterOrderDateFrom" class="form-control form-control-sm recent-tasks-dynamic-filter" value="<?php echo htmlspecialchars($filterOrderDateFrom, ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-6 col-md-4 col-lg-2">
                            <label class="form-label small mb-0">تاريخ الطلب إلى</label>
                            <input type="date" name="order_date_to" id="recentTasksFilterOrderDateTo" class="form-control form-control-sm recent-tasks-dynamic-filter" value="<?php echo htmlspecialchars($filterOrderDateTo, ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-auto align-self-end">
                            <a href="?<?php echo $statusFilter !== '' ? 'page=production_tasks&status=' . rawurlencode($statusFilter) : 'page=production_tasks'; ?>" class="btn btn-outline-danger btn-sm">إزالة الفلتر</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <div class="table-responsive dashboard-table-wrapper">
            <table class="table dashboard-table dashboard-table--no-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <?php if ($canPrintTasks): ?>
                        <th style="width: 40px;">
                            <input type="checkbox" class="form-check-input" id="selectAllTasks" title="تحديد الكل">
                        </th>
                        <?php endif; ?>
                        <th>رقم الطلب</th>
                        <th style="min-width: 220px;">اسم العميل</th>
                        <th style="min-width: 180px;">من</th>
                        <th>نوع الاوردر</th>
                        <th>الحاله</th>
                        <th>التسليم</th>
                        
                        <th>إجراءات</th>
                    </tr>
                </thead>
                <tbody id="recentTasksTableBody">
                        <?php if (empty($recentTasks)): ?>
                            <tr>
                                <td colspan="<?php echo $canPrintTasks ? 8 : 7; ?>" class="text-center text-muted py-4">لم يتم إنشاء مهام بعد.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentTasks as $index => $task): ?>
                                <?php
                                $relatedType = $task['related_type'] ?? '';
                                $displayType = (strpos($relatedType, 'manager_') === 0) ? substr($relatedType, 8) : ($task['task_type'] ?? 'general');
                                $rowSearchParts = array_filter([
                                    $task['title'] ?? '',
                                    $task['notes'] ?? '',
                                    $task['customer_name'] ?? '',
                                    $task['customer_phone'] ?? '',
                                ], function($v) { return trim((string)$v) !== ''; });
                                $rowSearchText = implode(' ', array_map('trim', $rowSearchParts));
                                $rowDueDate = !empty($task['due_date']) ? date('Y-m-d', strtotime((string)$task['due_date'])) : '';
                                $rowOrderDate = !empty($task['created_at']) ? date('Y-m-d', strtotime((string)$task['created_at'])) : '';
                                $rowCustomer = trim(($task['customer_name'] ?? '') . ' ' . ($task['customer_phone'] ?? ''));
                                ?>
                                <tr class="recent-tasks-filter-row" data-task-id="<?php echo (int)$task['id']; ?>" data-search="<?php echo htmlspecialchars($rowSearchText, ENT_QUOTES, 'UTF-8'); ?>" data-customer="<?php echo htmlspecialchars($rowCustomer, ENT_QUOTES, 'UTF-8'); ?>" data-task-type="<?php echo htmlspecialchars($displayType, ENT_QUOTES, 'UTF-8'); ?>" data-due-date="<?php echo htmlspecialchars($rowDueDate, ENT_QUOTES, 'UTF-8'); ?>" data-order-date="<?php echo htmlspecialchars($rowOrderDate, ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php if ($canPrintTasks): ?>
                                    <td>
                                        <?php
                                        $cbNotes = $task['notes'] ?? '';
                                        $cbProductsJson = '[]';
                                        if (preg_match('/(?:\[PRODUCTS_JSON\]|المنتجات)\s*:\s*(\[.+?\])(?=\s*\n|\[ASSIGNED_WORKERS_IDS\]|\z)/su', $cbNotes, $cbPm)) {
                                            $cbDecoded = json_decode(trim($cbPm[1]), true);
                                            if (is_array($cbDecoded)) $cbProductsJson = json_encode($cbDecoded, JSON_UNESCAPED_UNICODE);
                                        }
                                        $cbShipping = 0.0;
                                        if (preg_match('/\[SHIPPING_FEES\]:\s*([0-9.]+)/', $cbNotes, $cbSm)
                                            || preg_match('/رسوم\s*الشحن\s*:\s*([0-9.]+)/u', $cbNotes, $cbSm)) $cbShipping = (float)$cbSm[1];
                                        $cbDiscount = 0.0;
                                        if (preg_match('/\[DISCOUNT\]:\s*([0-9.]+)/', $cbNotes, $cbDm)
                                            || preg_match('/الخصم\s*:\s*([0-9.]+)/u', $cbNotes, $cbDm)) $cbDiscount = (float)$cbDm[1];
                                        $cbApproved = in_array((int)$task['id'], $approvedTaskIds, true) ? '1' : '0';
                                        $cbReceiptTotal = isset($task['receipt_total']) ? (float)$task['receipt_total'] : 0;
                                        ?>
                                        <input type="checkbox" class="form-check-input task-print-checkbox" value="<?php echo (int)$task['id']; ?>"
                                            data-print-url="<?php echo htmlspecialchars(getRelativeUrl('print_task_receipt.php?id=' . (int)$task['id']), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-customer-name="<?php echo htmlspecialchars(trim((string)($task['customer_name'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-receipt-total="<?php echo $cbReceiptTotal; ?>"
                                            data-order-type="<?php echo htmlspecialchars($displayType ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                            data-approved="<?php echo $cbApproved; ?>"
                                            data-products-json="<?php echo htmlspecialchars($cbProductsJson, ENT_QUOTES, 'UTF-8'); ?>"
                                            data-shipping-fees="<?php echo $cbShipping; ?>"
                                            data-discount="<?php echo $cbDiscount; ?>">
                                    </td>
                                    <?php endif; ?>
                                    <td>
                                        <?php 
                                        $printCount = (int) ($task['receipt_print_count'] ?? 0);
                                        if ($printCount > 0): 
                                        ?>
                                        <span class="badge bg-info mb-1" title="عدد مرات طباعة إيصال الأوردر" style="font-size: 0.7rem;"> <?php echo $printCount; ?> <?php echo $printCount === 1 ? '' : ''; ?></span>
                                        <?php endif; ?>
                                        <strong class="copy-order-id" title="انقر للنسخ" style="cursor:pointer">#<?php echo (int)$task['id']; ?></strong>
                                        <?php
                                        $createdAt = $task['created_at'] ?? '';
                                        if ($createdAt !== '') {
                                            $day = (int) date('j', strtotime($createdAt));
                                            $monthsAr = ['', 'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
                                            $month = $monthsAr[(int) date('n', strtotime($createdAt))];
                                            echo '<div class="text-muted small mt-1">' . $day . ' ' . $month . '</div>';
                                        }
                                        ?>
                                    </td>
                                    <td class="text-wrap" data-wrap="true" style="min-width: 220px;"><?php 
                                        $custName = isset($task['customer_name']) ? trim((string)$task['customer_name']) : '';
                                        echo $custName !== '' ? htmlspecialchars($custName, ENT_QUOTES, 'UTF-8') : '<span class="text-muted">-</span>';
                                        if (in_array((int)$task['id'], $approvedTaskIds, true)) {
                                            echo ' <i class="bi bi-patch-check-fill text-success" style="font-size: 0.75rem; vertical-align: middle;" title="تم اعتماد الفاتورة" aria-label="تم اعتماد الفاتورة"></i>';
                                        }
                                    ?></td>
                                    <td class="text-wrap" data-wrap="true" style="min-width: 180px;">                                        <?php 
                                        // عرض منشئ المهمة إذا كان المحاسب أو المدير
                                        if (isset($task['creator_name']) && ($isAccountant || $isManager || $isSales)) {
                                            $creatorRoleLabel = '';
                                            if (isset($task['creator_role'])) {
                                                $creatorRoleLabel = ($task['creator_role'] ?? '') === 'accountant' ? 'المحاسب' : 'المدير';
                                            } elseif (isset($task['created_by'])) {
                                                $creatorUser = $db->queryOne("SELECT role FROM users WHERE id = ? LIMIT 1", [$task['created_by']]);
                                                if ($creatorUser) {
                                                    $creatorRoleLabel = ($creatorUser['role'] ?? '') === 'accountant' ? 'المحاسب' : 'المدير';
                                                }
                                            }
                                            if ($creatorRoleLabel) {
                                                echo '<div class="text-muted small"><i class="bi bi-person me-1"></i>' . htmlspecialchars($task['creator_name']) . '</div>';
                                            }
                                        }
                                        ?>
                                        
                                    </td>
                                    <td>
                                        <?php
                                        $relatedType = $task['related_type'] ?? '';
                                        $displayType = (strpos($relatedType, 'manager_') === 0) ? substr($relatedType, 8) : ($task['task_type'] ?? 'general');
                                        $orderTypeLabels = ['shop_order' => ' محل', 'cash_customer' => 'عميل نقدي', 'telegraph' => 'تليجراف', 'shipping_company' => 'شركة شحن'];
                                        echo htmlspecialchars($orderTypeLabels[$displayType] ?? $displayType);
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        $rawStatusKey = trim((string) ($task['status'] ?? ''));
                                        $statusKey = strtolower($rawStatusKey);
                                        $statusKey = preg_replace('/[\s\-]+/', '_', $statusKey);
                                        $statusKey = trim((string) $statusKey, '_');

                                        $statusAliases = [
                                            'مع_شركة_الشحن' => 'with_shipping_company',
                                            'مع شركة الشحن' => 'with_shipping_company',
                                            'with_shipping_company' => 'with_shipping_company',
                                            'with_shipping_company.' => 'with_shipping_company',
                                            'with_shipping_company,' => 'with_shipping_company',
                                            'with_shipping_company_' => 'with_shipping_company',
                                            'with_shipping_company__' => 'with_shipping_company',
                                            'with_shipping_company___' => 'with_shipping_company',
                                            'with_shipping_company____' => 'with_shipping_company',
                                            'with_shipping_company_____' => 'with_shipping_company',
                                            'with_shipping_company_______' => 'with_shipping_company',
                                            'with shipping company' => 'with_shipping_company',
                                            'مع_المندوب' => 'with_delegate',
                                            'مع المندوب' => 'with_delegate',
                                            'مع_السائق' => 'with_driver',
                                            'مع السائق' => 'with_driver',
                                            'معلقة' => 'pending',
                                            'مكتملة' => 'completed',
                                            'تم_التوصيل' => 'delivered',
                                            'تم التوصيل' => 'delivered',
                                            'تم_الارجاع' => 'returned',
                                            'تم الارجاع' => 'returned',
                                            'ملغاة' => 'cancelled',
                                        ];

                                        if (isset($statusAliases[$rawStatusKey])) {
                                            $statusKey = $statusAliases[$rawStatusKey];
                                        } elseif (isset($statusAliases[$statusKey])) {
                                            $statusKey = $statusAliases[$statusKey];
                                        }

                                        $isShippingOrder = in_array($displayType, ['telegraph', 'shipping_company'], true);
                                        $isApprovedForShipping = in_array((int)($task['id'] ?? 0), $approvedTaskIds, true);
                                        if (($rawStatusKey === '' || !isset($statusStyles[$statusKey])) && $isShippingOrder && $isApprovedForShipping) {
                                            $statusKey = 'with_shipping_company';
                                        }

                                        $statusMeta = $statusStyles[$statusKey] ?? [
                                            'class' => 'secondary',
                                            'label' => ($rawStatusKey !== '' ? $rawStatusKey : 'غير معروفة')
                                        ];
                                        ?>
                                        <?php if ($isManager || $isAccountant || $isSales): ?>
                                        <div class="dropdown">
                                            <span class="badge bg-<?php echo htmlspecialchars($statusMeta['class']); ?> status-badge-dropdown"
                                                  role="button"
                                                  data-bs-toggle="dropdown"
                                                  aria-expanded="false"
                                                  data-task-id="<?php echo (int)$task['id']; ?>"
                                                  data-current-status="<?php echo htmlspecialchars($statusKey); ?>"
                                                  style="cursor:pointer;">
                                                <?php echo htmlspecialchars($statusMeta['label']); ?> <i class="bi bi-chevron-down" style="font-size:0.65em;"></i>
                                            </span>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <?php foreach ($statusStyles as $sKey => $sMeta): ?>
                                                <li>
                                                    <a class="dropdown-item status-quick-change<?php echo ($sKey === $statusKey) ? ' active' : ''; ?>"
                                                       href="#"
                                                       data-task-id="<?php echo (int)$task['id']; ?>"
                                                       data-status="<?php echo htmlspecialchars($sKey); ?>">
                                                        <span class="badge bg-<?php echo htmlspecialchars($sMeta['class']); ?> me-1">&nbsp;</span>
                                                        <?php echo htmlspecialchars($sMeta['label']); ?>
                                                    </a>
                                                </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                        <?php else: ?>
                                        <span class="badge bg-<?php echo htmlspecialchars($statusMeta['class']); ?>">
                                            <?php echo htmlspecialchars($statusMeta['label']); ?>
                                        </span>
                                        <?php endif; ?>
                                        <?php if (!empty($task['status_changed_by_name'])): ?>
                                        <div class="text-muted small mt-1">
                                            <i class="bi bi-person me-1"></i><?php echo htmlspecialchars($task['status_changed_by_name']); ?>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        if ($task['due_date']) {
                                            $dt = DateTime::createFromFormat('Y-m-d', $task['due_date']);
                                            if ($dt) {
                                                echo htmlspecialchars($dt->format('d/m'));
                                            } else {
                                                echo htmlspecialchars($task['due_date']);
                                            }
                                        } else {
                                            echo '<span class="text-muted">غير محدد</span>';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php if ($isSales): ?>
                                            <?php if (($task['status'] ?? '') === 'with_delegate'): ?>
                                            <div class="dropdown">
                                                <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="إجراءات">
                                                    <i class="bi bi-three-dots-vertical"></i> إجراءات
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end">
                                                    <li>
                                                        <button type="button" class="dropdown-item status-quick-change" href="#"
                                                               data-task-id="<?php echo (int)$task['id']; ?>"
                                                               data-status="delivered">
                                                            <i class="bi bi-check2-circle me-1 text-success"></i>تم التوصيل
                                                        </button>
                                                    </li>
                                                </ul>
                                            </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                        <div class="dropdown">
                                            <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="إجراءات">
                                                <i class="bi bi-three-dots-vertical"></i> إجراءات
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <?php
                                                $custPhone = isset($task['customer_phone']) ? trim((string)$task['customer_phone']) : '';
                                                $custPhoneEsc = $custPhone !== '' ? 'tel:' . preg_replace('/[^\d+]/', '', $custPhone) : '';
                                                if ($custPhone !== '' && $custPhoneEsc !== 'tel:'):
                                                ?>
                                                <li>
                                                    <a class="dropdown-item" href="<?php echo htmlspecialchars($custPhoneEsc, ENT_QUOTES, 'UTF-8'); ?>" title="اتصال بالعميل">
                                                        <i class="bi bi-telephone-fill me-1"></i>اتصال بالعميل
                                                    </a>
                                                </li>
                                                <?php endif; ?>
                                                <?php if ($canPrintTasks): ?>
                                                <li>
                                                    <button type="button" class="dropdown-item" onclick="window.open('<?php echo htmlspecialchars(getRelativeUrl('print_task_receipt.php?id=' . (int) $task['id'] . '&print=0'), ENT_QUOTES, 'UTF-8'); ?>', '_blank', 'noopener')">
                                                        <i class="bi bi-printer me-1"></i>طباعة الاوردر
                                                    </button>
                                                </li>
                                                <?php endif; ?>
                                                <?php if ($isAccountant || $isManager): ?>
                                                <li>
                                                    <button type="button" class="dropdown-item" onclick="openChangeStatusModal(<?php echo (int)$task['id']; ?>, '<?php echo htmlspecialchars($task['status'], ENT_QUOTES, 'UTF-8'); ?>')">
                                                        <i class="bi bi-gear me-1"></i>تغيير حالة الطلب
                                                    </button>
                                                </li>
                                                <li>
                                                    <button type="button" class="dropdown-item" onclick="openEditTaskModal(<?php echo (int)$task['id']; ?>)">
                                                        <i class="bi bi-pencil-square me-1"></i>تعديل الاوردر
                                                    </button>
                                                </li>
                                                <?php
                                                $taskApproved = in_array((int)$task['id'], $approvedTaskIds, true);
                                                $hasCustomer = trim((string)($task['customer_name'] ?? '')) !== '' || trim((string)($task['customer_phone'] ?? '')) !== '';
                                                $receiptTotal = isset($task['receipt_total']) ? (float)$task['receipt_total'] : 0;
                                                $isShippingOrderType = ($displayType === 'telegraph' || $displayType === 'shipping_company');
                                                $canShowApproveBtn = !$taskApproved && ($hasCustomer || $isShippingOrderType);
                                                if ($canShowApproveBtn):
                                                ?>
                                                <li>
                                                    <button type="button" class="dropdown-item text-success approve-invoice-btn" data-task-id="<?php echo (int)$task['id']; ?>" data-customer-name="<?php echo htmlspecialchars(trim((string)($task['customer_name'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>" data-receipt-total="<?php echo (float)$receiptTotal; ?>" data-order-type="<?php echo htmlspecialchars($displayType ?? '', ENT_QUOTES, 'UTF-8'); ?>" onclick="openApproveInvoiceCardFromBtn(this)">
                                                        <i class="bi bi-check2-circle me-1"></i>اعتماد الفاتورة
                                                    </button>
                                                </li>
                                                <?php endif; ?>
                                                <?php endif; ?>
                                                <?php if (!in_array($task['status'] ?? '', ['completed', 'delivered', 'returned'], true)): ?>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <form method="post" class="d-inline" onsubmit="return confirm('هل أنت متأكد من حذف هذه المهمة؟ سيتم حذفها نهائياً ولن تظهر في الجدول.');">
                                                        <input type="hidden" name="action" value="cancel_task">
                                                        <input type="hidden" name="task_id" value="<?php echo (int)$task['id']; ?>">
                                                        <button type="submit" class="dropdown-item text-danger">
                                                            <i class="bi bi-trash me-1"></i>حذف المهمة
                                                        </button>
                                                    </form>
                                                </li>
                                                <?php endif; ?>
                                            </ul>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalRecentPages > 1): ?>
                <?php
                $paginateParams = $recentTasksQueryParams;
                $paginateBase = $recentTasksQueryString;
                ?>
                <nav aria-label="تنقل صفحات المهام" class="p-3 pt-0" id="recentTasksPagination">
                    <ul class="pagination justify-content-center mb-0">
                        <li class="page-item <?php echo $tasksPageNum <= 1 ? 'disabled' : ''; ?>">
                            <?php $prevParams = $paginateParams; $prevParams['p'] = max(1, $tasksPageNum - 1); ?>
                            <a class="page-link recent-tasks-page-link" href="?<?php echo http_build_query($prevParams, '', '&', PHP_QUERY_RFC3986); ?>" data-page="<?php echo max(1, $tasksPageNum - 1); ?>" aria-label="السابق">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        </li>
                        <?php
                        $startPage = max(1, $tasksPageNum - 2);
                        $endPage = min($totalRecentPages, $tasksPageNum + 2);
                        if ($startPage > 1): ?>
                            <?php $p1 = $paginateParams; $p1['p'] = 1; ?>
                            <li class="page-item"><a class="page-link recent-tasks-page-link" href="?<?php echo http_build_query($p1, '', '&', PHP_QUERY_RFC3986); ?>" data-page="1">1</a></li>
                            <?php if ($startPage > 2): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <?php $pi = $paginateParams; $pi['p'] = $i; ?>
                            <li class="page-item <?php echo $i == $tasksPageNum ? 'active' : ''; ?>">
                                <a class="page-link recent-tasks-page-link" href="?<?php echo http_build_query($pi, '', '&', PHP_QUERY_RFC3986); ?>" data-page="<?php echo $i; ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                        <?php if ($endPage < $totalRecentPages): ?>
                            <?php if ($endPage < $totalRecentPages - 1): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                            <?php $plast = $paginateParams; $plast['p'] = $totalRecentPages; ?>
                            <li class="page-item"><a class="page-link recent-tasks-page-link" href="?<?php echo http_build_query($plast, '', '&', PHP_QUERY_RFC3986); ?>" data-page="<?php echo $totalRecentPages; ?>"><?php echo $totalRecentPages; ?></a></li>
                        <?php endif; ?>
                        <li class="page-item <?php echo $tasksPageNum >= $totalRecentPages ? 'disabled' : ''; ?>">
                            <?php $nextParams = $paginateParams; $nextParams['p'] = min($totalRecentPages, $tasksPageNum + 1); ?>
                            <a class="page-link recent-tasks-page-link" href="?<?php echo http_build_query($nextParams, '', '&', PHP_QUERY_RFC3986); ?>" data-page="<?php echo min($totalRecentPages, $tasksPageNum + 1); ?>" aria-label="التالي">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- فلترة ديناميكية لجدول آخر المهام + الحفاظ على الفلتر عند التنقل بين الصفحات -->
<script>
(function() {
    'use strict';
    var tbody = document.getElementById('recentTasksTableBody');
    if (!tbody) return;
    var rows = tbody.querySelectorAll('tr.recent-tasks-filter-row');
    var form = document.getElementById('recentTasksFilterForm');
    if (!form) return;

    function normalize(s) {
        if (typeof s !== 'string') return '';
        return s.replace(/\s+/g, ' ').trim().toLowerCase();
    }

    function applyRecentTasksFilter() {
        var searchText = document.getElementById('recentTasksSearchText');
        var taskId = document.getElementById('recentTasksFilterTaskId');
        var customer = document.getElementById('recentTasksFilterCustomer');
        var taskType = document.getElementById('recentTasksFilterTaskType');
        var dueFrom = document.getElementById('recentTasksFilterDueFrom');
        var dueTo = document.getElementById('recentTasksFilterDueTo');
        var orderFrom = document.getElementById('recentTasksFilterOrderDateFrom');
        var orderTo = document.getElementById('recentTasksFilterOrderDateTo');

        var searchVal = searchText ? normalize(searchText.value) : '';
        var taskIdVal = taskId ? String((taskId.value || '').trim()) : '';
        var customerVal = customer ? normalize(customer.value) : '';
        var taskTypeVal = taskType ? (taskType.value || '').trim() : '';
        var dueFromVal = dueFrom ? (dueFrom.value || '').trim() : '';
        var dueToVal = dueTo ? (dueTo.value || '').trim() : '';
        var orderFromVal = orderFrom ? (orderFrom.value || '').trim() : '';
        var orderToVal = orderTo ? (orderTo.value || '').trim() : '';

        rows.forEach(function(tr) {
            var show = true;
            var rowTaskId = String(tr.getAttribute('data-task-id') || '');
            var rowSearch = normalize(tr.getAttribute('data-search') || '');
            var rowCustomer = normalize(tr.getAttribute('data-customer') || '');
            var rowTaskType = (tr.getAttribute('data-task-type') || '').trim();
            var rowDueDate = (tr.getAttribute('data-due-date') || '').trim();
            var rowOrderDate = (tr.getAttribute('data-order-date') || '').trim();

            if (searchVal && rowSearch.indexOf(searchVal) === -1) show = false;
            if (taskIdVal && rowTaskId.indexOf(taskIdVal) === -1) show = false;
            if (customerVal && rowCustomer.indexOf(customerVal) === -1) show = false;
            if (taskTypeVal && rowTaskType !== taskTypeVal) show = false;
            if (dueFromVal && rowDueDate && rowDueDate < dueFromVal) show = false;
            if (dueToVal && rowDueDate && rowDueDate > dueToVal) show = false;
            if (orderFromVal && rowOrderDate && rowOrderDate < orderFromVal) show = false;
            if (orderToVal && rowOrderDate && rowOrderDate > orderToVal) show = false;

            tr.style.display = show ? '' : 'none';
        });
    }

    var debounceTimer;
    function scheduleFilter() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(applyRecentTasksFilter, 180);
    }

    form.querySelectorAll('.recent-tasks-dynamic-filter').forEach(function(el) {
        if (el.tagName === 'SELECT' || (el.type === 'date')) {
            el.addEventListener('change', applyRecentTasksFilter);
        } else {
            el.addEventListener('input', scheduleFilter);
            el.addEventListener('keyup', scheduleFilter);
        }
    });
    form.addEventListener('submit', function(e) { e.preventDefault(); applyRecentTasksFilter(); });

    applyRecentTasksFilter();

    function initRecentTasksDropdowns() {
        if (typeof bootstrap === 'undefined' || !bootstrap.Dropdown) return;
        tbody.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(function(toggle) {
            var existing = bootstrap.Dropdown.getInstance(toggle);
            if (existing) { try { existing.dispose(); } catch(e) {} }
            new bootstrap.Dropdown(toggle, {
                popperConfig: function(defaultConfig) {
                    return Object.assign({}, defaultConfig, {
                        strategy: 'fixed',
                        modifiers: (defaultConfig.modifiers || []).concat([{
                            name: 'flip',
                            options: { fallbackPlacements: ['top-end', 'top-start', 'bottom-end'] }
                        }, {
                            name: 'preventOverflow',
                            options: { boundary: 'viewport' }
                        }])
                    });
                }
            });
        });
    }

    function doAjaxPage(targetPage) {
        var fd = new FormData(form);
        fd.set('p', targetPage);
        var params = [];
        fd.forEach(function(value, key) {
            if (value !== '' && value !== '0') params.push(encodeURIComponent(key) + '=' + encodeURIComponent(value));
        });
        var url = '?' + params.join('&');
        var wrapper = tbody.closest('.table-responsive');
        if (wrapper) wrapper.style.opacity = '0.5';
        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(r) { return r.text(); })
            .then(function(html) {
                var parser = new DOMParser();
                var doc = parser.parseFromString(html, 'text/html');
                var newTbody = doc.getElementById('recentTasksTableBody');
                if (newTbody) tbody.innerHTML = newTbody.innerHTML;
                rows = tbody.querySelectorAll('tr.recent-tasks-filter-row');
                var paginationNav = document.getElementById('recentTasksPagination');
                var newPagination = doc.getElementById('recentTasksPagination');
                if (paginationNav && newPagination) {
                    paginationNav.innerHTML = newPagination.innerHTML;
                } else if (paginationNav && !newPagination) {
                    paginationNav.innerHTML = '';
                }
                var allSmall = document.querySelectorAll('.card-header .text-muted.small');
                var oldCounter = null;
                allSmall.forEach(function(el) {
                    if (el.textContent.indexOf('صفحة') !== -1) oldCounter = el;
                });
                var newCounter = null;
                doc.querySelectorAll('.card-header .text-muted.small').forEach(function(el) {
                    if (el.textContent.indexOf('صفحة') !== -1) newCounter = el;
                });
                if (oldCounter && newCounter) oldCounter.textContent = newCounter.textContent;
                if (wrapper) wrapper.style.opacity = '';
                applyRecentTasksFilter();
                initRecentTasksDropdowns();
                history.replaceState(null, '', url);
            })
            .catch(function() {
                if (wrapper) wrapper.style.opacity = '';
                window.location.href = url;
            });
    }

    document.addEventListener('click', function(e) {
        var link = e.target && e.target.closest ? e.target.closest('a.recent-tasks-page-link') : null;
        if (!link || link.closest('.page-item.disabled')) return;
        var targetPage = link.getAttribute('data-page');
        if (!targetPage) return;
        e.preventDefault();
        doAjaxPage(targetPage);
    });
})();
</script>

<!-- فلترة ديناميكية بالضغط على بطاقات الحالة بدون ريفريش -->
<script>
(function() {
    'use strict';
    var statusCards = document.querySelectorAll('.status-filter-card');
    if (!statusCards.length) return;

    var statusStyles = {
        'all':           { bg: 'bg-primary',   text: 'text-white', border: 'border-primary', countClass: 'text-primary', labelActive: 'text-white-50' },
        'pending':       { bg: 'bg-warning',   text: 'text-dark',  border: 'border-warning', countClass: 'text-warning', labelActive: 'text-dark-50' },
        'completed':     { bg: 'bg-success',   text: 'text-white', border: 'border-success', countClass: 'text-success', labelActive: 'text-white-50' },
        'with_delegate': { bg: 'bg-info',      text: 'text-white', border: 'border-info',    countClass: 'text-info',    labelActive: 'text-white-50' },
        'with_shipping_company': { bg: 'bg-warning', text: 'text-dark', border: 'border-warning', countClass: 'text-warning', labelActive: 'text-dark' },
        'delivered':     { bg: 'bg-success',    text: 'text-white', border: 'border-success', countClass: 'text-success', labelActive: 'text-white-50' },
        'returned':      { bg: 'bg-secondary',  text: 'text-white', border: 'border-secondary', countClass: 'text-secondary', labelActive: 'text-white-50' }
    };

    function updateCardStyles(activeStatus) {
        statusCards.forEach(function(link) {
            var s = link.getAttribute('data-status');
            var style = statusStyles[s] || statusStyles['all'];
            var card = link.querySelector('.card');
            var label = card.querySelector('.small');
            var count = card.querySelector('.fs-5');
            var isActive = (s === activeStatus);

            card.classList.remove(style.bg, style.text, style.border);
            if (label) label.classList.remove('text-muted', style.labelActive);
            if (count) count.classList.remove(style.countClass, style.text);

            if (isActive) {
                card.classList.add(style.bg, style.text);
                if (label) label.classList.add(style.labelActive);
                if (count) count.classList.add(style.text);
            } else {
                card.classList.add(style.border);
                if (label) label.classList.add('text-muted');
                if (count) count.classList.add(style.countClass);
            }
        });
    }

    function doStatusFilter(status) {
        var url = status === 'all' ? '?page=production_tasks' : '?page=production_tasks&status=' + encodeURIComponent(status);
        var tbody = document.getElementById('recentTasksTableBody');
        var wrapper = tbody ? tbody.closest('.table-responsive') : null;
        if (wrapper) wrapper.style.opacity = '0.5';

        updateCardStyles(status);

        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(r) { return r.text(); })
            .then(function(responseHtml) {
                var parser = new DOMParser();
                var doc = parser.parseFromString(responseHtml, 'text/html');

                // Update table body using safe DOM replacement
                var newTbody = doc.getElementById('recentTasksTableBody');
                if (tbody && newTbody) {
                    while (tbody.firstChild) tbody.removeChild(tbody.firstChild);
                    while (newTbody.firstChild) tbody.appendChild(newTbody.firstChild);
                }

                // Update pagination
                var paginationNav = document.getElementById('recentTasksPagination');
                var newPagination = doc.getElementById('recentTasksPagination');
                if (paginationNav && newPagination) {
                    while (paginationNav.firstChild) paginationNav.removeChild(paginationNav.firstChild);
                    while (newPagination.firstChild) paginationNav.appendChild(newPagination.firstChild);
                } else if (paginationNav && !newPagination) {
                    while (paginationNav.firstChild) paginationNav.removeChild(paginationNav.firstChild);
                }

                // Update page counter
                var allSmall = document.querySelectorAll('.card-header .text-muted.small');
                var oldCounter = null;
                allSmall.forEach(function(el) {
                    if (el.textContent.indexOf('صفحة') !== -1) oldCounter = el;
                });
                var newCounter = null;
                doc.querySelectorAll('.card-header .text-muted.small').forEach(function(el) {
                    if (el.textContent.indexOf('صفحة') !== -1) newCounter = el;
                });
                if (oldCounter && newCounter) oldCounter.textContent = newCounter.textContent;

                // Update stats on cards
                var newCards = doc.querySelectorAll('.status-filter-card');
                newCards.forEach(function(newLink) {
                    var s = newLink.getAttribute('data-status');
                    var newCount = newLink.querySelector('.fs-5');
                    if (!newCount) return;
                    statusCards.forEach(function(oldLink) {
                        if (oldLink.getAttribute('data-status') === s) {
                            var oldCount = oldLink.querySelector('.fs-5');
                            if (oldCount) oldCount.textContent = newCount.textContent;
                        }
                    });
                });

                if (wrapper) wrapper.style.opacity = '';

                // Re-init dropdowns
                if (typeof bootstrap !== 'undefined' && bootstrap.Dropdown) {
                    tbody.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(function(toggle) {
                        var existing = bootstrap.Dropdown.getInstance(toggle);
                        if (existing) { try { existing.dispose(); } catch(e) {} }
                        new bootstrap.Dropdown(toggle);
                    });
                }

                history.replaceState(null, '', url);
            })
            .catch(function() {
                if (wrapper) wrapper.style.opacity = '';
                window.location.href = url;
            });
    }

    statusCards.forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            doStatusFilter(link.getAttribute('data-status'));
        });
    });
})();
</script>

<!-- Card تغيير حالة المهمة (مخصص للموبايل) -->
<div class="container-fluid px-0">
    <div class="collapse" id="changeStatusCardCollapse">
        <div class="card shadow-sm border-info mb-3">
            <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="bi bi-gear me-2"></i>تغيير حالة الطلب
                </h5>
                <button type="button" class="btn btn-sm btn-light" onclick="closeChangeStatusCard()" aria-label="إغلاق">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
            <form method="POST" id="changeStatusCardForm" action="?page=production_tasks">
                <input type="hidden" name="action" value="update_task_status">
                <input type="hidden" name="task_id" id="changeStatusCardTaskId">
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">الحالة الحالية</label>
                        <div id="currentStatusCardDisplay" class="alert alert-info mb-0"></div>
                    </div>
                    <div class="mb-3">
                        <label for="newStatusCard" class="form-label fw-bold">اختر الحالة الجديدة <span class="text-danger">*</span></label>
                        <select class="form-select" name="status" id="newStatusCard" required>
                            <option value="">-- اختر الحالة --</option>
                            <option value="pending">معلقة</option>
                            <option value="completed">مكتملة</option>
                            <option value="with_delegate">مع المندوب</option>
                            <option value="with_shipping_company">مع شركة الشحن</option>
                            <option value="delivered">تم التوصيل</option>
                            <option value="returned">تم الارجاع</option>
                            <option value="cancelled">ملغاة</option>
                        </select>
                        <div class="form-text">سيتم تحديث حالة الطلب فوراً بعد الحفظ.</div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-secondary w-50" onclick="closeChangeStatusCard()">
                            <i class="bi bi-x-circle me-1"></i>إلغاء
                        </button>
                        <button type="submit" class="btn btn-info w-50">
                            <i class="bi bi-check-circle me-1"></i>حفظ
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal تغيير حالة المهمة -->
<div class="modal fade" id="changeStatusModal" tabindex="-1" aria-labelledby="changeStatusModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="changeStatusModalLabel">
                    <i class="bi bi-gear me-2"></i>تغيير حالة الطلب
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <form method="POST" id="changeStatusForm" action="?page=production_tasks">
                <input type="hidden" name="action" value="update_task_status">
                <input type="hidden" name="task_id" id="changeStatusTaskId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">الحالة الحالية</label>
                        <div id="currentStatusDisplay" class="alert alert-info mb-0"></div>
                    </div>
                    <div class="mb-3">
                        <label for="newStatus" class="form-label fw-bold">اختر الحالة الجديدة <span class="text-danger">*</span></label>
                        <select class="form-select" name="status" id="newStatus" required>
                            <option value="">-- اختر الحالة --</option>
                            <option value="pending">معلقة</option>
                            <option value="completed">مكتملة</option>
                            <option value="with_delegate">مع المندوب</option>
                            <option value="with_shipping_company">مع شركة الشحن</option>
                            <option value="delivered">تم التوصيل</option>
                            <option value="returned">تم الارجاع</option>
                            <option value="cancelled">ملغاة</option>
                        </select>
                        <div class="form-text">سيتم تحديث حالة الطلب فوراً بعد الحفظ.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-info">
                        <i class="bi bi-check-circle me-1"></i>حفظ التغييرات
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- مودال إيصال الأوردر -->
<div class="modal fade" id="orderReceiptModal" tabindex="-1" aria-labelledby="orderReceiptModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-light border-bottom">
                <h5 class="modal-title" id="orderReceiptModalLabel"><i class="bi bi-receipt me-2"></i>إيصال الأوردر</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body p-4" id="orderReceiptContent">
                <div class="text-center py-4 text-muted" id="orderReceiptLoading">
                    <div class="spinner-border" role="status"></div>
                    <p class="mt-2 mb-0">جاري تحميل تفاصيل الأوردر...</p>
                </div>
                <div id="orderReceiptBody" style="display: none;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
            </div>
        </div>
    </div>
</div>

<!-- مودال إيصال مختصر (رقم الأوردر + المنتجات والكميات فقط) -->
<div class="modal fade" id="taskReceiptModal" tabindex="-1" aria-labelledby="taskReceiptModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2 bg-light border-bottom">
                <h6 class="modal-title" id="taskReceiptModalLabel"><i class="bi bi-eye me-1"></i>إيصال</h6>
                <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body p-3" id="taskReceiptContent">
                <div class="text-center py-3 text-muted" id="taskReceiptLoading">
                    <div class="spinner-border spinner-border-sm" role="status"></div>
                    <p class="mt-2 mb-0 small">جاري التحميل...</p>
                </div>
                <div id="taskReceiptBody" style="display: none;"></div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="receiptIframeModal" tabindex="-1" aria-labelledby="receiptIframeModalLabel" aria-hidden="true" data-no-loading="true" data-bs-backdrop="false">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2 bg-light border-bottom">
                <h6 class="modal-title" id="receiptIframeModalLabel"><i class="bi bi-file-text me-1"></i>إيصال الطلب</h6>
                <button type="button" class="btn btn-sm btn-outline-secondary border-0 px-2" style="touch-action:manipulation" onpointerdown="closeReceiptIframeModal()" aria-label="إغلاق">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
            <div class="modal-body p-0">
                <iframe id="receiptIframeEl" src="about:blank" style="width:100%;height:75vh;border:none;"></iframe>
            </div>
        </div>
    </div>
</div>
<style>
@media (max-width: 767.98px) {
    #receiptIframeModal .modal-dialog {
        margin: 0;
        max-width: 100%;
        width: 100%;
        height: 100%;
    }
    #receiptIframeModal .modal-content {
        height: 100%;
        border: none;
        border-radius: 0;
        box-shadow: none;
    }
    #receiptIframeModal #receiptIframeEl {
        height: calc(100vh - 49px);
    }
    #receiptIframeModal.modal {
        padding: 0 !important;
    }
    /* انتقال سلس من الأسفل للأعلى */
    #receiptIframeModal .modal-dialog {
        transform: translateY(100%);
        transition: transform 0.2s ease-out;
    }
    #receiptIframeModal.show .modal-dialog {
        transform: translateY(0);
    }
    /* تسريع أنيميشن الإغلاق */
    #receiptIframeModal.fade {
        transition: opacity 0.15s linear;
    }
}
/* إغلاق فوري بدون أي انتقال — يُطبَّق عند الضغط على زر الإغلاق */
#receiptIframeModal.instant-close,
#receiptIframeModal.instant-close .modal-dialog,
#receiptIframeModal.instant-close .modal-content {
    transition: none !important;
    animation: none !important;
}

/* وضع البطاقة على الهاتف — بدون Bootstrap modal */
#receiptIframeModal.card-mode {
    display: block !important;
    position: fixed;
    inset: 0;
    z-index: 1055;
    background: #fff;
    opacity: 0;
    transform: translateY(100%);
    transition: transform 0.2s ease-out, opacity 0.2s ease-out;
    padding: 0 !important;
    overflow: hidden;
}
#receiptIframeModal.card-mode.card-show {
    opacity: 1;
    transform: translateY(0);
}
#receiptIframeModal.card-mode .modal-dialog {
    margin: 0;
    max-width: 100%;
    width: 100%;
    height: 100%;
    transform: none !important;
    transition: none !important;
    pointer-events: auto;
}
#receiptIframeModal.card-mode .modal-content {
    height: 100%;
    border: none;
    border-radius: 0;
    box-shadow: none;
    display: flex;
    flex-direction: column;
}
#receiptIframeModal.card-mode .modal-body {
    flex: 1 1 0;
    overflow: hidden;
    padding: 0;
}
#receiptIframeModal.card-mode #receiptIframeEl {
    width: 100%;
    height: 100%;
    border: none;
    display: block;
}
</style>

<style>
.search-wrap.position-relative { position: relative; }
.search-dropdown-task { position: absolute; left: 0; right: 0; top: 100%; z-index: 1055; max-height: 220px; overflow-y: auto; background: #fff; border: 1px solid #dee2e6; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); margin-top: 2px; }
#createTaskFormCollapse .card-body,
#createTaskFormCollapse .row { overflow: visible !important; }
#editTaskFormCollapse .card-body,
#editTaskFormCollapse .row { overflow: visible !important; }
.search-dropdown-task .search-dropdown-item-task { padding: 0.5rem 0.75rem; cursor: pointer; border-bottom: 1px solid #f0f0f0; }
.search-dropdown-task .search-dropdown-item-task:hover { background: #f8f9fa; }
.search-dropdown-task .search-dropdown-item-task:last-child { border-bottom: none; }
.product-name-wrap.position-relative { position: relative; }
.product-template-dropdown { position: absolute; left: 0; right: 0; top: 100%; z-index: 1050; max-height: 220px; overflow-y: auto; background: #fff; border: 1px solid #dee2e6; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); margin-top: 2px; }
.product-template-dropdown .product-template-item { padding: 0.5rem 0.75rem; cursor: pointer; border-bottom: 1px solid #f0f0f0; }
.product-template-dropdown .product-template-item:hover { background: #f8f9fa; }
.product-template-dropdown .product-template-item:last-child { border-bottom: none; }
.template-picker { display: flex; flex-wrap: wrap; gap: 0.4rem; padding: 0.5rem; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; max-height: 200px; overflow-y: auto; }
.template-picker-btn { display: flex; flex-direction: column; align-items: flex-start; gap: 0.1rem; padding: 0.35rem 0.6rem; border: 1px solid #dee2e6; border-radius: 6px; background: #fff; cursor: pointer; font-size: 0.8rem; transition: all 0.15s; text-align: right; }
.template-picker-btn:hover { border-color: #0d6efd; background: #e9f0ff; }
.template-picker-btn.selected { border-color: #0d6efd; background: #0d6efd; color: #fff; }
.template-picker-btn.selected .tpl-qty { color: #cfe2ff !important; }
.template-picker-btn .tpl-name { font-weight: 600; }
.template-picker-btn .tpl-qty { font-size: 0.72rem; }
.task-draft-item {
    padding: 0.75rem 0.9rem;
}
.task-draft-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
}
.task-draft-info {
    min-width: 0;
    flex: 1 1 auto;
}
.task-draft-title {
    display: flex;
    align-items: center;
    gap: 0.15rem;
    font-size: 0.95rem;
    line-height: 1.4;
    word-break: break-word;
}
.task-draft-meta {
    margin-top: 0.2rem;
    font-size: 0.76rem;
}
.task-draft-actions {
    display: flex;
    gap: 0.45rem;
    flex-shrink: 0;
}
.task-draft-actions .btn {
    white-space: nowrap;
}
@media (max-width: 768px) {
    #taskDraftsCard .card-header h5 {
        font-size: 0.95rem;
    }
    .task-draft-item {
        padding: 0.65rem 0.75rem;
    }
    .task-draft-row {
        flex-direction: column;
        align-items: stretch;
        gap: 0.6rem;
    }
    .task-draft-title {
        font-size: 0.88rem;
    }
    .task-draft-meta {
        font-size: 0.72rem;
    }
    .task-draft-actions {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        width: 100%;
    }
    .task-draft-actions .btn {
        font-size: 0.78rem;
        padding: 0.35rem 0.45rem;
    }
}
/* إخفاء خانة العميل اليدوي إن وُجدت (كاش قديم) */
#customer_manual_block_task { display: none !important; }
input[name="customer_type_radio_task"][value="manual"] { display: none !important; }
label[for="ct_task_manual"], .form-check:has(#ct_task_manual) { display: none !important; }

/* Collapsible Cards Animation */
.toggle-cards-btn {
    transition: all 0.2s ease-in-out;
    border: none !important;
    background: transparent !important;
}

.toggle-cards-btn:hover {
    transform: scale(1.1);
    background: rgba(0,0,0,0.05) !important;
}

.toggle-icon {
    transition: transform 0.3s ease-in-out;
}

.card-body.p-0 .collapse {
    transition: height 0.35s ease-in-out, opacity 0.3s ease-in-out;
}

.card-body.p-0 .collapse.show {
    opacity: 1;
}

.card-body.p-0 .collapse:not(.show) {
    opacity: 0;
    height: 0 !important;
}

/* Filter section collapse animation */
#filterSectionCollapse {
    transition: height 0.35s ease-in-out, opacity 0.3s ease-in-out;
}

#filterSectionCollapse.show {
    opacity: 1;
}

#filterSectionCollapse:not(.show) {
    opacity: 0;
    height: 0 !important;
}

/* Compact filter cards */
#statusFilterCards .card-body {
    padding: 0.35rem 0.5rem !important;
}

#statusFilterCards .card-body .small {
    font-size: 0.6rem !important;
    margin-bottom: 0.15rem !important;
}

#statusFilterCards .card-body .fs-5 {
    font-size: 0.75rem !important;
    font-weight: 600 !important;
}

#statusFilterCards .card {
    border-radius: 0.375rem !important;
    min-height: 4rem !important;
}

#statusFilterCards .card-body {
    display: flex !important;
    flex-direction: column !important;
    justify-content: center !important;
    align-items: center !important;
    height: 100% !important;
}

#statusFilterCards .card-body .small {
    font-size: 0.65rem !important;
    margin-bottom: 0.2rem !important;
    font-weight: 500 !important;
    line-height: 1.2 !important;
}

#statusFilterCards .card-body .fs-5 {
    font-size: 0.85rem !important;
    font-weight: 700 !important;
    line-height: 1.1 !important;
}

/* Smooth card header styling */
.card-header .toggle-cards-btn {
    padding: 0.25rem 0.5rem;
    border-radius: 0.25rem;
}

.card-header .toggle-cards-btn:hover {
    background: rgba(255,255,255,0.2) !important;
}

.card.bg-success .card-header .toggle-cards-btn:hover,
.card-header.bg-success .toggle-cards-btn:hover {
    background: rgba(255,255,255,0.3) !important;
}

.card.bg-light .card-header .toggle-cards-btn:hover {
    background: rgba(0,0,0,0.1) !important;
}
</style>
<script>
console.log('[DEBUG-SCRIPT] production_tasks script START');
var __localCustomersForTask = <?php echo json_encode($localCustomersForDropdown, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]'; ?>;
var __repCustomersForTask = <?php echo json_encode($repCustomersForTask, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]'; ?>;
console.log('[DEBUG-SCRIPT] localCustomers:', __localCustomersForTask ? __localCustomersForTask.length : 'NULL');
console.log('[DEBUG-SCRIPT] repCustomers:', __repCustomersForTask ? __repCustomersForTask.length : 'NULL');
var __shippingCompaniesForTask = <?php echo json_encode($shippingCompaniesForDropdown, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]'; ?>;
var __quCategories = <?php echo json_encode($quCategoriesForTask, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]'; ?>;
var __quData = <?php echo json_encode($quDataForTask, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}'; ?>;

function makeIdBadge(id) {
    if (!id && id !== 0) return '';
    return '<span class="almostafa-id-badge">' + String(id) + '</span> ';
}
function escHtml(str) {
    return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

var editProductIndex = 0;
function buildEditProductRow(idx, product) {
    var p = product || { name: '', quantity: '', unit: 'قطعة', price: '', line_total: '', item_type: '' };
    var unitVal = String(p.unit || 'قطعة').trim();
    var itemType = String(p.item_type || '').trim();
    var isRawMat = itemType === 'raw_material';
    var isTemplate = itemType === 'template';
    var isPackaging = itemType === 'packaging';
    var isSecondGrade = itemType === 'second_grade';
    var typeSelectorVal = ['external','template','second_grade','raw_material','packaging'].indexOf(itemType) !== -1 ? itemType : '';
    var isExternal = itemType === 'external';
    var unitList = isRawMat ? ['كيلو','جرام'] : (isTemplate ? ['قطعة','كرتونة'] : (isSecondGrade ? ['قطعة','كيلو','كرتونة'] : (isExternal ? ['كرتونة','شرينك','دسته','قطعة'] : (isPackaging ? ['قطعة','عبوة','كرتونة','دسته'] : ['كرتونة','عبوة','كيلو','جرام','شرينك','دسته','قطعة']))));
    var unitOpts = unitList.map(function(u) {
        return '<option value="' + u + '"' + (u === unitVal ? ' selected' : '') + '>' + u + '</option>';
    }).join('');
    var typeSelectorOpts =
        '<option value=""' + (typeSelectorVal === '' ? ' selected' : '') + '>— اختر النوع —</option>' +
        '<option value="external"' + (typeSelectorVal === 'external' ? ' selected' : '') + '>📦 منتجات خارجية</option>' +
        '<option value="template"' + (typeSelectorVal === 'template' ? ' selected' : '') + '>🏭 منتجات المصنع</option>' +
        '<option value="second_grade"' + (typeSelectorVal === 'second_grade' ? ' selected' : '') + '>♻️ فرز تاني</option>' +
        '<option value="raw_material"' + (typeSelectorVal === 'raw_material' ? ' selected' : '') + '>⚗️ خامات</option>' +
        '<option value="packaging"' + (typeSelectorVal === 'packaging' ? ' selected' : '') + '>🧴 أدوات تعبئة</option>';
    var quCats = (typeof __quCategories !== 'undefined' && Array.isArray(__quCategories)) ? __quCategories : [];
    var catVal = String(p.category || '').trim();
    var catOpts = '<option value="">— اختر التصنيف —</option>' + quCats.map(function(qc) {
        var t = (qc.type || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        return '<option value="' + t + '"' + (t === catVal ? ' selected' : '') + '>' + t + '</option>';
    }).join('');
    var initRawQty = '—';
    var initRawQtyClass = 'raw-material-qty-value fw-semibold text-info';
    if ((isRawMat || isPackaging || isTemplate) && p.name) {
        var _rmDetail = (typeof getProductDetail === 'function') ? getProductDetail(p.name) : null;
        if (_rmDetail && _rmDetail.available_qty !== undefined) {
            var _qty = parseFloat(_rmDetail.available_qty);
            var _qtyUnit = isPackaging ? (_rmDetail.unit || 'قطعة') : (isTemplate ? 'قطعة' : 'كيلو');
            initRawQty = _qty.toLocaleString('ar-EG', {maximumFractionDigits: 3}) + ' ' + _qtyUnit;
            initRawQtyClass = 'raw-material-qty-value fw-semibold ' + (_qty > 0 ? 'text-success' : 'text-danger');
        }
    }
    var qtyVal = (p.quantity !== null && p.quantity !== undefined && p.quantity !== '') ? String(p.quantity) : '';
    var priceVal = (p.price !== null && p.price !== undefined && p.price !== '') ? String(p.price) : '';
    var lineTotalVal = (p.line_total !== null && p.line_total !== undefined && p.line_total !== '') ? String(p.line_total) : '';
    var nameVal = String(p.name || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    return '<div class="product-row mb-3 p-3 border rounded edit-product-row" data-edit-product-index="' + idx + '" data-item-type="' + (isRawMat ? 'raw_material' : (isPackaging ? 'packaging' : '')) + '">' +
        '<div class="row g-2">' +
        '<div class="col-12 col-md-3">' +
        '<label class="form-label small">النوع</label>' +
        '<select class="form-select form-select-sm product-type-selector mb-1" name="products[' + idx + '][item_type]">' + typeSelectorOpts + '</select>' +
        '<div class="product-name-wrap position-relative">' +
        '<input type="text" class="form-control edit-product-name" name="products[' + idx + '][name]" placeholder="اختر من القائمة" autocomplete="off" list="templateSuggestions" value="' + nameVal + '">' +
        '</div></div>' +
        '<div class="col-6 col-md-2"><label class="form-label small">الكمية</label>' +
        '<input type="number" class="form-control edit-product-qty" name="products[' + idx + '][quantity]" step="1" min="0" placeholder="0" value="' + qtyVal + '"></div>' +
        '<div class="col-6 col-md-2">' +
        '<div class="category-wrap"' + ((isRawMat || isTemplate || isPackaging) ? ' style="display:none"' : '') + '><label class="form-label small">التصنيف</label>' +
        '<select class="form-select form-select-sm edit-product-category" name="products[' + idx + '][category]">' + catOpts + '</select></div>' +
        '<div class="raw-qty-wrap"' + ((!isRawMat && !isPackaging && !isTemplate) ? ' style="display:none"' : '') + '>' +
        '<label class="form-label small text-info">الكمية المتاحة</label>' +
        '<div class="' + initRawQtyClass + '">' + initRawQty + '</div></div></div>' +
        '<div class="col-6 col-md-2"><label class="form-label small">الوحدة</label>' +
        '<select class="form-select form-select-sm edit-product-unit" name="products[' + idx + '][unit]">' + unitOpts + '</select></div>' +
        '<div class="col-6 col-md-2"><label class="form-label small">السعر</label>' +
        '<input type="number" class="form-control edit-product-price" name="products[' + idx + '][price]" step="0.01" min="0" placeholder="0.00" value="' + priceVal + '"></div>' +
        '<div class="col-6 col-md-2"><label class="form-label small">الإجمالي</label>' +
        '<div class="input-group input-group-sm"><input type="number" class="form-control edit-product-line-total" name="products[' + idx + '][line_total]" step="0.01" min="0" placeholder="0.00" value="' + lineTotalVal + '"><span class="input-group-text">ج.م</span></div></div>' +
        '<div class="col-6 col-md-1 d-flex align-items-end">' +
        '<button type="button" class="btn btn-danger btn-sm w-100 edit-remove-product-btn"><i class="bi bi-trash"></i></button></div></div></div>';
}
/** عند تغيير الكمية أو السعر: الإجمالي = الكمية × السعر */
function updateEditProductLineTotal(row) {
    if (!row) return;
    var qtyInput = row.querySelector('.edit-product-qty');
    var priceInput = row.querySelector('.edit-product-price');
    var totalInput = row.querySelector('.edit-product-line-total');
    if (!qtyInput || !priceInput || !totalInput) return;
    var qty = parseFloat(qtyInput.value || '0');
    var price = parseFloat(priceInput.value || '0');
    var total = qty * price;
    totalInput.value = total > 0 ? total.toFixed(2) : '';
}
/** عند تغيير الإجمالي: السعر = الإجمالي ÷ الكمية */
function syncEditPriceFromLineTotal(row) {
    if (!row) return;
    var qtyInput = row.querySelector('.edit-product-qty');
    var priceInput = row.querySelector('.edit-product-price');
    var totalInput = row.querySelector('.edit-product-line-total');
    if (!qtyInput || !priceInput || !totalInput) return;
    var qty = parseFloat(qtyInput.value || '0');
    var totalVal = parseFloat(totalInput.value || '0');
    if (qty > 0 && totalVal >= 0) {
        priceInput.value = (totalVal / qty).toFixed(2);
    }
}
function updateEditTaskSummary() {
    var container = document.getElementById('editProductsContainer');
    var subEl = document.getElementById('editTaskSubtotalDisplay');
    var shipEl = document.getElementById('editTaskShippingDisplay');
    var discountEl = document.getElementById('editTaskDiscountDisplay');
    var finalEl = document.getElementById('editTaskFinalTotalDisplay');
    var advanceEl = document.getElementById('editTaskAdvancePaymentDisplay');
    var remainingEl = document.getElementById('editTaskRemainingDisplay');
    var shipInput = document.getElementById('editTaskShippingFees');
    var discountInput = document.getElementById('editTaskDiscount');
    var advanceInput = document.getElementById('editTaskAdvancePayment');
    if (!container || !subEl || !shipEl || !finalEl) return;
    var subtotal = 0;
    container.querySelectorAll('.edit-product-line-total').forEach(function(input) {
        var v = parseFloat(input.value);
        if (!isNaN(v) && v >= 0) subtotal += v;
    });
    var isTg = document.getElementById('editTaskType') && document.getElementById('editTaskType').value === 'telegraph';
    var shipping = 0;
    if (!isTg && shipInput) {
        var v = parseFloat(shipInput.value || '0');
        if (!isNaN(v) && v >= 0) shipping = v;
    }
    var discount = (discountInput && !isNaN(parseFloat(discountInput.value))) ? Math.max(0, parseFloat(discountInput.value)) : 0;
    var advance = (advanceInput && !isNaN(parseFloat(advanceInput.value))) ? Math.max(0, parseFloat(advanceInput.value)) : 0;
    var finalTotal;
    if (isTg) {
        var deliveryCost = window._tgEditDeliveryCost || 0;
        finalTotal = subtotal - deliveryCost;
    } else {
        finalTotal = subtotal + shipping - discount;
    }
    var remaining = finalTotal - advance;
    subEl.textContent = subtotal.toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ج.م';
    shipEl.textContent = shipping.toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ج.م';
    if (discountEl) discountEl.textContent = discount.toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ج.م';
    finalEl.textContent = finalTotal.toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ج.م';
    if (advanceEl) advanceEl.textContent = advance.toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ج.م';
    if (remainingEl) remainingEl.textContent = remaining.toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ج.م';
    var advanceCol = document.getElementById('editTaskAdvancePaymentCol');
    var remainingCol = document.getElementById('editTaskRemainingCol');
    if (advanceCol) advanceCol.style.display = advance > 0 ? '' : 'none';
    if (remainingCol) remainingCol.style.display = advance > 0 ? '' : 'none';
}
function delegateEditSummaryInputs() {
    var form = document.getElementById('editTaskForm');
    if (!form || form._editSummaryDelegated) return;
    form._editSummaryDelegated = true;
    function onEditProductInput(e) {
        var row = e.target.closest('.edit-product-row');
        if (row) {
            if (e.target.matches('.edit-product-qty') || e.target.matches('.edit-product-price')) {
                updateEditProductLineTotal(row);
            } else if (e.target.matches('.edit-product-line-total')) {
                syncEditPriceFromLineTotal(row);
            }
        }
        if (e.target.matches('.edit-product-line-total, .edit-product-price, .edit-product-qty') || e.target.id === 'editTaskShippingFees' || e.target.id === 'editTaskDiscount' || e.target.id === 'editTaskAdvancePayment') {
            updateEditTaskSummary();
        }
    }
    form.addEventListener('input', onEditProductInput);
    form.addEventListener('change', onEditProductInput);
}
function addEditProductRow(product) {
    var container = document.getElementById('editProductsContainer');
    if (!container) return;
    var row = document.createElement('div');
    row.innerHTML = buildEditProductRow(editProductIndex, product);
    row = row.firstElementChild;
    container.appendChild(row);
    row.querySelector('.edit-remove-product-btn').addEventListener('click', function() {
        var rows = container.querySelectorAll('.edit-product-row');
        if (rows.length > 1) row.remove();
        updateEditTaskSummary();
    });
    editProductIndex++;
    updateEditTaskSummary();
}
window.closeEditTaskCard = function() {
    var collapse = document.getElementById('editTaskFormCollapse');
    if (collapse) {
        var bs = bootstrap.Collapse.getInstance(collapse);
        if (bs) bs.hide();
    }
};
window.openEditTaskModal = function(taskId) {
    var createCollapse = document.getElementById('createTaskFormCollapse');
    var editCollapse = document.getElementById('editTaskFormCollapse');
    if (!editCollapse) return;
    if (createCollapse) {
        var createBs = bootstrap.Collapse.getInstance(createCollapse);
        if (createBs) createBs.hide();
    }
    document.getElementById('editTaskId').value = taskId;
    var container = document.getElementById('editProductsContainer');
    if (container) container.innerHTML = '';
    editProductIndex = 0;
    var url = new URL(window.location.href);
    url.searchParams.set('action', 'get_task_for_edit');
    url.searchParams.set('task_id', String(taskId));
    fetch(url.toString())
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success && data.task) {
                var t = data.task;
                document.getElementById('editTaskType').value = t.task_type || 'shop_order';
                document.getElementById('editPriority').value = t.priority || 'normal';
                document.getElementById('editDueDate').value = t.due_date || '';
                // حقول العميل الجديدة
                var editSubName = document.getElementById('edit_submit_customer_name');
                if (editSubName) editSubName.value = t.customer_name || '';
                var editLocalSearch = document.getElementById('local_customer_search_edit');
                if (editLocalSearch) editLocalSearch.value = t.customer_name || '';
                var editLocalId = document.getElementById('local_customer_id_edit');
                if (editLocalId) editLocalId.value = '';
                var editSubPhone = document.getElementById('edit_submit_customer_phone');
                if (editSubPhone) editSubPhone.value = t.customer_phone || '';
                // إعادة تعيين راديو العميل إلى محلي
                var localRadio = document.getElementById('ct_edit_local');
                if (localRadio) { localRadio.checked = true; localRadio.dispatchEvent(new Event('change')); }
                // تعطيل زر السجل حتى يتم اختيار عميل مسجل
                var editHistBtn = document.getElementById('editViewCustomerHistoryBtn');
                if (editHistBtn) { editHistBtn.disabled = true; editHistBtn.title = 'اختر عميلاً أولاً'; }
                // إخفاء بطاقة السجل
                var editHistCard = document.getElementById('editCustomerPurchaseHistoryCard');
                if (editHistCard) editHistCard.style.display = 'none';
                document.getElementById('editDetails').value = t.details || '';
                var orderTitleEl = document.getElementById('editOrderTitle');
                if (orderTitleEl && t.order_title !== undefined) orderTitleEl.value = t.order_title || '';
                var editGovEl = document.getElementById('editGov');
                var editGovSearch = document.getElementById('editGovSearch');
                if (editGovEl) editGovEl.value = t.tg_governorate || '';
                if (editGovSearch) editGovSearch.value = t.tg_governorate || '';
                // مهم: TelelgraphEx حساب تكلفة التوصيل في نموذج التعديل يعتمد على editGovId/editCityId.
                // triggerCityFetchByGovName قد لا يعبّي govId، لذلك بنستخدم setTgAutoFillIds أولاً إن وُجد.
                if (t.tg_governorate && typeof window.setTgAutoFillIds === 'function') {
                    window.setTgAutoFillIds(t.tg_governorate, 'editGovId', 'editCitySearch', t.tg_city || '');
                    // ضمان تعبئة قيمة الاسم الظاهرة فوراً (حتى لو هتتحول لاحقاً عبر autocomplete)
                    var editCityHidden = document.getElementById('editCity');
                    var editCitySearch = document.getElementById('editCitySearch');
                    if (editCityHidden) editCityHidden.value = t.tg_city || '';
                    if (editCitySearch) editCitySearch.value = t.tg_city || '';
                } else if (t.tg_governorate && typeof window.triggerCityFetchByGovName === 'function') {
                    window.triggerCityFetchByGovName(t.tg_governorate, 'editCitySearch', 'editCity', t.tg_city || '');
                } else {
                    var editCityHidden = document.getElementById('editCity');
                    var editCitySearch = document.getElementById('editCitySearch');
                    if (editCityHidden) editCityHidden.value = t.tg_city || '';
                    if (editCitySearch) editCitySearch.value = t.tg_city || '';
                }
                var editTgWeightEl = document.getElementById('editTgWeight');
                if (editTgWeightEl) editTgWeightEl.value = t.tg_weight || '';
                var editTgParcelDescEl = document.getElementById('editTgParcelDesc');
                if (editTgParcelDescEl) editTgParcelDescEl.value = t.tg_parcel_desc || '';
                if (typeof window.toggleEditTgFields === 'function') window.toggleEditTgFields();
                var shippingEl = document.getElementById('editTaskShippingFees');
                if (shippingEl && typeof t.shipping_fees === 'number') shippingEl.value = t.shipping_fees;
                if (shippingEl && (typeof t.shipping_fees === 'string' && t.shipping_fees !== '')) shippingEl.value = t.shipping_fees;
                var discountEl = document.getElementById('editTaskDiscount');
                if (discountEl && (typeof t.discount === 'number' || (typeof t.discount === 'string' && t.discount !== ''))) discountEl.value = t.discount;
                var advancePayEl = document.getElementById('editTaskAdvancePayment');
                if (advancePayEl) advancePayEl.value = (typeof t.advance_payment === 'number' || (typeof t.advance_payment === 'string' && t.advance_payment !== '')) ? t.advance_payment : 0;
                var products = Array.isArray(t.products) ? t.products : [];
                if (products.length === 0) products = [{}];
                products.forEach(function(p) {
                    var row = { name: p.name || '', quantity: p.quantity, unit: p.unit || 'قطعة', price: p.price, line_total: p.line_total };
                    addEditProductRow(row);
                });
                updateEditTaskSummary();
                delegateEditSummaryInputs();
            }
        })
        .catch(function() { addEditProductRow({}); });
    var editBs = bootstrap.Collapse.getInstance(editCollapse) || new bootstrap.Collapse(editCollapse, { toggle: false });
    editBs.show();
    setTimeout(function() { editCollapse.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 100);
};
document.addEventListener('DOMContentLoaded', function() {
    var editAddBtn = document.getElementById('editAddProductBtn');
    if (editAddBtn) {
        editAddBtn.addEventListener('click', function() { addEditProductRow({}); });
    }
    delegateEditSummaryInputs();

    // === تهيئة بحث العميل في نموذج التعديل ===
    (function initCustomerCardEdit() {
        var localCustomers = (typeof __localCustomersForTask !== 'undefined' && Array.isArray(__localCustomersForTask)) ? __localCustomersForTask : [];
        var repCustomers = (typeof __repCustomersForTask !== 'undefined' && Array.isArray(__repCustomersForTask)) ? __repCustomersForTask : [];
        var submitName  = document.getElementById('edit_submit_customer_name');
        var submitPhone = document.getElementById('edit_submit_customer_phone');
        var localSearch = document.getElementById('local_customer_search_edit');
        var localId     = document.getElementById('local_customer_id_edit');
        var localDrop   = document.getElementById('local_customer_dropdown_edit');
        var repSearch   = document.getElementById('rep_customer_search_edit');
        var repDrop     = document.getElementById('rep_customer_dropdown_edit');
        var histBtn     = document.getElementById('editViewCustomerHistoryBtn');
        var histCard    = document.getElementById('editCustomerPurchaseHistoryCard');
        var histClose   = document.getElementById('editCloseHistoryCard');
        if (!submitName || !submitPhone) return;

        function matchSearch(text, q) {
            if (!q || !text) return true;
            return (text + '').toLowerCase().indexOf((q + '').trim().toLowerCase()) !== -1;
        }
        function matchLocal(c, q) {
            var extra = (c.phones && c.phones.length) ? c.phones.join(' ') : (c.phone || '');
            return matchSearch((c.name || '') + ' ' + extra, q);
        }
        function matchRep(c, q) {
            return matchSearch((c.name || '') + ' ' + (c.rep_name || '') + ' ' + (c.phone || ''), q);
        }

        function setEditCustomerBlocks() {
            var v = document.querySelector('input[name="customer_type_radio_edit"]:checked');
            var val = v ? v.value : 'local';
            var localBlock = document.getElementById('customer_select_local_edit');
            var repBlock   = document.getElementById('customer_select_rep_edit');
            if (localBlock) localBlock.classList.toggle('d-none', val !== 'local');
            if (repBlock)   repBlock.classList.toggle('d-none',   val !== 'rep');
            if (val !== 'local') { if (localSearch) localSearch.value = ''; if (localId) localId.value = ''; if (localDrop) localDrop.classList.add('d-none'); }
            if (val !== 'rep')   { if (repSearch) repSearch.value = ''; if (repDrop) repDrop.classList.add('d-none'); }
        }
        document.querySelectorAll('input[name="customer_type_radio_edit"]').forEach(function(r) {
            r.addEventListener('change', setEditCustomerBlocks);
        });
        setEditCustomerBlocks();

        function positionEditDropdown(inputEl, dropEl) {
            var rect = inputEl.getBoundingClientRect();
            dropEl.style.position = 'fixed';
            dropEl.style.top = (rect.bottom + 2) + 'px';
            dropEl.style.left = rect.left + 'px';
            dropEl.style.width = rect.width + 'px';
            dropEl.style.right = 'auto';
            dropEl.style.zIndex = '9999';
        }
        function showEditDrop(inputEl, hiddenIdEl, dropEl, list, getLabel, matcher) {
            if (!inputEl || !dropEl) return;
            var q = (inputEl.value || '').trim();
            var filtered = list.filter(function(c) { return matcher(c, q); });
            dropEl.innerHTML = '';
            if (!filtered.length) { dropEl.classList.add('d-none'); return; }
            filtered.forEach(function(c) {
                var div = document.createElement('div');
                div.className = 'search-dropdown-item-task';
                div.innerHTML = makeIdBadge(c.id) + escHtml(c.name) + (c.phone ? ' <span class="text-muted small">— ' + escHtml(c.phone) + '</span>' : '');
                div.dataset.id = c.id;
                div.dataset.name = c.name;
                div.dataset.phone = (c.phone || '').toString();
                div.dataset.address = (c.address || '').toString();
                div.dataset.tgGovernorate = (c.tg_governorate || '').toString();
                div.dataset.tgGovId = (c.tg_gov_id || '').toString();
                div.dataset.tgCity = (c.tg_city || '').toString();
                div.dataset.tgCityId = (c.tg_city_id || '').toString();
                div.addEventListener('click', function() {
                    if (hiddenIdEl) {
                        hiddenIdEl.value = this.dataset.id;
                        hiddenIdEl.dispatchEvent(new CustomEvent('edit-customer-selected', { bubbles: true }));
                    }
                    inputEl.value = this.dataset.name;
                    submitName.value = this.dataset.name || '';
                    if (this.dataset.phone) submitPhone.value = this.dataset.phone;
                    dropEl.classList.add('d-none');
                    // ملء بيانات التليجراف تلقائياً إذا كان نوع الأوردر تليجراف
                    var editTypeEl = document.getElementById('editTaskType');
                    var isTgEdit = editTypeEl ? editTypeEl.value === 'telegraph' : false;
                    if (isTgEdit) {
                        var eAddrEl = document.getElementById('editOrderTitle');
                        var eGovSearchEl = document.getElementById('editGovSearch');
                        var eGovEl = document.getElementById('editGov');
                        var eGovIdEl = document.getElementById('editGovId');
                        var eCitySearchEl = document.getElementById('editCitySearch');
                        var eCityEl = document.getElementById('editCity');
                        var eCityIdEl = document.getElementById('editCityId');
                        if (eAddrEl && this.dataset.address) eAddrEl.value = this.dataset.address;
                        if (eGovSearchEl && this.dataset.tgGovernorate) eGovSearchEl.value = this.dataset.tgGovernorate;
                        if (eGovEl && this.dataset.tgGovernorate) eGovEl.value = this.dataset.tgGovernorate;
                        if (eGovIdEl && this.dataset.tgGovId) eGovIdEl.value = this.dataset.tgGovId;
                        if (eCitySearchEl && this.dataset.tgCity) eCitySearchEl.value = this.dataset.tgCity;
                        if (eCityEl && this.dataset.tgCity) eCityEl.value = this.dataset.tgCity;
                        if (eCityIdEl && this.dataset.tgCityId) eCityIdEl.value = this.dataset.tgCityId;
                        // احتساب تكلفة الشحن
                        if (this.dataset.tgGovId && this.dataset.tgCityId) {
                            // الـ IDs محفوظة → استدعاء مباشر
                            if (typeof window.fetchEditDeliveryCost === 'function') window.fetchEditDeliveryCost();
                        } else if (this.dataset.tgGovernorate && typeof window.setTgAutoFillIds === 'function') {
                            // الـ IDs غير محفوظة → بحث بالاسم وتشغيل السلسلة (يستدعي fetchEditDeliveryCost تلقائياً)
                            window.setTgAutoFillIds(this.dataset.tgGovernorate, 'editGovId', 'editCitySearch', this.dataset.tgCity || '');
                        }
                    }
                });
                dropEl.appendChild(div);
            });
            positionEditDropdown(inputEl, dropEl);
            dropEl.classList.remove('d-none');
        }
        function initEditSearch(inputEl, hiddenIdEl, dropEl, list, getLabel, matcher) {
            if (!inputEl || !dropEl) return;
            inputEl.addEventListener('input', function() { if (hiddenIdEl) hiddenIdEl.value = ''; showEditDrop(inputEl, hiddenIdEl, dropEl, list, getLabel, matcher); });
            inputEl.addEventListener('focus', function() { showEditDrop(inputEl, hiddenIdEl, dropEl, list, getLabel, matcher); });
            window.addEventListener('scroll', function() { if (!dropEl.classList.contains('d-none')) positionEditDropdown(inputEl, dropEl); }, true);
            window.addEventListener('resize', function() { if (!dropEl.classList.contains('d-none')) positionEditDropdown(inputEl, dropEl); });
        }
        initEditSearch(localSearch, localId, localDrop, localCustomers, function(c) { return c.id + ' - ' + c.name + (c.phone ? ' — ' + c.phone : ''); }, matchLocal);
        initEditSearch(repSearch, null, repDrop, repCustomers, function(c) { return c.id + ' - ' + (c.rep_name ? c.name + ' (' + c.rep_name + ')' : c.name); }, matchRep);

        // تفعيل زر السجل عند اختيار عميل مسجل
        if (localId) {
            localId.addEventListener('edit-customer-selected', function() {
                if (histBtn) { histBtn.disabled = false; histBtn.removeAttribute('title'); }
            });
        }

        // زر عرض السجل
        if (histBtn) {
            histBtn.addEventListener('click', function() {
                var cid = localId ? (localId.value || '').trim() : '';
                var cname = localSearch ? (localSearch.value || 'العميل').trim() : 'العميل';
                if (!cid) return;
                if (histCard && histCard.style.display !== 'none') { histCard.style.display = 'none'; return; }
                loadEditCustomerHistory(cid, cname);
            });
        }
        if (histClose) {
            histClose.addEventListener('click', function() { if (histCard) histCard.style.display = 'none'; });
        }

        // إغلاق القوائم عند الضغط خارجها
        document.addEventListener('click', function(e) {
            if (!e.target.closest('#editTaskForm .search-wrap')) {
                if (localDrop) localDrop.classList.add('d-none');
                if (repDrop)   repDrop.classList.add('d-none');
            }
        });

        // عند الإرسال: ضمان تعبئة customer_name
        var form = document.getElementById('editTaskForm');
        if (form) {
            form.addEventListener('submit', function() {
                var v = document.querySelector('input[name="customer_type_radio_edit"]:checked');
                var val = v ? v.value : 'local';
                var activeSearch = (val === 'local') ? localSearch : repSearch;
                if (activeSearch && activeSearch.value.trim()) submitName.value = activeSearch.value.trim();
            });
        }
    })();
});

window.openOrderReceiptModal = function(orderId) {
    var modalEl = document.getElementById('orderReceiptModal');
    var loadingEl = document.getElementById('orderReceiptLoading');
    var bodyEl = document.getElementById('orderReceiptBody');
    if (!modalEl || !loadingEl || !bodyEl) return;
    loadingEl.style.display = 'block';
    bodyEl.style.display = 'none';
    bodyEl.innerHTML = '';
    var modalInstance = bootstrap.Modal.getOrCreateInstance(modalEl);
    modalInstance.show();
    var params = new URLSearchParams(window.location.search);
    params.set('get_order_receipt', '1');
    params.set('order_id', String(orderId));
    fetch('?' + params.toString())
        .then(function(r) { return r.json(); })
        .then(function(data) {
            loadingEl.style.display = 'none';
            if (data.success && data.order) {
                var o = data.order;
                var items = data.items || [];
                var total = typeof o.total_amount === 'number' ? o.total_amount : parseFloat(o.total_amount) || 0;
                var rows = items.map(function(it) {
                    var qty = typeof it.quantity === 'number' ? it.quantity : parseFloat(it.quantity) || 0;
                    var un = (it.unit || 'قطعة').trim();
                    var pName = it.product_name || '-';
                    var pDetail = getProductDetail(it.product_name);
                    var pCell = pDetail && (pDetail.code || pDetail.id) ? makeIdBadge(pDetail.code || pDetail.id) + escHtml(pName) : escHtml(pName);
                    return '<tr><td>' + pCell + '</td><td class="text-end">' + qty + ' ' + un + '</td></tr>';
                }).join('');
                bodyEl.innerHTML =
                    '<div class="border rounded p-3 mb-3 bg-light"><h6 class="mb-2">بيانات الطلب</h6>' +
                    '<p class="mb-1"><strong>رقم الأوردر:</strong> ' + (o.order_number || '-') + '</p>' +
                    '<p class="mb-1"><strong>العميل:</strong> ' + (o.customer_name || '-') + '</p>' +
                    (o.customer_phone ? '<p class="mb-1"><strong>الهاتف:</strong> ' + o.customer_phone + '</p>' : '') +
                    (o.customer_address ? '<p class="mb-1"><strong>العنوان:</strong> ' + o.customer_address + '</p>' : '') +
                    '<p class="mb-1"><strong>تاريخ الطلب:</strong> ' + (o.order_date || '-') + '</p>' +
                    (o.delivery_date ? '<p class="mb-1"><strong>تاريخ التسليم:</strong> ' + o.delivery_date + '</p>' : '') +
                    '</div>' +
                    '<h6 class="mb-2">تفاصيل المنتجات</h6>' +
                    '<table class="table table-sm table-bordered"><thead><tr><th>المنتج</th><th class="text-end">الكمية</th></tr></thead><tbody>' + rows + '</tbody><tfoot><tr><td class="text-end fw-bold" colspan="2">الإجمالي: ' + total.toFixed(2) + ' ' + getCurrencySymbol() + '</td></tr></tfoot></table>' +
                    (o.notes ? '<p class="mt-2 text-muted small mb-0"><strong>ملاحظات:</strong> ' + o.notes + '</p>' : '');
                bodyEl.style.display = 'block';
            } else {
                bodyEl.innerHTML = '<p class="text-muted mb-0">الطلب غير موجود أو لا يمكن تحميل التفاصيل.</p>';
                bodyEl.style.display = 'block';
            }
        })
        .catch(function() {
            loadingEl.style.display = 'none';
            bodyEl.innerHTML = '<p class="text-danger mb-0">حدث خطأ أثناء تحميل تفاصيل الأوردر.</p>';
            bodyEl.style.display = 'block';
        });
};

function isMobileViewport() {
    return window.matchMedia('(max-width: 767.98px)').matches;
}

window.openReceiptIframeModal = function(url) {
    var el = document.getElementById('receiptIframeModal');
    var iframe = document.getElementById('receiptIframeEl');
    if (!el || !iframe) return;
    // كاش: لا نُعيد تحميل iframe إذا كان يعرض نفس الإيصال
    var currentUrl = iframe.getAttribute('data-current-url') || '';
    if (currentUrl !== url) {
        iframe.setAttribute('data-current-url', url);
        iframe.src = url;
    }
    if (isMobileViewport()) {
        // الهاتف: بطاقة بدون Bootstrap modal
        el.classList.remove('instant-close');
        el.classList.add('card-mode');
        requestAnimationFrame(function() {
            el.classList.add('card-show');
        });
        document.body.style.overflow = 'hidden';
    } else {
        // سطح المكتب: Bootstrap modal عادي
        el.classList.remove('instant-close');
        bootstrap.Modal.getOrCreateInstance(el, { backdrop: false, keyboard: true }).show();
    }
};

window.closeReceiptIframeModal = function() {
    var el = document.getElementById('receiptIframeModal');
    if (!el) return;
    if (el.classList.contains('card-mode')) {
        // الهاتف: toggle class فقط، بلا Bootstrap
        el.classList.remove('card-show');
        document.body.style.overflow = '';
        setTimeout(function() { el.classList.remove('card-mode'); }, 200);
    } else {
        el.classList.add('instant-close');
        var inst = bootstrap.Modal.getInstance(el);
        if (inst) inst.hide();
    }
};

window.openTaskReceiptModal = function(taskId) {
    var modalEl = document.getElementById('taskReceiptModal');
    var titleEl = document.getElementById('taskReceiptModalLabel');
    var loadingEl = document.getElementById('taskReceiptLoading');
    var bodyEl = document.getElementById('taskReceiptBody');
    if (!modalEl || !titleEl || !loadingEl || !bodyEl) return;
    loadingEl.style.display = 'block';
    bodyEl.style.display = 'none';
    bodyEl.innerHTML = '';
    var modalInstance = bootstrap.Modal.getOrCreateInstance(modalEl);
    modalInstance.show();
    var params = new URLSearchParams(window.location.search);
    params.set('action', 'get_task_receipt');
    params.set('task_id', String(taskId));
    fetch('?' + params.toString())
        .then(function(r) {
            var ct = (r.headers.get('Content-Type') || '');
            if (!r.ok || ct.indexOf('application/json') === -1) {
                throw new Error('غير JSON');
            }
            return r.json();
        })
        .then(function(data) {
            loadingEl.style.display = 'none';
            if (data.success && Array.isArray(data.items)) {
                var title = data.order_number ? ('إيصال أوردر #' + data.order_number) : ('مهمة #' + data.task_id);
                titleEl.innerHTML = '<i class="bi bi-eye me-1"></i>' + title;
                var rows = data.items.map(function(it) {
                    var qty = typeof it.quantity === 'number' ? it.quantity : parseFloat(it.quantity) || 0;
                    var un = (it.unit || 'قطعة').trim();
                    var pName = it.product_name || '-';
                    var pDetail = getProductDetail(it.product_name);
                    var pCell = pDetail && (pDetail.code || pDetail.id) ? makeIdBadge(pDetail.code || pDetail.id) + escHtml(pName) : escHtml(pName);
                    return '<tr><td>' + pCell + '</td><td class="text-end">' + qty + ' ' + un + '</td></tr>';
                }).join('');
                bodyEl.innerHTML = '<table class="table table-sm table-bordered mb-0"><thead class="table-light"><tr><th>المنتج</th><th class="text-end">الكمية</th></tr></thead><tbody>' + rows + '</tbody></table>';
                bodyEl.style.display = 'block';
            } else {
                bodyEl.innerHTML = '<p class="text-muted small mb-0">لا توجد بيانات للعرض.</p>';
                bodyEl.style.display = 'block';
            }
        })
        .catch(function() {
            loadingEl.style.display = 'none';
            bodyEl.innerHTML = '<p class="text-danger small mb-0">حدث خطأ أثناء التحميل.</p>';
            bodyEl.style.display = 'block';
        });
};

console.log('[DEBUG-SCRIPT] about to register DOMContentLoaded');
document.addEventListener('DOMContentLoaded', function () {
    console.log('[DEBUG-SCRIPT] DOMContentLoaded FIRED');
    const taskTypeSelect = document.getElementById('taskTypeSelect');
    const titleInput = document.querySelector('input[name="title"]');
    const productWrapper = document.getElementById('productFieldWrapper');
    const quantityWrapper = document.getElementById('quantityFieldWrapper');
    const productNameInput = document.getElementById('productNameInput');
    const quantityInput = document.getElementById('productQuantityInput');
    const templateSuggestions = document.getElementById('templateSuggestions');

    // خانة العميل: عميل محلي / عميل مندوب — اسم من البحث (أو المُدخل) ورقم العميل ظاهر دائماً ويُملأ تلقائياً عند اختيار عميل مسجل
    (function initCustomerCardTask() {
        var localCustomers = (typeof __localCustomersForTask !== 'undefined' && Array.isArray(__localCustomersForTask)) ? __localCustomersForTask : [];
        var repCustomers = (typeof __repCustomersForTask !== 'undefined' && Array.isArray(__repCustomersForTask)) ? __repCustomersForTask : [];
        var submitName = document.getElementById('submit_customer_name');
        var submitPhone = document.getElementById('submit_customer_phone');
        var localSearch = document.getElementById('local_customer_search_task');
        var localId = document.getElementById('local_customer_id_task');
        var localDrop = document.getElementById('local_customer_dropdown_task');
        var repSearch = document.getElementById('rep_customer_search_task');
        var repId = document.getElementById('rep_customer_id_task');
        var repDrop = document.getElementById('rep_customer_dropdown_task');
        console.log('[DEBUG-CUSTOMER] initCustomerCardTask called');
        console.log('[DEBUG-CUSTOMER] localCustomers count:', localCustomers.length);
        console.log('[DEBUG-CUSTOMER] repCustomers count:', repCustomers.length);
        console.log('[DEBUG-CUSTOMER] submitName:', submitName, 'submitPhone:', submitPhone);
        console.log('[DEBUG-CUSTOMER] localSearch:', localSearch, 'localDrop:', localDrop);
        if (!submitName || !submitPhone) { console.log('[DEBUG-CUSTOMER] EARLY RETURN: submitName or submitPhone missing!'); return; }

        // نفس منطق matchSearch في صفحة الأسعار المخصصة: عند الفراغ نعرض الكل، وإلا بحث بسيط (نص يحتوي على الاستعلام)
        function matchSearch(text, q) {
            if (!q || !text) return true;
            var t = (text + '').toLowerCase();
            var k = (q + '').trim().toLowerCase();
            return t.indexOf(k) !== -1;
        }
        // للعميل المحلي: نفس سلوك custom_prices — البحث في الاسم (والهاتف اختياري)
        function matchLocalCustomer(c, query) {
            var q = (query + '').trim();
            if (!q) return true;
            var name = (c.name || '') + '';
            var extra = (c.phones && c.phones.length) ? c.phones.join(' ') : ((c.phone || '') + '');
            var text = (name + ' ' + extra).trim();
            return matchSearch(text, q);
        }
        // لعميل المندوب: البحث في الاسم + اسم المندوب + الهاتف
        function matchRepCustomer(c, query) {
            var q = (query + '').trim();
            if (!q) return true;
            var text = (c.name || '') + ' ' + (c.rep_name || '') + ' ' + (c.phone || '');
            return matchSearch(text, q);
        }

        function setCustomerBlocks() {
            var v = document.querySelector('input[name="customer_type_radio_task"]:checked');
            var val = v ? v.value : 'local';
            document.getElementById('customer_select_local_task').classList.toggle('d-none', val !== 'local');
            document.getElementById('customer_select_rep_task').classList.toggle('d-none', val !== 'rep');
            if (val !== 'local') {
                if (localSearch) localSearch.value = '';
                if (localId) localId.value = '';
                if (localDrop) localDrop.classList.add('d-none');
            }
            if (val !== 'rep') {
                if (repSearch) repSearch.value = '';
                if (repId) repId.value = '';
                if (repDrop) repDrop.classList.add('d-none');
            }
            if (typeof syncCreateTelegraphRequiredState === 'function') syncCreateTelegraphRequiredState();
        }

        document.querySelectorAll('input[name="customer_type_radio_task"]').forEach(function(r) {
            r.addEventListener('change', setCustomerBlocks);
        });
        setCustomerBlocks();

        // إخفاء أي بقايا لخانة العميل اليدوي (إن وُجدت من كاش قديم)
        (function hideManualCustomerBlock() {
            var manualBlock = document.getElementById('customer_manual_block_task');
            if (manualBlock) { manualBlock.style.display = 'none'; manualBlock.remove(); }
            document.querySelectorAll('input[name="customer_type_radio_task"][value="manual"]').forEach(function(r) {
                var wrap = r.closest('.form-check');
                if (wrap) wrap.style.display = 'none';
            });
        })();

        function positionDropdown(inputEl, dropEl) {
            var rect = inputEl.getBoundingClientRect();
            dropEl.style.position = 'fixed';
            dropEl.style.top = (rect.bottom + 2) + 'px';
            dropEl.style.left = rect.left + 'px';
            dropEl.style.width = rect.width + 'px';
            dropEl.style.right = 'auto';
            dropEl.style.zIndex = '9999';
        }
        function showCustomerDropdown(inputEl, hiddenIdEl, dropEl, list, getLabel, matcher, onSelect) {
            if (!inputEl || !dropEl) { console.log('[DEBUG-CUSTOMER] showCustomerDropdown: inputEl or dropEl missing'); return; }
            var q = (inputEl.value || '').trim();
            var filterFn = (typeof matcher === 'function') ? function(c) { return matcher(c, q); } : function(c) { return matchSearch(getLabel(c), q); };
            var filtered = list.filter(filterFn);
            console.log('[DEBUG-CUSTOMER] showCustomerDropdown: query="' + q + '", list.length=' + list.length + ', filtered.length=' + filtered.length);
            dropEl.innerHTML = '';
            if (filtered.length === 0) {
                dropEl.classList.add('d-none');
                return;
            }
            filtered.forEach(function(c) {
                var div = document.createElement('div');
                div.className = 'search-dropdown-item-task';
                div.innerHTML = makeIdBadge(c.id) + escHtml(c.name) + (c.phone ? ' <span class="text-muted small">— ' + escHtml(c.phone) + '</span>' : '') + (c.rep_name ? ' <span class="text-muted small">(' + escHtml(c.rep_name) + ')</span>' : '');
                div.dataset.id = c.id;
                div.dataset.name = c.name;
                div.dataset.phone = (c.phone || '').toString();
                div.dataset.address = (c.address || '').toString();
                div.dataset.tgGovernorate = (c.tg_governorate || '').toString();
                div.dataset.tgGovId = (c.tg_gov_id || '').toString();
                div.dataset.tgCity = (c.tg_city || '').toString();
                div.dataset.tgCityId = (c.tg_city_id || '').toString();
                div.addEventListener('click', function() {
                    if (hiddenIdEl) {
                        hiddenIdEl.value = this.dataset.id;
                        hiddenIdEl.dispatchEvent(new CustomEvent('customer-selected', { bubbles: true }));
                    }
                    inputEl.value = this.dataset.name;
                    submitName.value = this.dataset.name || '';
                    if (this.dataset.phone) submitPhone.value = this.dataset.phone;
                    dropEl.classList.add('d-none');
                    // تحديث حالة زر الإرسال بدون مسح ID العميل أو إعادة فتح القائمة
                    updateCreateSubmitBtnState();
                    // ملء بيانات التليجراف تلقائياً إذا كان نوع الأوردر تليجراف
                    var typeEl = document.getElementById('taskTypeSelect');
                    if (typeEl && typeEl.value === 'telegraph') {
                        var addrEl = document.getElementById('createOrderTitle');
                        var govSearchEl = document.getElementById('createGovSearch');
                        var govEl = document.getElementById('createGov');
                        var govIdEl = document.getElementById('createGovId');
                        var citySearchEl = document.getElementById('createCitySearch');
                        var cityEl = document.getElementById('createCity');
                        var cityIdEl = document.getElementById('createCityId');
                        if (addrEl && this.dataset.address) addrEl.value = this.dataset.address;
                        if (govSearchEl && this.dataset.tgGovernorate) govSearchEl.value = this.dataset.tgGovernorate;
                        if (govEl && this.dataset.tgGovernorate) govEl.value = this.dataset.tgGovernorate;
                        if (govIdEl && this.dataset.tgGovId) govIdEl.value = this.dataset.tgGovId;
                        if (citySearchEl && this.dataset.tgCity) citySearchEl.value = this.dataset.tgCity;
                        if (cityEl && this.dataset.tgCity) cityEl.value = this.dataset.tgCity;
                        if (cityIdEl && this.dataset.tgCityId) cityIdEl.value = this.dataset.tgCityId;
                        // احتساب تكلفة الشحن
                        if (this.dataset.tgGovId && this.dataset.tgCityId) {
                            // الـ IDs محفوظة → استدعاء مباشر
                            if (typeof fetchCreateDeliveryCost === 'function') fetchCreateDeliveryCost();
                        } else if (this.dataset.tgGovernorate && typeof window.setTgAutoFillIds === 'function') {
                            // الـ IDs غير محفوظة → بحث بالاسم وتشغيل السلسلة (يستدعي fetchCreateDeliveryCost تلقائياً)
                            window.setTgAutoFillIds(this.dataset.tgGovernorate, 'createGovId', 'createCitySearch', this.dataset.tgCity || '');
                        }
                    }
                    if (onSelect) onSelect(c);
                });
                dropEl.appendChild(div);
            });
            positionDropdown(inputEl, dropEl);
            dropEl.classList.remove('d-none');
        }

        function initCustomerSearch(inputEl, hiddenIdEl, dropEl, list, getLabel, matcher) {
            if (!inputEl || !dropEl) return;
            function show() { showCustomerDropdown(inputEl, hiddenIdEl, dropEl, list, getLabel, matcher); }
            inputEl.addEventListener('input', function() {
                if (hiddenIdEl) hiddenIdEl.value = '';
                show();
            });
            inputEl.addEventListener('focus', function() {
                show();
            });
            // تحديث موضع الـ dropdown عند التمرير أو تغيير حجم النافذة
            window.addEventListener('scroll', function() { if (!dropEl.classList.contains('d-none')) positionDropdown(inputEl, dropEl); }, true);
            window.addEventListener('resize', function() { if (!dropEl.classList.contains('d-none')) positionDropdown(inputEl, dropEl); });
        }

        initCustomerSearch(localSearch, localId, localDrop, localCustomers, function(c) { return c.id + ' - ' + c.name + (c.phone ? ' — ' + c.phone : ''); }, matchLocalCustomer);
        initCustomerSearch(repSearch, repId, repDrop, repCustomers, function(c) { return c.id + ' - ' + (c.rep_name ? c.name + ' (' + c.rep_name + ')' : c.name); }, matchRepCustomer);

        var submitBtn = document.getElementById('createTaskSubmitBtn');
        function updateCreateSubmitBtnState() {
            if (!submitBtn) return;
            var v = document.querySelector('input[name="customer_type_radio_task"]:checked');
            var val = v ? v.value : 'local';
            var activeSearch = (val === 'local') ? localSearch : repSearch;
            submitBtn.disabled = !activeSearch || !activeSearch.value.trim();
        }
        if (localSearch) localSearch.addEventListener('input', updateCreateSubmitBtnState);
        if (repSearch) repSearch.addEventListener('input', updateCreateSubmitBtnState);
        document.querySelectorAll('input[name="customer_type_radio_task"]').forEach(function(r) {
            r.addEventListener('change', updateCreateSubmitBtnState);
        });

        document.addEventListener('click', function(e) {
            if (!e.target.closest('.search-wrap')) {
                document.querySelectorAll('.search-dropdown-task').forEach(function(d) { d.classList.add('d-none'); });
            }
        });

        // عند الإرسال: التحقق من اسم العميل (الحقل الظاهر فقط) ثم أخذ القيمة من حقل البحث النشط
        var form = submitName && submitName.closest('form');
        if (form) {
            form.addEventListener('submit', function(e) {
                var v = document.querySelector('input[name="customer_type_radio_task"]:checked');
                var val = v ? v.value : 'local';
                var activeSearch = (val === 'local') ? localSearch : repSearch;
                var activeLabel = (val === 'local') ? 'اسم العميل (عميل محلي)' : 'اسم العميل (عميل مندوب)';
                if (activeSearch && !activeSearch.value.trim()) {
                    e.preventDefault();
                    alert('يرجى إدخال ' + activeLabel + ' قبل الإرسال.');
                    activeSearch.focus();
                    return;
                }
                if (val === 'local' && localSearch) {
                    submitName.value = localSearch.value.trim();
                } else if (val === 'rep' && repSearch) {
                    submitName.value = repSearch.value.trim();
                }
            });
        }
    })();

    function updateTaskTypeUI() {
        if (!titleInput) {
            // continue to toggle other fields even إن لم يوجد العنوان
        }
        const isProduction = taskTypeSelect && taskTypeSelect.value === 'production';
        if (titleInput) {
            titleInput.placeholder = isProduction
                ? '.'
                : 'مثال: تنظيف خط الإنتاج';
        }
    }

    if (taskTypeSelect) {
        taskTypeSelect.addEventListener('change', updateTaskTypeUI);
    }
    updateTaskTypeUI();

    function updateCreateTelegraphPiecesCount(forceAutoFill) {
        var piecesInput = document.getElementById('createTgPiecesCount');
        var containerEl = document.getElementById('productsContainer');
        if (!piecesInput || !containerEl) return;

        var totalPieces = 0;
        containerEl.querySelectorAll('.product-quantity-input').forEach(function(inp) {
            var val = parseFloat(inp.value || '0');
            if (!isNaN(val) && val > 0) totalPieces += val;
        });

        var autoPieces = Math.max(1, Math.ceil(totalPieces));
        piecesInput.dataset.autoValue = String(autoPieces);
        var isManualOverride = piecesInput.dataset.manualOverride === 'true';

        if (forceAutoFill || !isManualOverride || !piecesInput.value) {
            piecesInput.value = autoPieces;
            if (forceAutoFill) piecesInput.dataset.manualOverride = 'false';
        }
    }

    function setCreateTelegraphPiecesValue(value) {
        var piecesInput = document.getElementById('createTgPiecesCount');
        if (!piecesInput) return;
        updateCreateTelegraphPiecesCount(true);
        if (value !== undefined && value !== null && value !== '') {
            piecesInput.value = value;
            var numericValue = parseInt(value, 10);
            var autoVal = parseInt(piecesInput.dataset.autoValue || '0', 10);
            piecesInput.dataset.manualOverride = (!isNaN(numericValue) && numericValue > 0 && numericValue !== autoVal) ? 'true' : 'false';
        } else {
            piecesInput.dataset.manualOverride = 'false';
            updateCreateTelegraphPiecesCount(true);
        }
    }

    var createTgPiecesInput = document.getElementById('createTgPiecesCount');
    if (createTgPiecesInput && createTgPiecesInput.dataset.boundAutoCount !== '1') {
        createTgPiecesInput.addEventListener('input', function() {
            var raw = (this.value || '').trim();
            if (raw === '') {
                this.dataset.manualOverride = 'false';
                return;
            }
            var current = parseInt(raw, 10);
            var autoVal = parseInt(this.dataset.autoValue || '0', 10);
            this.dataset.manualOverride = (!isNaN(current) && current > 0 && current !== autoVal) ? 'true' : 'false';
        });
        createTgPiecesInput.dataset.boundAutoCount = '1';
    }

    // جعل حقول التليجراف إجبارية في نموذج الإنشاء
    function syncCreateTelegraphRequiredState() {
        var isTg = taskTypeSelect && taskTypeSelect.value === 'telegraph';
        var activeType = document.querySelector('input[name="customer_type_radio_task"]:checked');
        var customerMode = activeType ? activeType.value : 'local';
        var localSearchEl = document.getElementById('local_customer_search_task');
        var repSearchEl = document.getElementById('rep_customer_search_task');
        var phoneEl = document.getElementById('submit_customer_phone');
        var orderTitleEl = document.getElementById('createOrderTitle');
        var govSearchEl = document.getElementById('createGovSearch');
        var citySearchEl = document.getElementById('createCitySearch');
        var weightEl = document.getElementById('createTgWeight');
        var piecesInput = document.getElementById('createTgPiecesCount');
        var parcelDescEl = document.getElementById('createTgParcelDesc');

        if (localSearchEl) localSearchEl.required = !!isTg && customerMode === 'local';
        if (repSearchEl) repSearchEl.required = !!isTg && customerMode === 'rep';

        [phoneEl, orderTitleEl, govSearchEl, citySearchEl, weightEl, piecesInput, parcelDescEl].forEach(function(el) {
            if (!el) return;
            el.required = !!isTg;
            if (!isTg) el.setCustomValidity('');
        });

        if (weightEl) {
            weightEl.min = isTg ? '0.01' : '0';
        }
        if (piecesInput) {
            piecesInput.min = isTg ? '1' : '0';
        }
        if (isTg) {
            updateCreateTelegraphPiecesCount();
        }
    }

    // إظهار/إخفاء حقلي المحافظة والمدينة عند اختيار تليجراف (نموذج الإنشاء)
    function toggleCreateTgFields() {
        var isTg = taskTypeSelect && taskTypeSelect.value === 'telegraph';
        ['createGovWrap', 'createCityWrap', 'createTgParcelWrap'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) { el.classList.toggle('d-none', !isTg); }
        });
        syncCreateTelegraphRequiredState();
        // إخفاء خانة الشحن اليدوية وإظهار تكلفة التوصيل عند اختيار تليجراف
        var shippingWrap = document.getElementById('createShippingFeesWrap');
        var shippingCol  = document.getElementById('createTaskShippingCol');
        var deliveryCol  = document.getElementById('createTaskDeliveryCostCol');
        if (shippingWrap) shippingWrap.classList.toggle('d-none', isTg);
        if (shippingCol)  shippingCol.classList.toggle('d-none', isTg);
        if (deliveryCol)  deliveryCol.classList.toggle('d-none', !isTg);
        var returnCol = document.getElementById('createTaskReturnCostCol');
        if (returnCol) returnCol.classList.toggle('d-none', !isTg);
        if (isTg) {
            // تصفير قيمة الشحن اليدوي عند التبديل لتليجراف
            var shippingInput = document.getElementById('createTaskShippingFees');
            if (shippingInput) { shippingInput.value = '0'; }
            if (typeof window._updateCreateTaskSummary === 'function') window._updateCreateTaskSummary();
            if (typeof window.fetchCreateDeliveryCost === 'function') window.fetchCreateDeliveryCost();
        } else {
            window._tgDeliveryCost = 0;
            if (typeof window._updateCreateTaskSummary === 'function') window._updateCreateTaskSummary();
        }
    }
    if (taskTypeSelect) {
        taskTypeSelect.addEventListener('change', toggleCreateTgFields);
    }
    toggleCreateTgFields();

    // إظهار/إخفاء حقلي المحافظة والمدينة (نموذج التعديل)
    var editTaskTypeEl = document.getElementById('editTaskType');
    window.toggleEditTgFields = toggleEditTgFields;
    function toggleEditTgFields() {
        var isTg = editTaskTypeEl && editTaskTypeEl.value === 'telegraph';
        ['editGovWrap', 'editCityWrap', 'editTgParcelWrap'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) { el.classList.toggle('d-none', !isTg); }
        });
        // إخفاء خانة الشحن اليدوية وإظهار تكلفة التوصيل عند اختيار تليجراف
        var shippingWrap = document.getElementById('editShippingFeesWrap');
        var shippingCol  = document.getElementById('editTaskShippingCol');
        var deliveryCol  = document.getElementById('editTaskDeliveryCostCol');
        if (shippingWrap) shippingWrap.classList.toggle('d-none', isTg);
        if (shippingCol)  shippingCol.classList.toggle('d-none', isTg);
        if (deliveryCol)  deliveryCol.classList.toggle('d-none', !isTg);
        var returnCol = document.getElementById('editTaskReturnCostCol');
        if (returnCol) returnCol.classList.toggle('d-none', !isTg);
        if (isTg) {
            // تصفير قيمة الشحن اليدوي عند التبديل لتليجراف
            var shippingInput = document.getElementById('editTaskShippingFees');
            if (shippingInput) { shippingInput.value = '0'; }
            updateEditTaskSummary();
            if (typeof window.fetchEditDeliveryCost === 'function') window.fetchEditDeliveryCost();
        } else {
            window._tgEditDeliveryCost = 0;
            updateEditTaskSummary();
        }
    }
    if (editTaskTypeEl) {
        editTaskTypeEl.addEventListener('change', toggleEditTgFields);
    }

    // ===== نظام autocomplete للمحافظات والمدن + جلب المدن ديناميكياً =====
    (function() {
        var GOV_LIST = <?php $govJson = json_decode(file_get_contents(__DIR__ . '/../../gov.json'), true); echo json_encode($govJson['data']['listZonesDropdown'] ?? []); ?>;

        // قوائم المدن المجلوبة لكل حقل
        var citiesCache = { createCitySearch: [], editCitySearch: [] };

        var style = document.createElement('style');
        style.textContent = [
            '.almostafa-id-badge{display:inline-block;padding:1px 6px;border-radius:4px;background:#e0f2fe;color:#0369a1;border:1px solid #7dd3fc;font-size:0.72em;font-weight:700;font-family:monospace;vertical-align:middle;white-space:nowrap;}',
            '.gov-dropdown,.city-dropdown{position:absolute;top:100%;right:0;left:0;z-index:1055;background:#fff;border:1px solid #ced4da;border-radius:0 0 .375rem .375rem;max-height:220px;overflow-y:auto;box-shadow:0 4px 12px rgba(0,0,0,.12);}',
            '.gov-dropdown .gov-item,.city-dropdown .city-item{padding:.45rem .75rem;cursor:pointer;font-size:.9rem;}',
            '.gov-dropdown .gov-item:hover,.gov-dropdown .gov-item.active,.city-dropdown .city-item:hover,.city-dropdown .city-item.active{background:#e9f0ff;color:#0d6efd;}',
            '.gov-dropdown .gov-no-result,.city-dropdown .city-no-result{padding:.45rem .75rem;font-size:.85rem;color:#888;}',
            '.product-template-section-header{padding:.35rem .75rem;font-size:.78rem;font-weight:700;color:#6c757d;background:#f8f9fa;border-bottom:1px solid #e9ecef;border-top:1px solid #e9ecef;}'
        ].join('');
        document.head.appendChild(style);

        // بناء مسار API بشكل ديناميكي
        function getTgZonesApiPath() {
            var currentPath = window.location.pathname || '/';
            var pathParts = currentPath.split('/').filter(Boolean);
            var stopSegments = ['dashboard', 'modules', 'api', 'assets', 'includes'];
            var baseParts = [];
            for (var i = 0; i < pathParts.length; i++) {
                var part = pathParts[i];
                if (stopSegments.indexOf(part) !== -1 || part.indexOf('.php') !== -1) break;
                baseParts.push(part);
            }
            var basePath = baseParts.length ? '/' + baseParts.join('/') : '';
            return (basePath + '/api/tg_zones.php').replace(/\/+/g, '/');
        }

        // دالة عامة للـ autocomplete (محافظات أو مدن)
        function initAutocomplete(opts) {
            // opts: { searchId, hiddenId, dropdownClass, itemClass, noResultClass, getList, onSelect, onClear }
            var searchEl = document.getElementById(opts.searchId);
            var hiddenEl = document.getElementById(opts.hiddenId);
            if (!searchEl || !hiddenEl) return;

            var wrap = searchEl.closest('.' + (opts.wrapClass || 'gov-autocomplete-wrap'));
            var dropdown = wrap.querySelector('.' + opts.dropdownClass);
            var activeIdx = -1;

            function renderDropdown(filtered) {
                dropdown.innerHTML = '';
                activeIdx = -1;
                if (!filtered.length) {
                    dropdown.innerHTML = '<div class="' + opts.noResultClass + '">لا توجد نتائج</div>';
                    dropdown.classList.remove('d-none');
                    return;
                }
                filtered.forEach(function(item) {
                    var el = document.createElement('div');
                    el.className = opts.itemClass;
                    var prefix = item.code || item.id || '';
                    el.innerHTML = (prefix ? makeIdBadge(prefix) : '') + escHtml(item.name);
                    el.dataset.code = item.code || '';
                    el.addEventListener('mousedown', function(e) {
                        e.preventDefault();
                        selectItem(item.name, item.code);
                    });
                    dropdown.appendChild(el);
                });
                dropdown.classList.remove('d-none');
            }

            function selectItem(name, code) {
                searchEl.value = name;
                hiddenEl.value = name;
                dropdown.classList.add('d-none');
                searchEl.classList.remove('is-invalid');
                try { hiddenEl.dispatchEvent(new CustomEvent('ac-select', { detail: { name: name, code: code } })); } catch(e) {}
                if (opts.onSelect) opts.onSelect(name, code);
            }

            function closeDropdown() {
                dropdown.classList.add('d-none');
                if (!searchEl.value.trim()) {
                    hiddenEl.value = '';
                    if (opts.onClear) opts.onClear();
                }
                var typed = searchEl.value.trim();
                var list = opts.getList();
                var match = list.find(function(g) { return g.name === typed; });
                if (!match) searchEl.value = hiddenEl.value;
            }

            searchEl.addEventListener('input', function() {
                var q = this.value.trim();
                hiddenEl.value = '';
                if (opts.onClear) opts.onClear();
                if (!q) { dropdown.classList.add('d-none'); return; }
                var filtered = opts.getList().filter(function(g) { return g.name.includes(q); });
                renderDropdown(filtered);
            });

            searchEl.addEventListener('focus', function() {
                var q = this.value.trim();
                var list = opts.getList();
                if (q) {
                    var filtered = list.filter(function(g) { return g.name.includes(q); });
                    if (filtered.length) renderDropdown(filtered);
                } else {
                    renderDropdown(list);
                }
            });

            searchEl.addEventListener('keydown', function(e) {
                var items = dropdown.querySelectorAll('.' + opts.itemClass);
                if (!items.length) return;
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    activeIdx = Math.min(activeIdx + 1, items.length - 1);
                    items.forEach(function(el, i) { el.classList.toggle('active', i === activeIdx); });
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    activeIdx = Math.max(activeIdx - 1, 0);
                    items.forEach(function(el, i) { el.classList.toggle('active', i === activeIdx); });
                } else if (e.key === 'Enter') {
                    if (activeIdx >= 0 && items[activeIdx]) {
                        e.preventDefault();
                        selectItem(items[activeIdx].textContent, items[activeIdx].dataset.code);
                    }
                } else if (e.key === 'Escape') {
                    closeDropdown();
                }
            });

            searchEl.addEventListener('blur', function() {
                setTimeout(closeDropdown, 150);
            });

            // تحديث القائمة من الخارج بعد جلب بيانات جديدة
            return {
                setList: function(list, preSelectValue) {
                    opts._list = list;
                    if (preSelectValue) {
                        var match = list.find(function(c) { return c.name === preSelectValue; });
                        if (match) selectItem(match.name, match.code);
                    }
                },
                reset: function() {
                    searchEl.value = '';
                    hiddenEl.value = '';
                    opts._list = [];
                    dropdown.classList.add('d-none');
                    searchEl.classList.remove('is-invalid');
                }
            };
        }

        // جلب مدن المحافظة وتحديث الـ autocomplete المقابل
        function fetchCities(govCode, cityInstance, preSelectValue) {
            if (!govCode || !cityInstance) return;
            cityInstance.reset();
            var searchId = cityInstance._searchId;
            var searchEl = searchId ? document.getElementById(searchId) : null;
            if (searchEl) { searchEl.placeholder = 'جاري التحميل...'; searchEl.disabled = true; }

            fetch(getTgZonesApiPath() + '?parentId=' + encodeURIComponent(govCode))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    var zones = (data && data.data && data.data.listZonesDropdown) || [];
                    if (searchEl) { searchEl.placeholder = 'ابحث عن مدينة...'; searchEl.disabled = false; }
                    cityInstance.setList(zones, preSelectValue);
                })
                .catch(function() {
                    if (searchEl) { searchEl.placeholder = 'خطأ في التحميل'; searchEl.disabled = false; }
                });
        }

        // تهيئة autocomplete المحافظات
        function initGovAutocomplete(searchInputId, hiddenInputId, cityInstance) {
            return initAutocomplete({
                searchId: searchInputId,
                hiddenId: hiddenInputId,
                wrapClass: 'gov-autocomplete-wrap',
                dropdownClass: 'gov-dropdown',
                itemClass: 'gov-item',
                noResultClass: 'gov-no-result',
                getList: function() { return GOV_LIST; },
                onSelect: function(name, code) {
                    if (cityInstance && code) fetchCities(code, cityInstance);
                },
                onClear: function() {
                    if (cityInstance) cityInstance.reset();
                }
            });
        }

        // تهيئة autocomplete المدن (القائمة تُحدَّث ديناميكياً)
        function initCityAutocomplete(searchInputId, hiddenInputId) {
            var cityList = [];
            var instance = initAutocomplete({
                searchId: searchInputId,
                hiddenId: hiddenInputId,
                wrapClass: 'city-autocomplete-wrap',
                dropdownClass: 'city-dropdown',
                itemClass: 'city-item',
                noResultClass: 'city-no-result',
                getList: function() { return cityList; },
                _list: cityList
            });
            if (instance) {
                instance._searchId = searchInputId;
                var origSetList = instance.setList;
                instance.setList = function(list, preSelectValue) {
                    cityList = list;
                    origSetList.call(this, list, preSelectValue);
                };
            }
            return instance;
        }

        // دالة عامة: جلب مدن المحافظة بالاسم (تُستخدم عند تعبئة نموذج التعديل)
        window.triggerCityFetchByGovName = function(govName, citySearchId, cityHiddenId, preSelectValue) {
            if (!govName) return;
            var gov = GOV_LIST.find(function(g) { return g.name === govName; });
            var inst = cityInstances[citySearchId];
            if (gov && inst) fetchCities(gov.code, inst, preSelectValue);
        };

        // دالة: تعبئة govId من القائمة وتشغيل سلسلة تحميل المدن → ac-select → fetchDeliveryCost
        // تُستخدم عند الملء التلقائي لعميل ليس لديه IDs مخزنة
        window.setTgAutoFillIds = function(govName, govIdFieldId, citySearchId, cityName) {
            if (!govName) return;
            var gov = GOV_LIST.find(function(g) { return g.name === govName; });
            if (!gov) return;
            var govIdEl = document.getElementById(govIdFieldId);
            if (govIdEl) govIdEl.value = gov.code || '';
            var inst = cityInstances[citySearchId];
            if (inst) fetchCities(gov.code, inst, cityName || '');
            // fetchCities → setList → selectItem → ac-select على createCity/editCity
            // → linkIdField يضبط cityId ويستدعي fetchCreateDeliveryCost/fetchEditDeliveryCost
        };

        function validateAutocompleteOnSubmit(formEl, hiddenInputId, searchInputId, fieldLabel) {
            var hiddenEl = document.getElementById(hiddenInputId);
            var searchEl = document.getElementById(searchInputId);
            var fieldWrap = searchEl ? (searchEl.closest('[id$="GovWrap"]') || searchEl.closest('[id$="CityWrap"]')) : null;
            if (!fieldWrap || fieldWrap.classList.contains('d-none')) return true;
            if (!hiddenEl || !hiddenEl.value.trim()) {
                if (searchEl) {
                    searchEl.classList.add('is-invalid');
                    searchEl.focus();
                }
                if (fieldLabel) alert('يرجى اختيار ' + fieldLabel + ' من القائمة.');
                return false;
            }
            if (searchEl) searchEl.classList.remove('is-invalid');
            return true;
        }

        // تهيئة autocomplete المدن
        var cityInstances = {
            createCitySearch: initCityAutocomplete('createCitySearch', 'createCity'),
            editCitySearch:   initCityAutocomplete('editCitySearch',   'editCity')
        };

        // تهيئة autocomplete المحافظات مع ربط المدن
        initGovAutocomplete('createGovSearch', 'createGov', cityInstances.createCitySearch);
        initGovAutocomplete('editGovSearch',   'editGov',   cityInstances.editCitySearch);

        // التحقق عند الإرسال - نموذج الإنشاء
        var createForm = document.querySelector('#createTaskFormCollapse form');
        if (createForm) {
            createForm.addEventListener('submit', function(e) {
                var isTelegraphCreate = taskTypeSelect && taskTypeSelect.value === 'telegraph';
                if (isTelegraphCreate) {
                    if (typeof syncCreateTelegraphRequiredState === 'function') syncCreateTelegraphRequiredState();
                    if (typeof this.checkValidity === 'function' && !this.checkValidity()) {
                        e.preventDefault();
                        if (typeof this.reportValidity === 'function') this.reportValidity();
                        return;
                    }
                    if (!validateAutocompleteOnSubmit(this, 'createGov', 'createGovSearch', 'المحافظة')) {
                        e.preventDefault();
                        return;
                    }
                    if (!validateAutocompleteOnSubmit(this, 'createCity', 'createCitySearch', 'المدينة')) {
                        e.preventDefault();
                        return;
                    }
                }
            });
        }

        // التحقق عند الإرسال - نموذج التعديل
        var editForm = document.querySelector('#editTaskFormCollapse form');
        if (editForm) {
            editForm.addEventListener('submit', function(e) {
                var editTypeEl = document.getElementById('editTaskType');
                var isTelegraphEdit = editTypeEl && editTypeEl.value === 'telegraph';
                if (isTelegraphEdit) {
                    if (typeof this.checkValidity === 'function' && !this.checkValidity()) {
                        e.preventDefault();
                        if (typeof this.reportValidity === 'function') this.reportValidity();
                        return;
                    }
                    if (!validateAutocompleteOnSubmit(this, 'editGov', 'editGovSearch', 'المحافظة')) {
                        e.preventDefault();
                        return;
                    }
                    if (!validateAutocompleteOnSubmit(this, 'editCity', 'editCitySearch', 'المدينة')) {
                        e.preventDefault();
                        return;
                    }
                }
            });
        }
    })();
    // ===== نهاية نظام autocomplete للمحافظات والمدن =====

    // ===== ملء حقول gov_id و city_id عند اختيار قيمة من autocomplete =====
    (function() {
        function linkIdField(hiddenNameId, idFieldId, onSelectCallback) {
            var h = document.getElementById(hiddenNameId);
            var idEl = document.getElementById(idFieldId);
            if (!h || !idEl) return;
            h.addEventListener('ac-select', function(e) {
                var d = e.detail || {};
                idEl.value = d.code || '';
                if (typeof onSelectCallback === 'function') onSelectCallback();
            });
        }
        linkIdField('createGov', 'createGovId');
        linkIdField('editGov', 'editGovId');
        linkIdField('createCity', 'createCityId', function() {
            if (typeof fetchCreateDeliveryCost === 'function') fetchCreateDeliveryCost();
        });
        linkIdField('editCity', 'editCityId', function() {
            if (typeof fetchEditDeliveryCost === 'function') fetchEditDeliveryCost();
        });
    })();

    // تحميل أسماء القوالب وتعبئة datalist
    function loadTemplateSuggestions() {
        if (!templateSuggestions) {
            return;
        }

        // الحصول على base path بشكل صحيح
        function getApiPath(endpoint) {
            const currentPath = window.location.pathname || '/';
            const pathParts = currentPath.split('/').filter(Boolean);
            const stopSegments = ['dashboard', 'modules', 'api', 'assets', 'includes'];
            const baseParts = [];

            for (const part of pathParts) {
                if (stopSegments.includes(part) || part.endsWith('.php')) {
                    break;
                }
                baseParts.push(part);
            }

            const basePath = baseParts.length ? '/' + baseParts.join('/') : '';
            const apiPath = (basePath + '/api/' + endpoint).replace(/\/+/g, '/');
            return apiPath.startsWith('/') ? apiPath : '/' + apiPath;
        }

        const apiUrl = getApiPath('get_product_templates.php');

        fetch(apiUrl)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                if (data.success && Array.isArray(data.templates)) {
                    window.__productTemplatesList = data.templates;
                    // حفظ التفاصيل الكاملة (id, code, type)
                    window.__productTemplatesDetailed = Array.isArray(data.templates_detailed) ? data.templates_detailed : [];
                    if (templateSuggestions) {
                        templateSuggestions.innerHTML = '';
                        data.templates.forEach(templateName => {
                            const option = document.createElement('option');
                            option.value = templateName;
                            templateSuggestions.appendChild(option);
                        });
                    }
                    initAllProductNameDropdowns();
                }
            })
            .catch(error => {
                console.error('Error loading template suggestions:', error);
            });
    }

    // إظهار منتقي القوالب أسفل حقل الاسم
    function showTemplatePicker(row) {
        var picker = row.querySelector('.template-picker');
        if (!picker) return;
        var tplList = (typeof window.__productTemplatesDetailed !== 'undefined' && Array.isArray(window.__productTemplatesDetailed))
            ? window.__productTemplatesDetailed.filter(function(d) { return d.type === 'template'; })
            : [];
        picker.innerHTML = '';
        if (tplList.length === 0) {
            picker.innerHTML = '<div class="text-muted small p-2">لا توجد قوالب متاحة</div>';
        } else {
            tplList.forEach(function(tpl) {
                var qty = parseFloat(tpl.available_qty || 0);
                var qtyClass = qty > 0 ? 'text-success' : 'text-danger';
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'template-picker-btn';
                btn.innerHTML =
                    '<span class="tpl-name">' + escHtml(tpl.name || '') + '</span>' +
                    '<span class="tpl-qty ' + qtyClass + '">' + Math.round(qty).toLocaleString('ar-EG') + ' متاح</span>';
                btn.addEventListener('click', function() {
                    var nameInput = row.querySelector('.product-name-input') || row.querySelector('.edit-product-name');
                    if (nameInput) {
                        nameInput.value = tpl.name || '';
                        nameInput.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                    updateRawMaterialQtyDisplay(row, tpl.name || '');
                    // تمييز الزر المختار
                    picker.querySelectorAll('.template-picker-btn').forEach(function(b) { b.classList.remove('selected'); });
                    btn.classList.add('selected');
                });
                picker.appendChild(btn);
            });
        }
        picker.classList.remove('d-none');
    }

    function hideTemplatePicker(row) {
        var picker = row.querySelector('.template-picker');
        if (picker) picker.classList.add('d-none');
    }

    // دالة مساعدة: جلب تفاصيل المنتج (id, code, type) بالاسم
    function getProductDetail(name) {
        var detailed = window.__productTemplatesDetailed || [];
        return detailed.find(function(d) { return d.name === name; }) || null;
    }

    // دالة مساعدة: بناء نص العرض (الكود/ID - الاسم)
    function getProductDisplayLabel(name) {
        var detail = getProductDetail(name);
        if (!detail) return name;
        var prefix = detail.code || detail.id || '';
        return prefix ? prefix + ' - ' + name : name;
    }

    // إظهار/إخفاء حقل التصنيف ↔ الكمية المتاحة حسب النوع (للتوافق مع الكود القديم)
    function toggleRawMaterialQtyDisplay(row, isRawMaterial) {
        toggleCategoryAndQtyDisplay(row, isRawMaterial ? 'raw_material' : '');
    }
    // تحديث عرض الكمية المتاحة من الخامة المختارة
    function updateRawMaterialQtyDisplay(row, productName) {
        var rawQtyWrap = row.querySelector('.raw-qty-wrap');
        if (!rawQtyWrap) return;
        var qtyEl = rawQtyWrap.querySelector('.raw-material-qty-value');
        if (!qtyEl) return;
        var detail = getProductDetail(productName);
        if (detail && (detail.type === 'raw_material' || detail.type === 'packaging' || detail.type === 'template' || detail.type === 'second_grade' || detail.type === 'external') && detail.available_qty !== undefined) {
            var qty = parseFloat(detail.available_qty);
            var qtyUnit = (detail.type === 'packaging') ? (detail.unit || 'قطعة') : (detail.type === 'raw_material' ? 'كيلو' : (detail.unit || 'قطعة'));
            qtyEl.textContent = qty.toLocaleString('ar-EG', {maximumFractionDigits: 3}) + ' ' + qtyUnit;
            qtyEl.className = 'raw-material-qty-value fw-semibold ' + (qty > 0 ? 'text-success' : 'text-danger');
        } else {
            qtyEl.textContent = '—';
            qtyEl.className = 'raw-material-qty-value fw-semibold text-info';
        }
    }

    // تطبيق قيود الوحدة والحقول حسب نوع المنتج
    function applyRawMaterialUnitRestriction(row, typeOrBool) {
        // يقبل string (نوع المنتج) أو boolean (للتوافق مع الكود القديم)
        var type = (typeOrBool === true) ? 'raw_material' : (typeOrBool === false ? '' : String(typeOrBool || ''));
        var unitSelect = row.querySelector('.product-unit-input') || row.querySelector('.edit-product-unit');
        if (!unitSelect) return;
        var currentUnit = unitSelect.value;
        if (type === 'raw_material') {
            var defaultUnit = (currentUnit === 'جرام') ? 'جرام' : 'كيلو';
            unitSelect.innerHTML = '<option value="كيلو"' + (defaultUnit === 'كيلو' ? ' selected' : '') + '>كيلو</option>' +
                '<option value="جرام"' + (defaultUnit === 'جرام' ? ' selected' : '') + '>جرام</option>';
            row.setAttribute('data-item-type', 'raw_material');
        } else if (type === 'template') {
            var tmplUnits = ['قطعة','كرتونة'];
            var keepTmpl = tmplUnits.indexOf(currentUnit) !== -1 ? currentUnit : 'قطعة';
            unitSelect.innerHTML = tmplUnits.map(function(u) {
                return '<option value="' + u + '"' + (u === keepTmpl ? ' selected' : '') + '>' + u + '</option>';
            }).join('');
            row.setAttribute('data-item-type', 'template');
        } else if (type === 'external') {
            var extUnits = ['كرتونة','شرينك','دسته','قطعة'];
            var keepExt = extUnits.indexOf(currentUnit) !== -1 ? currentUnit : 'قطعة';
            unitSelect.innerHTML = extUnits.map(function(u) {
                return '<option value="' + u + '"' + (u === keepExt ? ' selected' : '') + '>' + u + '</option>';
            }).join('');
            row.setAttribute('data-item-type', 'external');
        } else if (type === 'second_grade') {
            var sgUnits = ['قطعة','كيلو','كرتونة'];
            var keepSg = sgUnits.indexOf(currentUnit) !== -1 ? currentUnit : 'قطعة';
            unitSelect.innerHTML = sgUnits.map(function(u) {
                return '<option value="' + u + '"' + (u === keepSg ? ' selected' : '') + '>' + u + '</option>';
            }).join('');
            row.setAttribute('data-item-type', 'second_grade');
        } else if (type === 'packaging') {
            var pkgUnits = ['قطعة','عبوة','كرتونة','دسته'];
            var keepPkg = pkgUnits.indexOf(currentUnit) !== -1 ? currentUnit : 'قطعة';
            unitSelect.innerHTML = pkgUnits.map(function(u) {
                return '<option value="' + u + '"' + (u === keepPkg ? ' selected' : '') + '>' + u + '</option>';
            }).join('');
            row.setAttribute('data-item-type', 'packaging');
        } else {
            var units = ['كرتونة','عبوة','كيلو','جرام','شرينك','دسته','قطعة'];
            var keepUnit = units.indexOf(currentUnit) !== -1 ? currentUnit : 'قطعة';
            unitSelect.innerHTML = units.map(function(u) {
                return '<option value="' + u + '"' + (u === keepUnit ? ' selected' : '') + '>' + u + '</option>';
            }).join('');
            row.setAttribute('data-item-type', type);
        }
        toggleCategoryAndQtyDisplay(row, type);
    }
    // تحكم في ظهور حقل التصنيف والكمية المتاحة حسب نوع المنتج
    function toggleCategoryAndQtyDisplay(row, type) {
        var categoryWrap = row.querySelector('.category-wrap');
        var rawQtyWrap = row.querySelector('.raw-qty-wrap');
        // إخفاء التصنيف دائماً وإظهار الكمية المتاحة لجميع الأنواع
        if (categoryWrap) { categoryWrap.classList.add('d-none'); }
        if (rawQtyWrap) { rawQtyWrap.classList.remove('d-none'); rawQtyWrap.style.display = ''; }
    }

    // دروب داون اسم المنتج: يفلتر حسب المدخلات ويسمح بالإدخال اليدوي أو الاختيار من القائمة
    function initProductNameDropdown(inputEl) {
        if (!inputEl || inputEl.dataset.productDropdownInited === '1') return;
        var templates = (typeof window.__productTemplatesList !== 'undefined' && Array.isArray(window.__productTemplatesList)) ? window.__productTemplatesList : [];
        var wrap = inputEl.closest('.product-name-wrap');
        if (!wrap) {
            wrap = document.createElement('div');
            wrap.className = 'product-name-wrap position-relative';
            inputEl.parentNode.insertBefore(wrap, inputEl);
            wrap.appendChild(inputEl);
        }
        var dropEl = wrap.querySelector('.product-template-dropdown');
        if (!dropEl) {
            dropEl = document.createElement('div');
            dropEl.className = 'product-template-dropdown d-none';
            wrap.appendChild(dropEl);
        }
        function matchTemplate(name, q) {
            if (!q || !name) return true;
            var lowerQ = (q + '').trim().toLowerCase();
            // البحث في الاسم والكود/ID
            if ((name + '').toLowerCase().indexOf(lowerQ) !== -1) return true;
            var detail = getProductDetail(name);
            if (detail) {
                if (detail.code && (detail.code + '').indexOf(lowerQ) !== -1) return true;
                if ((detail.id + '').indexOf(lowerQ) !== -1) return true;
            }
            return false;
        }
        function showDropdown() {
            var q = (inputEl.value || '').trim();
            var detailed = window.__productTemplatesDetailed || [];
            // فلترة حسب النوع المختار
            var typeFilter = '';
            var typeSelector = inputEl.closest('.product-row') ? inputEl.closest('.product-row').querySelector('.product-type-selector') : null;
            if (typeSelector) typeFilter = typeSelector.value || '';
            var candidates = typeFilter !== '' ? templates.filter(function(t) {
                var d = getProductDetail(t);
                if (!d) return typeFilter === 'template';
                return d.type === typeFilter;
            }) : templates;
            var filtered = q ? candidates.filter(function(t) { return matchTemplate(t, q); }) : candidates.slice(0, 100);
            dropEl.innerHTML = '';
            if (filtered.length === 0) {
                dropEl.classList.add('d-none');
                return;
            }
            // تجميع حسب النوع
            var externals = [];
            var templateItems = [];
            var secondGradeItems = [];
            var rawMaterials = [];
            var packagingItems = [];
            filtered.forEach(function(name) {
                var detail = getProductDetail(name);
                if (detail && detail.type === 'external') {
                    externals.push({ name: name, detail: detail });
                } else if (detail && detail.type === 'second_grade') {
                    secondGradeItems.push({ name: name, detail: detail });
                } else if (detail && detail.type === 'raw_material') {
                    rawMaterials.push({ name: name, detail: detail });
                } else if (detail && detail.type === 'packaging') {
                    packagingItems.push({ name: name, detail: detail });
                } else {
                    templateItems.push({ name: name, detail: detail });
                }
            });
            function addSection(title, items) {
                if (items.length === 0) return;
                var header = document.createElement('div');
                header.className = 'product-template-section-header';
                header.textContent = title;
                dropEl.appendChild(header);
                items.forEach(function(item) {
                    var div = document.createElement('div');
                    div.className = 'product-template-item';
                    var prefix = item.detail ? (item.detail.code || item.detail.id || '') : '';
                    var sectionLabel = (item.detail && item.detail.section) ? ' <span class="text-muted small">— ' + escHtml(item.detail.section) + '</span>' : '';
                    div.innerHTML = (prefix ? makeIdBadge(prefix) : '') + escHtml(item.name) + sectionLabel;
                    div.addEventListener('click', function() {
                        inputEl.value = item.name;
                        dropEl.classList.add('d-none');
                        inputEl.focus();
                        var _row = inputEl.closest('.product-row');
                        if (_row) {
                            var itemDetailType = item.detail ? (item.detail.type || '') : '';
                            // مزامنة النوع مع الـ selector
                            var _typeSelector = _row.querySelector('.product-type-selector');
                            if (_typeSelector && itemDetailType) _typeSelector.value = itemDetailType;
                            applyRawMaterialUnitRestriction(_row, itemDetailType);
                            updateRawMaterialQtyDisplay(_row, item.name);
                            setTimeout(function() { if (typeof fetchProductPriceHistory === 'function') fetchProductPriceHistory(_row); }, 50);
                        }
                    });
                    dropEl.appendChild(div);
                });
            }
            addSection('📦 المنتجات الخارجية', externals);
            addSection('🏭 قوالب المنتجات', templateItems);
            addSection('♻️ فرز تاني', secondGradeItems);
            addSection('⚗️ الخامات', rawMaterials);
            addSection('🧴 أدوات التعبئة', packagingItems);
            dropEl.classList.remove('d-none');
        }
        function hideDropdown() {
            dropEl.classList.add('d-none');
        }
        inputEl.addEventListener('input', showDropdown);
        inputEl.addEventListener('focus', showDropdown);
        inputEl.addEventListener('blur', function() {
            setTimeout(hideDropdown, 200);
            var _row = inputEl.closest('.product-row');
            if (_row) setTimeout(function() { if (typeof fetchProductPriceHistory === 'function') fetchProductPriceHistory(_row); }, 300);
        });
        inputEl.dataset.productDropdownInited = '1';
    }
    (function closeProductDropdownsOnOutsideClick() {
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.product-name-wrap')) {
                document.querySelectorAll('.product-template-dropdown').forEach(function(d) { d.classList.add('d-none'); });
            }
        });
    })();
    // تهيئة حقل اختيار النوع
    function initTypeSelector(selectEl) {
        if (!selectEl || selectEl.dataset.typeSelectorInited === '1') return;
        selectEl.addEventListener('change', function() {
            var row = selectEl.closest('.product-row');
            if (!row) return;
            var nameInput = row.querySelector('.product-name-input') || row.querySelector('.edit-product-name');
            var val = selectEl.value;
            // تطبيق قيود الوحدة والحقول حسب النوع
            applyRawMaterialUnitRestriction(row, val);
            // إظهار/إخفاء منتقي القوالب
            if (val === 'template') {
                showTemplatePicker(row);
            } else {
                hideTemplatePicker(row);
                if (nameInput) {
                    nameInput.value = '';
                    nameInput.focus();
                    nameInput.dispatchEvent(new Event('input', { bubbles: true }));
                }
            }
        });
        selectEl.dataset.typeSelectorInited = '1';
    }
    function initAllProductNameDropdowns() {
        document.querySelectorAll('.product-name-input').forEach(initProductNameDropdown);
        document.querySelectorAll('.product-type-selector').forEach(initTypeSelector);
    }

    // تحميل الاقتراحات عند تحميل الصفحة
    loadTemplateSuggestions();
    
    // إدارة المنتجات المتعددة
    const productsContainer = document.getElementById('productsContainer');
    const addProductBtn = document.getElementById('addProductBtn');
    let productIndex = 1;
    
    function updateRemoveButtons() {
        const productRows = productsContainer.querySelectorAll('.product-row');
        productRows.forEach((row, index) => {
            const removeBtn = row.querySelector('.remove-product-btn');
            if (productRows.length > 1) {
                removeBtn.style.display = 'block';
            } else {
                removeBtn.style.display = 'none';
            }
        });
    }
    
    function addProductRow() {
        const quCats = (typeof __quCategories !== 'undefined' && Array.isArray(__quCategories)) ? __quCategories : [];
        const categoryOptions = quCats.map(function(qc) {
            const t = (qc.type || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            return '<option value="' + t + '">' + t + '</option>';
        }).join('');
        const newRow = document.createElement('div');
        newRow.className = 'product-row mb-3 p-3 border rounded';
        newRow.setAttribute('data-product-index', productIndex);
        newRow.innerHTML = `
            <div class="row g-2">
                <div class="col-12 col-md-3">
                    <label class="form-label small">النوع</label>
                    <select class="form-select form-select-sm product-type-selector mb-1" name="products[${productIndex}][item_type]">
                        <option value="">— اختر النوع —</option>
                        <option value="external">📦 منتجات خارجية</option>
                        <option value="template">🏭 منتجات المصنع</option>
                        <option value="second_grade">♻️ فرز تاني</option>
                        <option value="raw_material">⚗️ خامات</option>
                        <option value="packaging">🧴 أدوات تعبئة</option>
                    </select>
                    <div class="product-name-wrap position-relative">
                        <input type="text" class="form-control product-name-input" name="products[${productIndex}][name]" placeholder="اختر من القائمة" autocomplete="off">
                        <div class="product-template-dropdown d-none"></div>
                    </div>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small">الكمية</label>
                    <input type="number" class="form-control product-quantity-input" name="products[${productIndex}][quantity]" step="1" min="0" placeholder="مثال: 120" id="product-quantity-${productIndex}">
                    <small class="product-effective-qty-hint text-muted d-none" id="product-effective-qty-hint-${productIndex}"></small>
                </div>
                <div class="col-6 col-md-2">
                    <div class="category-wrap d-none">
                        <label class="form-label small">التصنيف</label>
                        <select class="form-select form-select-sm product-category-input" name="products[${productIndex}][category]" id="product-category-${productIndex}">
                            <option value="">— اختر التصنيف —</option>
                            ${categoryOptions}
                        </select>
                    </div>
                    <div class="raw-qty-wrap">
                        <label class="form-label small text-info">الكمية المتاحة</label>
                        <div class="raw-material-qty-value fw-semibold text-info">—</div>
                    </div>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small">الوحدة</label>
                    <select class="form-select form-select-sm product-unit-input" name="products[${productIndex}][unit]" id="product-unit-${productIndex}" onchange="updateQuantityStep(${productIndex})">
                        <option value="قطعة" selected>قطعة</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small">السعر</label>
                    <input type="number" class="form-control product-price-input" name="products[${productIndex}][price]" step="0.01" min="0" placeholder="0.00" id="product-price-${productIndex}">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small">الإجمالي (قابل للتحكم)</label>
                    <div class="input-group input-group-sm">
                        <input type="number" class="form-control product-line-total-input" name="products[${productIndex}][line_total]" step="0.01" min="0" placeholder="0.00" id="product-line-total-${productIndex}" title="الإجمالي = الكمية × السعر حسب الوحدة">
                        <span class="input-group-text">ج.م</span>
                    </div>
                </div>
                <div class="col-6 col-md-1 d-flex align-items-end">
                    <button type="button" class="btn btn-danger btn-sm w-100 remove-product-btn">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
        `;
        productsContainer.appendChild(newRow);
        productIndex++;
        updateRemoveButtons();
        var newNameInput = newRow.querySelector('.product-name-input');
        if (newNameInput) initProductNameDropdown(newNameInput);
        var newTypeSelector = newRow.querySelector('.product-type-selector');
        if (newTypeSelector) initTypeSelector(newTypeSelector);
        
        // إضافة مستمع الحدث لزر الحذف
        newRow.querySelector('.remove-product-btn').addEventListener('click', function() {
            newRow.remove();
            updateRemoveButtons();
            if (typeof updateCreateTaskSummary === 'function') updateCreateTaskSummary();
            if (typeof updateCreateTelegraphPiecesCount === 'function') updateCreateTelegraphPiecesCount();
        });
        if (typeof updateCreateTaskSummary === 'function') updateCreateTaskSummary();
        if (typeof updateCreateTelegraphPiecesCount === 'function') updateCreateTelegraphPiecesCount();
    }
    
    // إضافة منتج جديد
    if (addProductBtn) {
        addProductBtn.addEventListener('click', addProductRow);
    }
    
    // إضافة مستمعات الأحداث لأزرار الحذف الموجودة
    productsContainer.querySelectorAll('.remove-product-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            this.closest('.product-row').remove();
            updateRemoveButtons();
            if (typeof updateCreateTaskSummary === 'function') updateCreateTaskSummary();
            if (typeof updateCreateTelegraphPiecesCount === 'function') updateCreateTelegraphPiecesCount();
        });
    });
    
    // تحديث حالة أزرار الحذف عند التحميل
    updateRemoveButtons();
    
    // تحديث step للكمية بناءً على الوحدة المختارة عند التحميل
    document.querySelectorAll('.product-unit-input').forEach(function(unitSelect) {
        const index = unitSelect.id.replace('product-unit-', '');
        updateQuantityStep(index);
    });
    
    // تحديث تلميح "الكمية الفعلية للخصم" عند الوحدة = شرينك واختيار التصنيف
    function updateEffectiveQtyHintForRow(row) {
        if (!row) return;
        const unitSelect = row.querySelector('.product-unit-input');
        const categorySelect = row.querySelector('.product-category-input');
        const qtyInput = row.querySelector('.product-quantity-input');
        const hintEl = row.querySelector('.product-effective-qty-hint');
        if (!hintEl || !unitSelect || !categorySelect || !qtyInput) return;
        const quData = (typeof __quData !== 'undefined' && Array.isArray(__quData)) ? __quData : [];
        if (unitSelect.value === 'شرينك' && categorySelect.value && quData.length > 0) {
            let multiplier = 1;
            for (let i = 0; i < quData.length; i++) {
                const it = quData[i];
                if ((it.type || '').trim() === (categorySelect.value || '').trim() && (it.description || '').trim() === 'شرينك') {
                    multiplier = (it.quantity != null) ? parseFloat(it.quantity) : 1;
                    break;
                }
            }
            const qty = parseFloat(qtyInput.value) || 0;
            const effective = qty * multiplier;
            if (effective > 0) {
                hintEl.textContent = 'الكمية الفعلية للخصم: ' + effective;
                hintEl.classList.remove('d-none');
            } else {
                hintEl.classList.add('d-none');
            }
        } else {
            hintEl.classList.add('d-none');
        }
    }
    function updateAllEffectiveQtyHints() {
        if (!productsContainer) return;
        productsContainer.querySelectorAll('.product-row').forEach(updateEffectiveQtyHintForRow);
    }
    productsContainer.addEventListener('change', function(e) {
        if (e.target.classList.contains('product-unit-input') || e.target.classList.contains('product-category-input')) {
            updateEffectiveQtyHintForRow(e.target.closest('.product-row'));
        }
    });
    productsContainer.addEventListener('input', function(e) {
        if (e.target.classList.contains('product-quantity-input')) {
            updateEffectiveQtyHintForRow(e.target.closest('.product-row'));
        }
    });
    updateAllEffectiveQtyHints();
    
    // حساب الإجمالي تلقائياً: الإجمالي = الكمية × السعر (حسب الوحدة والكمية)
    function updateProductLineTotal(row) {
        const qtyInput = row.querySelector('.product-quantity-input');
        const priceInput = row.querySelector('.product-price-input');
        const totalInput = row.querySelector('.product-line-total-input');
        if (!qtyInput || !priceInput || !totalInput) return;
        const qty = parseFloat(qtyInput.value || '0');
        const price = parseFloat(priceInput.value || '0');
        const total = qty * price;
        totalInput.value = total > 0 ? total.toFixed(2) : '';
    }
    
    function syncPriceFromLineTotal(row) {
        const qtyInput = row.querySelector('.product-quantity-input');
        const priceInput = row.querySelector('.product-price-input');
        const totalInput = row.querySelector('.product-line-total-input');
        if (!qtyInput || !priceInput || !totalInput) return;
        const qty = parseFloat(qtyInput.value || '0');
        const totalVal = parseFloat(totalInput.value || '0');
        if (qty > 0 && totalVal >= 0) {
            priceInput.value = (totalVal / qty).toFixed(2);
        }
    }
    
    productsContainer.addEventListener('input', function(e) {
        const row = e.target.closest('.product-row');
        if (!row) return;
        if (e.target.classList.contains('product-quantity-input') || e.target.classList.contains('product-price-input')) {
            updateProductLineTotal(row);
        } else if (e.target.classList.contains('product-line-total-input')) {
            syncPriceFromLineTotal(row);
        }
        updateCreateTaskSummary();
        if (e.target.classList.contains('product-quantity-input') && typeof updateCreateTelegraphPiecesCount === 'function') {
            updateCreateTelegraphPiecesCount();
        }
    });
    
    // تحديث الإجمالي للصفوف الموجودة عند التحميل
    productsContainer.querySelectorAll('.product-row').forEach(updateProductLineTotal);
    
    // ملخص الإجمالي النهائي (إجمالي المنتجات + رسوم الشحن - الخصم - المدفوع مقدماً)
    function updateCreateTaskSummary() {
        var subtotalEl = document.getElementById('createTaskSubtotalDisplay');
        var shippingEl = document.getElementById('createTaskShippingDisplay');
        var discountEl = document.getElementById('createTaskDiscountDisplay');
        var finalEl = document.getElementById('createTaskFinalTotalDisplay');
        var advanceEl = document.getElementById('createTaskAdvancePaymentDisplay');
        var remainingEl = document.getElementById('createTaskRemainingDisplay');
        var shippingInput = document.getElementById('createTaskShippingFees');
        var discountInput = document.getElementById('createTaskDiscount');
        var advanceInput = document.getElementById('createTaskAdvancePayment');
        if (!subtotalEl || !shippingEl || !finalEl) return;
        var subtotal = 0;
        productsContainer.querySelectorAll('.product-line-total-input').forEach(function(inp) {
            var v = parseFloat(inp.value || '0');
            if (!isNaN(v) && v >= 0) subtotal += v;
        });
        var shipping = 0;
        var isTg = document.getElementById('taskTypeSelect') && document.getElementById('taskTypeSelect').value === 'telegraph';
        if (!isTg && shippingInput) {
            var v = parseFloat(shippingInput.value || '0');
            if (!isNaN(v) && v >= 0) shipping = v;
        }
        var discount = 0;
        if (discountInput) {
            var v = parseFloat(discountInput.value || '0');
            if (!isNaN(v) && v >= 0) discount = v;
        }
        var advance = 0;
        if (advanceInput) {
            var v = parseFloat(advanceInput.value || '0');
            if (!isNaN(v) && v >= 0) advance = v;
        }
        var finalTotal;
        if (isTg) {
            // تليجراف: الإجمالي = إجمالي المنتجات - تكلفة التوصيل
            var deliveryCost = window._tgDeliveryCost || 0;
            finalTotal = subtotal - deliveryCost;
        } else {
            finalTotal = subtotal + shipping - discount;
        }
        var remaining = finalTotal - advance;
        subtotalEl.textContent = subtotal.toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ج.م';
        shippingEl.textContent = shipping.toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ج.م';
        if (discountEl) discountEl.textContent = discount.toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ج.م';
        finalEl.textContent = finalTotal.toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ج.م';
        if (advanceEl) advanceEl.textContent = advance.toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ج.م';
        if (remainingEl) remainingEl.textContent = remaining.toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ج.م';
        var advanceCol = document.getElementById('createTaskAdvancePaymentCol');
        var remainingCol = document.getElementById('createTaskRemainingCol');
        if (advanceCol) advanceCol.style.display = advance > 0 ? '' : 'none';
        if (remainingCol) remainingCol.style.display = advance > 0 ? '' : 'none';
    }
    if (document.getElementById('createTaskShippingFees')) {
        document.getElementById('createTaskShippingFees').addEventListener('input', updateCreateTaskSummary);
        document.getElementById('createTaskShippingFees').addEventListener('change', updateCreateTaskSummary);
    }
    if (document.getElementById('createTaskDiscount')) {
        document.getElementById('createTaskDiscount').addEventListener('input', updateCreateTaskSummary);
        document.getElementById('createTaskDiscount').addEventListener('change', updateCreateTaskSummary);
    }
    if (document.getElementById('createTaskAdvancePayment')) {
        document.getElementById('createTaskAdvancePayment').addEventListener('input', updateCreateTaskSummary);
        document.getElementById('createTaskAdvancePayment').addEventListener('change', updateCreateTaskSummary);
    }
    updateCreateTaskSummary();
    if (typeof updateCreateTelegraphPiecesCount === 'function') updateCreateTelegraphPiecesCount(true);

    // === حساب تكلفة التوصيل TelegraphEx ===
    var _tgCalcTimer = null;
    function getTgCalcApiPath() {
        var currentPath = window.location.pathname || '/';
        var pathParts = currentPath.split('/').filter(Boolean);
        var stopSegments = ['dashboard', 'modules', 'api', 'assets', 'includes'];
        var baseParts = [];
        for (var i = 0; i < pathParts.length; i++) {
            if (stopSegments.indexOf(pathParts[i]) !== -1 || pathParts[i].indexOf('.php') !== -1) break;
            baseParts.push(pathParts[i]);
        }
        var basePath = baseParts.length ? '/' + baseParts.join('/') : '';
        return (basePath + '/api/tg_calc_fees.php').replace(/\/+/g, '/');
    }

    function fetchCreateDeliveryCost() {
        var isTg = taskTypeSelect && taskTypeSelect.value === 'telegraph';
        if (!isTg) return;

        var govIdEl   = document.getElementById('createGovId');
        var cityIdEl  = document.getElementById('createCityId');
        var weightEl  = document.getElementById('createTgWeight');
        var spinner   = document.getElementById('createTaskDeliveryCostSpinner');
        var valueEl   = document.getElementById('createTaskDeliveryCostValue');

        var govId  = govIdEl ? parseInt(govIdEl.value) : 0;
        var cityId = cityIdEl ? parseInt(cityIdEl.value) : 0;

        if (!govId || !cityId) {
            if (valueEl) valueEl.textContent = '—';
            return;
        }

        // حساب إجمالي المنتجات - الخصم
        var subtotal = 0;
        productsContainer.querySelectorAll('.product-line-total-input').forEach(function(inp) {
            var v = parseFloat(inp.value || '0');
            if (!isNaN(v) && v >= 0) subtotal += v;
        });
        var discountInput = document.getElementById('createTaskDiscount');
        var discount = discountInput ? parseFloat(discountInput.value || '0') : 0;
        if (isNaN(discount) || discount < 0) discount = 0;
        var price = Math.max(0, subtotal - discount);
        var weight = weightEl ? parseFloat(weightEl.value || '1') : 1;
        if (isNaN(weight) || weight <= 0) weight = 1;

        if (spinner) spinner.classList.remove('d-none');
        if (valueEl) valueEl.textContent = 'جاري الحساب...';

        clearTimeout(_tgCalcTimer);
        _tgCalcTimer = setTimeout(function() {
            var url = getTgCalcApiPath() + '?price=' + price + '&recipientZoneId=' + govId + '&recipientSubzoneId=' + cityId + '&weight=' + weight;
            fetch(url)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (spinner) spinner.classList.add('d-none');
                    var fees = data && data.data && data.data.calculateShipmentFees;
                    if (fees) {
                        var deliveryCost = (parseFloat(fees.delivery) || 0) + (parseFloat(fees.weight) || 0) + (parseFloat(fees.collection) || 0);
                        if (valueEl) valueEl.textContent = deliveryCost.toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ج.م';
                        window._tgDeliveryCost = deliveryCost;
                        updateCreateTaskSummary();
                        var returnVal = parseFloat(fees['return']) || 0;
                        var returnEl = document.getElementById('createTaskReturnCostValue');
                        if (returnEl) returnEl.textContent = returnVal.toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ج.م';
                    } else {
                        if (valueEl) valueEl.textContent = 'غير متاح';
                        window._tgDeliveryCost = 0;
                        updateCreateTaskSummary();
                        var returnEl = document.getElementById('createTaskReturnCostValue');
                        if (returnEl) returnEl.textContent = '—';
                    }
                })
                .catch(function() {
                    if (spinner) spinner.classList.add('d-none');
                    if (valueEl) valueEl.textContent = 'خطأ في الحساب';
                });
        }, 500);
    }

    // جعل الدالة متاحة عالمياً ليتم استدعاؤها من autocomplete callbacks
    window.fetchCreateDeliveryCost = fetchCreateDeliveryCost;

    // ===== حساب تكلفة التوصيل لنموذج التعديل =====
    var _tgEditCalcTimer = null;
    function fetchEditDeliveryCost() {
        var editTypeEl = document.getElementById('editTaskType');
        if (!editTypeEl || editTypeEl.value !== 'telegraph') return;

        var govIdEl  = document.getElementById('editGovId');
        var cityIdEl = document.getElementById('editCityId');
        var weightEl = document.getElementById('editTgWeight');
        if (!govIdEl || !cityIdEl) return;

        var govId  = parseInt(govIdEl.value)  || 0;
        var cityId = parseInt(cityIdEl.value) || 0;
        if (!govId || !cityId) return;

        var container = document.getElementById('editProductsContainer');
        var subtotal = 0;
        if (container) {
            container.querySelectorAll('.edit-product-line-total').forEach(function(inp) {
                var v = parseFloat(inp.value);
                if (!isNaN(v) && v >= 0) subtotal += v;
            });
        }
        var discountInput = document.getElementById('editTaskDiscount');
        var discount = discountInput ? parseFloat(discountInput.value || '0') : 0;
        if (isNaN(discount) || discount < 0) discount = 0;
        var price = Math.max(0, subtotal - discount);
        var weight = weightEl ? parseFloat(weightEl.value || '1') : 1;
        if (isNaN(weight) || weight <= 0) weight = 1;

        var spinner  = document.getElementById('editTaskDeliveryCostSpinner');
        var valueEl  = document.getElementById('editTaskDeliveryCostValue');
        if (spinner) spinner.classList.remove('d-none');
        if (valueEl) valueEl.textContent = '';

        clearTimeout(_tgEditCalcTimer);
        _tgEditCalcTimer = setTimeout(function() {
            var url = getTgCalcApiPath() + '?price=' + price + '&recipientZoneId=' + govId + '&recipientSubzoneId=' + cityId + '&weight=' + weight;
            fetch(url)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (spinner) spinner.classList.add('d-none');
                    var fees = data && data.data && data.data.calculateShipmentFees;
                    if (fees) {
                        var deliveryCost = (parseFloat(fees.delivery) || 0) + (parseFloat(fees.weight) || 0) + (parseFloat(fees.collection) || 0);
                        if (valueEl) valueEl.textContent = deliveryCost.toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ج.م';
                        window._tgEditDeliveryCost = deliveryCost;
                        updateEditTaskSummary();
                        var returnVal = parseFloat(fees['return']) || 0;
                        var returnEl = document.getElementById('editTaskReturnCostValue');
                        if (returnEl) returnEl.textContent = returnVal.toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ج.م';
                    } else {
                        if (valueEl) valueEl.textContent = 'غير متاح';
                        window._tgEditDeliveryCost = 0;
                        updateEditTaskSummary();
                        var returnEl = document.getElementById('editTaskReturnCostValue');
                        if (returnEl) returnEl.textContent = '—';
                    }
                })
                .catch(function() {
                    if (spinner) spinner.classList.add('d-none');
                    if (valueEl) valueEl.textContent = 'خطأ في الحساب';
                });
        }, 500);
    }
    window.fetchEditDeliveryCost = fetchEditDeliveryCost;

    // ربط حساب تكلفة توصيل التعديل بتغيير الوزن/الخصم/المنتجات
    if (document.getElementById('editTgWeight')) {
        document.getElementById('editTgWeight').addEventListener('input', fetchEditDeliveryCost);
    }
    if (document.getElementById('editTaskDiscount')) {
        document.getElementById('editTaskDiscount').addEventListener('input', fetchEditDeliveryCost);
        document.getElementById('editTaskDiscount').addEventListener('change', fetchEditDeliveryCost);
    }
    if (document.getElementById('editTaskAdvancePayment')) {
        document.getElementById('editTaskAdvancePayment').addEventListener('input', updateEditTaskSummary);
        document.getElementById('editTaskAdvancePayment').addEventListener('change', updateEditTaskSummary);
    }
    var editProductsContainerEl = document.getElementById('editProductsContainer');
    if (editProductsContainerEl) {
        editProductsContainerEl.addEventListener('input', function(e) {
            if (e.target.classList.contains('edit-product-line-total') || e.target.classList.contains('edit-product-price') || e.target.classList.contains('edit-product-qty')) {
                fetchEditDeliveryCost();
            }
        });
    }

    // ربط حساب تكلفة التوصيل بتغيير الوزن/الخصم/المنتجات
    if (document.getElementById('createTgWeight')) {
        document.getElementById('createTgWeight').addEventListener('input', fetchCreateDeliveryCost);
    }
    if (document.getElementById('createTaskDiscount')) {
        document.getElementById('createTaskDiscount').addEventListener('input', fetchCreateDeliveryCost);
        document.getElementById('createTaskDiscount').addEventListener('change', fetchCreateDeliveryCost);
    }
    // تحديث عند تغيير المنتجات
    productsContainer.addEventListener('input', function(e) {
        if (e.target.classList.contains('product-quantity-input') || e.target.classList.contains('product-price-input') || e.target.classList.contains('product-line-total-input')) {
            fetchCreateDeliveryCost();
        }
    });

    // === مقترحات الأسعار السابقة ===
    var _priceHistoryCache = {};

    function getOrCreatePriceSuggestionsEl(productRow) {
        var existing = productRow.querySelector('.price-suggestions-wrap');
        if (existing) return existing;
        var priceInput = productRow.querySelector('.product-price-input');
        if (!priceInput) return null;
        var col = priceInput.parentElement;
        var wrap = document.createElement('div');
        wrap.className = 'price-suggestions-wrap mt-1';
        col.appendChild(wrap);
        return wrap;
    }

    function showPriceSuggestions(productRow, suggestions) {
        console.log('showPriceSuggestions called with', suggestions);
        var wrap = getOrCreatePriceSuggestionsEl(productRow);
        if (!wrap) {
            console.log('showPriceSuggestions: no wrap element found');
            return;
        }
        wrap.innerHTML = '';
        if (!suggestions || suggestions.length === 0) {
            console.log('showPriceSuggestions: no suggestions to show');
            return;
        }
        var label = document.createElement('div');
        label.className = 'text-muted small mb-1';
        label.textContent = 'أسعار سابقة:';
        wrap.appendChild(label);
        var pillsWrap = document.createElement('div');
        pillsWrap.className = 'd-flex flex-wrap gap-1';
        suggestions.forEach(function(s) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-outline-info btn-sm py-0 px-2 price-suggestion-pill';
            btn.style.fontSize = '0.75rem';
            btn.title = 'اضغط لتطبيق هذا السعر';
            var priceFormatted = parseFloat(s.price).toFixed(2);
            btn.innerHTML = '<span class="fw-semibold">' + priceFormatted + '</span> ج.م'
                + (s.date ? ' <span class="text-muted opacity-75">(' + s.date + ')</span>' : '');
            btn.addEventListener('click', function() {
                var priceInput = productRow.querySelector('.product-price-input');
                if (priceInput) {
                    priceInput.value = priceFormatted;
                    priceInput.dispatchEvent(new Event('input', { bubbles: true }));
                }
                pillsWrap.querySelectorAll('.price-suggestion-pill').forEach(function(b) {
                    b.classList.remove('btn-info');
                    b.classList.add('btn-outline-info');
                });
                btn.classList.remove('btn-outline-info');
                btn.classList.add('btn-info');
            });
            pillsWrap.appendChild(btn);
        });
        wrap.appendChild(pillsWrap);
    }

    function fetchProductPriceHistory(productRow) {
        var customerIdEl = document.getElementById('local_customer_id_task');
        var customerIdVal = customerIdEl ? (customerIdEl.value || '').trim() : '';
        var nameInput = productRow.querySelector('.product-name-input');
        var productName = nameInput ? (nameInput.value || '').trim() : '';

        // Debug code - تسجيل البيانات للتشخيص
        console.log('fetchProductPriceHistory called', {
            customerId: customerIdVal,
            productName: productName,
            hasCustomer: !!customerIdVal,
            hasProduct: !!productName,
            customerElement: !!customerIdEl,
            nameElement: !!nameInput
        });

        if (!customerIdVal || !productName) {
            var wrap = productRow.querySelector('.price-suggestions-wrap');
            if (wrap) wrap.innerHTML = '';
            console.log('fetchProductPriceHistory: missing customer or product name');
            return;
        }

        var cacheKey = customerIdVal + '::' + productName.toLowerCase();
        if (_priceHistoryCache[cacheKey] !== undefined) {
            showPriceSuggestions(productRow, _priceHistoryCache[cacheKey]);
            return;
        }

        // إذا عندنا بيانات الأوردرات محملة في الذاكرة → نستخرج منها مباشرة
        if (_loadedCustomerOrders && _loadedCustomerOrders.customerId === customerIdVal) {
            var localSuggestions = extractSuggestionsFromOrders(_loadedCustomerOrders.orders, productName);
            _priceHistoryCache[cacheKey] = localSuggestions;
            showPriceSuggestions(productRow, localSuggestions);
            return;
        }

        var _params = new URLSearchParams(window.location.search);
        _params.set('action', 'get_customer_price_history');
        _params.set('customer_id', customerIdVal);
        _params.set('product_name', productName);
        var url = '?' + _params.toString();
        
        console.log('fetchProductPriceHistory: making API request to', url);

        fetch(url)
            .then(function(r) { 
                console.log('fetchProductPriceHistory: API response status', r.status);
                return r.json(); 
            })
            .then(function(data) {
                console.log('fetchProductPriceHistory: API response data', data);
                if (data && data.success && Array.isArray(data.suggestions)) {
                    _priceHistoryCache[cacheKey] = data.suggestions;
                    showPriceSuggestions(productRow, data.suggestions);
                    console.log('fetchProductPriceHistory: showing suggestions', data.suggestions);
                } else {
                    console.log('fetchProductPriceHistory: no valid suggestions in response');
                }
            })
            .catch(function(error) { 
                console.error('fetchProductPriceHistory: API request failed', error);
            });
    }

    // استخراج مقترحات الأسعار من أوردرات محملة مسبقاً
    var _loadedCustomerOrders = null; // { customerId, orders }

    function extractSuggestionsFromOrders(orders, productName) {
        var suggestions = [];
        var seen = {};
        var pnLower = productName.toLowerCase();
        for (var i = 0; i < orders.length; i++) {
            var order = orders[i];
            if (!order.products) continue;
            for (var j = 0; j < order.products.length; j++) {
                var p = order.products[j];
                var pName = (p.name || '').trim();
                if (!pName) continue;
                var pnl = pName.toLowerCase();
                if (pnl.indexOf(pnLower) === -1 && pnLower.indexOf(pnl) === -1) continue;
                if (!p.price || p.price <= 0) continue;
                var key = parseFloat(p.price).toFixed(2) + '_' + (p.unit || '');
                if (seen[key]) continue;
                seen[key] = true;
                suggestions.push({ price: p.price, unit: p.unit || 'قطعة', date: order.date || '' });
                if (suggestions.length >= 5) return suggestions;
            }
        }
        return suggestions;
    }

    // عند اختيار عميل جديد: مسح الكاش وتحديث كل صفوف المنتجات + تفعيل زر السجل
    var _localCustomerIdEl = document.getElementById('local_customer_id_task');
    var _viewHistoryBtn    = document.getElementById('viewCustomerHistoryBtn');

    if (_localCustomerIdEl) {
        _localCustomerIdEl.addEventListener('customer-selected', function() {
            _priceHistoryCache = {};
            _loadedCustomerOrders = null;
            if (productsContainer) {
                productsContainer.querySelectorAll('.product-row').forEach(function(row) {
                    fetchProductPriceHistory(row);
                });
            }
            // تفعيل زر السجل
            if (_viewHistoryBtn) {
                _viewHistoryBtn.disabled = false;
                _viewHistoryBtn.removeAttribute('title');
            }
        });
    }

    // عرض سجل المشتريات في بطاقة inline
    var _historyCard    = document.getElementById('customerPurchaseHistoryCard');
    var _closeHistoryBtn = document.getElementById('closeHistoryCard');

    function loadCustomerHistory(customerIdVal, customerName) {
        var loadingEl = document.getElementById('customerHistoryLoading');
        var contentEl = document.getElementById('customerHistoryContent');
        var nameSpan  = document.getElementById('historyCardCustomerName');

        if (nameSpan)  nameSpan.textContent = customerName;
        if (loadingEl) loadingEl.style.display = '';
        if (contentEl) contentEl.innerHTML = '';
        if (_historyCard) _historyCard.style.display = '';

        var params = new URLSearchParams(window.location.search);
        params.set('action', 'get_customer_purchase_history');
        params.set('customer_id', customerIdVal);

        fetch('?' + params.toString())
            .then(function(r) {
                return r.text().then(function(text) {
                    try { return JSON.parse(text); }
                    catch(e) { throw new Error('استجابة غير صالحة: ' + text.substring(0, 200)); }
                });
            })
            .then(function(data) {
                if (loadingEl) loadingEl.style.display = 'none';
                if (!contentEl) return;
                if (data.error) {
                    contentEl.innerHTML = '<div class="alert alert-warning small mb-0">خطأ: ' + data.error + '</div>';
                    return;
                }
                if (!data.success || !data.orders || data.orders.length === 0) {
                    contentEl.innerHTML = '<div class="text-center text-muted py-3"><i class="bi bi-inbox fs-4 d-block mb-1"></i><small>لا توجد مشتريات سابقة</small></div>';
                    return;
                }
                var html = '';
                data.orders.forEach(function(order) {
                    var label = order.task_number ? '' + order.task_number : (order.title || ('' + order.task_id));
                    var shortDate = '';
                    if (order.date) {
                        var parts = order.date.split('-');
                        shortDate = parts.length >= 3 ? parts[2] + '/' + parts[1] : order.date;
                    }
                    html += '<div class="card mb-2 border-0 border-bottom">';
                    html += '<div class="px-2 pt-2 d-flex justify-content-between align-items-center">';
                    html += '<span class="fw-semibold small"><i class="bi bi-receipt me-1 text-primary"></i>' + label + '</span>';
                    html += '<span class="text-muted small">' + shortDate + '</span>';
                    html += '</div><div class="mb-1"></div>';
                    if (order.products && order.products.length > 0) {
                        html += '<div class="table-responsive"><table class="table table-sm mb-1 small">';
                        html += '<thead class="table-light"><tr><th>المنتج</th><th class="text-center">الكمية</th><th class="text-center">السعر</th></tr></thead><tbody>';
                        order.products.forEach(function(p) {
                            var qty   = p.quantity != null ? p.quantity + ' ' + (p.unit || '') : '—';
                            var price = p.price != null ? parseFloat(p.price).toFixed(2) + ' ج.م' : '—';
                            var pName2 = p.name || '-';
                            var pDetail2 = getProductDetail(p.name);
                            var pCell2 = pDetail2 && (pDetail2.code || pDetail2.id) ? makeIdBadge(pDetail2.code || pDetail2.id) + escHtml(pName2) : escHtml(pName2);
                            html += '<tr><td>' + pCell2 + '</td><td class="text-center">' + qty + '</td><td class="text-center">' + price + '</td></tr>';
                        });
                        html += '</tbody></table></div>';
                    } else {
                        html += '<div class="px-2 pb-2 text-muted small">لا تفاصيل منتجات</div>';
                    }
                    html += '</div>';
                });
                contentEl.innerHTML = html;

                // حفظ الأوردرات في الذاكرة وتحديث مقترحات الأسعار فوراً
                _loadedCustomerOrders = { customerId: customerIdVal, orders: data.orders };
                _priceHistoryCache = {}; // مسح كاش قديم ليُعاد بناؤه من البيانات الجديدة
                if (productsContainer) {
                    productsContainer.querySelectorAll('.product-row').forEach(function(row) {
                        fetchProductPriceHistory(row);
                    });
                }
            })
            .catch(function(err) {
                if (loadingEl) loadingEl.style.display = 'none';
                if (contentEl) contentEl.innerHTML = '<div class="alert alert-danger small mb-0">' + (err.message || 'خطأ غير معروف') + '</div>';
            });
    }

    if (_viewHistoryBtn) {
        _viewHistoryBtn.addEventListener('click', function() {
            var customerIdVal  = _localCustomerIdEl ? (_localCustomerIdEl.value || '').trim() : '';
            var customerNameEl = document.getElementById('local_customer_search_task');
            var customerName   = customerNameEl ? (customerNameEl.value || '').trim() : 'العميل';
            if (!customerIdVal) return;
            // إذا كانت البطاقة ظاهرة بالفعل، أخفها (toggle)
            if (_historyCard && _historyCard.style.display !== 'none') {
                _historyCard.style.display = 'none';
                return;
            }
            loadCustomerHistory(customerIdVal, customerName);
        });
    }

    if (_closeHistoryBtn) {
        _closeHistoryBtn.addEventListener('click', function() {
            if (_historyCard) _historyCard.style.display = 'none';
        });
    }

    // تصدير دوال داخلية للاستخدام في تكرار الأوردر
    window._addCreateProductRow = addProductRow;
    window._updateCreateTaskSummary = updateCreateTaskSummary;
    window._resetProductIndex = function() { productIndex = 0; };
});

// دالة لتحديث step حقل الكمية بناءً على الوحدة المختارة
function updateQuantityStep(index) {
    const unitSelect = document.getElementById('product-unit-' + index);
    const quantityInput = document.getElementById('product-quantity-' + index);
    
    if (!unitSelect || !quantityInput) {
        return;
    }
    
    const selectedUnit = unitSelect.value;
    // الوحدات التي يجب أن تكون أرقام صحيحة فقط
    const integerUnits = ['كيلو', 'قطعة', 'جرام', 'دسته'];
    const mustBeInteger = integerUnits.includes(selectedUnit);
    
    if (mustBeInteger) {
        quantityInput.step = '1';
        quantityInput.setAttribute('step', '1');
        // تحويل القيمة الحالية إلى رقم صحيح إذا كانت عشرية
        if (quantityInput.value && quantityInput.value.includes('.')) {
            quantityInput.value = Math.round(parseFloat(quantityInput.value));
        }
    } else {
        quantityInput.step = '0.01';
        quantityInput.setAttribute('step', '0.01');
    }
}

// طباعة تلقائية للإيصال بعد إنشاء المهمة بنجاح
(function() {
    'use strict';
    
    // التحقق من وجود معلومات الطباعة في session
    <?php if (isset($_SESSION['print_task_id']) && isset($_SESSION['print_task_url'])): ?>
    const printTaskId = <?php echo (int)$_SESSION['print_task_id']; ?>;
    const printTaskUrl = <?php echo json_encode($_SESSION['print_task_url'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    
    // فتح نافذة الطباعة تلقائياً
    if (printTaskId > 0 && printTaskUrl) {
        // فتح نافذة جديدة للطباعة
        const printWindow = window.open(printTaskUrl, '_blank', 'width=400,height=600');
        
        // بعد فتح النافذة، مسح معلومات الطباعة من session
        // سيتم مسحها عند إعادة تحميل الصفحة
        <?php 
        unset($_SESSION['print_task_id']);
        unset($_SESSION['print_task_url']);
        ?>
    }
    <?php endif; ?>
?>

// بطاقة اعتماد الفاتورة (عميل محلي أو شركة شحن حسب نوع الأوردر)
function ensureApproveInvoiceCardExists() {
    if (document.getElementById('approveInvoiceCardCollapse')) {
        return true;
    }
    var host = document.querySelector('main') || document.getElementById('main-content') || document.body;
    if (!host) return false;
    var list = (typeof __shippingCompaniesForTask !== 'undefined' && Array.isArray(__shippingCompaniesForTask)) ? __shippingCompaniesForTask : [];
    var opts = list.map(function(c) { return '<option value="' + parseInt(c.id, 10) + '">' + (c.name || '').replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</option>'; }).join('');
    var wrapper = document.createElement('div');
    wrapper.className = 'container-fluid px-0';
    wrapper.innerHTML = '<div class="collapse" id="approveInvoiceCardCollapse">' +
        '<div class="card shadow-sm border-success mb-3">' +
        '<div class="card-header bg-success text-white d-flex justify-content-between align-items-center">' +
        '<h5 class="mb-0"><i class="bi bi-check2-circle me-2"></i>اعتماد الفاتورة</h5>' +
        '<button type="button" class="btn btn-sm btn-light" onclick="closeApproveInvoiceCard()" aria-label="إغلاق"><i class="bi bi-x-lg"></i></button>' +
        '</div>' +
        '<form method="POST" id="approveInvoiceCardForm" action="?page=production_tasks">' +
        '<input type="hidden" name="action" value="approve_task_invoice">' +
        '<input type="hidden" name="task_id" id="approveInvoiceCardTaskId">' +
        '<input type="hidden" name="total_amount" id="approveInvoiceCardTotalAmount">' +
        '<input type="hidden" name="approve_for_shipping" id="approveInvoiceCardForShipping" value="0">' +
        '<div class="card-body">' +
        '<div id="approveInvoiceCardCustomerBlock" class="approve-invoice-block">' +
        '<p class="text-muted small mb-3">يُضاف الأوردر إلى سجل مشتريات <strong>العميل المكتوب في الأوردر</strong> ويُضاف الإجمالي النهائي إلى رصيده المدين.</p>' +
        '<div class="mb-3"><label class="form-label fw-bold">العميل (من الأوردر)</label><div id="approveInvoiceCardCustomerName" class="form-control bg-light"></div></div>' +
        '</div>' +
        '<div id="approveInvoiceCardShippingBlock" class="approve-invoice-block" style="display:none;">' +
        '<p class="text-muted small mb-3">يُضاف الأوردر إلى <strong>سجل الفواتير الورقية لشركة الشحن</strong>. المبلغ الذي يُضاف لديون الشركة هو صافي سعر الطرد (أدناه).</p>' +
        '<div class="mb-3"><label class="form-label fw-bold">شركة الشحن</label><select class="form-select" name="shipping_company_id" id="approveInvoiceCardShippingCompanyId"><option value="">— اختر الشركة —</option>' + opts + '</select></div>' +
        '<div class="mb-3"><label class="form-label fw-bold">صافي سعر الطرد (ج.م)</label><input type="number" step="0.01" class="form-control" name="net_parcel_price" id="approveInvoiceCardNetParcelPrice" placeholder="موجب أو سالب">' +
        '<small class="form-text text-muted">هذا المبلغ يُضاف لديون شركة الشحن (سالب = يقلل الديون)</small></div>' +
        '</div>' +
        '<!-- ملخص خصم المخزون -->' +
        '<div id="approveInvoiceInventoryPreview" class="mb-3" style="display:none;">' +
        '<label class="form-label fw-bold"><i class="bi bi-box-seam me-1"></i>المخزون الذي سيُخصم عند الاعتماد</label>' +
        '<div id="approveInvoiceInventoryContent"><div class="text-muted small text-center py-2"><span class="spinner-border spinner-border-sm me-1"></span>جاري تحميل بيانات المخزون...</div></div>' +
        '</div>' +
        '<div class="mb-3"><label class="form-label fw-bold">المبلغ الذي سيُضاف للرصيد (المتبقي بعد المدفوع مقدماً)</label><div id="approveInvoiceCardTotalDisplay" class="form-control bg-light fw-bold text-danger"></div></div>' +
        '<div class="d-flex gap-2">' +
        '<button type="button" class="btn btn-secondary w-50" onclick="closeApproveInvoiceCard()"><i class="bi bi-x-circle me-1"></i>إلغاء</button>' +
        '<button type="submit" class="btn btn-success w-50"><i class="bi bi-check2-circle me-1"></i>اعتماد وإضافة للسجل</button>' +
        '</div>' +
        '</div>' +
        '</form>' +
        '</div>' +
        '</div>';
    host.appendChild(wrapper);
    return !!document.getElementById('approveInvoiceCardCollapse');
}
function closeApproveInvoiceCard() {
    var collapse = document.getElementById('approveInvoiceCardCollapse');
    if (collapse && typeof bootstrap !== 'undefined') {
        var c = bootstrap.Collapse.getInstance(collapse);
        if (c) c.hide();
    }
}

/**
 * بناء جدول ملخص المخزون من بيانات الـ API (flat array per product)
 */
function buildInventoryPreviewTable(data) {
    if (!Array.isArray(data) || data.length === 0) {
        return '<div class="text-muted small text-center py-1">لا توجد بيانات مخزون مرتبطة بهذا الأوردر</div>';
    }

    function fmtQty(n) { return (Math.round(parseFloat(n) * 100) / 100).toLocaleString('ar-EG'); }
    function suffBadge(sufficient) {
        if (sufficient === null || sufficient === undefined) return '<span class="badge bg-secondary">غير موجود</span>';
        return sufficient ? '<span class="badge bg-success">كافية</span>' : '<span class="badge bg-danger">غير كافية</span>';
    }
    function renderSection(rows, icon, iconColor, title) {
        if (!rows.length) return '';
        var s = '<div class="mb-2"><div class="d-flex align-items-center gap-1 mb-1"><i class="bi ' + icon + ' text-' + iconColor + '"></i><strong class="small">' + title + '</strong></div>' +
            '<table class="table table-sm table-bordered mb-0" style="font-size:0.8rem;">' +
            '<thead class="table-light"><tr><th>المنتج</th><th class="text-center">المطلوب</th><th class="text-center">المتاح</th><th class="text-center">الحالة</th></tr></thead><tbody>';
        rows.forEach(function(r) {
            var rowCls = (r.sufficient === false) ? 'table-danger' : '';
            var availTxt = (r.available === null || r.available === undefined) ? '—' : fmtQty(r.available) + ' ' + (r.unit || '');
            s += '<tr class="' + rowCls + '">' +
                '<td>' + (r.name || '—') + '</td>' +
                '<td class="text-center">' + fmtQty(r.needed) + ' ' + (r.unit || '') + '</td>' +
                '<td class="text-center">' + availTxt + '</td>' +
                '<td class="text-center">' + suffBadge(r.sufficient) + '</td></tr>';
        });
        return s + '</tbody></table></div>';
    }

    var groups = { products: [], packaging: [], raw: [], none: [] };
    data.forEach(function(r) {
        var src = r.source;
        if (src === 'products') groups.products.push(r);
        else if (src === 'packaging') groups.packaging.push(r);
        else if (src === 'raw') groups.raw.push(r);
        else groups.none.push(r);
    });

    var html = renderSection(groups.products, 'bi-shop', 'primary', 'منتجات الشركة') +
               renderSection(groups.packaging, 'bi-archive', 'info', 'مخزن أدوات التعبئة') +
               renderSection(groups.raw, 'bi-boxes', 'warning', 'مخزن الخامات') +
               renderSection(groups.none, 'bi-question-circle', 'secondary', 'غير موجود في المخزون');

    return html || '<div class="text-muted small text-center py-1">لا توجد بيانات مخزون مرتبطة بهذا الأوردر</div>';
}

/**
 * جلب وعرض ملخص خصم المخزون لأوردر محدد
 */
function loadInventoryPreview(taskId) {
    var previewSection = document.getElementById('approveInvoiceInventoryPreview');
    var contentEl = document.getElementById('approveInvoiceInventoryContent');
    if (!previewSection || !contentEl || !taskId) return;

    previewSection.style.display = 'block';
    contentEl.innerHTML = '<div class="text-muted small text-center py-2"><span class="spinner-border spinner-border-sm me-1"></span>جاري تحميل بيانات المخزون...</div>';

    // بناء مسار الـ API بنفس طريقة باقي الاستدعاءات في هذه الصفحة
    var _curPath = window.location.pathname || '/';
    var _pathParts = _curPath.split('/').filter(Boolean);
    var _stopSegs = ['dashboard', 'modules', 'api', 'assets', 'includes'];
    var _baseParts = [];
    for (var _i = 0; _i < _pathParts.length; _i++) {
        var _p = _pathParts[_i];
        if (_stopSegs.indexOf(_p) !== -1 || _p.indexOf('.php') !== -1) break;
        _baseParts.push(_p);
    }
    var _base = _baseParts.length ? '/' + _baseParts.join('/') : '';
    var url = (_base + '/api/task_inventory_preview.php').replace(/\/+/g, '/') + '?task_id=' + encodeURIComponent(taskId);

    fetch(url)
        .then(function(res) { return res.json(); })
        .then(function(json) {
            if (!json.success) {
                contentEl.innerHTML = '<div class="text-muted small text-center py-1">' + (json.error || 'تعذر تحميل بيانات المخزون') + '</div>';
                return;
            }
            contentEl.innerHTML = buildInventoryPreviewTable(json.data);
        })
        .catch(function() {
            contentEl.innerHTML = '<div class="text-muted small text-center py-1">تعذر تحميل بيانات المخزون</div>';
        });
}

window.openApproveInvoiceCardFromBtn = function(btn) {
    if (!btn || !btn.getAttribute) return;
    if (btn.stopPropagation) btn.stopPropagation();
    var taskId = parseInt(btn.getAttribute('data-task-id'), 10) || 0;
    var customerName = btn.getAttribute('data-customer-name') || '';
    var receiptTotal = parseFloat(btn.getAttribute('data-receipt-total')) || 0;
    var orderType = (btn.getAttribute('data-order-type') || '').trim();
    openApproveInvoiceCard(taskId, customerName, receiptTotal, orderType);
};
window.openApproveInvoiceCard = function(taskId, customerName, receiptTotal, orderType) {
    if (!ensureApproveInvoiceCardExists()) return;
    var collapse = document.getElementById('approveInvoiceCardCollapse');
    var taskIdInput = collapse && collapse.querySelector('#approveInvoiceCardTaskId');
    var totalInput = collapse && collapse.querySelector('#approveInvoiceCardTotalAmount');
    var nameEl = collapse && collapse.querySelector('#approveInvoiceCardCustomerName');
    var totalDisplay = collapse && collapse.querySelector('#approveInvoiceCardTotalDisplay');
    var forShippingInput = collapse && collapse.querySelector('#approveInvoiceCardForShipping');
    var customerBlock = collapse && collapse.querySelector('#approveInvoiceCardCustomerBlock');
    var shippingBlock = collapse && collapse.querySelector('#approveInvoiceCardShippingBlock');
    var shippingSelect = collapse && collapse.querySelector('#approveInvoiceCardShippingCompanyId');
    var netPriceInput = collapse && collapse.querySelector('#approveInvoiceCardNetParcelPrice');
    var isShippingMode = (orderType === 'telegraph' || orderType === 'shipping_company');
    if (taskIdInput) taskIdInput.value = taskId || '';
    if (totalInput) totalInput.value = (receiptTotal != null) ? String(receiptTotal) : '';
    if (totalDisplay) totalDisplay.textContent = (receiptTotal != null) ? parseFloat(receiptTotal).toFixed(2) + ' ج.م' : '—';
    if (forShippingInput) forShippingInput.value = isShippingMode ? '1' : '0';
    if (customerBlock) customerBlock.style.display = isShippingMode ? 'none' : 'block';
    if (shippingBlock) shippingBlock.style.display = isShippingMode ? 'block' : 'none';
    if (nameEl) nameEl.textContent = (customerName != null && String(customerName).trim() !== '') ? customerName : '—';
    if (shippingSelect) { shippingSelect.value = ''; shippingSelect.removeAttribute('required'); if (isShippingMode) shippingSelect.setAttribute('required', 'required'); }
    if (netPriceInput) { netPriceInput.value = ''; netPriceInput.removeAttribute('required'); if (isShippingMode) netPriceInput.setAttribute('required', 'required'); }
    // جلب ملخص خصم المخزون
    if (taskId) { loadInventoryPreview(taskId); }
    if (collapse && typeof bootstrap !== 'undefined') {
        var c = bootstrap.Collapse.getOrCreateInstance(collapse, { toggle: true });
        c.show();
        setTimeout(function() {
            if (collapse.scrollIntoView) collapse.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }, 150);
    }
};

// ===== بطاقة اعتماد الفواتير المحددة (جماعي) =====
function ensureBulkApproveCardExists() {
    if (document.getElementById('bulkApproveCardCollapse')) return true;
    var host = document.querySelector('main') || document.getElementById('main-content') || document.body;
    if (!host) return false;
    var wrapper = document.createElement('div');
    wrapper.className = 'container-fluid px-0';
    wrapper.id = 'bulkApproveCardWrapper';
    wrapper.innerHTML =
        '<div class="collapse" id="bulkApproveCardCollapse">' +
        '<div class="card shadow-sm border-success mb-3">' +
        '<div class="card-header bg-success text-white d-flex justify-content-between align-items-center">' +
        '<h5 class="mb-0"><i class="bi bi-check2-all me-2"></i><span id="bulkApproveCardTitle">اعتماد الفواتير المحددة</span></h5>' +
        '<button type="button" class="btn btn-sm btn-light" onclick="closeBulkApproveCard()" aria-label="إغلاق"><i class="bi bi-x-lg"></i></button>' +
        '</div>' +
        '<div class="card-body">' +
        '<div id="bulkApproveOrdersContainer"></div>' +
        '<form method="POST" id="bulkApproveCardForm" action="?page=production_tasks" class="mt-3">' +
        '<input type="hidden" name="action" value="bulk_approve_task_invoice">' +
        '<div id="bulkApproveTaskIdsContainer"></div>' +
        '<div id="bulkApproveShippingWarning" class="alert alert-warning py-2 small" style="display:none;"></div>' +
        '<div class="d-flex gap-2 mt-3">' +
        '<button type="button" class="btn btn-secondary w-50" onclick="closeBulkApproveCard()"><i class="bi bi-x-circle me-1"></i>إلغاء</button>' +
        '<button type="submit" class="btn btn-success w-50" id="bulkApproveSubmitBtn"><i class="bi bi-check2-all me-1"></i>اعتماد الكل</button>' +
        '</div>' +
        '</form>' +
        '</div>' +
        '</div>' +
        '</div>';
    host.appendChild(wrapper);
    return !!document.getElementById('bulkApproveCardCollapse');
}

function closeBulkApproveCard() {
    var collapse = document.getElementById('bulkApproveCardCollapse');
    if (collapse && typeof bootstrap !== 'undefined') {
        var c = bootstrap.Collapse.getInstance(collapse);
        if (c) c.hide();
    }
}

window.openBulkApproveCard = function() {
    var checked = document.querySelectorAll('.task-print-checkbox:checked');
    if (!checked.length) return;

    var orderTypeLabels = {
        'shop_order': 'محل', 'cash_customer': 'عميل نقدي',
        'telegraph': 'تليجراف', 'shipping_company': 'شركة شحن'
    };

    var approvable = [];
    var shippingOrders = [];
    var alreadyApproved = [];

    checked.forEach(function(cb) {
        var id = parseInt(cb.value, 10);
        var approved = cb.getAttribute('data-approved') === '1';
        var orderType = (cb.getAttribute('data-order-type') || '').trim();
        var isShipping = (orderType === 'telegraph' || orderType === 'shipping_company');
        var customerName = cb.getAttribute('data-customer-name') || '—';
        var receiptTotal = parseFloat(cb.getAttribute('data-receipt-total')) || 0;
        var productsJson = cb.getAttribute('data-products-json') || '[]';
        var shippingFees = parseFloat(cb.getAttribute('data-shipping-fees')) || 0;
        var discount = parseFloat(cb.getAttribute('data-discount')) || 0;
        var typeLabel = orderTypeLabels[orderType] || orderType;

        var products = [];
        try { products = JSON.parse(productsJson); } catch(e) {}

        var orderData = { id: id, customerName: customerName, orderType: orderType, typeLabel: typeLabel, receiptTotal: receiptTotal, products: products, shippingFees: shippingFees, discount: discount };

        if (approved) {
            alreadyApproved.push(orderData);
        } else if (isShipping) {
            shippingOrders.push(orderData);
        } else {
            approvable.push(orderData);
        }
    });

    if (!ensureBulkApproveCardExists()) return;

    var titleEl = document.getElementById('bulkApproveCardTitle');
    var container = document.getElementById('bulkApproveOrdersContainer');
    var idsContainer = document.getElementById('bulkApproveTaskIdsContainer');
    var shippingWarning = document.getElementById('bulkApproveShippingWarning');
    var submitBtn = document.getElementById('bulkApproveSubmitBtn');

    if (titleEl) titleEl.textContent = 'اعتماد الفواتير المحددة (' + approvable.length + ' فاتورة)';

    // بناء hidden inputs لمعرفات الأوردرات القابلة للاعتماد
    if (idsContainer) {
        idsContainer.innerHTML = approvable.map(function(o) {
            return '<input type="hidden" name="task_ids[]" value="' + o.id + '">';
        }).join('');
    }

    // تحذير الشحن
    if (shippingWarning) {
        if (shippingOrders.length > 0) {
            shippingWarning.style.display = '';
            shippingWarning.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i><strong>' + shippingOrders.length + ' أوردر شحن/تليجراف</strong> لم تُدرج: يتطلب اعتمادها فردياً (تحديد شركة الشحن وصافي سعر الطرد). ' +
                shippingOrders.map(function(o) { return '#' + o.id; }).join('، ');
        } else {
            shippingWarning.style.display = 'none';
        }
    }

    // بناء جدول تفاصيل الأوردرات
    if (container) {
        if (approvable.length === 0 && alreadyApproved.length === 0 && shippingOrders.length === 0) {
            container.innerHTML = '<div class="alert alert-info">لا توجد فواتير قابلة للاعتماد.</div>';
        } else {
            var allOrders = approvable.concat(alreadyApproved).concat(shippingOrders);
            var html = '<div class="table-responsive"><table class="table table-sm table-bordered align-middle mb-0">' +
                '<thead class="table-light"><tr><th>رقم الأوردر</th><th>العميل</th><th>النوع</th><th>الإجمالي</th><th>الحالة</th></tr></thead><tbody>';

            allOrders.forEach(function(o) {
                var statusBadge;
                if (alreadyApproved.indexOf(o) >= 0) {
                    statusBadge = '<span class="badge bg-secondary">معتمد مسبقاً</span>';
                } else if (shippingOrders.indexOf(o) >= 0) {
                    statusBadge = '<span class="badge bg-warning text-dark">يتطلب اعتماداً فردياً</span>';
                } else {
                    statusBadge = '<span class="badge bg-success">سيتم الاعتماد</span>';
                }

                html += '<tr>' +
                    '<td><strong>#' + o.id + '</strong></td>' +
                    '<td>' + (o.customerName || '—').replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</td>' +
                    '<td><span class="badge bg-light text-dark border">' + (o.typeLabel || '—').replace(/</g, '&lt;') + '</span></td>' +
                    '<td class="fw-bold text-success">' + o.receiptTotal.toFixed(2) + ' ج.م</td>' +
                    '<td>' + statusBadge + '</td>' +
                    '</tr>';
            });
            html += '</tbody></table></div>';
            container.innerHTML = html;
        }
    }

    if (submitBtn) submitBtn.disabled = approvable.length === 0;

    var collapse = document.getElementById('bulkApproveCardCollapse');
    if (collapse && typeof bootstrap !== 'undefined') {
        var c = bootstrap.Collapse.getOrCreateInstance(collapse, { toggle: false });
        c.show();
        setTimeout(function() {
            if (collapse.scrollIntoView) collapse.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }, 150);
    }
};

// دالة لفتح modal تغيير الحالة - يجب أن تكون في النطاق العام
window.openChangeStatusModal = function(taskId, currentStatus) {
    function ensureChangeStatusCardExists() {
        const existingCollapse = document.getElementById('changeStatusCardCollapse');
        if (existingCollapse) {
            // تأكد أن البطاقة كاملة (كل العناصر الداخلية موجودة)
            const taskIdInput = existingCollapse.querySelector('#changeStatusCardTaskId');
            const currentStatusDisplay = existingCollapse.querySelector('#currentStatusCardDisplay');
            const newStatusSelect = existingCollapse.querySelector('#newStatusCard');
            if (taskIdInput && currentStatusDisplay && newStatusSelect) {
                return true;
            }
            // بطاقة ناقصة (مثلاً بعد تنقل AJAX) — أزل الوعاء وأعد الإنشاء
            const wrapper = existingCollapse.closest('.container-fluid') || existingCollapse.parentElement;
            if (wrapper && wrapper.parentNode) {
                wrapper.remove();
            }
        }

        const host = document.querySelector('main') || document.getElementById('main-content') || document.body;
        if (!host) {
            return false;
        }

        const wrapper = document.createElement('div');
        wrapper.className = 'container-fluid px-0';
        wrapper.innerHTML = `
            <div class="collapse" id="changeStatusCardCollapse">
                <div class="card shadow-sm border-info mb-3">
                    <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="bi bi-gear me-2"></i>تغيير حالة الطلب
                        </h5>
                        <button type="button" class="btn btn-sm btn-light" onclick="closeChangeStatusCard()" aria-label="إغلاق">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                    <form method="POST" id="changeStatusCardForm" action="?page=production_tasks">
                        <input type="hidden" name="action" value="update_task_status">
                        <input type="hidden" name="task_id" id="changeStatusCardTaskId">
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label fw-bold">الحالة الحالية</label>
                                <div id="currentStatusCardDisplay" class="alert alert-info mb-0"></div>
                            </div>
                            <div class="mb-3">
                                <label for="newStatusCard" class="form-label fw-bold">اختر الحالة الجديدة <span class="text-danger">*</span></label>
                                <select class="form-select" name="status" id="newStatusCard" required>
                                    <option value="">-- اختر الحالة --</option>
                                    <option value="pending">معلقة</option>
                                    <option value="completed">مكتملة</option>
                                    <option value="with_delegate">مع المندوب</option>
                                    <option value="with_shipping_company">مع شركة الشحن</option>
                                    <option value="delivered">تم التوصيل</option>
                                    <option value="returned">تم الارجاع</option>
                                    <option value="cancelled">ملغاة</option>
                                </select>
                                <div class="form-text">سيتم تحديث حالة الطلب فوراً بعد الحفظ.</div>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-secondary w-50" onclick="closeChangeStatusCard()">
                                    <i class="bi bi-x-circle me-1"></i>إلغاء
                                </button>
                                <button type="submit" class="btn btn-info w-50">
                                    <i class="bi bi-check-circle me-1"></i>حفظ
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        `;

        // ضعه في نهاية main ليكون داخل المحتوى المعروض
        host.appendChild(wrapper);
        return !!document.getElementById('changeStatusCardCollapse');
    }

    function openChangeStatusCard(taskIdInner, currentStatusInner, retryCount = 0) {
        // في بعض حالات AJAX navigation قد لا تكون عناصر البطاقة موجودة
        if (!ensureChangeStatusCardExists()) {
            console.error('Failed to create change status card');
            return false;
        }

        const collapseEl = document.getElementById('changeStatusCardCollapse');
        if (!collapseEl) {
            if (retryCount < 3) {
                setTimeout(() => openChangeStatusCard(taskIdInner, currentStatusInner, retryCount + 1), 80);
                return false;
            }
            console.error('Change status card elements not found after retries');
            return false;
        }

        // استخراج العناصر من داخل نفس البطاقة لتجنب تداخل IDs أو بطاقة ناقصة
        const taskIdInput = collapseEl.querySelector('#changeStatusCardTaskId');
        const currentStatusDisplay = collapseEl.querySelector('#currentStatusCardDisplay');
        const newStatusSelect = collapseEl.querySelector('#newStatusCard');

        if (!taskIdInput || !currentStatusDisplay || !newStatusSelect) {
            if (retryCount < 3) {
                setTimeout(() => openChangeStatusCard(taskIdInner, currentStatusInner, retryCount + 1), 80);
                return false;
            }
            console.error('Change status card elements not found after retries');
            return false;
        }

        // تعيين معرف المهمة
        taskIdInput.value = taskIdInner;

        const statusLabels = {
            'pending': 'معلقة',
            'received': '',
            'completed': 'مكتملة',
            'with_delegate': 'مع المندوب',
            'with_shipping_company': 'مع شركة الشحن',
            'delivered': 'تم التوصيل',
            'returned': 'تم الارجاع',
            'cancelled': 'ملغاة'
        };

        const statusClasses = {
            'pending': 'warning',
            'received': 'info',
            'completed': 'success',
            'with_delegate': 'info',
            'with_shipping_company': 'warning',
            'delivered': 'success',
            'returned': 'secondary',
            'cancelled': 'danger'
        };

        const currentStatusLabel = statusLabels[currentStatusInner] || currentStatusInner;
        const currentStatusClass = statusClasses[currentStatusInner] || 'secondary';

        currentStatusDisplay.className = 'alert alert-' + currentStatusClass + ' mb-0';
        currentStatusDisplay.innerHTML = '<strong>الحالة الحالية:</strong> <span class="badge bg-' + currentStatusClass + '">' + currentStatusLabel + '</span>';

        // إعادة تعيين القائمة المنسدلة
        newStatusSelect.value = '';

        // فتح البطاقة (collapse)
        const collapse = bootstrap.Collapse.getInstance(collapseEl) || new bootstrap.Collapse(collapseEl, { toggle: false });
        collapse.show();

        // سكرول للبطاقة لسهولة الاستخدام على الهاتف
        setTimeout(() => {
            collapseEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 50);

        return true;
    }

    // على الموبايل نعرض البطاقة بدل المودال
    const isMobile = !!(window.matchMedia && (
        window.matchMedia('(max-width: 768px)').matches ||
        window.matchMedia('(pointer: coarse)').matches
    ));
    if (isMobile) {
        openChangeStatusCard(taskId, currentStatus);
        return;
    }

    const modalElement = document.getElementById('changeStatusModal');
    if (!modalElement) {
        // fallback: لو المودال غير موجود لأي سبب (AJAX navigation)، افتح البطاقة
        openChangeStatusCard(taskId, currentStatus);
        return;
    }
    
    const modal = new bootstrap.Modal(modalElement);
    const taskIdInput = document.getElementById('changeStatusTaskId');
    const currentStatusDisplay = document.getElementById('currentStatusDisplay');
    const newStatusSelect = document.getElementById('newStatus');
    
    if (!taskIdInput || !currentStatusDisplay || !newStatusSelect) {
        // fallback: لو عناصر المودال ناقصة (بسبب استبدال المحتوى بالـAJAX)، افتح البطاقة
        openChangeStatusCard(taskId, currentStatus);
        return;
    }
    
    // تعيين معرف المهمة
    taskIdInput.value = taskId;
    
    // عرض الحالة الحالية
    const statusLabels = {
        'pending': 'معلقة',
        'completed': 'مكتملة',
        'with_delegate': 'مع المندوب',
        'with_shipping_company': 'مع شركة الشحن',
        'delivered': 'تم التوصيل',
        'returned': 'تم الارجاع',
        'cancelled': 'ملغاة'
    };
    
    const statusClasses = {
        'pending': 'warning',
        'received': 'info',
        'completed': 'success',
        'with_delegate': 'info',
        'with_shipping_company': 'warning',
        'delivered': 'success',
        'returned': 'secondary',
        'cancelled': 'danger'
    };
    
    const currentStatusLabel = statusLabels[currentStatus] || currentStatus;
    const currentStatusClass = statusClasses[currentStatus] || 'secondary';
    
    currentStatusDisplay.className = 'alert alert-' + currentStatusClass + ' mb-0';
    currentStatusDisplay.innerHTML = '<strong>الحالة الحالية:</strong> <span class="badge bg-' + currentStatusClass + '">' + currentStatusLabel + '</span>';
    
    // إعادة تعيين القائمة المنسدلة
    newStatusSelect.value = '';
    
    // فتح الـ modal
    modal.show();
};

// إغلاق بطاقة تغيير الحالة (موبايل)
window.closeChangeStatusCard = function() {
    const collapseEl = document.getElementById('changeStatusCardCollapse');
    if (!collapseEl) {
        return;
    }
    const collapse = bootstrap.Collapse.getInstance(collapseEl) || new bootstrap.Collapse(collapseEl, { toggle: false });
    collapse.hide();
};

// تحديد أوردرات متعددة للطباعة والاعتماد
(function() {
    var selectAll = document.getElementById('selectAllTasks');
    var checkboxes = document.querySelectorAll('.task-print-checkbox');
    var printBtn = document.getElementById('printSelectedReceiptsBtn');
    var selectedCountEl = document.getElementById('selectedCount');
    var approveBtn = document.getElementById('approveSelectedBtn');
    var approveCountEl = document.getElementById('approveSelectedCount');
    var exportBtn = document.getElementById('exportSelectedExcelBtn');
    var exportCountEl = document.getElementById('exportSelectedCount');
    // even if there are no checkboxes (no recent tasks), allow period export

    function updateSelection() {
        var checked = document.querySelectorAll('.task-print-checkbox:checked');
        var n = checked.length;
        if (selectedCountEl) selectedCountEl.textContent = n;
        if (printBtn) printBtn.disabled = n === 0;
        if (exportCountEl) exportCountEl.textContent = n;
        // عد الأوردرات القابلة للاعتماد (غير معتمدة وليست شحن)
        var approvable = 0;
        checked.forEach(function(cb) {
            var approved = cb.getAttribute('data-approved');
            var orderType = (cb.getAttribute('data-order-type') || '').trim();
            var isShipping = (orderType === 'telegraph' || orderType === 'shipping_company');
            if (approved !== '1' && !isShipping) approvable++;
        });
        if (approveCountEl) approveCountEl.textContent = approvable;
        if (approveBtn) approveBtn.disabled = approvable === 0;
        if (selectAll) {
            selectAll.checked = checkboxes.length > 0 && checked.length === checkboxes.length;
            selectAll.indeterminate = checked.length > 0 && checked.length < checkboxes.length;
        }
    }

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            checkboxes.forEach(function(cb) { cb.checked = selectAll.checked; });
            updateSelection();
        });
    }
    checkboxes.forEach(function(cb) {
        cb.addEventListener('change', updateSelection);
    });

    if (printBtn) {
        printBtn.addEventListener('click', function() {
            var checked = document.querySelectorAll('.task-print-checkbox:checked');
            var ids = [];
            checked.forEach(function(cb) {
                var id = cb.value;
                if (id) ids.push(id);
            });
            if (ids.length === 0) return;
            var firstUrl = document.querySelector('.task-print-checkbox') && document.querySelector('.task-print-checkbox').getAttribute('data-print-url');
            var path = firstUrl ? firstUrl.split('?')[0] : 'print_task_receipt.php';
            var url = path + '?ids=' + ids.join(',');
            window.open(url, '_blank', 'noopener,noreferrer');
        });
    }

    if (exportBtn) {
        exportBtn.addEventListener('click', function() {
            // لا نريد شاشة التحميل لهذا النموذج (تقرير/تصدير)
            if (typeof window.resetPageLoading === 'function') window.resetPageLoading();

            var url = new URL(window.location.href);
            var fromEl = document.getElementById('recentTasksFilterOrderDateFrom');
            var toEl = document.getElementById('recentTasksFilterOrderDateTo');
            var fromVal = fromEl ? fromEl.value : '';
            var toVal = toEl ? toEl.value : '';

            // Modal لتحديد الفترة من التقويم بدل كتابة يدوي
            var modalId = 'exportTasksPeriodModal';
            var modalEl = document.getElementById(modalId);
            if (!modalEl) {
                modalEl = document.createElement('div');
                modalEl.id = modalId;
                modalEl.className = 'modal fade';
                modalEl.tabIndex = -1;
                modalEl.setAttribute('aria-hidden', 'true');
                modalEl.setAttribute('data-no-loading', 'true');
                modalEl.innerHTML =
                    '<div class="modal-dialog modal-dialog-centered">' +
                        '<div class="modal-content">' +
                            '<div class="modal-header">' +
                                '<h5 class="modal-title"><i class="bi bi-calendar-event me-2"></i>تحديد فترة التصدير</h5>' +
                                '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>' +
                            '</div>' +
                            '<div class="modal-body">' +
                                '<div class="row g-2 align-items-end">' +
                                    '<div class="col-12 col-md-6">' +
                                        '<label class="form-label mb-1">تاريخ من</label>' +
                                        '<input type="date" class="form-control form-control-sm" id="exportTasksPeriodFrom">' +
                                    '</div>' +
                                    '<div class="col-12 col-md-6">' +
                                        '<label class="form-label mb-1">تاريخ إلى</label>' +
                                        '<input type="date" class="form-control form-control-sm" id="exportTasksPeriodTo">' +
                                    '</div>' +
                                '</div>' +
                                '<div class="form-text mt-2">سيتم تصدير كل الطلبات داخل نطاق تاريخ الطلب.</div>' +
                            '</div>' +
                            '<div class="modal-footer">' +
                                '<button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">إلغاء</button>' +
                                '<button type="button" class="btn btn-primary btn-sm" id="exportTasksPeriodConfirmBtn" data-no-loading="true">تصدير</button>' +
                            '</div>' +
                        '</div>' +
                    '</div>';
                document.body.appendChild(modalEl);
            }

            var exportFromInput = document.getElementById('exportTasksPeriodFrom');
            var exportToInput = document.getElementById('exportTasksPeriodTo');
            if (exportFromInput) exportFromInput.value = fromVal || '';
            if (exportToInput) exportToInput.value = toVal || '';

            var confirmBtn = document.getElementById('exportTasksPeriodConfirmBtn');
            if (confirmBtn && !confirmBtn.dataset.bound) {
                confirmBtn.dataset.bound = '1';
                confirmBtn.addEventListener('click', function() {
                    // لا نريد شاشة التحميل لهذا النموذج (تقرير/تصدير)
                    if (typeof window.resetPageLoading === 'function') window.resetPageLoading();

                    var dateFrom = exportFromInput ? String(exportFromInput.value || '').trim() : '';
                    var dateTo = exportToInput ? String(exportToInput.value || '').trim() : '';

                    if (!dateFrom || !dateTo) {
                        alert('فضلاً اختر تاريخ من وتاريخ الى.');
                        return;
                    }
                    if (dateFrom > dateTo) {
                        alert('تاريخ من لازم يكون قبل او يساوي تاريخ الى.');
                        return;
                    }

                    url.searchParams.set('export_recent_tasks_print_period', '1');
                    url.searchParams.set('order_date_from', dateFrom);
                    url.searchParams.set('order_date_to', dateTo);
                    url.searchParams.delete('ids');
                    url.searchParams.delete('export_recent_tasks_print');

                    // فتح التصدير في تبويب جديد لتجنب تعليق شاشة التحميل في نفس الصفحة عند بدء تنزيل الملف
                    // (بعض المتصفحات/الاستضافات تنفّذ download بدون إعادة تحميل كاملة، فيظل overlay ظاهراً)
                    var exportUrl = url.toString();
                    var w = null;
                    try {
                        w = window.open(exportUrl, '_blank', 'noopener,noreferrer');
                    } catch (e) {
                        w = null;
                    }
                    // إن تم حظر الـ popup، نعود للطريقة التقليدية
                    if (!w) {
                        window.location.href = exportUrl;
                        return;
                    }
                    // أغلق المودال وأعد ضبط شاشة التحميل إن كانت مفعّلة
                    try {
                        var mm = (typeof bootstrap !== 'undefined' && bootstrap.Modal) ? bootstrap.Modal.getInstance(modalEl) : null;
                        if (mm) mm.hide();
                    } catch (e2) {}
                    if (typeof window.resetPageLoading === 'function') window.resetPageLoading();
                });
            }

            if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                var m = bootstrap.Modal.getOrCreateInstance(modalEl);
                m.show();
            } else {
                alert('حدث خطأ: Bootstrap Modal غير متاح.');
            }
        });
    }
    updateSelection();
})();

// لا حاجة لإعادة التحميل التلقائي - preventDuplicateSubmission يتولى ذلك

// ===== سجل مشتريات العميل في نموذج التعديل =====
function loadEditCustomerHistory(customerIdVal, customerName) {
    var loadingEl = document.getElementById('editCustomerHistoryLoading');
    var contentEl = document.getElementById('editCustomerHistoryContent');
    var nameSpan  = document.getElementById('editHistoryCardCustomerName');
    var histCard  = document.getElementById('editCustomerPurchaseHistoryCard');
    if (nameSpan)  nameSpan.textContent = customerName;
    if (loadingEl) loadingEl.style.display = '';
    if (contentEl) contentEl.innerHTML = '';
    if (histCard)  histCard.style.display = '';
    var params = new URLSearchParams(window.location.search);
    params.set('action', 'get_customer_purchase_history');
    params.set('customer_id', customerIdVal);
    fetch('?' + params.toString())
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (loadingEl) loadingEl.style.display = 'none';
            if (!contentEl) return;
            if (!data.success || !data.orders || data.orders.length === 0) {
                contentEl.innerHTML = '<div class="text-center text-muted py-3"><i class="bi bi-inbox fs-4 d-block mb-1"></i><small>لا توجد مشتريات سابقة</small></div>';
                return;
            }
            var html = '';
            data.orders.forEach(function(order) {
                var label = order.task_number ? '' + order.task_number : (order.title || ('' + order.task_id));
                var shortDate = '';
                if (order.date) { var parts = order.date.split('-'); shortDate = parts.length >= 3 ? parts[2] + '/' + parts[1] : order.date; }
                html += '<div class="card mb-2 border-0 border-bottom"><div class="px-2 pt-2 d-flex justify-content-between align-items-center">';
                html += '<span class="fw-semibold small"><i class="bi bi-receipt me-1 text-primary"></i>' + label + '</span>';
                html += '<span class="text-muted small">' + shortDate + '</span></div><div class="mb-1"></div>';
                if (order.products && order.products.length > 0) {
                    html += '<div class="table-responsive"><table class="table table-sm mb-1 small"><thead class="table-light"><tr><th>المنتج</th><th class="text-center">الكمية</th><th class="text-center">السعر</th></tr></thead><tbody>';
                    order.products.forEach(function(p) {
                        var pName3 = p.name || '-';
                        var pDetail3 = getProductDetail(p.name);
                        var pCell3 = pDetail3 && (pDetail3.code || pDetail3.id) ? makeIdBadge(pDetail3.code || pDetail3.id) + escHtml(pName3) : escHtml(pName3);
                        html += '<tr><td>' + pCell3 + '</td><td class="text-center">' + (p.quantity != null ? p.quantity + ' ' + (p.unit || '') : '—') + '</td><td class="text-center">' + (p.price != null ? parseFloat(p.price).toFixed(2) + ' ج.م' : '—') + '</td></tr>';
                    });
                    html += '</tbody></table></div>';
                } else { html += '<div class="px-2 pb-2 text-muted small">لا تفاصيل منتجات</div>'; }
                html += '</div>';
            });
            contentEl.innerHTML = html;
        })
        .catch(function() { if (loadingEl) loadingEl.style.display = 'none'; if (contentEl) contentEl.innerHTML = '<div class="alert alert-danger small mb-0">خطأ في تحميل السجل</div>'; });
}

// ===== تكرار أوردر موجود =====
function fillProductRowForDuplicate(row, product) {
    if (!row || !product) return;
    var nameInput = row.querySelector('.product-name-input');
    if (nameInput) nameInput.value = product.name || '';
    var qtyInput = row.querySelector('.product-quantity-input');
    if (qtyInput) qtyInput.value = (product.quantity != null) ? product.quantity : '';
    var unitInput = row.querySelector('.product-unit-input');
    if (unitInput) unitInput.value = product.unit || 'قطعة';
    var priceInput = row.querySelector('.product-price-input');
    if (priceInput) priceInput.value = (product.price != null) ? product.price : '';
    var lineTotalInput = row.querySelector('.product-line-total-input');
    if (lineTotalInput) lineTotalInput.value = (product.line_total != null) ? product.line_total : '';
}

window.duplicateOrderById = function() {
    var idInput = document.getElementById('duplicateOrderIdInput');
    var taskId = idInput ? parseInt(idInput.value, 10) : 0;
    if (!taskId || taskId <= 0) {
        alert('يرجى إدخال رقم أوردر صحيح');
        return;
    }
    var url = new URL(window.location.href);
    url.searchParams.set('action', 'get_task_for_edit');
    url.searchParams.set('task_id', String(taskId));
    fetch(url.toString())
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success || !data.task) {
                alert('لم يتم العثور على أوردر بهذا الرقم!');
                return;
            }
            var t = data.task;

            // الحقول الأساسية
            var typeEl = document.getElementById('taskTypeSelect');
            if (typeEl) typeEl.value = t.task_type || 'shop_order';

            var priorityEl = document.querySelector('#createTaskFormCollapse select[name="priority"]');
            if (priorityEl) priorityEl.value = t.priority || 'normal';

            var dueDateEl = document.querySelector('#createTaskFormCollapse input[name="due_date"]');
            if (dueDateEl) dueDateEl.value = t.due_date || '';

            // العميل
            var customerNameHidden = document.getElementById('submit_customer_name');
            if (customerNameHidden) customerNameHidden.value = t.customer_name || '';
            var localSearch = document.getElementById('local_customer_search_task');
            if (localSearch) localSearch.value = t.customer_name || '';
            var phoneEl = document.getElementById('submit_customer_phone');
            if (phoneEl) phoneEl.value = t.customer_phone || '';

            // عنوان الأوردر والملاحظات
            var titleEl = document.getElementById('createOrderTitle');
            if (titleEl) titleEl.value = t.order_title || '';
            var detailsEl = document.querySelector('#createTaskFormCollapse textarea[name="details"]');
            if (detailsEl) detailsEl.value = t.details || '';

            // الشحن والخصم والمدفوع مقدماً
            var shippingEl = document.getElementById('createTaskShippingFees');
            if (shippingEl) shippingEl.value = (t.shipping_fees != null) ? t.shipping_fees : 0;
            var discountEl = document.getElementById('createTaskDiscount');
            if (discountEl) discountEl.value = (t.discount != null) ? t.discount : 0;
            var advanceEl = document.getElementById('createTaskAdvancePayment');
            if (advanceEl) advanceEl.value = (t.advance_payment != null) ? t.advance_payment : 0;

            // المنتجات
            var products = (Array.isArray(t.products) && t.products.length > 0) ? t.products : [{}];
            var container = document.getElementById('productsContainer');
            if (container) {
                // إزالة الصفوف الزائدة، الإبقاء على الأول فقط
                container.querySelectorAll('.product-row').forEach(function(row, i) {
                    if (i > 0) row.remove();
                });
                // ملء الصف الأول
                var firstRow = container.querySelector('.product-row');
                if (firstRow) fillProductRowForDuplicate(firstRow, products[0]);
                // إضافة وملء الصفوف المتبقية
                for (var i = 1; i < products.length; i++) {
                    if (typeof window._addCreateProductRow === 'function') {
                        window._addCreateProductRow();
                    } else {
                        var addBtn = document.getElementById('addProductBtn');
                        if (addBtn) addBtn.click();
                    }
                    var allRows = container.querySelectorAll('.product-row');
                    var newRow = allRows[allRows.length - 1];
                    if (newRow) fillProductRowForDuplicate(newRow, products[i]);
                }
            }

            // تحديث الإجمالي وعدد القطع
            if (typeof window._updateCreateTaskSummary === 'function') {
                window._updateCreateTaskSummary();
            }
            if (typeof setCreateTelegraphPiecesValue === 'function') {
                setCreateTelegraphPiecesValue(t.tg_pieces_count || '');
            }

            // إغلاق بطاقة التكرار وفتح نموذج الإنشاء
            var dupCollapse = document.getElementById('duplicateOrderCollapse');
            if (dupCollapse) {
                var dupBs = bootstrap.Collapse.getInstance(dupCollapse);
                if (dupBs) dupBs.hide();
            }
            var createCollapse = document.getElementById('createTaskFormCollapse');
            if (createCollapse) {
                var createBs = bootstrap.Collapse.getInstance(createCollapse) || new bootstrap.Collapse(createCollapse, { toggle: false });
                createBs.show();
                setTimeout(function() { createCollapse.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 150);
            }
        })
        .catch(function() { alert('حدث خطأ أثناء جلب بيانات الأوردر!'); });
};

// نسخ رقم الطلب عند الضغط
document.addEventListener('click', function (e) {
    var el = e.target.closest('.copy-order-id');
    if (!el) return;
    var text = el.textContent.replace('#', '').trim();
    if (!text) return;
    navigator.clipboard.writeText(text).then(function () {
        var prev = el.textContent;
        el.textContent = 'تم النسخ ✓';
        el.style.color = '#198754';
        setTimeout(function () { el.textContent = prev; el.style.color = ''; }, 1200);
    });
});
</script>

<script>
(function () {
    'use strict';

    var currentDraftIdInput = document.getElementById('currentDraftId');
    var saveDraftBtn = document.getElementById('saveDraftBtn');

    // ====== حفظ المسودة ======
    if (saveDraftBtn) {
        saveDraftBtn.addEventListener('click', function () {
            var form = document.getElementById('createTaskForm') || saveDraftBtn.closest('form');
            if (!form) return;

            var formData = new FormData(form);
            formData.set('action', 'save_task_draft');
            var draftId = currentDraftIdInput ? currentDraftIdInput.value : '';
            if (draftId) formData.set('draft_id', draftId);

            // جمع المنتجات من DOM بترتيبها الفعلي لضمان الحفظ الصحيح
            var _pc = document.getElementById('productsContainer');
            if (_pc) {
                var _rows = _pc.querySelectorAll('.product-row');
                var _prods = [];
                _rows.forEach(function(_r) {
                    var _ts = _r.querySelector('.product-type-selector');
                    var _ni = _r.querySelector('.product-name-input');
                    var _qi = _r.querySelector('.product-quantity-input');
                    var _us = _r.querySelector('.product-unit-input');
                    var _cs = _r.querySelector('.product-category-input');
                    var _pi = _r.querySelector('.product-price-input');
                    var _lt = _r.querySelector('.product-line-total-input');
                    _prods.push({
                        item_type: _ts ? _ts.value : '',
                        name: _ni ? _ni.value : '',
                        quantity: _qi ? _qi.value : '',
                        unit: _us ? _us.value : '',
                        category: _cs ? _cs.value : '',
                        price: _pi ? _pi.value : '',
                        line_total: _lt ? _lt.value : ''
                    });
                });
                formData.set('products_json', JSON.stringify(_prods));
            }

            saveDraftBtn.disabled = true;
            saveDraftBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>جاري الحفظ...';

            fetch(window.location.href, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData,
                credentials: 'same-origin'
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                saveDraftBtn.disabled = false;
                saveDraftBtn.innerHTML = '<i class="bi bi-floppy me-1"></i>حفظ كمسودة';
                if (res.success) {
                    if (currentDraftIdInput) currentDraftIdInput.value = res.draft_id;
                    addOrUpdateDraftInList(res.draft_id, res.draft_name);
                    showDraftToast('تم حفظ المسودة: ' + res.draft_name);
                } else {
                    alert(res.error || 'تعذر حفظ المسودة');
                }
            })
            .catch(function () {
                saveDraftBtn.disabled = false;
                saveDraftBtn.innerHTML = '<i class="bi bi-floppy me-1"></i>حفظ كمسودة';
                alert('خطأ في الاتصال');
            });
        });
    }

    // ====== تحميل المسودة في النموذج ======
    window.loadDraft = function (draftId) {
        var fd = new FormData();
        fd.append('action', 'load_task_draft');
        fd.append('draft_id', draftId);

        fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd,
            credentials: 'same-origin'
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res.success) { alert(res.error || 'تعذر تحميل المسودة'); return; }
            var d = res.data;

            // 1. فتح النموذج
            var collapseEl = document.getElementById('createTaskFormCollapse');
            if (collapseEl && !collapseEl.classList.contains('show')) {
                if (typeof bootstrap !== 'undefined') bootstrap.Collapse.getOrCreateInstance(collapseEl).show();
                else collapseEl.classList.add('show');
            }

            // 2. نوع الأوردر
            var typeEl = document.getElementById('taskTypeSelect');
            if (typeEl) { typeEl.value = d.task_type || 'shop_order'; typeEl.dispatchEvent(new Event('change')); }

            // 3. الأولوية والتاريخ
            var prEl = document.querySelector('#createTaskFormCollapse select[name="priority"]');
            if (prEl) prEl.value = d.priority || 'normal';
            var ddEl = document.querySelector('#createTaskFormCollapse input[name="due_date"]');
            if (ddEl) ddEl.value = d.due_date || '';

            // 4. العميل (نفس منطق duplicateOrderById)
            var customerType = d.customer_type_radio_task || 'local';
            var radioEl = document.getElementById('ct_task_' + customerType);
            if (radioEl) { radioEl.checked = true; radioEl.dispatchEvent(new Event('change')); }

            var hiddenNameEl = document.getElementById('submit_customer_name');
            if (hiddenNameEl) hiddenNameEl.value = d.customer_name || '';
            var localSearchEl = document.getElementById('local_customer_search_task');
            if (localSearchEl) localSearchEl.value = d.customer_name || '';
            var repSearchEl = document.getElementById('rep_customer_search_task');
            if (repSearchEl && customerType === 'rep') repSearchEl.value = d.customer_name || '';
            var phoneEl2 = document.getElementById('submit_customer_phone');
            if (phoneEl2) phoneEl2.value = d.customer_phone || '';
            var lcIdEl = document.getElementById('local_customer_id_task');
            if (lcIdEl) lcIdEl.value = d.local_customer_id || '';

            // 5. عنوان الأوردر والملاحظات
            var titleEl2 = document.getElementById('createOrderTitle');
            if (titleEl2) titleEl2.value = d.order_title || '';
            var detEl = document.querySelector('#createTaskFormCollapse textarea[name="details"]');
            if (detEl) detEl.value = d.details || '';

            // 6. الشحن والخصم
            var sfEl = document.getElementById('createTaskShippingFees');
            if (sfEl) { sfEl.value = (d.shipping_fees != null) ? d.shipping_fees : 0; sfEl.dispatchEvent(new Event('input')); }
            var discEl = document.getElementById('createTaskDiscount');
            if (discEl) { discEl.value = (d.discount != null) ? d.discount : 0; discEl.dispatchEvent(new Event('input')); }

            // 7. حقول التليجراف
            setTimeout(function () {
                var govEl = document.getElementById('createGov');
                var govSearchEl = document.getElementById('createGovSearch');
                var govIdEl = document.getElementById('createGovId');
                var cityEl = document.getElementById('createCity');
                var citySearchEl = document.getElementById('createCitySearch');
                var cityIdEl = document.getElementById('createCityId');
                if (govEl) govEl.value = d.tg_governorate || '';
                if (govSearchEl) govSearchEl.value = d.tg_governorate || '';
                if (govIdEl) govIdEl.value = d.tg_gov_id || '';
                if (cityEl) cityEl.value = d.tg_city || '';
                if (citySearchEl) citySearchEl.value = d.tg_city || '';
                if (cityIdEl) cityIdEl.value = d.tg_city_id || '';
                var weightEl2 = document.getElementById('createTgWeight');
                if (weightEl2) weightEl2.value = d.tg_weight || '';
                if (typeof setCreateTelegraphPiecesValue === 'function') {
                    setCreateTelegraphPiecesValue(d.tg_pieces_count || '');
                }
                var parcelEl = document.getElementById('createTgParcelDesc');
                if (parcelEl) parcelEl.value = d.tg_parcel_desc || '';
            }, 200);

            // 8. المنتجات
            var container = document.getElementById('productsContainer');
            var draftProducts = d.products;
            if (draftProducts && !Array.isArray(draftProducts) && typeof draftProducts === 'object') {
                draftProducts = Object.values(draftProducts);
            }
            if (container && draftProducts && Array.isArray(draftProducts) && draftProducts.length > 0) {
                container.querySelectorAll('.product-row').forEach(function (r) { r.remove(); });
                if (typeof window._resetProductIndex === 'function') window._resetProductIndex();

                draftProducts.forEach(function (prod) {
                    if (typeof window._addCreateProductRow === 'function') window._addCreateProductRow();
                    else { var ab = document.getElementById('addProductBtn'); if (ab) ab.click(); }

                    var rows = container.querySelectorAll('.product-row');
                    var row = rows[rows.length - 1];
                    if (!row) return;

                    // أولاً: تعيين النوع لأنه يمسح الاسم عند التغيير
                    var typeSel = row.querySelector('.product-type-selector');
                    if (typeSel && prod.item_type) {
                        typeSel.value = prod.item_type;
                        typeSel.dispatchEvent(new Event('change'));
                    }

                    // ثانياً: تعيين الاسم بعد مسح النوع له
                    var nameEl = row.querySelector('.product-name-input');
                    if (nameEl) nameEl.value = prod.name || '';

                    // باقي الحقول
                    var unitEl = row.querySelector('.product-unit-input');
                    if (unitEl && prod.unit) unitEl.value = prod.unit;

                    var catEl = row.querySelector('.product-category-input');
                    if (catEl && prod.category) catEl.value = prod.category;

                    var qtyEl = row.querySelector('.product-quantity-input');
                    if (qtyEl && prod.quantity != null) qtyEl.value = prod.quantity;

                    var priceEl = row.querySelector('.product-price-input');
                    if (priceEl && prod.price != null) priceEl.value = prod.price;

                    var totalEl = row.querySelector('.product-line-total-input');
                    if (totalEl && prod.line_total != null) totalEl.value = prod.line_total;
                });
            }

            // 9. إعادة حساب الإجماليات
            setTimeout(function () {
                if (typeof window._updateCreateTaskSummary === 'function') window._updateCreateTaskSummary();
            }, 150);

            if (currentDraftIdInput) currentDraftIdInput.value = res.draft_id;
            showDraftToast('تم تحميل المسودة: ' + res.draft_name);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        })
        .catch(function () { alert('خطأ في الاتصال'); });
    };

    // ====== حذف المسودة ======
    window.deleteDraft = function (draftId) {
        if (!confirm('هل تريد حذف هذه المسودة نهائياً؟')) return;
        var fd = new FormData();
        fd.append('action', 'delete_task_draft');
        fd.append('draft_id', draftId);

        fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd,
            credentials: 'same-origin'
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res.success) {
                var item = document.getElementById('draft-item-' + draftId);
                if (item) item.remove();
                if (currentDraftIdInput && currentDraftIdInput.value == draftId) currentDraftIdInput.value = '';
                updateDraftsCount();
                showDraftToast('تم حذف المسودة');
            } else {
                alert(res.error || 'تعذر حذف المسودة');
            }
        })
        .catch(function () { alert('خطأ في الاتصال'); });
    };

    // ====== إضافة / تحديث مسودة في القائمة ======
    function addOrUpdateDraftInList(draftId, draftName) {
        var card = document.getElementById('taskDraftsCard');
        var list = document.getElementById('draftsList');
        if (!list) return;

        var existing = document.getElementById('draft-item-' + draftId);
        var now = new Date();
        var dateStr = ('0' + now.getDate()).slice(-2) + '/' + ('0' + (now.getMonth()+1)).slice(-2) + '/' + now.getFullYear() + ' ' + ('0' + now.getHours()).slice(-2) + ':' + ('0' + now.getMinutes()).slice(-2);
        var html = '<div class="task-draft-row">'
                 + '<div class="task-draft-info">'
                 + '<div class="task-draft-title"><i class="bi bi-file-earmark-text text-warning me-2"></i><strong>' + escHtml(draftName) + '</strong></div>'
                 + '<div class="task-draft-meta text-muted small">آخر تحديث: ' + dateStr + '</div>'
                 + '</div>'
                 + '<div class="task-draft-actions">'
                 + '<button type="button" class="btn btn-outline-primary btn-sm" onclick="loadDraft(' + draftId + ')"><i class="bi bi-pencil-square me-1"></i>استكمال</button>'
                 + '<button type="button" class="btn btn-outline-danger btn-sm" onclick="deleteDraft(' + draftId + ')"><i class="bi bi-trash me-1"></i>حذف</button>'
                 + '</div>'
                 + '</div>';

        if (existing) {
            existing.innerHTML = html;
        } else {
            var li = document.createElement('li');
            li.className = 'list-group-item task-draft-item';
            li.id = 'draft-item-' + draftId;
            li.innerHTML = html;
            list.insertBefore(li, list.firstChild);
        }

        if (card) card.style.display = '';
        updateDraftsCount();
    }

    function updateDraftsCount() {
        var list = document.getElementById('draftsList');
        var countEl = document.getElementById('draftsCount');
        var card = document.getElementById('taskDraftsCard');
        if (!list) return;
        var count = list.querySelectorAll('li').length;
        if (countEl) countEl.textContent = count;
        if (card) card.style.display = count === 0 ? 'none' : '';
    }

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function showDraftToast(msg) {
        var t = document.createElement('div');
        t.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:#198754;color:#fff;padding:10px 22px;border-radius:8px;z-index:9999;font-size:0.95rem;box-shadow:0 2px 8px rgba(0,0,0,.2);';
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(function () { t.remove(); }, 3000);
    }
})();
</script>

<script>
(function () {
    var statusMeta = {
        'pending':               { cls: 'warning',   label: 'معلقة' },
        'completed':             { cls: 'success',    label: 'مكتملة' },
        'with_delegate':         { cls: 'info',       label: 'مع المندوب' },
        'with_driver':           { cls: 'primary',    label: 'مع السائق' },
        'with_shipping_company': { cls: 'warning',    label: 'مع شركة الشحن' },
        'delivered':             { cls: 'success',    label: 'تم التوصيل' },
        'returned':              { cls: 'secondary',  label: 'تم الارجاع' },
        'cancelled':             { cls: 'danger',     label: 'ملغاة' }
    };

    function showStatusToast(msg, ok) {
        var t = document.createElement('div');
        t.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:' + (ok ? '#198754' : '#dc3545') + ';color:#fff;padding:10px 22px;border-radius:8px;z-index:9999;font-size:0.95rem;box-shadow:0 2px 8px rgba(0,0,0,.2);';
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(function () { t.remove(); }, 3000);
    }

    document.addEventListener('click', function (e) {
        var item = e.target.closest('.status-quick-change');
        if (!item) return;
        e.preventDefault();

        var taskId = item.dataset.taskId;
        var newStatus = item.dataset.status;

        // إيجاد الـ badge المقابل
        var badge = document.querySelector('.status-badge-dropdown[data-task-id="' + taskId + '"]');
        if (!badge) return;

        var meta = statusMeta[newStatus];
        if (!meta) return;

        // تعطيل مؤقت
        badge.style.opacity = '0.5';
        badge.style.pointerEvents = 'none';

        var formData = new FormData();
        formData.append('action', 'update_task_status');
        formData.append('task_id', taskId);
        formData.append('status', newStatus);

        fetch(window.location.pathname + '?page=production_tasks', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                // تحديث الـ badge
                badge.className = badge.className.replace(/\bbg-\S+/, 'bg-' + meta.cls);
                badge.dataset.currentStatus = newStatus;
                badge.innerHTML = meta.label + ' <i class="bi bi-chevron-down" style="font-size:0.65em;"></i>';

                // تحديث active في القائمة
                var allItems = badge.closest('.dropdown').querySelectorAll('.status-quick-change');
                allItems.forEach(function (i) {
                    i.classList.toggle('active', i.dataset.status === newStatus);
                });

                showStatusToast('تم تحديث الحالة', true);
            } else {
                showStatusToast(data.error || 'حدث خطأ', false);
            }
        })
        .catch(function () { showStatusToast('تعذر الاتصال بالخادم', false); })
        .finally(function () {
            badge.style.opacity = '';
            badge.style.pointerEvents = '';
        });
    });
})();

// إلحاق قوائم الـ dropdown بـ body لتجاوز overflow في الجدول
(function() {
    document.addEventListener('show.bs.dropdown', function(e) {
        var toggle = e.target;
        if (!toggle.closest('.dashboard-table-wrapper')) return;
        var menu = toggle.nextElementSibling;
        if (!menu || !menu.classList.contains('dropdown-menu')) return;
        menu._ddToggle = toggle;
        menu._originalParent = menu.parentNode;
        document.body.appendChild(menu);
    });

    document.addEventListener('shown.bs.dropdown', function() {
        var menu = document.body.querySelector('.dropdown-menu.show');
        if (!menu || !menu._ddToggle) return;
        var rect = menu._ddToggle.getBoundingClientRect();
        var menuHeight = menu.offsetHeight;
        var spaceBelow = window.innerHeight - rect.bottom;
        var spaceAbove = rect.top;
        menu.style.position = 'fixed';
        menu.style.zIndex = '9999';
        menu.style.margin = '0';
        menu.style.left = 'auto';
        menu.style.right = (window.innerWidth - rect.right) + 'px';
        if (spaceBelow >= menuHeight || spaceBelow >= spaceAbove) {
            menu.style.top = (rect.bottom + 2) + 'px';
            menu.style.bottom = 'auto';
        } else {
            menu.style.bottom = (window.innerHeight - rect.top + 2) + 'px';
            menu.style.top = 'auto';
        }
    });

    document.addEventListener('hide.bs.dropdown', function() {
        var menu = document.body.querySelector('.dropdown-menu.show');
        if (menu && menu._originalParent) {
            menu._originalParent.appendChild(menu);
            menu.removeAttribute('style');
            delete menu._originalParent;
            delete menu._ddToggle;
        }
    });

})();
</script>

<!-- Collapsible Cards with User Preferences -->
<script>
(function() {
    'use strict';
    
    // User preference storage key
    const CARD_STATES_KEY = 'production_tasks_card_states';
    
    // Initialize card states from localStorage
    function getCardStates() {
        try {
            const saved = localStorage.getItem(CARD_STATES_KEY);
            return saved ? JSON.parse(saved) : {};
        } catch (e) {
            return {};
        }
    }
    
    // Save card states to localStorage
    function saveCardStates(states) {
        try {
            localStorage.setItem(CARD_STATES_KEY, JSON.stringify(states));
        } catch (e) {
            console.warn('Could not save card states:', e);
        }
    }
    
    // Toggle card collapse state
    function toggleCardCollapse(button) {
        const targetId = button.getAttribute('data-target');
        const collapseId = targetId + 'Collapse';
        const collapseElement = document.getElementById(collapseId);
        const icon = button.querySelector('.toggle-icon');
        
        if (!collapseElement || !icon) return;
        
        const isExpanded = collapseElement.classList.contains('show');
        const cardStates = getCardStates();
        
        // Update UI
        if (isExpanded) {
            bootstrap.Collapse.getInstance(collapseElement)?.hide();
            icon.classList.remove('bi-chevron-up');
            icon.classList.add('bi-chevron-down');
            cardStates[targetId] = false;
        } else {
            bootstrap.Collapse.getInstance(collapseElement)?.show();
            icon.classList.remove('bi-chevron-down');
            icon.classList.add('bi-chevron-up');
            cardStates[targetId] = true;
        }
        
        // Save preference
        saveCardStates(cardStates);
    }
    
    // Initialize collapsible cards
    function initCollapsibleCards() {
        const toggleButtons = document.querySelectorAll('.toggle-cards-btn');
        const cardStates = getCardStates();
        
        toggleButtons.forEach(button => {
            const targetId = button.getAttribute('data-target');
            const collapseId = targetId + 'Collapse';
            const collapseElement = document.getElementById(collapseId);
            const icon = button.querySelector('.toggle-icon');
            
            if (!collapseElement || !icon) return;
            
            // Set initial state based on saved preference
            const isExpanded = cardStates[targetId] !== false; // Default to expanded
            
            if (isExpanded) {
                collapseElement.classList.add('show');
                icon.classList.remove('bi-chevron-down');
                icon.classList.add('bi-chevron-up');
            } else {
                collapseElement.classList.remove('show');
                icon.classList.remove('bi-chevron-up');
                icon.classList.add('bi-chevron-down');
            }
            
            // Initialize Bootstrap collapse
            new bootstrap.Collapse(collapseElement, {
                toggle: false
            });
            
            // Add click handler
            button.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                toggleCardCollapse(button);
            });
        });
        
        // Initialize tooltips
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    }
    
    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCollapsibleCards);
    } else {
        initCollapsibleCards();
    }
    
    // Make functions globally accessible
    window.toggleCardCollapse = toggleCardCollapse;
    window.initCollapsibleCards = initCollapsibleCards;
    
})();
</script>
