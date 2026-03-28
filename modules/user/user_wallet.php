<?php
/**
 * صفحة محفظة المستخدم
 * للسائق وعامل الإنتاج: إضافة مبالغ، عرض الرصيد وسجل المعاملات
 */

if (!defined('ACCESS_ALLOWED')) {
    define('ACCESS_ALLOWED', true);
}

if (!defined('CONFIG_LOADED')) {
    require_once __DIR__ . '/../../includes/config.php';
}
if (!function_exists('db')) {
    require_once __DIR__ . '/../../includes/db.php';
}
if (!function_exists('isLoggedIn')) {
    require_once __DIR__ . '/../../includes/auth.php';
}
if (!function_exists('logAudit')) {
    require_once __DIR__ . '/../../includes/audit_log.php';
}
if (!function_exists('getRelativeUrl')) {
    require_once __DIR__ . '/../../includes/path_helper.php';
}

if (!function_exists('isLoggedIn') || !isLoggedIn()) {
    requireLogin();
}

$currentUser = getCurrentUser();
if (!$currentUser || !is_array($currentUser) || empty($currentUser['id'])) {
    $loginUrl = function_exists('getRelativeUrl') ? getRelativeUrl('index.php') : '/index.php';
    if (!headers_sent()) {
        header('Location: ' . $loginUrl);
        exit;
    }
}

// الصلاحية: سائق أو عامل إنتاج أو مندوب مبيعات أو مدير أو محاسب (للموافقة على الطلبات)
$role = strtolower($currentUser['role'] ?? '');
$canApproveRequests = in_array($role, ['manager', 'accountant'], true);
if (!in_array($role, ['driver', 'production', 'sales', 'manager', 'accountant'], true)) {
    echo '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>هذه الصفحة متاحة للسائق وعامل الإنتاج والمندوب فقط.</div>';
    return;
}

$db = db();

// التأكد من وجود جدول المحفظة
$tableCheck = $db->queryOne("SHOW TABLES LIKE 'user_wallet_transactions'");
if (empty($tableCheck)) {
    echo '<div class="alert alert-warning">جدول محفظة المستخدم غير متوفر. يرجى تشغيل ملف <code>database/migrations/add_user_wallet_tables.php</code></div>';
    return;
}

$collectionRequestsTableExists = $db->queryOne("SHOW TABLES LIKE 'user_wallet_local_collection_requests'");
$localCustomersTableExists = $db->queryOne("SHOW TABLES LIKE 'local_customers'");

// قائمة العملاء المحليين للبحث (نفس الاستعلام كما في الأسعار المخصصة / مهام الإنتاج)
$localCustomersForWallet = [];
if (!empty($localCustomersTableExists)) {
    try {
        $rows = $db->query("SELECT id, name, COALESCE(balance, 0) AS balance FROM local_customers WHERE status = 'active' ORDER BY name ASC");
        foreach ($rows as $r) {
            $localCustomersForWallet[] = [
                'id' => (int)$r['id'],
                'name' => trim((string)($r['name'] ?? '')),
                'balance' => (float)($r['balance'] ?? 0),
            ];
        }
    } catch (Throwable $e) {
        error_log('user_wallet local_customers: ' . $e->getMessage());
    }
}

$error = '';
$success = '';

// عند فتح المدير/المحاسب للمحفظة مع user_id نعرض محفظة ذلك المستخدم
$walletUserId = (int)$currentUser['id'];
if ($canApproveRequests && isset($_GET['user_id']) && (int)$_GET['user_id'] > 0) {
    $targetId = (int)$_GET['user_id'];
    $targetUser = $db->queryOne("SELECT id FROM users WHERE id = ? AND status = 'active'", [$targetId]);
    if (!empty($targetUser)) {
        $walletUserId = $targetId;
    }
}

$isWalletAjax = (
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest' &&
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action'])
);

if ($isWalletAjax) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    $action = $_POST['action'] ?? '';
    $ajaxError = '';
    $ajaxSuccess = '';
    $ajaxWalletUserId = (int)$currentUser['id'];
    if ($action === 'add_deposit') {
        $amount = isset($_POST['amount']) ? (float) str_replace(',', '', $_POST['amount']) : 0;
        $reason = trim($_POST['reason'] ?? '');
        if ($amount <= 0) {
            $ajaxError = 'يرجى إدخال مبلغ صحيح أكبر من الصفر.';
        } elseif (empty($reason)) {
            $ajaxError = 'يرجى ذكر سبب الإضافة إلى المحفظة.';
        } else {
            try {
                $db->execute(
                    "INSERT INTO user_wallet_transactions (user_id, type, amount, reason, created_by) VALUES (?, 'deposit', ?, ?, ?)",
                    [$currentUser['id'], $amount, $reason, $currentUser['id']]
                );
                logAudit($currentUser['id'], 'wallet_deposit', 'user_wallet_transactions', $db->getLastInsertId(), null, ['amount' => $amount, 'reason' => $reason]);
                $ajaxSuccess = 'تم إضافة ' . formatCurrency($amount) . ' إلى محفظتك بنجاح.';
            } catch (Throwable $e) {
                error_log('Wallet deposit failed: ' . $e->getMessage());
                $ajaxError = 'حدث خطأ أثناء الإضافة. يرجى المحاولة مرة أخرى.';
            }
        }
    } elseif ($action === 'add_order_collection') {
        $amount = isset($_POST['order_amount']) ? (float) str_replace(',', '', $_POST['order_amount']) : 0;
        $orderNumber = trim($_POST['order_number'] ?? '');
        if ($amount <= 0) {
            $ajaxError = 'يرجى إدخال مبلغ التحصيل صحيح أكبر من الصفر.';
        } elseif (empty($orderNumber)) {
            $ajaxError = 'يرجى إدخال رقم الأوردر.';
        } else {
            $reason = 'تحصيل من أوردر #' . $orderNumber;
            try {
                $db->execute(
                    "INSERT INTO user_wallet_transactions (user_id, type, amount, reason, created_by) VALUES (?, 'deposit', ?, ?, ?)",
                    [$currentUser['id'], $amount, $reason, $currentUser['id']]
                );
                logAudit($currentUser['id'], 'wallet_order_collection', 'user_wallet_transactions', $db->getLastInsertId(), null, ['amount' => $amount, 'order_number' => $orderNumber]);
                $ajaxSuccess = 'تم إضافة تحصيل ' . formatCurrency($amount) . ' من أوردر #' . htmlspecialchars($orderNumber) . ' إلى محفظتك بنجاح.';
            } catch (Throwable $e) {
                error_log('Wallet order collection failed: ' . $e->getMessage());
                $ajaxError = 'حدث خطأ أثناء الإضافة. يرجى المحاولة مرة أخرى.';
            }
        }
    } elseif ($action === 'submit_local_collection' && !empty($collectionRequestsTableExists)) {
        $customerId = isset($_POST['local_customer_id']) ? (int)$_POST['local_customer_id'] : 0;
        $customerName = trim($_POST['local_customer_name'] ?? '');
        $amount = isset($_POST['collection_amount']) ? (float) str_replace(',', '', $_POST['collection_amount']) : 0;
        if ($customerId <= 0 || $customerName === '') {
            $ajaxError = 'يرجى اختيار العميل من نتائج البحث.';
        } elseif ($amount <= 0) {
            $ajaxError = 'يرجى إدخال مبلغ التحصيل صحيح أكبر من الصفر.';
        } else {
            try {
                $db->execute(
                    "INSERT INTO user_wallet_local_collection_requests (user_id, local_customer_id, customer_name, amount, status) VALUES (?, ?, ?, ?, 'pending')",
                    [$currentUser['id'], $customerId, $customerName, $amount]
                );
                $requestId = $db->getLastInsertId();
                if (function_exists('logAudit')) {
                    logAudit($currentUser['id'], 'wallet_local_collection_request', 'user_wallet_local_collection_requests', $requestId, null, ['local_customer_id' => $customerId, 'amount' => $amount]);
                }
                $ajaxSuccess = 'تم تسجيل طلب التحصيل (' . formatCurrency($amount) . ' من ' . htmlspecialchars($customerName) . ') في انتظار موافقة المحاسب أو المدير.';
            } catch (Throwable $e) {
                error_log('Wallet local collection request failed: ' . $e->getMessage());
                $ajaxError = 'حدث خطأ أثناء تسجيل الطلب. يرجى المحاولة مرة أخرى.';
            }
        }
    } elseif ($action === 'approve_all_pending_collection_requests' && !empty($collectionRequestsTableExists)) {
        $roleForAjax = strtolower($currentUser['role'] ?? '');
        if (!in_array($roleForAjax, ['manager', 'accountant'], true)) {
            $ajaxError = 'الموافقة على الطلبات متاحة للمدير أو المحاسب فقط.';
        } else {
            $targetUserId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : (int)$currentUser['id'];
            $ajaxWalletUserId = $targetUserId;
            $pendingList = $db->query(
                "SELECT * FROM user_wallet_local_collection_requests WHERE user_id = ? AND status = 'pending' ORDER BY id ASC",
                [$targetUserId]
            ) ?: [];
            $walletOwnerInfo = $db->queryOne("SELECT full_name FROM users WHERE id = ?", [$targetUserId]);
            $walletOwnerName = $walletOwnerInfo['full_name'] ?? 'مستخدم';
            $approvedCount = 0;
            $failMsg = '';
            foreach ($pendingList as $req) {
                $requestId = (int)$req['id'];
                $customerId = (int)$req['local_customer_id'];
                $amount = (float)$req['amount'];
                $userId = (int)$req['user_id'];
                $customerName = $req['customer_name'] ?? '';
                try {
                    $db->beginTransaction();
                    $customer = $db->queryOne("SELECT id, name, balance FROM local_customers WHERE id = ? FOR UPDATE", [$customerId]);
                    if (!$customer) {
                        throw new InvalidArgumentException('العميل غير موجود.');
                    }
                    $currentBalance = (float)($customer['balance'] ?? 0);
                    $newBalance = round($currentBalance - $amount, 2);
                    $db->execute("UPDATE local_customers SET balance = ? WHERE id = ?", [$newBalance, $customerId]);
                    if (function_exists('logAudit')) {
                        logAudit($currentUser['id'], 'approve_wallet_local_collection', 'local_customer', $customerId, null, ['request_id' => $requestId, 'amount' => $amount, 'previous_balance' => $currentBalance, 'new_balance' => $newBalance]);
                    }
                    $localCollectionsExists = $db->queryOne("SHOW TABLES LIKE 'local_collections'");
                    if (!empty($localCollectionsExists)) {
                        $cols = $db->queryOne("SHOW COLUMNS FROM local_collections LIKE 'status'");
                        $collColumns = ['customer_id', 'amount', 'date', 'payment_method', 'collected_by'];
                        $collValues = [$customerId, $amount, date('Y-m-d'), 'cash', $currentUser['id']];
                        if (!empty($cols)) {
                            $collColumns[] = 'status';
                            $collValues[] = 'approved';
                        }
                        $ph = implode(',', array_fill(0, count($collColumns), '?'));
                        $db->execute("INSERT INTO local_collections (" . implode(',', $collColumns) . ") VALUES ($ph)", $collValues);
                    }
                    $accountantTableExists = $db->queryOne("SHOW TABLES LIKE 'accountant_transactions'");
                    if (!empty($accountantTableExists)) {
                        $desc = 'تحصيل من عميل محلي (محفظة ' . $walletOwnerName . '): ' . $customerName;
                        $ref = $requestId . '-' . date('Ymd');
                        $db->execute(
                            "INSERT INTO accountant_transactions (transaction_type, amount, description, reference_number, payment_method, status, created_by, approved_by, approved_at) VALUES ('income', ?, ?, ?, 'cash', 'approved', ?, ?, NOW())",
                            [$amount, $desc, $ref, $currentUser['id'], $currentUser['id']]
                        );
                    }
                    $db->execute(
                        "INSERT INTO user_wallet_transactions (user_id, type, amount, reason, created_by) VALUES (?, 'deposit', ?, ?, ?)",
                        [$userId, $amount, 'تحصيل من عميل محلي: ' . $customerName . ' (تمت الموافقة)', $currentUser['id']]
                    );
                    $db->execute(
                        "UPDATE user_wallet_local_collection_requests SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?",
                        [$currentUser['id'], $requestId]
                    );
                    $db->commit();
                    $approvedCount++;
                } catch (Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    error_log('approve_all_pending_collection_requests item ' . $requestId . ': ' . $e->getMessage());
                    $failMsg = $e->getMessage();
                    break;
                }
            }
            if ($failMsg !== '') {
                $ajaxError = 'حدث خطأ أثناء الموافقة. تمت الموافقة على ' . $approvedCount . ' طلب/طلبات. يرجى المحاولة مرة أخرى.';
            } elseif ($approvedCount > 0) {
                $ajaxSuccess = 'تمت الموافقة على جميع الطلبات (' . $approvedCount . ' طلب) بنجاح.';
            } else {
                $ajaxSuccess = 'لا توجد طلبات قيد الانتظار للموافقة.';
            }
        }
    } else {
        $ajaxError = 'إجراء غير صحيح.';
    }
    $balance = getWalletBalance($db, $ajaxWalletUserId);
    $transactions = $db->query(
        "SELECT t.*, u.full_name as created_by_name FROM user_wallet_transactions t LEFT JOIN users u ON u.id = t.created_by WHERE t.user_id = ? ORDER BY t.created_at DESC LIMIT 100",
        [$ajaxWalletUserId]
    ) ?: [];
    $pendingLocalCollectionRequests = [];
    if (!empty($collectionRequestsTableExists)) {
        $pendingLocalCollectionRequests = $db->query(
            "SELECT * FROM user_wallet_local_collection_requests WHERE user_id = ? AND status = 'pending' ORDER BY created_at DESC LIMIT 20",
            [$ajaxWalletUserId]
        ) ?: [];
    }
    $typeLabels = ['deposit' => 'إيداع', 'withdrawal' => 'سحب', 'custody_add' => 'عهدة', 'custody_retrieve' => 'استرجاع عهدة'];
    $out = [
        'success' => ($ajaxError === ''),
        'message' => $ajaxError ?: $ajaxSuccess,
        'balance' => $balance,
        'balance_formatted' => formatCurrency($balance),
        'transactions' => [],
        'pending_requests' => []
    ];
    foreach ($transactions as $t) {
        $type = $t['type'] ?? '';
        $isCredit = in_array($type, ['deposit', 'custody_add']);
        $out['transactions'][] = [
            'created_at' => date('Y-m-d H:i', strtotime($t['created_at'])),
            'type' => $type,
            'type_label' => $typeLabels[$type] ?? $type,
            'amount' => (float)$t['amount'],
            'amount_formatted' => ($isCredit ? '+' : '-') . formatCurrency($t['amount']),
            'reason' => $t['reason'] ?? '-',
            'is_credit' => $isCredit
        ];
    }
    foreach ($pendingLocalCollectionRequests as $req) {
        $out['pending_requests'][] = [
            'id' => (int)$req['id'],
            'created_at' => date('Y-m-d H:i', strtotime($req['created_at'])),
            'customer_name' => $req['customer_name'] ?? '',
            'amount' => (float)$req['amount'],
            'amount_formatted' => formatCurrency($req['amount'])
        ];
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * حساب رصيد المحفظة للمستخدم
 */
function getWalletBalance($db, $userId) {
    $credits = $db->queryOne(
        "SELECT COALESCE(SUM(amount), 0) as total FROM user_wallet_transactions WHERE user_id = ? AND type IN ('deposit', 'custody_add')",
        [$userId]
    );
    $debits = $db->queryOne(
        "SELECT COALESCE(SUM(amount), 0) as total FROM user_wallet_transactions WHERE user_id = ? AND type IN ('withdrawal', 'custody_retrieve')",
        [$userId]
    );
    return (float)($credits['total'] ?? 0) - (float)($debits['total'] ?? 0);
}

// معالجة إضافة مبلغ عام
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_deposit') {
    $amount = isset($_POST['amount']) ? (float) str_replace(',', '', $_POST['amount']) : 0;
    $reason = trim($_POST['reason'] ?? '');

    if ($amount <= 0) {
        $error = 'يرجى إدخال مبلغ صحيح أكبر من الصفر.';
    } elseif (empty($reason)) {
        $error = 'يرجى ذكر سبب الإضافة إلى المحفظة.';
    } else {
        try {
            $db->execute(
                "INSERT INTO user_wallet_transactions (user_id, type, amount, reason, created_by) VALUES (?, 'deposit', ?, ?, ?)",
                [$currentUser['id'], $amount, $reason, $currentUser['id']]
            );
            logAudit($currentUser['id'], 'wallet_deposit', 'user_wallet_transactions', $db->getLastInsertId(), null, ['amount' => $amount, 'reason' => $reason]);
            $success = 'تم إضافة ' . formatCurrency($amount) . ' إلى محفظتك بنجاح.';
        } catch (Throwable $e) {
            error_log('Wallet deposit failed: ' . $e->getMessage());
            $error = 'حدث خطأ أثناء الإضافة. يرجى المحاولة مرة أخرى.';
        }
    }
}

// معالجة تحصيل من أوردر
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_order_collection') {
    $amount = isset($_POST['order_amount']) ? (float) str_replace(',', '', $_POST['order_amount']) : 0;
    $orderNumber = trim($_POST['order_number'] ?? '');

    if ($amount <= 0) {
        $error = 'يرجى إدخال مبلغ التحصيل صحيح أكبر من الصفر.';
    } elseif (empty($orderNumber)) {
        $error = 'يرجى إدخال رقم الأوردر.';
    } else {
        $reason = 'تحصيل من أوردر #' . $orderNumber;
        try {
            $db->execute(
                "INSERT INTO user_wallet_transactions (user_id, type, amount, reason, created_by) VALUES (?, 'deposit', ?, ?, ?)",
                [$currentUser['id'], $amount, $reason, $currentUser['id']]
            );
            logAudit($currentUser['id'], 'wallet_order_collection', 'user_wallet_transactions', $db->getLastInsertId(), null, ['amount' => $amount, 'order_number' => $orderNumber]);
            $success = 'تم إضافة تحصيل ' . formatCurrency($amount) . ' من أوردر #' . htmlspecialchars($orderNumber) . ' إلى محفظتك بنجاح.';
        } catch (Throwable $e) {
            error_log('Wallet order collection failed: ' . $e->getMessage());
            $error = 'حدث خطأ أثناء الإضافة. يرجى المحاولة مرة أخرى.';
        }
    }
}

// معالجة طلب تحصيل من عميل محلي (في انتظار موافقة المحاسب/المدير)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_local_collection' && !empty($collectionRequestsTableExists)) {
    $customerId = isset($_POST['local_customer_id']) ? (int)$_POST['local_customer_id'] : 0;
    $customerName = trim($_POST['local_customer_name'] ?? '');
    $amount = isset($_POST['collection_amount']) ? (float) str_replace(',', '', $_POST['collection_amount']) : 0;

    if ($customerId <= 0 || $customerName === '') {
        $error = 'يرجى اختيار العميل من نتائج البحث.';
    } elseif ($amount <= 0) {
        $error = 'يرجى إدخال مبلغ التحصيل صحيح أكبر من الصفر.';
    } else {
        try {
            $db->execute(
                "INSERT INTO user_wallet_local_collection_requests (user_id, local_customer_id, customer_name, amount, status) VALUES (?, ?, ?, ?, 'pending')",
                [$currentUser['id'], $customerId, $customerName, $amount]
            );
            $requestId = $db->getLastInsertId();
            if (function_exists('logAudit')) {
                logAudit($currentUser['id'], 'wallet_local_collection_request', 'user_wallet_local_collection_requests', $requestId, null, ['local_customer_id' => $customerId, 'amount' => $amount]);
            }
            $success = 'تم تسجيل طلب التحصيل (' . formatCurrency($amount) . ' من ' . htmlspecialchars($customerName) . ') في انتظار موافقة المحاسب أو المدير.';
        } catch (Throwable $e) {
            error_log('Wallet local collection request failed: ' . $e->getMessage());
            $error = 'حدث خطأ أثناء تسجيل الطلب. يرجى المحاولة مرة أخرى.';
        }
    }
}

$balance = getWalletBalance($db, $walletUserId);

// طلبات التحصيل من العملاء المحليين (في انتظار الموافقة) للمستخدم الحالي أو المعروض
$pendingLocalCollectionRequests = [];
if (!empty($collectionRequestsTableExists)) {
    $pendingLocalCollectionRequests = $db->query(
        "SELECT * FROM user_wallet_local_collection_requests WHERE user_id = ? AND status = 'pending' ORDER BY created_at DESC LIMIT 20",
        [$walletUserId]
    ) ?: [];
}

// جلب سجل المعاملات
$transactions = $db->query(
    "SELECT t.*, u.full_name as created_by_name
     FROM user_wallet_transactions t
     LEFT JOIN users u ON u.id = t.created_by
     WHERE t.user_id = ?
     ORDER BY t.created_at DESC
     LIMIT 100",
    [$walletUserId]
) ?: [];

$typeLabels = [
    'deposit' => 'إيداع',
    'withdrawal' => 'سحب',
    'custody_add' => 'عهدة',
    'custody_retrieve' => 'استرجاع عهدة'
];
?>
<div class="container-fluid">
    <div class="page-header mb-4">
        <h2><i class="bi bi-wallet2 me-2"></i>محفظة المستخدم</h2>
    </div>

    <div id="wallet-alert-container"></div>
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-12 col-lg-4 mb-4">
            <div class="card shadow-sm h-100 border-primary">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-cash-stack me-2"></i>رصيد المحفظة</h5>
                </div>
                <div class="card-body text-center py-4">
                    <p class="display-5 fw-bold text-primary mb-0" id="wallet-balance-display"><?php echo formatCurrency($balance); ?></p>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-8 mb-4">
            <!-- بطاقة إضافة مبلغ عام -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-light fw-bold">
                    <i class="bi bi-plus-circle me-2"></i>إضافة مبلغ للمحفظة
                </div>
                <div class="card-body">
                    <form method="POST" class="row g-3" id="wallet-form-deposit" data-wallet-ajax>
                        <input type="hidden" name="action" value="add_deposit">
                        <div class="col-12 col-md-4">
                            <label for="walletAmount" class="form-label">المبلغ <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">ج.م</span>
                                <input type="number" step="0.01" min="0.01" class="form-control" id="walletAmount" name="amount" required placeholder="0.00">
                            </div>
                        </div>
                        <div class="col-12 col-md-6">
                            <label for="walletReason" class="form-label">سبب الإضافة <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="walletReason" name="reason" required placeholder="مثال: مبلغ تسليم - توصيل طلب #123">
                        </div>
                        <div class="col-12 col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-plus-lg me-1"></i>إضافة
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <!-- بطاقة تحصيل من أوردر -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-light fw-bold">
                    <i class="bi bi-cart-check me-2"></i>تحصيل من أوردر
                </div>
                <div class="card-body">
                    <form method="POST" class="row g-3" id="wallet-form-order" data-wallet-ajax>
                        <input type="hidden" name="action" value="add_order_collection">
                        <div class="col-12 col-md-4">
                            <label for="orderAmount" class="form-label">مبلغ التحصيل <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">ج.م</span>
                                <input type="number" step="0.01" min="0.01" class="form-control" id="orderAmount" name="order_amount" required placeholder="0.00">
                            </div>
                        </div>
                        <div class="col-12 col-md-4">
                            <label for="orderNumber" class="form-label">رقم الأوردر <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="orderNumber" name="order_number" required placeholder="أدخل رقم الأوردر يدوياً">
                        </div>
                        <div class="col-12 col-md-4 d-flex align-items-end">
                            <button type="submit" class="btn btn-success w-100">
                                <i class="bi bi-cash-coin me-1"></i>إضافة التحصيل
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <?php if (!empty($collectionRequestsTableExists) && !empty($localCustomersTableExists)): ?>
            <!-- بطاقة تحصيل من عميل محلي (نفس خانة البحث كما في الأسعار المخصصة / مهام الإنتاج) -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-light fw-bold">
                    <i class="bi bi-person-lines-fill me-2"></i>تحصيل من عميل محلي
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">اختر العميل من نتائج البحث، ثم أدخل مبلغ التحصيل. يُسجّل الطلب في انتظار موافقة المحاسب أو المدير.</p>
                    <form method="POST" id="localCollectionForm" data-wallet-ajax>
                        <input type="hidden" name="action" value="submit_local_collection">
                        <input type="hidden" name="local_customer_id" id="wallet_local_customer_id" value="">
                        <input type="hidden" name="local_customer_name" id="wallet_local_customer_name" value="">
                        <div class="row g-3">
                            <div class="col-12 col-md-4">
                                <label class="form-label small">ابحث عن العميل المحلي <span class="text-danger">*</span></label>
                                <div class="search-wrap position-relative">
                                    <input type="text" id="wallet_local_customer_search" class="form-control form-control-sm" placeholder="اكتب للبحث..." autocomplete="off">
                                    <div id="wallet_local_customer_dropdown" class="search-dropdown-wallet d-none"></div>
                                </div>
                            </div>
                            <div class="col-12 col-md-2">
                                <label class="form-label small">رصيد العميل</label>
                                <div class="form-control form-control-sm bg-light fw-bold small py-2" id="wallet_customer_balance_display">-</div>
                            </div>
                            <div class="col-12 col-md-4">
                                <label for="wallet_collection_amount" class="form-label">مبلغ التحصيل <span class="text-danger">*</span></label>
                                <div class="input-group input-group-lg">
                                    <span class="input-group-text">ج.م</span>
                                    <input type="number" step="0.01" min="0.01" class="form-control form-control-lg" id="wallet_collection_amount" name="collection_amount" required placeholder="0.00">
                                </div>
                            </div>
                            <div class="col-12 col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-send-check me-1"></i>تسجيل الطلب
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            <?php if (!empty($pendingLocalCollectionRequests)): ?>
            <div class="card shadow-sm mb-4 border-warning" id="wallet-pending-card">
                <div class="card-header bg-warning bg-opacity-25 fw-bold d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <span><i class="bi bi-hourglass-split me-2"></i>طلبات التحصيل في انتظار الموافقة</span>
                    <?php if ($canApproveRequests && count($pendingLocalCollectionRequests) > 0): ?>
                    <button type="button" class="btn btn-success btn-sm" id="wallet-approve-all-pending-btn" data-wallet-user-id="<?php echo (int)$walletUserId; ?>">
                        <i class="bi bi-check-all me-1"></i>الموافقة على كل الطلبات
                    </button>
                    <?php endif; ?>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>التاريخ</th>
                                    <th>العميل</th>
                                    <th>المبلغ</th>
                                    <th>الحالة</th>
                                </tr>
                            </thead>
                            <tbody id="wallet-pending-tbody">
                                <?php foreach ($pendingLocalCollectionRequests as $req): ?>
                                <tr>
                                    <td><?php echo date('Y-m-d H:i', strtotime($req['created_at'])); ?></td>
                                    <td><?php echo htmlspecialchars($req['customer_name']); ?></td>
                                    <td class="fw-bold"><?php echo formatCurrency($req['amount']); ?></td>
                                    <td><span class="badge bg-warning text-dark">في انتظار الموافقة</span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-light fw-bold">
            <i class="bi bi-journal-text me-2"></i>سجل المعاملات
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>التاريخ</th>
                            <th>النوع</th>
                            <th>المبلغ</th>
                            <th>السبب / الوصف</th>
                        </tr>
                    </thead>
                    <tbody id="wallet-transactions-tbody">
                        <?php if (empty($transactions)): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">لا توجد معاملات بعد</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($transactions as $t): ?>
                                <tr>
                                    <td><?php echo date('Y-m-d H:i', strtotime($t['created_at'])); ?></td>
                                    <td><span class="badge bg-<?php echo in_array($t['type'], ['deposit', 'custody_add']) ? 'success' : 'danger'; ?>"><?php echo htmlspecialchars($typeLabels[$t['type']] ?? $t['type']); ?></span></td>
                                    <td class="fw-bold <?php echo in_array($t['type'], ['deposit', 'custody_add']) ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo in_array($t['type'], ['deposit', 'custody_add']) ? '+' : '-'; ?><?php echo formatCurrency($t['amount']); ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($t['reason'] ?? '-'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php if (!empty($collectionRequestsTableExists) && !empty($localCustomersTableExists) && !empty($localCustomersForWallet)): ?>
<style>
.search-wrap.position-relative { position: relative; }
.search-dropdown-wallet { position: absolute; left: 0; right: 0; top: 100%; z-index: 1050; max-height: 220px; overflow-y: auto; background: #fff; border: 1px solid #dee2e6; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); margin-top: 2px; }
.search-dropdown-wallet .search-dropdown-item-wallet { padding: 0.5rem 0.75rem; cursor: pointer; border-bottom: 1px solid #f0f0f0; }
.search-dropdown-wallet .search-dropdown-item-wallet:hover { background: #f8f9fa; }
.search-dropdown-wallet .search-dropdown-item-wallet:last-child { border-bottom: none; }
</style>
<script>
(function() {
    var localCustomers = <?php echo json_encode($localCustomersForWallet); ?>;
    var searchInput = document.getElementById('wallet_local_customer_search');
    var dropdown = document.getElementById('wallet_local_customer_dropdown');
    var hiddenId = document.getElementById('wallet_local_customer_id');
    var hiddenName = document.getElementById('wallet_local_customer_name');
    var balanceDisplay = document.getElementById('wallet_customer_balance_display');
    if (!searchInput || !dropdown) return;
    function matchSearch(text, q) {
        if (!q || !text) return true;
        var t = (text + '').toLowerCase();
        var k = (q + '').trim().toLowerCase();
        return t.indexOf(k) !== -1;
    }
    function showDropdown() {
        var q = (searchInput.value || '').trim();
        var filtered = q ? localCustomers.filter(function(c) { return matchSearch(c.name, q); }) : localCustomers.slice(0, 50);
        dropdown.innerHTML = '';
        if (filtered.length === 0) {
            dropdown.classList.add('d-none');
            return;
        }
        filtered.forEach(function(c) {
            var div = document.createElement('div');
            div.className = 'search-dropdown-item-wallet';
            div.textContent = c.name + (c.balance > 0 ? ' — رصيد: ' + parseFloat(c.balance).toFixed(2) + ' ج.م' : '');
            div.dataset.id = c.id;
            div.dataset.name = c.name;
            div.dataset.balance = c.balance;
            div.addEventListener('click', function() {
                hiddenId.value = this.dataset.id;
                hiddenName.value = this.dataset.name;
                searchInput.value = this.dataset.name;
                var bal = parseFloat(this.dataset.balance || 0);
                balanceDisplay.textContent = bal > 0 ? bal.toFixed(2) + ' ج.م (رصيد مدين)' : (bal < 0 ? Math.abs(bal).toFixed(2) + ' ج.م (رصيد دائن)' : '0.00 ج.م');
                dropdown.classList.add('d-none');
            });
            dropdown.appendChild(div);
        });
        dropdown.classList.remove('d-none');
    }
    searchInput.addEventListener('input', function() {
        hiddenId.value = '';
        hiddenName.value = '';
        balanceDisplay.textContent = '-';
        showDropdown();
    });
    searchInput.addEventListener('focus', function() { showDropdown(); });
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.search-wrap')) dropdown.classList.add('d-none');
    });
})();
</script>
<?php endif; ?>

<script>
(function() {
    var alertContainer = document.getElementById('wallet-alert-container');
    var balanceEl = document.getElementById('wallet-balance-display');
    var transactionsTbody = document.getElementById('wallet-transactions-tbody');
    var pendingTbody = document.getElementById('wallet-pending-tbody');
    function showAlert(msg, isSuccess) {
        if (!alertContainer) return;
        alertContainer.innerHTML = '<div class="alert alert-' + (isSuccess ? 'success' : 'danger') + ' alert-dismissible fade show"><i class="bi bi-' + (isSuccess ? 'check-circle' : 'exclamation-circle') + ' me-2"></i>' + (msg || '') + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        if (typeof window.bootstrap !== 'undefined' && alertContainer.querySelector('.alert')) {
            setTimeout(function() {
                var al = alertContainer.querySelector('.alert');
                if (al && al.offsetParent) new bootstrap.Alert(al);
            }, 10);
        }
    }
    function applyResponse(data) {
        if (data.balance_formatted && balanceEl) balanceEl.textContent = data.balance_formatted;
        if (data.transactions && transactionsTbody) {
            if (data.transactions.length === 0) {
                transactionsTbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-4">لا توجد معاملات بعد</td></tr>';
            } else {
                var html = '';
                data.transactions.forEach(function(t) {
                    var badgeClass = t.is_credit ? 'success' : 'danger';
                    var textClass = t.is_credit ? 'text-success' : 'text-danger';
                    html += '<tr><td>' + (t.created_at || '') + '</td><td><span class="badge bg-' + badgeClass + '">' + (t.type_label || '') + '</span></td><td class="fw-bold ' + textClass + '">' + (t.amount_formatted || '') + '</td><td>' + (t.reason || '-') + '</td></tr>';
                });
                transactionsTbody.innerHTML = html;
            }
        }
        if (pendingTbody && data.pending_requests) {
            if (data.pending_requests.length === 0) {
                pendingTbody.innerHTML = '';
                var pendingCard = document.getElementById('wallet-pending-card');
                if (pendingCard) pendingCard.classList.add('d-none');
            } else {
                var ph = '';
                data.pending_requests.forEach(function(r) {
                    ph += '<tr><td>' + (r.created_at || '') + '</td><td>' + (r.customer_name || '') + '</td><td class="fw-bold">' + (r.amount_formatted || '') + '</td><td><span class="badge bg-warning text-dark">في انتظار الموافقة</span></td></tr>';
                });
                pendingTbody.innerHTML = ph;
            }
        }
    }
    document.querySelectorAll('form[data-wallet-ajax]').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var btn = form.querySelector('button[type="submit"]');
            var origHtml = btn ? btn.innerHTML : '';
            if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span>جاري الحفظ...'; }
            var fd = new FormData(form);
            fetch(window.location.href, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd, credentials: 'same-origin' })
                .then(function(r) { return r.json().then(function(j) { return { ok: r.ok, json: j }; }); })
                .then(function(res) {
                    var d = res.json;
                    showAlert(d.message || (d.success ? 'تمت العملية بنجاح.' : 'حدث خطأ.'), d.success);
                    if (d.success) {
                        applyResponse(d);
                        form.reset();
                        function hideAfterPaint() {
                            requestAnimationFrame(function() {
                                requestAnimationFrame(function() {
                                    if (typeof window.hidePageLoading === 'function') window.hidePageLoading();
                                });
                            });
                        }
                        hideAfterPaint();
                    } else {
                        if (typeof window.hidePageLoading === 'function') window.hidePageLoading();
                    }
                })
                .catch(function(err) {
                    showAlert('حدث خطأ في الاتصال. يرجى المحاولة مرة أخرى.', false);
                    if (typeof window.hidePageLoading === 'function') window.hidePageLoading();
                })
                .finally(function() {
                    if (btn) { btn.disabled = false; btn.innerHTML = origHtml; }
                });
        });
    });
    var approveAllBtn = document.getElementById('wallet-approve-all-pending-btn');
    if (approveAllBtn) {
        approveAllBtn.addEventListener('click', function() {
            if (!confirm('الموافقة على جميع طلبات التحصيل المعلقة؟ سيتم خصم المبالغ من رصيد العملاء وإضافتها لخزنة الشركة ومحفظة المستخدم.')) return;
            var userId = approveAllBtn.getAttribute('data-wallet-user-id') || '';
            var fd = new FormData();
            fd.append('action', 'approve_all_pending_collection_requests');
            fd.append('user_id', userId);
            var origHtml = approveAllBtn.innerHTML;
            approveAllBtn.disabled = true;
            approveAllBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span>جاري الموافقة...';
            fetch(window.location.href, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd, credentials: 'same-origin' })
                .then(function(r) { return r.json().then(function(j) { return { ok: r.ok, json: j }; }); })
                .then(function(res) {
                    var d = res.json;
                    showAlert(d.message || (d.success ? 'تمت العملية بنجاح.' : 'حدث خطأ.'), d.success);
                    if (d.success) {
                        applyResponse(d);
                        if (typeof window.hidePageLoading === 'function') window.hidePageLoading();
                    }
                })
                .catch(function(err) {
                    showAlert('حدث خطأ في الاتصال. يرجى المحاولة مرة أخرى.', false);
                })
                .finally(function() {
                    approveAllBtn.disabled = false;
                    approveAllBtn.innerHTML = origHtml;
                });
        });
    }
})();
</script>
