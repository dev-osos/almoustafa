<?php
/**
 * API Proxy: جلب مناطق/مدن التليجراف بناءً على كود المحافظة (parentId)
 */
define('ACCESS_ALLOWED', true);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$parentId = isset($_GET['parentId']) ? intval($_GET['parentId']) : 0;
if (!$parentId) {
    http_response_code(400);
    echo json_encode(['error' => 'parentId مطلوب'], JSON_UNESCAPED_UNICODE);
    exit;
}

$payload = json_encode([
    'operationName' => 'ListZonesDropdown',
    'variables' => [
        'input' => [
            'active'   => true,
            'parentId' => $parentId,
            'service'  => [
                'serviceId'     => 1,
                'fromZoneId'    => 1,
                'fromSubzoneId' => 346,
            ],
        ],
    ],
    'query' => "query ListZonesDropdown(\$input: ListZonesFilterInput) {\n  listZonesDropdown(input: \$input) {\n    id\n    name\n    code\n  }\n}",
]);

$ch = curl_init('https://system.telegraphex.com:8443/graphql');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
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

$result = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error    = curl_error($ch);
curl_close($ch);

if ($error) {
    http_response_code(502);
    echo json_encode(['error' => 'cURL error: ' . $error], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code($httpCode ?: 200);
echo $result;
