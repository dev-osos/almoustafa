<?php
/**
 * API: تسجيل المرتجعات (Register Returns)
 * Handles customer selection, item management, and return invoice creation
 */

define('ACCESS_ALLOWED', true);
define('IS_API_REQUEST', true);

ini_set('display_errors', 0);
set_error_handler(function($severity, $message, $file, $line) {
    error_log("Register Returns API Error [$severity]: $message in $file:$line");
    return true;
});
set_exception_handler(function($e) {
    error_log("Register Returns API Exception: " . $e->getMessage());
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

ensureReturnInvoiceTablesExist($db);

switch ($action) {
    case 'get_customers':
        if ($method !== 'GET') returnJson(['success' => false, 'message' => 'يجب استخدام GET'], 405);
        handleGetCustomers($db);
        break;

    case 'get_items':
        if ($method !== 'GET') returnJson(['success' => false, 'message' => 'يجب استخدام GET'], 405);
        handleGetItems($db);
        break;

    case 'submit_return':
        if ($method !== 'POST') returnJson(['success' => false, 'message' => 'يجب استخدام POST'], 405);
        handleSubmitReturn($db, $currentUser);
        break;

    case 'get_returns':
        if ($method !== 'GET') returnJson(['success' => false, 'message' => 'يجب استخدام GET'], 405);
        handleGetReturns($db);
        break;

    case 'get_return_details':
        if ($method !== 'GET') returnJson(['success' => false, 'message' => 'يجب استخدام GET'], 405);
        handleGetReturnDetails($db);
        break;

    default:
        returnJson(['success' => false, 'message' => 'إجراء غير معروف'], 400);
}

// ─── Handlers ───

function handleGetCustomers($db) {
    $type = trim($_GET['type'] ?? 'all');
    $search = trim($_GET['search'] ?? '');

    $customers = [];

    // Local customers
    if ($type === 'all' || $type === 'local') {
        $where = "status = 'active'";
        $params = [];
        if ($search !== '') {
            $where .= " AND (name LIKE ? OR phone LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        $rows = $db->query("SELECT id, name, phone, balance FROM local_customers WHERE $where ORDER BY name LIMIT 50", $params);
        if ($rows) {
            foreach ($rows as $r) {
                $customers[] = [
                    'id' => $r['id'],
                    'name' => $r['name'],
                    'phone' => $r['phone'] ?: '',
                    'balance' => floatval($r['balance']),
                    'type' => 'local'
                ];
            }
        }
    }

    // Delegate (sales rep) customers
    if ($type === 'all' || $type === 'delegate') {
        $where = "status = 'active'";
        $params = [];
        if ($search !== '') {
            $where .= " AND (name LIKE ? OR phone LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        $rows = $db->query("SELECT id, name, phone, balance FROM customers WHERE $where ORDER BY name LIMIT 50", $params);
        if ($rows) {
            foreach ($rows as $r) {
                $customers[] = [
                    'id' => $r['id'],
                    'name' => $r['name'],
                    'phone' => $r['phone'] ?: '',
                    'balance' => floatval($r['balance']),
                    'type' => 'delegate'
                ];
            }
        }
    }

    returnJson(['success' => true, 'customers' => $customers]);
}

function handleGetItems($db) {
    $department = trim($_GET['department'] ?? '');
    if (empty($department)) {
        returnJson(['success' => false, 'message' => 'يرجى تحديد القسم'], 400);
    }

    $items = [];

    switch ($department) {
        case 'raw_materials':
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

        case 'second_grade':
            $sgItems = $db->query("SELECT id, name, quantity, unit FROM products WHERE product_type = 'second_grade' AND status = 'active' ORDER BY name");
            if ($sgItems) {
                foreach ($sgItems as $item) {
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

        case 'product_molds':
            $moldsItems = $db->query(
                "SELECT pt.id as template_id, pt.product_name,
                        COALESCE(SUM(fp.quantity_produced), 0) as available_quantity,
                        MAX(fp.id) as fp_id
                 FROM product_templates pt
                 LEFT JOIN finished_products fp ON fp.product_name = pt.product_name
                 WHERE pt.status = 'active'
                 GROUP BY pt.id, pt.product_name
                 HAVING fp_id IS NOT NULL
                 ORDER BY pt.product_name"
            );
            if ($moldsItems) {
                foreach ($moldsItems as $item) {
                    $items[] = [
                        'id' => $item['fp_id'],
                        'name' => $item['product_name'],
                        'table' => 'finished_products',
                        'quantity_field' => 'quantity_produced',
                        'current_quantity' => floatval($item['available_quantity']),
                        'unit' => 'قطعة'
                    ];
                }
            }
            break;

        default:
            returnJson(['success' => false, 'message' => 'قسم غير معروف'], 400);
    }

    returnJson(['success' => true, 'items' => $items]);
}

function handleSubmitReturn($db, $currentUser) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    $customerId = intval($input['customer_id'] ?? 0);
    $customerType = trim($input['customer_type'] ?? '');
    $notes = trim((string)($input['notes'] ?? ''));
    $rows = $input['rows'] ?? [];

    if ($customerId <= 0 || !in_array($customerType, ['local', 'delegate'])) {
        returnJson(['success' => false, 'message' => 'يرجى اختيار العميل'], 400);
    }

    if (empty($rows) || !is_array($rows)) {
        returnJson(['success' => false, 'message' => 'يرجى إضافة صنف واحد على الأقل'], 400);
    }

    // Validate customer exists
    $customerTable = $customerType === 'local' ? 'local_customers' : 'customers';
    $customer = $db->queryOne("SELECT id, name, balance FROM `$customerTable` WHERE id = ?", [$customerId]);
    if (!$customer) {
        returnJson(['success' => false, 'message' => 'العميل غير موجود'], 400);
    }

    // Validate rows
    foreach ($rows as $i => $row) {
        if (empty($row['department']) || empty($row['item_id']) || empty($row['table']) || empty($row['quantity_field'])) {
            returnJson(['success' => false, 'message' => "بيانات الصف " . ($i + 1) . " غير مكتملة"], 400);
        }
        $qty = floatval($row['quantity'] ?? 0);
        if ($qty <= 0) {
            returnJson(['success' => false, 'message' => "الكمية في الصف " . ($i + 1) . " يجب أن تكون أكبر من صفر"], 400);
        }
        $unitPrice = floatval($row['unit_price'] ?? 0);
        if ($unitPrice < 0) {
            returnJson(['success' => false, 'message' => "سعر الوحدة في الصف " . ($i + 1) . " غير صالح"], 400);
        }
    }

    $conn = $db->getConnection();
    $conn->begin_transaction();

    try {
        // Create return invoice record
        $notesValue = ($notes !== '') ? $notes : null;
        $stmt = $conn->prepare("INSERT INTO return_invoices (invoice_number, customer_id, customer_type, notes, created_by, created_at) VALUES ('', ?, ?, ?, ?, NOW())");
        $stmt->bind_param('issi', $customerId, $customerType, $notesValue, $currentUser['id']);
        $stmt->execute();
        $returnId = $conn->insert_id;

        $invoiceNumber = (string)$returnId;
        $updateStmt = $conn->prepare("UPDATE return_invoices SET invoice_number = ? WHERE id = ?");
        $updateStmt->bind_param('si', $invoiceNumber, $returnId);
        $updateStmt->execute();

        $grandTotal = 0;
        $resultItems = [];

        $allowedTables = ['honey_stock', 'nuts_stock', 'sesame_stock', 'date_stock', 'herbal_stock', 'packaging_materials', 'products', 'finished_products'];
        $allowedFields = ['raw_honey_quantity', 'filtered_honey_quantity', 'quantity', 'converted_to_tahini_quantity', 'quantity_produced'];

        foreach ($rows as $row) {
            $department = $row['department'];
            $itemId = intval($row['item_id']);
            $table = $row['table'];
            $quantityField = $row['quantity_field'];
            $addedQty = floatval($row['quantity']);
            $unitPrice = floatval($row['unit_price'] ?? 0);
            $totalPrice = $addedQty * $unitPrice;

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

            // Update inventory (add returned items back)
            $db->execute(
                "UPDATE `$table` SET `$quantityField` = ? WHERE id = ?",
                [$afterQty, $itemId]
            );

            // Insert return item
            $db->execute(
                "INSERT INTO return_invoice_items (return_invoice_id, department, item_id, item_table, quantity_field, item_name, before_quantity, added_quantity, after_quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$returnId, $department, $itemId, $table, $quantityField, $row['item_name'] ?? '', $beforeQty, $addedQty, $afterQty, $unitPrice, $totalPrice]
            );

            $grandTotal += $totalPrice;

            $resultItems[] = [
                'item_name' => $row['item_name'] ?? '',
                'department' => $department,
                'before_quantity' => $beforeQty,
                'added_quantity' => $addedQty,
                'after_quantity' => $afterQty,
                'unit' => $row['unit'] ?? '',
                'unit_price' => $unitPrice,
                'total_price' => $totalPrice
            ];
        }

        // Update grand total
        $db->execute("UPDATE return_invoices SET grand_total = ? WHERE id = ?", [$grandTotal, $returnId]);

        // Update customer balance
        $currentBalance = floatval($customer['balance']);
        if ($currentBalance > 0) {
            // Customer has debt - reduce it
            $newBalance = $currentBalance - $grandTotal;
        } else {
            // Customer has zero or credit - add credit
            $newBalance = $currentBalance - $grandTotal;
        }

        $db->execute("UPDATE `$customerTable` SET balance = ? WHERE id = ?", [$newBalance, $customerId]);

        $conn->commit();

        logAudit(
            $currentUser['id'],
            'create_return_invoice',
            'return_invoice',
            $returnId,
            null,
            ['invoice_number' => $invoiceNumber, 'customer' => $customer['name'], 'grand_total' => $grandTotal, 'items_count' => count($rows)]
        );

        returnJson([
            'success' => true,
            'message' => 'تم تسجيل فاتورة المرتجعات بنجاح',
            'return_invoice' => [
                'id' => $returnId,
                'invoice_number' => $invoiceNumber,
                'customer_name' => $customer['name'],
                'customer_type' => $customerType,
                'grand_total' => $grandTotal,
                'balance_before' => $currentBalance,
                'balance_after' => $newBalance,
                'created_at' => date('Y-m-d H:i:s'),
                'created_by' => $currentUser['full_name'] ?? $currentUser['username'],
                'notes' => $notes,
                'items' => $resultItems
            ]
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        error_log("Return invoice submission error: " . $e->getMessage());
        returnJson(['success' => false, 'message' => 'حدث خطأ أثناء الحفظ: ' . $e->getMessage()], 500);
    }
}

function handleGetReturns($db) {
    $page = max(1, intval($_GET['page'] ?? 1));
    $perPage = 15;
    $offset = ($page - 1) * $perPage;

    $where = "1=1";
    $params = [];

    if (!empty($_GET['date_from'])) {
        $where .= " AND r.created_at >= ?";
        $params[] = $_GET['date_from'] . ' 00:00:00';
    }
    if (!empty($_GET['date_to'])) {
        $where .= " AND r.created_at <= ?";
        $params[] = $_GET['date_to'] . ' 23:59:59';
    }
    if (!empty($_GET['search'])) {
        $where .= " AND r.invoice_number LIKE ?";
        $params[] = '%' . $_GET['search'] . '%';
    }

    $totalRow = $db->queryOne("SELECT COUNT(*) as total FROM return_invoices r WHERE $where", $params);
    $total = $totalRow ? intval($totalRow['total']) : 0;

    $returns = $db->query(
        "SELECT r.*, u.full_name as created_by_name, u.username as created_by_username,
                (SELECT COUNT(*) FROM return_invoice_items WHERE return_invoice_id = r.id) as items_count
         FROM return_invoices r
         LEFT JOIN users u ON r.created_by = u.id
         WHERE $where
         ORDER BY r.created_at DESC
         LIMIT $perPage OFFSET $offset",
        $params
    );

    // Resolve customer names
    $resolvedReturns = [];
    if ($returns) {
        foreach ($returns as $ret) {
            $custTable = $ret['customer_type'] === 'local' ? 'local_customers' : 'customers';
            $cust = $db->queryOne("SELECT name FROM `$custTable` WHERE id = ?", [$ret['customer_id']]);
            $ret['customer_name'] = $cust ? $cust['name'] : 'غير معروف';
            $resolvedReturns[] = $ret;
        }
    }

    returnJson([
        'success' => true,
        'returns' => $resolvedReturns,
        'pagination' => [
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($total / $perPage)
        ]
    ]);
}

function handleGetReturnDetails($db) {
    $returnId = intval($_GET['id'] ?? 0);
    if ($returnId <= 0) {
        returnJson(['success' => false, 'message' => 'معرف غير صالح'], 400);
    }

    $ret = $db->queryOne(
        "SELECT r.*, u.full_name as created_by_name, u.username as created_by_username
         FROM return_invoices r
         LEFT JOIN users u ON r.created_by = u.id
         WHERE r.id = ?",
        [$returnId]
    );

    if (!$ret) {
        returnJson(['success' => false, 'message' => 'الفاتورة غير موجودة'], 404);
    }

    // Resolve customer name
    $custTable = $ret['customer_type'] === 'local' ? 'local_customers' : 'customers';
    $cust = $db->queryOne("SELECT name, phone, balance FROM `$custTable` WHERE id = ?", [$ret['customer_id']]);
    $ret['customer_name'] = $cust ? $cust['name'] : 'غير معروف';
    $ret['customer_phone'] = $cust ? ($cust['phone'] ?: '') : '';

    $items = $db->query("SELECT * FROM return_invoice_items WHERE return_invoice_id = ? ORDER BY id", [$returnId]);
    $ret['items'] = $items ?: [];

    returnJson(['success' => true, 'return_invoice' => $ret]);
}

// ─── Helpers ───

function ensureReturnInvoiceTablesExist($db) {
    $tableExists = $db->queryOne("SHOW TABLES LIKE 'return_invoices'");
    if (empty($tableExists)) {
        $db->getConnection()->query("CREATE TABLE IF NOT EXISTS `return_invoices` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `invoice_number` varchar(50) NOT NULL,
            `customer_id` int(11) NOT NULL,
            `customer_type` varchar(20) NOT NULL DEFAULT 'local',
            `notes` text DEFAULT NULL,
            `grand_total` decimal(15,2) DEFAULT 0.00,
            `created_by` int(11) NOT NULL,
            `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `customer_id` (`customer_id`),
            KEY `customer_type` (`customer_type`),
            KEY `created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    $notesColumnExists = $db->queryOne("SHOW COLUMNS FROM return_invoices LIKE 'notes'");
    if (empty($notesColumnExists)) {
        $db->getConnection()->query("ALTER TABLE `return_invoices` ADD COLUMN `notes` TEXT DEFAULT NULL AFTER `customer_type`");
    }

    $tableExists2 = $db->queryOne("SHOW TABLES LIKE 'return_invoice_items'");
    if (empty($tableExists2)) {
        $db->getConnection()->query("CREATE TABLE IF NOT EXISTS `return_invoice_items` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `return_invoice_id` int(11) NOT NULL,
            `department` varchar(50) NOT NULL,
            `item_id` int(11) NOT NULL,
            `item_table` varchar(50) NOT NULL,
            `quantity_field` varchar(50) NOT NULL,
            `item_name` varchar(255) DEFAULT '',
            `before_quantity` decimal(12,3) DEFAULT 0,
            `added_quantity` decimal(12,3) DEFAULT 0,
            `after_quantity` decimal(12,3) DEFAULT 0,
            `unit_price` decimal(15,2) DEFAULT 0.00,
            `total_price` decimal(15,2) DEFAULT 0.00,
            `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `return_invoice_id` (`return_invoice_id`),
            CONSTRAINT `return_invoice_items_ibfk_1` FOREIGN KEY (`return_invoice_id`) REFERENCES `return_invoices` (`id`) ON DELETE CASCADE
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
