<?php
/**
 * نظام الإشعارات
 */

// منع الوصول المباشر
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/telegram_notifications.php';
require_once __DIR__ . '/path_helper.php';
if (!function_exists('getOfficialWorkTime')) {
    require_once __DIR__ . '/attendance.php';
}

if (!defined('NOTIFICATIONS_MAX_ROWS')) {
    define('NOTIFICATIONS_MAX_ROWS', 200);
}

if (!defined('ATTENDANCE_NOTIFICATION_COOLDOWN_MINUTES')) {
    define('ATTENDANCE_NOTIFICATION_COOLDOWN_MINUTES', 120);
}

if (!defined('ATTENDANCE_NOTIFICATION_CACHE_TTL')) {
    define('ATTENDANCE_NOTIFICATION_CACHE_TTL', 86400); // 24 hours
}

if (!defined('ATTENDANCE_NOTIFICATION_CACHE_PREFIX')) {
    define('ATTENDANCE_NOTIFICATION_CACHE_PREFIX', 'attendance_notif:');
}

/**
 * تم إزالة نظام الجلسات - استخدام cache فقط لتذكيرات الحضور
 */
function getAttendanceSessionKey(int $userId, string $kind): string
{
    $today = date('Y-m-d');
    return "attendance_notification_{$userId}_{$kind}_{$today}";
}

function sessionHasAttendanceNotification(int $userId, string $kind): bool
{
    // تم إزالة نظام الجلسات - استخدام cache فقط
    return false;
}

function sessionMarkAttendanceNotification(int $userId, string $kind): void
{
    // تم إزالة نظام الجلسات - لا حاجة للتنفيذ
}

function sessionClearAttendanceNotification(int $userId, string $kind): void
{
    // تم إزالة نظام الجلسات - لا حاجة للتنفيذ
}

/**
 * إنشاء إشعار جديد
 */
function createNotification($userId, $title, $message, $type = 'info', $link = null, $sendTelegram = false) {
    try {
        $db = db();
        
        // تنظيف Cache قبل إنشاء إشعار جديد (شريط الإشعارات يطلب limit=10 فيجب مسح كل الحدود)
        if (class_exists('Cache')) {
            Cache::forget("notification_count_{$userId}");
            for ($limit = 10; $limit <= 100; $limit += 10) {
                Cache::forget("notifications_{$userId}_all_{$limit}");
                Cache::forget("notifications_{$userId}_unread_{$limit}");
            }
        }
        
        $sql = "INSERT INTO notifications (user_id, title, message, type, link) 
                VALUES (?, ?, ?, ?, ?)";
        
        $db->execute($sql, [
            $userId,
            $title,
            $message,
            $type,
            $link
        ]);

        pruneNotificationsIfNeeded($db);
        
        // إرسال إشعار Telegram إذا كان مفعّل
        if ($sendTelegram && isTelegramConfigured()) {
            $user = $db->queryOne("SELECT role FROM users WHERE id = ?", [$userId]);
            if ($user) {
                $telegramMessage = "📢 <b>{$title}</b>\n\n{$message}";
                if ($link) {
                    $telegramMessage .= "\n\n🔗 رابط: {$link}";
                }
                sendTelegramNotificationByRole($user['role'], $telegramMessage, $type);
            }
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Notification Error: " . $e->getMessage());
        return false;
    }
}

/**
 * إنشاء إشعار لجميع المستخدمين بدور معين
 */
function createNotificationForRole($role, $title, $message, $type = 'info', $link = null, $sendTelegram = false) {
    try {
        $db = db();
        
        // التأكد من أن الاستعلام يطابق الدور فقط مع التحقق الإضافي
        $users = $db->query("SELECT id, role FROM users WHERE role = ? AND status = 'active'", [$role]);
        
        foreach ($users as $user) {
            // التحقق الإضافي من تطابق الدور لمنع أي خلط في البيانات
            if (!isset($user['role']) || $user['role'] !== $role) {
                error_log("Warning: User ID {$user['id']} has role mismatch. Expected: {$role}, Found: " . ($user['role'] ?? 'NULL'));
                continue; // تخطي هذا المستخدم
            }
            
            createNotification($user['id'], $title, $message, $type, $link, false);
        }
        
        // إرسال إشعار Telegram للدور إذا كان مفعّل
        if ($sendTelegram && isTelegramConfigured()) {
            $telegramMessage = "📢 <b>{$title}</b>\n\n{$message}";
            if ($link) {
                $telegramMessage .= "\n\n🔗 رابط: {$link}";
            }
            sendTelegramNotificationByRole($role, $telegramMessage, $type);
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Notification Error: " . $e->getMessage());
        return false;
    }
}

/**
 * إنشاء إشعار لجميع المديرين
 */
function notifyManagers($title, $message, $type = 'info', $link = null, $sendTelegram = true) {
    return createNotificationForRole('manager', $title, $message, $type, $link, $sendTelegram);
}

/**
 * تقليم جدول الإشعارات عند تجاوز الحد
 */
function pruneNotificationsIfNeeded($db, $threshold = NOTIFICATIONS_MAX_ROWS) {
    if (!$db) {
        return;
    }

    try {
        $countRow = $db->queryOne("SELECT COUNT(*) as total FROM notifications");
        $total = isset($countRow['total']) ? (int)$countRow['total'] : 0;

        if ($total >= $threshold) {
            $target = max($threshold - 1, 0);
            $excess = $total - $target;
            if ($excess < 1) {
                $excess = 1;
            }

            $db->execute(
                "DELETE FROM notifications ORDER BY created_at ASC LIMIT " . (int)$excess
            );
        }
    } catch (Exception $e) {
        error_log("Notification pruning error: " . $e->getMessage());
    }
}

/**
 * الحصول على إشعارات المستخدم (مع Cache محسّن)
 */
function getUserNotifications($userId, $unreadOnly = false, $limit = 50) {
    // استخدام Cache لتسريع الاستعلامات
    if (!class_exists('Cache')) {
        $cacheFile = __DIR__ . '/cache.php';
        if (file_exists($cacheFile)) {
            require_once $cacheFile;
        }
    }
    
    // إنشاء مفتاح Cache فريد
    $cacheKey = "notifications_{$userId}_" . ($unreadOnly ? 'unread' : 'all') . "_{$limit}";
    
    // محاولة الحصول من Cache أولاً (TTL = 15 ثانية)
    if (class_exists('Cache')) {
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }
    }
    
    $db = db();
    
    $sql = "SELECT * FROM notifications 
            WHERE user_id = ?";
    
    if ($unreadOnly) {
        $sql .= " AND `read` = 0";
    }
    
    $sql .= " ORDER BY created_at DESC LIMIT ?";
    
    $notifications = $db->query($sql, [$userId, $limit]);
    
    // حفظ في Cache لمدة 15 ثانية
    if (class_exists('Cache') && $notifications !== null) {
        Cache::put($cacheKey, $notifications, 15);
    }
    
    return $notifications;
}

/**
 * الحصول على عدد الإشعارات غير المقروءة (مع Cache محسّن)
 */
function getUnreadNotificationCount($userId) {
    // استخدام Cache لتسريع الاستعلامات
    if (!class_exists('Cache')) {
        $cacheFile = __DIR__ . '/cache.php';
        if (file_exists($cacheFile)) {
            require_once $cacheFile;
        }
    }
    
    // إنشاء مفتاح Cache فريد
    $cacheKey = "notification_count_{$userId}";
    
    // محاولة الحصول من Cache أولاً (TTL = 10 ثانية)
    if (class_exists('Cache')) {
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }
    }
    
    $db = db();
    
    $result = $db->queryOne(
        "SELECT COUNT(*) as count FROM notifications 
         WHERE user_id = ? AND `read` = 0",
        [$userId]
    );
    
    $count = $result['count'] ?? 0;
    
    // حفظ في Cache لمدة 10 ثواني
    if (class_exists('Cache')) {
        Cache::put($cacheKey, $count, 10);
    }
    
    return $count;
}

/**
 * الحصول على عدد الطلبات الجديدة غير المقروءة للمندوب
 */
function getNewOrdersCount($salesRepId) {
    $db = db();
    
    // حساب الإشعارات غير المقروءة المرتبطة بالطلبات
    $result = $db->queryOne(
        "SELECT COUNT(*) as count
         FROM notifications n
         WHERE n.user_id = ?
            AND n.`read` = 0
            AND n.type = 'info'
            AND n.title IN ('طلب جديد', 'طلب شركة جديد')
            AND n.link LIKE '%sales.php?page=orders%'
            AND n.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
        [$salesRepId]
    );
    
    return $result['count'] ?? 0;
}

/**
 * تحديد إشعار كمقروء (مع تنظيف Cache)
 */
function markNotificationAsRead($notificationId, $userId) {
    $db = db();
    
    $db->execute(
        "UPDATE notifications SET `read` = 1 
         WHERE id = ? AND user_id = ?",
        [$notificationId, $userId]
    );
    
    // تنظيف Cache بعد التحديث
    if (class_exists('Cache')) {
        Cache::forget("notifications_{$userId}_all_50");
        Cache::forget("notifications_{$userId}_unread_50");
        Cache::forget("notification_count_{$userId}");
    }
    
    return true;
}

/**
 * تحديد جميع الإشعارات كمقروءة (مع تنظيف Cache)
 */
function markAllNotificationsAsRead($userId) {
    $db = db();
    
    $db->execute(
        "UPDATE notifications SET `read` = 1 
         WHERE user_id = ? AND `read` = 0",
        [$userId]
    );
    
    // تنظيف Cache بعد التحديث
    if (class_exists('Cache')) {
        Cache::forget("notifications_{$userId}_all_50");
        Cache::forget("notifications_{$userId}_unread_50");
        Cache::forget("notification_count_{$userId}");
    }
    
    return true;
}

/**
 * حذف إشعار (مع تنظيف Cache)
 */
function deleteNotification($notificationId, $userId) {
    $db = db();
    
    $db->execute(
        "DELETE FROM notifications WHERE id = ? AND user_id = ?",
        [$notificationId, $userId]
    );
    
    // تنظيف Cache بعد الحذف
    if (class_exists('Cache')) {
        Cache::forget("notifications_{$userId}_all_50");
        Cache::forget("notifications_{$userId}_unread_50");
        Cache::forget("notification_count_{$userId}");
    }
    
    return true;
}

/**
 * حذف جميع إشعارات المستخدم
 */
function deleteAllNotifications($userId) {
    $db = db();
    
    $db->execute(
        "DELETE FROM notifications WHERE user_id = ?",
        [$userId]
    );
    
    // تنظيف Cache بعد حذف جميع الإشعارات
    if (!class_exists('Cache')) {
        $cacheFile = __DIR__ . '/cache.php';
        if (file_exists($cacheFile)) {
            require_once $cacheFile;
        }
    }
    
    if (class_exists('Cache')) {
        // حذف جميع مفاتيح cache المتعلقة بالإشعارات
        try {
            Cache::forget("notification_count_{$userId}");
            Cache::forget("notifications_{$userId}_all_50");
            Cache::forget("notifications_{$userId}_unread_50");
            // محاولة حذف cache بأعداد مختلفة
            for ($limit = 10; $limit <= 100; $limit += 10) {
                Cache::forget("notifications_{$userId}_all_{$limit}");
                Cache::forget("notifications_{$userId}_unread_{$limit}");
            }
        } catch (Exception $e) {
            error_log("Error clearing notification cache: " . $e->getMessage());
        }
    }
    
    return true;
}

/**
 * إرسال إشعار متصفح (Browser Notification)
 */
function sendBrowserNotification($title, $body, $icon = null, $tag = null) {
    // يتم إرسال إشعارات المتصفح عبر JavaScript
    // هذه الدالة للإشارة فقط
    return true;
}

/**
 * الحصول على رابط صفحة الحضور المناسب للدور
 */
function getAttendanceReminderLink($role) {
    $dashboardUrl = getDashboardUrl($role);
    $separator = strpos($dashboardUrl, '?') === false ? '?' : '&';
    return $dashboardUrl . $separator . 'page=attendance';
}

/**
 * إنشاء أو تحديث تذكير الحضور/الانصراف
 */
function ensureAttendanceReminderForUser($userId, $role, $kind, $title, $message) {
    if (!$userId || !in_array($kind, ['checkin', 'checkout'], true)) {
        return false;
    }

    if (hasAttendanceNotificationBeenSentToday((int)$userId, $kind)) {
        return false;
    }

    $db = db();
    $type = 'attendance_' . $kind;
    $link = getAttendanceReminderLink($role);

    $existing = $db->queryOne(
        "SELECT id, `read`, created_at FROM notifications WHERE user_id = ? AND type = ? AND DATE(created_at) = CURDATE() ORDER BY created_at DESC LIMIT 1",
        [$userId, $type]
    );

    $startReference = null;
    $endReference = null;
    $workTime = getOfficialWorkTime($userId);
    if (!empty($workTime['start']) && !empty($workTime['end'])) {
        $today = date('Y-m-d');
        $startReference = DateTime::createFromFormat('Y-m-d H:i:s', "{$today} {$workTime['start']}");
        $endReference = DateTime::createFromFormat('Y-m-d H:i:s', "{$today} {$workTime['end']}");
    }

    if ($existing) {
        $notificationId = (int) ($existing['id'] ?? 0);
        $isUnread = isset($existing['read']) ? ((int) $existing['read'] === 0) : true;
        $lastCreatedAt = null;

        if (!empty($existing['created_at'])) {
            $lastCreatedAt = DateTime::createFromFormat('Y-m-d H:i:s', $existing['created_at']);
        }

        $shouldReactivate = shouldReactivateAttendanceNotification($kind, $isUnread, $lastCreatedAt, $startReference, $endReference);
        $setParts = ['title = ?', 'message = ?', 'link = ?'];
        $params = [$title, $message, $link];

        if ($shouldReactivate) {
            $setParts[] = "`read` = 0";
            $setParts[] = "created_at = NOW()";
        }

        $params[] = $notificationId;

        $db->execute(
            "UPDATE notifications SET " . implode(', ', $setParts) . " WHERE id = ?",
            $params
        );

        if ($shouldReactivate || !$lastCreatedAt || $lastCreatedAt->format('Y-m-d') !== date('Y-m-d')) {
            markAttendanceNotificationSent((int)$userId, $kind);
        }

        return $notificationId;
    }

    $sent = createNotification($userId, $title, $message, $type, $link, false);
    if ($sent) {
        markAttendanceNotificationSent((int)$userId, $kind);
    }

    return $sent;
}

/**
 * التحقق مما إذا تم إرسال إشعار الحضور للمستخدم اليوم
 */
function hasAttendanceNotificationBeenSentToday(int $userId, string $kind): bool
{
    if (function_exists('cache_get')) {
        $cacheKey = ATTENDANCE_NOTIFICATION_CACHE_PREFIX . $userId . ':' . $kind;
        $cached = cache_get($cacheKey);
        $today = date('Y-m-d');
        if ($cached) {
            if ($cached === $today) {
                return true;
            }
            cache_delete($cacheKey);
        }
    }

    if (sessionHasAttendanceNotification($userId, $kind)) {
        return true;
    }

    $db = db();
    $type = 'attendance_' . $kind;
    $today = date('Y-m-d');

    $notificationExists = $db->queryOne(
        "SELECT id FROM notifications WHERE user_id = ? AND type = ? AND DATE(created_at) = ? LIMIT 1",
        [$userId, $type, $today]
    );

    if (!empty($notificationExists)) {
        if (function_exists('cache_set')) {
            cache_set(ATTENDANCE_NOTIFICATION_CACHE_PREFIX . $userId . ':' . $kind, $today, ATTENDANCE_NOTIFICATION_CACHE_TTL);
        }
        return true;
    }

    $logTableExists = $db->queryOne("SHOW TABLES LIKE 'attendance_notification_logs'");
    if (empty($logTableExists)) {
        return false;
    }

    $logExists = $db->queryOne(
        "SELECT id FROM attendance_notification_logs WHERE user_id = ? AND notification_kind = ? AND sent_date = ? LIMIT 1",
        [$userId, $kind, $today]
    );

    if (!empty($logExists)) {
        if (function_exists('cache_set')) {
            cache_set(ATTENDANCE_NOTIFICATION_CACHE_PREFIX . $userId . ':' . $kind, $today, ATTENDANCE_NOTIFICATION_CACHE_TTL);
        }
        sessionMarkAttendanceNotification($userId, $kind);
        return true;
    }

    return false;
}

/**
 * تسجيل إرسال إشعار الحضور للمستخدم
 */
function markAttendanceNotificationSent(int $userId, string $kind): void
{
    $db = db();
    $today = date('Y-m-d');

    try {
        $logTableExists = $db->queryOne("SHOW TABLES LIKE 'attendance_notification_logs'");
        if (empty($logTableExists)) {
            $db->execute("
                CREATE TABLE IF NOT EXISTS `attendance_notification_logs` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `user_id` int(11) NOT NULL,
                  `notification_kind` enum('checkin','checkout') NOT NULL,
                  `sent_date` date NOT NULL,
                  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `user_kind_date` (`user_id`,`notification_kind`,`sent_date`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        $existingLog = $db->queryOne(
            "SELECT id FROM attendance_notification_logs WHERE user_id = ? AND notification_kind = ? AND sent_date = ? LIMIT 1",
            [$userId, $kind, $today]
        );

        if (empty($existingLog)) {
            $db->execute(
                "INSERT INTO attendance_notification_logs (user_id, notification_kind, sent_date) VALUES (?, ?, ?)",
                [$userId, $kind, $today]
            );
        }
    } catch (Exception $e) {
        error_log('Attendance notification log error: ' . $e->getMessage());
    }

    if (function_exists('cache_set')) {
        $secondsUntilTomorrow = strtotime('tomorrow') - time();
        $ttl = max(60, $secondsUntilTomorrow);
        cache_set(ATTENDANCE_NOTIFICATION_CACHE_PREFIX . $userId . ':' . $kind, $today, $ttl);
    }

    sessionMarkAttendanceNotification($userId, $kind);
}

/**
 * إزالة سجل إشعار الحضور للمستخدم (لنفس اليوم)
 */
function clearAttendanceNotificationLog(int $userId, string $kind): void
{
    $db = db();
    $today = date('Y-m-d');

    try {
        $db->execute(
            "DELETE FROM attendance_notification_logs WHERE user_id = ? AND notification_kind = ? AND sent_date = ?",
            [$userId, $kind, $today]
        );
    } catch (Exception $e) {
        error_log('Clear attendance notification log error: ' . $e->getMessage());
    }

    if (function_exists('cache_delete')) {
        cache_delete(ATTENDANCE_NOTIFICATION_CACHE_PREFIX . $userId . ':' . $kind);
    }

    sessionClearAttendanceNotification($userId, $kind);
}

/**
 * تحديد هل يجب إعادة تفعيل إشعار الحضور/الانصراف
 */
function shouldReactivateAttendanceNotification(
    string $kind,
    bool $isUnread,
    ?DateTime $lastCreatedAt,
    ?DateTime $startTime,
    ?DateTime $endTime
): bool {
    if ($isUnread) {
        return false;
    }

    $now = new DateTime('now');
    $cooldownMinutes = ATTENDANCE_NOTIFICATION_COOLDOWN_MINUTES;
    $minutesSinceLast = null;

    if ($lastCreatedAt instanceof DateTime) {
        $minutesSinceLast = floor(($now->getTimestamp() - $lastCreatedAt->getTimestamp()) / 60);
        if ($minutesSinceLast < $cooldownMinutes) {
            return false;
        }
    }

    if ($kind === 'checkin') {
        if (!$startTime instanceof DateTime || $now < (clone $startTime)->modify('-60 minutes')) {
            return false;
        }
        if ($minutesSinceLast === null) {
            return true;
        }
        return $minutesSinceLast >= $cooldownMinutes;
    }

    if ($kind === 'checkout') {
        if (!$endTime instanceof DateTime) {
            return false;
        }

        $windowStart = clone $endTime;
        $windowEnd = (clone $endTime)->modify('+5 minutes');

        if ($now < $windowStart || $now > $windowEnd) {
            return false;
        }

        if ($minutesSinceLast === null) {
            return true;
        }
        return $minutesSinceLast >= $cooldownMinutes;
    }

    return false;
}

/**
 * إزالة تذكير الحضور/الانصراف للمستخدم
 */
function clearAttendanceReminderForUser($userId, $kind) {
    if (!$userId || !in_array($kind, ['checkin', 'checkout'], true)) {
        return false;
    }

    $db = db();
    $type = 'attendance_' . $kind;

    $db->execute(
        "DELETE FROM notifications WHERE user_id = ? AND type = ?",
        [$userId, $type]
    );

    return true;
}

/**
 * التعامل مع تذكيرات الحضور/الانصراف للمستخدم الحالي
 */
function handleAttendanceRemindersForUser($user) {
    if (empty($user) || empty($user['id']) || empty($user['role'])) {
        return;
    }

    $role = $user['role'];
    if (!in_array($role, ['production', 'sales', 'accountant'], true)) {
        return;
    }

    $userId = (int) $user['id'];
    $workTime = getOfficialWorkTime($userId);
    if (!$workTime || empty($workTime['start']) || empty($workTime['end'])) {
        return;
    }

    $now = new DateTime('now');
    $today = $now->format('Y-m-d');

    $startTime = DateTime::createFromFormat('Y-m-d H:i:s', "{$today} {$workTime['start']}");
    $endTime = DateTime::createFromFormat('Y-m-d H:i:s', "{$today} {$workTime['end']}");

    if (!$startTime || !$endTime) {
        return;
    }

    $checkInRecords = getTodayAttendanceRecords($userId, $today);
    $hasCheckIn = !empty($checkInRecords);
    $hasOpenAttendance = false;
    $openAttendanceCheckInTime = null;

    foreach ($checkInRecords as $record) {
        if (empty($record['check_out_time'])) {
            $hasOpenAttendance = true;
            if (!empty($record['check_in_time']) && !$openAttendanceCheckInTime) {
                try {
                    $openAttendanceCheckInTime = new DateTime($record['check_in_time']);
                } catch (Exception $e) {
                    $openAttendanceCheckInTime = null;
                }
            }
            break;
        }
    }

    // تذكير تسجيل الحضور
    $checkInReminderThreshold = (clone $startTime)->modify('-15 minutes');
    if (!$hasCheckIn && $now >= $checkInReminderThreshold) {
        $title = 'تنبيه تسجيل الحضور';
        $message = 'تنبيه هام لتسجيل الحضور لتفادي الخصومات. يرجى تسجيل الحضور الآن.';
        ensureAttendanceReminderForUser($userId, $role, 'checkin', $title, $message);
    } else {
        clearAttendanceReminderForUser($userId, 'checkin');
    }

    // تذكير تسجيل الانصراف
    $nowTimestamp = $now->getTimestamp();
    $checkoutStart = $endTime->getTimestamp();
    $checkoutEnd = $checkoutStart + 300; // +5 دقائق
    $eligibleForCheckoutReminder = $hasOpenAttendance && $nowTimestamp >= $checkoutStart && $nowTimestamp <= $checkoutEnd;

    if ($eligibleForCheckoutReminder && $openAttendanceCheckInTime instanceof DateTime) {
        $minutesSinceCheckIn = floor(($nowTimestamp - $openAttendanceCheckInTime->getTimestamp()) / 60);
        $minimumSessionMinutes = 30;
        if ($minutesSinceCheckIn < $minimumSessionMinutes) {
            $eligibleForCheckoutReminder = false;
        }
    }

    if ($eligibleForCheckoutReminder) {
        $title = 'تنبيه تسجيل الانصراف';
        $message = 'تنبيه هام لتسجيل الانصراف لتفادي الخصومات. يرجى تسجيل الانصراف في موعده الآن.';
        ensureAttendanceReminderForUser($userId, $role, 'checkout', $title, $message);
    } elseif ($nowTimestamp > $checkoutEnd || !$hasOpenAttendance) {
        clearAttendanceReminderForUser($userId, 'checkout');
    }
}

