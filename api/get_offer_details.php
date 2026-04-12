<?php
/**
 * API: جلب تفاصيل عرض معين
 * Returns offer info + items as JSON
 */

if (!headers_sent()) {
    header('Content-Type: application/json; charset=UTF-8');
}

define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح.']);
    exit;
}

$currentUser = getCurrentUser();
$allowedRoles = ['manager', 'telegraph', 'developer'];
if (!in_array($currentUser['role'] ?? '', $allowedRoles)) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح لهذا الدور.']);
    exit;
}

$offerId = (int)($_GET['offer_id'] ?? 0);
if ($offerId <= 0) {
    echo json_encode(['success' => false, 'message' => 'معرّف العرض غير صحيح.']);
    exit;
}

try {
    $db = db();

    $offer = $db->queryOne(
        "SELECT id, name, price, notes, status, created_at FROM offers WHERE id = ?",
        [$offerId]
    );
    if (!$offer) {
        echo json_encode(['success' => false, 'message' => 'العرض غير موجود.']);
        exit;
    }

    $items = $db->query(
        "SELECT oi.id, oi.product_id, oi.quantity, oi.unit_price,
                p.name AS product_name, p.unit, p.category, p.unit_price AS default_unit_price
         FROM offer_items oi
         JOIN products p ON p.id = oi.product_id
         WHERE oi.offer_id = ?
         ORDER BY oi.id ASC",
        [$offerId]
    );

    echo json_encode([
        'success' => true,
        'offer'   => $offer,
        'items'   => $items,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log('get_offer_details: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'خطأ في الخادم.']);
}
exit;
