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
    <title>إيصال مستلزمات</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Arial', sans-serif;
            background-color: #fff;
            padding: 0;
            margin: 0;
        }
        
        @page {
            size: 80mm 200mm;
            margin: 2mm;
        }
        
        .receipt-container {
            width: 80mm;
            max-width: 80mm;
            margin: 0 auto;
            padding: 3mm;
            background-color: white;
            font-size: 11px;
            line-height: 1.4;
        }
        
        .receipt-header {
            text-align: center;
            margin-bottom: 3mm;
            border-bottom: 1px dashed #000;
            padding-bottom: 2mm;
        }
        
        .receipt-header h3 {
            font-size: 13px;
            font-weight: bold;
            margin-bottom: 1mm;
        }
        
        .receipt-info {
            font-size: 10px;
            margin-bottom: 1mm;
        }
        
        .receipt-info p {
            margin: 0.5mm 0;
        }
        
        .items-section {
            margin: 3mm 0;
            border-bottom: 1px dashed #000;
            padding-bottom: 2mm;
        }
        
        .items-section h4 {
            font-size: 11px;
            font-weight: bold;
            margin-bottom: 1mm;
            text-align: center;
        }
        
        .item {
            margin-bottom: 1.5mm;
            padding-bottom: 1mm;
            border-bottom: 1px dotted #ddd;
        }
        
        .item:last-child {
            border-bottom: none;
        }
        
        .item-name {
            font-weight: bold;
            font-size: 11px;
            margin-bottom: 0.5mm;
        }
        
        .item-details {
            display: flex;
            justify-content: space-between;
            font-size: 10px;
        }
        
        .item-detail {
            flex: 1;
        }
        
        .total-section {
            margin: 2mm 0 3mm 0;
            padding: 2mm;
            background-color: #f0f0f0;
            border: 1px solid #000;
            border-radius: 2px;
        }
        
        .total-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 1mm;
            font-weight: bold;
        }
        
        .total-row:last-child {
            margin-bottom: 0;
            font-size: 12px;
            border-top: 1px solid #000;
            padding-top: 1mm;
        }
        
        .status-badge {
            display: inline-block;
            padding: 1mm 2mm;
            background-color: <?php echo $supply['status'] === 'purchased' ? '#28a745' : '#ffc107'; ?>;
            color: <?php echo $supply['status'] === 'purchased' ? 'white' : '#000'; ?>;
            border-radius: 2px;
            font-size: 10px;
            font-weight: bold;
            margin-top: 1mm;
        }
        
        .receipt-footer {
            text-align: center;
            margin-top: 2mm;
            padding-top: 2mm;
            border-top: 1px dashed #000;
            font-size: 9px;
        }
        
        .receipt-footer p {
            margin: 0.5mm 0;
        }
        
        @media print {
            body {
                background: white;
            }
            .receipt-container {
                padding: 0;
                box-shadow: none;
            }
            @page {
                margin: 0;
            }
        }
    </style>
</head>
<body onload="window.print();">
    <div class="receipt-container">
        <div class="receipt-header">
            <h3>إيصال مستلزمات الشركة</h3>
            <div class="status-badge">
                <?php echo htmlspecialchars($status); ?>
            </div>
        </div>
        
        <div class="receipt-info">
            <p><strong>رقم الإيصال:</strong> <?php echo printf('%05d', $supply['id']); ?></p>
            <p><strong>التاريخ:</strong> <?php echo htmlspecialchars($createdAt); ?></p>
        </div>
        
        <div class="items-section">
            <h4>المستلزمات</h4>
            <?php foreach ($items as $item): ?>
                <div class="item">
                    <div class="item-name"><?php echo htmlspecialchars($item['name']); ?></div>
                    <div class="item-details">
                        <div class="item-detail">
                            كمية: <?php echo htmlspecialchars($item['quantity']); ?>
                        </div>
                        <?php if (!empty($item['price'])): ?>
                            <div class="item-detail">
                                السعر: <?php echo htmlspecialchars($item['price']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <div class="total-section">
            <div class="total-row">
                <span>إجمالي الكمية:</span>
                <span><?php echo htmlspecialchars($totalQuantity); ?></span>
            </div>
            <?php if ($totalPrice > 0): ?>
                <div class="total-row">
                    <span>إجمالي السعر:</span>
                    <span><?php echo htmlspecialchars($totalPrice); ?></span>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="receipt-footer">
            <p>شكراً لك</p>
            <p style="font-size: 8px; color: #999;">
                تم الطباعة: <?php echo date('Y-m-d H:i:s'); ?>
            </p>
        </div>
    </div>
</body>
</html>
