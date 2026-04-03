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

// التحقق من الصلاحيات
if (!in_array($userRole, ['manager', 'accountant', 'developer'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'لا تملك صلاحية لتنفيذ هذه العملية'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = db();
$action = $_POST['action'] ?? '';

try {
    if ($action === 'update_status') {
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $status = isset($_POST['status']) ? (string) $_POST['status'] : '';
        
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

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
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
