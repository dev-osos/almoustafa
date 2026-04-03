<?php
/**
 * Homeline Style Sidebar - Modern Collapsible Sidebar
 * شريط جانبي حديث قابل للطي بتصميم Homeline
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../includes/path_helper.php';

// التأكد من أن $currentUser موجود - إذا لم يكن موجوداً، محاولة تحميله
if (!isset($currentUser) || $currentUser === null) {
    // محاولة تحميل auth.php إذا لم يكن محملاً
    if (!function_exists('getCurrentUser')) {
        if (!defined('ACCESS_ALLOWED')) {
            define('ACCESS_ALLOWED', true);
        }
        require_once __DIR__ . '/../includes/config.php';
        require_once __DIR__ . '/../includes/db.php';
        require_once __DIR__ . '/../includes/auth.php';
    }
    if (function_exists('getCurrentUser')) {
        $currentUser = getCurrentUser();
    }
}

// التأكد من أن $lang موجود
if (!isset($lang) || empty($lang)) {
    if (!function_exists('getCurrentLanguage')) {
        if (!defined('ACCESS_ALLOWED')) {
            define('ACCESS_ALLOWED', true);
        }
        require_once __DIR__ . '/../includes/config.php';
    }
    if (function_exists('getCurrentLanguage')) {
        $currentLang = getCurrentLanguage();
        $langFile = __DIR__ . '/../includes/lang/' . $currentLang . '.php';
        if (file_exists($langFile)) {
            require_once $langFile;
        }
        if (isset($translations)) {
            $lang = $translations;
        }
    }
}

$currentPage = basename($_SERVER['PHP_SELF']);
// الحصول على base path فقط (بدون /dashboard/)
$basePath = getBasePath();
$baseUrl = rtrim($basePath, '/') . '/dashboard/';

// الحصول على role بأمان
$role = '';
if (isset($currentUser) && is_array($currentUser) && isset($currentUser['role'])) {
    $role = strtolower(trim($currentUser['role']));
}

// تم إزالة نظام الجلسات - استخدام getUserFromToken() فقط
if (empty($role) && function_exists('getUserFromToken')) {
    $user = getUserFromToken();
    if ($user && isset($user['role'])) {
        $role = strtolower(trim($user['role']));
    }
}

$currentPageParam = trim($_GET['page'] ?? '');
$currentFocus = trim($_GET['focus'] ?? '');
$sidebarPreferenceUserKey = 'homeline_sidebar_groups_' . ($role ?: 'guest') . '_' . (isset($currentUser['id']) ? (int) $currentUser['id'] : 0);

// محاولة تحديد role من الصفحة الحالية إذا كان غير معروف
if (empty($role)) {
    if ($currentPage === 'sales.php') {
        $role = 'sales';
    } elseif ($currentPage === 'manager.php') {
        $role = 'manager';
    } elseif ($currentPage === 'accountant.php') {
        $role = 'accountant';
    } elseif ($currentPage === 'production.php') {
        $role = 'production';
    } elseif ($currentPage === 'driver.php') {
        $role = 'driver';
    } elseif ($currentPage === 'developer.php') {
        $role = 'developer';
    }
}

// تحديد الروابط بناءً على الدور
$menuItems = [];

switch ($role) {
    case 'manager':
        $menuItems = [
            ['divider' => true, 'title' => 'إدارة الأوردرات'],
            [
                'title' => 'تسجيل الأوردرات',
                'icon' => 'bi-list-task',
                'url' => $baseUrl . 'manager.php?page=production_tasks',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'production_tasks'),
                'badge' => null
            ],
            [
                'title' => 'طلبات الشحن',
                'icon' => 'bi-truck',
                'url' => $baseUrl . 'manager.php?page=shipping_orders',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'shipping_orders'),
                'badge' => null
            ],
            [
                'title' => 'تسجيل طلبات العملاء',
                'icon' => 'bi-bag-check',
                'url' => $baseUrl . 'manager.php?page=orders',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'orders'),
                'badge' => null
            ],
            [
                'title' => 'نقطة البيع',
                'icon' => 'bi-cart4',
                'url' => $baseUrl . 'manager.php?page=pos',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'pos'),
                'badge' => null
            ],

            ['divider' => true, 'title' => 'إدارة العملاء'],
            [
                'title' => 'العملاء المحليين',
                'icon' => 'bi-people',
                'url' => $baseUrl . 'manager.php?page=local_customers',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'local_customers'),
                'badge' => null
            ],
            [
                'title' => 'عملاء المندوبين',
                'icon' => 'bi-people-fill',
                'url' => $baseUrl . 'manager.php?page=representatives_customers',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'representatives_customers'),
                'badge' => null
            ],
            [
                'title' => 'عملاء المتجر',
                'icon' => 'bi-shop-window',
                'url' => '#',
                'active' => false,
                'badge' => '<span class="badge text-bg-warning-subtle text-warning-emphasis border border-warning-subtle">قريباً</span>',
                'disabled' => true,
                'title_attr' => 'قيد التطوير'
            ],
            [
                'title' => 'الأسعار المخصصة',
                'icon' => 'bi-tag',
                'url' => $baseUrl . 'manager.php?page=custom_prices',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'custom_prices'),
                'badge' => null
            ],
            [
                'title' => 'جداول التحصيل اليومية',
                'icon' => 'bi-calendar2-range',
                'url' => $baseUrl . 'manager.php?page=daily_collection_schedules',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'daily_collection_schedules'),
                'badge' => null
            ],
            [
                'title' => 'جداول التحصيل',
                'icon' => 'bi-calendar-check',
                'url' => $baseUrl . 'manager.php?page=company_payment_schedules',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'company_payment_schedules'),
                'badge' => null
            ],
            [
                'title' => 'تسجيل المرتجعات',
                'icon' => 'bi-arrow-return-left',
                'url' => $baseUrl . 'manager.php?page=register_returns',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'register_returns'),
                'badge' => null
            ],
            [
                'title' => 'أرصدة العملاء الدائنة',
                'icon' => 'bi-wallet2',
                'url' => $baseUrl . 'manager.php?page=customer_credit_balances',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'customer_credit_balances'),
                'badge' => null
            ],

            ['divider' => true, 'title' => 'إدارة المخازن'],
            [
                'title' => 'منتجات الشركة',
                'icon' => 'bi-box-seam',
                'url' => $baseUrl . 'manager.php?page=company_products',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'company_products'),
                'badge' => null
            ],
            [
                'title' => 'مخزن الخامات',
                'icon' => 'bi-box-seam',
                'url' => $baseUrl . 'manager.php?page=raw_materials_warehouse',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'raw_materials_warehouse'),
                'badge' => null
            ],
            [
                'title' => 'مخزن أدوات التعبئة',
                'icon' => 'bi-box-seam',
                'url' => $baseUrl . 'manager.php?page=packaging_warehouse',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'packaging_warehouse'),
                'badge' => null
            ],
            [
                'title' => 'تسجيل الواردات',
                'icon' => 'bi-box-arrow-in-down',
                'url' => $baseUrl . 'manager.php?page=inbound_supplies',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'inbound_supplies'),
                'badge' => null
            ],
            [
                'title' => 'مستلزمات الشركة',
                'icon' => 'bi-box-seam',
                'url' => $baseUrl . 'manager.php?page=company_supplies',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'company_supplies'),
                'badge' => null
            ],
            [
                'title' => 'مخزن توالف المصنع',
                'icon' => 'bi-trash',
                'url' => $baseUrl . 'manager.php?page=factory_waste_warehouse',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'factory_waste_warehouse'),
                'badge' => null
            ],

            ['divider' => true, 'title' => 'إدارة الماليات'],
            [
                'title' => 'خزنة الشركة',
                'icon' => 'bi-bank',
                'url' => $baseUrl . 'manager.php?page=company_cash',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'company_cash'),
                'badge' => null
            ],
            [
                'title' => 'محافظ المستخدمين',
                'icon' => 'bi-wallet',
                'url' => $baseUrl . 'manager.php?page=user_wallets_control',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'user_wallets_control'),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_suppliers']) ? $lang['menu_suppliers'] : 'الموردين',
                'icon' => 'bi-truck',
                'url' => $baseUrl . 'manager.php?page=suppliers',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'suppliers'),
                'badge' => null
            ],

            ['divider' => true, 'title' => 'إدارة الموظفين'],
            [
                'title' => isset($lang['menu_salaries']) ? $lang['menu_salaries'] : 'الرواتب',
                'icon' => 'bi-currency-dollar',
                'url' => $baseUrl . 'manager.php?page=salaries',
                'active' => ($currentPage === 'manager.php' && in_array($currentPageParam, ['salaries', 'salary_details'], true)),
                'badge' => null
            ],
            [
                'title' => 'متابعة الحضور والانصراف',
                'icon' => 'bi-calendar-check',
                'url' => $baseUrl . 'manager.php?page=attendance_management',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'attendance_management'),
                'badge' => null
            ],
            [
                'title' => 'إدارة المستخدمين والأدوار',
                'icon' => 'bi-person-gear',
                'url' => $baseUrl . 'manager.php?page=users',
                'active' => ($currentPage === 'manager.php' && in_array($currentPageParam, ['users', 'permissions'], true)),
                'badge' => null
            ],

            ['divider' => true, 'title' => 'إدارة السيارات'],
            [
                'title' => 'السيارات',
                'icon' => 'bi-truck',
                'url' => $baseUrl . 'manager.php?page=vehicles',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'vehicles'),
                'badge' => null
            ],
            [
                'title' => 'صيانات السيارات',
                'icon' => 'bi-wrench-adjustable',
                'url' => $baseUrl . 'manager.php?page=vehicle_maintenance',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'vehicle_maintenance'),
                'badge' => null
            ],
            [
                'title' => 'تتبع السائقين',
                'icon' => 'bi-geo-alt-fill',
                'url' => $baseUrl . 'manager.php?page=driver_tracking',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'driver_tracking'),
                'badge' => null,
                'no_ajax' => true
            ],

            ['divider' => true, 'title' => 'إدارة المنتجات'],
            [
                'title' => 'قوالب ووصفات المنتجات',
                'icon' => 'bi-file-earmark-text',
                'url' => $baseUrl . 'manager.php?page=product_templates',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'product_templates'),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_warehouse_transfers']) ? $lang['menu_warehouse_transfers'] : 'نقل المخازن',
                'icon' => 'bi-arrow-left-right',
                'url' => $baseUrl . 'manager.php?page=warehouse_transfers',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'warehouse_transfers'),
                'badge' => null
            ],
            [
                'title' => 'قارئ أرقام التشغيلات',
                'icon' => 'bi-upc-scan',
                'url' => $baseUrl . 'manager.php?page=batch_reader',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'batch_reader'),
                'badge' => null
            ],

            ['divider' => true, 'title' => 'إدارة التقارير'],
            [
                'title' => isset($lang['menu_reports']) ? $lang['menu_reports'] : 'التقارير',
                'icon' => 'bi-file-earmark-text',
                'url' => $baseUrl . 'manager.php?page=reports',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'reports'),
                'badge' => null
            ],
            [
                'title' => 'الفواتير',
                'icon' => 'bi-receipt',
                'url' => $baseUrl . 'manager.php?page=invoices',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'invoices'),
                'badge' => null
            ],

            ['divider' => true, 'title' => 'إدارة النظام'],
            [
                'title' => 'إعدادات النظام',
                'icon' => 'bi-gear',
                'url' => $baseUrl . 'manager.php?page=system_settings',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'system_settings'),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_security']) ? $lang['menu_security'] : 'الأمان',
                'icon' => 'bi-lock',
                'url' => $baseUrl . 'manager.php?page=security',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'security'),
                'badge' => null
            ],

            ['divider' => true, 'title' => 'عام'],
            [
                'title' => isset($lang['dashboard']) ? $lang['dashboard'] : 'لوحة التحكم',
                'icon' => 'bi-speedometer2',
                'url' => $baseUrl . 'manager.php',
                'active' => ($currentPage === 'manager.php' && ($currentPageParam === 'overview' || $currentPageParam === '')),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_approvals']) ? $lang['menu_approvals'] : 'الموافقات',
                'icon' => 'bi-check-circle',
                'url' => $baseUrl . 'manager.php?page=approvals',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'approvals'),
                'badge' => '<span class="badge" id="approvalBadge">0</span>'
            ],
            [
                'title' => 'الشات',
                'icon' => 'bi-chat-dots',
                'url' => $baseUrl . 'manager.php?page=chat',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'chat'),
                'badge' => null
            ]
        ];
        break;
        
    case 'accountant':
        $menuItems = [
            // 1) إدارة العملاء
            ['divider' => true, 'title' => 'إدارة العملاء'],
            [
                'title' => 'العملاء المحليين',
                'icon' => 'bi-people',
                'url' => $baseUrl . 'accountant.php?page=local_customers',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'local_customers'),
                'badge' => null
            ],
            [
                'title' => 'عملاء المندوبين',
                'icon' => 'bi-people-fill',
                'url' => $baseUrl . 'accountant.php?page=representatives_customers',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'representatives_customers'),
                'badge' => null
            ],
            [
                'title' => 'عملاء المتجر',
                'icon' => 'bi-shop-window',
                'url' => '#',
                'active' => false,
                'badge' => '<span class="badge bg-warning text-dark">قريباً</span>',
                'disabled' => true,
                'title_attr' => 'تبويب عملاء المتجر قيد التطوير وسيتم تفعيله قريباً.'
            ],
            [
                'title' => 'الأسعار المخصصة',
                'icon' => 'bi-tag',
                'url' => $baseUrl . 'accountant.php?page=custom_prices',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'custom_prices'),
                'badge' => null
            ],
            [
                'title' => 'جداول التحصيل اليومية',
                'icon' => 'bi-calendar2-range',
                'url' => $baseUrl . 'accountant.php?page=daily_collection_schedules',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'daily_collection_schedules'),
                'badge' => null
            ],
            [
                'title' => 'جداول التحصيل',
                'icon' => 'bi-calendar-check',
                'url' => $baseUrl . 'accountant.php?page=company_payment_schedules',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'company_payment_schedules'),
                'badge' => null
            ],
            [
                'title' => 'تسجيل المرتجعات',
                'icon' => 'bi-arrow-return-left',
                'url' => $baseUrl . 'accountant.php?page=register_returns',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'register_returns'),
                'badge' => null
            ],
            [
                'title' => 'أرصدة العملاء الدائنة',
                'icon' => 'bi-wallet2',
                'url' => $baseUrl . 'accountant.php?page=customer_credit_balances',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'customer_credit_balances'),
                'badge' => null
            ],

            // 2) إدارة الأوردرات
            ['divider' => true, 'title' => 'إدارة الأوردرات'],
            [
                'title' => 'تسجيل الأوردرات',
                'icon' => 'bi-send-check',
                'url' => $baseUrl . 'accountant.php?page=production_tasks',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'production_tasks'),
                'badge' => null
            ],
            [
                'title' => 'طلبات الشحن',
                'icon' => 'bi-truck',
                'url' => $baseUrl . 'accountant.php?page=shipping_orders',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'shipping_orders'),
                'badge' => null
            ],
            [
                'title' => 'تسجيل طلبات العملاء',
                'icon' => 'bi-bag-check',
                'url' => $baseUrl . 'accountant.php?page=orders',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'orders'),
                'badge' => null
            ],
            [
                'title' => 'نقطة البيع',
                'icon' => 'bi-cart4',
                'url' => $baseUrl . 'accountant.php?page=pos',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'pos'),
                'badge' => null
            ],

            // 3) إدارة المخازن
            ['divider' => true, 'title' => 'إدارة المخازن'],
            [
                'title' => 'منتجات الشركة',
                'icon' => 'bi-box-seam',
                'url' => $baseUrl . 'accountant.php?page=company_products',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'company_products'),
                'badge' => null
            ],
            [
                'title' => 'مخزن الخامات',
                'icon' => 'bi-droplet',
                'url' => $baseUrl . 'accountant.php?page=raw_materials_warehouse',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'raw_materials_warehouse'),
                'badge' => null
            ],
            [
                'title' => 'مخزن أدوات التعبئة',
                'icon' => 'bi-box-seam',
                'url' => $baseUrl . 'accountant.php?page=packaging_warehouse',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'packaging_warehouse'),
                'badge' => null
            ],
            [
                'title' => 'مستلزمات الشركة',
                'icon' => 'bi-box-seam',
                'url' => $baseUrl . 'accountant.php?page=company_supplies',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'company_supplies'),
                'badge' => null
            ],
            [
                'title' => 'تسجيل الواردات',
                'icon' => 'bi-box-arrow-in-down',
                'url' => $baseUrl . 'accountant.php?page=inbound_supplies',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'inbound_supplies'),
                'badge' => null
            ],
            [
                'title' => 'مخزن توالف المصنع',
                'icon' => 'bi-trash',
                'url' => $baseUrl . 'accountant.php?page=factory_waste_warehouse',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'factory_waste_warehouse'),
                'badge' => null
            ],

            // 4) إدارة الماليات
            ['divider' => true, 'title' => 'إدارة الماليات'],
            [
                'title' => 'خزنة الشركة',
                'icon' => 'bi-safe',
                'url' => $baseUrl . 'accountant.php?page=accountant_cash',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'accountant_cash'),
                'badge' => null
            ],
            [
                'title' => 'محافظ المستخدمين',
                'icon' => 'bi-wallet',
                'url' => $baseUrl . 'accountant.php?page=user_wallets_control',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'user_wallets_control'),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_suppliers']) ? $lang['menu_suppliers'] : 'الموردين',
                'icon' => 'bi-truck',
                'url' => $baseUrl . 'accountant.php?page=suppliers',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'suppliers'),
                'badge' => null
            ],

            // 5) إدارة الموظفين
            ['divider' => true, 'title' => 'إدارة الموظفين'],
            [
                'title' => isset($lang['menu_salaries']) ? $lang['menu_salaries'] : 'الرواتب',
                'icon' => 'bi-currency-dollar',
                'url' => $baseUrl . 'accountant.php?page=salaries',
                'active' => ($currentPage === 'accountant.php' && ($currentPageParam === 'salaries' || $currentPageParam === 'salary_details')),
                'badge' => null
            ],
            [
                'title' => isset($lang['my_salary']) ? $lang['my_salary'] : 'مرتبي',
                'icon' => 'bi-wallet2',
                'url' => $baseUrl . 'accountant.php?page=my_salary',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'my_salary'),
                'badge' => null
            ],
            [
                'title' => 'متابعة الحضور والانصراف',
                'icon' => 'bi-bar-chart',
                'url' => $baseUrl . 'accountant.php?page=attendance_management',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'attendance_management'),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_attendance']) ? $lang['menu_attendance'] : 'الحضور',
                'icon' => 'bi-calendar-check',
                'url' => getRelativeUrl('attendance.php'),
                'active' => ($currentPage === 'attendance.php'),
                'badge' => null
            ],

            // 6) إدارة السيارات
            ['divider' => true, 'title' => 'إدارة السيارات'],
            [
                'title' => 'السيارات',
                'icon' => 'bi-car-front',
                'url' => $baseUrl . 'accountant.php?page=vehicles',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'vehicles'),
                'badge' => null
            ],
            [
                'title' => 'صيانات السيارات',
                'icon' => 'bi-wrench-adjustable',
                'url' => $baseUrl . 'accountant.php?page=vehicle_maintenance',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'vehicle_maintenance'),
                'badge' => null
            ],
            [
                'title' => 'تتبع السائقين',
                'icon' => 'bi-geo-alt-fill',
                'url' => $baseUrl . 'accountant.php?page=driver_tracking',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'driver_tracking'),
                'badge' => null,
                'no_ajax' => true
            ],

            // 7) إدارة المنتجات
            ['divider' => true, 'title' => 'إدارة المنتجات'],
            [
                'title' => 'قوالب ووصفات المنتجات',
                'icon' => 'bi-file-earmark-text',
                'url' => $baseUrl . 'manager.php?page=product_templates',
                'active' => ($currentPage === 'manager.php' && $currentPageParam === 'product_templates'),
                'badge' => null,
                'no_ajax' => true
            ],
            [
                'title' => 'نقل المخازن',
                'icon' => 'bi-arrow-left-right',
                'url' => $baseUrl . 'accountant.php?page=warehouse_transfers',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'warehouse_transfers'),
                'badge' => null
            ],
            [
                'title' => 'قارئ أرقام التشغيلات',
                'icon' => 'bi-upc-scan',
                'url' => $baseUrl . 'accountant.php?page=batch_reader',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'batch_reader'),
                'badge' => null
            ],

            // 8) إدارة التقارير
            ['divider' => true, 'title' => 'إدارة التقارير'],
            [
                'title' => isset($lang['menu_reports']) ? $lang['menu_reports'] : 'التقارير',
                'icon' => 'bi-file-earmark-text',
                'url' => $baseUrl . 'accountant.php?page=reports',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'reports'),
                'badge' => null
            ],
            [
                'title' => 'الفواتير',
                'icon' => 'bi-receipt',
                'url' => $baseUrl . 'accountant.php?page=invoices',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'invoices'),
                'badge' => null
            ],

            // 9) عام
            ['divider' => true, 'title' => 'عام'],
            [
                'title' => isset($lang['dashboard']) ? $lang['dashboard'] : 'لوحة التحكم',
                'icon' => 'bi-speedometer2',
                'url' => $baseUrl . 'accountant.php',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === ''),
                'badge' => null
            ],
            [
                'title' => 'الشات',
                'icon' => 'bi-chat-dots',
                'url' => $baseUrl . 'accountant.php?page=chat',
                'active' => ($currentPage === 'accountant.php' && $currentPageParam === 'chat'),
                'badge' => null
            ]
        ];
        break;
        
    case 'sales':
        $menuItems = [
            [
                'title' => isset($lang['dashboard']) ? $lang['dashboard'] : 'لوحة التحكم',
                'icon' => 'bi-speedometer2',
                'url' => $baseUrl . 'sales.php',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === ''),
                'badge' => null
            ],
            [
                'title' => 'الشات',
                'icon' => 'bi-chat-dots',
                'url' => $baseUrl . 'sales.php?page=chat',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'chat'),
                'badge' => null
            ],
            ['divider' => true, 'title' => isset($lang['sales_section']) ? $lang['sales_section'] : 'المبيعات'],
            [
                'title' => isset($lang['customers']) ? $lang['customers'] : 'العملاء',
                'icon' => 'bi-people',
                'url' => $baseUrl . 'sales.php?page=customers',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'customers'),
                'badge' => null
            ],

            [
                'title' => isset($lang['customer_orders']) ? $lang['customer_orders'] : 'طلبات العملاء',
                'icon' => 'bi-clipboard-check',
                'url' => $baseUrl . 'sales.php?page=orders',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'orders'),
                'badge' => (isset($currentUser) && $currentUser['role'] === 'sales' && function_exists('getNewOrdersCount')) ? getNewOrdersCount($currentUser['id']) : null
            ],
            [
                'title' => isset($lang['payment_schedules']) ? $lang['payment_schedules'] : 'جداول التحصيل',
                'icon' => 'bi-calendar-event',
                'url' => $baseUrl . 'sales.php?page=payment_schedules',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'payment_schedules'),
                'badge' => null
            ],
            [
                'title' => isset($lang['vehicle_inventory']) ? $lang['vehicle_inventory'] : 'مخزون السيارات',
                'icon' => 'bi-truck',
                'url' => $baseUrl . 'sales.php?page=vehicle_inventory',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'vehicle_inventory'),
                'badge' => null
            ],
           
           
            [
                'title' => isset($lang['sales_pos']) ? $lang['sales_pos'] : 'نقطة البيع',
                'icon' => 'bi-shop',
                'url' => $baseUrl . 'sales.php?page=pos',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'pos'),
                'badge' => null
            ],
            [
                'title' => 'خزنة المندوب',
                'icon' => 'bi-cash-stack',
                'url' => $baseUrl . 'sales.php?page=cash_register',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'cash_register'),
                'badge' => null
            ],
            [
                'title' => 'محفظة المستخدم',
                'icon' => 'bi-wallet2',
                'url' => $baseUrl . 'sales.php?page=user_wallet',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'user_wallet'),
                'badge' => null
            ],
            [
                'title' => 'سجلات المندوب',
                'icon' => 'bi-journal-text',
                'url' => $baseUrl . 'sales.php?page=my_records',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'my_records'),
                'badge' => null
            ],
            ['divider' => true, 'title' => 'الحضور و الرواتب'],
            [
                'title' => 'الحضور',
                'icon' => 'bi-clock-history',
                'url' => $baseUrl . 'sales.php?page=attendance',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'attendance'),
                'badge' => null
            ],
            [
                'title' => 'مرتبي',
                'icon' => 'bi-cash',
                'url' => $baseUrl . 'sales.php?page=my_salary',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'my_salary'),
                'badge' => null
            ],
            ['divider' => true, 'title' => 'أدوات'],
            [
                'title' => 'قارئ أرقام التشغيلات',
                'icon' => 'bi-upc-scan',
                'url' => $baseUrl . 'sales.php?page=batch_reader',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'batch_reader'),
                'badge' => null
            ]
        ];
        break;
        
    case 'developer':
        $menuItems = [
            [
                'title' => 'لوحة المطور',
                'icon' => 'bi-code-slash',
                'url' => $baseUrl . 'developer.php',
                'active' => ($currentPage === 'developer.php' && ($currentPageParam === 'overview' || $currentPageParam === '')),
                'badge' => null
            ],
            ['divider' => true, 'title' => 'إدارة النظام'],
            [
                'title' => 'إعدادات النظام',
                'icon' => 'bi-gear',
                'url' => $baseUrl . 'developer.php?page=system_settings',
                'active' => ($currentPage === 'developer.php' && $currentPageParam === 'system_settings'),
                'badge' => null
            ],
            [
                'title' => 'المستخدمين',
                'icon' => 'bi-people',
                'url' => $baseUrl . 'developer.php?page=users',
                'active' => ($currentPage === 'developer.php' && $currentPageParam === 'users'),
                'badge' => null
            ],
            [
                'title' => 'الأمان',
                'icon' => 'bi-shield-lock',
                'url' => $baseUrl . 'developer.php?page=security',
                'active' => ($currentPage === 'developer.php' && $currentPageParam === 'security'),
                'badge' => null
            ],
            [
                'title' => 'سجلات التدقيق',
                'icon' => 'bi-journal-text',
                'url' => $baseUrl . 'developer.php?page=audit_logs',
                'active' => ($currentPage === 'developer.php' && $currentPageParam === 'audit_logs'),
                'badge' => null
            ],
            [
                'title' => 'النسخ الاحتياطية',
                'icon' => 'bi-database',
                'url' => $baseUrl . 'developer.php?page=backups',
                'active' => ($currentPage === 'developer.php' && $currentPageParam === 'backups'),
                'badge' => null
            ],
            ['divider' => true, 'title' => 'لوحات أخرى'],
            [
                'title' => 'لوحة المدير',
                'icon' => 'bi-speedometer2',
                'url' => $baseUrl . 'manager.php',
                'active' => false,
                'badge' => null
            ]
        ];
        break;
    
    case 'production':
        $menuItems = [
            ['divider' => true, 'title' => 'الرئيسية'],
            [
                'title' => isset($lang['dashboard']) ? $lang['dashboard'] : 'لوحة التحكم',
                'icon' => 'bi-speedometer2',
                'url' => $baseUrl . 'production.php',
                'active' => ($currentPage === 'production.php' && $currentPageParam === ''),
                'badge' => null
            ],
            [
                'title' => 'الشات',
                'icon' => 'bi-chat-dots',
                'url' => $baseUrl . 'production.php?page=chat',
                'active' => ($currentPage === 'production.php' && $currentPageParam === 'chat'),
                'badge' => null
            ],
            ['divider' => true, 'title' => 'التشغيل اليومي'],
            [
                'title' => isset($lang['menu_production']) ? $lang['menu_production'] : 'الإنتاج',
                'icon' => 'bi-box-seam',
                'url' => $baseUrl . 'production.php?page=production',
                'active' => ($currentPage === 'production.php' && $currentPageParam === 'production' && $currentFocus !== 'warehouse-damage-log'),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_tasks']) ? $lang['menu_tasks'] : 'أوردرات',
                'icon' => 'bi-list-check',
                'url' => $baseUrl . 'production.php?page=tasks',
                'active' => ($currentPage === 'production.php' && $currentPageParam === 'tasks'),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_attendance']) ? $lang['menu_attendance'] : 'الحضور',
                'icon' => 'bi-calendar-check',
                'url' => getRelativeUrl('attendance.php'),
                'active' => ($currentPage === 'attendance.php'),
                'badge' => null
            ],
            ['divider' => true, 'title' => 'المخازن'],
            [
                'title' => 'مستلزمات الشركة',
                'icon' => 'bi-box-seam',
                'url' => $baseUrl . 'production.php?page=company_supplies',
                'active' => ($currentPage === 'production.php' && $currentPageParam === 'company_supplies'),
                'badge' => null
            ],
            [
                'title' => 'مخزن الخامات',
                'icon' => 'bi-droplet-half',
                'url' => $baseUrl . 'production.php?page=raw_materials_warehouse',
                'active' => ($currentPage === 'production.php' && $currentPageParam === 'raw_materials_warehouse'),
                'badge' => null
            ],
            [
                'title' => 'مخزن أدوات التعبئة',
                'icon' => 'bi-box2-heart',
                'url' => $baseUrl . 'production.php?page=packaging_warehouse',
                'active' => ($currentPage === 'production.php' && $currentPageParam === 'packaging_warehouse'),
                'badge' => null
            ],
            [
                'title' => 'تسجيل الواردات',
                'icon' => 'bi-box-arrow-in-down',
                'url' => $baseUrl . 'production.php?page=inbound_supplies',
                'active' => ($currentPage === 'production.php' && $currentPageParam === 'inbound_supplies'),
                'badge' => null
            ],
            [
                'title' => 'منتجات الشركة',
                'icon' => 'bi-box-seam-fill',
                'url' => $baseUrl . 'production.php?page=inventory',
                'active' => ($currentPage === 'production.php' && $currentPageParam === 'inventory'),
                'badge' => null
            ],
            [
                'title' => 'مخزن توالف المصنع',
                'icon' => 'bi-exclamation-triangle',
                'url' => $baseUrl . 'production.php?page=factory_waste_warehouse',
                'active' => ($currentPage === 'production.php' && $currentPageParam === 'factory_waste_warehouse'),
                'badge' => null
            ],
            ['divider' => true, 'title' => 'محافظ و أموال'],
            [
                'title' => 'جداول التحصيل اليومية',
                'icon' => 'bi-calendar2-range',
                'url' => $baseUrl . 'production.php?page=daily_collection_my_tables',
                'active' => ($currentPage === 'production.php' && $currentPageParam === 'daily_collection_my_tables'),
                'badge' => null
            ],
            [
                'title' => isset($lang['my_salary']) ? $lang['my_salary'] : 'مرتبي',
                'icon' => 'bi-wallet2',
                'url' => $baseUrl . 'production.php?page=my_salary',
                'active' => ($currentPage === 'production.php' && $currentPageParam === 'my_salary'),
                'badge' => null
            ],
            [
                'title' => 'محفظة المستخدم',
                'icon' => 'bi-wallet',
                'url' => $baseUrl . 'production.php?page=user_wallet',
                'active' => ($currentPage === 'production.php' && $currentPageParam === 'user_wallet'),
                'badge' => null
            ],
            ['divider' => true, 'title' => 'الأدوات'],
            [
                'title' => 'قارئ أرقام التشغيلات',
                'icon' => 'bi-upc-scan',
                'url' => $baseUrl . 'production.php?page=batch_reader',
                'active' => ($currentPage === 'production.php' && $currentPageParam === 'batch_reader'),
                'badge' => null
            ],
        ];
        break;

    case 'driver':
        $menuItems = [
            [
                'title' => isset($lang['dashboard']) ? $lang['dashboard'] : 'لوحة التحكم',
                'icon' => 'bi-speedometer2',
                'url' => $baseUrl . 'driver.php',
                'active' => ($currentPage === 'driver.php' && ($currentPageParam === '' || $currentPageParam === 'dashboard')),
                'badge' => null
            ],
            [
                'title' => isset($lang['menu_attendance']) ? $lang['menu_attendance'] : 'الحضور والانصراف',
                'icon' => 'bi-calendar-check',
                'url' => getRelativeUrl('attendance.php'),
                'active' => ($currentPage === 'attendance.php'),
                'badge' => null
            ],
            [
                'title' => 'الاوردرات',
                'icon' => 'bi-list-task',
                'url' => $baseUrl . 'driver.php?page=tasks',
                'active' => ($currentPage === 'driver.php' && $currentPageParam === 'tasks'),
                'badge' => null
            ],
            [
                'title' => 'محفظة المستخدم',
                'icon' => 'bi-wallet',
                'url' => $baseUrl . 'driver.php?page=user_wallet',
                'active' => ($currentPage === 'driver.php' && $currentPageParam === 'user_wallet'),
                'badge' => null
            ],
            [
                'title' => 'صيانات السيارة',
                'icon' => 'bi-wrench-adjustable',
                'url' => $baseUrl . 'driver.php?page=vehicle_maintenance',
                'active' => ($currentPage === 'driver.php' && $currentPageParam === 'vehicle_maintenance'),
                'badge' => null
            ],
            [
                'title' => 'جداول التحصيل اليومية',
                'icon' => 'bi-calendar2-range',
                'url' => $baseUrl . 'driver.php?page=daily_collection_my_tables',
                'active' => ($currentPage === 'driver.php' && $currentPageParam === 'daily_collection_my_tables'),
                'badge' => null
            ],
        ];
        break;
}

if (empty($menuItems)) {
    // إذا كانت الصفحة الحالية هي sales.php، استخدم قائمة sales
    if ($currentPage === 'sales.php') {
        $menuItems = [
            [
                'title' => isset($lang['dashboard']) ? $lang['dashboard'] : 'لوحة التحكم',
                'icon' => 'bi-speedometer2',
                'url' => $baseUrl . 'sales.php',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === ''),
                'badge' => null
            ],
            [
                'title' => 'الشات',
                'icon' => 'bi-chat-dots',
                'url' => $baseUrl . 'sales.php?page=chat',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'chat'),
                'badge' => null
            ],
            ['divider' => true, 'title' => isset($lang['sales_section']) ? $lang['sales_section'] : 'المبيعات'],
            [
                'title' => isset($lang['customers']) ? $lang['customers'] : 'العملاء',
                'icon' => 'bi-people',
                'url' => $baseUrl . 'sales.php?page=customers',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'customers'),
                'badge' => null
            ],
            [
                'title' => isset($lang['customer_orders']) ? $lang['customer_orders'] : 'طلبات العملاء',
                'icon' => 'bi-clipboard-check',
                'url' => $baseUrl . 'sales.php?page=orders',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'orders'),
                'badge' => (isset($currentUser) && $currentUser['role'] === 'sales' && function_exists('getNewOrdersCount')) ? getNewOrdersCount($currentUser['id']) : null
            ],
            [
                'title' => 'سجلات المندوب',
                'icon' => 'bi-journal-text',
                'url' => $baseUrl . 'sales.php?page=my_records',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'my_records'),
                'badge' => null
            ],
            [
                'title' => 'جداول التحصيل اليومية',
                'icon' => 'bi-calendar2-range',
                'url' => $baseUrl . 'sales.php?page=daily_collection_my_tables',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'daily_collection_my_tables'),
                'badge' => null
            ],
            [
                'title' => isset($lang['sales_pos']) ? $lang['sales_pos'] : 'نقطة البيع',
                'icon' => 'bi-shop',
                'url' => $baseUrl . 'sales.php?page=pos',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'pos'),
                'badge' => null
            ],
            [
                'title' => 'محفظة المستخدم',
                'icon' => 'bi-wallet2',
                'url' => $baseUrl . 'sales.php?page=user_wallet',
                'active' => ($currentPage === 'sales.php' && $currentPageParam === 'user_wallet'),
                'badge' => null
            ],
        ];
    } else {
        // القائمة الافتراضية
        $menuItems = [
            [
                'title' => isset($lang['dashboard']) ? $lang['dashboard'] : 'لوحة التحكم',
                'icon' => 'bi-speedometer2',
                'url' => $baseUrl . 'accountant.php',
                'active' => true,
                'badge' => null
            ],
            [
                'title' => 'الشات',
                'icon' => 'bi-chat-dots',
                'url' => $baseUrl . 'accountant.php?page=chat',
                'active' => false,
                'badge' => null
            ]
        ];
    }
}
?>

<aside class="homeline-sidebar">
    <div class="sidebar-header">
        <a href="<?php echo getDashboardUrl($role); ?>" class="sidebar-logo">
            <i class="bi bi-building"></i>
            <span class="sidebar-logo-text"><?php echo APP_NAME; ?></span>
        </a>
        <button class="sidebar-toggle" id="sidebarToggle" type="button">
            <i class="bi bi-chevron-left"></i>
        </button>
    </div>
    
    <!-- Sidebar Search -->
    <div class="sidebar-search-wrapper px-3 mb-3">
        <div class="input-group input-group-sm">
            <span class="input-group-text bg-transparent border-end-0 text-muted">
                <i class="bi bi-search"></i>
            </span>
            <input type="text" class="form-control border-start-0 bg-transparent sidebar-search-input shadow-none" 
                   id="sidebarSearchInput" 
                   placeholder="بحث سريع..." 
                   autocomplete="off"
                   style="border-color: rgba(0,0,0,0.1);">
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('sidebarSearchInput');
            const sidebarGroups = Array.from(document.querySelectorAll('.homeline-sidebar .sidebar-menu-group'));
            const storageKey = <?php echo json_encode($sidebarPreferenceUserKey, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            let ignoreToggleSave = false;
            let savedGroupState = {};

            try {
                savedGroupState = JSON.parse(localStorage.getItem(storageKey) || '{}') || {};
            } catch (error) {
                savedGroupState = {};
            }

            function applySavedSidebarState() {
                ignoreToggleSave = true;
                sidebarGroups.forEach(function(group) {
                    const groupKey = group.getAttribute('data-group-key') || '';
                    const defaultOpen = group.getAttribute('data-default-open') === '1';
                    const shouldOpen = groupKey !== '' && Object.prototype.hasOwnProperty.call(savedGroupState, groupKey)
                        ? !!savedGroupState[groupKey]
                        : defaultOpen;

                    group.style.display = '';
                    group.querySelectorAll('.nav-item').forEach(function(item) {
                        item.style.display = '';
                    });

                    if (shouldOpen) {
                        group.setAttribute('open', 'open');
                    } else {
                        group.removeAttribute('open');
                    }
                });
                ignoreToggleSave = false;
            }

            applySavedSidebarState();

            sidebarGroups.forEach(function(group) {
                group.addEventListener('toggle', function() {
                    if (ignoreToggleSave) return;
                    if (searchInput && searchInput.value.trim() !== '') return;

                    const groupKey = group.getAttribute('data-group-key') || '';
                    if (!groupKey) return;

                    savedGroupState[groupKey] = group.open;
                    localStorage.setItem(storageKey, JSON.stringify(savedGroupState));
                });
            });

            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    const filter = this.value.toLowerCase().trim();

                    if (!filter) {
                        applySavedSidebarState();
                        return;
                    }

                    ignoreToggleSave = true;
                    sidebarGroups.forEach(function(group) {
                        const navItems = group.querySelectorAll('.nav-item');
                        let hasVisibleItem = false;

                        navItems.forEach(function(item) {
                            const link = item.querySelector('.nav-link');
                            if (!link) return;
                            const text = link.textContent.toLowerCase();
                            const matched = text.includes(filter);
                            item.style.display = matched ? '' : 'none';
                            if (matched) {
                                hasVisibleItem = true;
                            }
                        });

                        group.style.display = hasVisibleItem ? '' : 'none';
                        if (hasVisibleItem) {
                            group.setAttribute('open', 'open');
                        } else {
                            group.removeAttribute('open');
                        }
                    });
                    ignoreToggleSave = false;
                });
            }
        });
        </script>
    </div>

    <nav class="sidebar-nav">
        <ul class="nav flex-column">
            <?php
            $groupedMenuItems = [];
            $sidebarGroupIcons = [
                'الرئيسية' => 'bi-grid-1x2-fill',
                'الإدارة' => 'bi-briefcase-fill',
                'إدارة العملاء' => 'bi-people-fill',
                'إدارة الأوردرات' => 'bi-list-task',
                'إدارة المخازن' => 'bi-boxes',
                'إدارة الماليات' => 'bi-cash-coin',
                'إدارة الموظفين' => 'bi-person-badge-fill',
                'إدارة السيارات' => 'bi-truck-front-fill',
                'إدارة المنتجات' => 'bi-grid-3x3-gap-fill',
                'إدارة التقارير' => 'bi-bar-chart-line-fill',
                'إدارة النظام' => 'bi-sliders',
                'المحاسبة' => 'bi-calculator-fill',
                'المبيعات' => 'bi-graph-up-arrow',
                'الإنتاج' => 'bi-box-seam-fill',
                'التشغيل اليومي' => 'bi-gear-wide-connected',
                'المخازن' => 'bi-boxes',
                'الأدوات' => 'bi-tools',
                'محافظ و أموال' => 'bi-cash-stack',
                'عام' => 'bi-list-ul',
                'لوحات أخرى' => 'bi-columns-gap',
                'Finance' => 'bi-cash-stack',
                'Sales' => 'bi-graph-up',
                'Warehouses' => 'bi-boxes',
                'Listing' => 'bi-card-checklist',
                'Management' => 'bi-kanban-fill'
            ];
            $currentGroupIndex = -1;
            foreach ($menuItems as $item) {
                if (isset($item['divider']) && $item['divider']) {
                    $groupedMenuItems[] = [
                        'title' => trim((string) ($item['title'] ?? 'القائمة')) ?: 'القائمة',
                        'items' => []
                    ];
                    $currentGroupIndex = count($groupedMenuItems) - 1;
                    continue;
                }

                if ($currentGroupIndex < 0) {
                    $groupedMenuItems[] = [
                        'title' => 'الرئيسية',
                        'items' => []
                    ];
                    $currentGroupIndex = 0;
                }

                $groupedMenuItems[$currentGroupIndex]['items'][] = $item;
            }

            foreach ($groupedMenuItems as $groupIndex => $group):
                $groupItems = $group['items'] ?? [];
                if (empty($groupItems)) {
                    continue;
                }
                $groupHasActive = false;
                foreach ($groupItems as $groupItem) {
                    if (!empty($groupItem['active'])) {
                        $groupHasActive = true;
                        break;
                    }
                }
                $groupTitle = trim((string) ($group['title'] ?? 'القائمة')) ?: 'القائمة';
                $groupIcon = $sidebarGroupIcons[$groupTitle] ?? 'bi-folder2-open';
            ?>
                <li class="sidebar-group-wrapper">
                    <details class="sidebar-menu-group" data-group-key="<?php echo 'group-' . (int) $groupIndex; ?>" data-default-open="<?php echo $groupHasActive ? '1' : '0'; ?>">
                        <summary class="sidebar-menu-summary">
                            <span class="sidebar-menu-summary-label">
                                <i class="bi <?php echo htmlspecialchars($groupIcon); ?>"></i>
                                <span class="sidebar-menu-summary-text"><?php echo htmlspecialchars($groupTitle); ?></span>
                            </span>
                            <i class="bi bi-chevron-down"></i>
                        </summary>
                        <ul class="nav flex-column sidebar-group-links">
                            <?php foreach ($groupItems as $item): ?>
                                <?php $isDisabled = !empty($item['disabled']); ?>
                                <li class="nav-item">
                                    <a class="nav-link <?php echo !empty($item['active']) ? 'active' : ''; ?><?php echo $isDisabled ? ' is-disabled' : ''; ?>"
                                       href="<?php echo $isDisabled ? '#' : htmlspecialchars($item['url']); ?>"
                                       <?php if ($isDisabled): ?>aria-disabled="true" tabindex="-1"<?php endif; ?>
                                       <?php if (!empty($item['no_ajax']) && !$isDisabled): ?>data-ajax="false"<?php endif; ?>
                                       <?php if (!empty($item['title_attr'])): ?>title="<?php echo htmlspecialchars($item['title_attr']); ?>"<?php endif; ?>
                                       data-no-splash="true">
                                        <i class="bi <?php echo htmlspecialchars($item['icon']); ?>"></i>
                                        <span><?php echo htmlspecialchars($item['title']); ?></span>
                                        <?php if (!empty($item['badge'])): ?>
                                            <?php echo $item['badge']; ?>
                                        <?php endif; ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>

    <!-- جزء التعريف الشخصي وتسجيل الخروج - يظهر لجميع المستخدمين (كل الأدوار) دون استثناء -->
    <div class="homeline-sidebar-profile-section" role="region" aria-label="التعريف الشخصي وتسجيل الخروج">
        <?php if (isset($currentUser) && is_array($currentUser) && !empty($currentUser)): ?>
            <?php
            $profileFullName = trim($currentUser['full_name'] ?? '') ?: '—';
            $profileUsername = trim($currentUser['username'] ?? '') ?: '—';
            $profileUserId = isset($currentUser['id']) ? (int) $currentUser['id'] : 0;
            ?>
            <div class="sidebar-profile-info mb-2">
                <div class="sidebar-profile-row" title="<?php echo htmlspecialchars($profileFullName); ?>">
                    <span class="sidebar-profile-row-icon"><i class="bi bi-person-fill" aria-hidden="true"></i></span>
                    <span class="sidebar-profile-row-label">الاسم</span>
                    <span class="sidebar-profile-row-value sidebar-profile-name"><?php echo htmlspecialchars($profileFullName); ?></span>
                </div>
                <div class="sidebar-profile-row" title="<?php echo htmlspecialchars($profileUsername); ?>">
                    <span class="sidebar-profile-row-icon"><i class="bi bi-at" aria-hidden="true"></i></span>
                    <span class="sidebar-profile-row-label">اسم المستخدم</span>
                    <span class="sidebar-profile-row-value sidebar-profile-username"><?php echo htmlspecialchars($profileUsername); ?></span>
                </div>
                <div class="sidebar-profile-row" title="<?php echo $profileUserId; ?>">
                    <span class="sidebar-profile-row-icon"><i class="bi bi-hash" aria-hidden="true"></i></span>
                    <span class="sidebar-profile-row-label">المعرّف</span>
                    <span class="sidebar-profile-row-value sidebar-profile-userid"><?php echo $profileUserId; ?></span>
                </div>
            </div>
            <a href="<?php echo getRelativeUrl('logout.php'); ?>" class="btn btn-danger btn-sm w-100 d-flex align-items-center justify-content-center gap-2 homeline-sidebar-logout-btn" data-no-splash="true">
                <i class="bi bi-box-arrow-right"></i>
                <span><?php echo isset($lang['logout']) ? $lang['logout'] : 'تسجيل الخروج'; ?></span>
            </a>
        <?php else: ?>
            <a href="<?php echo getRelativeUrl('index.php'); ?>" class="btn btn-danger btn-sm w-100 d-flex align-items-center justify-content-center gap-2 homeline-sidebar-logout-btn" data-no-splash="true">
                <i class="bi bi-box-arrow-in-right"></i>
                <span><?php echo isset($lang['login']) ? $lang['login'] : 'تسجيل الدخول'; ?></span>
            </a>
        <?php endif; ?>
    </div>
</aside>

<style>
.homeline-sidebar .sidebar-group-wrapper {
    list-style: none;
}
.homeline-sidebar .sidebar-menu-group {
    margin: 0.2rem 0 0.45rem;
    border-radius: 12px;
    background: rgba(15, 23, 42, 0.03);
    overflow: hidden;
}
.homeline-sidebar .sidebar-menu-summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 0.75rem 0.95rem;
    cursor: pointer;
    font-weight: 700;
    color: #1f2937;
    user-select: none;
    list-style: none;
}
.homeline-sidebar .sidebar-menu-summary::-webkit-details-marker {
    display: none;
}
.homeline-sidebar .sidebar-menu-summary-label {
    display: inline-flex;
    align-items: center;
    gap: 0.55rem;
}
.homeline-sidebar .sidebar-menu-summary-label i {
    font-size: 0.95rem;
    color: #2563eb;
}
.homeline-sidebar .sidebar-menu-summary-text {
    font-size: 0.92rem;
}
.homeline-sidebar .sidebar-menu-summary .bi-chevron-down {
    transition: transform 0.2s ease;
    font-size: 0.8rem;
}
.homeline-sidebar .sidebar-menu-group[open] .sidebar-menu-summary .bi-chevron-down {
    transform: rotate(180deg);
}
.homeline-sidebar .sidebar-group-links {
    padding: 0 0 0.4rem;
}
.homeline-sidebar .sidebar-group-links .nav-link {
    padding-inline-start: 1.25rem;
}
.homeline-sidebar .sidebar-group-links .nav-link.is-disabled {
    opacity: 0.68;
    cursor: not-allowed;
    pointer-events: none;
}
.homeline-sidebar .sidebar-group-links .nav-link.is-disabled .badge {
    margin-inline-start: auto;
}
.homeline-sidebar .homeline-sidebar-profile-section {
    margin-top: auto;
    padding: 1rem 1rem 1.25rem;
    border-top: 1px solid rgba(0, 0, 0, 0.08);
    background: rgba(0, 0, 0, 0.02);
    flex-shrink: 0;
}
.homeline-sidebar .sidebar-profile-label {
    font-size: 0.7rem;
    letter-spacing: 0.02em;
    opacity: 0.85;
}
.homeline-sidebar .sidebar-profile-info {
    background: rgba(0, 0, 0, 0.03);
    border-radius: 0.375rem;
    padding: 0.5rem 0.65rem;
    border: 1px solid rgba(0, 0, 0, 0.06);
}
.homeline-sidebar .sidebar-profile-row {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    min-height: 1.5rem;
    margin-bottom: 0.35rem;
    line-height: 1.35;
}
.homeline-sidebar .sidebar-profile-row:last-child {
    margin-bottom: 0;
}
.homeline-sidebar .sidebar-profile-row-icon {
    flex-shrink: 0;
    width: 1.25rem;
    text-align: center;
    color: var(--bs-secondary, #6c757d);
    font-size: 0.85rem;
}
.homeline-sidebar .sidebar-profile-row-label {
    flex-shrink: 0;
    font-size: 0.7rem;
    color: var(--bs-secondary, #6c757d);
    min-width: 4.5em;
}
.homeline-sidebar .sidebar-profile-row-value {
    flex: 1;
    min-width: 0;
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--bs-body-color, #212529);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.homeline-sidebar .sidebar-profile-name {
    font-weight: 600;
}
.homeline-sidebar .sidebar-profile-username {
    font-weight: 500;
    font-size: 0.75rem;
    color: var(--bs-body-color, #495057);
}
.homeline-sidebar .homeline-sidebar-logout-btn {
    font-weight: 500;
    padding: 0.5rem 0.75rem;
}
.homeline-sidebar .homeline-sidebar-logout-btn:hover {
    background-color: var(--bs-danger);
    color: #fff;
    border-color: var(--bs-danger);
    opacity: 0.92;
}
/* ضمان أن الشريط يعرض الجزء السفلي بشكل صحيح */
.homeline-sidebar {
    display: flex;
    flex-direction: column;
}
.homeline-sidebar .sidebar-nav {
    flex: 1 1 auto;
    overflow-y: auto;
    overflow-x: hidden;
}
/* عند طي الشريط: إظهار قسم التعريف بشكل مضغوط لجميع المستخدمين */
.dashboard-wrapper.sidebar-collapsed .homeline-sidebar .homeline-sidebar-profile-section {
    padding: 0.75rem 0.5rem;
}
.dashboard-wrapper.sidebar-collapsed .homeline-sidebar .sidebar-profile-label,
.dashboard-wrapper.sidebar-collapsed .homeline-sidebar .sidebar-profile-info,
.dashboard-wrapper.sidebar-collapsed .homeline-sidebar .sidebar-profile-name,
.dashboard-wrapper.sidebar-collapsed .homeline-sidebar .sidebar-profile-username {
    opacity: 0;
    width: 0;
    height: 0;
    overflow: hidden;
    margin: 0;
    padding: 0;
    position: absolute;
    pointer-events: none;
}
.dashboard-wrapper.sidebar-collapsed .homeline-sidebar .homeline-sidebar-logout-btn span {
    opacity: 0;
    width: 0;
    overflow: hidden;
    position: absolute;
}
.dashboard-wrapper.sidebar-collapsed .homeline-sidebar .homeline-sidebar-logout-btn {
    justify-content: center;
    padding: 0.5rem;
}
</style>

<!-- PWA Performance: Prefetching للروابط في الشريط الجانبي -->
<script>
(function() {
    'use strict';
    
    // كشف نوع الاتصال
    function detectConnectionType() {
        if ('connection' in navigator) {
            const conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
            if (conn) {
                const effectiveType = conn.effectiveType || 'unknown';
                const saveData = conn.saveData || false;
                
                // لا نستخدم prefetching على اتصالات بطيئة أو saveData
                if (saveData || effectiveType === '2g' || effectiveType === 'slow-2g') {
                    return false;
                }
            }
        }
        return true; // السماح بالـ prefetching افتراضياً
    }
    
    const canPrefetch = detectConnectionType();
    
    if (!canPrefetch) {
        return; // لا نستخدم prefetching على اتصالات بطيئة
    }
    
    // Prefetching للروابط في الشريط الجانبي
    function setupPrefetching() {
        const sidebarLinks = document.querySelectorAll('.homeline-sidebar .nav-link[href]');
        const prefetchedUrls = new Set();
        
        sidebarLinks.forEach(link => {
            const href = link.getAttribute('href');
            if (!href || href === '#' || href.startsWith('javascript:') || href.startsWith('mailto:') || href.startsWith('tel:')) {
                return;
            }
            
            // Prefetch عند hover (للكمبيوتر) أو touchstart (للهاتف)
            const prefetchUrl = () => {
                if (prefetchedUrls.has(href)) {
                    return; // تم prefetch مسبقاً
                }
                
                // استخدام link prefetch
                const linkElement = document.createElement('link');
                linkElement.rel = 'prefetch';
                linkElement.href = href;
                linkElement.as = 'document';
                document.head.appendChild(linkElement);
                
                prefetchedUrls.add(href);
            };
            
            // Prefetch عند hover (للكمبيوتر)
            link.addEventListener('mouseenter', prefetchUrl, { once: true, passive: true });
            
            // Prefetch عند touchstart (للهاتف) - بعد تأخير بسيط
            link.addEventListener('touchstart', () => {
                setTimeout(prefetchUrl, 100); // تأخير 100ms لتجنب prefetching غير ضروري
            }, { once: true, passive: true });
        });
    }
    
    // تهيئة prefetching بعد تحميل DOM
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupPrefetching);
    } else {
        setupPrefetching();
    }
    
    // كشف PWA
    function isPWA() {
        return window.matchMedia('(display-mode: standalone)').matches ||
               (navigator.standalone === true) ||
               (window.matchMedia('(display-mode: fullscreen)').matches);
    }
    
    // في PWA، prefetch جميع الصفحات الشائعة فوراً بعد تحميل الصفحة
    if (isPWA()) {
        setTimeout(() => {
            const sidebarLinks = document.querySelectorAll('.homeline-sidebar .nav-link[href]');
            const prefetchedUrls = new Set();
            
            // Prefetch أول 5 صفحات شائعة فوراً في PWA
            Array.from(sidebarLinks).slice(0, 5).forEach((link, index) => {
                setTimeout(() => {
                    const href = link.getAttribute('href');
                    if (href && href !== '#' && !href.startsWith('javascript:') && !prefetchedUrls.has(href)) {
                        const linkElement = document.createElement('link');
                        linkElement.rel = 'prefetch';
                        linkElement.href = href;
                        linkElement.as = 'document';
                        document.head.appendChild(linkElement);
                        prefetchedUrls.add(href);
                    }
                }, index * 300); // تأخير 300ms بين كل صفحة
            });
        }, 1000); // انتظار ثانية واحدة بعد تحميل الصفحة
    }
    
    // Prefetch للصفحة النشطة الحالية (إذا كانت موجودة في cache)
    const currentActiveLink = document.querySelector('.homeline-sidebar .nav-link.active');
    if (currentActiveLink) {
        const activeHref = currentActiveLink.getAttribute('href');
        if (activeHref && activeHref !== '#') {
            // Prefetch الصفحات المجاورة في القائمة
            const allLinks = Array.from(document.querySelectorAll('.homeline-sidebar .nav-link[href]'));
            const currentIndex = allLinks.indexOf(currentActiveLink);
            
            // Prefetch الصفحة التالية والسابقة
            [currentIndex - 1, currentIndex + 1].forEach(index => {
                if (index >= 0 && index < allLinks.length) {
                    const link = allLinks[index];
                    const href = link.getAttribute('href');
                    if (href && href !== '#') {
                        const linkElement = document.createElement('link');
                        linkElement.rel = 'prefetch';
                        linkElement.href = href;
                        linkElement.as = 'document';
                        document.head.appendChild(linkElement);
                    }
                }
            });
        }
    }
})();
</script>

