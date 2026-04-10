<?php
/**
 * صفحة التحكم في محافظ المستخدمين
 * للمحاسب والمدير: عرض أرصدة المحافظ وسحب الأموال (لا تُضاف للخزنة)
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/path_helper.php';

requireRole(['manager', 'accountant', 'developer']);

$currentUser = getCurrentUser();
$db = db();

// منع الكاش عند التبديل بين حسابات "المستخدمون ذوو المحافظ" لضمان عرض بيانات محدثة
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Expires: 0');
}

// التأكد من وجود جدول المحفظة
$tableCheck = $db->queryOne("SHOW TABLES LIKE 'user_wallet_transactions'");
if (empty($tableCheck)) {
    echo '<div class="alert alert-warning">جدول محفظة المستخدم غير متوفر. يرجى تشغيل ملف <code>database/migrations/add_user_wallet_tables.php</code></div>';
    return;
}

/**
 * حساب رصيد المحفظة للمستخدم
 */
function getWalletBalanceForControl($db, $userId) {
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

$error = '';
$success = '';
$selectedUserId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;

if (isset($_SESSION['user_wallets_success'])) {
    $success = $_SESSION['user_wallets_success'];
    unset($_SESSION['user_wallets_success']);
}

$isWalletControlAjax = (
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest' &&
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action'])
);

if ($isWalletControlAjax) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    $collectionRequestsTableExistsForAjax = $db->queryOne("SHOW TABLES LIKE 'user_wallet_local_collection_requests'");
    $ajaxError = '';
    $ajaxSuccess = '';
    $action = $_POST['action'] ?? '';

    if ($action === 'withdraw') {
        $targetUserId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
        $amount = isset($_POST['amount']) ? (float) str_replace(',', '', $_POST['amount']) : 0;
        $notes = trim($_POST['notes'] ?? '');
        if ($targetUserId <= 0) {
            $ajaxError = 'يرجى اختيار المستخدم.';
        } elseif ($amount <= 0) {
            $ajaxError = 'يرجى إدخال مبلغ صحيح أكبر من الصفر.';
        } else {
            $targetUser = $db->queryOne("SELECT id, full_name, username, role FROM users WHERE id = ? AND role IN ('driver', 'production', 'sales') AND status = 'active'", [$targetUserId]);
            if (empty($targetUser)) {
                $ajaxError = 'المستخدم غير موجود أو غير مسموح له بالمحفظة.';
            } else {
                $balance = getWalletBalanceForControl($db, $targetUserId);
                if ($amount > $balance) {
                    $ajaxError = 'رصيد المحفظة (' . formatCurrency($balance) . ') غير كافٍ للسحب.';
                } else {
                    try {
                        $db->execute(
                            "INSERT INTO user_wallet_transactions (user_id, type, amount, reason, created_by) VALUES (?, 'withdrawal', ?, ?, ?)",
                            [$targetUserId, $amount, $notes ?: 'سحب من قبل ' . ($currentUser['full_name'] ?? $currentUser['username']), $currentUser['id']]
                        );
                        logAudit($currentUser['id'], 'wallet_withdrawal', 'user_wallet_transactions', $db->getLastInsertId(), null, ['user_id' => $targetUserId, 'amount' => $amount]);
                        $ajaxSuccess = 'تم سحب ' . formatCurrency($amount) . ' من محفظة ' . htmlspecialchars($targetUser['full_name'] ?? $targetUser['username']) . ' بنجاح. (لا يُضاف للخزنة)';
                    } catch (Throwable $e) {
                        error_log('Wallet withdrawal failed: ' . $e->getMessage());
                        $ajaxError = 'حدث خطأ أثناء السحب. يرجى المحاولة مرة أخرى.';
                    }
                }
            }
        }
    } elseif ($action === 'approve_local_collection_request' && !empty($collectionRequestsTableExistsForAjax)) {
        $requestId = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
        if ($requestId <= 0) {
            $ajaxError = 'معرف الطلب غير صحيح.';
        } else {
            $req = $db->queryOne("SELECT * FROM user_wallet_local_collection_requests WHERE id = ? AND status = 'pending'", [$requestId]);
            if (empty($req)) {
                $ajaxError = 'الطلب غير موجود أو تمت معالجته مسبقاً.';
            } else {
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
                    $walletOwner = $db->queryOne("SELECT full_name FROM users WHERE id = ?", [$userId]);
                    $walletOwnerName = $walletOwner['full_name'] ?? 'مستخدم';
                    $accountantTableExists = $db->queryOne("SHOW TABLES LIKE 'accountant_transactions'");
                    if (!empty($accountantTableExists)) {
                        $desc = 'تحصيل من عميل محلي (محفظة ' . $walletOwnerName . '): ' . $customerName;
                        $ref =  $requestId . '-' . date('Ymd');
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
                    $ajaxSuccess = 'تمت الموافقة على طلب التحصيل (' . formatCurrency($amount) . ' من ' . htmlspecialchars($customerName) . ') وخصم المبلغ من رصيد العميل وإضافته لخزنة الشركة ومحفظة المستخدم.';
                } catch (Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    error_log('approve_local_collection_request: ' . $e->getMessage());
                    $ajaxError = (strpos($e->getMessage(), 'InvalidArgumentException') !== false) ? 'العميل غير موجود أو البيانات غير صحيحة.' : 'حدث خطأ أثناء الموافقة. يرجى المحاولة مرة أخرى.';
                }
            }
        }
    } elseif ($action === 'reject_local_collection_request' && !empty($collectionRequestsTableExistsForAjax)) {
        $requestId = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
        $rejectionReason = trim($_POST['rejection_reason'] ?? '');
        if ($requestId <= 0) {
            $ajaxError = 'معرف الطلب غير صحيح.';
        } else {
            $updated = $db->execute("UPDATE user_wallet_local_collection_requests SET status = 'rejected', approved_by = ?, approved_at = NOW(), rejection_reason = ? WHERE id = ? AND status = 'pending'", [$currentUser['id'], $rejectionReason ?: null, $requestId]);
            if ($updated) {
                $ajaxSuccess = 'تم رفض طلب التحصيل.';
            } else {
                $ajaxError = 'الطلب غير موجود أو تمت معالجته مسبقاً.';
            }
        }
    } elseif ($action === 'approve_all_pending_for_user' && !empty($collectionRequestsTableExistsForAjax)) {
        $targetUserId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
        if ($targetUserId <= 0) {
            $ajaxError = 'يرجى اختيار المستخدم أولاً.';
        } else {
            $pendingList = $db->query(
                "SELECT * FROM user_wallet_local_collection_requests WHERE user_id = ? AND status = 'pending' ORDER BY id ASC",
                [$targetUserId]
            ) ?: [];
            $bulkWalletOwner = $db->queryOne("SELECT full_name FROM users WHERE id = ?", [$targetUserId]);
            $bulkWalletOwnerName = $bulkWalletOwner['full_name'] ?? 'مستخدم';
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
                        $desc = 'تحصيل من عميل محلي (محفظة ' . $bulkWalletOwnerName . '): ' . $customerName;
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
                    error_log('approve_all_pending_for_user item ' . $requestId . ': ' . $e->getMessage());
                    $failMsg = $e->getMessage();
                    break;
                }
            }
            if ($failMsg !== '') {
                $ajaxError = 'حدث خطأ أثناء الموافقة. تمت الموافقة على ' . $approvedCount . ' طلب/طلبات.';
            } elseif ($approvedCount > 0) {
                $ajaxSuccess = 'تمت الموافقة على جميع طلبات التحصيل للمستخدم (' . $approvedCount . ' طلب) بنجاح.';
            } else {
                $ajaxSuccess = 'لا توجد طلبات معلقة لهذا المستخدم.';
            }
        }
    } else {
        $ajaxError = 'إجراء غير صحيح.';
    }

    $walletUsersAjax = $db->query("SELECT u.id, u.full_name, u.username, u.role FROM users u WHERE u.status = 'active' AND u.role IN ('driver', 'production', 'sales') ORDER BY u.role, u.full_name ASC, u.username ASC") ?: [];
    $userBalancesAjax = [];
    foreach ($walletUsersAjax as $u) {
        $userBalancesAjax[$u['id']] = getWalletBalanceForControl($db, $u['id']);
    }
    $pendingLocalCollectionRequestsAjax = [];
    if (!empty($collectionRequestsTableExistsForAjax)) {
        $pendingLocalCollectionRequestsAjax = $db->query(
            "SELECT r.*, u.full_name AS user_full_name, u.username AS user_username FROM user_wallet_local_collection_requests r LEFT JOIN users u ON u.id = r.user_id WHERE r.status = 'pending' ORDER BY r.created_at ASC"
        ) ?: [];
    }
    $selectedUserIdAjax = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
    $txPageAjax = max(1, (int)($_GET['tx_page'] ?? 1));
    $perPageAjax = 15;
    $selectedTransactionsAjax = [];
    $selectedUserBalanceFormatted = '';
    $selectedUserBalanceRaw = 0;
    $transactionsTotalCountAjax = 0;
    $transactionsTotalPagesAjax = 0;
    // فلاتر AJAX
    $ajaxFilterDateFrom = isset($_GET['filter_date_from']) && $_GET['filter_date_from'] !== '' ? $_GET['filter_date_from'] : null;
    $ajaxFilterDateTo = isset($_GET['filter_date_to']) && $_GET['filter_date_to'] !== '' ? $_GET['filter_date_to'] : null;
    $ajaxFilterType = isset($_GET['filter_type']) && $_GET['filter_type'] !== '' ? $_GET['filter_type'] : null;
    $ajaxFilterReason = isset($_GET['filter_reason']) && trim($_GET['filter_reason']) !== '' ? trim($_GET['filter_reason']) : null;
    $ajaxFilterAmountFrom = isset($_GET['filter_amount_from']) && $_GET['filter_amount_from'] !== '' ? (float)$_GET['filter_amount_from'] : null;
    $ajaxFilterAmountTo = isset($_GET['filter_amount_to']) && $_GET['filter_amount_to'] !== '' ? (float)$_GET['filter_amount_to'] : null;

    if ($selectedUserIdAjax > 0) {
        $selectedUserBalanceRaw = $userBalancesAjax[$selectedUserIdAjax] ?? 0;
        $selectedUserBalanceFormatted = formatCurrency($selectedUserBalanceRaw);

        $txWhereAjax = "WHERE t.user_id = ?";
        $txParamsAjax = [$selectedUserIdAjax];
        if ($ajaxFilterDateFrom !== null) { $txWhereAjax .= " AND DATE(t.created_at) >= ?"; $txParamsAjax[] = $ajaxFilterDateFrom; }
        if ($ajaxFilterDateTo !== null) { $txWhereAjax .= " AND DATE(t.created_at) <= ?"; $txParamsAjax[] = $ajaxFilterDateTo; }
        if ($ajaxFilterType !== null) { $txWhereAjax .= " AND t.type = ?"; $txParamsAjax[] = $ajaxFilterType; }
        if ($ajaxFilterReason !== null) { $txWhereAjax .= " AND t.reason LIKE ?"; $txParamsAjax[] = '%' . $ajaxFilterReason . '%'; }
        if ($ajaxFilterAmountFrom !== null) { $txWhereAjax .= " AND t.amount >= ?"; $txParamsAjax[] = $ajaxFilterAmountFrom; }
        if ($ajaxFilterAmountTo !== null) { $txWhereAjax .= " AND t.amount <= ?"; $txParamsAjax[] = $ajaxFilterAmountTo; }

        $countRowAjax = $db->queryOne("SELECT COUNT(*) AS cnt FROM user_wallet_transactions t $txWhereAjax", $txParamsAjax);
        $transactionsTotalCountAjax = (int)($countRowAjax['cnt'] ?? 0);
        $transactionsTotalPagesAjax = $transactionsTotalCountAjax > 0 ? (int)ceil($transactionsTotalCountAjax / $perPageAjax) : 0;
        $txOffsetAjax = ($txPageAjax - 1) * $perPageAjax;
        $selectedTransactionsAjax = $db->query(
            "SELECT t.*, u.full_name as created_by_name FROM user_wallet_transactions t LEFT JOIN users u ON u.id = t.created_by $txWhereAjax ORDER BY t.created_at DESC LIMIT " . (int)$perPageAjax . " OFFSET " . (int)$txOffsetAjax,
            $txParamsAjax
        ) ?: [];
    }
    $typeLabelsAjax = ['deposit' => 'إيداع', 'withdrawal' => 'سحب', 'custody_add' => 'عهدة', 'custody_retrieve' => 'استرجاع عهدة'];
    $out = [
        'success' => ($ajaxError === ''),
        'message' => $ajaxError ?: $ajaxSuccess,
        'user_balances' => [],
        'selected_user_id' => $selectedUserIdAjax,
        'selected_user_balance' => $selectedUserBalanceRaw,
        'selected_user_balance_formatted' => $selectedUserBalanceFormatted,
        'transactions' => [],
        'pending_requests' => [],
        'pagination' => [
            'current_page' => $txPageAjax,
            'total_pages' => $transactionsTotalPagesAjax,
            'total_count' => $transactionsTotalCountAjax,
            'per_page' => $perPageAjax
        ]
    ];
    foreach ($userBalancesAjax as $uid => $bal) {
        $out['user_balances'][(string)$uid] = formatCurrency($bal);
    }
    $printReceiptBaseAjax = getRelativeUrl('print_task_receipt.php');
    foreach ($selectedTransactionsAjax as $t) {
        $type = $t['type'] ?? '';
        $isCredit = in_array($type, ['deposit', 'custody_add']);
        $reason = (string)($t['reason'] ?? '');
        $orderIdForPrint = null;
        if ($type === 'deposit' && (strpos($reason, 'أوردر') !== false || strpos($reason, 'اوردر') !== false) && preg_match('/#\s*(\d+)/u', $reason, $orderMatch)) {
            $orderIdForPrint = (int)$orderMatch[1];
        }
        $printReceiptUrl = $orderIdForPrint > 0 ? ($printReceiptBaseAjax . (strpos($printReceiptBaseAjax, '?') !== false ? '&' : '?') . 'id=' . $orderIdForPrint) : '';
        $out['transactions'][] = [
            'created_at' => date('Y-m-d H:i', strtotime($t['created_at'])),
            'type' => $type,
            'type_label' => $typeLabelsAjax[$type] ?? $type,
            'amount' => (float)$t['amount'],
            'amount_formatted' => ($isCredit ? '+' : '-') . formatCurrency($t['amount']),
            'reason' => $t['reason'] ?? '-',
            'created_by_name' => $t['created_by_name'] ?? '-',
            'is_credit' => $isCredit,
            'order_id_for_print' => $orderIdForPrint,
            'print_receipt_url' => $printReceiptUrl
        ];
    }
    foreach ($pendingLocalCollectionRequestsAjax as $req) {
        $out['pending_requests'][] = [
            'id' => (int)$req['id'],
            'created_at' => date('Y-m-d H:i', strtotime($req['created_at'])),
            'user_full_name' => $req['user_full_name'] ?? $req['user_username'] ?? ('#' . $req['user_id']),
            'customer_name' => $req['customer_name'] ?? '',
            'amount' => (float)$req['amount'],
            'amount_formatted' => formatCurrency($req['amount'])
        ];
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

// معالجة السحب
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'withdraw') {
    $targetUserId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
    $amount = isset($_POST['amount']) ? (float) str_replace(',', '', $_POST['amount']) : 0;
    $notes = trim($_POST['notes'] ?? '');

    if ($targetUserId <= 0) {
        $error = 'يرجى اختيار المستخدم.';
    } elseif ($amount <= 0) {
        $error = 'يرجى إدخال مبلغ صحيح أكبر من الصفر.';
    } else {
        $targetUser = $db->queryOne("SELECT id, full_name, username, role FROM users WHERE id = ? AND role IN ('driver', 'production', 'sales') AND status = 'active'", [$targetUserId]);
        if (empty($targetUser)) {
            $error = 'المستخدم غير موجود أو غير مسموح له بالمحفظة.';
        } else {
            $balance = getWalletBalanceForControl($db, $targetUserId);
            if ($amount > $balance) {
                $error = 'رصيد المحفظة (' . formatCurrency($balance) . ') غير كافٍ للسحب.';
            } else {
                try {
                    $db->execute(
                        "INSERT INTO user_wallet_transactions (user_id, type, amount, reason, created_by) VALUES (?, 'withdrawal', ?, ?, ?)",
                        [$targetUserId, $amount, $notes ?: 'سحب من قبل ' . ($currentUser['full_name'] ?? $currentUser['username']), $currentUser['id']]
                    );
                    logAudit($currentUser['id'], 'wallet_withdrawal', 'user_wallet_transactions', $db->getLastInsertId(), null, ['user_id' => $targetUserId, 'amount' => $amount]);
                    $_SESSION['user_wallets_success'] = 'تم سحب ' . formatCurrency($amount) . ' من محفظة ' . htmlspecialchars($targetUser['full_name'] ?? $targetUser['username']) . ' بنجاح. (لا يُضاف للخزنة)';
                    $redirectUrl = ($_SERVER['PHP_SELF'] ?? 'manager.php') . '?page=user_wallets_control&user_id=' . $targetUserId;
                    if (!headers_sent()) {
                        header('Location: ' . $redirectUrl);
                        exit;
                    }
                } catch (Throwable $e) {
                    error_log('Wallet withdrawal failed: ' . $e->getMessage());
                    $error = 'حدث خطأ أثناء السحب. يرجى المحاولة مرة أخرى.';
                }
            }
        }
    }
}

// جدول طلبات التحصيل من العملاء المحليين
$collectionRequestsTableExists = $db->queryOne("SHOW TABLES LIKE 'user_wallet_local_collection_requests'");

// معالجة الموافقة على طلب تحصيل من عميل محلي
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'approve_local_collection_request' && !empty($collectionRequestsTableExists)) {
    $requestId = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
    if ($requestId <= 0) {
        $error = 'معرف الطلب غير صحيح.';
    } else {
        $req = $db->queryOne("SELECT * FROM user_wallet_local_collection_requests WHERE id = ? AND status = 'pending'", [$requestId]);
        if (empty($req)) {
            $error = 'الطلب غير موجود أو تمت معالجته مسبقاً.';
        } else {
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
                $collectionId = null;
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
                    $collectionId = $db->getLastInsertId();
                }
                $accountantTableExists = $db->queryOne("SHOW TABLES LIKE 'accountant_transactions'");
                if (!empty($accountantTableExists)) {
                    $walletUser = $db->queryOne("SELECT full_name, username FROM users WHERE id = ?", [$userId]);
                    $walletUserName = trim($walletUser['full_name'] ?? '') ?: trim($walletUser['username'] ?? '') ?: 'مستخدم';
                    $desc = 'تحصيل من عميل محلي (محفظة ' . $walletUserName . '): ' . $customerName;
                    $ref = (string) random_int(100000, 999999);
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
                $_SESSION['user_wallets_success'] = 'تمت الموافقة على طلب التحصيل (' . formatCurrency($amount) . ' من ' . htmlspecialchars($customerName) . ') وخصم المبلغ من رصيد العميل وإضافته لخزنة الشركة ومحفظة المستخدم.';
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('approve_local_collection_request: ' . $e->getMessage());
                $error = $e->getMessage();
                if (strpos($error, 'InvalidArgumentException') !== false) {
                    $error = 'العميل غير موجود أو البيانات غير صحيحة.';
                } else {
                    $error = 'حدث خطأ أثناء الموافقة. يرجى المحاولة مرة أخرى.';
                }
            }
        }
    }
    $redirectUrl = ($_SERVER['PHP_SELF'] ?? 'manager.php') . '?page=user_wallets_control';
    if ($selectedUserId > 0) {
        $redirectUrl .= '&user_id=' . $selectedUserId;
    }
    if (!headers_sent() && empty($error)) {
        header('Location: ' . $redirectUrl);
        exit;
    }
}

// معالجة رفض طلب تحصيل من عميل محلي
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reject_local_collection_request' && !empty($collectionRequestsTableExists)) {
    $requestId = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
    $rejectionReason = trim($_POST['rejection_reason'] ?? '');
    if ($requestId <= 0) {
        $error = 'معرف الطلب غير صحيح.';
    } else {
        $updated = $db->execute("UPDATE user_wallet_local_collection_requests SET status = 'rejected', approved_by = ?, approved_at = NOW(), rejection_reason = ? WHERE id = ? AND status = 'pending'", [$currentUser['id'], $rejectionReason ?: null, $requestId]);
        if ($updated) {
            $_SESSION['user_wallets_success'] = 'تم رفض طلب التحصيل.';
            $redirectUrl = ($_SERVER['PHP_SELF'] ?? 'manager.php') . '?page=user_wallets_control';
            if ($selectedUserId > 0) {
                $redirectUrl .= '&user_id=' . $selectedUserId;
            }
            if (!headers_sent()) {
                header('Location: ' . $redirectUrl);
                exit;
            }
        } else {
            $error = 'الطلب غير موجود أو تمت معالجته مسبقاً.';
        }
    }
}

$pendingLocalCollectionRequests = [];
if (!empty($collectionRequestsTableExists)) {
    $pendingLocalCollectionRequests = $db->query(
        "SELECT r.*, u.full_name AS user_full_name, u.username AS user_username FROM user_wallet_local_collection_requests r LEFT JOIN users u ON u.id = r.user_id WHERE r.status = 'pending' ORDER BY r.created_at ASC"
    ) ?: [];
}
$pendingCountForSelectedUser = 0;
$usersWithPendingRequests = []; // [ ['user_id'=>x, 'user_name'=>y, 'pending_count'=>z], ... ]
if (!empty($pendingLocalCollectionRequests)) {
    $byUser = [];
    foreach ($pendingLocalCollectionRequests as $r) {
        $uid = (int)$r['user_id'];
        $name = trim($r['user_full_name'] ?? '') ?: trim($r['user_username'] ?? '') ?: ('#' . $uid);
        if (!isset($byUser[$uid])) {
            $byUser[$uid] = ['user_id' => $uid, 'user_name' => $name, 'pending_count' => 0];
        }
        $byUser[$uid]['pending_count']++;
        if ($uid === (int)$selectedUserId) {
            $pendingCountForSelectedUser++;
        }
    }
    $usersWithPendingRequests = array_values($byUser);
}

// جلب قائمة المستخدمين (سائق، عامل إنتاج) مع أرصدتهم
$walletUsers = $db->query(
    "SELECT u.id, u.full_name, u.username, u.role FROM users u WHERE u.status = 'active' AND u.role IN ('driver', 'production', 'sales') ORDER BY u.role, u.full_name ASC, u.username ASC"
) ?: [];

$userBalances = [];
foreach ($walletUsers as $u) {
    $userBalances[$u['id']] = getWalletBalanceForControl($db, $u['id']);
}

$transactionsPerPage = 15;
$txPage = max(1, (int)($_GET['tx_page'] ?? 1));
$selectedUser = null;
$selectedTransactions = [];
$transactionsTotalCount = 0;
$transactionsTotalPages = 0;
// فلاتر سجل المعاملات
$filterDateFrom = isset($_GET['filter_date_from']) && $_GET['filter_date_from'] !== '' ? $_GET['filter_date_from'] : null;
$filterDateTo = isset($_GET['filter_date_to']) && $_GET['filter_date_to'] !== '' ? $_GET['filter_date_to'] : null;
$filterType = isset($_GET['filter_type']) && $_GET['filter_type'] !== '' ? $_GET['filter_type'] : null;
$filterReason = isset($_GET['filter_reason']) && trim($_GET['filter_reason']) !== '' ? trim($_GET['filter_reason']) : null;
$filterAmountFrom = isset($_GET['filter_amount_from']) && $_GET['filter_amount_from'] !== '' ? (float)$_GET['filter_amount_from'] : null;
$filterAmountTo = isset($_GET['filter_amount_to']) && $_GET['filter_amount_to'] !== '' ? (float)$_GET['filter_amount_to'] : null;

if ($selectedUserId > 0) {
    $selectedUser = $db->queryOne("SELECT id, full_name, username, role FROM users WHERE id = ? AND role IN ('driver', 'production', 'sales')", [$selectedUserId]);
    if ($selectedUser) {
        $txWhere = "WHERE t.user_id = ?";
        $txParams = [$selectedUserId];
        if ($filterDateFrom !== null) { $txWhere .= " AND DATE(t.created_at) >= ?"; $txParams[] = $filterDateFrom; }
        if ($filterDateTo !== null) { $txWhere .= " AND DATE(t.created_at) <= ?"; $txParams[] = $filterDateTo; }
        if ($filterType !== null) { $txWhere .= " AND t.type = ?"; $txParams[] = $filterType; }
        if ($filterReason !== null) { $txWhere .= " AND t.reason LIKE ?"; $txParams[] = '%' . $filterReason . '%'; }
        if ($filterAmountFrom !== null) { $txWhere .= " AND t.amount >= ?"; $txParams[] = $filterAmountFrom; }
        if ($filterAmountTo !== null) { $txWhere .= " AND t.amount <= ?"; $txParams[] = $filterAmountTo; }

        $countRow = $db->queryOne("SELECT COUNT(*) AS cnt FROM user_wallet_transactions t $txWhere", $txParams);
        $transactionsTotalCount = (int)($countRow['cnt'] ?? 0);
        $transactionsTotalPages = $transactionsTotalCount > 0 ? (int)ceil($transactionsTotalCount / $transactionsPerPage) : 0;
        $txOffset = ($txPage - 1) * $transactionsPerPage;
        $selectedTransactions = $db->query(
            "SELECT t.*, u.full_name as created_by_name FROM user_wallet_transactions t LEFT JOIN users u ON u.id = t.created_by $txWhere ORDER BY t.created_at DESC LIMIT " . (int)$transactionsPerPage . " OFFSET " . (int)$txOffset,
            $txParams
        ) ?: [];

        // إحصائيات الشهر الحالي
        $monthlyStats = $db->queryOne("
            SELECT
                COALESCE(SUM(CASE WHEN type IN ('deposit','custody_add') THEN amount ELSE 0 END), 0) AS total_in,
                COALESCE(SUM(CASE WHEN type IN ('withdrawal','custody_retrieve') THEN amount ELSE 0 END), 0) AS total_out,
                COUNT(CASE WHEN type = 'deposit' THEN 1 END) AS deposit_count,
                COUNT(CASE WHEN type = 'withdrawal' THEN 1 END) AS withdrawal_count
            FROM user_wallet_transactions
            WHERE user_id = ? AND YEAR(created_at) = YEAR(NOW()) AND MONTH(created_at) = MONTH(NOW())
        ", [$selectedUserId]) ?: [];
    }
}

$typeLabels = [
    'deposit' => 'إيداع',
    'withdrawal' => 'سحب',
    'custody_add' => 'عهدة',
    'custody_retrieve' => 'استرجاع عهدة'
];
$roleLabels = ['driver' => 'سائق', 'production' => 'عامل إنتاج', 'sales' => 'مندوب مبيعات'];
?>
<div class="container-fluid">
    <div class="page-header mb-4">
        <h2><i class="bi bi-wallet2 me-2"></i>التحكم في محافظ المستخدمين</h2>
        <p class="text-muted mb-0">سحب الأموال من محافظ السائقين وعمال الإنتاج (لا تُضاف للخزنة)</p>
    </div>

    <div id="wallets-control-alert-container"></div>
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle me-2"></i><?php echo $success; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($collectionRequestsTableExists) && !empty($pendingLocalCollectionRequests)): ?>
    <div class="card shadow-sm mb-4 border-warning" id="wallets-control-pending-card">
        <div class="card-header bg-warning bg-opacity-25 fw-bold d-flex flex-wrap align-items-center justify-content-between gap-2">
            <span><i class="bi bi-hourglass-split me-2"></i>طلبات التحصيل من العملاء المحليين (في انتظار الموافقة)</span>
            <button type="button" class="btn btn-success btn-sm" id="wallets-control-open-approve-all-card-btn">
                <i class="bi bi-check-all me-1"></i>الموافقة على طلبات مستخدم
            </button>
        </div>
        <div class="collapse" id="wallets-control-approve-all-user-card">
            <div class="card-body border-top bg-light">
                <p class="mb-2 small text-muted">اختر المستخدم للموافقة على جميع طلبات التحصيل المعلقة الخاصة به.</p>
                <div class="row g-2 align-items-end">
                    <div class="col-12 col-md-6 col-lg-4">
                        <label for="wallets-control-approve-all-user-select" class="form-label small">المستخدم</label>
                        <select class="form-select form-select-sm" id="wallets-control-approve-all-user-select">
                            <option value="">— اختر المستخدم —</option>
                            <?php foreach ($usersWithPendingRequests as $u): ?>
                            <option value="<?php echo (int)$u['user_id']; ?>" data-count="<?php echo (int)$u['pending_count']; ?>">
                                <?php echo htmlspecialchars($u['user_name']); ?> (<?php echo (int)$u['pending_count']; ?> طلب)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-6 col-lg-4">
                        <button type="button" class="btn btn-success btn-sm" id="wallets-control-approve-all-user-btn">
                            <i class="bi bi-check-all me-1"></i>الموافقة على كل الطلبات المعلقة له
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm ms-1" data-bs-toggle="collapse" data-bs-target="#wallets-control-approve-all-user-card" aria-expanded="false">إلغاء</button>
                    </div>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>التاريخ</th>
                            <th>المستخدم</th>
                            <th>العميل</th>
                            <th>المبلغ</th>
                            <th>إجراء</th>
                        </tr>
                    </thead>
                    <tbody id="wallets-control-pending-tbody">
                        <?php foreach ($pendingLocalCollectionRequests as $req): ?>
                        <tr>
                            <td><?php echo date('Y-m-d H:i', strtotime($req['created_at'])); ?></td>
                            <td><?php echo htmlspecialchars($req['user_full_name'] ?: $req['user_username'] ?? '#' . $req['user_id']); ?></td>
                            <td><?php echo htmlspecialchars($req['customer_name']); ?></td>
                            <td class="fw-bold"><?php echo formatCurrency($req['amount']); ?></td>
                            <td>
                                <form method="POST" class="d-inline wallets-control-approve-form" onsubmit="return confirm('الموافقة على هذا الطلب ستخصم المبلغ من رصيد العميل وتضيفه لخزنة الشركة ومحفظة المستخدم. متأكد؟');" data-wallets-control-ajax data-request-id="<?php echo (int)$req['id']; ?>">
                                    <input type="hidden" name="action" value="approve_local_collection_request">
                                    <input type="hidden" name="request_id" value="<?php echo (int)$req['id']; ?>">
                                    <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-check-lg me-1"></i>موافقة</button>
                                </form>
                                <button type="button" class="btn btn-outline-danger btn-sm" data-reject-toggle data-reject-card-id="rejectCard<?php echo (int)$req['id']; ?>"><i class="bi bi-x-lg me-1"></i>رفض</button>
                                <div class="reject-card-wrap d-none mt-2" id="rejectCard<?php echo (int)$req['id']; ?>">
                                    <div class="card border shadow-none bg-light">
                                        <div class="card-header py-2 d-flex justify-content-between align-items-center">
                                            <h6 class="mb-0">رفض طلب التحصيل</h6>
                                            <button type="button" class="btn-close btn-sm" aria-label="إغلاق" data-reject-card-close></button>
                                        </div>
                                        <div class="card-body">
                                            <form method="POST" class="wallets-control-reject-form" data-wallets-control-ajax data-request-id="<?php echo (int)$req['id']; ?>">
                                                <input type="hidden" name="action" value="reject_local_collection_request">
                                                <input type="hidden" name="request_id" value="<?php echo (int)$req['id']; ?>">
                                                <p class="mb-2 small">طلب: <?php echo formatCurrency($req['amount']); ?> من <?php echo htmlspecialchars($req['customer_name']); ?></p>
                                                <label class="form-label">سبب الرفض (اختياري)</label>
                                                <input type="text" class="form-control form-control-sm" name="rejection_reason" placeholder="سبب الرفض">
                                                <div class="mt-2 d-flex gap-1">
                                                    <button type="button" class="btn btn-secondary btn-sm" data-reject-card-close>إلغاء</button>
                                                    <button type="submit" class="btn btn-danger btn-sm">رفض الطلب</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-12 col-lg-4 mb-4">
            <div class="card shadow-sm">
                <div class="card-header bg-light fw-bold">
                    <i class="bi bi-people me-2"></i>المستخدمون ذوو المحافظ
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <?php foreach ($walletUsers as $u): ?>
                            <?php $bal = $userBalances[$u['id']] ?? 0; ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['user_id' => $u['id'], 'page' => 'user_wallets_control'])); ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?php echo $selectedUserId == $u['id'] ? 'active' : ''; ?>" data-wallet-user-id="<?php echo (int)$u['id']; ?>">
                                <span><?php echo htmlspecialchars($u['full_name'] ?: $u['username']); ?> <small class="text-muted">(<?php echo $roleLabels[$u['role']] ?? $u['role']; ?>)</small></span>
                                <span class="badge bg-<?php echo $bal >= 0 ? 'success' : 'danger'; ?> rounded-pill" data-wallet-user-balance><?php echo formatCurrency($bal); ?></span>
                            </a>
                        <?php endforeach; ?>
                        <?php if (empty($walletUsers)): ?>
                            <div class="list-group-item text-muted text-center">لا يوجد مستخدمون مسجلون</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-8 mb-4">
            <?php if ($selectedUser): ?>
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-light fw-bold d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <span><i class="bi bi-cash-stack me-2"></i>سحب من محفظة <?php echo htmlspecialchars($selectedUser['full_name'] ?: $selectedUser['username']); ?>
                        <span class="badge bg-primary ms-2" id="wallets-control-selected-balance">الرصيد: <?php echo formatCurrency($userBalances[$selectedUser['id']] ?? 0); ?></span></span>
                    </div>
                    <?php if (!empty($monthlyStats)): ?>
                    <div class="px-3 pt-3 pb-1">
                        <div class="row g-2 text-center">
                            <div class="col-6 col-md-3">
                                <div class="border rounded p-2 bg-success bg-opacity-10">
                                    <div class="small text-muted">إيداعات الشهر</div>
                                    <div class="fw-bold text-success"><?php echo formatCurrency($monthlyStats['total_in'] ?? 0); ?></div>
                                    <div class="text-muted" style="font-size:.75rem"><?php echo (int)($monthlyStats['deposit_count'] ?? 0); ?> عملية</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="card-body">
                        <form method="POST" class="row g-3" id="wallets-control-withdraw-form" data-wallets-control-ajax>
                            <input type="hidden" name="action" value="withdraw">
                            <input type="hidden" name="user_id" value="<?php echo (int) $selectedUser['id']; ?>">
                            <div class="col-12 col-md-4">
                                <label for="withdrawAmount" class="form-label">المبلغ <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text">ج.م</span>
                                    <input type="number" step="0.01" min="0.01" max="<?php echo ($userBalances[$selectedUser['id']] ?? 0); ?>" class="form-control" id="withdrawAmount" name="amount" required placeholder="0.00">
                                </div>
                            </div>
                            <div class="col-12 col-md-6">
                                <label for="withdrawNotes" class="form-label">ملاحظات (اختياري)</label>
                                <input type="text" class="form-control" id="withdrawNotes" name="notes" placeholder="سبب السحب أو تفاصيل">
                            </div>
                            <div class="col-12 col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-danger w-100">
                                    <i class="bi bi-dash-circle me-1"></i>سحب
                                </button>
                            </div>
                        </form>
                        <small class="text-muted">ملاحظة: المبلغ المسحوب لا يُضاف إلى خزنة الشركة</small>
                    </div>
                </div>
                <div class="card shadow-sm">
                    <div class="card-header bg-light fw-bold">
                        <i class="bi bi-journal-text me-2"></i>سجل معاملات <?php echo htmlspecialchars($selectedUser['full_name'] ?: $selectedUser['username']); ?>
                    </div>
                    <div class="card-body p-0">
                        <div class="p-3 bg-light border-bottom" id="wallets-tx-filters">
                            <form id="wallets-tx-filter-form" class="row g-2 align-items-end">
                                <div class="col-6 col-md-2">
                                    <label class="form-label small mb-1">من تاريخ</label>
                                    <input type="date" class="form-control form-control-sm" name="filter_date_from" value="<?php echo htmlspecialchars($filterDateFrom ?? ''); ?>">
                                </div>
                                <div class="col-6 col-md-2">
                                    <label class="form-label small mb-1">إلى تاريخ</label>
                                    <input type="date" class="form-control form-control-sm" name="filter_date_to" value="<?php echo htmlspecialchars($filterDateTo ?? ''); ?>">
                                </div>
                                <div class="col-6 col-md-2">
                                    <label class="form-label small mb-1">النوع</label>
                                    <select class="form-select form-select-sm" name="filter_type">
                                        <option value="">الكل</option>
                                        <option value="deposit" <?php echo ($filterType ?? '') === 'deposit' ? 'selected' : ''; ?>>إيداع</option>
                                        <option value="withdrawal" <?php echo ($filterType ?? '') === 'withdrawal' ? 'selected' : ''; ?>>سحب</option>
                                        <option value="custody_add" <?php echo ($filterType ?? '') === 'custody_add' ? 'selected' : ''; ?>>عهدة</option>
                                        <option value="custody_retrieve" <?php echo ($filterType ?? '') === 'custody_retrieve' ? 'selected' : ''; ?>>استرجاع عهدة</option>
                                    </select>
                                </div>
                                <div class="col-6 col-md-2">
                                    <label class="form-label small mb-1">السبب / الوصف</label>
                                    <input type="text" class="form-control form-control-sm" name="filter_reason" placeholder="بحث..." value="<?php echo htmlspecialchars($filterReason ?? ''); ?>">
                                </div>
                                <div class="col-6 col-md-1">
                                    <label class="form-label small mb-1">مبلغ من</label>
                                    <input type="number" step="0.01" class="form-control form-control-sm" name="filter_amount_from" placeholder="من" value="<?php echo $filterAmountFrom !== null ? htmlspecialchars($filterAmountFrom) : ''; ?>">
                                </div>
                                <div class="col-6 col-md-1">
                                    <label class="form-label small mb-1">مبلغ إلى</label>
                                    <input type="number" step="0.01" class="form-control form-control-sm" name="filter_amount_to" placeholder="إلى" value="<?php echo $filterAmountTo !== null ? htmlspecialchars($filterAmountTo) : ''; ?>">
                                </div>
                                <div class="col-12 col-md-2 d-flex gap-1">
                                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i>فلترة</button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" id="wallets-tx-filter-reset"><i class="bi bi-x-lg"></i></button>
                                </div>
                            </form>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>التاريخ</th>
                                        <th>النوع</th>
                                        <th>المبلغ</th>
                                        <th>السبب / الوصف</th>
                                        <th>بواسطة</th>
                                        <th>إجراء</th>
                                    </tr>
                                </thead>
                                <tbody id="wallets-control-transactions-tbody">
                                    <?php if (empty($selectedTransactions)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted py-4">لا توجد معاملات</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php
                                        $printReceiptBase = getRelativeUrl('print_task_receipt.php');
                                        foreach ($selectedTransactions as $t):
                                            $reason = (string)($t['reason'] ?? '');
                                            $isOrderCollection = ($t['type'] ?? '') === 'deposit' && (strpos($reason, 'أوردر') !== false || strpos($reason, 'اوردر') !== false);
                                            $orderIdForPrint = null;
                                            if ($isOrderCollection && preg_match('/#\s*(\d+)/u', $reason, $orderMatch)) {
                                                $orderIdForPrint = (int)$orderMatch[1];
                                            }
                                        ?>
                                            <tr>
                                                <td><?php echo date('Y-m-d H:i', strtotime($t['created_at'])); ?></td>
                                                <td><span class="badge bg-<?php echo in_array($t['type'], ['deposit', 'custody_add']) ? 'success' : 'danger'; ?>"><?php echo htmlspecialchars($typeLabels[$t['type']] ?? $t['type']); ?></span></td>
                                                <td class="fw-bold <?php echo in_array($t['type'], ['deposit', 'custody_add']) ? 'text-success' : 'text-danger'; ?>">
                                                    <?php echo in_array($t['type'], ['deposit', 'custody_add']) ? '+' : '-'; ?><?php echo formatCurrency($t['amount']); ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($t['reason'] ?? '-'); ?></td>
                                                <td><?php echo htmlspecialchars($t['created_by_name'] ?? '-'); ?></td>
                                                <td>
                                                    <?php if ($orderIdForPrint > 0): ?>
                                                        <a href="<?php echo htmlspecialchars($printReceiptBase . (strpos($printReceiptBase, '?') !== false ? '&' : '?') . 'id=' . $orderIdForPrint); ?>" target="_blank" class="btn btn-outline-primary btn-sm" title="طباعة الأوردر #<?php echo $orderIdForPrint; ?>">
                                                            <i class="bi bi-printer me-1"></i> 
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php
                        $paginationBaseParams = array_merge($_GET, ['page' => 'user_wallets_control']);
                        if ($selectedUserId > 0) {
                            $paginationBaseParams['user_id'] = $selectedUserId;
                        }
                        $paginationScript = $_SERVER['PHP_SELF'] ?? 'manager.php';
                        ?>
                        <div class="card-footer bg-light py-2 d-flex flex-wrap align-items-center justify-content-between gap-2" id="wallets-control-pagination" data-pagination-script="<?php echo htmlspecialchars($paginationScript); ?>" data-user-id="<?php echo (int)$selectedUserId; ?>">
                            <?php if ($transactionsTotalPages > 1): ?>
                            <nav aria-label="ترقيم سجل المعاملات" class="mb-0">
                                <ul class="pagination pagination-sm mb-0 flex-wrap">
                                    <?php
                                    $prevParams = array_merge($paginationBaseParams, ['tx_page' => $txPage - 1]);
                                    $nextParams = array_merge($paginationBaseParams, ['tx_page' => $txPage + 1]);
                                    ?>
                                    <li class="page-item <?php echo $txPage <= 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="<?php echo $txPage <= 1 ? '#' : htmlspecialchars($paginationScript . '?' . http_build_query($prevParams)); ?>">السابق</a>
                                    </li>
                                    <?php
                                    $from = max(1, $txPage - 2);
                                    $to = min($transactionsTotalPages, $txPage + 2);
                                    for ($p = $from; $p <= $to; $p++):
                                        $pageParams = array_merge($paginationBaseParams, ['tx_page' => $p]);
                                        $link = $paginationScript . '?' . http_build_query($pageParams);
                                    ?>
                                    <li class="page-item <?php echo $p === $txPage ? 'active' : ''; ?>"><a class="page-link" href="<?php echo htmlspecialchars($link); ?>"><?php echo $p; ?></a></li>
                                    <?php endfor; ?>
                                    <li class="page-item <?php echo $txPage >= $transactionsTotalPages ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="<?php echo $txPage >= $transactionsTotalPages ? '#' : htmlspecialchars($paginationScript . '?' . http_build_query($nextParams)); ?>">التالي</a>
                                    </li>
                                </ul>
                            </nav>
                            <?php endif; ?>
                            <small class="text-muted"><?php echo $transactionsTotalCount; ?> معاملة<?php echo $transactionsTotalPages > 1 ? ' — صفحة ' . $txPage . ' من ' . $transactionsTotalPages : ''; ?></small>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="card shadow-sm">
                    <div class="card-body text-center text-muted py-5">
                        <i class="bi bi-person-plus display-4"></i>
                        <p class="mt-2 mb-0">اختر مستخدماً من القائمة لعرض محفظته وإجراء السحب</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function() {
    var alertContainer = document.getElementById('wallets-control-alert-container');
    var selectedBalanceEl = document.getElementById('wallets-control-selected-balance');
    var transactionsTbody = document.getElementById('wallets-control-transactions-tbody');
    var pendingTbody = document.getElementById('wallets-control-pending-tbody');

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
        if (data.user_balances) {
            document.querySelectorAll('[data-wallet-user-id]').forEach(function(a) {
                var id = a.getAttribute('data-wallet-user-id');
                var badge = a.querySelector('[data-wallet-user-balance]');
                var formatted = data.user_balances[id];
                if (badge && formatted !== undefined) {
                    badge.textContent = formatted;
                    var num = parseFloat(formatted.replace(/[^\d.-]/g, '')) || 0;
                    badge.className = 'badge rounded-pill ' + (num >= 0 ? 'bg-success' : 'bg-danger');
                }
            });
        }
        if (data.selected_user_balance_formatted !== undefined && selectedBalanceEl) {
            selectedBalanceEl.textContent = 'الرصيد: ' + data.selected_user_balance_formatted;
        }
        var withdrawInput = document.getElementById('withdrawAmount');
        if (withdrawInput && data.selected_user_balance !== undefined) {
            withdrawInput.setAttribute('max', Math.max(0, data.selected_user_balance));
        }
        if (data.transactions && transactionsTbody) {
            if (data.transactions.length === 0) {
                transactionsTbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">لا توجد معاملات</td></tr>';
            } else {
                var html = '';
                data.transactions.forEach(function(t) {
                    var badgeClass = t.is_credit ? 'success' : 'danger';
                    var textClass = t.is_credit ? 'text-success' : 'text-danger';
                    var actionCell = '';
                    if (t.print_receipt_url) {
                        actionCell = '<a href="' + (t.print_receipt_url || '') + '" target="_blank" class="btn btn-outline-primary btn-sm" title="طباعة الأوردر #' + (t.order_id_for_print || '') + '"><i class="bi bi-printer me-1"></i>طباعة الأوردر</a>';
                    } else {
                        actionCell = '<span class="text-muted">—</span>';
                    }
                    html += '<tr><td>' + (t.created_at || '') + '</td><td><span class="badge bg-' + badgeClass + '">' + (t.type_label || '') + '</span></td><td class="fw-bold ' + textClass + '">' + (t.amount_formatted || '') + '</td><td>' + (t.reason || '-') + '</td><td>' + (t.created_by_name || '-') + '</td><td>' + actionCell + '</td></tr>';
                });
                transactionsTbody.innerHTML = html;
            }
        }
        if (data.pending_requests && pendingTbody) {
            if (data.pending_requests.length === 0) {
                pendingTbody.innerHTML = '';
            } else {
                var ph = '';
                data.pending_requests.forEach(function(r) {
                    ph += '<tr><td>' + (r.created_at || '') + '</td><td>' + (r.user_full_name || '') + '</td><td>' + (r.customer_name || '') + '</td><td class="fw-bold">' + (r.amount_formatted || '') + '</td><td>';
                    ph += '<form method="POST" class="d-inline wallets-control-approve-form" onsubmit="return confirm(\'الموافقة على هذا الطلب ستخصم المبلغ من رصيد العميل وتضيفه لخزنة الشركة ومحفظة المستخدم. متأكد؟\');" data-wallets-control-ajax><input type="hidden" name="action" value="approve_local_collection_request"><input type="hidden" name="request_id" value="' + r.id + '"><button type="submit" class="btn btn-success btn-sm"><i class="bi bi-check-lg me-1"></i>موافقة</button></form> ';
                    ph += '<button type="button" class="btn btn-outline-danger btn-sm" data-reject-toggle data-reject-card-id="rejectCard' + r.id + '"><i class="bi bi-x-lg me-1"></i>رفض</button>';
                    ph += '<div class="reject-card-wrap d-none mt-2" id="rejectCard' + r.id + '"><div class="card border shadow-none bg-light"><div class="card-header py-2 d-flex justify-content-between align-items-center"><h6 class="mb-0">رفض طلب التحصيل</h6><button type="button" class="btn-close btn-sm" aria-label="إغلاق" data-reject-card-close></button></div><div class="card-body"><form method="POST" class="wallets-control-reject-form" data-wallets-control-ajax"><input type="hidden" name="action" value="reject_local_collection_request"><input type="hidden" name="request_id" value="' + r.id + '"><p class="mb-2 small">طلب: ' + (r.amount_formatted || '') + ' من ' + (r.customer_name || '') + '</p><label class="form-label">سبب الرفض (اختياري)</label><input type="text" class="form-control form-control-sm" name="rejection_reason" placeholder="سبب الرفض"><div class="mt-2 d-flex gap-1"><button type="button" class="btn btn-secondary btn-sm" data-reject-card-close>إلغاء</button><button type="submit" class="btn btn-danger btn-sm">رفض الطلب</button></div></form></div></div></div></td></tr>';
                });
                pendingTbody.innerHTML = ph;
                bindWalletsControlForms();
            }
        }
        var paginationEl = document.getElementById('wallets-control-pagination');
        if (paginationEl && data.pagination) {
            var pg = data.pagination;
            var totalCount = pg.total_count || 0;
            var totalPages = pg.total_pages || 0;
            var currentPage = pg.current_page || 1;
            var script = paginationEl.getAttribute('data-pagination-script') || '';
            var userId = paginationEl.getAttribute('data-user-id') || (data.selected_user_id || '');
            var baseQuery = 'page=user_wallets_control&user_id=' + encodeURIComponent(userId);
            var html = '';
            if (totalPages > 1) {
                var from = Math.max(1, currentPage - 2);
                var to = Math.min(totalPages, currentPage + 2);
                html += '<nav aria-label="ترقيم سجل المعاملات" class="mb-0"><ul class="pagination pagination-sm mb-0 flex-wrap">';
                html += '<li class="page-item' + (currentPage <= 1 ? ' disabled' : '') + '"><a class="page-link" href="' + (currentPage <= 1 ? '#' : (script + '?' + baseQuery + '&tx_page=' + (currentPage - 1))) + '">السابق</a></li>';
                for (var p = from; p <= to; p++) {
                    html += '<li class="page-item' + (p === currentPage ? ' active' : '') + '"><a class="page-link" href="' + (script + '?' + baseQuery + '&tx_page=' + p) + '">' + p + '</a></li>';
                }
                html += '<li class="page-item' + (currentPage >= totalPages ? ' disabled' : '') + '"><a class="page-link" href="' + (currentPage >= totalPages ? '#' : (script + '?' + baseQuery + '&tx_page=' + (currentPage + 1))) + '">التالي</a></li>';
                html += '</ul></nav>';
            }
            html += '<small class="text-muted">' + totalCount + ' معاملة' + (totalPages > 1 ? ' — صفحة ' + currentPage + ' من ' + totalPages : '') + '</small>';
            paginationEl.innerHTML = html;
        }
    }

    function submitWalletsControlForm(form, btn) {
        var origHtml = btn ? btn.innerHTML : '';
        if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>جاري الحفظ...'; }
        var fd = new FormData(form);
        var url = window.location.href;
        fetch(url, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd, credentials: 'same-origin' })
            .then(function(r) { return r.json().then(function(j) { return { ok: r.ok, json: j }; }); })
            .then(function(res) {
                var d = res.json;
                showAlert(d.message || (d.success ? 'تمت العملية بنجاح.' : 'حدث خطأ.'), d.success);
                if (d.success) {
                    applyResponse(d);
                    form.reset();
                    var cardWrap = form.closest('.reject-card-wrap');
                    if (cardWrap) cardWrap.classList.add('d-none');
                    var modal = form.closest('.modal');
                    if (modal && typeof window.bootstrap !== 'undefined') {
                        var bsModal = bootstrap.Modal.getInstance(modal);
                        if (bsModal) bsModal.hide();
                    }
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
                var ensureLoadingHidden = function() {
                    if (typeof window.resetPageLoading === 'function') {
                        window.resetPageLoading();
                    } else if (typeof window.hidePageLoading === 'function') {
                        window.hidePageLoading();
                    }
                };
                setTimeout(ensureLoadingHidden, 350);
            });
    }

    function bindWalletsControlForms() {
        document.querySelectorAll('form[data-wallets-control-ajax]').forEach(function(form) {
            if (form._walletsControlBound) return;
            form._walletsControlBound = true;
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                var btn = form.querySelector('button[type="submit"]');
                submitWalletsControlForm(form, btn);
            });
        });
    }
    // فتح/إغلاق بطاقة الرفض (بدون مودال أو باكدروب)
    document.addEventListener('click', function(e) {
        var toggleBtn = e.target.closest('[data-reject-toggle]');
        if (toggleBtn) {
            var id = toggleBtn.getAttribute('data-reject-card-id');
            if (id) {
                var cardWrap = document.getElementById(id);
                if (cardWrap) cardWrap.classList.toggle('d-none');
            }
            return;
        }
        var closeBtn = e.target.closest('[data-reject-card-close]');
        if (closeBtn) {
            var wrap = closeBtn.closest('.reject-card-wrap');
            if (wrap) wrap.classList.add('d-none');
        }
    });
    bindWalletsControlForms();

    var openApproveAllCardBtn = document.getElementById('wallets-control-open-approve-all-card-btn');
    var approveAllUserCard = document.getElementById('wallets-control-approve-all-user-card');
    var approveAllUserSelect = document.getElementById('wallets-control-approve-all-user-select');
    var approveAllUserBtn = document.getElementById('wallets-control-approve-all-user-btn');

    if (openApproveAllCardBtn && approveAllUserCard) {
        openApproveAllCardBtn.addEventListener('click', function() {
            var bsCollapse = typeof window.bootstrap !== 'undefined' && window.bootstrap.Collapse ? new bootstrap.Collapse(approveAllUserCard, { toggle: true }) : null;
            if (!bsCollapse) approveAllUserCard.classList.toggle('show');
        });
    }

    if (approveAllUserBtn && approveAllUserSelect) {
        approveAllUserBtn.addEventListener('click', function() {
            var userId = approveAllUserSelect.value || '';
            if (!userId) {
                showAlert('يرجى اختيار المستخدم أولاً.', false);
                return;
            }
            if (!confirm('الموافقة على جميع طلبات التحصيل المعلقة لهذا المستخدم؟ سيتم خصم المبالغ من رصيد العملاء وإضافتها لخزنة الشركة ومحفظة المستخدم.')) return;
            var fd = new FormData();
            fd.append('action', 'approve_all_pending_for_user');
            fd.append('user_id', userId);
            var origHtml = approveAllUserBtn.innerHTML;
            approveAllUserBtn.disabled = true;
            approveAllUserBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>جاري الموافقة...';
            fetch(window.location.href, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd, credentials: 'same-origin' })
                .then(function(r) { return r.json().then(function(j) { return { ok: r.ok, json: j }; }); })
                .then(function(res) {
                    var d = res.json;
                    showAlert(d.message || (d.success ? 'تمت العملية بنجاح.' : 'حدث خطأ.'), d.success);
                    if (d.success) {
                        applyResponse(d);
                        var opt = approveAllUserSelect.querySelector('option[value="' + userId + '"]');
                        if (opt) opt.remove();
                        approveAllUserSelect.value = '';
                        if (approveAllUserSelect.options.length <= 1) {
                            if (typeof window.bootstrap !== 'undefined' && window.bootstrap.Collapse && approveAllUserCard) {
                                var c = bootstrap.Collapse.getInstance(approveAllUserCard);
                                if (c) c.hide();
                            } else if (approveAllUserCard) approveAllUserCard.classList.remove('show');
                        }
                        var pendingCard = document.getElementById('wallets-control-pending-card');
                        if (pendingCard && d.pending_requests && d.pending_requests.length === 0) pendingCard.classList.add('d-none');
                        if (typeof window.hidePageLoading === 'function') window.hidePageLoading();
                    }
                })
                .catch(function() {
                    showAlert('حدث خطأ في الاتصال. يرجى المحاولة مرة أخرى.', false);
                })
                .finally(function() {
                    approveAllUserBtn.disabled = false;
                    approveAllUserBtn.innerHTML = origHtml;
                });
        });
    }
    // فلترة سجل المعاملات
    var filterForm = document.getElementById('wallets-tx-filter-form');
    var filterResetBtn = document.getElementById('wallets-tx-filter-reset');
    if (filterForm) {
        filterForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var params = new URLSearchParams(window.location.search);
            var fd = new FormData(filterForm);
            // مسح الفلاتر القديمة
            ['filter_date_from','filter_date_to','filter_type','filter_reason','filter_amount_from','filter_amount_to'].forEach(function(k) { params.delete(k); });
            fd.forEach(function(val, key) { if (val !== '') params.set(key, val); });
            params.set('tx_page', '1');
            window.location.search = params.toString();
        });
    }
    if (filterResetBtn) {
        filterResetBtn.addEventListener('click', function() {
            var params = new URLSearchParams(window.location.search);
            ['filter_date_from','filter_date_to','filter_type','filter_reason','filter_amount_from','filter_amount_to','tx_page'].forEach(function(k) { params.delete(k); });
            window.location.search = params.toString();
        });
    }
})();
</script>
