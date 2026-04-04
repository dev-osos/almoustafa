<?php
/**
 * API لمعالجة عمليات مستلزمات الشركة
 */

define('ACCESS_ALLOWED', true);

if (!headers_sent()) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
}

if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'غير مصرح بالوصول'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/path_helper.php';
require_once __DIR__ . '/../includes/audit_log.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'يجب تسجيل الدخول أولاً'], JSON_UNESCAPED_UNICODE);
    exit;
}

$currentUser = getCurrentUser();
$userRole = strtolower($currentUser['role'] ?? '');
$allowedRoles = ['manager', 'accountant', 'production', 'developer'];

// التحقق من الصلاحيات
if (!in_array($userRole, $allowedRoles, true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'لا تملك صلاحية لتنفيذ هذه العملية'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = db();
$requestData = $_POST;

if (empty($requestData)) {
    $rawBody = file_get_contents('php://input');
    if (is_string($rawBody) && trim($rawBody) !== '') {
        $decodedBody = json_decode($rawBody, true);
        if (is_array($decodedBody)) {
            $requestData = $decodedBody;
        }
    }
}

$action = $requestData['action'] ?? '';

try {
    if ($action === 'save_supplies') {
        $rawItems = $requestData['items'] ?? '[]';
        $items = json_decode((string) $rawItems, true);

        if (empty($items) || !is_array($items)) {
            echo json_encode(['success' => false, 'message' => 'يرجى إضافة عنصر واحد على الأقل'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $validatedItems = [];
        foreach ($items as $item) {
            $name = trim((string) ($item['name'] ?? ''));
            $quantity = isset($item['quantity']) ? (float) $item['quantity'] : 0;
            $priceRaw = $item['price'] ?? null;

            if ($name === '' || $quantity <= 0) {
                echo json_encode(['success' => false, 'message' => 'الرجاء التحقق من صحة البيانات المدخلة لكل عنصر'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if ($priceRaw !== null && $priceRaw !== '' && !is_numeric($priceRaw)) {
                echo json_encode(['success' => false, 'message' => 'السعر المدخل غير صحيح'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $validatedItems[] = [
                'name' => $name,
                'quantity' => $quantity,
                'price' => ($priceRaw === null || $priceRaw === '') ? null : (float) $priceRaw,
            ];
        }

        $status = (string) ($requestData['status'] ?? 'pending');
        if (!in_array($status, ['pending', 'purchased'], true)) {
            $status = 'pending';
        }

        if ($userRole === 'production') {
            $status = 'pending';
        }

        $tableExists = $db->queryOne("SHOW TABLES LIKE 'company_supplies'");
        if (empty($tableExists)) {
            $db->execute("
                CREATE TABLE IF NOT EXISTS `company_supplies` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `items` longtext NOT NULL,
                  `status` varchar(20) DEFAULT 'pending',
                  `created_by` int(11) NOT NULL,
                  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
                  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `status` (`status`),
                  KEY `created_by` (`created_by`),
                  KEY `created_at` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        $db->execute(
            "INSERT INTO company_supplies (items, status, created_by) VALUES (?, ?, ?)",
            [json_encode($validatedItems, JSON_UNESCAPED_UNICODE), $status, $currentUser['id']]
        );

        $newId = method_exists($db, 'getLastInsertId') ? (int) $db->getLastInsertId() : 0;

        addAuditLog(
            $currentUser['id'],
            'company_supplies_create',
            'company_supplies',
            'create',
            'تم حفظ مستلزمات جديدة',
            json_encode(['items_count' => count($validatedItems), 'status' => $status], JSON_UNESCAPED_UNICODE)
        );

        echo json_encode([
            'success' => true,
            'message' => 'تم حفظ المستلزمات بنجاح',
            'supply_id' => $newId,
            'status' => $status,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } elseif ($action === 'update_status') {
        $id = isset($requestData['id']) ? intval($requestData['id']) : 0;
        $status = isset($requestData['status']) ? (string) $requestData['status'] : '';
        
        if ($id <= 0 || !in_array($status, ['pending', 'purchased'], true)) {
            echo json_encode(['success' => false, 'message' => 'بيانات غير صحيحة'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // التحقق من وجود السجل
        $supply = $db->queryOne("SELECT id FROM company_supplies WHERE id = ?", [$id]);
        if (!$supply) {
            echo json_encode(['success' => false, 'message' => 'السجل غير موجود'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // تحديث الحالة
        $db->execute(
            "UPDATE company_supplies SET status = ? WHERE id = ?",
            [$status, $id]
        );
        
        // تسجيل المراجعة
        addAuditLog(
            $currentUser['id'],
            'company_supplies_update',
            'company_supplies',
            'update',
            'تم تحديث حالة الإيصال',
            json_encode(['supply_id' => $id, 'status' => $status])
        );
        
        echo json_encode(['success' => true, 'message' => 'تم تحديث الحالة بنجاح'], JSON_UNESCAPED_UNICODE);
        exit;
    } elseif ($action === 'delete_supply') {
        if (!in_array($userRole, ['manager', 'accountant', 'developer'], true)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'لا تملك صلاحية الحذف'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $id = isset($requestData['id']) ? intval($requestData['id']) : 0;
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'بيانات غير صحيحة'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // التحقق من وجود السجل
        $supply = $db->queryOne("SELECT id FROM company_supplies WHERE id = ?", [$id]);
        if (!$supply) {
            echo json_encode(['success' => false, 'message' => 'السجل غير موجود'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // حذف السجل
        $db->execute("DELETE FROM company_supplies WHERE id = ?", [$id]);

        addAuditLog(
            $currentUser['id'],
            'company_supplies_delete',
            'company_supplies',
            'delete',
            'تم حذف الإيصال',
            json_encode(['supply_id' => $id])
        );

        echo json_encode(['success' => true, 'message' => 'تم حذف الإيصال بنجاح'], JSON_UNESCAPED_UNICODE);
        exit;
    } else {
        echo json_encode(['success' => false, 'message' => 'الإجراء غير معروف'], JSON_UNESCAPED_UNICODE);
        exit;
    }
} catch (Exception $e) {
    error_log('Error in company_supplies_api: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'حدث خطأ في الخادم'], JSON_UNESCAPED_UNICODE);
    exit;
}
