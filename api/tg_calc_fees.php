<?php
/**
 * API Proxy: حساب تكلفة شحن TelegraphEx
 */
define('ACCESS_ALLOWED', true);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$price            = isset($_GET['price']) ? (float)$_GET['price'] : 0;
$recipientZoneId  = isset($_GET['recipientZoneId']) ? (int)$_GET['recipientZoneId'] : 0;
$recipientSubzoneId = isset($_GET['recipientSubzoneId']) ? (int)$_GET['recipientSubzoneId'] : 0;
$weight           = isset($_GET['weight']) ? (float)$_GET['weight'] : 1;

if (!$recipientZoneId || !$recipientSubzoneId) {
    http_response_code(400);
    echo json_encode(['error' => 'recipientZoneId و recipientSubzoneId مطلوبان'], JSON_UNESCAPED_UNICODE);
    exit;
}

$payload = json_encode([
    'operationName' => 'CalculateShipmentFees',
    'variables' => [
        'input' => [
            'price'              => $price,
            'recipientSubzoneId' => $recipientSubzoneId,
            'recipientZoneId'    => $recipientZoneId,
            'serviceId'          => 1,
            'weight'             => $weight,
            'paymentTypeCode'    => 'COLC',
            'priceTypeCode'      => 'INCLD',
            'senderSubzoneId'    => 346,
            'senderZoneId'       => 1,
            'size'               => ['height' => 0, 'length' => 0, 'width' => 0],
        ],
    ],
    'query' => 'query CalculateShipmentFees($input: CalculateShipmentFeesInput!) { calculateShipmentFees(input: $input) { amount delivery weight collection total tax post return } }',
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

$result   = curl_exec($ch);
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
