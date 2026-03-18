<?php
/**
 * Proxy: طباعة بوليصة TelegraphEx
 * يجلب صفحة الطباعة بالـ Bearer token ويعيد HTML جاهز للعرض
 */
define('ACCESS_ALLOWED', true);

$shipmentNum = isset($_GET['num']) ? preg_replace('/[^0-9A-Za-z\-]/', '', $_GET['num']) : '';
if (!$shipmentNum) {
    http_response_code(400);
    echo '<p style="font-family:sans-serif;color:red">رقم الشحنة مطلوب</p>';
    exit;
}

$url = 'https://system.telegraphex.com/print/waybill/shipment/A4/1c/' . $shipmentNum;

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_HTTPHEADER     => [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Authorization: Bearer 245467|m90rxf6dkwYyeku570WIGKSuyhkZr1Kt2ehSUQVLf862e568',
        'Referer: https://system.telegraphex.com/admin/shipments',
        'x-app-version: 5.2.2',
        'x-client-name: Mac OS-Safari',
        'x-client-type: WEB',
        'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15',
    ],
]);

$html     = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error    = curl_error($ch);
curl_close($ch);

if ($error) {
    http_response_code(502);
    echo '<p style="font-family:sans-serif;color:red">خطأ في الاتصال: ' . htmlspecialchars($error) . '</p>';
    exit;
}

if ($httpCode >= 400 || !$html) {
    http_response_code($httpCode ?: 502);
    echo '<p style="font-family:sans-serif;color:red">تعذّر تحميل البوليصة (HTTP ' . $httpCode . ')</p>';
    exit;
}

// تحويل الروابط النسبية إلى مطلقة حتى تعمل الأصول (CSS/JS/صور)
$base = 'https://system.telegraphex.com';
$html = preg_replace('/(src|href)=(["\'])\/(?!\/)/i', '$1=$2' . $base . '/', $html);
$html = preg_replace('/(url\(["\']?)\/(?!\/)/i', '$1' . $base . '/', $html);

// حقن script طباعة تلقائي مباشرة بعد تحميل الصفحة
$printScript = '<script>window.addEventListener("load",function(){window.print();});</script>';
$html = str_replace('</body>', $printScript . '</body>', $html);

header('Content-Type: text/html; charset=utf-8');
echo $html;
