<?php
/**
 * صفحة منتجات الشركة - المدير
 * Company Products Page - Manager
 */

// تعيين ترميز UTF-8 ومنع الكاش عند التبديل بين تبويبات الشريط الجانبي
if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
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
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/table_styles.php';
require_once __DIR__ . '/../../includes/audit_log.php';

requireRole(['manager', 'accountant', 'developer', 'production']);

$currentUser = getCurrentUser();
$isProductionRole = ($currentUser['role'] ?? '') === 'production';
$redirectRole = $currentUser['role'] ?? 'manager'; // لإعادة التوجيه بعد العمليات (مدير/محاسب/عامل إنتاج)
$db = db();
$error = '';
$success = '';

// إنشاء جدول الأصناف إذا لم يكن موجوداً وإضافة الأصناف الافتراضية
try {
    $categoriesTableExists = $db->queryOne("SHOW TABLES LIKE 'product_categories'");
    if (empty($categoriesTableExists)) {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `product_categories` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `name` varchar(100) NOT NULL,
              `is_default` tinyint(1) DEFAULT 0,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `name` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
    // إضافة الأصناف الافتراضية إذا لم تكن موجودة
    $defaultCategories = ['عسل', 'زيت زيتون', 'كريمات', 'زيوت', 'تمور', 'اخري'];
    foreach ($defaultCategories as $catName) {
        try {
            $existing = $db->queryOne("SELECT id FROM product_categories WHERE name = ?", [$catName]);
            if (empty($existing)) {
                $db->execute(
                    "INSERT INTO product_categories (name, is_default) VALUES (?, 1)",
                    [$catName]
                );
            }
        } catch (Exception $e) {
            error_log('Error inserting default category ' . $catName . ': ' . $e->getMessage());
        }
    }
} catch (Exception $e) {
    error_log('Error creating product_categories table: ' . $e->getMessage());
}

// الحصول على قائمة الأصناف
$productCategories = [];
try {
    // التحقق من وجود الجدول أولاً
    $categoriesTableExists = $db->queryOne("SHOW TABLES LIKE 'product_categories'");
    if (!empty($categoriesTableExists)) {
        $productCategories = $db->query("SELECT id, name FROM product_categories ORDER BY is_default DESC, name ASC");
        
        // إذا كانت القائمة فارغة، إضافة الأصناف الافتراضية
        if (empty($productCategories)) {
            $defaultCategories = ['عسل', 'زيت زيتون', 'كريمات', 'زيوت', 'تمور', 'اخري'];
            foreach ($defaultCategories as $catName) {
                try {
                    $db->execute(
                        "INSERT IGNORE INTO product_categories (name, is_default) VALUES (?, 1)",
                        [$catName]
                    );
                } catch (Exception $e) {
                    error_log('Error inserting category ' . $catName . ': ' . $e->getMessage());
                }
            }
            // إعادة جلب القائمة
            $productCategories = $db->query("SELECT id, name FROM product_categories ORDER BY is_default DESC, name ASC");
        }
    } else {
        // الجدول غير موجود، استخدام قائمة افتراضية
        error_log('product_categories table does not exist');
    }
} catch (Exception $e) {
    error_log('Error fetching product categories: ' . $e->getMessage());
}

// في حالة عدم وجود أصناف، استخدام قائمة افتراضية
if (empty($productCategories)) {
    $productCategories = [
        ['id' => 1, 'name' => 'عسل'],
        ['id' => 2, 'name' => 'زيت زيتون'],
        ['id' => 3, 'name' => 'كريمات'],
        ['id' => 4, 'name' => 'زيوت'],
        ['id' => 6, 'name' => 'تمور'],
        ['id' => 5, 'name' => 'اخري']
    ];
}

// الحصول على رسائل النجاح والخطأ من session (بعد redirect)
$sessionSuccess = getSuccessMessage();
if ($sessionSuccess) {
    $success = $sessionSuccess;
}

$sessionError = getErrorMessage();
if ($sessionError) {
    $error = $sessionError;
}

// معالجة العمليات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_external_product') {
        $name = trim($_POST['product_name'] ?? '');
        $quantity = max(0, floatval($_POST['quantity'] ?? 0));
        $unitPrice = max(0, floatval($_POST['unit_price'] ?? 0));
        $unit = trim($_POST['unit'] ?? 'قطعة');
        $categoryId = intval($_POST['category_id'] ?? 0);
        $customCategory = trim($_POST['custom_category'] ?? '');
        
        if ($name === '') {
            $error = 'يرجى إدخال اسم المنتج.';
            // في حالة وجود خطأ في التحقق، إعادة التوجيه مع رسالة الخطأ
            preventDuplicateSubmission(
                null,
                ['page' => 'company_products'],
                null,
                $redirectRole,
                $error
            );
        } else {
            try {
                // التأكد من وجود الأعمدة المطلوبة
                try {
                    $productTypeColumn = $db->queryOne("SHOW COLUMNS FROM products LIKE 'product_type'");
                    if (empty($productTypeColumn)) {
                        $db->execute("ALTER TABLE `products` ADD COLUMN `product_type` ENUM('internal','external') DEFAULT 'internal' AFTER `category`");
                    }
                } catch (Exception $e) {
                    // العمود موجود بالفعل
                }
                
                // معالجة الصنف
                $categoryName = 'منتجات خارجية';
                if ($categoryId > 0) {
                    $category = $db->queryOne("SELECT name FROM product_categories WHERE id = ?", [$categoryId]);
                    if ($category) {
                        $categoryName = $category['name'];
                    }
                } elseif (!empty($customCategory)) {
                    // حفظ الصنف المخصص في جدول الأصناف
                    try {
                        $db->execute(
                            "INSERT INTO product_categories (name, is_default) VALUES (?, 0)",
                            [$customCategory]
                        );
                        $categoryName = $customCategory;
                    } catch (Exception $e) {
                        // الصنف موجود بالفعل
                        $categoryName = $customCategory;
                    }
                }
                
                $db->execute(
                    "INSERT INTO products (name, category, product_type, quantity, unit, unit_price, status)
                     VALUES (?, ?, 'external', ?, ?, ?, 'active')",
                    [$name, $categoryName, $quantity, $unit, $unitPrice]
                );
                
                $productId = $db->getLastInsertId();
                logAudit($currentUser['id'], 'create_external_product', 'product', $productId, null, [
                    'name' => $name,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'category' => $categoryName
                ]);
                
                // منع التكرار باستخدام redirect
                preventDuplicateSubmission(
                    'تم إضافة المنتج الخارجي بنجاح.',
                    ['page' => 'company_products'],
null,
                $redirectRole
            );
            } catch (Exception $e) {
                error_log('create_external_product error: ' . $e->getMessage());
                preventDuplicateSubmission(
                    null,
                    ['page' => 'company_products'],
                null,
                $redirectRole,
                    'تعذر إضافة المنتج الخارجي. يرجى المحاولة لاحقاً.'
                );
            }
        }
    } elseif ($action === 'update_external_product') {
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        if ($isAjax) {
            // تنظيف أي output مخزن مؤقتاً (HTML من الصفحة الرئيسية) قبل إرسال JSON
            while (ob_get_level() > 0) ob_end_clean();
        }
        // منع المحاسب وعامل الإنتاج من التعديل على المنتجات الخارجية
        if ($currentUser['role'] === 'accountant' || $currentUser['role'] === 'production') {
            if ($isAjax) {
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode(['success' => false, 'message' => 'ليس لديك صلاحية لتعديل المنتجات الخارجية.']);
                exit;
            }
            $error = 'ليس لديك صلاحية لتعديل المنتجات الخارجية.';
            preventDuplicateSubmission(null, ['page' => 'company_products'], null, $redirectRole, $error);
        } else {
            $productId = intval($_POST['product_id'] ?? 0);
            $name = trim($_POST['product_name'] ?? '');
            $quantity = max(0, floatval($_POST['quantity'] ?? 0));
            $unitPrice = max(0, floatval($_POST['unit_price'] ?? 0));
            $unit = trim($_POST['unit'] ?? 'قطعة');
            $categoryId = intval($_POST['category_id'] ?? 0);
            $customCategory = trim($_POST['custom_category'] ?? '');

            if ($productId <= 0 || $name === '') {
                if ($isAjax) {
                    header('Content-Type: application/json; charset=UTF-8');
                    echo json_encode(['success' => false, 'message' => 'بيانات غير صحيحة.']);
                    exit;
                }
                $error = 'بيانات غير صحيحة.';
                preventDuplicateSubmission(null, ['page' => 'company_products'], null, $redirectRole, $error);
            } else {
                try {
                    // معالجة الصنف
                    $categoryName = null; // null يعني عدم التغيير
                    if ($categoryId > 0) {
                        $category = $db->queryOne("SELECT name FROM product_categories WHERE id = ?", [$categoryId]);
                        if ($category) {
                            $categoryName = $category['name'];
                        }
                    } elseif (!empty($customCategory)) {
                        try {
                            $db->execute("INSERT INTO product_categories (name, is_default) VALUES (?, 0)", [$customCategory]);
                            $categoryName = $customCategory;
                        } catch (Exception $e) {
                            $categoryName = $customCategory;
                        }
                    }

                    // قراءة الصنف الحالي إذا لم يتغير
                    if ($categoryName === null) {
                        $existing = $db->queryOne("SELECT category FROM products WHERE id = ?", [$productId]);
                        $categoryName = $existing['category'] ?? null;
                    }

                    $db->execute(
                        "UPDATE products SET name = ?, category = ?, quantity = ?, unit = ?, unit_price = ?, updated_at = NOW()
                         WHERE id = ? AND product_type = 'external'",
                        [$name, $categoryName, $quantity, $unit, $unitPrice, $productId]
                    );

                    logAudit($currentUser['id'], 'update_external_product', 'product', $productId, null, [
                        'name' => $name,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'category' => $categoryName
                    ]);

                    if ($isAjax) {
                        header('Content-Type: application/json; charset=UTF-8');
                        echo json_encode([
                            'success' => true,
                            'message' => 'تم تحديث المنتج الخارجي بنجاح.',
                            'product' => [
                                'id'         => $productId,
                                'name'       => $name,
                                'quantity'   => $quantity,
                                'unit'       => $unit,
                                'unit_price' => $unitPrice,
                                'category'   => $categoryName,
                                'total_value'=> $quantity * $unitPrice,
                            ]
                        ]);
                        exit;
                    }

                    preventDuplicateSubmission('تم تحديث المنتج الخارجي بنجاح.', ['page' => 'company_products'], null, $redirectRole);
                } catch (Exception $e) {
                    error_log('update_external_product error: ' . $e->getMessage());
                    if ($isAjax) {
                        header('Content-Type: application/json; charset=UTF-8');
                        echo json_encode(['success' => false, 'message' => 'تعذر تحديث المنتج الخارجي. يرجى المحاولة لاحقاً.']);
                        exit;
                    }
                    preventDuplicateSubmission(null, ['page' => 'company_products'], null, $redirectRole, 'تعذر تحديث المنتج الخارجي. يرجى المحاولة لاحقاً.');
                }
            }
        }
    } elseif ($action === 'delete_external_product') {
        // منع المحاسب وعامل الإنتاج من الحذف على المنتجات الخارجية
        if ($currentUser['role'] === 'accountant' || $currentUser['role'] === 'production') {
            $error = 'ليس لديك صلاحية لحذف المنتجات الخارجية.';
            // في حالة عدم وجود صلاحية، إعادة التوجيه مع رسالة الخطأ
            preventDuplicateSubmission(
                null,
                ['page' => 'company_products'],
                null,
                $redirectRole,
                $error
            );
        } else {
            $productId = intval($_POST['product_id'] ?? 0);
            
            if ($productId <= 0) {
                $error = 'بيانات غير صحيحة.';
                // في حالة وجود خطأ في التحقق، إعادة التوجيه مع رسالة الخطأ
                preventDuplicateSubmission(
                    null,
                    ['page' => 'company_products'],
                null,
                $redirectRole,
                    $error
                );
            } else {
                try {
                    $db->execute(
                        "DELETE FROM products WHERE id = ? AND product_type = 'external'",
                        [$productId]
                    );
                    
                    logAudit($currentUser['id'], 'delete_external_product', 'product', $productId, null, []);
                    
                    // منع التكرار باستخدام redirect
                    preventDuplicateSubmission(
                        'تم حذف المنتج الخارجي بنجاح.',
                        ['page' => 'company_products'],
null,
                $redirectRole
            );
                } catch (Exception $e) {
                    error_log('delete_external_product error: ' . $e->getMessage());
                    preventDuplicateSubmission(
                        null,
                        ['page' => 'company_products'],
                null,
                $redirectRole,
                        'تعذر حذف المنتج الخارجي. يرجى المحاولة لاحقاً.'
                    );
                }
            }
        }
    } elseif ($action === 'add_quantity_external_product') {
        // إضافة كمية لمنتج خارجي (متاح لعامل الإنتاج والمدير والمحاسب)
        $productId = intval($_POST['product_id'] ?? 0);
        $quantityToAdd = max(0, floatval($_POST['quantity_to_add'] ?? 0));
        if ($productId <= 0 || $quantityToAdd <= 0) {
            $error = 'بيانات غير صحيحة. أدخل كمية صحيحة.';
            preventDuplicateSubmission(null, ['page' => 'company_products'], null, $redirectRole, $error);
        } else {
            try {
                $row = $db->queryOne("SELECT id, quantity FROM products WHERE id = ? AND product_type = 'external' AND status = 'active'", [$productId]);
                if (!$row) {
                    $error = 'المنتج غير موجود.';
                    preventDuplicateSubmission(null, ['page' => 'company_products'], null, $redirectRole, $error);
                } else {
                    $newQuantity = floatval($row['quantity'] ?? 0) + $quantityToAdd;
                    $db->execute("UPDATE products SET quantity = ? WHERE id = ?", [$newQuantity, $productId]);
                    logAudit($currentUser['id'], 'add_quantity_external_product', 'product', $productId, null, [
                        'quantity_added' => $quantityToAdd,
                        'new_quantity' => $newQuantity
                    ]);
                    preventDuplicateSubmission('تم إضافة الكمية بنجاح.', ['page' => 'company_products'], null, $redirectRole);
                }
            } catch (Exception $e) {
                error_log('add_quantity_external_product error: ' . $e->getMessage());
                preventDuplicateSubmission(null, ['page' => 'company_products'], null, $redirectRole, 'تعذر إضافة الكمية.');
            }
        }
    // ===== الفرز التاني =====
    } elseif ($action === 'create_second_grade_product') {
        if ($currentUser['role'] === 'accountant' || $currentUser['role'] === 'production') {
            preventDuplicateSubmission(null, ['page' => 'company_products'], null, $redirectRole, 'ليس لديك صلاحية لإضافة منتجات الفرز التاني.');
        } else {
            $name = trim($_POST['product_name'] ?? '');
            $quantity = max(0, floatval($_POST['quantity'] ?? 0));
            $unitPrice = max(0, floatval($_POST['unit_price'] ?? 0));
            $unit = trim($_POST['unit'] ?? 'قطعة');
            $categoryId = intval($_POST['category_id'] ?? 0);
            $customCategory = trim($_POST['custom_category'] ?? '');
            if ($name === '') {
                preventDuplicateSubmission(null, ['page' => 'company_products'], null, $redirectRole, 'يرجى إدخال اسم المنتج.');
            } else {
                try {
                    // ترقية ENUM إذا لزم
                    try {
                        $col = $db->queryOne("SHOW COLUMNS FROM products LIKE 'product_type'");
                        if ($col && strpos($col['Type'] ?? '', 'second_grade') === false) {
                            $db->execute("ALTER TABLE `products` MODIFY COLUMN `product_type` ENUM('internal','external','second_grade') DEFAULT 'internal'");
                        }
                    } catch (Exception $e) {}

                    $categoryName = 'فرز تاني';
                    if ($categoryId > 0) {
                        $cat = $db->queryOne("SELECT name FROM product_categories WHERE id = ?", [$categoryId]);
                        if ($cat) $categoryName = $cat['name'];
                    } elseif (!empty($customCategory)) {
                        try { $db->execute("INSERT INTO product_categories (name, is_default) VALUES (?, 0)", [$customCategory]); } catch (Exception $e) {}
                        $categoryName = $customCategory;
                    }

                    $db->execute(
                        "INSERT INTO products (name, category, product_type, quantity, unit, unit_price, status) VALUES (?, ?, 'second_grade', ?, ?, ?, 'active')",
                        [$name, $categoryName, $quantity, $unit, $unitPrice]
                    );
                    $productId = $db->getLastInsertId();
                    logAudit($currentUser['id'], 'create_second_grade_product', 'product', $productId, null, ['name' => $name, 'quantity' => $quantity, 'unit_price' => $unitPrice]);
                    preventDuplicateSubmission('تم إضافة منتج الفرز التاني بنجاح.', ['page' => 'company_products'], null, $redirectRole);
                } catch (Exception $e) {
                    error_log('create_second_grade_product error: ' . $e->getMessage());
                    preventDuplicateSubmission(null, ['page' => 'company_products'], null, $redirectRole, 'تعذر إضافة المنتج. يرجى المحاولة لاحقاً.');
                }
            }
        }

    } elseif ($action === 'update_second_grade_product') {
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        if ($isAjax) { while (ob_get_level() > 0) ob_end_clean(); }
        if ($currentUser['role'] === 'accountant' || $currentUser['role'] === 'production') {
            if ($isAjax) { header('Content-Type: application/json; charset=UTF-8'); echo json_encode(['success' => false, 'message' => 'ليس لديك صلاحية.']); exit; }
            preventDuplicateSubmission(null, ['page' => 'company_products'], null, $redirectRole, 'ليس لديك صلاحية.');
        } else {
            $productId = intval($_POST['product_id'] ?? 0);
            $name = trim($_POST['product_name'] ?? '');
            $quantity = max(0, floatval($_POST['quantity'] ?? 0));
            $unitPrice = max(0, floatval($_POST['unit_price'] ?? 0));
            $unit = trim($_POST['unit'] ?? 'قطعة');
            $categoryId = intval($_POST['category_id'] ?? 0);
            $customCategory = trim($_POST['custom_category'] ?? '');
            if ($productId <= 0 || $name === '') {
                if ($isAjax) { header('Content-Type: application/json; charset=UTF-8'); echo json_encode(['success' => false, 'message' => 'بيانات غير صحيحة.']); exit; }
                preventDuplicateSubmission(null, ['page' => 'company_products'], null, $redirectRole, 'بيانات غير صحيحة.');
            } else {
                try {
                    $categoryName = null;
                    if ($categoryId > 0) {
                        $cat = $db->queryOne("SELECT name FROM product_categories WHERE id = ?", [$categoryId]);
                        if ($cat) $categoryName = $cat['name'];
                    } elseif (!empty($customCategory)) {
                        try { $db->execute("INSERT INTO product_categories (name, is_default) VALUES (?, 0)", [$customCategory]); } catch (Exception $e) {}
                        $categoryName = $customCategory;
                    }
                    if ($categoryName === null) {
                        $ex = $db->queryOne("SELECT category FROM products WHERE id = ?", [$productId]);
                        $categoryName = $ex['category'] ?? null;
                    }
                    $db->execute(
                        "UPDATE products SET name = ?, category = ?, quantity = ?, unit = ?, unit_price = ?, updated_at = NOW() WHERE id = ? AND product_type = 'second_grade'",
                        [$name, $categoryName, $quantity, $unit, $unitPrice, $productId]
                    );
                    logAudit($currentUser['id'], 'update_second_grade_product', 'product', $productId, null, ['name' => $name, 'quantity' => $quantity, 'unit_price' => $unitPrice]);
                    if ($isAjax) {
                        header('Content-Type: application/json; charset=UTF-8');
                        echo json_encode(['success' => true, 'message' => 'تم تحديث المنتج بنجاح.', 'product' => ['id' => $productId, 'name' => $name, 'quantity' => $quantity, 'unit' => $unit, 'unit_price' => $unitPrice, 'category' => $categoryName, 'total_value' => $quantity * $unitPrice]]);
                        exit;
                    }
                    preventDuplicateSubmission('تم تحديث منتج الفرز التاني بنجاح.', ['page' => 'company_products'], null, $redirectRole);
                } catch (Exception $e) {
                    error_log('update_second_grade_product error: ' . $e->getMessage());
                    if ($isAjax) { header('Content-Type: application/json; charset=UTF-8'); echo json_encode(['success' => false, 'message' => 'تعذر تحديث المنتج.']); exit; }
                    preventDuplicateSubmission(null, ['page' => 'company_products'], null, $redirectRole, 'تعذر تحديث المنتج.');
                }
            }
        }

    } elseif ($action === 'delete_second_grade_product') {
        if ($currentUser['role'] === 'accountant' || $currentUser['role'] === 'production') {
            preventDuplicateSubmission(null, ['page' => 'company_products'], null, $redirectRole, 'ليس لديك صلاحية.');
        } else {
            $productId = intval($_POST['product_id'] ?? 0);
            if ($productId <= 0) {
                preventDuplicateSubmission(null, ['page' => 'company_products'], null, $redirectRole, 'بيانات غير صحيحة.');
            } else {
                try {
                    $db->execute("DELETE FROM products WHERE id = ? AND product_type = 'second_grade'", [$productId]);
                    logAudit($currentUser['id'], 'delete_second_grade_product', 'product', $productId, null, []);
                    preventDuplicateSubmission('تم حذف المنتج بنجاح.', ['page' => 'company_products'], null, $redirectRole);
                } catch (Exception $e) {
                    error_log('delete_second_grade_product error: ' . $e->getMessage());
                    preventDuplicateSubmission(null, ['page' => 'company_products'], null, $redirectRole, 'تعذر حذف المنتج.');
                }
            }
        }

    } elseif ($action === 'add_quantity_second_grade_product') {
        $productId = intval($_POST['product_id'] ?? 0);
        $quantityToAdd = max(0, floatval($_POST['quantity_to_add'] ?? 0));
        if ($productId <= 0 || $quantityToAdd <= 0) {
            preventDuplicateSubmission(null, ['page' => 'company_products'], null, $redirectRole, 'بيانات غير صحيحة. أدخل كمية صحيحة.');
        } else {
            try {
                $row = $db->queryOne("SELECT id, quantity FROM products WHERE id = ? AND product_type = 'second_grade' AND status = 'active'", [$productId]);
                if (!$row) {
                    preventDuplicateSubmission(null, ['page' => 'company_products'], null, $redirectRole, 'المنتج غير موجود.');
                } else {
                    $newQuantity = floatval($row['quantity'] ?? 0) + $quantityToAdd;
                    $db->execute("UPDATE products SET quantity = ? WHERE id = ?", [$newQuantity, $productId]);
                    logAudit($currentUser['id'], 'add_quantity_second_grade_product', 'product', $productId, null, ['quantity_added' => $quantityToAdd, 'new_quantity' => $newQuantity]);
                    preventDuplicateSubmission('تم إضافة الكمية بنجاح.', ['page' => 'company_products'], null, $redirectRole);
                }
            } catch (Exception $e) {
                error_log('add_quantity_second_grade_product error: ' . $e->getMessage());
                preventDuplicateSubmission(null, ['page' => 'company_products'], null, $redirectRole, 'تعذر إضافة الكمية.');
            }
        }

    } elseif ($action === 'add_quantity_factory_product') {
        // إضافة كمية لمنتج مصنع (تشغيلة) - متاح لعامل الإنتاج والمدير والمحاسب
        $batchId = intval($_POST['batch_id'] ?? 0);
        $quantityToAdd = max(0, floatval($_POST['quantity_to_add'] ?? 0));
        if ($batchId <= 0 || $quantityToAdd <= 0) {
            $error = 'بيانات غير صحيحة. أدخل كمية صحيحة.';
            preventDuplicateSubmission(null, ['page' => 'company_products'], null, $redirectRole, $error);
        } else {
            try {
                $row = $db->queryOne("SELECT id, quantity_produced FROM finished_products WHERE id = ?", [$batchId]);
                if (!$row) {
                    $error = 'التشغيلة غير موجودة.';
                    preventDuplicateSubmission(null, ['page' => 'company_products'], null, $redirectRole, $error);
                } else {
                    $currentQty = floatval($row['quantity_produced'] ?? 0);
                    $newQuantity = $currentQty + $quantityToAdd;
                    $db->execute("UPDATE finished_products SET quantity_produced = ? WHERE id = ?", [$newQuantity, $batchId]);
                    logAudit($currentUser['id'], 'add_quantity_factory_product', 'finished_product', $batchId, null, [
                        'quantity_added' => $quantityToAdd,
                        'new_quantity' => $newQuantity
                    ]);
                    preventDuplicateSubmission('تم إضافة الكمية بنجاح.', ['page' => 'company_products'], null, $redirectRole);
                }
            } catch (Exception $e) {
                error_log('add_quantity_factory_product error: ' . $e->getMessage());
                preventDuplicateSubmission(null, ['page' => 'company_products'], null, $redirectRole, 'تعذر إضافة الكمية.');
            }
        }
    } elseif ($action === 'update_factory_product_category') {
        // منع عامل الإنتاج من تعديل الصنف
        if ($currentUser['role'] === 'production') {
            $error = 'ليس لديك صلاحية لتعديل صنف المنتج.';
            preventDuplicateSubmission(null, ['page' => 'company_products'], null, $redirectRole, $error);
        }
        $batchId = intval($_POST['batch_id'] ?? 0);
        $categoryId = intval($_POST['category_id'] ?? 0);
        $customCategory = trim($_POST['custom_category'] ?? '');
        
        if ($batchId <= 0) {
            $error = 'بيانات غير صحيحة.';
            preventDuplicateSubmission(
                null,
                ['page' => 'company_products'],
                null,
                $redirectRole,
                $error
            );
        } else {
            try {
                // معالجة الصنف
                $categoryName = null;
                if ($categoryId > 0) {
                    $category = $db->queryOne("SELECT name FROM product_categories WHERE id = ?", [$categoryId]);
                    if ($category) {
                        $categoryName = $category['name'];
                    }
                } elseif (!empty($customCategory)) {
                    // حفظ الصنف المخصص في جدول الأصناف
                    try {
                        $db->execute(
                            "INSERT INTO product_categories (name, is_default) VALUES (?, 0)",
                            [$customCategory]
                        );
                        $categoryName = $customCategory;
                    } catch (Exception $e) {
                        // الصنف موجود بالفعل
                        $categoryName = $customCategory;
                    }
                }
                
                if ($categoryName !== null) {
                    // تحديث الصنف في جدول products المرتبط بـ finished_products
                    $finishedProduct = $db->queryOne("
                        SELECT product_id FROM finished_products WHERE id = ?
                    ", [$batchId]);
                    
                    if ($finishedProduct && $finishedProduct['product_id']) {
                        $db->execute(
                            "UPDATE products SET category = ? WHERE id = ?",
                            [$categoryName, $finishedProduct['product_id']]
                        );
                    }
                }
                
                logAudit($currentUser['id'], 'update_factory_product_category', 'finished_product', $batchId, null, [
                    'category' => $categoryName
                ]);
                
                preventDuplicateSubmission(
                    'تم تحديث صنف المنتج بنجاح.',
                    ['page' => 'company_products'],
null,
                $redirectRole
            );
            } catch (Exception $e) {
                error_log('update_factory_product_category error: ' . $e->getMessage());
                preventDuplicateSubmission(
                    null,
                    ['page' => 'company_products'],
                null,
                $redirectRole,
                    'تعذر تحديث صنف المنتج. يرجى المحاولة لاحقاً.'
                );
            }
        }
    } elseif ($action === 'update_factory_product_price') {
        // منع عامل الإنتاج من تعديل السعر
        if ($currentUser['role'] === 'production') {
            $error = 'ليس لديك صلاحية لتعديل سعر المنتج.';
            preventDuplicateSubmission(null, ['page' => 'company_products'], null, $redirectRole, $error);
        }
        // تعديل سعر منتج المصنع (متاح للمدير والمحاسب)
        $batchId = intval($_POST['batch_id'] ?? 0);
        $unitPrice = max(0, floatval($_POST['unit_price'] ?? 0));

        if ($batchId <= 0) {
            $error = 'بيانات غير صحيحة.';
            preventDuplicateSubmission(
                null,
                ['page' => 'company_products'],
                null,
                $redirectRole,
                $error
            );
        } else {
            try {
                $db->execute(
                    "UPDATE finished_products SET unit_price = ? WHERE id = ?",
                    [$unitPrice, $batchId]
                );
                logAudit($currentUser['id'], 'update_factory_product_price', 'finished_product', $batchId, null, [
                    'unit_price' => $unitPrice
                ]);
                preventDuplicateSubmission(
                    'تم تحديث سعر المنتج بنجاح.',
                    ['page' => 'company_products'],
null,
                $redirectRole
            );
            } catch (Exception $e) {
                error_log('update_factory_product_price error: ' . $e->getMessage());
                preventDuplicateSubmission(
                    null,
                    ['page' => 'company_products'],
                null,
                $redirectRole,
                    'تعذر تحديث السعر. يرجى المحاولة لاحقاً.'
                );
            }
        }
    } elseif ($action === 'create_factory_product') {
        $productName = trim($_POST['product_name'] ?? '');
        $categoryId = intval($_POST['category_id'] ?? 0);
        $customCategory = trim($_POST['custom_category'] ?? '');
        $productionDate = trim($_POST['production_date'] ?? '');
        $expiryDate = trim($_POST['expiry_date'] ?? '');
        $quantityProduced = max(0.01, floatval($_POST['quantity_produced'] ?? 0));
        $unitPrice = max(0, floatval($_POST['unit_price'] ?? 0));
        
        if ($productName === '' || $productionDate === '' || $quantityProduced <= 0) {
            $error = 'يرجى إدخال جميع البيانات المطلوبة.';
            preventDuplicateSubmission(
                null,
                ['page' => 'company_products'],
                null,
                $redirectRole,
                $error
            );
        } else {
            try {
                require_once __DIR__ . '/../../includes/batch_creation.php';
                
                // معالجة الصنف
                $categoryName = 'اخري';
                if ($categoryId > 0) {
                    $category = $db->queryOne("SELECT name FROM product_categories WHERE id = ?", [$categoryId]);
                    if ($category) {
                        $categoryName = $category['name'];
                    }
                } elseif (!empty($customCategory)) {
                    // حفظ الصنف المخصص في جدول الأصناف
                    try {
                        $db->execute(
                            "INSERT INTO product_categories (name, is_default) VALUES (?, 0)",
                            [$customCategory]
                        );
                        $categoryName = $customCategory;
                    } catch (Exception $e) {
                        // الصنف موجود بالفعل
                        $categoryName = $customCategory;
                    }
                }
                
                // التحقق من وجود جدول finished_products
                $finishedProductsTableExists = $db->queryOne("SHOW TABLES LIKE 'finished_products'");
                if (empty($finishedProductsTableExists)) {
                    throw new Exception('جدول finished_products غير موجود');
                }
                
                // التحقق من وجود جدول batches
                $batchesTableExists = $db->queryOne("SHOW TABLES LIKE 'batches'");
                if (empty($batchesTableExists)) {
                    throw new Exception('جدول batches غير موجود');
                }
                
                // توليد رقم باركود باستخدام دالة batchCreationGenerateNumber
                $pdo = batchCreationGetPdo();
                $batchNumber = batchCreationGenerateNumber($pdo);
                
                // إنشاء batch_id في جدول batches
                $batchInsertResult = $db->execute(
                    "INSERT INTO batches (batch_number, product_id, production_date, expiry_date, quantity, status)
                     VALUES (?, NULL, ?, ?, ?, 'completed')",
                    [$batchNumber, $productionDate, !empty($expiryDate) ? $expiryDate : null, (int)$quantityProduced]
                );
                
                $batchId = $batchInsertResult['insert_id'] ?? $db->getLastInsertId();
                
                if (empty($batchId)) {
                    throw new Exception('فشل في إنشاء batch_id');
                }
                
                // البحث عن product_id من جدول products أو إنشاء منتج جديد
                $productId = null;
                $existingProduct = $db->queryOne(
                    "SELECT id FROM products WHERE name = ? AND category = ? LIMIT 1",
                    [$productName, $categoryName]
                );
                
                if ($existingProduct) {
                    $productId = $existingProduct['id'];
                } else {
                    // إنشاء منتج جديد في جدول products
                    $productInsertResult = $db->execute(
                        "INSERT INTO products (name, category, product_type, quantity, unit, unit_price, status)
                         VALUES (?, ?, 'internal', 0, 'قطعة', ?, 'active')",
                        [$productName, $categoryName, $unitPrice > 0 ? $unitPrice : 0]
                    );
                    $productId = $productInsertResult['insert_id'] ?? $db->getLastInsertId();
                }
                
                // إدراج المنتج في جدول finished_products
                $db->execute(
                    "INSERT INTO finished_products (batch_id, product_id, product_name, batch_number, production_date, expiry_date, quantity_produced, unit_price)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $batchId,
                        $productId,
                        $productName,
                        $batchNumber,
                        $productionDate,
                        !empty($expiryDate) ? $expiryDate : null,
                        (int)$quantityProduced,
                        $unitPrice > 0 ? $unitPrice : null
                    ]
                );
                
                $finishedProductId = $db->getLastInsertId();
                
                logAudit($currentUser['id'] ?? null, 'create_factory_product', 'finished_product', $finishedProductId, null, [
                    'product_name' => $productName,
                    'batch_number' => $batchNumber,
                    'category' => $categoryName,
                    'quantity_produced' => $quantityProduced,
                    'unit_price' => $unitPrice
                ]);
                
                preventDuplicateSubmission(
                    'تم إضافة منتج المصنع بنجاح. رقم الباركود: ' . $batchNumber,
                    ['page' => 'company_products'],
null,
                $redirectRole
            );
            } catch (Exception $e) {
                error_log('create_factory_product error: ' . $e->getMessage());
                preventDuplicateSubmission(
                    null,
                    ['page' => 'company_products'],
                null,
                $redirectRole,
                    'تعذر إضافة منتج المصنع. يرجى المحاولة لاحقاً: ' . $e->getMessage()
                );
            }
        }
    }
}

// الحصول على منتجات المصنع (من جدول finished_products - كل تشغيلة منفصلة)
$factoryProducts = [];
try {
    // التحقق من وجود جدول finished_products
    $finishedProductsTableExists = $db->queryOne("SHOW TABLES LIKE 'finished_products'");
    
    if (!empty($finishedProductsTableExists)) {
        $factoryProducts = $db->query("
            SELECT 
                base.id,
                base.batch_id,
                base.batch_number,
                base.product_id,
                base.product_name,
                base.product_category,
                base.production_date,
                base.quantity_produced,
                base.unit_price,
                base.total_price,
                CASE 
                    WHEN base.unit_price > 0 
                        THEN (base.unit_price * COALESCE(base.quantity_produced, 0))
                    WHEN base.unit_price = 0 AND base.total_price IS NOT NULL AND base.total_price > 0 
                        THEN base.total_price
                    ELSE 0
                END AS calculated_total_price,
                base.workers
            FROM (
                SELECT 
                    fp.id,
                    fp.batch_id,
                    fp.batch_number,
                    COALESCE(fp.product_id, bn.product_id) AS product_id,
                    COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name, 'غير محدد') AS product_name,
                    pr.category as product_category,
                    fp.production_date,
                    fp.quantity_produced,
                    COALESCE(
                        NULLIF(fp.unit_price, 0),
                        (SELECT pt.unit_price 
                         FROM product_templates pt 
                         WHERE pt.status = 'active' 
                           AND pt.unit_price IS NOT NULL 
                           AND pt.unit_price > 0
                           AND pt.unit_price <= 10000
                           AND (
                               -- مطابقة product_id أولاً (الأكثر دقة)
                               (
                                   COALESCE(fp.product_id, bn.product_id) IS NOT NULL 
                                   AND COALESCE(fp.product_id, bn.product_id) > 0
                                   AND pt.product_id IS NOT NULL 
                                   AND pt.product_id > 0 
                                   AND pt.product_id = COALESCE(fp.product_id, bn.product_id)
                               )
                               -- مطابقة product_name (مطابقة دقيقة أو جزئية)
                               OR (
                                   pt.product_name IS NOT NULL 
                                   AND pt.product_name != ''
                                   AND COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name) IS NOT NULL
                                   AND COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name) != ''
                                   AND (
                                       LOWER(TRIM(pt.product_name)) = LOWER(TRIM(COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name)))
                                       OR LOWER(TRIM(pt.product_name)) LIKE CONCAT('%', LOWER(TRIM(COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name))), '%')
                                       OR LOWER(TRIM(COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name))) LIKE CONCAT('%', LOWER(TRIM(pt.product_name)), '%')
                                   )
                               )
                               -- إذا لم يكن هناك product_id في القالب، نبحث فقط بالاسم
                               OR (
                                   (pt.product_id IS NULL OR pt.product_id = 0)
                                   AND pt.product_name IS NOT NULL 
                                   AND pt.product_name != ''
                                   AND COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name) IS NOT NULL
                                   AND COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name) != ''
                                   AND (
                                       LOWER(TRIM(pt.product_name)) = LOWER(TRIM(COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name)))
                                       OR LOWER(TRIM(pt.product_name)) LIKE CONCAT('%', LOWER(TRIM(COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name))), '%')
                                       OR LOWER(TRIM(COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name))) LIKE CONCAT('%', LOWER(TRIM(pt.product_name)), '%')
                                   )
                               )
                           )
                         ORDER BY pt.unit_price DESC
                         LIMIT 1),
                        0
                    ) AS unit_price,
                    fp.total_price,
                    GROUP_CONCAT(DISTINCT u.full_name ORDER BY u.full_name SEPARATOR ', ') AS workers
                FROM finished_products fp
                LEFT JOIN batch_numbers bn ON fp.batch_number = bn.batch_number
                LEFT JOIN products pr ON COALESCE(fp.product_id, bn.product_id) = pr.id
                LEFT JOIN batch_workers bw ON fp.batch_id = bw.batch_id
                LEFT JOIN users u ON bw.employee_id = u.id
                WHERE (fp.quantity_produced IS NULL OR fp.quantity_produced > 0)
                GROUP BY fp.id
            ) AS base
            ORDER BY base.production_date DESC, base.id DESC
        ");
    }
} catch (Exception $e) {
    error_log('Error fetching factory products from finished_products: ' . $e->getMessage());
}

// حساب الكمية المتاحة لكل منتج من منتجات المصنع (طرح المبيعات والطلبات المعلقة)
if (!empty($factoryProducts)) {
    foreach ($factoryProducts as &$product) {
        $quantityProduced = (float)($product['quantity_produced'] ?? 0);
        $batchNumber = $product['batch_number'] ?? '';
        $batchId = $product['id'] ?? null;
        
        // حساب الكمية المتاحة (طرح المبيعات والطلبات المعلقة)
        $soldQty = 0;
        $pendingQty = 0;
        $pendingShippingQty = 0;
        
        if (!empty($batchNumber) && $batchId) {
            try {
                // حساب الكمية المباعة
                $sold = $db->queryOne("
                    SELECT COALESCE(SUM(ii.quantity), 0) AS sold_quantity
                    FROM invoice_items ii
                    INNER JOIN invoices i ON ii.invoice_id = i.id
                    INNER JOIN sales_batch_numbers sbn ON ii.id = sbn.invoice_item_id
                    INNER JOIN batch_numbers bn ON sbn.batch_number_id = bn.id
                    WHERE bn.batch_number = ?
                ", [$batchNumber]);
                $soldQty = (float)($sold['sold_quantity'] ?? 0);
                
                // حساب الكمية المحجوزة في طلبات العملاء المعلقة
                $pending = $db->queryOne("
                    SELECT COALESCE(SUM(oi.quantity), 0) AS pending_quantity
                    FROM customer_order_items oi
                    INNER JOIN customer_orders co ON oi.order_id = co.id
                    INNER JOIN finished_products fp2 ON fp2.product_id = oi.product_id AND fp2.batch_number = ?
                    WHERE co.status = 'pending'
                ", [$batchNumber]);
                $pendingQty = (float)($pending['pending_quantity'] ?? 0);
                
                // ملاحظة: لا نحتاج لخصم طلبات الشحن من quantity_produced
                // لأن quantity_produced يتم تحديثه مباشرة عند إنشاء طلب الشحن
                // (يتم خصم الكمية منه عبر recordInventoryMovement)
                // لذلك quantity_produced يحتوي بالفعل على الكمية المتبقية بعد خصم طلبات الشحن
                // نحسب pendingShippingQty فقط للعرض (للمعلومات) وليس للخصم
                $pendingShipping = $db->queryOne("
                    SELECT COALESCE(SUM(soi.quantity), 0) AS pending_quantity
                    FROM shipping_company_order_items soi
                    INNER JOIN shipping_company_orders sco ON soi.order_id = sco.id
                    WHERE sco.status = 'in_transit'
                      AND soi.batch_id = ?
                ", [$batchId]);
                $pendingShippingQty = (float)($pendingShipping['pending_quantity'] ?? 0);
            } catch (Throwable $calcError) {
                error_log('company_products: error calculating available quantity for batch ' . $batchNumber . ': ' . $calcError->getMessage());
            }
        }
        
        // حساب الكمية المتاحة
        // ملاحظة: quantity_produced يتم تحديثه تلقائياً عند المبيعات وطلبات الشحن
        // لذلك نحتاج فقط خصم طلبات العملاء المعلقة (pendingQty)
        $product['available_quantity'] = max(0, $quantityProduced - $pendingQty);
        $product['sold_quantity'] = $soldQty;
        $product['pending_quantity'] = $pendingQty;
        $product['pending_shipping_quantity'] = $pendingShippingQty;
    }
    unset($product); // إلغاء المرجع لتجنب التأثير على المتغيرات المستقبلية
}

// الحصول على المنتجات الخارجية
$externalProducts = [];
try {
    $externalProducts = $db->query("
        SELECT 
            id,
            name,
            COALESCE(category, '') as category,
            quantity,
            COALESCE(unit, 'قطعة') as unit,
            unit_price,
            (quantity * unit_price) as total_value,
            created_at,
            updated_at
        FROM products
        WHERE product_type = 'external'
          AND status = 'active'
        ORDER BY name ASC
    ");
} catch (Exception $e) {
    error_log('Error fetching external products: ' . $e->getMessage());
}

// الحصول على منتجات الفرز التاني
$secondGradeProducts = [];
try {
    $secondGradeProducts = $db->query("
        SELECT
            id,
            name,
            COALESCE(category, '') as category,
            quantity,
            COALESCE(unit, 'قطعة') as unit,
            unit_price,
            (quantity * unit_price) as total_value,
            created_at,
            updated_at
        FROM products
        WHERE product_type = 'second_grade'
          AND status = 'active'
        ORDER BY name ASC
    ");
} catch (Exception $e) {
    error_log('Error fetching second_grade products: ' . $e->getMessage());
}

// الحصول على قوالب المنتجات
$productTemplates = [];
try {
    $productTemplates = $db->query("
        SELECT 
            pt.id,
            pt.product_name,
            pt.unit_price,
            pt.status,
            pt.created_at,
            COALESCE(SUM(fp.quantity_produced), 0) as available_quantity
        FROM product_templates pt
        LEFT JOIN finished_products fp ON fp.product_name = pt.product_name
        WHERE pt.status = 'active'
        GROUP BY pt.id, pt.product_name, pt.unit_price, pt.status, pt.created_at
        ORDER BY pt.product_name ASC
    ");
} catch (Exception $e) {
    error_log('Error fetching product templates: ' . $e->getMessage());
}

// إحصائيات
$totalFactoryProducts = count($factoryProducts);
$totalExternalProducts = count($externalProducts);
$totalSecondGradeProducts = count($secondGradeProducts);
$totalProductTemplates = count($productTemplates);
$totalExternalValue = 0;
foreach ($externalProducts as $ext) {
    $totalExternalValue += floatval($ext['total_value'] ?? 0);
}
$totalSecondGradeValue = 0;
foreach ($secondGradeProducts as $sg) {
    $totalSecondGradeValue += floatval($sg['total_value'] ?? 0);
}

// حساب القيمة الإجمالية لمنتجات المصنع بناءً على الكمية المتاحة
$totalFactoryValue = 0;
foreach ($factoryProducts as $product) {
    $unitPrice = floatval($product['unit_price'] ?? 0);
    $availableQuantity = floatval($product['available_quantity'] ?? $product['quantity_produced'] ?? 0);
    $totalPrice = $unitPrice * $availableQuantity;
    $totalFactoryValue += $totalPrice;
}
?>

<style>
* {
    box-sizing: border-box;
}

.company-products-page {
    padding: 1.5rem 0;
    width: 100%;
    max-width: 100%;
    overflow-x: hidden;
    box-sizing: border-box;
}

/* ضمان أن جميع العناصر داخل الصفحة لا تتجاوز العرض */
.company-products-page * {
    max-width: 100%;
    box-sizing: border-box;
}

.section-header {
    background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%);
    color: white;
    padding: 1.5rem 1.75rem;
    border-radius: 16px 16px 0 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 4px 12px rgba(29, 78, 216, 0.15);
    width: 100%;
    max-width: 100%;
    flex-wrap: wrap;
    gap: 1rem;
    cursor: pointer;
    user-select: none;
}

.section-header .collapse-arrow {
    transition: transform 0.25s ease;
    font-size: 1.1rem;
    opacity: 0.85;
    margin-inline-start: 0.5rem;
    flex-shrink: 0;
}

.section-header.collapsed .collapse-arrow {
    transform: rotate(-90deg);
}

.section-collapse-body {
    transition: none;
}

.section-collapse-body.collapsing-hide {
    display: none;
}

.section-header h5 {
    margin: 0;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    font-size: 1.15rem;
}

.section-header h5 i {
    font-size: 1.4rem;
    opacity: 0.95;
}

.section-header .badge {
    background: rgba(255, 255, 255, 0.25);
    color: white;
    padding: 0.45rem 0.9rem;
    border-radius: 25px;
    font-size: 0.875rem;
    font-weight: 600;
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.company-card {
    border: none;
    border-radius: 16px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
    margin-bottom: 2.5rem;
    overflow: hidden;
    background: #ffffff;
    transition: all 0.3s ease;
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
}

.company-card:hover {
    box-shadow: 0 12px 32px rgba(0, 0, 0, 0.12);
    transform: translateY(-2px);
}

.company-card .card-body {
    padding: 2rem;
    width: 100%;
    max-width: 100%;
    overflow-x: hidden;
}

/* تحسين تصميم الجداول */
.company-card .dashboard-table-wrapper {
    border-radius: 12px;
    border: 1px solid rgba(226, 232, 240, 0.8);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
    overflow: hidden;
    background: #ffffff;
}

.company-card .dashboard-table thead th {
    background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%);
    color: #ffffff;
    font-weight: 600;
    letter-spacing: 0.03em;
    text-transform: uppercase;
    font-size: 0.8rem;
    padding: 1rem 1.25rem;
    border-right: 1px solid rgba(255, 255, 255, 0.15);
    white-space: nowrap;
    position: relative;
}

.company-card .dashboard-table thead th:first-child {
    padding-left: 1.5rem;
}

.company-card .dashboard-table thead th:last-child {
    border-right: none;
    padding-right: 1.5rem;
}

.company-card .dashboard-table tbody tr {
    transition: all 0.2s ease;
    border-bottom: 1px solid rgba(226, 232, 240, 0.5);
}

.company-card .dashboard-table tbody tr:hover {
    background: linear-gradient(90deg, rgba(29, 78, 216, 0.05) 0%, rgba(37, 99, 235, 0.08) 100%) !important;
    transform: scale(1.01);
    box-shadow: 0 2px 8px rgba(29, 78, 216, 0.1);
}

.company-card .dashboard-table tbody tr:nth-child(even) {
    background: rgba(248, 250, 252, 0.6);
}

.company-card .dashboard-table tbody tr:nth-child(even):hover {
    background: linear-gradient(90deg, rgba(29, 78, 216, 0.08) 0%, rgba(37, 99, 235, 0.12) 100%) !important;
}

.company-card .dashboard-table tbody td {
    padding: 1rem 1.25rem;
    vertical-align: middle;
    font-size: 0.9rem;
    color: #1e293b;
    border: none;
}

.company-card .dashboard-table tbody td:first-child {
    padding-left: 1.5rem;
    font-weight: 600;
    color: #0f172a;
}

.company-card .dashboard-table tbody td:last-child {
    padding-right: 1.5rem;
}

.company-card .dashboard-table tbody td strong {
    font-weight: 600;
    color: #0f172a;
}

/* تحسين الألوان للقيم */
.company-card .dashboard-table tbody td .text-success {
    color: #059669 !important;
    font-weight: 600;
    font-size: 1rem;
}

.company-card .dashboard-table tbody td .text-primary {
    color: #1d4ed8 !important;
    font-weight: 600;
    font-size: 1rem;
}

/* إحصائيات المنتجات الخارجية */
.total-value-box {
    background: linear-gradient(135deg, rgba(5, 150, 105, 0.1) 0%, rgba(16, 185, 129, 0.15) 100%);
    border: 1px solid rgba(5, 150, 105, 0.2);
    border-radius: 12px;
    padding: 1.25rem 1.5rem;
    margin-bottom: 1.5rem;
    width: 100%;
    max-width: 100%;
    overflow: hidden;
}

.total-value-box .fw-bold {
    color: #0f766e;
    font-size: 1rem;
}

.total-value-box .text-success {
    color: #059669 !important;
    font-size: 1.5rem;
    font-weight: 700;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
}

.btn-primary-custom {
    background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%);
    border: none;
    color: white;
    padding: 0.65rem 1.4rem;
    border-radius: 10px;
    font-weight: 600;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(29, 78, 216, 0.25);
}

.btn-primary-custom:hover {
    background: linear-gradient(135deg, #1e40af 0%, #1d4ed8 100%);
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(29, 78, 216, 0.35);
    color: white;
}

.btn-success-custom {
    background: linear-gradient(135deg, #059669 0%, #10b981 100%);
    border: none;
    color: white;
    padding: 0.65rem 1.4rem;
    border-radius: 10px;
    font-weight: 600;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.25);
}

.btn-success-custom:hover {
    background: linear-gradient(135deg, #047857 0%, #059669 100%);
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(5, 150, 105, 0.35);
    color: white;
}

.btn-success-custom.btn-sm {
    padding: 0.5rem 1rem;
    font-size: 0.875rem;
}

.btn-print-custom {
    background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
    border: none;
    color: white;
    padding: 0.6rem 1.25rem;
    border-radius: 10px;
    font-weight: 600;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(99, 102, 241, 0.25);
    display: inline-flex;
    align-items: center;
    gap: 6px;
    line-height: 1;
}

.btn-print-custom:hover {
    background: linear-gradient(135deg, #4f46e5 0%, #4338ca 100%);
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(99, 102, 241, 0.4);
    color: white;
}

.btn-print-custom.btn-sm {
    padding: 0.45rem 1rem;
    font-size: 0.85rem;
    border-radius: 8px;
}

/* تحسين الأزرار في الجداول */
.company-card .btn-group-sm .btn {
    border-radius: 8px;
    font-weight: 500;
    padding: 0.4rem 0.8rem;
    font-size: 0.8rem;
    transition: all 0.2s ease;
}

.company-card .btn-outline-primary {
    border-color: #1d4ed8;
    color: #1d4ed8;
}

.company-card .btn-outline-primary:hover {
    background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%);
    border-color: #1d4ed8;
    color: white;
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(29, 78, 216, 0.2);
}

.company-card .btn-outline-danger {
    border-color: #dc2626;
    color: #dc2626;
}

.company-card .btn-outline-danger:hover {
    background: linear-gradient(135deg, #dc2626 0%, #ef4444 100%);
    border-color: #dc2626;
    color: white;
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(220, 38, 38, 0.2);
}

/* حالة فارغة */
.company-card .dashboard-table tbody tr td.text-center {
    padding: 3rem 1.5rem;
    color: #94a3b8;
    font-style: italic;
}

/* ===== تصميم شبكة المنتجات وبطاقات القوالب ===== */
.products-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 20px;
    padding: 20px;
    width: 100%;
    box-sizing: border-box;
}

.product-card {
    background: #ffffff;
    border: 1px solid rgba(226, 232, 240, 0.8);
    border-radius: 14px;
    padding: 24px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
    box-sizing: border-box;
}

.product-card:hover {
    box-shadow: 0 8px 24px rgba(29, 78, 216, 0.12);
    transform: translateY(-3px);
    border-color: rgba(29, 78, 216, 0.2);
}

.product-status {
    position: absolute;
    top: 12px;
    left: 12px;
    background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%);
    color: white;
    padding: 5px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 4px;
}

.product-name {
    font-size: 17px;
    font-weight: 700;
    color: #0f172a;
    margin-bottom: 6px;
    margin-top: 30px;
    line-height: 1.4;
    word-break: break-word;
}

.product-batch-id {
    color: #64748b;
    font-size: 13px;
    margin-bottom: 12px;
}

.product-barcode-box {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 14px;
    margin: 14px 0;
    text-align: center;
}

.product-barcode-container {
    min-height: 55px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.product-barcode-id {
    font-size: 12px;
    color: #94a3b8;
    margin-top: 6px;
    font-family: monospace;
}

.product-detail-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 7px 0;
    border-bottom: 1px solid rgba(226, 232, 240, 0.5);
    font-size: 14px;
    color: #334155;
}

.product-detail-row:last-of-type {
    border-bottom: none;
}

.product-detail-row span:first-child {
    color: #64748b;
    font-weight: 500;
}

/* ===== نهاية تصميم شبكة المنتجات ===== */

@media (max-width: 768px) {
    .company-products-page {
        padding: 1rem 0;
    }
    
    .section-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
        padding: 1.25rem 1.5rem;
        width: 100%;
        max-width: 100%;
    }
    
    .section-header h5 {
        font-size: 1rem;
        word-wrap: break-word;
        width: 100%;
    }
    
    .section-header .badge {
        font-size: 0.8rem;
        padding: 0.4rem 0.8rem;
    }
    
    .company-card {
        width: 100%;
        max-width: 100%;
        margin-bottom: 1.5rem;
    }
    
    .company-card .card-body {
        padding: 1.25rem;
        width: 100%;
        max-width: 100%;
        overflow-x: hidden;
    }
    
    .total-value-box {
        padding: 1rem 1.25rem;
        margin-bottom: 1.25rem;
        width: 100%;
        max-width: 100%;
    }
    
    .total-value-box .d-flex {
        flex-direction: column;
        gap: 0.5rem;
        align-items: flex-start !important;
    }
    
    .total-value-box .fw-bold {
        font-size: 0.9rem;
        word-wrap: break-word;
    }
    
    .total-value-box .text-success {
        font-size: 1.25rem !important;
    }
    
    .products-grid {
        padding: 15px;
        grid-template-columns: 1fr !important;
        gap: 15px;
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box;
        margin: 0;
    }
    
    .product-card {
        padding: 20px;
        border-radius: 12px;
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
        box-sizing: border-box;
        margin: 0;
        overflow: hidden;
    }
    
    .product-status {
        top: 10px;
        left: 10px;
        padding: 5px 12px;
        font-size: 11px;
    }
    
    .product-name {
        font-size: 16px;
        margin-bottom: 5px;
        word-wrap: break-word;
    }
    
    .product-batch-id {
        font-size: 13px;
        word-wrap: break-word;
    }
    
    .product-barcode-box {
        padding: 12px;
        margin: 12px 0;
    }
    
    .product-barcode-container {
        min-height: 50px;
    }
    
    .product-barcode-container svg {
        max-width: 100%;
        height: auto;
    }
    
    .product-barcode-id {
        font-size: 12px;
        margin-top: 6px;
        word-wrap: break-word;
    }
    
    .product-detail-row {
        font-size: 13px;
        margin-top: 4px;
        flex-wrap: wrap;
        gap: 0.5rem;
    }
    
    .product-detail-row span {
        word-wrap: break-word;
    }
    
    .product-card > div[style*="display: flex"] {
        flex-direction: row !important;
        gap: 10px;
        flex-wrap: wrap;
    }
    
    .product-card button {
        flex: 1 1 auto !important;
        min-width: calc(50% - 5px);
        padding: 10px 14px !important;
        font-size: 12px !important;
    }
    
    .company-card .dashboard-table-wrapper {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        width: 100%;
        max-width: 100%;
    }
    
    .company-card .dashboard-table-wrapper::-webkit-scrollbar {
        height: 8px;
    }
    
    .company-card .dashboard-table-wrapper::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 4px;
    }
    
    .company-card .dashboard-table-wrapper::-webkit-scrollbar-thumb {
        background: #888;
        border-radius: 4px;
    }
    
    .company-card .dashboard-table {
        min-width: 500px;
    }
    
    .company-card .dashboard-table thead th,
    .company-card .dashboard-table tbody td {
        padding: 0.75rem 0.85rem;
        font-size: 0.85rem;
    }
    
    .company-card .dashboard-table thead th:first-child {
        padding-left: 1rem;
    }
    
    .company-card .dashboard-table tbody td:first-child {
        padding-left: 1rem;
    }
}

@media (max-width: 576px) {
    .company-products-page {
        padding: 0.75rem 0;
    }
    
    .company-products-page h2 {
        font-size: 1.25rem;
        word-wrap: break-word;
    }
    
    .section-header {
        padding: 1rem 1.25rem;
        gap: 0.75rem;
    }
    
    .section-header h5 {
        font-size: 0.95rem;
    }
    
    .section-header .badge {
        font-size: 0.75rem;
        padding: 0.35rem 0.7rem;
    }
    
    .company-card .card-body {
        padding: 1rem;
    }
    
    .total-value-box {
        padding: 0.875rem 1rem;
        margin-bottom: 1rem;
    }
    
    .total-value-box .fw-bold {
        font-size: 0.85rem;
    }
    
    .total-value-box .text-success {
        font-size: 1.1rem !important;
    }
    
    .products-grid {
        padding: 12px;
        gap: 12px;
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box;
    }
    
    .product-card {
        padding: 15px;
        border-radius: 10px;
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
        box-sizing: border-box;
    }
    
    .product-status {
        top: 8px;
        left: 8px;
        padding: 4px 10px;
        font-size: 10px;
    }
    
    .product-name {
        font-size: 15px;
    }
    
    .product-batch-id {
        font-size: 12px;
    }
    
    .product-barcode-box {
        padding: 10px;
        margin: 10px 0;
    }
    
    .product-barcode-container {
        min-height: 45px;
    }
    
    
    .product-barcode-id {
        font-size: 11px;
    }
    
    .product-detail-row {
        font-size: 12px;
    }
    
    .product-card > div[style*="display: flex"] {
        flex-direction: row !important;
        gap: 8px;
    }
    
    .product-card button {
        flex: 1 1 auto !important;
        min-width: calc(50% - 4px);
        padding: 8px 12px !important;
        font-size: 11px !important;
    }
    
    .company-card .dashboard-table {
        min-width: 450px;
        font-size: 0.8rem;
    }
    
    .company-card .dashboard-table thead th,
    .company-card .dashboard-table tbody td {
        padding: 0.6rem 0.7rem;
        font-size: 0.8rem;
    }
    
    .btn-success-custom.btn-sm {
        padding: 0.4rem 0.8rem;
        font-size: 0.8rem;
    }
}
</style>

<div class="company-products-page">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2" style="width: 100%; max-width: 100%;">
        <h2 class="mb-0" style="word-wrap: break-word;"><i class="bi bi-box-seam me-2 text-primary"></i>منتجات الشركة</h2>
        <button type="button" class="btn btn-warning" onclick="showStagnantProductsReport()">
            <i class="bi bi-hourglass-split me-1"></i>المنتجات الراكدة
        </button>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" id="errorAlert" data-auto-refresh="true">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" id="successAlert" data-auto-refresh="true">
            <i class="bi bi-check-circle-fill me-2"></i>
            <?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>


    <!-- قسم  منتجات المصنع -->
    <div class="card company-card mb-4" id="productTemplatesSection">
        <div class="section-header" data-section="productTemplates">
            <h5>
                <i class="bi bi-diagram-3"></i>
                  منتجات المصنع
            </h5>
            <div class="d-flex align-items-center gap-2">
                <span class="badge" id="templateProductsCount"><?php echo $totalProductTemplates; ?> منتج</span>
                <button type="button" class="btn btn-print-custom btn-sm" onclick="event.stopPropagation(); printFactoryInventory()" title="طباعة جرد المنتجات الظاهرة">
                    <i class="bi bi-printer"></i>طباعة جرد القسم
                </button>
                <i class="bi bi-chevron-down collapse-arrow"></i>
            </div>
        </div>
        <div class="card-body section-collapse-body" id="productTemplatesBody">
            <!-- شريط البحث والفلترة لقوالب المنتجات -->
            <div class="mb-3 p-3 bg-light rounded" style="border: 1px solid #dee2e6;">
                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <label class="form-label small mb-1"><i class="bi bi-search me-1"></i>البحث</label>
                        <input type="text" 
                               class="form-control form-control-sm" 
                               id="templateSearchInput" 
                               placeholder="اسم المنتج..." 
                               autocomplete="off">
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label small mb-1"><i class="bi bi-funnel me-1"></i>فلترة الكمية</label>
                        <select class="form-control form-control-sm" id="templateQuantityFilter">
                            <option value="all">جميع المنتجات</option>
                            <option value="available">متاحة (كمية > 0)</option>
                            <option value="unavailable">غير متاحة (كمية = 0)</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label small mb-1"><i class="bi bi-sort-numeric-down me-1"></i>الترتيب</label>
                        <select class="form-control form-control-sm" id="templateSortOrder">
                            <option value="name_asc">الاسم (أ-ي)</option>
                            <option value="name_desc">الاسم (ي-أ)</option>
                            <option value="quantity_desc">الكمية (الأعلى أولاً)</option>
                            <option value="quantity_asc">الكمية (الأقل أولاً)</option>
                            <option value="price_desc">السعر (الأعلى أولاً)</option>
                            <option value="price_asc">السعر (الأقل أولاً)</option>
                        </select>
                    </div>
                </div>
            </div>

            <div id="templateProductsContainer">
            <?php if (empty($productTemplates)): ?>
                <div style="padding: 25px;">
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle me-2"></i>
                        لا توجد قوالب منتجات حالياً
                    </div>
                </div>
            <?php else: ?>
                <div class="products-grid" id="templateProductsGrid">
                    <?php foreach ($productTemplates as $template): ?>
                        <?php
                            $templateName = htmlspecialchars($template['product_name'] ?? 'غير محدد');
                            $templatePrice = floatval($template['unit_price'] ?? 0);
                            $templateId = $template['id'] ?? 0;
                            $availableQuantity = floatval($template['available_quantity'] ?? 0);
                        ?>
                        <div class="product-card" data-quantity="<?php echo $availableQuantity; ?>">
                            

                            <div class="product-name"><?php echo $templateName; ?></div>
                            <div style="color: #94a3b8; font-size: 13px; margin-bottom: 10px;">الكود: <?php echo $templateId; ?></div>

                            <div class="product-detail-row"><span>السعر:</span> <span><strong class="text-success"><?php echo formatCurrency($templatePrice); ?></strong></span></div>
                            <div class="product-detail-row"><span>الكمية المتاحة:</span> <span><strong class="text-primary"><?php echo number_format($availableQuantity, 2); ?> قطعة</strong></span></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- قسم المنتجات الخارجية -->
    <div class="card company-card" id="externalProductsSection">
        <div class="section-header" data-section="externalProducts">
            <h5>
                <i class="bi bi-cart4"></i>
                المنتجات الخارجية
            </h5>
            <div class="d-flex gap-2 align-items-center">
                <span class="badge" id="externalProductsCount"><?php echo $totalExternalProducts; ?> منتج</span>
                <button type="button" class="btn btn-print-custom btn-sm" onclick="event.stopPropagation(); printExternalInventory()" title="طباعة جرد المنتجات الظاهرة">
                    <i class="bi bi-printer"></i>طباعة جرد القسم
                </button>
                <button type="button" class="btn btn-success-custom btn-sm" onclick="event.stopPropagation(); showAddExternalProductModal()">
                    <i class="bi bi-plus-circle me-1"></i>إضافة منتج خارجي
                </button>
                <i class="bi bi-chevron-down collapse-arrow"></i>
            </div>
        </div>
        <div class="card-body section-collapse-body" id="externalProductsBody">
            <!-- شريط البحث والفلترة للمنتجات الخارجية -->
            <div class="mb-3 p-3 bg-light rounded" style="border: 1px solid #dee2e6;">
                <div class="row g-3">
                    <div class="col-6 col-md-6">
                        <label class="form-label small mb-1"><i class="bi bi-search me-1"></i>البحث</label>
                        <input type="text" 
                               class="form-control form-control-sm" 
                               id="externalSearchInput" 
                               placeholder="اسم المنتج..." 
                               autocomplete="off">
                    </div>
                    <div class="col-6 col-md-6">
                        <label class="form-label small mb-1"><i class="bi bi-folder me-1"></i>الصنف</label>
                        <select class="form-control form-control-sm" id="externalCategoryFilter">
                            <option value="">جميع الأصناف</option>
                            <?php if (!empty($productCategories)): ?>
                                <?php foreach ($productCategories as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat['name']); ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="عسل">عسل</option>
                                <option value="زيت زيتون">زيت زيتون</option>
                                <option value="كريمات">كريمات</option>
                                <option value="زيوت">زيوت</option>
                                <option value="تمور">تمور</option>
                                <option value="اخري">اخري</option>
                            <?php endif; ?>
                        </select>
                    </div>
                   
                    <div class="col-6 col-md-6">
                        <label class="form-label small mb-1"><i class="bi bi-box me-1"></i>كمية من</label>
                        <input type="number" 
                               class="form-control form-control-sm" 
                               id="externalMinQuantity" 
                               placeholder="من" 
                               step="1" 
                               min="0">
                    </div>
                    <div class="col-6 col-md-6">
                        <label class="form-label small mb-1"><i class="bi bi-box me-1"></i>كمية إلى</label>
                        <input type="number" 
                               class="form-control form-control-sm" 
                               id="externalMaxQuantity" 
                               placeholder="إلى" 
                               step="1" 
                               min="0">
                    </div>
                </div>
            </div>
            
            <div id="externalProductsStats" style="<?php echo empty($externalProducts) ? 'display:none;' : ''; ?>">
                <div class="total-value-box">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-bold">القيمة الإجمالية للمنتجات الخارجية:</span>
                        <span class="text-success fw-bold" id="externalTotalValue"><?php echo formatCurrency($totalExternalValue); ?></span>
                    </div>
                </div>
            </div>
            
            <div id="externalProductsContainer">
            <?php if (empty($externalProducts)): ?>
                <div style="padding: 25px;">
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle me-2"></i>
                        لا توجد منتجات خارجية. قم بإضافة منتج جديد باستخدام الزر أعلاه.
                    </div>
                </div>
            <?php else: ?>
                <div class="products-grid" id="externalProductsGrid">
                    <?php foreach ($externalProducts as $product): ?>
                        <?php
                            $productName = htmlspecialchars($product['name'] ?? 'غير محدد');
                            $quantity = number_format((float)($product['quantity'] ?? 0), 2);
                            $unit = htmlspecialchars($product['unit'] ?? 'قطعة');
                            $unitPrice = floatval($product['unit_price'] ?? 0);
                            $totalValue = floatval($product['total_value'] ?? 0);
                            $id=$product['id'] ?? 0;
                        ?>
                        <div class="product-card">
                           

                            <div class="product-name"><?php echo $productName; ?></div>
                            <div style="color: #94a3b8; font-size: 13px; margin-bottom: 10px;"> الكود : <?php echo $id; ?></div>

                            <?php $extCategory = htmlspecialchars($product['category'] ?? '—'); ?>
                            <div class="product-detail-row"><span>الصنف:</span> <span><?php echo $extCategory; ?></span></div>
                            <div class="product-detail-row"><span>الكمية:</span> <span><strong><?php echo $quantity; ?> <?php echo $unit; ?></strong></span></div>
                            <div class="product-detail-row"><span>سعر الوحدة:</span> <span><?php echo formatCurrency($unitPrice); ?></span></div>
                            <div class="product-detail-row"><span>الإجمالي:</span> <span><strong class="text-success"><?php echo formatCurrency($totalValue); ?></strong></span></div>
                            
                            <div class="product-actions" style="display: flex; gap: 10px; margin-top: 15px; flex-wrap: wrap;">
                            <?php if ($currentUser['role'] !== 'accountant' && !$isProductionRole): ?>
                                <button type="button" 
                                        class="btn btn-outline-primary js-edit-external" 
                                        style="flex: 1; min-width: calc(50% - 5px); border-radius: 10px; padding: 10px 16px; font-weight: bold; font-size: 13px;"
                                        data-id="<?php echo $product['id']; ?>"
                                        data-name="<?php echo htmlspecialchars($product['name'], ENT_QUOTES); ?>"
                                        data-quantity="<?php echo $product['quantity']; ?>"
                                        data-unit="<?php echo htmlspecialchars($product['unit'] ?? 'قطعة', ENT_QUOTES); ?>"
                                        data-price="<?php echo $product['unit_price']; ?>"
                                        data-category="<?php echo htmlspecialchars($product['category'] ?? '', ENT_QUOTES); ?>">
                                    <i class="bi bi-pencil me-1"></i>تعديل
                                </button>
                                <button type="button" 
                                        class="btn btn-outline-danger js-delete-external" 
                                        style="flex: 1; min-width: calc(50% - 5px); border-radius: 10px; padding: 10px 16px; font-weight: bold; font-size: 13px;"
                                        data-id="<?php echo $product['id']; ?>"
                                        data-name="<?php echo htmlspecialchars($product['name'], ENT_QUOTES); ?>">
                                    <i class="bi bi-trash me-1"></i>حذف
                                </button>
                            <?php endif; ?>
                                <button type="button" 
                                        class="btn btn-outline-secondary js-add-quantity-external" 
                                        style="flex: 1; min-width: calc(50% - 5px); border-radius: 10px; padding: 10px 16px; font-weight: bold; font-size: 13px;"
                                        data-product-id="<?php echo $product['id']; ?>"
                                        data-product-name="<?php echo htmlspecialchars($product['name'], ENT_QUOTES); ?>"
                                        data-quantity="<?php echo $product['quantity']; ?>">
                                    <i class="bi bi-plus-circle me-1"></i>إضافة كمية
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- قسم الفرز التاني -->
<div class="card company-card mt-4" id="secondGradeSection">
    <div class="section-header" data-section="secondGrade">
        <h5>
            <i class="bi bi-layers"></i>
            الفرز التاني
        </h5>
        <div class="d-flex gap-2 align-items-center">
            <span class="badge" id="secondGradeCount"><?php echo $totalSecondGradeProducts; ?> منتج</span>
            <button type="button" class="btn btn-print-custom btn-sm" onclick="event.stopPropagation(); printSecondGradeInventory()" title="طباعة جرد المنتجات الظاهرة">
                <i class="bi bi-printer"></i>طباعة جرد القسم
            </button>
            <?php if ($currentUser['role'] !== 'accountant' && !$isProductionRole): ?>
            <button type="button" class="btn btn-success-custom btn-sm" onclick="event.stopPropagation(); showAddSecondGradeModal()">
                <i class="bi bi-plus-circle me-1"></i>إضافة منتج
            </button>
            <?php endif; ?>
            <i class="bi bi-chevron-down collapse-arrow"></i>
        </div>
    </div>
    <div class="card-body section-collapse-body" id="secondGradeBody">
        <!-- شريط البحث والفلترة -->
        <div class="mb-3 p-3 bg-light rounded" style="border: 1px solid #dee2e6;">
            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <label class="form-label small mb-1"><i class="bi bi-search me-1"></i>البحث</label>
                    <input type="text" class="form-control form-control-sm" id="sgSearchInput" placeholder="اسم المنتج..." autocomplete="off">
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label small mb-1"><i class="bi bi-funnel me-1"></i>فلترة الكمية</label>
                    <select class="form-control form-control-sm" id="sgQuantityFilter">
                        <option value="all">جميع المنتجات</option>
                        <option value="available">متاحة (كمية > 0)</option>
                        <option value="unavailable">غير متاحة (كمية = 0)</option>
                    </select>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label small mb-1"><i class="bi bi-sort-numeric-down me-1"></i>الترتيب</label>
                    <select class="form-control form-control-sm" id="sgSortOrder">
                        <option value="name_asc">الاسم (أ-ي)</option>
                        <option value="name_desc">الاسم (ي-أ)</option>
                        <option value="quantity_desc">الكمية (الأعلى أولاً)</option>
                        <option value="quantity_asc">الكمية (الأقل أولاً)</option>
                        <option value="price_desc">السعر (الأعلى أولاً)</option>
                        <option value="price_asc">السعر (الأقل أولاً)</option>
                    </select>
                </div>
            </div>
        </div>

        <?php if (empty($secondGradeProducts)): ?>
            <div class="alert alert-info mb-0">
                <i class="bi bi-info-circle me-2"></i>
                لا توجد منتجات فرز تاني. <?php if ($currentUser['role'] !== 'accountant' && !$isProductionRole): ?>يمكنك إضافة منتجات جديدة بالضغط على زر "إضافة منتج".<?php endif; ?>
            </div>
        <?php else: ?>
        <div class="products-grid" id="secondGradeProductsGrid">
            <?php foreach ($secondGradeProducts as $product):
                $id = intval($product['id']);
                $productName = htmlspecialchars($product['name'] ?? 'غير محدد');
                $quantity = number_format(floatval($product['quantity'] ?? 0), 2);
                $unit = htmlspecialchars($product['unit'] ?? 'قطعة');
                $unitPrice = floatval($product['unit_price'] ?? 0);
                $totalValue = floatval($product['total_value'] ?? 0);
            ?>
                <div class="product-card">
                    <div class="product-name"><?php echo $productName; ?></div>
                    <div style="color: #94a3b8; font-size: 13px; margin-bottom: 10px;">الكود: <?php echo $id; ?></div>
                    <?php $sgCategory = htmlspecialchars($product['category'] ?? '—'); ?>
                    <div class="product-detail-row"><span>الصنف:</span> <span><?php echo $sgCategory; ?></span></div>
                    <div class="product-detail-row"><span>الكمية:</span> <span><strong><?php echo $quantity; ?> <?php echo $unit; ?></strong></span></div>
                    <div class="product-detail-row"><span>سعر الوحدة:</span> <span><?php echo formatCurrency($unitPrice); ?></span></div>
                    <div class="product-detail-row"><span>الإجمالي:</span> <span><strong class="text-success"><?php echo formatCurrency($totalValue); ?></strong></span></div>
                    <div class="product-actions" style="display: flex; gap: 10px; margin-top: 15px; flex-wrap: wrap;">
                    <?php if ($currentUser['role'] !== 'accountant' && !$isProductionRole): ?>
                        <button type="button"
                                class="btn btn-outline-primary js-edit-second-grade"
                                style="flex: 1; min-width: calc(50% - 5px); border-radius: 10px; padding: 10px 16px; font-weight: bold; font-size: 13px;"
                                data-id="<?php echo $id; ?>"
                                data-name="<?php echo htmlspecialchars($product['name'], ENT_QUOTES); ?>"
                                data-quantity="<?php echo $product['quantity']; ?>"
                                data-unit="<?php echo htmlspecialchars($product['unit'] ?? 'قطعة', ENT_QUOTES); ?>"
                                data-price="<?php echo $product['unit_price']; ?>"
                                data-category="<?php echo htmlspecialchars($product['category'] ?? '', ENT_QUOTES); ?>">
                            <i class="bi bi-pencil me-1"></i>تعديل
                        </button>
                        <button type="button"
                                class="btn btn-outline-danger js-delete-second-grade"
                                style="flex: 1; min-width: calc(50% - 5px); border-radius: 10px; padding: 10px 16px; font-weight: bold; font-size: 13px;"
                                data-id="<?php echo $id; ?>"
                                data-name="<?php echo htmlspecialchars($product['name'], ENT_QUOTES); ?>">
                            <i class="bi bi-trash me-1"></i>حذف
                        </button>
                    <?php endif; ?>
                        <button type="button"
                                class="btn btn-outline-secondary js-add-qty-second-grade"
                                style="flex: 1; min-width: calc(50% - 5px); border-radius: 10px; padding: 10px 16px; font-weight: bold; font-size: 13px;"
                                data-product-id="<?php echo $id; ?>"
                                data-product-name="<?php echo htmlspecialchars($product['name'], ENT_QUOTES); ?>"
                                data-quantity="<?php echo $product['quantity']; ?>">
                            <i class="bi bi-plus-circle me-1"></i>إضافة كمية
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>




<!-- Modal تعديل صنف منتج المصنع -->
<!-- Modal للكمبيوتر فقط -->
<div class="modal fade d-none d-md-block" id="editFactoryProductCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>تعديل صنف منتج المصنع</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="editFactoryCategoryForm">
                <input type="hidden" name="action" value="update_factory_product_category">
                <input type="hidden" name="batch_id" id="edit_factory_batch_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اسم المنتج</label>
                        <input type="text" class="form-control" id="edit_factory_product_name" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الصنف <span class="text-danger">*</span></label>
                        <select class="form-control" name="category_id" id="edit_factory_category_id" required>
                            <option value="">اختر الصنف</option>
                            <?php if (!empty($productCategories)): ?>
                                <?php foreach ($productCategories as $cat): ?>
                                    <option value="<?php echo intval($cat['id']); ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="1">عسل</option>
                                <option value="2">زيت زيتون</option>
                                <option value="4">زيوت</option>
                                <option value="6">تمور</option>
                                <option value="5">اخري</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="mb-3" id="edit_factory_custom_category_div" style="display: none;">
                        <label class="form-label">أدخل الصنف يدوياً <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="custom_category" id="edit_factory_custom_category" placeholder="أدخل اسم الصنف">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-warning">حفظ التغييرات</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal تعديل سعر منتج المصنع (للمدير والمحاسب) -->
<div class="modal fade d-none d-md-block" id="editFactoryProductPriceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="bi bi-currency-dollar me-2"></i>تعديل سعر منتج المصنع</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="editFactoryPriceForm">
                <input type="hidden" name="action" value="update_factory_product_price">
                <input type="hidden" name="batch_id" id="edit_factory_price_batch_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اسم المنتج</label>
                        <input type="text" class="form-control" id="edit_factory_price_product_name" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">سعر الوحدة <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" min="0" class="form-control" name="unit_price" id="edit_factory_price_unit_price" required placeholder="أدخل سعر الوحدة">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-info text-white">حفظ السعر</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal إضافة كمية لمنتج مصنع -->
<div class="modal fade d-none d-md-block" id="addQuantityFactoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-secondary text-white">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>إضافة كمية لمنتج المصنع</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="addQuantityFactoryForm">
                <input type="hidden" name="action" value="add_quantity_factory_product">
                <input type="hidden" name="batch_id" id="add_quantity_factory_batch_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">المنتج</label>
                        <input type="text" class="form-control" id="add_quantity_factory_product_name" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الكمية الحالية</label>
                        <input type="text" class="form-control" id="add_quantity_factory_current_qty" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الكمية المضافة <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" min="0.01" class="form-control" name="quantity_to_add" id="add_quantity_factory_to_add" required placeholder="أدخل الكمية">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-secondary text-white">إضافة</button>
                </div>
            </form>
        </div>
    </div>
</div>



<!-- Modal طباعة الباركود -->
<!-- Modal للكمبيوتر فقط -->
<div class="modal fade d-none d-md-block" id="printBarcodesModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-printer me-2"></i>طباعة الباركود</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-success">
                    <i class="bi bi-check-circle me-2"></i>
                    جاهز للطباعة
                </div>
                <div class="mb-3">
                    <label class="form-label">اسم المنتج</label>
                    <input type="text" class="form-control" id="barcode_product_name" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label">عدد الباركودات المراد طباعتها</label>
                    <input type="number" class="form-control" id="barcode_print_quantity" min="1" value="1">
                    <small class="text-muted">سيتم طباعة نفس رقم التشغيلة بعدد المرات المحدد</small>
                </div>
                <div class="mb-3">
                    <label class="form-label">أرقام التشغيلة</label>
                    <div class="border rounded p-3" style="max-height: 200px; overflow-y: auto;">
                        <div id="batch_numbers_list"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
                <button type="button" class="btn btn-primary" onclick="printBarcodes()">
                    <i class="bi bi-printer me-2"></i>طباعة
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Card طباعة الباركود - للموبايل فقط -->
<div class="card shadow-sm mb-4 d-md-none" id="printBarcodesCard" style="display: none;">
    <div class="card-header bg-success text-white">
        <h5 class="mb-0">
            <i class="bi bi-printer me-2"></i>طباعة الباركود
        </h5>
    </div>
    <div class="card-body">
        <div class="alert alert-success">
            <i class="bi bi-check-circle me-2"></i>
            جاهز للطباعة
        </div>
        <div class="mb-3">
            <label class="form-label">اسم المنتج</label>
            <input type="text" class="form-control" id="barcodeCardProductName" readonly>
        </div>
        <div class="mb-3">
            <label class="form-label">عدد الباركودات المراد طباعتها</label>
            <input type="number" class="form-control" id="barcodeCardPrintQuantity" min="1" value="1">
            <small class="text-muted">سيتم طباعة نفس رقم التشغيلة بعدد المرات المحدد</small>
        </div>
        <div class="mb-3">
            <label class="form-label">أرقام التشغيلة</label>
            <div class="border rounded p-3" style="max-height: 200px; overflow-y: auto;">
                <div id="batchNumbersListCard"></div>
            </div>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-primary" onclick="printBarcodesFromCard()">
                <i class="bi bi-printer me-2"></i>طباعة
            </button>
            <button type="button" class="btn btn-secondary" onclick="closePrintBarcodesCard()">إغلاق</button>
        </div>
    </div>
</div>

<!-- Card للموبايل - إضافة منتج خارجي -->
<div class="card shadow-sm mb-4" id="addExternalProductCard" style="display: none;">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="bi bi-plus-circle me-2"></i>إضافة منتج خارجي جديد</h5>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="create_external_product">
            <div class="mb-3">
                <label class="form-label">اسم المنتج <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="product_name" required>
            </div>
            <div class="mb-3">
                <label class="form-label">الصنف <span class="text-danger">*</span></label>
                <select class="form-control" name="category_id" id="addCard_category_id" required>
                    <option value="">اختر الصنف</option>
                    <?php if (!empty($productCategories)): ?>
                        <?php foreach ($productCategories as $cat): ?>
                            <option value="<?php echo intval($cat['id']); ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <option value="1">عسل</option>
                        <option value="2">زيت زيتون</option>
                        <option value="3">كريمات</option>
                        <option value="4">زيوت</option>
                        <option value="5">اخري</option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="mb-3" id="addCard_custom_category_div" style="display: none;">
                <label class="form-label">أدخل الصنف يدوياً <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="custom_category" id="addCard_custom_category" placeholder="أدخل اسم الصنف">
            </div>
            <div class="row">
                <div class="col-6 mb-3">
                    <label class="form-label">الكمية</label>
                    <input type="number" step="0.01" class="form-control" name="quantity" value="0" min="0">
                </div>
                <div class="col-6 mb-3">
                    <label class="form-label">الوحدة</label>
                    <input type="text" class="form-control" name="unit" value="قطعة">
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">سعر الوحدة</label>
                <input type="number" step="0.01" class="form-control" name="unit_price" value="0" min="0">
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary-custom">حفظ</button>
                <button type="button" class="btn btn-secondary" onclick="closeAddExternalProductCard()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<!-- Card تعديل منتج خارجي -->
<div class="card shadow-sm mb-4" id="editExternalProductCard" style="display: none;">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="bi bi-pencil me-2"></i>تعديل منتج خارجي</h5>
    </div>
    <div class="card-body">
        <form method="POST" id="editExternalProductForm" data-no-loading="true">
            <input type="hidden" name="action" value="update_external_product">
            <input type="hidden" name="product_id" id="editCard_product_id">
            <div class="mb-3">
                <label class="form-label">اسم المنتج <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="product_name" id="editCard_product_name" required>
            </div>
            <div class="mb-3">
                <label class="form-label">الصنف <span class="text-danger">*</span></label>
                <select class="form-control" name="category_id" id="editCard_category_id" required>
                    <option value="">اختر الصنف</option>
                    <?php if (!empty($productCategories)): ?>
                        <?php foreach ($productCategories as $cat): ?>
                            <option value="<?php echo intval($cat['id']); ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <option value="1">عسل</option>
                        <option value="2">زيت زيتون</option>
                        <option value="3">كريمات</option>
                        <option value="4">زيوت</option>
                        <option value="5">اخري</option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="mb-3" id="editCard_custom_category_div" style="display: none;">
                <label class="form-label">أدخل الصنف يدوياً <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="custom_category" id="editCard_custom_category" placeholder="أدخل اسم الصنف">
            </div>
            <div class="row">
                <div class="col-6 mb-3">
                    <label class="form-label">الكمية</label>
                    <input type="number" step="0.01" class="form-control" name="quantity" id="editCard_quantity" min="0">
                </div>
                <div class="col-6 mb-3">
                    <label class="form-label">الوحدة</label>
                    <input type="text" class="form-control" name="unit" id="editCard_unit">
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">سعر الوحدة</label>
                <input type="number" step="0.01" class="form-control" name="unit_price" id="editCard_unit_price" min="0">
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary-custom">حفظ التغييرات</button>
                <button type="button" class="btn btn-secondary" onclick="closeEditExternalProductCard()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<!-- Card للموبايل - حذف منتج خارجي -->
<div class="card shadow-sm mb-4" id="deleteExternalProductCard" style="display: none;">
    <div class="card-header bg-danger text-white">
        <h5 class="mb-0"><i class="bi bi-exclamation-triangle me-2"></i>تأكيد الحذف</h5>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="delete_external_product">
            <input type="hidden" name="product_id" id="deleteCard_product_id">
            <p>هل أنت متأكد من حذف المنتج <strong id="deleteCard_product_name"></strong>؟</p>
            <p class="text-danger mb-3"><small>لا يمكن التراجع عن هذه العملية.</small></p>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-danger">حذف</button>
                <button type="button" class="btn btn-secondary" onclick="closeDeleteExternalProductCard()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<!-- Card للموبايل - إضافة كمية لمنتج خارجي -->
<div class="card shadow-sm mb-4" id="addQuantityExternalCard" style="display: none;">
    <div class="card-header bg-secondary text-white">
        <h5 class="mb-0"><i class="bi bi-plus-circle me-2"></i>إضافة كمية للمنتج الخارجي</h5>
    </div>
    <div class="card-body">
        <form method="POST" id="addQuantityExternalCardForm">
            <input type="hidden" name="action" value="add_quantity_external_product">
            <input type="hidden" name="product_id" id="addQtyExtCard_product_id">
            <div class="mb-3">
                <label class="form-label">المنتج</label>
                <input type="text" class="form-control" id="addQtyExtCard_product_name" readonly>
            </div>
            <div class="mb-3">
                <label class="form-label">الكمية الحالية</label>
                <input type="text" class="form-control" id="addQtyExtCard_current_qty" readonly>
            </div>
            <div class="mb-3">
                <label class="form-label">الكمية المضافة <span class="text-danger">*</span></label>
                <input type="number" step="0.01" min="0.01" class="form-control" name="quantity_to_add" id="addQtyExtCard_to_add" required placeholder="أدخل الكمية">
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-secondary text-white">إضافة</button>
                <button type="button" class="btn btn-secondary" onclick="closeAddQuantityExternalCard()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<!-- Card للموبايل - إضافة منتج فرز تاني -->
<div class="card shadow-sm mb-4" id="addSecondGradeCard" style="display: none;">
    <div class="card-header bg-success text-white">
        <h5 class="mb-0"><i class="bi bi-plus-circle me-2"></i>إضافة منتج فرز تاني</h5>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="create_second_grade_product">
            <div class="mb-3">
                <label class="form-label">اسم المنتج <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="product_name" required>
            </div>
            <div class="mb-3">
                <label class="form-label">الصنف</label>
                <select class="form-control" name="category_id">
                    <option value="">اختر الصنف</option>
                    <?php foreach ($productCategories as $cat): ?>
                        <option value="<?php echo intval($cat['id']); ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="row">
                <div class="col-6 mb-3">
                    <label class="form-label">الكمية</label>
                    <input type="number" step="0.01" class="form-control" name="quantity" min="0" value="0">
                </div>
                <div class="col-6 mb-3">
                    <label class="form-label">الوحدة</label>
                    <input type="text" class="form-control" name="unit" value="قطعة">
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">سعر الوحدة</label>
                <input type="number" step="0.01" class="form-control" name="unit_price" min="0" value="0">
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-success">إضافة</button>
                <button type="button" class="btn btn-secondary" onclick="closeAddSecondGradeCard()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<!-- Card للموبايل - تعديل منتج فرز تاني -->
<div class="card shadow-sm mb-4" id="editSecondGradeCard" style="display: none;">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="bi bi-pencil me-2"></i>تعديل منتج فرز تاني</h5>
    </div>
    <div class="card-body">
        <form method="POST" id="editSecondGradeForm" data-no-loading="true">
            <input type="hidden" name="action" value="update_second_grade_product">
            <input type="hidden" name="product_id" id="editSG_product_id">
            <div class="mb-3">
                <label class="form-label">اسم المنتج <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="product_name" id="editSG_product_name" required>
            </div>
            <div class="mb-3">
                <label class="form-label">الصنف</label>
                <select class="form-control" name="category_id" id="editSG_category_id">
                    <option value="">اختر الصنف</option>
                    <?php foreach ($productCategories as $cat): ?>
                        <option value="<?php echo intval($cat['id']); ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="row">
                <div class="col-6 mb-3">
                    <label class="form-label">الكمية</label>
                    <input type="number" step="0.01" class="form-control" name="quantity" id="editSG_quantity" min="0">
                </div>
                <div class="col-6 mb-3">
                    <label class="form-label">الوحدة</label>
                    <input type="text" class="form-control" name="unit" id="editSG_unit">
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">سعر الوحدة</label>
                <input type="number" step="0.01" class="form-control" name="unit_price" id="editSG_unit_price" min="0">
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary-custom">حفظ التغييرات</button>
                <button type="button" class="btn btn-secondary" onclick="closeEditSecondGradeCard()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<!-- Card للموبايل - حذف منتج فرز تاني -->
<div class="card shadow-sm mb-4" id="deleteSecondGradeCard" style="display: none;">
    <div class="card-header bg-danger text-white">
        <h5 class="mb-0"><i class="bi bi-exclamation-triangle me-2"></i>تأكيد الحذف</h5>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="delete_second_grade_product">
            <input type="hidden" name="product_id" id="deleteSG_product_id">
            <p>هل أنت متأكد من حذف المنتج <strong id="deleteSG_product_name"></strong>؟</p>
            <p class="text-danger mb-3"><small>لا يمكن التراجع عن هذه العملية.</small></p>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-danger">حذف</button>
                <button type="button" class="btn btn-secondary" onclick="closeDeleteSecondGradeCard()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<!-- Card للموبايل - إضافة كمية لمنتج فرز تاني -->
<div class="card shadow-sm mb-4" id="addQtySecondGradeCard" style="display: none;">
    <div class="card-header bg-secondary text-white">
        <h5 class="mb-0"><i class="bi bi-plus-circle me-2"></i>إضافة كمية - فرز تاني</h5>
    </div>
    <div class="card-body">
        <form method="POST" id="addQtySGCardForm">
            <input type="hidden" name="action" value="add_quantity_second_grade_product">
            <input type="hidden" name="product_id" id="addQtySG_product_id">
            <div class="mb-3">
                <label class="form-label">المنتج</label>
                <input type="text" class="form-control" id="addQtySG_product_name" readonly>
            </div>
            <div class="mb-3">
                <label class="form-label">الكمية الحالية</label>
                <input type="text" class="form-control" id="addQtySG_current_qty" readonly>
            </div>
            <div class="mb-3">
                <label class="form-label">الكمية المضافة <span class="text-danger">*</span></label>
                <input type="number" step="0.01" min="0.01" class="form-control" name="quantity_to_add" id="addQtySG_to_add" required placeholder="أدخل الكمية">
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-secondary text-white">إضافة</button>
                <button type="button" class="btn btn-secondary" onclick="closeAddQtySGCard()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal تفاصيل التشغيلة -->
<div class="modal fade d-none d-md-block" id="batchDetailsModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">تفاصيل التشغيلة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div id="batchDetailsLoading" class="d-flex justify-content-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">جارٍ التحميل...</span>
                    </div>
                </div>
                <div id="batchDetailsError" class="alert alert-danger d-none" role="alert"></div>
                <div id="batchDetailsContent" class="d-none">
                    <div id="batchSummarySection" class="mb-4"></div>
                    <div id="batchMaterialsSection" class="mb-4"></div>
                    <div id="batchRawMaterialsSection" class="mb-4"></div>
                    <div id="batchWorkersSection" class="mb-0"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
            </div>
        </div>
    </div>
</div>

<!-- Card تفاصيل التشغيلة - للموبايل فقط -->
<div class="card shadow-sm mb-4 d-md-none" id="batchDetailsCard" style="display: none;">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">
            <i class="bi bi-info-circle me-2"></i>تفاصيل التشغيلة
        </h5>
    </div>
    <div class="card-body">
        <div id="batchDetailsCardLoading" class="d-flex justify-content-center py-4">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">جارٍ التحميل...</span>
            </div>
        </div>
        <div id="batchDetailsCardError" class="alert alert-danger d-none" role="alert"></div>
        <div id="batchDetailsCardContent" class="d-none">
            <div id="batchDetailsCardSummarySection" class="mb-4"></div>
            <div id="batchDetailsCardMaterialsSection" class="mb-4"></div>
            <div id="batchDetailsCardRawMaterialsSection" class="mb-4"></div>
            <div id="batchDetailsCardWorkersSection" class="mb-0"></div>
        </div>
        <div class="d-flex gap-2 mt-3">
            <button type="button" class="btn btn-secondary" onclick="closeBatchDetailsCard()">إغلاق</button>
        </div>
    </div>
</div>

<script>
// ===== دوال أساسية =====

function isMobile() {
    return window.innerWidth <= 768;
}

function scrollToElement(element) {
    if (!element) return;
    
    setTimeout(function() {
        const rect = element.getBoundingClientRect();
        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        const elementTop = rect.top + scrollTop;
        const offset = 80;
        
        requestAnimationFrame(function() {
            window.scrollTo({
                top: Math.max(0, elementTop - offset),
                behavior: 'smooth'
            });
        });
    }, 200);
}

function closeAllForms() {
    const cards = [
        'addExternalProductCard',
        'editExternalProductCard',
        'deleteExternalProductCard',
        'addQuantityExternalCard',
        'editSecondGradeCard',
        'deleteSecondGradeCard',
        'addQtySecondGradeCard',
        'batchDetailsCard',
        'printBarcodesCard'
    ];
    cards.forEach(function(cardId) {
        const card = document.getElementById(cardId);
        if (card && card.style.display !== 'none') {
            card.style.display = 'none';
            const form = card.querySelector('form');
            if (form) form.reset();
        }
    });
    
    const modals = [
        'batchDetailsModal',
        'printBarcodesModal',
        'editFactoryProductCategoryModal',
        'editFactoryProductPriceModal',
        'addQuantityFactoryModal',
    ];
    modals.forEach(function(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            const modalInstance = bootstrap.Modal.getInstance(modal);
            if (modalInstance) modalInstance.hide();
        }
    });
}

// ===== دوال فتح النماذج =====

function showAddExternalProductModal() {
    closeAllForms();
    const card = document.getElementById('addExternalProductCard');
    if (card) {
        card.style.display = 'block';
        setTimeout(function() { scrollToElement(card); }, 50);
    }
}

// ===== دوال إغلاق Cards =====

function closeAddExternalProductCard() {
    const card = document.getElementById('addExternalProductCard');
    if (card) {
        card.style.display = 'none';
        const form = card.querySelector('form');
        if (form) form.reset();
    }
}

function closeEditExternalProductCard() {
    const card = document.getElementById('editExternalProductCard');
    if (card) {
        card.style.display = 'none';
        const form = card.querySelector('form');
        if (form) form.reset();
    }
}

function closeBatchDetailsCard() {
    const card = document.getElementById('batchDetailsCard');
    if (card) {
        card.style.display = 'none';
    }
}

function closePrintBarcodesCard() {
    const card = document.getElementById('printBarcodesCard');
    if (card) {
        card.style.display = 'none';
    }
}

function printBarcodesFromCard() {
    const batchNumbers = window.batchNumbersToPrint || [];
    if (batchNumbers.length === 0) {
        alert('لا توجد أرقام تشغيلة للطباعة');
        return;
    }

    const quantityInput = document.getElementById('barcodeCardPrintQuantity');
    const printQuantity = quantityInput ? parseInt(quantityInput.value, 10) : 1;
    
    if (!printQuantity || printQuantity < 1) {
        alert('يرجى إدخال عدد صحيح للطباعة');
        return;
    }

    const batchNumber = batchNumbers[0];
    const printUrl = `${PRINT_BARCODE_URL}?batch=${encodeURIComponent(batchNumber)}&quantity=${printQuantity}&print=1`;
    window.open(printUrl, '_blank');
}

function closeDeleteExternalProductCard() {
    const card = document.getElementById('deleteExternalProductCard');
    if (card) {
        card.style.display = 'none';
        const form = card.querySelector('form');
        if (form) form.reset();
    }
}

function closeAddQuantityExternalCard() {
    const card = document.getElementById('addQuantityExternalCard');
    if (card) {
        card.style.display = 'none';
        const form = card.querySelector('form');
        if (form) form.reset();
    }
}

// ===== دوال الفرز التاني =====

function showAddSecondGradeModal() {
    closeAllForms();
    const card = document.getElementById('addSecondGradeCard');
    if (card) { card.style.display = 'block'; setTimeout(function() { scrollToElement(card); }, 50); }
}
function closeAddSecondGradeCard() {
    const card = document.getElementById('addSecondGradeCard');
    if (card) { card.style.display = 'none'; const f = card.querySelector('form'); if (f) f.reset(); }
}
function closeEditSecondGradeCard() {
    const card = document.getElementById('editSecondGradeCard');
    if (card) { card.style.display = 'none'; const f = card.querySelector('form'); if (f) f.reset(); }
}
function closeDeleteSecondGradeCard() {
    const card = document.getElementById('deleteSecondGradeCard');
    if (card) { card.style.display = 'none'; const f = card.querySelector('form'); if (f) f.reset(); }
}
function closeAddQtySGCard() {
    const card = document.getElementById('addQtySecondGradeCard');
    if (card) { card.style.display = 'none'; const f = card.querySelector('form'); if (f) f.reset(); }
}

function initSecondGradeButtons() {
    document.querySelectorAll('.js-edit-second-grade').forEach(btn => {
        btn.addEventListener('click', function() {
            closeAllForms();
            const card = document.getElementById('editSecondGradeCard');
            if (!card) return;
            document.getElementById('editSG_product_id').value = this.dataset.id;
            document.getElementById('editSG_product_name').value = this.dataset.name;
            document.getElementById('editSG_quantity').value = this.dataset.quantity;
            document.getElementById('editSG_unit').value = this.dataset.unit;
            document.getElementById('editSG_unit_price').value = this.dataset.price;
            const sel = document.getElementById('editSG_category_id');
            const cat = this.dataset.category || '';
            if (sel && cat) {
                for (let i = 0; i < sel.options.length; i++) {
                    if (sel.options[i].textContent.trim() === cat.trim()) { sel.selectedIndex = i; break; }
                }
            }
            card.style.display = 'block';
            setTimeout(function() { scrollToElement(card); }, 50);
        });
    });

    document.querySelectorAll('.js-delete-second-grade').forEach(btn => {
        btn.addEventListener('click', function() {
            closeAllForms();
            const card = document.getElementById('deleteSecondGradeCard');
            if (!card) return;
            document.getElementById('deleteSG_product_id').value = this.dataset.id;
            document.getElementById('deleteSG_product_name').textContent = this.dataset.name;
            card.style.display = 'block';
            setTimeout(function() { scrollToElement(card); }, 50);
        });
    });

    document.querySelectorAll('.js-add-qty-second-grade').forEach(btn => {
        btn.addEventListener('click', function() {
            closeAllForms();
            const card = document.getElementById('addQtySecondGradeCard');
            if (!card) return;
            document.getElementById('addQtySG_product_id').value = this.dataset.productId;
            document.getElementById('addQtySG_product_name').value = this.dataset.productName;
            document.getElementById('addQtySG_current_qty').value = this.dataset.quantity;
            document.getElementById('addQtySG_to_add').value = '';
            card.style.display = 'block';
            setTimeout(function() { scrollToElement(card); }, 50);
        });
    });
}

// AJAX edit second grade
(function() {
    const form = document.getElementById('editSecondGradeForm');
    if (!form) return;
    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        const submitBtn = form.querySelector('button[type="submit"]');
        const origText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>جاري الحفظ...';
        try {
            const formData = new FormData(form);
            const response = await fetch(window.location.href, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: formData });
            const data = await response.json();
            if (data.success) {
                updateSecondGradeCard(data.product);
                closeEditSecondGradeCard();
                showInlineToast(data.message, 'success');
            } else {
                showInlineToast(data.message || 'حدث خطأ غير متوقع.', 'danger');
            }
        } catch (err) {
            showInlineToast('تعذر الاتصال بالخادم.', 'danger');
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = origText;
            if (typeof window.resetPageLoading === 'function') window.resetPageLoading();
        }
    });

    function updateSecondGradeCard(product) {
        const fmt = (n) => parseFloat(n).toLocaleString('ar-EG', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ج.م';
        const qty = parseFloat(product.quantity).toFixed(2);
        const unit = product.unit || 'قطعة';
        const category = product.category || '—';
        const total = parseFloat(product.total_value || 0);
        document.querySelectorAll(`.js-edit-second-grade[data-id="${product.id}"]`).forEach(btn => {
            btn.dataset.name = product.name; btn.dataset.quantity = product.quantity;
            btn.dataset.unit = unit; btn.dataset.price = product.unit_price; btn.dataset.category = category;
        });
        document.querySelectorAll(`.js-add-qty-second-grade[data-product-id="${product.id}"]`).forEach(btn => {
            btn.dataset.productName = product.name; btn.dataset.quantity = product.quantity;
        });
        document.querySelectorAll(`.js-delete-second-grade[data-id="${product.id}"]`).forEach(btn => { btn.dataset.name = product.name; });
        document.querySelectorAll(`.js-edit-second-grade[data-id="${product.id}"]`).forEach(btn => {
            const card = btn.closest('.product-card');
            if (!card) return;
            const nameEl = card.querySelector('.product-name');
            if (nameEl) nameEl.textContent = product.name;
            card.querySelectorAll('.product-detail-row').forEach(row => {
                const label = row.querySelector('span:first-child');
                const val = row.querySelector('span:last-child');
                if (!label || !val) return;
                const text = label.textContent.trim();
                if (text === 'الصنف:') val.textContent = category;
                else if (text === 'الكمية:') val.innerHTML = `<strong>${qty} ${unit}</strong>`;
                else if (text === 'سعر الوحدة:') val.textContent = fmt(product.unit_price);
                else if (text === 'الإجمالي:') val.innerHTML = `<strong class="text-success">${fmt(total)}</strong>`;
            });
        });
    }
})();

// فلترة وترتيب الفرز التاني
(function() {
    const sgData = <?php echo json_encode(array_values($secondGradeProducts)); ?>;
    const container = document.getElementById('secondGradeProductsGrid');
    const searchInput = document.getElementById('sgSearchInput');
    const qtyFilter = document.getElementById('sgQuantityFilter');
    const sortOrder = document.getElementById('sgSortOrder');
    const canEdit = <?php echo json_encode($currentUser['role'] !== 'accountant' && !$isProductionRole); ?>;

    if (!container || !searchInput) return;

    function renderSG(list) {
        if (!list.length) {
            container.innerHTML = '<div style="padding:25px"><div class="alert alert-info mb-0"><i class="bi bi-info-circle me-2"></i>لا توجد منتجات تطابق البحث</div></div>';
            return;
        }
        const fmt = (n) => parseFloat(n).toLocaleString('ar-EG', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ج.م';
        container.innerHTML = list.map(p => {
            const qty = parseFloat(p.quantity || 0).toFixed(2);
            const unit = p.unit || 'قطعة';
            const cat = p.category || '—';
            const total = parseFloat(p.total_value || 0);
            const editBtn = canEdit ? `
                <button type="button" class="btn btn-outline-primary js-edit-second-grade" style="flex:1;min-width:calc(50% - 5px);border-radius:10px;padding:10px 16px;font-weight:bold;font-size:13px;" data-id="${p.id}" data-name="${escapeHtml(p.name)}" data-quantity="${p.quantity}" data-unit="${escapeHtml(unit)}" data-price="${p.unit_price}" data-category="${escapeHtml(cat)}"><i class="bi bi-pencil me-1"></i>تعديل</button>
                <button type="button" class="btn btn-outline-danger js-delete-second-grade" style="flex:1;min-width:calc(50% - 5px);border-radius:10px;padding:10px 16px;font-weight:bold;font-size:13px;" data-id="${p.id}" data-name="${escapeHtml(p.name)}"><i class="bi bi-trash me-1"></i>حذف</button>` : '';
            return `<div class="product-card">
                <div class="product-name">${escapeHtml(p.name)}</div>
                <div style="color:#94a3b8;font-size:13px;margin-bottom:10px;">فرز تاني</div>
                <div class="product-detail-row"><span>الصنف:</span> <span>${escapeHtml(cat)}</span></div>
                <div class="product-detail-row"><span>الكمية:</span> <span><strong>${qty} ${escapeHtml(unit)}</strong></span></div>
                <div class="product-detail-row"><span>سعر الوحدة:</span> <span>${fmt(p.unit_price)}</span></div>
                <div class="product-detail-row"><span>الإجمالي:</span> <span><strong class="text-success">${fmt(total)}</strong></span></div>
                <div class="product-actions" style="display:flex;gap:10px;margin-top:15px;flex-wrap:wrap;">
                    ${editBtn}
                    <button type="button" class="btn btn-outline-secondary js-add-qty-second-grade" style="flex:1;min-width:calc(50% - 5px);border-radius:10px;padding:10px 16px;font-weight:bold;font-size:13px;" data-product-id="${p.id}" data-product-name="${escapeHtml(p.name)}" data-quantity="${p.quantity}"><i class="bi bi-plus-circle me-1"></i>إضافة كمية</button>
                </div>
            </div>`;
        }).join('');
        initSecondGradeButtons();
    }

    function filterAndRender() {
        const term = searchInput.value.trim().toLowerCase();
        const qf = qtyFilter.value;
        const so = sortOrder.value;
        let list = sgData.filter(p => {
            if (term && !(p.name || '').toLowerCase().includes(term)) return false;
            if (qf === 'available' && parseFloat(p.quantity || 0) <= 0) return false;
            if (qf === 'unavailable' && parseFloat(p.quantity || 0) > 0) return false;
            return true;
        });
        list.sort((a, b) => {
            if (so === 'name_asc') return (a.name || '').localeCompare(b.name || '', 'ar');
            if (so === 'name_desc') return (b.name || '').localeCompare(a.name || '', 'ar');
            if (so === 'quantity_desc') return parseFloat(b.quantity || 0) - parseFloat(a.quantity || 0);
            if (so === 'quantity_asc') return parseFloat(a.quantity || 0) - parseFloat(b.quantity || 0);
            if (so === 'price_desc') return parseFloat(b.unit_price || 0) - parseFloat(a.unit_price || 0);
            if (so === 'price_asc') return parseFloat(a.unit_price || 0) - parseFloat(b.unit_price || 0);
            return 0;
        });
        renderSG(list);
    }

    [searchInput, qtyFilter, sortOrder].forEach(el => el.addEventListener('input', filterAndRender));
    initSecondGradeButtons();
})();

// ===== دوال موجودة - تعديلها لدعم الموبايل =====

// تهيئة المتغيرات والدوال
const PRINT_BARCODE_URL = <?php echo json_encode(getRelativeUrl('print_barcode.php')); ?>;
if (typeof window !== 'undefined') {
    window.PRINT_BARCODE_URL = PRINT_BARCODE_URL;
}

function showBarcodePrintModal(batchNumber, productName, defaultQuantity) {
    if (typeof closeAllForms === 'function') {
        closeAllForms();
    }
    
    const quantity = defaultQuantity > 0 ? defaultQuantity : 1;
    window.batchNumbersToPrint = [batchNumber];
    
    const isMobileDevice = isMobile();
    
    if (isMobileDevice) {
        // على الموبايل: استخدام Card فقط
        const card = document.getElementById('printBarcodesCard');
        if (!card) {
            console.error('printBarcodesCard not found');
            const fallbackUrl = `${PRINT_BARCODE_URL}?batch=${encodeURIComponent(batchNumber)}&quantity=${quantity}&print=1`;
            window.open(fallbackUrl, '_blank');
            return;
        }
        
        const productNameInput = document.getElementById('barcodeCardProductName');
        if (productNameInput) {
            productNameInput.value = productName || '';
        }
        
        const quantityInput = document.getElementById('barcodeCardPrintQuantity');
        if (quantityInput) {
            quantityInput.value = quantity;
        }
        
        const batchListContainer = document.getElementById('batchNumbersListCard');
        if (batchListContainer) {
            batchListContainer.innerHTML = `
                <div class="alert alert-info mb-0">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>رقم التشغيلة:</strong> ${batchNumber}<br>
                    <small>ستتم طباعة نفس رقم التشغيلة بعدد ${quantity} باركود</small>
                </div>
            `;
        }
        
        card.style.display = 'block';
        setTimeout(function() {
            scrollToElement(card);
        }, 50);
    } else {
        // على الكمبيوتر: استخدام Modal
        const modalElement = document.getElementById('printBarcodesModal');
        if (!modalElement) {
            console.error('Modal printBarcodesModal not found');
            const fallbackUrl = `${PRINT_BARCODE_URL}?batch=${encodeURIComponent(batchNumber)}&quantity=${quantity}&print=1`;
            window.open(fallbackUrl, '_blank');
            return;
        }

        const productNameInput = document.getElementById('barcode_product_name');
        if (productNameInput) {
            productNameInput.value = productName || '';
        }

        const quantityInput = document.getElementById('barcode_print_quantity');
        if (quantityInput) {
            quantityInput.value = quantity;
        }

        const batchListContainer = document.getElementById('batch_numbers_list');
        if (batchListContainer) {
            batchListContainer.innerHTML = `
                <div class="alert alert-info mb-0">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>رقم التشغيلة:</strong> ${batchNumber}<br>
                    <small>ستتم طباعة نفس رقم التشغيلة بعدد ${quantity} باركود</small>
                </div>
            `;
        }

        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
            modal.show();
        } else {
            const fallbackUrl = `${PRINT_BARCODE_URL}?batch=${encodeURIComponent(batchNumber)}&quantity=${quantity}&print=1`;
            window.open(fallbackUrl, '_blank');
        }
    }
}

function printBarcodes() {
    const batchNumbers = window.batchNumbersToPrint || [];
    if (batchNumbers.length === 0) {
        alert('لا توجد أرقام تشغيلة للطباعة');
        return;
    }

    const quantityInput = document.getElementById('barcode_print_quantity');
    const printQuantity = quantityInput ? parseInt(quantityInput.value, 10) : 1;
    
    if (!printQuantity || printQuantity < 1) {
        alert('يرجى إدخال عدد صحيح للطباعة');
        return;
    }

    const batchNumber = batchNumbers[0];
    const printUrl = `${PRINT_BARCODE_URL}?batch=${encodeURIComponent(batchNumber)}&quantity=${printQuantity}&print=1`;
    window.open(printUrl, '_blank');
}

window.showBarcodePrintModal = showBarcodePrintModal;
window.printBarcodes = printBarcodes;

// وظيفة عرض تفاصيل التشغيلة
const batchDetailsEndpoint = <?php echo json_encode(getRelativeUrl('api/production/get_batch_details.php')); ?>;
let batchDetailsIsLoading = false;

// تخزين مؤقت (Cache) لتفاصيل التشغيلات
const batchDetailsCache = new Map();
const CACHE_DURATION = 10 * 60 * 1000; // 10 دقائق بالملي ثانية
const MAX_CACHE_SIZE = 50; // أقصى عدد من التشغيلات في cache

/**
 * تنظيف cache من البيانات المنتهية الصلاحية
 */
function cleanBatchDetailsCache() {
    const now = Date.now();
    const keysToDelete = [];
    
    batchDetailsCache.forEach((value, key) => {
        if (now - value.timestamp > CACHE_DURATION) {
            keysToDelete.push(key);
        }
    });
    
    keysToDelete.forEach(key => batchDetailsCache.delete(key));
    
    // إذا كان حجم cache كبيراً، احذف أقدم العناصر
    if (batchDetailsCache.size > MAX_CACHE_SIZE) {
        const entries = Array.from(batchDetailsCache.entries());
        entries.sort((a, b) => a[1].timestamp - b[1].timestamp);
        
        const toRemove = entries.slice(0, entries.length - MAX_CACHE_SIZE);
        toRemove.forEach(([key]) => batchDetailsCache.delete(key));
    }
}

/**
 * الحصول على تفاصيل التشغيلة من cache
 */
function getBatchDetailsFromCache(batchNumber) {
    cleanBatchDetailsCache();
    const cached = batchDetailsCache.get(batchNumber);
    
    if (cached) {
        const now = Date.now();
        if (now - cached.timestamp < CACHE_DURATION) {
            return cached.data;
        } else {
            // حذف البيانات المنتهية الصلاحية
            batchDetailsCache.delete(batchNumber);
        }
    }
    
    return null;
}

/**
 * حفظ تفاصيل التشغيلة في cache
 */
function setBatchDetailsInCache(batchNumber, data) {
    cleanBatchDetailsCache();
    batchDetailsCache.set(batchNumber, {
        data: data,
        timestamp: Date.now()
    });
}

function showBatchDetailsModal(batchNumber, productName, retryCount = 0) {
    if (!batchNumber || typeof batchNumber !== 'string' || batchNumber.trim() === '') {
        console.error('Invalid batch number');
        return;
    }
    
    if (batchDetailsIsLoading) {
        return;
    }
    
    // إغلاق جميع النماذج المفتوحة أولاً
    if (typeof closeAllForms === 'function') {
        closeAllForms();
    }
    
    const isMobileDevice = isMobile();
    
    if (isMobileDevice) {
        // على الموبايل: استخدام Card فقط
        const card = document.getElementById('batchDetailsCard');
        if (!card) {
            console.error('batchDetailsCard not found');
            return;
        }
        
        const loader = card.querySelector('#batchDetailsCardLoading');
        const errorAlert = card.querySelector('#batchDetailsCardError');
        const contentWrapper = card.querySelector('#batchDetailsCardContent');
        
        // التحقق من وجود البيانات في cache
        const cachedData = getBatchDetailsFromCache(batchNumber);
        if (cachedData) {
            if (loader) loader.classList.add('d-none');
            if (errorAlert) errorAlert.classList.add('d-none');
            renderBatchDetailsCard(cachedData);
            if (contentWrapper) contentWrapper.classList.remove('d-none');
            card.style.display = 'block';
            setTimeout(function() {
                scrollToElement(card);
            }, 50);
            return;
        }
        
        // إذا لم تكن البيانات موجودة في cache، جلبها من الخادم
        if (loader) loader.classList.remove('d-none');
        if (errorAlert) errorAlert.classList.add('d-none');
        if (contentWrapper) contentWrapper.classList.add('d-none');
        batchDetailsIsLoading = true;
        
        card.style.display = 'block';
        setTimeout(function() {
            scrollToElement(card);
        }, 50);
        
        // تحميل البيانات
        fetch(batchDetailsEndpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ batch_number: batchNumber })
        })
        .then(response => response.json())
        .then(data => {
            if (loader) loader.classList.add('d-none');
            batchDetailsIsLoading = false;
            
            if (data.success && data.batch) {
                setBatchDetailsInCache(batchNumber, data.batch);
                renderBatchDetailsCard(data.batch);
                if (contentWrapper) contentWrapper.classList.remove('d-none');
            } else {
                if (errorAlert) {
                    errorAlert.textContent = data.message || 'تعذر تحميل تفاصيل التشغيلة';
                    errorAlert.classList.remove('d-none');
                }
            }
        })
        .catch(error => {
            if (loader) loader.classList.add('d-none');
            if (errorAlert) {
                errorAlert.textContent = 'حدث خطأ أثناء تحميل التفاصيل';
                errorAlert.classList.remove('d-none');
            }
            batchDetailsIsLoading = false;
            console.error('Error loading batch details:', error);
        });
        return;
    }
    
    // على الكمبيوتر: استخدام Modal
    if (typeof bootstrap === 'undefined' || typeof bootstrap.Modal === 'undefined') {
        alert('تعذر فتح تفاصيل التشغيلة. يرجى تحديث الصفحة.');
        return;
    }
    
    const modalElement = document.getElementById('batchDetailsModal');
    if (!modalElement) {
        // محاولة مرة أخرى بعد فترة قصيرة إذا كان النموذج غير موجود
        if (retryCount < 3) {
            setTimeout(function() {
                showBatchDetailsModal(batchNumber, productName, retryCount + 1);
            }, 200);
            return;
        }
        console.error('batchDetailsModal not found after retries');
        return;
    }
    
    const modalInstance = bootstrap.Modal.getOrCreateInstance(modalElement);
    const loader = modalElement.querySelector('#batchDetailsLoading');
    const errorAlert = modalElement.querySelector('#batchDetailsError');
    const contentWrapper = modalElement.querySelector('#batchDetailsContent');
    const modalTitle = modalElement.querySelector('.modal-title');
    
    // التحقق من وجود العناصر المطلوبة
    // loader و contentWrapper ضروريان، errorAlert اختياري
    if (!loader || !contentWrapper) {
        // محاولة مرة أخرى بعد فترة قصيرة إذا كانت العناصر غير موجودة
        if (retryCount < 3) {
            setTimeout(function() {
                showBatchDetailsModal(batchNumber, productName, retryCount + 1);
            }, 200);
            return;
        }
        console.error('Required modal elements not found after retries', {
            loader: !!loader,
            errorAlert: !!errorAlert,
            contentWrapper: !!contentWrapper
        });
        alert('تعذر فتح تفاصيل التشغيلة. يرجى تحديث الصفحة.');
        return;
    }
    
    // إنشاء errorAlert ديناميكياً إذا لم يكن موجوداً
    if (!errorAlert) {
        const modalBody = modalElement.querySelector('.modal-body');
        if (modalBody) {
            const errorDivElement = document.createElement('div');
            errorDivElement.className = 'alert alert-danger d-none';
            errorDivElement.id = 'batchDetailsError';
            errorDivElement.setAttribute('role', 'alert');
            // إدراجه بعد loader
            if (loader.nextSibling) {
                modalBody.insertBefore(errorDivElement, loader.nextSibling);
            } else {
                modalBody.appendChild(errorDivElement);
            }
        }
    }
    
    const finalErrorAlert = modalElement.querySelector('#batchDetailsError');
    
    if (modalTitle) {
        modalTitle.textContent = productName ? `تفاصيل التشغيلة - ${productName}` : 'تفاصيل التشغيلة';
    }
    
    // التحقق من وجود البيانات في cache
    const cachedData = getBatchDetailsFromCache(batchNumber);
    if (cachedData) {
        // استخدام البيانات من cache مباشرة
        loader.classList.add('d-none');
        if (finalErrorAlert) finalErrorAlert.classList.add('d-none');
        renderBatchDetails(cachedData);
        if (contentWrapper) contentWrapper.classList.remove('d-none');
        modalInstance.show();
        return;
    }
    
    // إذا لم تكن البيانات موجودة في cache، جلبها من الخادم
    loader.classList.remove('d-none');
    if (finalErrorAlert) finalErrorAlert.classList.add('d-none');
    contentWrapper.classList.add('d-none');
    batchDetailsIsLoading = true;
    
    modalInstance.show();
    
    fetch(batchDetailsEndpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ batch_number: batchNumber })
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        return response.json();
    })
    .then(data => {
        if (loader) loader.classList.add('d-none');
        batchDetailsIsLoading = false;
        
        const errorAlertEl = modalElement.querySelector('#batchDetailsError');
        
        if (data.success && data.batch) {
            // حفظ البيانات في cache
            setBatchDetailsInCache(batchNumber, data.batch);
            
            renderBatchDetails(data.batch);
            if (contentWrapper) contentWrapper.classList.remove('d-none');
            if (errorAlertEl) errorAlertEl.classList.add('d-none');
        } else {
            if (errorAlertEl) {
                errorAlertEl.textContent = data.message || 'تعذر تحميل تفاصيل التشغيلة';
                errorAlertEl.classList.remove('d-none');
            }
            if (contentWrapper) contentWrapper.classList.add('d-none');
        }
    })
    .catch(error => {
        if (loader) loader.classList.add('d-none');
        const errorAlertEl = modalElement.querySelector('#batchDetailsError');
        if (errorAlertEl) {
            errorAlertEl.textContent = 'حدث خطأ أثناء تحميل التفاصيل: ' + (error.message || 'خطأ غير معروف');
            errorAlertEl.classList.remove('d-none');
        }
        if (contentWrapper) contentWrapper.classList.add('d-none');
        batchDetailsIsLoading = false;
        console.error('Error loading batch details:', error);
    });
}

function renderBatchDetails(data) {
    const summarySection = document.getElementById('batchSummarySection');
    const materialsSection = document.getElementById('batchMaterialsSection');
    const rawMaterialsSection = document.getElementById('batchRawMaterialsSection');
    const workersSection = document.getElementById('batchWorkersSection');

    const batchNumber = data.batch_number ?? '—';
    const summaryRows = [
        ['رقم التشغيلة', batchNumber],
        ['المنتج', data.product_name ?? '—'],
        ['تاريخ الإنتاج', data.production_date ? data.production_date : '—'],
        ['الكمية المنتجة', data.quantity_produced ?? data.quantity ?? '—']
    ];

    if (data.honey_supplier_name) {
        summaryRows.push(['مورد العسل', data.honey_supplier_name]);
    }
    
    // عرض موردين أدوات التعبئة - دعم أكثر من مورد
    let packagingSuppliersDisplay = '—';
    if (data.packaging_suppliers_list && Array.isArray(data.packaging_suppliers_list) && data.packaging_suppliers_list.length > 0) {
        // استخدام قائمة الموردين من packaging_materials
        packagingSuppliersDisplay = data.packaging_suppliers_list.join('، ');
    } else if (data.packaging_supplier_name) {
        // استخدام المورد الافتراضي إذا لم تكن هناك قائمة
        packagingSuppliersDisplay = data.packaging_supplier_name;
    }
    
    if (packagingSuppliersDisplay !== '—') {
        summaryRows.push(['مورد أدوات التعبئة', packagingSuppliersDisplay]);
    }
    if (data.notes) {
        summaryRows.push(['ملاحظات', data.notes]);
    }

    summarySection.innerHTML = `
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>ملخص التشغيلة</h6>
            </div>
            <div class="card-body">
                <div class="table-responsive dashboard-table-wrapper">
                    <table class="table dashboard-table dashboard-table--compact align-middle mb-0">
                        <tbody>
                            ${summaryRows.map(([label, value]) => `
                                <tr>
                                    <th class="w-25">${label}</th>
                                    <td>${value}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    `;

    const packagingItems = Array.isArray(data.packaging_materials) 
        ? data.packaging_materials 
        : (Array.isArray(data.materials) ? data.materials : []);
    materialsSection.innerHTML = `
        <div class="card shadow-sm">
            <div class="card-header bg-light">
                <h6 class="mb-0"><i class="bi bi-box-seam me-2"></i>مواد التعبئة</h6>
            </div>
            <div class="card-body">
                ${packagingItems.length > 0 ? `
                    <div class="table-responsive dashboard-table-wrapper">
                        <table class="table dashboard-table dashboard-table--compact align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>المادة</th>
                                    <th>الكمية</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${packagingItems.map(item => {
                                    const materialName = item.name ?? item.material_name ?? '—';
                                    let quantityDisplay = '—';
                                    
                                    if (item.details) {
                                        // إذا كان الحقل details موجود (من materials)
                                        quantityDisplay = item.details;
                                    } else {
                                        // إذا كانت البيانات منفصلة (من packaging_materials)
                                        const quantity = item.quantity_used ?? item.quantity ?? null;
                                        const unit = item.unit ?? '';
                                        quantityDisplay = quantity !== null && quantity !== undefined 
                                            ? `${quantity} ${unit}`.trim() 
                                            : '—';
                                    }
                                    
                                    return `
                                    <tr>
                                        <td>${materialName}</td>
                                        <td>${quantityDisplay}</td>
                                    </tr>
                                `;
                                }).join('')}
                            </tbody>
                        </table>
                    </div>
                ` : `
                    <div class="text-center text-muted py-3">
                        <i class="bi bi-inbox fs-4 d-block mb-2"></i>
                        لا توجد مواد تعبئة مسجلة
                    </div>
                `}
            </div>
        </div>
    `;

    const rawMaterialsItems = Array.isArray(data.raw_materials) ? data.raw_materials : [];
    rawMaterialsSection.innerHTML = `
        <div class="card shadow-sm">
            <div class="card-header bg-light">
                <h6 class="mb-0"><i class="bi bi-flower1 me-2"></i>الخامات</h6>
            </div>
            <div class="card-body">
                ${rawMaterialsItems.length > 0 ? `
                    <div class="table-responsive dashboard-table-wrapper">
                        <table class="table dashboard-table dashboard-table--compact align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>المادة</th>
                                    <th>الكمية</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${rawMaterialsItems.map(item => {
                                    const materialName = item.name ?? item.material_name ?? '—';
                                    const quantity = item.quantity_used ?? item.quantity ?? null;
                                    const unit = item.unit ?? '';
                                    const quantityDisplay = quantity !== null && quantity !== undefined 
                                        ? `${quantity} ${unit}`.trim() 
                                        : '—';
                                    return `
                                    <tr>
                                        <td>${materialName}</td>
                                        <td>${quantityDisplay}</td>
                                    </tr>
                                `;
                                }).join('')}
                            </tbody>
                        </table>
                    </div>
                ` : `
                    <div class="text-center text-muted py-3">
                        <i class="bi bi-inbox fs-4 d-block mb-2"></i>
                        لا توجد خامات مسجلة
                    </div>
                `}
            </div>
        </div>
    `;

    const workers = Array.isArray(data.workers) ? data.workers : [];
    workersSection.innerHTML = `
        <div class="card shadow-sm">
            <div class="card-header bg-light">
                <h6 class="mb-0"><i class="bi bi-people me-2"></i>العمال</h6>
            </div>
            <div class="card-body">
                ${workers.length > 0 ? `
                    <ul class="list-unstyled mb-0">
                        ${workers.map(worker => {
                            const workerName = worker.full_name ?? worker.name ?? '—';
                            return `
                            <li class="mb-2">
                                <i class="bi bi-person-circle me-2 text-primary"></i>
                                ${workerName}
                            </li>
                        `;
                        }).join('')}
                    </ul>
                ` : `
                    <div class="text-center text-muted py-3">
                        <i class="bi bi-inbox fs-4 d-block mb-2"></i>
                        لا يوجد عمال مسجلون
                    </div>
                `}
            </div>
        </div>
    `;
}

function renderBatchDetailsCard(data) {
    const summarySection = document.getElementById('batchDetailsCardSummarySection');
    const materialsSection = document.getElementById('batchDetailsCardMaterialsSection');
    const rawMaterialsSection = document.getElementById('batchDetailsCardRawMaterialsSection');
    const workersSection = document.getElementById('batchDetailsCardWorkersSection');

    const batchNumber = data.batch_number ?? '—';
    const summaryRows = [
        ['رقم التشغيلة', batchNumber],
        ['المنتج', data.product_name ?? '—'],
        ['تاريخ الإنتاج', data.production_date ? data.production_date : '—'],
        ['الكمية المنتجة', data.quantity_produced ?? data.quantity ?? '—']
    ];

    if (data.honey_supplier_name) {
        summaryRows.push(['مورد العسل', data.honey_supplier_name]);
    }
    
    let packagingSuppliersDisplay = '—';
    if (data.packaging_suppliers_list && Array.isArray(data.packaging_suppliers_list) && data.packaging_suppliers_list.length > 0) {
        packagingSuppliersDisplay = data.packaging_suppliers_list.join('، ');
    } else if (data.packaging_supplier_name) {
        packagingSuppliersDisplay = data.packaging_supplier_name;
    }
    
    if (packagingSuppliersDisplay !== '—') {
        summaryRows.push(['مورد أدوات التعبئة', packagingSuppliersDisplay]);
    }
    if (data.notes) {
        summaryRows.push(['ملاحظات', data.notes]);
    }

    if (summarySection) {
        summarySection.innerHTML = `
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>ملخص التشغيلة</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive dashboard-table-wrapper">
                        <table class="table dashboard-table dashboard-table--compact align-middle mb-0">
                            <tbody>
                                ${summaryRows.map(([label, value]) => `
                                    <tr>
                                        <th class="w-25">${label}</th>
                                        <td>${value}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        `;
    }

    const packagingItems = Array.isArray(data.packaging_materials) 
        ? data.packaging_materials 
        : (Array.isArray(data.materials) ? data.materials : []);
    if (materialsSection) {
        materialsSection.innerHTML = `
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h6 class="mb-0"><i class="bi bi-box-seam me-2"></i>مواد التعبئة</h6>
                </div>
                <div class="card-body">
                    ${packagingItems.length > 0 ? `
                        <div class="table-responsive dashboard-table-wrapper">
                            <table class="table dashboard-table dashboard-table--compact align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>المادة</th>
                                        <th>الكمية</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${packagingItems.map(item => {
                                        const materialName = item.name ?? item.material_name ?? '—';
                                        let quantityDisplay = '—';
                                        
                                        if (item.details) {
                                            quantityDisplay = item.details;
                                        } else {
                                            const quantity = item.quantity_used ?? item.quantity ?? null;
                                            const unit = item.unit ?? '';
                                            quantityDisplay = quantity !== null && quantity !== undefined 
                                                ? `${quantity} ${unit}`.trim() 
                                                : '—';
                                        }
                                        
                                        return `
                                        <tr>
                                            <td>${materialName}</td>
                                            <td>${quantityDisplay}</td>
                                        </tr>
                                    `;
                                    }).join('')}
                                </tbody>
                            </table>
                        </div>
                    ` : `
                        <div class="text-center text-muted py-3">
                            <i class="bi bi-inbox fs-4 d-block mb-2"></i>
                            لا توجد مواد تعبئة مسجلة
                        </div>
                    `}
                </div>
            </div>
        `;
    }

    const rawMaterialsItems = Array.isArray(data.raw_materials) ? data.raw_materials : [];
    if (rawMaterialsSection) {
        rawMaterialsSection.innerHTML = `
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h6 class="mb-0"><i class="bi bi-flower1 me-2"></i>الخامات</h6>
                </div>
                <div class="card-body">
                    ${rawMaterialsItems.length > 0 ? `
                        <div class="table-responsive dashboard-table-wrapper">
                            <table class="table dashboard-table dashboard-table--compact align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>المادة</th>
                                        <th>الكمية</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${rawMaterialsItems.map(item => {
                                        const materialName = item.name ?? item.material_name ?? '—';
                                        const quantity = item.quantity_used ?? item.quantity ?? null;
                                        const unit = item.unit ?? '';
                                        const quantityDisplay = quantity !== null && quantity !== undefined 
                                            ? `${quantity} ${unit}`.trim() 
                                            : '—';
                                        return `
                                        <tr>
                                            <td>${materialName}</td>
                                            <td>${quantityDisplay}</td>
                                        </tr>
                                    `;
                                    }).join('')}
                                </tbody>
                            </table>
                        </div>
                    ` : `
                        <div class="text-center text-muted py-3">
                            <i class="bi bi-inbox fs-4 d-block mb-2"></i>
                            لا توجد خامات مسجلة
                        </div>
                    `}
                </div>
            </div>
        `;
    }

    const workers = Array.isArray(data.workers) ? data.workers : [];
    if (workersSection) {
        workersSection.innerHTML = `
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h6 class="mb-0"><i class="bi bi-people me-2"></i>العمال</h6>
                </div>
                <div class="card-body">
                    ${workers.length > 0 ? `
                        <ul class="list-unstyled mb-0">
                            ${workers.map(worker => {
                                const workerName = worker.full_name ?? worker.name ?? '—';
                                return `
                                <li class="mb-2">
                                    <i class="bi bi-person-circle me-2 text-primary"></i>
                                    ${workerName}
                                </li>
                            `;
                            }).join('')}
                        </ul>
                    ` : `
                        <div class="text-center text-muted py-3">
                            <i class="bi bi-inbox fs-4 d-block mb-2"></i>
                            لا يوجد عمال مسجلون
                        </div>
                    `}
                </div>
            </div>
        `;
    }
}

// دالة لمسح cache يدوياً
function clearBatchDetailsCache(batchNumber = null) {
    if (batchNumber) {
        // مسح تشغيلة محددة
        batchDetailsCache.delete(batchNumber);
    } else {
        // مسح جميع البيانات من cache
        batchDetailsCache.clear();
    }
}

// تنظيف cache تلقائياً كل 5 دقائق
setInterval(() => {
    cleanBatchDetailsCache();
}, 5 * 60 * 1000); // 5 دقائق

// تنظيف cache عند تحميل الصفحة
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', cleanBatchDetailsCache);
} else {
    cleanBatchDetailsCache();
}

// جعل الدوال متاحة عالمياً
window.showBatchDetailsModal = showBatchDetailsModal;
window.showBarcodePrintModal = showBarcodePrintModal;
window.printBarcodes = printBarcodes;
window.clearBatchDetailsCache = clearBatchDetailsCache;

// ربط الأحداث للأزرار - انتظار تحميل DOM بالكامل
function initBatchDetailsEventListeners() {
    // انتظار تحميل الصفحة بالكامل (CSS + JS) قبل ربط الأحداث
    function waitForResources() {
        if (document.readyState === 'complete') {
            setTimeout(attachBatchDetailsListeners, 200);
        } else {
            window.addEventListener('load', function() {
                setTimeout(attachBatchDetailsListeners, 200);
            });
        }
    }
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', waitForResources);
    } else {
        waitForResources();
    }
    
    // إضافة event listener للنموذج عند فتحه لضمان جاهزية العناصر
    const modalElement = document.getElementById('batchDetailsModal');
    if (modalElement) {
        modalElement.addEventListener('shown.bs.modal', function() {
            // استخدام requestAnimationFrame لضمان أن النموذج أصبح مرئياً بالكامل في DOM
            requestAnimationFrame(function() {
                requestAnimationFrame(function() {
                    // التأكد من وجود العناصر
                    const loader = modalElement.querySelector('#batchDetailsLoading');
                    const errorAlert = modalElement.querySelector('#batchDetailsError');
                    const contentWrapper = modalElement.querySelector('#batchDetailsContent');
                    
                    // إنشاء errorAlert ديناميكياً إذا لم يكن موجوداً
                    if (!errorAlert) {
                        const modalBody = modalElement.querySelector('.modal-body');
                        if (modalBody) {
                            const errorDivElement = document.createElement('div');
                            errorDivElement.className = 'alert alert-danger d-none';
                            errorDivElement.id = 'batchDetailsError';
                            errorDivElement.setAttribute('role', 'alert');
                            // إدراجه بعد loader
                            if (loader && loader.nextSibling) {
                                modalBody.insertBefore(errorDivElement, loader.nextSibling);
                            } else if (loader) {
                                modalBody.insertBefore(errorDivElement, loader);
                            } else {
                                modalBody.appendChild(errorDivElement);
                            }
                        }
                    }
                });
            });
        });
    }
}

// تهيئة الأحداث
initBatchDetailsEventListeners();

let batchDetailsListenerAttached = false;

function attachBatchDetailsListeners() {
    // التأكد من إضافة المستمع مرة واحدة فقط
    if (batchDetailsListenerAttached) {
        return;
    }
    batchDetailsListenerAttached = true;
    
    document.addEventListener('click', function(event) {
        // زر تفاصيل التشغيلة
        const detailsButton = event.target.closest('.js-batch-details');
        if (detailsButton) {
            event.preventDefault();
            event.stopPropagation();
            const batchNumber = detailsButton.getAttribute('data-batch') || detailsButton.dataset.batch;
            const productName = detailsButton.getAttribute('data-product') || detailsButton.dataset.product || '';
            if (batchNumber && batchNumber.trim() !== '') {
                if (typeof showBatchDetailsModal === 'function') {
                    showBatchDetailsModal(batchNumber, productName);
                } else {
                    console.error('showBatchDetailsModal function not found');
                }
            }
            return;
        }

        // زر طباعة الباركود
        const printButton = event.target.closest('.js-print-barcode');
        if (printButton) {
            event.preventDefault();
            event.stopPropagation();
            const batchNumber = printButton.getAttribute('data-batch') || printButton.dataset.batch;
            const productName = printButton.getAttribute('data-product') || printButton.dataset.product || '';
            const quantity = parseFloat(printButton.getAttribute('data-quantity') || printButton.dataset.quantity || '1');
            if (batchNumber && batchNumber.trim() !== '') {
                if (typeof window.showBarcodePrintModal === 'function') {
                    window.showBarcodePrintModal(batchNumber, productName, quantity);
                } else {
                    console.error('showBarcodePrintModal function not found');
                }
            }
            return;
        }
    });
}

// معالجة تعديل المنتجات الخارجية
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
        initEditExternalButtons();
    });
} else {
    initEditExternalButtons();
}

function initEditExternalButtons() {
    document.querySelectorAll('.js-edit-external').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            const name = this.dataset.name;
            const quantity = this.dataset.quantity;
            const unit = this.dataset.unit;
            const price = this.dataset.price;
            const category = this.dataset.category || '';
            
            closeAllForms();
            const card = document.getElementById('editExternalProductCard');
            if (card) {
                document.getElementById('editCard_product_id').value = id;
                document.getElementById('editCard_product_name').value = name;
                document.getElementById('editCard_quantity').value = quantity;
                document.getElementById('editCard_unit').value = unit;
                document.getElementById('editCard_unit_price').value = price;
                const categorySelect = document.getElementById('editCard_category_id');
                if (categorySelect && category) {
                    for (let i = 0; i < categorySelect.options.length; i++) {
                        if (categorySelect.options[i].textContent.trim() === category.trim()) {
                            categorySelect.selectedIndex = i;
                            if (categorySelect.options[i].textContent.trim() === 'اخري') {
                                const customDiv = document.getElementById('editCard_custom_category_div');
                                if (customDiv) customDiv.style.display = 'block';
                            }
                            break;
                        }
                    }
                }
                card.style.display = 'block';
                setTimeout(function() { scrollToElement(card); }, 50);
            }
        });
    });

    // معالجة حذف المنتجات الخارجية
    document.querySelectorAll('.js-delete-external').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            const name = this.dataset.name;
            
            closeAllForms();
            const card = document.getElementById('deleteExternalProductCard');
            if (card) {
                document.getElementById('deleteCard_product_id').value = id;
                document.getElementById('deleteCard_product_name').textContent = name;
                card.style.display = 'block';
                setTimeout(function() { scrollToElement(card); }, 50);
            }
        });
    });
}

// إزالة معاملات success/error من URL بعد عرض الرسالة (بدون إعادة تحميل)
(function() {
    const successAlert = document.getElementById('successAlert');
    const errorAlert = document.getElementById('errorAlert');
    
    // إذا كانت هناك رسالة نجاح أو خطأ، إزالة المعاملات من URL
    if (successAlert || errorAlert) {
        if (window.history && window.history.replaceState) {
            const url = new URL(window.location);
            if (url.searchParams.has('success') || url.searchParams.has('error')) {
                url.searchParams.delete('success');
                url.searchParams.delete('error');
                // إزالة معاملات _v و _t أيضاً إذا كانت موجودة
                url.searchParams.delete('_v');
                url.searchParams.delete('_t');
                window.history.replaceState({}, '', url);
            }
        }
    }
})();

// ===== البحث والفلترة الديناميكية =====
(function() {
    let factorySearchTimeout = null;
    let externalSearchTimeout = null;
    const API_URL = '<?php echo getRelativeUrl("api/search_company_products.php"); ?>';
    
    // عناصر البحث والفلترة لمنتجات المصنع
    const factorySearchInput = document.getElementById('factorySearchInput');
    const factoryCategoryFilter = document.getElementById('factoryCategoryFilter');
    const factoryMinPrice = document.getElementById('factoryMinPrice');
    const factoryMaxPrice = document.getElementById('factoryMaxPrice');
    const resetFactoryFiltersBtn = document.getElementById('resetFactoryFiltersBtn');
    
    // عناصر البحث والفلترة للمنتجات الخارجية
    const externalSearchInput = document.getElementById('externalSearchInput');
    const externalCategoryFilter = document.getElementById('externalCategoryFilter');
    const externalMinPrice = document.getElementById('externalMinPrice');
    const externalMaxPrice = document.getElementById('externalMaxPrice');
    const externalMinQuantity = document.getElementById('externalMinQuantity');
    const externalMaxQuantity = document.getElementById('externalMaxQuantity');
    const resetExternalFiltersBtn = document.getElementById('resetExternalFiltersBtn');
    
    // عناصر العرض
    const factoryProductsContainer = document.getElementById('factoryProductsContainer');
    const externalProductsContainer = document.getElementById('externalProductsContainer');
    const factoryProductsCount = document.getElementById('factoryProductsCount');
    const externalProductsCount = document.getElementById('externalProductsCount');
    const factoryTotalValue = document.getElementById('factoryTotalValue');
    const externalTotalValue = document.getElementById('externalTotalValue');
    const factoryProductsStats = document.getElementById('factoryProductsStats');
    const externalProductsStats = document.getElementById('externalProductsStats');
    
    // دالة جلب منتجات المصنع من API
    async function fetchFactoryProducts() {
        const params = new URLSearchParams();
        params.append('product_type', 'factory');
        
        if (factorySearchInput && factorySearchInput.value.trim()) {
            params.append('search', factorySearchInput.value.trim());
        }
        
        if (factoryCategoryFilter && factoryCategoryFilter.value) {
            params.append('category', factoryCategoryFilter.value);
        }
        
        if (factoryMinPrice && factoryMinPrice.value) {
            params.append('min_price', factoryMinPrice.value);
        }
        
        if (factoryMaxPrice && factoryMaxPrice.value) {
            params.append('max_price', factoryMaxPrice.value);
        }
        
        try {
            const response = await fetch(API_URL + '?' + params.toString());
            const data = await response.json();
            
            if (data.success) {
                updateFactoryProducts(data.factory_products || []);
                updateFactoryStatistics(data.statistics || {});
            } else {
                console.error('Error fetching factory products:', data.message);
            }
        } catch (error) {
            console.error('Error fetching factory products:', error);
        }
    }
    
    // دالة جلب المنتجات الخارجية من API
    async function fetchExternalProducts() {
        const params = new URLSearchParams();
        params.append('product_type', 'external');
        
        if (externalSearchInput && externalSearchInput.value.trim()) {
            params.append('search', externalSearchInput.value.trim());
        }
        
        if (externalCategoryFilter && externalCategoryFilter.value) {
            params.append('category', externalCategoryFilter.value);
        }
        
        if (externalMinPrice && externalMinPrice.value) {
            params.append('min_price', externalMinPrice.value);
        }
        
        if (externalMaxPrice && externalMaxPrice.value) {
            params.append('max_price', externalMaxPrice.value);
        }
        
        if (externalMinQuantity && externalMinQuantity.value) {
            params.append('min_quantity', externalMinQuantity.value);
        }
        
        if (externalMaxQuantity && externalMaxQuantity.value) {
            params.append('max_quantity', externalMaxQuantity.value);
        }
        
        try {
            const response = await fetch(API_URL + '?' + params.toString());
            const data = await response.json();
            
            if (data.success) {
                updateExternalProducts(data.external_products || []);
                updateExternalStatistics(data.statistics || {});
            } else {
                console.error('Error fetching external products:', data.message);
            }
        } catch (error) {
            console.error('Error fetching external products:', error);
        }
    }
    
    // تحديث منتجات المصنع
    function updateFactoryProducts(products) {
        if (!factoryProductsContainer) return;
        
        if (products.length === 0) {
            factoryProductsContainer.innerHTML = `
                <div style="padding: 25px;">
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle me-2"></i>
                        لا توجد منتجات مصنع تطابق البحث
                    </div>
                </div>
            `;
            return;
        }
        
        const productsHTML = products.map(product => {
            const batchNumber = product.batch_number || '';
            const productName = product.product_name || 'غير محدد';
            const category = product.product_category || '—';
            const productionDate = product.production_date ? formatDate(product.production_date) : '—';
            const quantity = parseFloat(product.available_quantity || 0).toFixed(2);
            const unitPrice = parseFloat(product.unit_price || 0);
            const totalPrice = parseFloat(product.total_price || 0);
            
            const batchDisplay = batchNumber && batchNumber !== '—' 
                ? `<a href="#" class="product-batch-id">${escapeHtml(batchNumber)}</a>`
                : `<span class="product-batch-id">—</span>`;
            
            const barcodeHTML = batchNumber && batchNumber !== '—'
                ? `
                    <div class="product-barcode-box">
                        <div class="product-barcode-container" data-batch="${escapeHtml(batchNumber)}">
                            <svg class="barcode-svg" style="width: 100%; height: 50px;"></svg>
                        </div>
                        <div class="product-barcode-id">${escapeHtml(batchNumber)}</div>
                    </div>
                `
                : `
                    <div class="product-barcode-box">
                        <div class="product-barcode-id" style="color: #999;">لا يوجد باركود</div>
                    </div>
                `;
            
            const isProductionRole = <?php echo json_encode($isProductionRole); ?>;
            const editButtonsHTML = !isProductionRole ? `
                        <button type="button" class="btn-view js-edit-factory-category" style="border: none; cursor: pointer; flex: 1; min-width: calc(50% - 5px); background: #ffc107; color: #000; padding: 10px 16px; border-radius: 10px; font-weight: bold; font-size: 13px;" data-batch-id="${product.id || 0}" data-product="${escapeHtml(productName)}" data-category="${escapeHtml(category)}"><i class="bi bi-pencil me-1"></i>تعديل الصنف</button>
                        <button type="button" class="btn-view js-edit-factory-price" style="border: none; cursor: pointer; flex: 1; min-width: calc(50% - 5px); background: #17a2b8; color: #fff; padding: 10px 16px; border-radius: 10px; font-weight: bold; font-size: 13px;" data-batch-id="${product.id || 0}" data-product="${escapeHtml(productName)}" data-unit-price="${product.unit_price != null ? product.unit_price : ''}"><i class="bi bi-currency-dollar me-1"></i>تعديل السعر</button>
            ` : '';
            const actionsHTML = batchNumber && batchNumber !== '—'
                ? `
                    <div class="product-actions" style="display: flex; gap: 10px; margin-top: 15px; flex-wrap: wrap;">
                        <button type="button" 
                                class="btn-view js-batch-details" 
                                style="border: none; cursor: pointer; flex: 1; min-width: calc(50% - 5px); background: #0c2c80; color: white; padding: 10px 16px; border-radius: 10px; font-weight: bold; font-size: 13px;"
                                data-batch="${escapeHtml(batchNumber)}"
                                data-product="${escapeHtml(productName)}"
                                data-view-url="<?php echo getRelativeUrl('production.php?page=batch_numbers&batch_number='); ?>${encodeURIComponent(batchNumber)}">
                            <i class="bi bi-eye me-1"></i>عرض التفاصيل
                        </button>
                        <button type="button" 
                                class="btn-view js-print-barcode" 
                                style="border: none; cursor: pointer; flex: 1; min-width: calc(50% - 5px); background: #28a745; color: white; padding: 10px 16px; border-radius: 10px; font-weight: bold; font-size: 13px;"
                                data-batch="${escapeHtml(batchNumber)}"
                                data-product="${escapeHtml(productName)}"
                                data-quantity="${escapeHtml(quantity)}">
                            <i class="bi bi-printer me-1"></i>طباعة الباركود
                        </button>
                        ${editButtonsHTML}
                        <button type="button" class="btn-view js-add-quantity-factory" style="border: none; cursor: pointer; flex: 1; min-width: calc(50% - 5px); background: #6f42c1; color: #fff; padding: 10px 16px; border-radius: 10px; font-weight: bold; font-size: 13px;" data-batch-id="${product.id || 0}" data-product="${escapeHtml(productName)}" data-quantity="${quantity}"><i class="bi bi-plus-circle me-1"></i>إضافة كمية</button>
                    </div>
                `
                : `
                    <span class="btn-view" style="opacity: 0.5; cursor: not-allowed; display: inline-block; margin-top: 15px; background: #0c2c80; color: white; padding: 10px 16px; border-radius: 10px; font-weight: bold; font-size: 13px;">
                        <i class="bi bi-eye me-1"></i>عرض التفاصيل
                    </span>
                `;
            
            return `
                <div class="product-card">
                    <div class="product-status">
                        <i class="bi bi-building me-1"></i>مصنع
                    </div>
                    <div class="product-name">${escapeHtml(productName)}</div>
                    ${batchDisplay}
                    ${barcodeHTML}
                    <div class="product-detail-row"><span>الصنف:</span> <span>${escapeHtml(category)}</span></div>
                    <div class="product-detail-row"><span>تاريخ الإنتاج:</span> <span>${escapeHtml(productionDate)}</span></div>
                    <div class="product-detail-row"><span>الكمية:</span> <span><strong>${quantity}</strong></span></div>
                    <div class="product-detail-row"><span>سعر الوحدة:</span> <span>${formatCurrency(unitPrice)}</span></div>
                    <div class="product-detail-row"><span>إجمالي القيمة:</span> <span><strong class="text-success">${formatCurrency(totalPrice)}</strong></span></div>
                    ${actionsHTML}
                </div>
            `;
        }).join('');
        
        factoryProductsContainer.innerHTML = `<div class="products-grid" id="factoryProductsGrid">${productsHTML}</div>`;
        
        // توليد الباركودات
        generateBarcodes();
        
        // إعادة إرفاق event listeners
        attachBatchDetailsListeners();
    }
    
    // تحديث المنتجات الخارجية
    function updateExternalProducts(products) {
        if (!externalProductsContainer) return;
        
        if (products.length === 0) {
            externalProductsContainer.innerHTML = `
                <div style="padding: 25px;">
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle me-2"></i>
                        لا توجد منتجات خارجية تطابق البحث
                    </div>
                </div>
            `;
            return;
        }
        
        const productsHTML = products.map(product => {
            const productName = product.name || 'غير محدد';
            const quantity = parseFloat(product.quantity || 0).toFixed(2);
            const unit = product.unit || 'قطعة';
            const unitPrice = parseFloat(product.unit_price || 0);
            const totalValue = parseFloat(product.total_value || 0);
            const category = product.category || '—';
            
            const canEditExternal = <?php echo json_encode($currentUser['role'] !== 'accountant' && !$isProductionRole); ?>;
            const editDeleteHTML = canEditExternal ? `
                        <button type="button" class="btn btn-outline-primary js-edit-external" style="flex: 1; min-width: calc(50% - 5px); border-radius: 10px; padding: 10px 16px; font-weight: bold; font-size: 13px;" data-id="${product.id}" data-name="${escapeHtml(productName)}" data-quantity="${product.quantity}" data-unit="${escapeHtml(unit)}" data-price="${product.unit_price}" data-category="${escapeHtml(category)}"><i class="bi bi-pencil me-1"></i>تعديل</button>
                        <button type="button" class="btn btn-outline-danger js-delete-external" style="flex: 1; min-width: calc(50% - 5px); border-radius: 10px; padding: 10px 16px; font-weight: bold; font-size: 13px;" data-id="${product.id}" data-name="${escapeHtml(productName)}"><i class="bi bi-trash me-1"></i>حذف</button>
            ` : '';
            const actionsHTML = `
                    <div class="product-actions" style="display: flex; gap: 10px; margin-top: 15px; flex-wrap: wrap;">
                        ${editDeleteHTML}
                        <button type="button" class="btn btn-outline-secondary js-add-quantity-external" style="flex: 1; min-width: calc(50% - 5px); border-radius: 10px; padding: 10px 16px; font-weight: bold; font-size: 13px;" data-product-id="${product.id}" data-product-name="${escapeHtml(productName)}" data-quantity="${product.quantity}"><i class="bi bi-plus-circle me-1"></i>إضافة كمية</button>
                    </div>
                `;
            
            return `
                <div class="product-card">
                    <div class="product-name">${escapeHtml(productName)}</div>
                    <div style="color: #94a3b8; font-size: 13px; margin-bottom: 10px;">منتج خارجي</div>
                    <div class="product-detail-row"><span>الصنف:</span> <span>${escapeHtml(category)}</span></div>
                    <div class="product-detail-row"><span>الكمية:</span> <span><strong>${quantity} ${escapeHtml(unit)}</strong></span></div>
                    <div class="product-detail-row"><span>سعر الوحدة:</span> <span>${formatCurrency(unitPrice)}</span></div>
                    <div class="product-detail-row"><span>الإجمالي:</span> <span><strong class="text-success">${formatCurrency(totalValue)}</strong></span></div>
                    ${actionsHTML}
                </div>
            `;
        }).join('');
        
        externalProductsContainer.innerHTML = `<div class="products-grid" id="externalProductsGrid">${productsHTML}</div>`;
        
        // إعادة إرفاق event listeners
        initEditExternalButtons();
    }
    
    // تحديث إحصائيات منتجات المصنع
    function updateFactoryStatistics(stats) {
        if (factoryProductsCount) {
            factoryProductsCount.textContent = (stats.total_factory_products || 0) + ' منتج';
        }
        
        if (factoryTotalValue) {
            factoryTotalValue.textContent = formatCurrency(stats.total_factory_value || 0);
        }
        
        if (factoryProductsStats) {
            factoryProductsStats.style.display = (stats.total_factory_products || 0) > 0 ? '' : 'none';
        }
    }
    
    // تحديث إحصائيات المنتجات الخارجية
    function updateExternalStatistics(stats) {
        if (externalProductsCount) {
            externalProductsCount.textContent = (stats.total_external_products || 0) + ' منتج';
        }
        
        if (externalTotalValue) {
            externalTotalValue.textContent = formatCurrency(stats.total_external_value || 0);
        }
        
        if (externalProductsStats) {
            externalProductsStats.style.display = (stats.total_external_products || 0) > 0 ? '' : 'none';
        }
    }
    
    // دالة توليد الباركودات
    function generateBarcodes() {
        if (typeof JsBarcode === 'undefined') {
            setTimeout(generateBarcodes, 100);
            return;
        }
        
        const containers = document.querySelectorAll('.product-barcode-container[data-batch]');
        containers.forEach(function(container) {
            const batchNumber = container.getAttribute('data-batch');
            const svg = container.querySelector('svg.barcode-svg');
            
            if (svg && batchNumber && batchNumber.trim() !== '') {
                try {
                    svg.innerHTML = '';
                    JsBarcode(svg, batchNumber, {
                        format: "CODE128",
                        width: 2,
                        height: 50,
                        displayValue: false,
                        margin: 5,
                        background: "#ffffff",
                        lineColor: "#000000"
                    });
                } catch (error) {
                    console.error('Error generating barcode:', error);
                    svg.innerHTML = '<text x="50%" y="50%" text-anchor="middle" font-size="12" fill="#666" font-family="Arial">' + batchNumber + '</text>';
                }
            }
        });
    }
    
    // دوال مساعدة
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    function formatCurrency(amount) {
        if (typeof amount === 'string') {
            amount = parseFloat(amount) || 0;
        }
        if (!Number.isFinite(amount)) {
            amount = 0;
        }
        // استخدام نفس تنسيق PHP: number_format($amount, 2, '.', ',') . ' ' . $currencySymbol
        const formatted = amount.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        return formatted + ' <?php echo addslashes(getCurrencySymbol()); ?>';
    }
    
    function formatDate(dateString) {
        if (!dateString) return '—';
        const date = new Date(dateString);
        return date.toLocaleDateString('ar-EG', {
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });
    }
    
    // دوال البحث مع تأخير (debounce)
    function performFactorySearch() {
        clearTimeout(factorySearchTimeout);
        factorySearchTimeout = setTimeout(() => {
            fetchFactoryProducts();
        }, 300);
    }
    
    function performExternalSearch() {
        clearTimeout(externalSearchTimeout);
        externalSearchTimeout = setTimeout(() => {
            fetchExternalProducts();
        }, 300);
    }
    
    // إرفاق event listeners لمنتجات المصنع
    if (factorySearchInput) {
        factorySearchInput.addEventListener('input', performFactorySearch);
    }
    
    if (factoryCategoryFilter) {
        factoryCategoryFilter.addEventListener('change', performFactorySearch);
    }
    
    if (factoryMinPrice) {
        factoryMinPrice.addEventListener('input', performFactorySearch);
    }
    
    if (factoryMaxPrice) {
        factoryMaxPrice.addEventListener('input', performFactorySearch);
    }
    
    // إرفاق event listeners للمنتجات الخارجية
    if (externalSearchInput) {
        externalSearchInput.addEventListener('input', performExternalSearch);
    }
    
    if (externalCategoryFilter) {
        externalCategoryFilter.addEventListener('change', performExternalSearch);
    }
    
    if (externalMinPrice) {
        externalMinPrice.addEventListener('input', performExternalSearch);
    }
    
    if (externalMaxPrice) {
        externalMaxPrice.addEventListener('input', performExternalSearch);
    }
    
    if (externalMinQuantity) {
        externalMinQuantity.addEventListener('input', performExternalSearch);
    }
    
    if (externalMaxQuantity) {
        externalMaxQuantity.addEventListener('input', performExternalSearch);
    }
})();

// ===== البحث والفلترة لقوالب المنتجات =====
(function() {
    const templateSearchInput = document.getElementById('templateSearchInput');
    const templateQuantityFilter = document.getElementById('templateQuantityFilter');
    const templateSortOrder = document.getElementById('templateSortOrder');
    const templateProductsContainer = document.getElementById('templateProductsContainer');
    
    function filterAndSortTemplates() {
        const searchText = (templateSearchInput?.value || '').toLowerCase();
        const quantityFilter = templateQuantityFilter?.value || 'all';
        const sortOrder = templateSortOrder?.value || 'name_asc';
        
        const grid = document.getElementById('templateProductsGrid');
        if (!grid) return;
        
        const cards = Array.from(grid.querySelectorAll('.product-card'));
        let visibleCount = 0;
        
        // فلترة البطاقات
        const filteredCards = cards.filter(card => {
            const productName = card.querySelector('.product-name')?.textContent.toLowerCase() || '';
            const quantity = parseFloat(card.dataset.quantity ?? '') || 0;
            
            // فحص البحث
            const matchesSearch = productName.includes(searchText);
            
            // فحص فلتر الكمية
            let matchesQuantity = true;
            if (quantityFilter === 'available') {
                matchesQuantity = quantity > 0;
            } else if (quantityFilter === 'unavailable') {
                matchesQuantity = quantity === 0;
            }
            
            return matchesSearch && matchesQuantity;
        });
        
        // ترتيب البطاقات
        filteredCards.sort((a, b) => {
            const nameA = a.querySelector('.product-name')?.textContent.toLowerCase() || '';
            const nameB = b.querySelector('.product-name')?.textContent.toLowerCase() || '';
            
            const quantityA = parseFloat(a.dataset.quantity ?? '0') || 0;
            const quantityB = parseFloat(b.dataset.quantity ?? '0') || 0;
            
            const priceA = parseFloat(a.querySelector('.product-detail-row span:last-child')?.previousElementSibling?.textContent.replace(/[^\d.]/g, '') || '0');
            const priceB = parseFloat(b.querySelector('.product-detail-row span:last-child')?.previousElementSibling?.textContent.replace(/[^\d.]/g, '') || '0');
            
            switch (sortOrder) {
                case 'name_desc':
                    return nameB.localeCompare(nameA);
                case 'quantity_desc':
                    return quantityB - quantityA;
                case 'quantity_asc':
                    return quantityA - quantityB;
                case 'price_desc':
                    return priceB - priceA;
                case 'price_asc':
                    return priceA - priceB;
                case 'name_asc':
                default:
                    return nameA.localeCompare(nameB);
            }
        });
        
        // إخفاء جميع البطاقات أولاً
        cards.forEach(card => card.style.display = 'none');
        
        // إظهار البطاقات المفلترة والمرتبة
        filteredCards.forEach(card => {
            card.style.display = '';
            visibleCount++;
        });
        
        // إظهار رسالة عند عدم وجود نتائج
        const noResultsMessage = grid.querySelector('.no-results-message');
        if (visibleCount === 0) {
            if (!noResultsMessage) {
                const messageDiv = document.createElement('div');
                messageDiv.className = 'no-results-message';
                messageDiv.style.cssText = 'grid-column: 1/-1; padding: 25px;';
                messageDiv.innerHTML = `
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle me-2"></i>
                        لا توجد قوالب منتجات تطابق معايير البحث والفلترة
                    </div>
                `;
                grid.appendChild(messageDiv);
            }
        } else {
            if (noResultsMessage) {
                noResultsMessage.remove();
            }
        }
    }
    
    // إضافة event listeners
    if (templateSearchInput) {
        templateSearchInput.addEventListener('input', filterAndSortTemplates);
    }
    
    if (templateQuantityFilter) {
        templateQuantityFilter.addEventListener('change', filterAndSortTemplates);
    }
    
    if (templateSortOrder) {
        templateSortOrder.addEventListener('change', filterAndSortTemplates);
    }
    
    // تشغيل الفلترة الأولية
    filterAndSortTemplates();
})();

// ===== معالجة اختيار الصنف "اخري" =====
(function() {
    // معالجة نموذج إضافة منتج خارجي
    const addCategorySelect = document.getElementById('add_category_id');
    const addCustomCategoryDiv = document.getElementById('add_custom_category_div');
    const addCustomCategoryInput = document.getElementById('add_custom_category');
    
    if (addCategorySelect) {
        addCategorySelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const isOther = selectedOption && selectedOption.textContent.trim() === 'اخري';
            
            if (addCustomCategoryDiv) {
                addCustomCategoryDiv.style.display = isOther ? 'block' : 'none';
            }
            if (addCustomCategoryInput) {
                addCustomCategoryInput.required = isOther;
                if (!isOther) {
                    addCustomCategoryInput.value = '';
                }
            }
        });
    }
    
    // معالجة نموذج تعديل منتج خارجي
    const editCategorySelect = document.getElementById('edit_category_id');
    const editCustomCategoryDiv = document.getElementById('edit_custom_category_div');
    const editCustomCategoryInput = document.getElementById('edit_custom_category');
    
    if (editCategorySelect) {
        editCategorySelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const isOther = selectedOption && selectedOption.textContent.trim() === 'اخري';
            
            if (editCustomCategoryDiv) {
                editCustomCategoryDiv.style.display = isOther ? 'block' : 'none';
            }
            if (editCustomCategoryInput) {
                editCustomCategoryInput.required = isOther;
                if (!isOther) {
                    editCustomCategoryInput.value = '';
                }
            }
        });
    }
    
    // معالجة نموذج تعديل صنف منتج المصنع
    const editFactoryCategorySelect = document.getElementById('edit_factory_category_id');
    const editFactoryCustomCategoryDiv = document.getElementById('edit_factory_custom_category_div');
    const editFactoryCustomCategoryInput = document.getElementById('edit_factory_custom_category');
    
    if (editFactoryCategorySelect) {
        editFactoryCategorySelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const isOther = selectedOption && selectedOption.textContent.trim() === 'اخري';
            
            if (editFactoryCustomCategoryDiv) {
                editFactoryCustomCategoryDiv.style.display = isOther ? 'block' : 'none';
            }
            if (editFactoryCustomCategoryInput) {
                editFactoryCustomCategoryInput.required = isOther;
                if (!isOther) {
                    editFactoryCustomCategoryInput.value = '';
                }
            }
        });
    }
    
    // إدارة بطاقة إضافة منتج مصنع جديد
    const addFactoryCategorySelect = document.getElementById('add_factory_category_id');
    const addFactoryCustomCategoryDiv = document.getElementById('add_factory_custom_category_div');
    const addFactoryCustomCategoryInput = document.getElementById('add_factory_custom_category');
    const addFactoryProductForm = document.getElementById('addFactoryProductForm');
    const addFactoryProductCard = document.getElementById('addFactoryProductCard');
    const toggleAddFactoryProductCardBtn = document.getElementById('toggleAddFactoryProductCard');
    const cancelAddFactoryProductBtn = document.getElementById('cancelAddFactoryProduct');
    
    // إظهار/إخفاء البطاقة
    if (toggleAddFactoryProductCardBtn && addFactoryProductCard) {
        toggleAddFactoryProductCardBtn.addEventListener('click', function() {
            if (addFactoryProductCard.style.display === 'none') {
                addFactoryProductCard.style.display = 'block';
                // التمرير إلى البطاقة
                addFactoryProductCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            } else {
                addFactoryProductCard.style.display = 'none';
                resetAddFactoryForm();
            }
        });
    }
    
    // إلغاء وإخفاء البطاقة
    if (cancelAddFactoryProductBtn && addFactoryProductCard) {
        cancelAddFactoryProductBtn.addEventListener('click', function() {
            addFactoryProductCard.style.display = 'none';
            resetAddFactoryForm();
        });
    }
    
    // إدارة حقل الصنف المخصص
    if (addFactoryCategorySelect) {
        addFactoryCategorySelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const isOther = selectedOption && selectedOption.textContent.trim() === 'اخري';
            
            if (addFactoryCustomCategoryDiv) {
                addFactoryCustomCategoryDiv.style.display = isOther ? 'block' : 'none';
            }
            if (addFactoryCustomCategoryInput) {
                addFactoryCustomCategoryInput.required = isOther;
                if (!isOther) {
                    addFactoryCustomCategoryInput.value = '';
                }
            }
        });
    }
    
    // دالة إعادة تعيين النموذج
    function resetAddFactoryForm() {
        if (addFactoryProductForm) {
            addFactoryProductForm.reset();
            // إعادة تعيين تاريخ الإنتاج إلى اليوم
            const productionDateInput = document.getElementById('add_factory_production_date');
            if (productionDateInput) {
                productionDateInput.value = new Date().toISOString().split('T')[0];
            }
            // إخفاء حقل الصنف المخصص
            if (addFactoryCustomCategoryDiv) {
                addFactoryCustomCategoryDiv.style.display = 'none';
            }
            if (addFactoryCustomCategoryInput) {
                addFactoryCustomCategoryInput.required = false;
                addFactoryCustomCategoryInput.value = '';
            }
        }
    }
    
    // معالجة نموذج إضافة منتج خارجي للموبايل
    const addCardCategorySelect = document.getElementById('addCard_category_id');
    const addCardCustomCategoryDiv = document.getElementById('addCard_custom_category_div');
    const addCardCustomCategoryInput = document.getElementById('addCard_custom_category');
    
    if (addCardCategorySelect) {
        addCardCategorySelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const isOther = selectedOption && selectedOption.textContent.trim() === 'اخري';
            
            if (addCardCustomCategoryDiv) {
                addCardCustomCategoryDiv.style.display = isOther ? 'block' : 'none';
            }
            if (addCardCustomCategoryInput) {
                addCardCustomCategoryInput.required = isOther;
                if (!isOther) {
                    addCardCustomCategoryInput.value = '';
                }
            }
        });
    }
    
    // معالجة نموذج تعديل منتج خارجي للموبايل
    const editCardCategorySelect = document.getElementById('editCard_category_id');
    const editCardCustomCategoryDiv = document.getElementById('editCard_custom_category_div');
    const editCardCustomCategoryInput = document.getElementById('editCard_custom_category');
    
    if (editCardCategorySelect) {
        editCardCategorySelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const isOther = selectedOption && selectedOption.textContent.trim() === 'اخري';
            
            if (editCardCustomCategoryDiv) {
                editCardCustomCategoryDiv.style.display = isOther ? 'block' : 'none';
            }
            if (editCardCustomCategoryInput) {
                editCardCustomCategoryInput.required = isOther;
                if (!isOther) {
                    editCardCustomCategoryInput.value = '';
                }
            }
        });
    }
    
    // معالجة زر تعديل صنف منتج المصنع
    document.addEventListener('click', function(e) {
        const editBtn = e.target.closest('.js-edit-factory-category');
        if (editBtn) {
            const batchId = editBtn.getAttribute('data-batch-id');
            const productName = editBtn.getAttribute('data-product');
            const currentCategory = editBtn.getAttribute('data-category');
            
            if (batchId && document.getElementById('edit_factory_batch_id')) {
                document.getElementById('edit_factory_batch_id').value = batchId;
                document.getElementById('edit_factory_product_name').value = productName || '';
                
                // تحديد الصنف الحالي في القائمة المنسدلة
                if (editFactoryCategorySelect && currentCategory) {
                    // البحث عن الصنف في القائمة
                    for (let i = 0; i < editFactoryCategorySelect.options.length; i++) {
                        if (editFactoryCategorySelect.options[i].textContent.trim() === currentCategory.trim()) {
                            editFactoryCategorySelect.selectedIndex = i;
                            break;
                        }
                    }
                }
                
                // إخفاء حقل الإدخال اليدوي
                if (editFactoryCustomCategoryDiv) {
                    editFactoryCustomCategoryDiv.style.display = 'none';
                }
                if (editFactoryCustomCategoryInput) {
                    editFactoryCustomCategoryInput.value = '';
                    editFactoryCustomCategoryInput.required = false;
                }
                
                // فتح النموذج
                const modal = new bootstrap.Modal(document.getElementById('editFactoryProductCategoryModal'));
                modal.show();
            }
        }
        // معالجة زر تعديل سعر منتج المصنع (للمدير والمحاسب)
        const editPriceBtn = e.target.closest('.js-edit-factory-price');
        if (editPriceBtn) {
            const batchId = editPriceBtn.getAttribute('data-batch-id');
            const productName = editPriceBtn.getAttribute('data-product');
            const unitPrice = editPriceBtn.getAttribute('data-unit-price') || '0';
            const batchIdEl = document.getElementById('edit_factory_price_batch_id');
            const productNameEl = document.getElementById('edit_factory_price_product_name');
            const unitPriceEl = document.getElementById('edit_factory_price_unit_price');
            if (batchId && batchIdEl && productNameEl && unitPriceEl) {
                batchIdEl.value = batchId;
                productNameEl.value = productName || '';
                unitPriceEl.value = unitPrice;
                const modal = new bootstrap.Modal(document.getElementById('editFactoryProductPriceModal'));
                modal.show();
            }
        }
        // معالجة زر إضافة كمية لمنتج مصنع
        const addQtyFactoryBtn = e.target.closest('.js-add-quantity-factory');
        if (addQtyFactoryBtn) {
            const batchId = addQtyFactoryBtn.getAttribute('data-batch-id');
            const productName = addQtyFactoryBtn.getAttribute('data-product');
            const currentQty = addQtyFactoryBtn.getAttribute('data-quantity') || '0';
            const batchIdEl = document.getElementById('add_quantity_factory_batch_id');
            const productNameEl = document.getElementById('add_quantity_factory_product_name');
            const currentQtyEl = document.getElementById('add_quantity_factory_current_qty');
            const toAddEl = document.getElementById('add_quantity_factory_to_add');
            if (batchId && batchIdEl && productNameEl && currentQtyEl && toAddEl) {
                batchIdEl.value = batchId;
                productNameEl.value = productName || '';
                currentQtyEl.value = currentQty;
                toAddEl.value = '';
                const modal = new bootstrap.Modal(document.getElementById('addQuantityFactoryModal'));
                modal.show();
            }
        }
        // معالجة زر إضافة كمية لمنتج خارجي
        const addQtyExternalBtn = e.target.closest('.js-add-quantity-external');
        if (addQtyExternalBtn) {
            const productId = addQtyExternalBtn.getAttribute('data-product-id');
            const productName = addQtyExternalBtn.getAttribute('data-product-name');
            const currentQty = addQtyExternalBtn.getAttribute('data-quantity') || '0';
            const card = document.getElementById('addQuantityExternalCard');
            if (card) {
                closeAllForms();
                document.getElementById('addQtyExtCard_product_id').value = productId || '';
                document.getElementById('addQtyExtCard_product_name').value = productName || '';
                document.getElementById('addQtyExtCard_current_qty').value = currentQty;
                document.getElementById('addQtyExtCard_to_add').value = '';
                card.style.display = 'block';
                setTimeout(function() { scrollToElement(card); }, 50);
            }
        }
    });
})();

// ===== طباعة جرد المنتجات الخارجية =====
function printExternalInventory() {
    const grid = document.getElementById('externalProductsGrid');
    if (!grid) {
        alert('لا توجد منتجات لطباعتها');
        return;
    }

    const cards = grid.querySelectorAll('.product-card');
    if (cards.length === 0) {
        alert('لا توجد منتجات ظاهرة لطباعتها');
        return;
    }

    // قراءة فلاتر البحث الحالية لعرضها في رأس التقرير
    const searchVal = (document.getElementById('externalSearchInput') || {}).value || '';
    const categoryVal = (document.getElementById('externalCategoryFilter') || {}).value || '';
    const minPrice = (document.getElementById('externalMinPrice') || {}).value || '';
    const maxPrice = (document.getElementById('externalMaxPrice') || {}).value || '';
    const minQty = (document.getElementById('externalMinQuantity') || {}).value || '';
    const maxQty = (document.getElementById('externalMaxQuantity') || {}).value || '';

    const filterParts = [];
    if (searchVal) filterParts.push('بحث: ' + searchVal);
    if (categoryVal) filterParts.push('الصنف: ' + categoryVal);
    if (minPrice || maxPrice) filterParts.push('السعر: ' + (minPrice || '—') + ' – ' + (maxPrice || '—'));
    if (minQty || maxQty) filterParts.push('الكمية: ' + (minQty || '—') + ' – ' + (maxQty || '—'));
    const filterText = filterParts.length ? filterParts.join(' | ') : 'جميع المنتجات';

    // استخراج بيانات البطاقات الظاهرة
    let rows = '';
    let grandTotal = 0;
    let serial = 1;
    cards.forEach(card => {
        const rows_data = card.querySelectorAll('.product-detail-row');
        let name = '', category = '', quantity = '', unitPrice = '', totalVal = '';

        const nameEl = card.querySelector('.product-name');
        if (nameEl) name = nameEl.textContent.trim();

        rows_data.forEach(row => {
            const spans = row.querySelectorAll('span');
            if (spans.length >= 2) {
                const label = spans[0].textContent.trim();
                const value = spans[1].textContent.trim();
                if (label.includes('الصنف')) category = value;
                else if (label.includes('الكمية')) quantity = value;
                else if (label.includes('سعر الوحدة')) unitPrice = value;
                else if (label.includes('الإجمالي')) totalVal = value;
            }
        });

        // جمع القيمة الإجمالية
        const totalNum = parseFloat(totalVal.replace(/[^0-9.]/g, '')) || 0;
        grandTotal += totalNum;

        rows += `<tr>
            <td>${serial++}</td>
            <td>${name}</td>
            <td>${category}</td>
            <td>${quantity}</td>
            <td>${unitPrice}</td>
            <td class="total-cell">${totalVal}</td>
        </tr>`;
    });

    // القيمة الإجمالية المعروضة في الصفحة (تعكس الفلتر)
    const totalValueEl = document.getElementById('externalTotalValue');
    const displayedTotal = totalValueEl ? totalValueEl.textContent.trim() : '';

    const now = new Date();
    const dateStr = now.toLocaleDateString('ar-EG', { year: 'numeric', month: 'long', day: 'numeric' });
    const timeStr = now.toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' });

    const printHTML = `<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>جرد المنتجات الخارجية</title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; direction: rtl; font-size: 13px; color: #222; background: #fff; padding: 20px; }
    .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #0c2c80; padding-bottom: 12px; }
    .header h1 { font-size: 20px; color: #0c2c80; margin-bottom: 4px; }
    .header .meta { font-size: 12px; color: #555; }
    .filter-info { background: #f0f4ff; border: 1px solid #c8d6ff; border-radius: 6px; padding: 8px 14px; margin-bottom: 16px; font-size: 12px; color: #333; }
    table { width: 100%; border-collapse: collapse; }
    thead th { background: #0c2c80; color: #fff; padding: 8px 10px; text-align: right; font-size: 13px; }
    tbody tr:nth-child(even) { background: #f5f7ff; }
    tbody td { padding: 7px 10px; border-bottom: 1px solid #dde; }
    .total-cell { font-weight: bold; color: #10b981; }
    .footer-row td { font-weight: bold; background: #e8edff; border-top: 2px solid #0c2c80; font-size: 14px; }
    @media print {
        body { padding: 10px; }
        button { display: none; }
    }
</style>
</head>
<body>
<div class="header">
    <h1>جرد المنتجات الخارجية</h1>
    <div class="meta">${dateStr} — ${timeStr}</div>
</div>
<div class="filter-info">الفلتر المطبق: ${filterText} &nbsp;|&nbsp; عدد المنتجات: ${cards.length}</div>
<table>
    <thead>
        <tr>
            <th>#</th>
            <th>اسم المنتج</th>
            <th>الصنف</th>
            <th>الكمية</th>
            <th>سعر الوحدة</th>
            <th>الإجمالي</th>
        </tr>
    </thead>
    <tbody>
        ${rows}
        <tr class="footer-row">
            <td colspan="5" style="text-align:center;">إجمالي القيمة</td>
            <td class="total-cell">${displayedTotal || '—'}</td>
        </tr>
    </tbody>
</table>
</body>
</html>`;

    const win = window.open('', '_blank', 'width=900,height=700');
    win.document.write(printHTML);
    win.document.close();
    win.focus();
    win.print();
}

window.printExternalInventory = printExternalInventory;

// ===== طباعة جرد منتجات المصنع =====
function printFactoryInventory() {
    const grid = document.getElementById('templateProductsGrid');
    if (!grid) {
        alert('لا توجد منتجات لطباعتها');
        return;
    }

    const cards = Array.from(grid.querySelectorAll('.product-card')).filter(card => card.style.display !== 'none');
    if (cards.length === 0) {
        alert('لا توجد منتجات ظاهرة لطباعتها');
        return;
    }

    // قراءة فلاتر البحث الحالية
    const searchVal = (document.getElementById('templateSearchInput') || {}).value || '';
    const qtyFilter = (document.getElementById('templateQuantityFilter') || {}).value || 'all';
    
    const filterParts = [];
    if (searchVal) filterParts.push('بحث: ' + searchVal);
    if (qtyFilter !== 'all') filterParts.push('الكمية: ' + (qtyFilter === 'available' ? 'متاحة' : 'غير متاحة'));
    const filterText = filterParts.length ? filterParts.join(' | ') : 'جميع المنتجات';

    let rows = '';
    let serial = 1;

    cards.forEach(card => {
        let name = '', code = '', price = '', quantity = '';
        
        const nameEl = card.querySelector('.product-name');
        if (nameEl) name = nameEl.textContent.trim();
        
        // البحث عن الكود (يوجد في div بعد اسم المنتج)
        const codeDiv = Array.from(card.querySelectorAll('div')).find(div => div.textContent.includes('الكود:'));
        if (codeDiv) code = codeDiv.textContent.replace('الكود:', '').trim();

        const rows_data = card.querySelectorAll('.product-detail-row');
        rows_data.forEach(row => {
            const spans = row.querySelectorAll('span');
            if (spans.length >= 2) {
                const label = spans[0].textContent.trim();
                const value = spans[1].textContent.trim();
                if (label.includes('السعر')) price = value;
                else if (label.includes('الكمية المتاحة')) quantity = value;
            }
        });

        rows += `<tr>
            <td>${serial++}</td>
            <td>${name}</td>
            <td>${code}</td>
            <td>${price}</td>
            <td class="total-cell">${quantity}</td>
        </tr>`;
    });

    const now = new Date();
    const dateStr = now.toLocaleDateString('ar-EG', { year: 'numeric', month: 'long', day: 'numeric' });
    const timeStr = now.toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' });

    const printHTML = `<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>جرد منتجات المصنع</title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; direction: rtl; font-size: 13px; color: #222; background: #fff; padding: 20px; }
    .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #0c2c80; padding-bottom: 12px; }
    .header h1 { font-size: 20px; color: #0c2c80; margin-bottom: 4px; }
    .header .meta { font-size: 12px; color: #555; }
    .filter-info { background: #f0f4ff; border: 1px solid #c8d6ff; border-radius: 6px; padding: 8px 14px; margin-bottom: 16px; font-size: 12px; color: #333; }
    table { width: 100%; border-collapse: collapse; }
    thead th { background: #0c2c80; color: #fff; padding: 8px 10px; text-align: right; font-size: 13px; }
    tbody tr:nth-child(even) { background: #f5f7ff; }
    tbody td { padding: 7px 10px; border-bottom: 1px solid #dde; }
    .total-cell { font-weight: bold; color: #0c2c80; }
    @media print {
        body { padding: 10px; }
        button { display: none; }
    }
</style>
</head>
<body>
<div class="header">
    <h1>جرد منتجات المصنع</h1>
    <div class="meta">${dateStr} — ${timeStr}</div>
</div>
<div class="filter-info">الفلتر المطبق: ${filterText} &nbsp;|&nbsp; عدد المنتجات: ${cards.length}</div>
<table>
    <thead>
        <tr>
            <th>#</th>
            <th>اسم المنتج</th>
            <th>الكود</th>
            <th>السعر</th>
            <th>الكمية المتاحة</th>
        </tr>
    </thead>
    <tbody>${rows}</tbody>
</table>
</body>
</html>`;

    const win = window.open('', '_blank', 'width=900,height=700');
    win.document.write(printHTML);
    win.document.close();
    win.focus();
    win.print();
}

// ===== طباعة جرد الفرز التاني =====
function printSecondGradeInventory() {
    const grid = document.getElementById('secondGradeProductsGrid');
    if (!grid) {
        alert('لا توجد منتجات لطباعتها');
        return;
    }

    const cards = Array.from(grid.querySelectorAll('.product-card')).filter(card => card.style.display !== 'none');
    if (cards.length === 0) {
        alert('لا توجد منتجات ظاهرة لطباعتها');
        return;
    }

    // قراءة فلاتر البحث الحالية
    const searchVal = (document.getElementById('sgSearchInput') || {}).value || '';
    const qtyFilter = (document.getElementById('sgQuantityFilter') || {}).value || 'all';
    
    const filterParts = [];
    if (searchVal) filterParts.push('بحث: ' + searchVal);
    if (qtyFilter !== 'all') filterParts.push('الكمية: ' + (qtyFilter === 'available' ? 'متاحة' : 'غير متاحة'));
    const filterText = filterParts.length ? filterParts.join(' | ') : 'جميع المنتجات';

    let rows = '';
    let grandTotal = 0;
    let serial = 1;

    cards.forEach(card => {
        const rows_data = card.querySelectorAll('.product-detail-row');
        let name = '', category = '', quantity = '', unitPrice = '', totalVal = '', code = '';

        const nameEl = card.querySelector('.product-name');
        if (nameEl) name = nameEl.textContent.trim();

        const codeDiv = Array.from(card.querySelectorAll('div')).find(div => div.textContent.includes('الكود:'));
        if (codeDiv) code = codeDiv.textContent.replace('الكود:', '').trim();

        rows_data.forEach(row => {
            const spans = row.querySelectorAll('span');
            if (spans.length >= 2) {
                const label = spans[0].textContent.trim();
                const value = spans[1].textContent.trim();
                if (label.includes('الصنف')) category = value;
                else if (label.includes('الكمية')) quantity = value;
                else if (label.includes('سعر الوحدة')) unitPrice = value;
                else if (label.includes('الإجمالي')) totalVal = value;
            }
        });

        // جمع القيمة الإجمالية
        const totalNum = parseFloat(totalVal.replace(/[^0-9.]/g, '')) || 0;
        grandTotal += totalNum;

        rows += `<tr>
            <td>${serial++}</td>
            <td>${name}</td>
            <td>${code}</td>
            <td>${category}</td>
            <td>${quantity}</td>
            <td>${unitPrice}</td>
            <td class="total-cell">${totalVal}</td>
        </tr>`;
    });

    const now = new Date();
    const dateStr = now.toLocaleDateString('ar-EG', { year: 'numeric', month: 'long', day: 'numeric' });
    const timeStr = now.toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' });
    const fmtTotal = grandTotal.toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ج.م';

    const printHTML = `<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>جرد منتجات الفرز التاني</title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; direction: rtl; font-size: 13px; color: #222; background: #fff; padding: 20px; }
    .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #0c2c80; padding-bottom: 12px; }
    .header h1 { font-size: 20px; color: #0c2c80; margin-bottom: 4px; }
    .header .meta { font-size: 12px; color: #555; }
    .filter-info { background: #f0f4ff; border: 1px solid #c8d6ff; border-radius: 6px; padding: 8px 14px; margin-bottom: 16px; font-size: 12px; color: #333; }
    table { width: 100%; border-collapse: collapse; }
    thead th { background: #0c2c80; color: #fff; padding: 8px 10px; text-align: right; font-size: 13px; }
    tbody tr:nth-child(even) { background: #f5f7ff; }
    tbody td { padding: 7px 10px; border-bottom: 1px solid #dde; }
    .total-cell { font-weight: bold; color: #10b981; }
    .footer-row td { font-weight: bold; background: #e8edff; border-top: 2px solid #0c2c80; font-size: 14px; }
    @media print {
        body { padding: 10px; }
        button { display: none; }
    }
</style>
</head>
<body>
<div class="header">
    <h1>جرد منتجات الفرز التاني</h1>
    <div class="meta">${dateStr} — ${timeStr}</div>
</div>
<div class="filter-info">الفلتر المطبق: ${filterText} &nbsp;|&nbsp; عدد المنتجات: ${cards.length}</div>
<table>
    <thead>
        <tr>
            <th>#</th>
            <th>اسم المنتج</th>
            <th>الكود</th>
            <th>الصنف</th>
            <th>الكمية</th>
            <th>سعر الوحدة</th>
            <th>الإجمالي</th>
        </tr>
    </thead>
    <tbody>
        ${rows}
        <tr class="footer-row">
            <td colspan="6" style="text-align:center;">إجمالي القيمة</td>
            <td class="total-cell">${fmtTotal}</td>
        </tr>
    </tbody>
</table>
</body>
</html>`;

    const win = window.open('', '_blank', 'width=900,height=700');
    win.document.write(printHTML);
    win.document.close();
    win.focus();
    win.print();
}

window.printExternalInventory = printExternalInventory;
window.printFactoryInventory = printFactoryInventory;
window.printSecondGradeInventory = printSecondGradeInventory;

// ===== AJAX Edit External Product =====
(function() {
    const form = document.getElementById('editExternalProductForm');
    if (!form) return;

    form.addEventListener('submit', async function(e) {
        e.preventDefault();

        const submitBtn = form.querySelector('button[type="submit"]');
        const origText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>جاري الحفظ...';

        try {
            const formData = new FormData(form);
            const response = await fetch(window.location.href, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            });
            const data = await response.json();

            if (data.success) {
                updateExternalProductCard(data.product);
                closeEditExternalProductCard();
                showInlineToast(data.message, 'success');
            } else {
                showInlineToast(data.message || 'حدث خطأ غير متوقع.', 'danger');
            }
        } catch (err) {
            showInlineToast('تعذر الاتصال بالخادم.', 'danger');
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = origText;
            if (typeof window.resetPageLoading === 'function') window.resetPageLoading();
            if (typeof window.hidePageLoading === 'function') window.hidePageLoading();
        }
    });

    function updateExternalProductCard(product) {
        const fmt = (n) => parseFloat(n).toLocaleString('ar-EG', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ج.م';
        const qty = parseFloat(product.quantity).toFixed(2);
        const unit = product.unit || 'قطعة';
        const category = product.category || '—';
        const total = parseFloat(product.total_value || 0);

        // تحديث بيانات الزر في كل البطاقات والجدول
        document.querySelectorAll(`.js-edit-external[data-id="${product.id}"]`).forEach(btn => {
            btn.dataset.name = product.name;
            btn.dataset.quantity = product.quantity;
            btn.dataset.unit = unit;
            btn.dataset.price = product.unit_price;
            btn.dataset.category = category;
        });
        document.querySelectorAll(`.js-add-quantity-external[data-product-id="${product.id}"]`).forEach(btn => {
            btn.dataset.productName = product.name;
            btn.dataset.quantity = product.quantity;
        });
        document.querySelectorAll(`.js-delete-external[data-id="${product.id}"]`).forEach(btn => {
            btn.dataset.name = product.name;
        });

        // تحديث بطاقة المنتج في الشبكة
        document.querySelectorAll(`.js-edit-external[data-id="${product.id}"]`).forEach(btn => {
            const card = btn.closest('.product-card');
            if (!card) return;
            const rows = card.querySelectorAll('.product-detail-row');
            const nameEl = card.querySelector('.product-name');
            if (nameEl) nameEl.textContent = product.name;
            rows.forEach(row => {
                const label = row.querySelector('span:first-child');
                const val   = row.querySelector('span:last-child');
                if (!label || !val) return;
                const text = label.textContent.trim();
                if (text === 'الصنف:') val.textContent = category;
                else if (text === 'الكمية:') val.innerHTML = `<strong>${qty} ${unit}</strong>`;
                else if (text === 'سعر الوحدة:') val.textContent = fmt(product.unit_price);
                else if (text === 'الإجمالي:') val.innerHTML = `<strong class="text-success">${fmt(total)}</strong>`;
            });
        });
    }

    function showInlineToast(message, type) {
        let container = document.getElementById('ajaxToastContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'ajaxToastContainer';
            container.style.cssText = 'position:fixed;top:20px;left:50%;transform:translateX(-50%);z-index:9999;min-width:280px;';
            document.body.appendChild(container);
        }
        const toast = document.createElement('div');
        toast.className = `alert alert-${type} shadow d-flex align-items-center gap-2 mb-2`;
        toast.style.cssText = 'animation:fadeInDown .3s ease;';
        toast.innerHTML = `<i class="bi bi-${type === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill'}"></i> ${message}`;
        container.appendChild(toast);
        setTimeout(() => { toast.style.opacity = '0'; toast.style.transition = 'opacity .4s'; setTimeout(() => toast.remove(), 400); }, 3000);
    }
})();

// ===== Section Collapse with localStorage =====
(function() {
    const PREF_KEY = 'companyProductsSections';

    function getPrefs() {
        try { return JSON.parse(localStorage.getItem(PREF_KEY)) || {}; } catch { return {}; }
    }
    function savePrefs(prefs) {
        localStorage.setItem(PREF_KEY, JSON.stringify(prefs));
    }

    function applyState(header, body, collapsed, animate) {
        if (collapsed) {
            header.classList.add('collapsed');
            body.style.display = 'none';
        } else {
            header.classList.remove('collapsed');
            body.style.display = '';
        }
    }

    document.querySelectorAll('.section-header[data-section]').forEach(function(header) {
        const sectionKey = header.dataset.section;
        const card = header.closest('.card');
        const body = card ? card.querySelector('.section-collapse-body') : null;
        if (!body) return;

        const prefs = getPrefs();
        const collapsed = prefs[sectionKey] === true;
        applyState(header, body, collapsed, false);

        header.addEventListener('click', function() {
            const isCollapsed = header.classList.contains('collapsed');
            const newCollapsed = !isCollapsed;
            applyState(header, body, newCollapsed, true);
            const p = getPrefs();
            p[sectionKey] = newCollapsed;
            savePrefs(p);
        });
    });
})();
</script>

<!-- Modal المنتجات الراكدة -->
<div class="modal fade" id="stagnantProductsModal" tabindex="-1" aria-labelledby="stagnantProductsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title" id="stagnantProductsModalLabel">
                    <i class="bi bi-hourglass-split me-2"></i>المنتجات الراكدة (لم يتم الخصم منها خلال 21 يوم)
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div id="stagnantLoadingSpinner" class="text-center py-5">
                    <div class="spinner-border text-warning" role="status"></div>
                    <div class="mt-2 text-muted">جاري تحميل التقرير...</div>
                </div>
                <div id="stagnantContent" style="display:none;">
                    <div id="stagnantSummary" class="p-3 bg-light border-bottom d-flex gap-3 flex-wrap align-items-center">
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" style="font-size:14px;">
                            <thead class="table-dark">
                                <tr>
                                    <th>#</th>
                                    <th>اسم المنتج</th>
                                    <th>القسم</th>
                                    <th>الصنف</th>
                                    <th>الكمية المتبقية</th>
                                    <th>السعر</th>
                                    <th>رقم التشغيلة</th>
                                    <th>آخر خصم</th>
                                    <th>أيام الركود</th>
                                </tr>
                            </thead>
                            <tbody id="stagnantTableBody"></tbody>
                        </table>
                    </div>
                </div>
                <div id="stagnantEmpty" class="text-center py-5" style="display:none;">
                    <i class="bi bi-check-circle text-success" style="font-size:3rem;"></i>
                    <div class="mt-3 text-success fw-bold">لا توجد منتجات راكدة — جميع المنتجات تم الخصم منها خلال الـ 21 يوم الماضية</div>
                </div>
                <div id="stagnantError" class="alert alert-danger m-3" style="display:none;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
                <button type="button" class="btn btn-outline-dark" onclick="printStagnantReport()">
                    <i class="bi bi-printer me-1"></i>طباعة
                </button>
            </div>
        </div>
    </div>
</div>

<script>
async function showStagnantProductsReport() {
    const modal = new bootstrap.Modal(document.getElementById('stagnantProductsModal'));
    modal.show();

    document.getElementById('stagnantLoadingSpinner').style.display = '';
    document.getElementById('stagnantContent').style.display = 'none';
    document.getElementById('stagnantEmpty').style.display = 'none';
    document.getElementById('stagnantError').style.display = 'none';

    try {
        const url = window.location.href.split('?')[0] + '?page=company_products&action=stagnant_products_report';
        const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const json = await response.json();

        document.getElementById('stagnantLoadingSpinner').style.display = 'none';

        if (!json.success) {
            document.getElementById('stagnantError').style.display = '';
            document.getElementById('stagnantError').textContent = 'خطأ: ' + (json.message || 'حدث خطأ غير متوقع');
            return;
        }

        const data = json.data || [];
        if (data.length === 0) {
            document.getElementById('stagnantEmpty').style.display = '';
            return;
        }

        // Summary
        const typeLabels = { factory: 'منتجات المصنع', external: 'منتجات خارجية', second_grade: 'درجة ثانية' };
        const counts = {};
        data.forEach(r => { counts[r.product_type] = (counts[r.product_type] || 0) + 1; });
        let summaryHtml = `<span class="badge bg-warning text-dark fs-6">${data.length} منتج راكد إجمالاً</span>`;
        Object.entries(counts).forEach(([type, cnt]) => {
            summaryHtml += ` <span class="badge bg-secondary">${typeLabels[type] || type}: ${cnt}</span>`;
        });
        document.getElementById('stagnantSummary').innerHTML = summaryHtml;

        // Table rows
        const now = new Date();
        const tbody = document.getElementById('stagnantTableBody');
        tbody.innerHTML = '';
        data.forEach((row, idx) => {
            let daysSince = '—';
            let daysNum = Infinity;
            if (row.last_deduction_at) {
                const lastDate = new Date(row.last_deduction_at);
                daysNum = Math.floor((now - lastDate) / 86400000);
                daysSince = daysNum + ' يوم';
            } else {
                daysSince = 'لم يُخصم قط';
            }

            const badgeColor = daysNum >= 60 ? 'danger' : daysNum >= 30 ? 'warning' : 'secondary';
            const typeLabel = typeLabels[row.product_type] || row.product_type;
            const qty = parseFloat(row.quantity || 0).toLocaleString('ar-EG', { maximumFractionDigits: 2 });
            const price = parseFloat(row.unit_price || 0).toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            const lastDate = row.last_deduction_at ? row.last_deduction_at.substring(0, 10) : '—';

            tbody.insertAdjacentHTML('beforeend', `
                <tr>
                    <td>${idx + 1}</td>
                    <td><strong>${escapeHtml(row.name || '')}</strong></td>
                    <td><span class="badge bg-primary">${typeLabel}</span></td>
                    <td>${escapeHtml(row.category || '—')}</td>
                    <td>${qty}</td>
                    <td>${price} ج.م</td>
                    <td>${escapeHtml(row.batch_number || '—')}</td>
                    <td>${lastDate}</td>
                    <td><span class="badge bg-${badgeColor}">${daysSince}</span></td>
                </tr>
            `);
        });

        document.getElementById('stagnantContent').style.display = '';
    } catch (e) {
        document.getElementById('stagnantLoadingSpinner').style.display = 'none';
        document.getElementById('stagnantError').style.display = '';
        document.getElementById('stagnantError').textContent = 'فشل تحميل التقرير: ' + e.message;
    }
}

function printStagnantReport() {
    const content = document.getElementById('stagnantContent');
    if (!content || content.style.display === 'none') return;
    const win = window.open('', '_blank');
    win.document.write(`
        <html><head><meta charset="UTF-8"><title>المنتجات الراكدة</title>
        <style>
            body { font-family: Arial, sans-serif; direction: rtl; font-size: 13px; }
            table { width: 100%; border-collapse: collapse; }
            th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: right; }
            th { background: #f5c518; }
            h2 { text-align: center; }
        </style></head><body>
        <h2>تقرير المنتجات الراكدة (لم يُخصم منها خلال 21 يوم)</h2>
        <p>تاريخ التقرير: ${new Date().toLocaleDateString('ar-EG')}</p>
        ${document.getElementById('stagnantSummary').innerHTML}
        <br>
        ${document.querySelector('#stagnantContent .table-responsive').innerHTML}
        </body></html>
    `);
    win.document.close();
    win.print();
}

function escapeHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

