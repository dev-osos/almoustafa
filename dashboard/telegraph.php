<?php
/**
 * لوحة تحكم مسؤول تليجراف
 * صفحتان فقط: تسجيل الأوردرات (تليجراف)، طلبات الشحن (عرض TelegraphExm)
 */

define('ACCESS_ALLOWED', true);

while (ob_get_level() > 0) {
    ob_end_clean();
}
if (!ob_get_level()) {
    ob_start();
}

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Expires: 0');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/path_helper.php';

requireRole(['telegraph', 'manager', 'developer']);

$currentUser = getCurrentUser();
$db = db();
$page = trim($_GET['page'] ?? 'production_tasks');
if ($page === '' || $page === 'dashboard') {
    $page = 'production_tasks';
}

$isAjaxNavigation = (
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest' &&
    isset($_SERVER['HTTP_ACCEPT']) &&
    stripos($_SERVER['HTTP_ACCEPT'], 'text/html') !== false
);

if ($isAjaxNavigation) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/html; charset=utf-8');
    header('X-AJAX-Navigation: true');
    ob_start();
}

require_once __DIR__ . '/../includes/lang/' . getCurrentLanguage() . '.php';
$lang = isset($translations) ? $translations : [];
$pageTitle = 'لوحة مسؤول تليجراف';
$pageDescription = 'لوحة تحكم مسؤول تليجراف - تسجيل الأوردرات وطلبات الشحن - ' . APP_NAME;
?>
<?php if (!$isAjaxNavigation): ?>
<?php include __DIR__ . '/../templates/header.php'; ?>
<?php endif; ?>

            <?php if ($page === 'production_tasks'): ?>
                <?php
                $modulePath = __DIR__ . '/../modules/manager/production_tasks.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">صفحة تسجيل الأوردرات غير متاحة حالياً</div>';
                }
                ?>
            <?php elseif ($page === 'shipping_orders'): ?>
                <?php
                $modulePath = __DIR__ . '/../modules/manager/shipping_orders.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">صفحة طلبات الشحن غير متاحة حالياً</div>';
                }
                ?>
            <?php elseif ($page === 'offers'): ?>
                <?php
                $modulePath = __DIR__ . '/../modules/manager/offers.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">صفحة العروض غير متاحة حالياً</div>';
                }
                ?>
            <?php else: ?>
                <div class="container-fluid">
                    <div class="alert alert-warning">الصفحة غير موجودة</div>
                </div>
            <?php endif; ?>

<script>
window.currentUser = {
    id: <?php echo (int)($currentUser['id'] ?? 0); ?>,
    role: '<?php echo htmlspecialchars($currentUser['role'] ?? ''); ?>'
};
</script>

<?php if (!$isAjaxNavigation): ?>
<?php include __DIR__ . '/../templates/footer.php'; ?>
<?php else: ?>
<?php
$content = ob_get_clean();
if (preg_match('/<main[^>]*>(.*?)<\/main>/is', $content, $matches)) {
    echo $matches[1];
} else {
    echo $content;
}
exit;
?>
<?php endif; ?>
