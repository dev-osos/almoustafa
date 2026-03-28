<?php
/**
 * API: Inbound Supplies (تسجيل الواردات)
 * Handles fetching items by department and submitting supply records
 */

define('ACCESS_ALLOWED', true);
define('IS_API_REQUEST', true);

ini_set('display_errors', 0);
set_error_handler(function($severity, $message, $file, $line) {
    error_log("Inbound Supplies API Error [$severity]: $message in $file:$line");
    return true;
});
set_exception_handler(function($e) {
    error_log("Inbound Supplies API Exception: " . $e->getMessage());
    returnJson(['success' => false, 'message' => 'حدث خطأ غير متوقع'], 500);
});

while (ob_get_level() > 0) {
    @ob_end_clean();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit_log.php';

if (!isLoggedIn()) {
    returnJson(['success' => false, 'message' => 'انتهت جلسة العمل. يرجى تسجيل الدخول مرة أخرى'], 401);
}

$currentUser = getCurrentUser();
$allowedRoles = ['manager', 'accountant', 'production', 'developer'];
if (!in_array($currentUser['role'], $allowedRoles)) {
    returnJson(['success' => false, 'message' => 'ليس لديك صلاحية للوصول'], 403);
}

$db = db();
$action = $_GET['action'] ?? $_POST['action'] ?? null;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Ensure tables exist
ensureTablesExist($db);

switch ($action) {
    case 'get_items':
        if ($method !== 'GET') {
            returnJson(['success' => false, 'message' => 'يجب استخدام GET'], 405);
        }
        handleGetItems($db);
        break;

    case 'submit_supply':
        if ($method !== 'POST') {
            returnJson(['success' => false, 'message' => 'يجب استخدام POST'], 405);
        }
        handleSubmitSupply($db, $currentUser);
        break;

    case 'get_supplies':
        if ($method !== 'GET') {
            returnJson(['success' => false, 'message' => 'يجب استخدام GET'], 405);
        }
        handleGetSupplies($db);
        break;

    case 'get_supply_details':
        if ($method !== 'GET') {
            returnJson(['success' => false, 'message' => 'يجب استخدام GET'], 405);
        }
        handleGetSupplyDetails($db);
        break;

    default:
        returnJson(['success' => false, 'message' => 'إجراء غير معروف'], 400);
}

// ─── Handlers ───

function handleGetItems($db) {
    $department = trim($_GET['department'] ?? '');
    if (empty($department)) {
        returnJson(['success' => false, 'message' => 'يرجى تحديد القسم'], 400);
    }

    $items = [];

    switch ($department) {
        case 'raw_materials':
            // Honey stock items (weight-tracked)
            $honeyItems = $db->query("SELECT id, supplier_id, honey_variety, raw_honey_quantity, filtered_honey_quantity FROM honey_stock ORDER BY honey_variety");
            if ($honeyItems) {
                foreach ($honeyItems as $item) {
                    $supplierName = '';
                    if ($item['supplier_id']) {
                        $supplier = $db->queryOne("SELECT name FROM suppliers WHERE id = ?", [$item['supplier_id']]);
                        $supplierName = $supplier ? $supplier['name'] : '';
                    }
                    $variety = $item['honey_variety'] ?: 'عسل';
                    $items[] = [
                        'id' => $item['id'],
                        'name' => $variety . ($supplierName ? " - $supplierName" : ''),
                        'table' => 'honey_stock',
                        'quantity_field' => 'raw_honey_quantity',
                        'current_quantity' => floatval($item['raw_honey_quantity']),
                        'unit' => 'كجم'
                    ];
                    $items[] = [
                        'id' => $item['id'],
                        'name' => $variety . ' (مصفى)' . ($supplierName ? " - $supplierName" : ''),
                        'table' => 'honey_stock',
                        'quantity_field' => 'filtered_honey_quantity',
                        'current_quantity' => floatval($item['filtered_honey_quantity']),
                        'unit' => 'كجم'
                    ];
                }
            }

            // Nuts stock
            $nutsItems = $db->query("SELECT id, supplier_id, nut_type, quantity FROM nuts_stock ORDER BY nut_type");
            if ($nutsItems) {
                foreach ($nutsItems as $item) {
                    $supplierName = '';
                    if ($item['supplier_id']) {
                        $supplier = $db->queryOne("SELECT name FROM suppliers WHERE id = ?", [$item['supplier_id']]);
                        $supplierName = $supplier ? $supplier['name'] : '';
                    }
                    $items[] = [
                        'id' => $item['id'],
                        'name' => ($item['nut_type'] ?: 'مكسرات') . ($supplierName ? " - $supplierName" : ''),
                        'table' => 'nuts_stock',
                        'quantity_field' => 'quantity',
                        'current_quantity' => floatval($item['quantity']),
                        'unit' => 'كجم'
                    ];
                }
            }

            // Sesame stock
            $sesameItems = $db->query("SELECT s.id, s.supplier_id, s.converted_to_tahini_quantity, sup.name as supplier_name FROM sesame_stock s LEFT JOIN suppliers sup ON s.supplier_id = sup.id ORDER BY s.id");
            if ($sesameItems) {
                foreach ($sesameItems as $item) {
                    $items[] = [
                        'id' => $item['id'],
                        'name' => 'سمسم' . ($item['supplier_name'] ? " - {$item['supplier_name']}" : ''),
                        'table' => 'sesame_stock',
                        'quantity_field' => 'converted_to_tahini_quantity',
                        'current_quantity' => floatval($item['converted_to_tahini_quantity']),
                        'unit' => 'كجم'
                    ];
                }
            }

            // Date stock
            $dateItems = $db->query("SELECT d.id, d.supplier_id, d.date_type, sup.name as supplier_name FROM date_stock d LEFT JOIN suppliers sup ON d.supplier_id = sup.id ORDER BY d.date_type");
            if ($dateItems) {
                foreach ($dateItems as $item) {
                    $items[] = [
                        'id' => $item['id'],
                        'name' => ($item['date_type'] ?: 'بلح') . ($item['supplier_name'] ? " - {$item['supplier_name']}" : ''),
                        'table' => 'date_stock',
                        'quantity_field' => 'quantity',
                        'current_quantity' => 0,
                        'unit' => 'كجم'
                    ];
                }
            }

            // Herbal stock
            $herbalItems = $db->query("SELECT h.id, h.supplier_id, h.herbal_type, h.quantity, sup.name as supplier_name FROM herbal_stock h LEFT JOIN suppliers sup ON h.supplier_id = sup.id ORDER BY h.herbal_type");
            if ($herbalItems) {
                foreach ($herbalItems as $item) {
                    $items[] = [
                        'id' => $item['id'],
                        'name' => ($item['herbal_type'] ?: 'أعشاب') . ($item['supplier_name'] ? " - {$item['supplier_name']}" : ''),
                        'table' => 'herbal_stock',
                        'quantity_field' => 'quantity',
                        'current_quantity' => floatval($item['quantity']),
                        'unit' => 'كجم'
                    ];
                }
            }
            break;

        case 'packaging':
            $packagingItems = $db->query("SELECT id, name, alias, quantity, unit FROM packaging_materials WHERE status = 'active' ORDER BY name");
            if ($packagingItems) {
                foreach ($packagingItems as $item) {
                    $displayName = $item['name'];
                    if (!empty($item['alias'])) {
                        $displayName .= " ({$item['alias']})";
                    }
                    $items[] = [
                        'id' => $item['id'],
                        'name' => $displayName,
                        'table' => 'packaging_materials',
                        'quantity_field' => 'quantity',
                        'current_quantity' => floatval($item['quantity']),
                        'unit' => $item['unit'] ?: 'قطعة'
                    ];
                }
            }
            break;

        case 'external_products':
            $externalItems = $db->query("SELECT id, name, quantity, unit FROM products WHERE product_type = 'external' AND status = 'active' ORDER BY name");
            if ($externalItems) {
                foreach ($externalItems as $item) {
                    $items[] = [
                        'id' => $item['id'],
                        'name' => $item['name'],
                        'table' => 'products',
                        'quantity_field' => 'quantity',
                        'current_quantity' => floatval($item['quantity']),
                        'unit' => $item['unit'] ?: 'قطعة'
                    ];
                }
            }
            break;

        default:
            returnJson(['success' => false, 'message' => 'قسم غير معروف'], 400);
    }

    returnJson(['success' => true, 'items' => $items]);
}

function handleSubmitSupply($db, $currentUser) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    $rows = $input['rows'] ?? [];
    if (empty($rows) || !is_array($rows)) {
        returnJson(['success' => false, 'message' => 'يرجى إضافة عنصر واحد على الأقل'], 400);
    }

    // Validate all rows first
    foreach ($rows as $i => $row) {
        if (empty($row['department']) || empty($row['item_id']) || empty($row['table']) || empty($row['quantity_field'])) {
            returnJson(['success' => false, 'message' => "بيانات الصف " . ($i + 1) . " غير مكتملة"], 400);
        }
        $qty = floatval($row['quantity'] ?? 0);
        if ($qty <= 0) {
            returnJson(['success' => false, 'message' => "الكمية في الصف " . ($i + 1) . " يجب أن تكون أكبر من صفر"], 400);
        }
    }

    $conn = $db->getConnection();
    $conn->begin_transaction();

    try {
        // Insert the supply record to get the ID
        $tempNumber = '';
        $userId = (int)$currentUser['id'];
        $stmt = $conn->prepare("INSERT INTO supplies (supply_number, created_at, created_by) VALUES (?, NOW(), ?)");
        $stmt->bind_param('si', $tempNumber, $userId);
        $stmt->execute();
        $supplyId = $conn->insert_id;

        // Use the ID as the supply number
        $supplyNumber = (string)$supplyId;

        // Update the supply record with the final number
        $updateStmt = $conn->prepare("UPDATE supplies SET supply_number = ? WHERE id = ?");
        $updateStmt->bind_param('si', $supplyNumber, $supplyId);
        $updateStmt->execute();

        $resultItems = [];

        // Process each row
        foreach ($rows as $row) {
            $department = $row['department'];
            $itemId = intval($row['item_id']);
            $table = $row['table'];
            $quantityField = $row['quantity_field'];
            $addedQty = floatval($row['quantity']);

            // Whitelist allowed tables and fields to prevent SQL injection
            $allowedTables = ['honey_stock', 'nuts_stock', 'sesame_stock', 'date_stock', 'herbal_stock', 'packaging_materials', 'products'];
            $allowedFields = ['raw_honey_quantity', 'filtered_honey_quantity', 'quantity', 'converted_to_tahini_quantity'];

            if (!in_array($table, $allowedTables) || !in_array($quantityField, $allowedFields)) {
                throw new Exception("جدول أو حقل غير مسموح به");
            }

            // Get current quantity
            $currentRecord = $db->queryOne("SELECT `$quantityField` FROM `$table` WHERE id = ?", [$itemId]);
            if (!$currentRecord) {
                throw new Exception("العنصر غير موجود: $itemId في $table");
            }

            $beforeQty = floatval($currentRecord[$quantityField]);
            $afterQty = $beforeQty + $addedQty;

            // Update inventory
            $db->execute(
                "UPDATE `$table` SET `$quantityField` = ? WHERE id = ?",
                [$afterQty, $itemId]
            );

            // Insert supply item
            $db->execute(
                "INSERT INTO supply_items (supply_id, department, item_id, item_table, quantity_field, before_quantity, added_quantity, after_quantity) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$supplyId, $department, $itemId, $table, $quantityField, $beforeQty, $addedQty, $afterQty]
            );

            $resultItems[] = [
                'item_name' => $row['item_name'] ?? '',
                'department' => $department,
                'before_quantity' => $beforeQty,
                'added_quantity' => $addedQty,
                'after_quantity' => $afterQty,
                'unit' => $row['unit'] ?? ''
            ];
        }

        $conn->commit();

        // Audit log
        logAudit(
            $currentUser['id'],
            'create_supply',
            'supply',
            $supplyId,
            null,
            ['supply_number' => $supplyNumber, 'items_count' => count($rows)]
        );

        returnJson([
            'success' => true,
            'message' => 'تم تسجيل الواردات بنجاح',
            'supply' => [
                'id' => $supplyId,
                'supply_number' => $supplyNumber,
                'created_at' => date('Y-m-d H:i:s'),
                'created_by' => $currentUser['full_name'] ?? $currentUser['username'],
                'items' => $resultItems
            ]
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        error_log("Supply submission error: " . $e->getMessage());
        returnJson(['success' => false, 'message' => 'حدث خطأ أثناء الحفظ: ' . $e->getMessage()], 500);
    }
}

function handleGetSupplies($db) {
    $page = max(1, intval($_GET['page'] ?? 1));
    $perPage = 15;
    $offset = ($page - 1) * $perPage;

    $where = "1=1";
    $params = [];

    // Filter by date
    if (!empty($_GET['date_from'])) {
        $where .= " AND s.created_at >= ?";
        $params[] = $_GET['date_from'] . ' 00:00:00';
    }
    if (!empty($_GET['date_to'])) {
        $where .= " AND s.created_at <= ?";
        $params[] = $_GET['date_to'] . ' 23:59:59';
    }

    // Search by supply number
    if (!empty($_GET['search'])) {
        $where .= " AND s.supply_number LIKE ?";
        $params[] = '%' . $_GET['search'] . '%';
    }

    $totalRow = $db->queryOne(
        "SELECT COUNT(*) as total FROM supplies s WHERE $where",
        $params
    );
    $total = $totalRow ? intval($totalRow['total']) : 0;

    $supplies = $db->query(
        "SELECT s.*, u.full_name as created_by_name, u.username as created_by_username,
                (SELECT COUNT(*) FROM supply_items WHERE supply_id = s.id) as items_count
         FROM supplies s
         LEFT JOIN users u ON s.created_by = u.id
         WHERE $where
         ORDER BY s.created_at DESC
         LIMIT $perPage OFFSET $offset",
        $params
    );

    returnJson([
        'success' => true,
        'supplies' => $supplies ?: [],
        'pagination' => [
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($total / $perPage)
        ]
    ]);
}

function handleGetSupplyDetails($db) {
    $supplyId = intval($_GET['id'] ?? 0);
    if ($supplyId <= 0) {
        returnJson(['success' => false, 'message' => 'معرف غير صالح'], 400);
    }

    $supply = $db->queryOne(
        "SELECT s.*, u.full_name as created_by_name, u.username as created_by_username
         FROM supplies s
         LEFT JOIN users u ON s.created_by = u.id
         WHERE s.id = ?",
        [$supplyId]
    );

    if (!$supply) {
        returnJson(['success' => false, 'message' => 'السجل غير موجود'], 404);
    }

    $items = $db->query(
        "SELECT * FROM supply_items WHERE supply_id = ? ORDER BY id",
        [$supplyId]
    );

    // Resolve item names
    $resolvedItems = [];
    if ($items) {
        foreach ($items as $item) {
            $itemName = resolveItemName($db, $item['item_table'], $item['item_id'], $item['quantity_field']);
            $item['item_name'] = $itemName;
            $item['department_label'] = getDepartmentLabel($item['department']);
            $resolvedItems[] = $item;
        }
    }

    $supply['items'] = $resolvedItems;
    returnJson(['success' => true, 'supply' => $supply]);
}

// ─── Helpers ───

function resolveItemName($db, $table, $itemId, $quantityField) {
    switch ($table) {
        case 'honey_stock':
            $row = $db->queryOne("SELECT h.honey_variety, s.name as supplier_name FROM honey_stock h LEFT JOIN suppliers s ON h.supplier_id = s.id WHERE h.id = ?", [$itemId]);
            if ($row) {
                $name = $row['honey_variety'] ?: 'عسل';
                if ($quantityField === 'filtered_honey_quantity') {
                    $name .= ' (مصفى)';
                }
                if ($row['supplier_name']) {
                    $name .= " - {$row['supplier_name']}";
                }
                return $name;
            }
            return 'عسل #' . $itemId;

        case 'nuts_stock':
            $row = $db->queryOne("SELECT n.nut_type, s.name as supplier_name FROM nuts_stock n LEFT JOIN suppliers s ON n.supplier_id = s.id WHERE n.id = ?", [$itemId]);
            return $row ? (($row['nut_type'] ?: 'مكسرات') . ($row['supplier_name'] ? " - {$row['supplier_name']}" : '')) : 'مكسرات #' . $itemId;

        case 'sesame_stock':
            $row = $db->queryOne("SELECT s.name as supplier_name FROM sesame_stock ss LEFT JOIN suppliers s ON ss.supplier_id = s.id WHERE ss.id = ?", [$itemId]);
            return 'سمسم' . ($row && $row['supplier_name'] ? " - {$row['supplier_name']}" : '');

        case 'date_stock':
            $row = $db->queryOne("SELECT d.date_type, s.name as supplier_name FROM date_stock d LEFT JOIN suppliers s ON d.supplier_id = s.id WHERE d.id = ?", [$itemId]);
            return $row ? (($row['date_type'] ?: 'بلح') . ($row['supplier_name'] ? " - {$row['supplier_name']}" : '')) : 'بلح #' . $itemId;

        case 'herbal_stock':
            $row = $db->queryOne("SELECT h.herbal_type, s.name as supplier_name FROM herbal_stock h LEFT JOIN suppliers s ON h.supplier_id = s.id WHERE h.id = ?", [$itemId]);
            return $row ? (($row['herbal_type'] ?: 'أعشاب') . ($row['supplier_name'] ? " - {$row['supplier_name']}" : '')) : 'أعشاب #' . $itemId;

        case 'packaging_materials':
            $row = $db->queryOne("SELECT name, alias FROM packaging_materials WHERE id = ?", [$itemId]);
            if ($row) {
                return $row['name'] . (!empty($row['alias']) ? " ({$row['alias']})" : '');
            }
            return 'مادة تعبئة #' . $itemId;

        case 'products':
            $row = $db->queryOne("SELECT name FROM products WHERE id = ?", [$itemId]);
            return $row ? $row['name'] : 'منتج #' . $itemId;

        default:
            return 'عنصر #' . $itemId;
    }
}

function getDepartmentLabel($dept) {
    $labels = [
        'raw_materials' => 'خامات',
        'packaging' => 'أدوات تعبئة',
        'external_products' => 'منتجات خارجية'
    ];
    return $labels[$dept] ?? $dept;
}

function ensureTablesExist($db) {
    $tableExists = $db->queryOne("SHOW TABLES LIKE 'supplies'");
    if (empty($tableExists)) {
        $db->getConnection()->query("CREATE TABLE IF NOT EXISTS `supplies` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `supply_number` varchar(50) NOT NULL,
            `created_by` int(11) NOT NULL,
            `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `supply_number` (`supply_number`),
            KEY `created_by` (`created_by`),
            KEY `created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    $tableExists2 = $db->queryOne("SHOW TABLES LIKE 'supply_items'");
    if (empty($tableExists2)) {
        $db->getConnection()->query("CREATE TABLE IF NOT EXISTS `supply_items` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `supply_id` int(11) NOT NULL,
            `department` varchar(50) NOT NULL,
            `item_id` int(11) NOT NULL,
            `item_table` varchar(50) NOT NULL,
            `quantity_field` varchar(50) NOT NULL,
            `before_quantity` decimal(12,3) DEFAULT 0,
            `added_quantity` decimal(12,3) DEFAULT 0,
            `after_quantity` decimal(12,3) DEFAULT 0,
            `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `supply_id` (`supply_id`),
            CONSTRAINT `supply_items_ibfk_1` FOREIGN KEY (`supply_id`) REFERENCES `supplies` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

function returnJson(array $data, int $status = 200): void {
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    @header('Content-Type: application/json; charset=utf-8', true);
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
