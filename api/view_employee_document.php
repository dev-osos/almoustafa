<?php
/**
 * عرض مستند موظف عبر نقطة آمنة لتجنب 403 من مجلد uploads المحمي
 */

define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

if (!function_exists('isLoggedIn') || !isLoggedIn()) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'غير مصرح';
    exit;
}

$currentUser = getCurrentUser();
$currentUserRole = strtolower(trim($currentUser['role'] ?? ''));
$allowedRoles = ['manager', 'accountant', 'developer'];

if (!$currentUser || !in_array($currentUserRole, $allowedRoles, true)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'غير مصرح';
    exit;
}

$documentId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($documentId <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'معرف غير صالح';
    exit;
}

$db = db();
$document = $db->queryOne(
    "SELECT id, employee_id, original_filename, file_path FROM employee_documents WHERE id = ?",
    [$documentId]
);

if (!$document || empty($document['file_path'])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'الملف غير موجود';
    exit;
}

$relativePath = ltrim(str_replace(['../', '..\\', "\0"], '', (string) $document['file_path']), '/');
$normalizedRelativePath = str_replace('\\', '/', $relativePath);

if (strpos($normalizedRelativePath, 'uploads/employee_documents/') !== 0) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'غير مصرح';
    exit;
}

$baseDir = defined('BASE_PATH') ? rtrim(BASE_PATH, '/\\') : realpath(__DIR__ . '/..');
$fullPath = realpath($baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));

if (!$fullPath || !is_file($fullPath)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'الملف غير موجود';
    exit;
}

$ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
$mimeTypes = [
    'pdf' => 'application/pdf',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'txt' => 'text/plain; charset=utf-8',
    'csv' => 'text/csv; charset=utf-8',
];

$contentType = $mimeTypes[$ext] ?? 'application/octet-stream';
$fileName = basename((string) ($document['original_filename'] ?: $fullPath));

$isImage = in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true);
$rawMode = isset($_GET['raw']) && $_GET['raw'] === '1';

if ($isImage && !$rawMode) {
    $imageUrl = getRelativeUrl('api/view_employee_document.php?id=' . $documentId . '&raw=1');
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($fileName, ENT_QUOTES, 'UTF-8'); ?></title>
    <style>
        html, body {
            margin: 0;
            width: 100%;
            height: 100%;
            background: #111;
        }

        body {
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            font-family: Arial, sans-serif;
        }

        .image-stage {
            width: 100vw;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background:
                linear-gradient(135deg, rgba(255,255,255,0.04), rgba(255,255,255,0)),
                #111;
        }

        .image-stage img {
            max-width: 100vw;
            max-height: 100vh;
            width: auto;
            height: auto;
            object-fit: contain;
            display: block;
        }
    </style>
</head>
<body>
    <div class="image-stage">
        <img src="<?php echo htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($fileName, ENT_QUOTES, 'UTF-8'); ?>">
    </div>
</body>
</html>
<?php
    exit;
}

header('Content-Type: ' . $contentType);
header('Content-Length: ' . filesize($fullPath));
header('Cache-Control: private, max-age=3600');
header('Content-Disposition: inline; filename="' . rawurlencode($fileName) . '"');
readfile($fullPath);
exit;
