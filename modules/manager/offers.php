<?php
/**
 * صفحة العروض - المدير / مسؤول تليجراف
 * Offers Page - Manager / Telegraph
 */

if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit_log.php';

requireRole(['manager', 'telegraph', 'developer']);

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';

// ——— إنشاء جداول العروض إذا لم تكن موجودة ———
try {
    $db->execute("
        CREATE TABLE IF NOT EXISTS `offers` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `name` varchar(200) NOT NULL,
          `price` decimal(12,2) NOT NULL DEFAULT 0.00,
          `notes` text DEFAULT NULL,
          `status` enum('active','inactive') DEFAULT 'active',
          `created_by` int(11) DEFAULT NULL,
          `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `status` (`status`),
          KEY `created_by` (`created_by`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $db->execute("
        CREATE TABLE IF NOT EXISTS `offer_items` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `offer_id` int(11) NOT NULL,
          `product_id` int(11) NOT NULL,
          `quantity` decimal(10,3) NOT NULL DEFAULT 1.000,
          `unit_price` decimal(12,2) DEFAULT NULL,
          PRIMARY KEY (`id`),
          KEY `offer_id` (`offer_id`),
          KEY `product_id` (`product_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (Exception $e) {
    error_log('offers: table creation error -> ' . $e->getMessage());
}

// ——— معالجة طلبات POST ———
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    // ——— إنشاء عرض جديد ———
    if ($action === 'create_offer') {
        $name      = trim($_POST['offer_name'] ?? '');
        $price     = (float)($_POST['offer_price'] ?? 0);
        $notes     = trim($_POST['offer_notes'] ?? '');
        $itemsRaw  = $_POST['items'] ?? [];

        if (empty($name)) {
            $error = 'يرجى إدخال اسم العرض.';
        } elseif ($price < 0) {
            $error = 'سعر العرض لا يمكن أن يكون سالباً.';
        } elseif (!is_array($itemsRaw) || empty($itemsRaw)) {
            $error = 'يرجى إضافة منتج واحد على الأقل.';
        } else {
            // تصفية العناصر الفارغة
            $validItems = [];
            foreach ($itemsRaw as $item) {
                $pid = (int)($item['product_id'] ?? 0);
                $qty = (float)($item['quantity'] ?? 0);
                $up  = isset($item['unit_price']) && $item['unit_price'] !== '' ? (float)$item['unit_price'] : null;
                if ($pid > 0 && $qty > 0) {
                    $validItems[] = ['product_id' => $pid, 'quantity' => $qty, 'unit_price' => $up];
                }
            }
            if (empty($validItems)) {
                $error = 'يرجى إضافة منتج واحد على الأقل بكمية صحيحة.';
            } else {
                try {
                    $db->execute(
                        "INSERT INTO offers (name, price, notes, created_by) VALUES (?, ?, ?, ?)",
                        [$name, $price, $notes ?: null, $currentUser['id']]
                    );
                    $offerId = $db->lastInsertId();
                    foreach ($validItems as $item) {
                        $db->execute(
                            "INSERT INTO offer_items (offer_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?)",
                            [$offerId, $item['product_id'], $item['quantity'], $item['unit_price']]
                        );
                    }
                    logAudit('create', 'offers', $offerId, null, ['name' => $name, 'price' => $price]);
                    $success = 'تم إنشاء العرض "' . htmlspecialchars($name) . '" بنجاح.';
                } catch (Exception $e) {
                    error_log('offers: create_offer -> ' . $e->getMessage());
                    $error = 'حدث خطأ أثناء حفظ العرض.';
                }
            }
        }
    }

    // ——— تعديل عرض ———
    elseif ($action === 'update_offer') {
        $offerId   = (int)($_POST['offer_id'] ?? 0);
        $name      = trim($_POST['offer_name'] ?? '');
        $price     = (float)($_POST['offer_price'] ?? 0);
        $notes     = trim($_POST['offer_notes'] ?? '');
        $status    = in_array($_POST['offer_status'] ?? '', ['active', 'inactive']) ? $_POST['offer_status'] : 'active';
        $itemsRaw  = $_POST['items'] ?? [];

        if ($offerId <= 0) {
            $error = 'معرّف العرض غير صحيح.';
        } elseif (empty($name)) {
            $error = 'يرجى إدخال اسم العرض.';
        } elseif (!is_array($itemsRaw) || empty($itemsRaw)) {
            $error = 'يرجى إضافة منتج واحد على الأقل.';
        } else {
            $validItems = [];
            foreach ($itemsRaw as $item) {
                $pid = (int)($item['product_id'] ?? 0);
                $qty = (float)($item['quantity'] ?? 0);
                $up  = isset($item['unit_price']) && $item['unit_price'] !== '' ? (float)$item['unit_price'] : null;
                if ($pid > 0 && $qty > 0) {
                    $validItems[] = ['product_id' => $pid, 'quantity' => $qty, 'unit_price' => $up];
                }
            }
            if (empty($validItems)) {
                $error = 'يرجى إضافة منتج واحد على الأقل بكمية صحيحة.';
            } else {
                try {
                    $existing = $db->queryOne("SELECT id, name, price FROM offers WHERE id = ?", [$offerId]);
                    if (!$existing) {
                        $error = 'العرض غير موجود.';
                    } else {
                        $db->execute(
                            "UPDATE offers SET name = ?, price = ?, notes = ?, status = ?, updated_at = NOW() WHERE id = ?",
                            [$name, $price, $notes ?: null, $status, $offerId]
                        );
                        $db->execute("DELETE FROM offer_items WHERE offer_id = ?", [$offerId]);
                        foreach ($validItems as $item) {
                            $db->execute(
                                "INSERT INTO offer_items (offer_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?)",
                                [$offerId, $item['product_id'], $item['quantity'], $item['unit_price']]
                            );
                        }
                        logAudit('update', 'offers', $offerId, ['name' => $existing['name'], 'price' => $existing['price']], ['name' => $name, 'price' => $price]);
                        $success = 'تم تحديث العرض "' . htmlspecialchars($name) . '" بنجاح.';
                    }
                } catch (Exception $e) {
                    error_log('offers: update_offer -> ' . $e->getMessage());
                    $error = 'حدث خطأ أثناء تحديث العرض.';
                }
            }
        }
    }

    // ——— حذف عرض ———
    elseif ($action === 'delete_offer') {
        $offerId = (int)($_POST['offer_id'] ?? 0);
        if ($offerId <= 0) {
            $error = 'معرّف العرض غير صحيح.';
        } else {
            try {
                $existing = $db->queryOne("SELECT id, name FROM offers WHERE id = ?", [$offerId]);
                if (!$existing) {
                    $error = 'العرض غير موجود.';
                } else {
                    $db->execute("DELETE FROM offer_items WHERE offer_id = ?", [$offerId]);
                    $db->execute("DELETE FROM offers WHERE id = ?", [$offerId]);
                    logAudit('delete', 'offers', $offerId, ['name' => $existing['name']], null);
                    $success = 'تم حذف العرض "' . htmlspecialchars($existing['name']) . '" بنجاح.';
                }
            } catch (Exception $e) {
                error_log('offers: delete_offer -> ' . $e->getMessage());
                $error = 'حدث خطأ أثناء حذف العرض.';
            }
        }
    }
}

// ——— جلب البيانات ———

// جميع منتجات الشركة من جدول products (جميع الأقسام)
$allProducts = [];
try {
    $allProducts = $db->query(
        "SELECT p.id, p.name, p.category, p.unit, p.unit_price, p.quantity, p.status
         FROM products p
         WHERE p.status = 'active'
         ORDER BY p.category ASC, p.name ASC"
    );
} catch (Exception $e) {
    error_log('offers: fetch products -> ' . $e->getMessage());
}

// تجميع المنتجات حسب القسم
$productsByCategory = [];
foreach ($allProducts as $p) {
    $cat = $p['category'] ?: 'أخرى';
    $productsByCategory[$cat][] = $p;
}

// جلب العروض مع عدد المنتجات
$offers = [];
try {
    $offers = $db->query(
        "SELECT o.id, o.name, o.price, o.notes, o.status, o.created_at,
                COUNT(oi.id) AS items_count,
                u.username AS created_by_name
         FROM offers o
         LEFT JOIN offer_items oi ON oi.offer_id = o.id
         LEFT JOIN users u ON u.id = o.created_by
         GROUP BY o.id
         ORDER BY o.created_at DESC"
    );
} catch (Exception $e) {
    error_log('offers: fetch offers -> ' . $e->getMessage());
}

// للتعديل: جلب بيانات العرض إذا طلب المستخدم تعديلاً
$editOffer = null;
$editItems = [];
$editOfferId = (int)($_GET['edit'] ?? 0);
if ($editOfferId > 0) {
    try {
        $editOffer = $db->queryOne("SELECT * FROM offers WHERE id = ?", [$editOfferId]);
        if ($editOffer) {
            $editItems = $db->query(
                "SELECT oi.*, p.name AS product_name, p.unit, p.category
                 FROM offer_items oi
                 JOIN products p ON p.id = oi.product_id
                 WHERE oi.offer_id = ?
                 ORDER BY oi.id ASC",
                [$editOfferId]
            );
        }
    } catch (Exception $e) {
        error_log('offers: fetch edit offer -> ' . $e->getMessage());
    }
}
?>

<!-- رسائل النجاح والخطأ -->
<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($success); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ——— بطاقة النموذج الثابتة في الأعلى ——— -->
<div class="card shadow-sm mb-4" id="offerFormCard">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h5 class="mb-0">
            <i class="bi bi-tags me-2"></i>
            <?php echo $editOffer ? 'تعديل العرض: ' . htmlspecialchars($editOffer['name']) : 'إنشاء عرض جديد'; ?>
        </h5>
        <?php if ($editOffer): ?>
        <a href="?page=offers" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-x-lg me-1"></i>إلغاء التعديل
        </a>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <form method="POST" id="offerForm">
            <input type="hidden" name="action" value="<?php echo $editOffer ? 'update_offer' : 'create_offer'; ?>">
            <?php if ($editOffer): ?>
            <input type="hidden" name="offer_id" value="<?php echo (int)$editOffer['id']; ?>">
            <?php endif; ?>

            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">اسم العرض <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="offer_name"
                           value="<?php echo htmlspecialchars($editOffer['name'] ?? ''); ?>"
                           placeholder="مثال: عرض رمضان " required>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">سعر العرض (ج.م) <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input type="number" class="form-control" name="offer_price" id="offerPriceInput"
                               value="<?php echo $editOffer ? number_format((float)$editOffer['price'], 2, '.', '') : ''; ?>"
                               step="0.01" min="0" placeholder="0.00" required>
                        <span class="input-group-text">ج.م</span>
                    </div>
                </div>
                <?php if ($editOffer): ?>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">الحالة</label>
                    <select class="form-select" name="offer_status">
                        <option value="active" <?php echo ($editOffer['status'] === 'active') ? 'selected' : ''; ?>>نشط</option>
                        <option value="inactive" <?php echo ($editOffer['status'] === 'inactive') ? 'selected' : ''; ?>>غير نشط</option>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-md-<?php echo $editOffer ? '3' : '5'; ?>">
                    <label class="form-label fw-semibold">ملاحظات</label>
                    <input type="text" class="form-control" name="offer_notes"
                           value="<?php echo htmlspecialchars($editOffer['notes'] ?? ''); ?>"
                           placeholder="وصف مختصر للعرض (اختياري)">
                </div>
            </div>

            <!-- جدول المنتجات -->
            <style>
            .offer-product-wrapper { position: relative; }
            .offer-search-dropdown {
                position: absolute; top: 100%; right: 0; left: 0; z-index: 1055;
                background: #fff; border: 1px solid #ced4da; border-top: none;
                border-radius: 0 0 .375rem .375rem;
                max-height: 220px; overflow-y: auto;
                box-shadow: 0 4px 12px rgba(0,0,0,.12);
                display: none;
            }
            .offer-search-dropdown .osd-item {
                padding: .45rem .75rem; cursor: pointer; font-size: .875rem;
                border-bottom: 1px solid #f0f0f0;
            }
            .offer-search-dropdown .osd-item:last-child { border-bottom: none; }
            .offer-search-dropdown .osd-item:hover,
            .offer-search-dropdown .osd-item.active { background: #e9f0ff; color: #1a56db; }
            .offer-search-dropdown .osd-cat {
                font-size: .75rem; color: #6c757d; display: block;
            }
            .offer-search-dropdown .osd-empty {
                padding: .5rem .75rem; color: #6c757d; font-size: .875rem;
            }
            .offer-search-input.has-value { border-color: #198754; }
            </style>
            <div class="table-responsive mb-3">
                <table class="table table-sm align-middle" id="offerItemsTable">
                    <thead class="table-light">
                        <tr>
                            <th style="min-width:280px;">المنتج <span class="text-danger">*</span></th>
                            <th style="width:90px;">الوحدة</th>
                            <th style="width:130px;">الكمية <span class="text-danger">*</span></th>
                            <th style="width:150px;">سعر الوحدة</th>
                            <th style="width:70px;">حذف</th>
                        </tr>
                    </thead>
                    <tbody id="offerItemsBody">
                        <?php if ($editOffer && !empty($editItems)): ?>
                            <?php foreach ($editItems as $idx => $ei): ?>
                            <tr>
                                <td>
                                    <div class="offer-product-wrapper">
                                        <input type="text" class="form-control form-control-sm offer-search-input<?php echo $ei['product_id'] ? ' has-value' : ''; ?>"
                                               placeholder="ابحث عن منتج..." autocomplete="off"
                                               value="<?php echo htmlspecialchars($ei['category'] . ' - ' . $ei['product_name']); ?>">
                                        <select class="offer-product-select visually-hidden"
                                                name="items[<?php echo $idx; ?>][product_id]" required
                                                data-unit="<?php echo htmlspecialchars($ei['unit'] ?? 'وحدة'); ?>"
                                                data-unit-price="<?php echo number_format((float)($ei['unit_price'] ?? 0), 2, '.', ''); ?>">
                                            <option value="<?php echo (int)$ei['product_id']; ?>" selected>
                                                <?php echo htmlspecialchars($ei['product_name']); ?>
                                            </option>
                                        </select>
                                        <div class="offer-search-dropdown"></div>
                                    </div>
                                </td>
                                <td class="text-muted small">
                                    <span class="offer-unit-label"><?php echo htmlspecialchars($ei['unit'] ?? '-'); ?></span>
                                </td>
                                <td>
                                    <input type="number" class="form-control offer-qty-input"
                                           name="items[<?php echo $idx; ?>][quantity]"
                                           value="<?php echo number_format((float)$ei['quantity'], 3, '.', ''); ?>"
                                           step="any" min="0.001" required>
                                </td>
                                <td>
                                    <div class="input-group input-group-sm">
                                        <input type="number" class="form-control offer-up-input"
                                               name="items[<?php echo $idx; ?>][unit_price]"
                                               value="<?php echo $ei['unit_price'] !== null ? number_format((float)$ei['unit_price'], 2, '.', '') : ''; ?>"
                                               step="0.01" min="0" placeholder="اختياري">
                                        <span class="input-group-text">ج.م</span>
                                    </div>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-outline-danger btn-sm remove-offer-item" title="حذف">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <button type="button" class="btn btn-outline-primary btn-sm" id="addOfferItemBtn">
                    <i class="bi bi-plus-circle me-1"></i>إضافة منتج
                </button>
                <div class="text-muted small">
                    إجمالي أسعار الوحدات: <strong id="offerCalcTotal" class="text-success">0.00 ج.م</strong>
                    <span class="text-muted">(للمرجعية فقط)</span>
                </div>
            </div>

            <div class="text-end">
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-<?php echo $editOffer ? 'pencil-square' : 'plus-circle'; ?> me-1"></i>
                    <?php echo $editOffer ? 'حفظ التعديلات' : 'إنشاء العرض'; ?>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ——— قائمة العروض ——— -->
<div class="card shadow-sm">
    <div class="card-header">
        <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>قائمة العروض</h5>
    </div>
    <div class="card-body p-0">
        <?php if (empty($offers)): ?>
        <div class="p-4 text-center text-muted">
            <i class="bi bi-tags fs-2 d-block mb-2"></i>
            لا توجد عروض مسجلة بعد. قم بإنشاء أول عرض باستخدام النموذج أعلاه.
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-striped table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>اسم العرض</th>
                        <th>السعر</th>
                        <th>عدد المنتجات</th>
                        <th>الحالة</th>
                        <th>ملاحظات</th>
                        <th>تاريخ الإنشاء</th>
                        <th style="width:220px;">الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($offers as $offer): ?>
                    <tr>
                        <td class="text-muted small"><?php echo (int)$offer['id']; ?></td>
                        <td class="fw-semibold"><?php echo htmlspecialchars($offer['name']); ?></td>
                        <td class="fw-bold text-success"><?php echo number_format((float)$offer['price'], 2); ?> ج.م</td>
                        <td>
                            <span class="badge bg-primary rounded-pill"><?php echo (int)$offer['items_count']; ?> منتج</span>
                        </td>
                        <td>
                            <?php if ($offer['status'] === 'active'): ?>
                                <span class="badge bg-success">نشط</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">غير نشط</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted small"><?php echo htmlspecialchars($offer['notes'] ?? '—'); ?></td>
                        <td class="text-muted small"><?php echo date('Y-m-d', strtotime($offer['created_at'])); ?></td>
                        <td>
                            <div class="d-flex gap-1 flex-wrap">
                                <button type="button" class="btn btn-sm btn-outline-info"
                                        onclick="showOfferDetails(<?php echo (int)$offer['id']; ?>, <?php echo htmlspecialchars(json_encode($offer['name']), ENT_QUOTES); ?>)"
                                        title="عرض التفاصيل">
                                    <i class="bi bi-eye"></i> تفاصيل
                                </button>
                                <a href="?page=offers&edit=<?php echo (int)$offer['id']; ?>#offerFormCard"
                                   class="btn btn-sm btn-outline-warning" title="تعديل">
                                    <i class="bi bi-pencil"></i> تعديل
                                </a>
                                <button type="button" class="btn btn-sm btn-outline-danger"
                                        onclick="confirmDeleteOffer(<?php echo (int)$offer['id']; ?>, <?php echo htmlspecialchars(json_encode($offer['name']), ENT_QUOTES); ?>)"
                                        title="حذف">
                                    <i class="bi bi-trash"></i> حذف
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- مودال عرض التفاصيل -->
<div class="modal fade" id="offerDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-tags me-2"></i><span id="offerDetailTitle">تفاصيل العرض</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="offerDetailBody">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- مودال تأكيد الحذف -->
<div class="modal fade" id="deleteOfferModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-danger"><i class="bi bi-exclamation-triangle me-2"></i>تأكيد الحذف</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                هل أنت متأكد من حذف العرض "<strong id="deleteOfferName"></strong>"؟<br>
                <span class="text-danger small">سيتم حذف جميع منتجات العرض نهائياً.</span>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="delete_offer">
                    <input type="hidden" name="offer_id" id="deleteOfferIdInput">
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash me-1"></i>حذف نهائياً
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    // ——— بيانات المنتجات ———
    const allProducts = <?php echo json_encode(array_values($allProducts), JSON_UNESCAPED_UNICODE); ?>;
    let offerRowIndex = <?php echo $editOffer && !empty($editItems) ? count($editItems) : 0; ?>;

    const itemsBody = document.getElementById('offerItemsBody');
    const addBtn    = document.getElementById('addOfferItemBtn');
    const calcTotal = document.getElementById('offerCalcTotal');

    const escHtml = v => String(v || '').replace(/[&<>"']/g, c =>
        ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    const recalc = () => {
        let total = 0;
        itemsBody.querySelectorAll('tr').forEach(row => {
            const qty = parseFloat(row.querySelector('.offer-qty-input')?.value || 0);
            const up  = parseFloat(row.querySelector('.offer-up-input')?.value || 0);
            if (qty > 0 && up > 0) total += qty * up;
        });
        if (calcTotal) calcTotal.textContent = total.toLocaleString('ar-EG', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' ج.م';
    };

    // ——— منطق البحث في المنتجات ———
    const filterProducts = (q) => {
        if (!q) return allProducts;
        const lower = q.toLowerCase();
        return allProducts.filter(p =>
            (p.name || '').toLowerCase().includes(lower) ||
            (p.category || '').toLowerCase().includes(lower)
        );
    };

    const selectProduct = (row, product) => {
        const searchInput = row.querySelector('.offer-search-input');
        const hiddenSel   = row.querySelector('.offer-product-select');
        const dropdown    = row.querySelector('.offer-search-dropdown');
        const unitLabel   = row.querySelector('.offer-unit-label');
        const upInput     = row.querySelector('.offer-up-input');

        // تحديث الـ hidden select
        hiddenSel.innerHTML = `<option value="${product.id}" selected
            data-unit="${escHtml(product.unit || 'وحدة')}"
            data-unit-price="${parseFloat(product.unit_price || 0).toFixed(2)}">
            ${escHtml(product.name)}
        </option>`;

        // تحديث نص الـ input
        searchInput.value = (product.category ? product.category + ' - ' : '') + product.name;
        searchInput.classList.add('has-value');

        // تحديث الوحدة وسعر الوحدة
        if (unitLabel) unitLabel.textContent = product.unit || '-';
        if (upInput && (!upInput.value || parseFloat(upInput.value) <= 0)) {
            const defPrice = parseFloat(product.unit_price || 0);
            if (defPrice > 0) upInput.value = defPrice.toFixed(2);
        }

        // إغلاق الـ dropdown
        dropdown.style.display = 'none';
        recalc();
    };

    const showDropdown = (row, results) => {
        const dropdown = row.querySelector('.offer-search-dropdown');
        if (!results.length) {
            dropdown.innerHTML = '<div class="osd-empty">لا توجد نتائج</div>';
        } else {
            dropdown.innerHTML = results.map(p => `
                <div class="osd-item" data-id="${p.id}">
                    ${escHtml(p.name)}
                    ${p.category ? `<span class="osd-cat">${escHtml(p.category)}</span>` : ''}
                </div>
            `).join('');
            // ربط أحداث النقر
            dropdown.querySelectorAll('.osd-item').forEach(item => {
                item.addEventListener('mousedown', (e) => {
                    e.preventDefault(); // منع blur قبل النقر
                    const pid = parseInt(item.dataset.id);
                    const product = allProducts.find(p => p.id === pid);
                    if (product) selectProduct(row, product);
                });
            });
        }
        dropdown.style.display = 'block';
    };

    const attachSearchEvents = (row) => {
        const searchInput = row.querySelector('.offer-search-input');
        const hiddenSel   = row.querySelector('.offer-product-select');
        const dropdown    = row.querySelector('.offer-search-dropdown');
        const qty         = row.querySelector('.offer-qty-input');
        const up          = row.querySelector('.offer-up-input');
        const del         = row.querySelector('.remove-offer-item');

        searchInput?.addEventListener('focus', () => {
            const q = searchInput.value.trim();
            // إذا كان النص هو اسم المنتج المختار، ابدأ بالنتائج الكاملة
            const results = filterProducts(hiddenSel?.value ? '' : q);
            showDropdown(row, results.slice(0, 60));
        });

        searchInput?.addEventListener('input', () => {
            const q = searchInput.value.trim();
            // مسح الاختيار الحالي عند الكتابة
            if (hiddenSel) {
                hiddenSel.innerHTML = '<option value="" selected></option>';
                searchInput.classList.remove('has-value');
            }
            const results = filterProducts(q);
            showDropdown(row, results.slice(0, 60));
        });

        searchInput?.addEventListener('blur', () => {
            // تأخير صغير لإتاحة حدث mousedown على الـ dropdown
            setTimeout(() => {
                dropdown.style.display = 'none';
                // إذا مُسح الاختيار ولم يُختر شيء → أعد النص الفارغ
                if (!hiddenSel?.value) {
                    searchInput.value = '';
                    searchInput.classList.remove('has-value');
                }
            }, 150);
        });

        qty?.addEventListener('input', recalc);
        up?.addEventListener('input', recalc);

        del?.addEventListener('click', () => {
            if (itemsBody.children.length > 1) {
                row.remove();
                recalc();
            }
        });
    };

    const addNewRow = (pid, qty, unitPrice) => {
        const idx = offerRowIndex++;
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>
                <div class="offer-product-wrapper">
                    <input type="text" class="form-control form-control-sm offer-search-input"
                           placeholder="ابحث عن منتج..." autocomplete="off">
                    <select class="offer-product-select visually-hidden"
                            name="items[${idx}][product_id]" required>
                        <option value=""></option>
                    </select>
                    <div class="offer-search-dropdown"></div>
                </div>
            </td>
            <td class="text-muted small"><span class="offer-unit-label">-</span></td>
            <td>
                <input type="number" class="form-control offer-qty-input" name="items[${idx}][quantity]"
                       value="${qty || ''}" step="any" min="0.001" required>
            </td>
            <td>
                <div class="input-group input-group-sm">
                    <input type="number" class="form-control offer-up-input" name="items[${idx}][unit_price]"
                           value="${unitPrice || ''}" step="0.01" min="0" placeholder="اختياري">
                    <span class="input-group-text">ج.م</span>
                </div>
            </td>
            <td>
                <button type="button" class="btn btn-outline-danger btn-sm remove-offer-item" title="حذف">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        `;
        itemsBody.appendChild(tr);
        attachSearchEvents(tr);

        // إذا تم تمرير product_id (مثلاً عند تحميل عرض) → اختره تلقائياً
        if (pid) {
            const product = allProducts.find(p => p.id === parseInt(pid));
            if (product) selectProduct(tr, product);
        }

        recalc();
    };

    // ربط أحداث الصفوف الموجودة (عند التعديل)
    itemsBody.querySelectorAll('tr').forEach(attachSearchEvents);

    // إضافة صف أول تلقائياً إذا لم يكن هناك صفوف
    if (itemsBody.children.length === 0) {
        addNewRow();
    }

    addBtn?.addEventListener('click', () => addNewRow());

    // ——— عرض تفاصيل العرض ———
    window.showOfferDetails = (offerId, offerName) => {
        document.getElementById('offerDetailTitle').textContent = 'تفاصيل العرض: ' + offerName;
        document.getElementById('offerDetailBody').innerHTML =
            '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div></div>';

        const modal = new bootstrap.Modal(document.getElementById('offerDetailsModal'));
        modal.show();

        fetch(`<?php echo getRelativeUrl('api/get_offer_details.php'); ?>?offer_id=${offerId}`)
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    document.getElementById('offerDetailBody').innerHTML =
                        '<div class="alert alert-danger">' + (data.message || 'خطأ في جلب البيانات') + '</div>';
                    return;
                }
                const offer = data.offer;
                const items = data.items;
                let rows = '';
                items.forEach(item => {
                    rows += `<tr>
                        <td>${escHtml(item.product_name)}</td>
                        <td class="text-muted">${escHtml(item.category || '')}</td>
                        <td>${parseFloat(item.quantity).toLocaleString('ar-EG', {maximumFractionDigits:3})} ${escHtml(item.unit || '')}</td>
                        <td>${item.unit_price ? parseFloat(item.unit_price).toLocaleString('ar-EG', {minimumFractionDigits:2}) + ' ج.م' : '—'}</td>
                    </tr>`;
                });
                document.getElementById('offerDetailBody').innerHTML = `
                    <div class="row mb-3">
                        <div class="col-sm-4"><strong>الاسم:</strong> ${escHtml(offer.name)}</div>
                        <div class="col-sm-4"><strong>السعر:</strong> <span class="text-success fw-bold">${parseFloat(offer.price).toLocaleString('ar-EG', {minimumFractionDigits:2})} ج.م</span></div>
                        <div class="col-sm-4"><strong>الحالة:</strong> ${offer.status === 'active' ? '<span class="badge bg-success">نشط</span>' : '<span class="badge bg-secondary">غير نشط</span>'}</div>
                        ${offer.notes ? `<div class="col-12 mt-1"><strong>ملاحظات:</strong> ${escHtml(offer.notes)}</div>` : ''}
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-0">
                            <thead class="table-light">
                                <tr><th>المنتج</th><th>القسم</th><th>الكمية</th><th>سعر الوحدة</th></tr>
                            </thead>
                            <tbody>${rows || '<tr><td colspan="4" class="text-center text-muted">لا توجد منتجات</td></tr>'}</tbody>
                        </table>
                    </div>
                `;
            })
            .catch(() => {
                document.getElementById('offerDetailBody').innerHTML =
                    '<div class="alert alert-danger">فشل الاتصال بالخادم.</div>';
            });
    };

    // ——— تأكيد الحذف ———
    window.confirmDeleteOffer = (offerId, offerName) => {
        document.getElementById('deleteOfferName').textContent = offerName;
        document.getElementById('deleteOfferIdInput').value = offerId;
        const modal = new bootstrap.Modal(document.getElementById('deleteOfferModal'));
        modal.show();
    };

    // الانتقال إلى النموذج عند التعديل
    <?php if ($editOffer): ?>
    document.getElementById('offerFormCard')?.scrollIntoView({behavior: 'smooth', block: 'start'});
    <?php endif; ?>

    recalc();
})();
</script>
