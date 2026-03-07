<?php
/**
 * عرض مرفق معاملة مالية (صورة أو مستند) — يتطلب تسجيل دخول ودور مدير/محاسب/مطوّر
 */
define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

if (!isLoggedIn()) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'غير مصرح';
    exit;
}

$allowedRoles = ['manager', 'accountant', 'developer'];
$user = getCurrentUser();
$role = strtolower($user['role'] ?? '');
if (!in_array($role, $allowedRoles, true)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'غير مصرح';
    exit;
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$source = isset($_GET['source']) ? $_GET['source'] : '';

if ($id <= 0 || $source !== 'financial_transactions') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'معرف غير صحيح';
    exit;
}

$db = db();
$row = $db->queryOne("SELECT id, attachment_path FROM financial_transactions WHERE id = ? AND attachment_path IS NOT NULL AND attachment_path != ''", [$id]);
if (empty($row)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'الملف غير موجود';
    exit;
}

$attachmentPath = $row['attachment_path'];
$attachmentPath = str_replace(['../', '..\\', "\0"], '', $attachmentPath);
$baseDir = BASE_PATH . '/uploads';
$fullPath = realpath($baseDir . '/' . $attachmentPath);

if (!$fullPath || !is_file($fullPath)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'الملف غير موجود';
    exit;
}

$baseDirReal = realpath($baseDir);
if (!$baseDirReal || strpos($fullPath, $baseDirReal) !== 0) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'غير مصرح';
    exit;
}

$ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
$mimeTypes = [
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
    'pdf' => 'application/pdf',
    'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
];
$contentType = $mimeTypes[$ext] ?? 'application/octet-stream';
$fileName = basename($fullPath);

header('Content-Type: ' . $contentType);
header('Content-Length: ' . filesize($fullPath));
header('Cache-Control: private, max-age=3600');
header('Content-Disposition: inline; filename="' . basename($fileName) . '"');
readfile($fullPath);
exit;
