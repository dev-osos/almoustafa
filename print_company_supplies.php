<?php
/**
 * صفحة طباعة إيصال مستلزمات الشركة - 80 مم
 */

define('ACCESS_ALLOWED', true);

if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/path_helper.php';

if (!isLoggedIn()) {
    die('unauthorized');
}

$currentUser = getCurrentUser();
$db = db();

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$status = isset($_GET['status']) ? (string) $_GET['status'] : '';

if ($id <= 0) {
    die('Invalid ID');
}

// جلب بيانات الإيصال
$supply = null;
try {
    $tableExists = $db->queryOne("SHOW TABLES LIKE 'company_supplies'");
    if (!empty($tableExists)) {
        $supply = $db->queryOne("SELECT * FROM company_supplies WHERE id = ?", [$id]);
    }
} catch (Exception $e) {
    error_log('Error fetching supply: ' . $e->getMessage());
}

if (!$supply) {
    die('Supply not found');
}

$items = json_decode($supply['items'], true);
$createdAt = date('Y-m-d H:i', strtotime($supply['created_at']));

// حساب إجمالي الكميات والسعر
$totalQuantity = 0;
$totalPrice = 0;
foreach ($items as $item) {
    $totalQuantity += $item['quantity'];
    if (!empty($item['price'])) {
        $totalPrice += $item['quantity'] * $item['price'];
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إيصال مستلزمات الشركة</title>
    <style>
        @page {
            size: 80mm auto;
            margin: 3mm;
        }

        * {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
            color-adjust: exact !important;
            box-sizing: border-box !important;
        }

        html, body {
            margin: 0 !important;
            padding: 0 !important;
            background: #ffffff !important;
            width: 100% !important;
            max-width: 100% !important;
            overflow: hidden !important;
            font-family: 'Tajawal', 'Cairo', 'Arial', sans-serif !important;
            font-size: 13px !important;
            line-height: 1.3 !important;
            color: #000 !important;
            direction: rtl !important;
            text-align: right !important;
            font-weight: 500 !important;
        }

        body * {
            max-width: 100% !important;
            box-sizing: border-box !important;
        }

        .receipt-80mm {
            width: 100% !important;
            margin: 0 !important;
            padding: 1mm !important;
            border: 1px solid #000 !important;
            overflow: hidden !important;
            box-sizing: border-box !important;
            direction: rtl !important;
            text-align: right !important;
        }

        .receipt-header-80mm {
            text-align: center !important;
            padding: 1mm 0.5mm 0.5mm 0.5mm !important;
            border-bottom: 2px solid #000 !important;
            width: 100% !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
        }

        .receipt-title {
            font-size: 14px !important;
            font-weight: 700 !important;
            margin-bottom: 4px !important;
            text-transform: uppercase !important;
            line-height: 1.3 !important;
            font-family: 'Tajawal', 'Cairo', 'Arial', sans-serif !important;
            display: inline !important;
        }

        .receipt-number {
            font-size: 14px !important;
            font-weight: 700 !important;
            margin: 0 !important;
            display: inline !important;
            color: #000 !important;
            margin-left: 3px !important;
        }

        .receipt-divider {
            border-top: 1px solid #000 !important;
            margin: 1px 0 !important;
        }

        .receipt-info {
            padding: 0.5mm 0.5mm !important;
            width: 100% !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
            margin-bottom: 1mm !important;
        }

        .info-row {
            display: flex !important;
            justify-content: space-between !important;
            margin-bottom: 1px !important;
            font-size: 14px !important;
            line-height: 1.3 !important;
            width: 100% !important;
            box-sizing: border-box !important;
            align-items: center !important;
            direction: rtl !important;
            text-align: right !important;
            font-family: 'Tajawal', 'Cairo', 'Arial', sans-serif !important;
        }

        .info-row .label {
            font-weight: 600 !important;
            margin-left: 1px !important;
            margin-right: 0 !important;
            white-space: nowrap !important;
            flex-shrink: 0 !important;
            font-size: 14px !important;
            text-align: right !important;
            font-family: 'Tajawal', 'Cairo', 'Arial', sans-serif !important;
        }

        .info-row .value {
            text-align: right !important;
            flex: 1 !important;
            font-weight: 500 !important;
            min-width: 0 !important;
            font-size: 14px !important;
            font-family: 'Tajawal', 'Cairo', 'Arial', sans-serif !important;
        }

        .receipt-items {
            padding: 0.5mm 0mm !important;
            width: 100% !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
            margin-bottom: 1mm !important;
        }

        .items-table {
            width: 100% !important;
            max-width: 100% !important;
            border-collapse: collapse !important;
            font-size: 13px !important;
            margin-top: 0.5px !important;
            table-layout: fixed !important;
            border-spacing: 0 !important;
            box-sizing: border-box !important;
            word-wrap: break-word !important;
            overflow-wrap: break-word !important;
        }

        .items-table thead {
            background: #f0f0f0 !important;
            border-bottom: 2px solid #000 !important;
        }

        .items-table th {
            padding: 0.5mm 0.3mm !important;
            text-align: center !important;
            font-weight: 700 !important;
            font-size: 12px !important;
            border-right: 1px solid #000 !important;
            border-left: none !important;
            line-height: 1.3 !important;
            font-family: 'Tajawal', 'Cairo', 'Arial', sans-serif !important;
        }

        .items-table th:first-child {
            border-right: none !important;
            text-align: right !important;
        }

        .items-table td {
            padding: 0.5mm 0.3mm !important;
            text-align: center !important;
            border-bottom: 1px solid #000 !important;
            border-right: 1px solid #000 !important;
            border-left: none !important;
            font-size: 12px !important;
            line-height: 1.3 !important;
            vertical-align: middle !important;
            font-weight: 500 !important;
            font-family: 'Tajawal', 'Cairo', 'Arial', sans-serif !important;
        }

        .items-table td:first-child {
            border-right: none !important;
            text-align: right !important;
        }

        .items-table .col-item {
            width: 40% !important;
            text-align: right !important;
            padding-left: 0.5mm !important;
            padding-right: 0.5mm !important;
        }

        .items-table .col-qty,
        .items-table .col-price,
        .items-table .col-total {
            width: 20% !important;
        }

        .receipt-footer {
            text-align: center !important;
            padding: 0.5mm 0.5mm !important;
            border-top: 1px solid #000 !important;
            width: 100% !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
            margin-top: 1mm !important;
        }

        .footer-text {
            font-size: 14px !important;
            font-weight: 600 !important;
            line-height: 1.4 !important;
        }

        .no-print {
            display: none !important;
        }
    </style>
</head>
<body onload="window.print();">
    <div class="receipt-80mm">
        <div class="receipt-header-80mm">
            <div style="padding: 1mm 1mm !important; margin-bottom: 1mm !important; text-align: center !important; background: #ffffff !important;">
                <div class="receipt-title">إيصال مستلزمات الشركة رقم - </div>
                <span class="receipt-number"><?php echo sprintf('%05d', $supply['id']); ?></span>
            </div>
        </div>

        <div class="receipt-info">
            <div class="info-row">
                <span class="label">التاريخ:</span>
                <span class="value"><?php echo date('Y-m-d', strtotime($supply['created_at'])); ?></span>
                <span class="label">الوقت:</span>
                <span class="value"><?php echo date('H:i', strtotime($supply['created_at'])); ?></span>
            </div>
            <div class="info-row">
                <span class="label">الحالة:</span>
                <span class="value"><?php echo htmlspecialchars($status ?: $supply['status']); ?></span>
            </div>
            <?php if (!empty($supply['created_by'])): ?>
                <div class="info-row">
                    <span class="label">المسجل:</span>
                    <span class="value"><?php echo htmlspecialchars($supply['created_by']); ?></span>
                </div>
            <?php endif; ?>
        </div>

        <div class="receipt-divider"></div>

        <div class="receipt-items">
            <table class="items-table">
                <thead>
                    <tr>
                        <th class="col-item">الصنف</th>
                        <th class="col-qty">الكمية</th>
                        <th class="col-price">السعر</th>
                        <th class="col-total">الإجمالي</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <?php
                    $itemTotal = (isset($item['price']) && is_numeric($item['price'])) ? ($item['quantity'] * $item['price']) : 0;
                    ?>
                    <tr>
                        <td class="col-item"><?php echo htmlspecialchars($item['name']); ?></td>
                        <td class="col-qty"><?php echo htmlspecialchars($item['quantity']); ?></td>
                        <td class="col-price"><?php echo !empty($item['price']) ? htmlspecialchars(number_format($item['price'], 2)) : '-'; ?></td>
                        <td class="col-total"><?php echo $itemTotal > 0 ? htmlspecialchars(number_format($itemTotal, 2)) : '-'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="receipt-divider"></div>

        <div class="receipt-info">
            <div class="info-row" style="font-weight: 700; border-top: 1px solid #000; padding-top: 2px;">
                <span class="label">إجمالي الكمية:</span>
                <span class="value"><?php echo htmlspecialchars($totalQuantity); ?></span>
                <span class="label">إجمالي السعر:</span>
                <span class="value"><?php echo htmlspecialchars(number_format($totalPrice, 2)); ?></span>
            </div>
        </div>

        <div class="receipt-footer">
            <div style="text-align: center; margin-top: 4px; padding: 4px; border: 2px solid #000; border-radius: 5px;">
                <div style="font-size: 13px; font-weight: 700; margin-bottom: 2px;">ختم الاعتماد</div>
                <div style="font-size: 10px;">التاريخ: <?php echo date('Y-m-d'); ?></div>
            </div>
        </div>
    </div>
</body>
</html>