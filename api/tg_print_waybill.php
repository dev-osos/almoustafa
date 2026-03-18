<?php
/**
 * بوليصة TelegraphEx — مولَّدة من GraphQL API
 */
define('ACCESS_ALLOWED', true);

$num = isset($_GET['num']) ? preg_replace('/[^0-9A-Za-z\-]/', '', $_GET['num']) : '';
if (!$num) { http_response_code(400); echo 'رقم الشحنة مطلوب'; exit; }

$code = 'TG' . ltrim($num, 'Tt Gg');   // TG7955670

// ── جلب بيانات الشحنة من GraphQL ──────────────────────────────────────────
$payload = json_encode([
    'operationName' => 'ListShipments',
    'variables'     => ['first' => 1, 'page' => 1, 'input' => ['code' => $code]],
    'query'         => 'query ListShipments($first:Int,$page:Int,$input:ListShipmentsFilterInput){
  listShipments(first:$first,page:$page,input:$input){
    data{
      code date recipientName recipientMobile refNumber
      recipientZone{name} recipientSubzone{name}
      status{name code}
      type{name code}
      paymentType{code}
      deliveryType{name}
      price amount totalAmount deliveryFees returnFees allDueFees collected
      branch{name}
      shipmentProducts{quantity price product{name}}
    }
  }
}',
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
$res   = curl_exec($ch);
$error = curl_error($ch);
curl_close($ch);

if ($error) { http_response_code(502); echo 'خطأ في الاتصال: ' . htmlspecialchars($error); exit; }

$data = json_decode($res, true);
$s    = $data['data']['listShipments']['data'][0] ?? null;

if (!$s) {
    http_response_code(404);
    echo '<p style="font-family:sans-serif;color:red;padding:2rem">لم يتم العثور على الشحنة: ' . htmlspecialchars($code) . '</p>';
    exit;
}

// ── مساعدات ───────────────────────────────────────────────────────────────
function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fmt($v) { return number_format((float)$v, 2); }

$zone    = ($s['recipientZone']['name']    ?? '') . ($s['recipientSubzone']['name'] ?? '' ? ' — ' . $s['recipientSubzone']['name'] : '');
$date    = !empty($s['date']) ? date('Y-m-d H:i', strtotime($s['date'])) : '—';
$payCode = $s['paymentType']['code'] ?? '';
$payLabel = match($payCode) { 'CASH' => 'كاش', 'VISA' => 'فيزا', 'PREPAID' => 'مدفوع مسبقاً', default => $payCode };
$products = $s['shipmentProducts'] ?? [];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>بوليصة شحن — <?php echo h($s['code']); ?></title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Libre+Barcode+39+Text&display=swap">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: 'Cairo', sans-serif; background: #fff; color: #111; font-size: 13px; }

  .waybill {
    width: 148mm; /* A5 عرضياً يناسب بوليصات الشحن */
    min-height: 200mm;
    margin: 0 auto;
    border: 2px solid #111;
    padding: 0;
  }

  /* رأس البوليصة */
  .wh-header {
    display: flex; align-items: stretch;
    border-bottom: 2px solid #111;
  }
  .wh-logo {
    flex: 0 0 40%;
    display: flex; flex-direction: column; justify-content: center; align-items: center;
    padding: 8px;
    border-left: 2px solid #111;
  }
  .wh-logo .brand { font-size: 22px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; }
  .wh-logo .brand-sub { font-size: 9px; color: #555; }
  .wh-code {
    flex: 1;
    display: flex; flex-direction: column; justify-content: center; align-items: center;
    padding: 8px;
  }
  .wh-code .label { font-size: 10px; color: #555; margin-bottom: 2px; }
  .wh-code .code  { font-size: 20px; font-weight: 700; letter-spacing: 1px; }

  /* باركود */
  .wh-barcode {
    border-bottom: 2px solid #111;
    text-align: center;
    padding: 4px 0 0;
    background: #fff;
  }
  .wh-barcode .barcode-text {
    font-family: 'Libre Barcode 39 Text', monospace;
    font-size: 52px;
    line-height: 1;
    letter-spacing: 0;
    direction: ltr;
  }
  .wh-barcode .barcode-num { font-size: 11px; color: #333; margin-top: -4px; padding-bottom: 2px; direction: ltr; }

  /* أقسام */
  .wh-section {
    border-bottom: 1.5px solid #ccc;
    padding: 7px 10px;
  }
  .wh-section:last-child { border-bottom: none; }
  .wh-section-title {
    font-size: 10px; font-weight: 700; color: #555;
    text-transform: uppercase; letter-spacing: .5px;
    margin-bottom: 5px; border-bottom: 1px dashed #ccc; padding-bottom: 3px;
  }

  .wh-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 12px; }
  .wh-row { display: flex; gap: 4px; align-items: baseline; }
  .wh-lbl { font-size: 10px; color: #666; white-space: nowrap; }
  .wh-val { font-size: 13px; font-weight: 600; }
  .wh-val.big { font-size: 16px; }

  /* المستلم — أبرز جزء */
  .recipient-name { font-size: 18px; font-weight: 700; margin-bottom: 3px; }
  .recipient-phone { font-size: 14px; direction: ltr; display: inline-block; }
  .recipient-zone { font-size: 13px; color: #333; margin-top: 2px; }

  /* المنتجات */
  .products-table { width: 100%; border-collapse: collapse; font-size: 11px; margin-top: 4px; }
  .products-table th { background: #f0f0f0; padding: 3px 5px; text-align: right; font-weight: 600; border: 1px solid #ddd; }
  .products-table td { padding: 3px 5px; border: 1px solid #ddd; }

  /* قسم المبالغ */
  .amounts-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 5px; }
  .amount-box {
    border: 1px solid #ddd; border-radius: 4px; padding: 5px 7px;
    display: flex; flex-direction: column; align-items: center;
  }
  .amount-box .albl { font-size: 9px; color: #666; }
  .amount-box .aval { font-size: 14px; font-weight: 700; }
  .amount-box.highlight { border-color: #111; background: #f8f8f8; }

  /* تذييل */
  .wh-footer {
    display: flex; justify-content: space-between; align-items: center;
    padding: 5px 10px; font-size: 10px; color: #666;
    border-top: 1.5px solid #ccc;
  }

  @media print {
    body { margin: 0; }
    .waybill { border: 2px solid #111; margin: 0; }
    @page { size: A5 landscape; margin: 5mm; }
  }
</style>
</head>
<body>
<div class="waybill">

  <!-- رأس البوليصة -->
  <div class="wh-header">
    <div class="wh-logo">
      <div class="brand">Telegraph</div>
      <div class="brand-sub">Fastest Courier Service</div>
      <?php if (!empty($s['branch']['name'])): ?>
        <div style="font-size:10px;color:#555;margin-top:4px"><?php echo h($s['branch']['name']); ?></div>
      <?php endif; ?>
    </div>
    <div class="wh-code">
      <div class="label">رقم الشحنة</div>
      <div class="code"><?php echo h($s['code']); ?></div>
      <?php if (!empty($s['refNumber'])): ?>
        <div style="font-size:10px;color:#777;margin-top:3px">Ref: <?php echo h($s['refNumber']); ?></div>
      <?php endif; ?>
      <div style="margin-top:5px">
        <span style="background:<?php
          echo match($s['status']['code'] ?? '') {
            'DTR' => '#198754', 'PKD' => '#ffc107', 'CNL' => '#6c757d',
            default => '#0dcaf0'
          };
        ?>;color:<?php echo in_array($s['status']['code'] ?? '', ['PKD']) ? '#000' : '#fff'; ?>;
          padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600">
          <?php echo h($s['status']['name'] ?? ''); ?>
        </span>
      </div>
    </div>
  </div>

  <!-- باركود -->
  <div class="wh-barcode">
    <div class="barcode-text">*<?php echo h($s['code']); ?>*</div>
    <div class="barcode-num"><?php echo h($s['code']); ?></div>
  </div>

  <!-- المستلم -->
  <div class="wh-section">
    <div class="wh-section-title">بيانات المستلم</div>
    <div class="recipient-name"><?php echo h($s['recipientName'] ?? '—'); ?></div>
    <?php if (!empty($s['recipientMobile'])): ?>
      <div class="recipient-phone"><?php echo h($s['recipientMobile']); ?></div>
    <?php endif; ?>
    <div class="recipient-zone"><?php echo h($zone ?: '—'); ?></div>
  </div>

  <!-- تفاصيل الشحنة -->
  <div class="wh-section">
    <div class="wh-section-title">تفاصيل الشحنة</div>
    <div class="wh-grid">
      <div class="wh-row">
        <span class="wh-lbl">نوع الشحنة:</span>
        <span class="wh-val"><?php echo h($s['type']['name'] ?? '—'); ?></span>
      </div>
      <div class="wh-row">
        <span class="wh-lbl">طريقة الدفع:</span>
        <span class="wh-val"><?php echo h($payLabel); ?></span>
      </div>
      <?php if (!empty($s['deliveryType']['name'])): ?>
      <div class="wh-row">
        <span class="wh-lbl">نوع التوصيل:</span>
        <span class="wh-val"><?php echo h($s['deliveryType']['name']); ?></span>
      </div>
      <?php endif; ?>
      <div class="wh-row">
        <span class="wh-lbl">التاريخ:</span>
        <span class="wh-val" style="font-size:11px"><?php echo h($date); ?></span>
      </div>
    </div>
  </div>

  <?php if (!empty($products)): ?>
  <!-- المنتجات -->
  <div class="wh-section">
    <div class="wh-section-title">المنتجات</div>
    <table class="products-table">
      <thead>
        <tr><th>المنتج</th><th>الكمية</th><th>السعر</th></tr>
      </thead>
      <tbody>
        <?php foreach ($products as $p): ?>
        <tr>
          <td><?php echo h($p['product']['name'] ?? '—'); ?></td>
          <td style="text-align:center"><?php echo h($p['quantity'] ?? ''); ?></td>
          <td style="text-align:left;direction:ltr"><?php echo fmt($p['price'] ?? 0); ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- المبالغ -->
  <div class="wh-section">
    <div class="wh-section-title">المبالغ</div>
    <div class="amounts-grid">
      <div class="amount-box highlight">
        <span class="albl">السعر</span>
        <span class="aval"><?php echo fmt($s['price'] ?? 0); ?> ج</span>
      </div>
      <div class="amount-box highlight">
        <span class="albl">الصافي</span>
        <span class="aval"><?php echo fmt($s['amount'] ?? 0); ?> ج</span>
      </div>
      <?php if (!empty($s['deliveryFees'])): ?>
      <div class="amount-box">
        <span class="albl">رسوم التوصيل</span>
        <span class="aval"><?php echo fmt($s['deliveryFees']); ?> ج</span>
      </div>
      <?php endif; ?>
      <?php if (!empty($s['returnFees'])): ?>
      <div class="amount-box">
        <span class="albl">رسوم الإرجاع</span>
        <span class="aval"><?php echo fmt($s['returnFees']); ?> ج</span>
      </div>
      <?php endif; ?>
      <?php if (!empty($s['totalAmount'])): ?>
      <div class="amount-box" style="grid-column:1/-1">
        <span class="albl">الإجمالي</span>
        <span class="aval big"><?php echo fmt($s['totalAmount']); ?> ج</span>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- تذييل -->
  <div class="wh-footer">
    <span><?php echo h($date); ?></span>
    <span><?php echo h($s['code']); ?></span>
  </div>

</div>

<script>window.addEventListener('load', function(){ window.print(); });</script>
</body>
</html>
