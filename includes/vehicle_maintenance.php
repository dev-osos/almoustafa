<?php
/**
 * نظام صيانات السيارة - تغيير الزيت وتفويل البنزين
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

/**
 * التأكد من وجود جدول vehicle_maintenance
 */
function ensureVehicleMaintenanceTable() {
    static $ensured = false;
    if ($ensured) {
        return;
    }
    try {
        $db = db();
        $exists = $db->queryOne("SHOW TABLES LIKE 'vehicle_maintenance'");
        if (empty($exists)) {
            $db->execute("
                CREATE TABLE IF NOT EXISTS vehicle_maintenance (
                  id int(11) NOT NULL AUTO_INCREMENT,
                  vehicle_id int(11) NOT NULL,
                  driver_id int(11) NOT NULL,
                  type enum('oil_change','fuel_refill') NOT NULL,
                  maintenance_date date NOT NULL,
                  km_reading int(11) NOT NULL,
                  km_diff int(11) DEFAULT NULL,
                  fuel_amount decimal(10,2) DEFAULT NULL,
                  photo_path varchar(500) NOT NULL,
                  notes text DEFAULT NULL,
                  created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (id),
                  KEY vehicle_id (vehicle_id),
                  KEY driver_id (driver_id),
                  KEY type (type),
                  KEY maintenance_date (maintenance_date)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } else {
            $hasFuelAmount = $db->queryOne("SHOW COLUMNS FROM vehicle_maintenance LIKE 'fuel_amount'");
            if (empty($hasFuelAmount)) {
                $db->execute("ALTER TABLE vehicle_maintenance ADD COLUMN fuel_amount decimal(10,2) DEFAULT NULL AFTER km_diff");
            }
        }
        $ensured = true;
    } catch (Throwable $e) {
        error_log('ensureVehicleMaintenanceTable: ' . $e->getMessage());
    }
}

/**
 * حفظ صورة الصيانة من base64
 * @return array [absolutePath, relativePath] أو [null, null] عند الفشل
 */
function saveMaintenancePhoto($photoBase64, $driverId, $type) {
    $photoBase64 = is_string($photoBase64) ? trim($photoBase64) : '';
    if ($photoBase64 === '') {
        return [null, null];
    }
    $cleanData = preg_replace('#^data:image/\w+;base64,#i', '', $photoBase64);
    $cleanData = str_replace(' ', '+', $cleanData);
    $mod = strlen($cleanData) % 4;
    if ($mod > 0) {
        $cleanData .= str_repeat('=', 4 - $mod);
    }
    $imageData = base64_decode($cleanData, true);
    if ($imageData === false) {
        return [null, null];
    }
    $uploadsRoot = realpath(__DIR__ . '/../uploads');
    if ($uploadsRoot === false) {
        $uploadsRoot = __DIR__ . '/../uploads';
    }
    $maintenanceDir = $uploadsRoot . DIRECTORY_SEPARATOR . 'vehicle_maintenance';
    if (!is_dir($maintenanceDir)) {
        if (!@mkdir($maintenanceDir, 0755, true)) {
            return [null, null];
        }
    }
    $monthFolder = date('Y-m');
    $targetDir = $maintenanceDir . DIRECTORY_SEPARATOR . $monthFolder;
    if (!is_dir($targetDir)) {
        if (!@mkdir($targetDir, 0755, true)) {
            return [null, null];
        }
    }
    try {
        $randomSuffix = bin2hex(random_bytes(4));
    } catch (Exception $e) {
        $randomSuffix = uniqid();
    }
    $fileName = sprintf('%s_%d_%s_%s.jpg', $type, $driverId, date('Ymd_His'), $randomSuffix);
    $absolutePath = $targetDir . DIRECTORY_SEPARATOR . $fileName;
    $bytesWritten = @file_put_contents($absolutePath, $imageData, LOCK_EX);
    if ($bytesWritten === false || $bytesWritten === 0) {
        return [null, null];
    }
    $relativePath = 'vehicle_maintenance' . '/' . $monthFolder . '/' . $fileName;
    return [$absolutePath, $relativePath];
}

/**
 * الحصول على المسار الكامل لصورة الصيانة
 */
function getMaintenancePhotoAbsolutePath($relativePath) {
    if (!$relativePath) {
        return null;
    }
    $relativePath = ltrim(str_replace(['\\', '..'], ['/', ''], $relativePath), '/');
    $uploadsRoot = realpath(__DIR__ . '/../uploads');
    if ($uploadsRoot === false) {
        $uploadsRoot = rtrim(__DIR__ . '/../uploads', DIRECTORY_SEPARATOR);
    }
    $fullPath = $uploadsRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $realFullPath = @realpath($fullPath);
    if ($realFullPath !== false && is_file($realFullPath)) {
        $base = realpath($uploadsRoot) ?: str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $uploadsRoot);
        if (strpos($realFullPath, $base) === 0) {
            return $realFullPath;
        }
    }
    if (is_file($fullPath)) {
        return $fullPath;
    }
    return null;
}

/**
 * الحصول على سيارة السائق الحالي
 */
function getDriverVehicle($driverId) {
    $db = db();
    return $db->queryOne(
        "SELECT v.id, v.vehicle_number, v.model, v.vehicle_type
         FROM vehicles v
         WHERE v.driver_id = ? AND v.status = 'active'
         LIMIT 1",
        [$driverId]
    );
}

/**
 * حفظ سجل صيانة جديد
 * @param string|null $photoAbsolutePath مسار الملف المطلق (إن وُجد) لاستخدامه في إرسال تليجرام
 * @param float|null $fuelAmount مبلغ التفويل (لتفويل البنزين فقط)
 * @return array ['success' => bool, 'message' => string, 'id' => int|null]
 */
function saveVehicleMaintenance($vehicleId, $driverId, $type, $kmReading, $photoPath, $notes = null, $photoAbsolutePath = null, $fuelAmount = null) {
    ensureVehicleMaintenanceTable();
    $db = db();

    $maintenanceDate = date('Y-m-d');
    $kmDiff = null;

    // جلب آخر سجل لنفس السيارة ونفس النوع
    $lastRecord = $db->queryOne(
        "SELECT km_reading FROM vehicle_maintenance
         WHERE vehicle_id = ? AND type = ?
         ORDER BY maintenance_date DESC, id DESC LIMIT 1",
        [$vehicleId, $type]
    );
    if ($lastRecord && isset($lastRecord['km_reading'])) {
        $lastKm = (int) $lastRecord['km_reading'];
        $currentKm = (int) $kmReading;
        if ($currentKm > $lastKm) {
            $kmDiff = $currentKm - $lastKm;
        }
    }

    $fuelAmountValue = ($type === 'fuel_refill' && $fuelAmount !== null && $fuelAmount !== '') ? (float) $fuelAmount : null;
    $db->execute(
        "INSERT INTO vehicle_maintenance (vehicle_id, driver_id, type, maintenance_date, km_reading, km_diff, fuel_amount, photo_path, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [$vehicleId, $driverId, $type, $maintenanceDate, (int) $kmReading, $kmDiff, $fuelAmountValue, $photoPath, $notes]
    );
    $id = $db->getLastInsertId();

    // إرسال تنبيه تليجرام (مع الصورة إن وُجدت)
    $sendResult = sendMaintenanceToTelegram($id, $vehicleId, $driverId, $type, $maintenanceDate, $kmReading, $kmDiff, $photoPath, $notes, $photoAbsolutePath);

    return [
        'success' => true,
        'message' => 'تم تسجيل الصيانة بنجاح',
        'id' => $id,
        'km_diff' => $kmDiff,
        'telegram_sent' => $sendResult,
    ];
}

/**
 * إرسال تنبيه الصيانة إلى تليجرام (مع الصورة إن وُجدت)
 * @param string|null $photoAbsolutePath مسار الملف المطلق للصورة (يُفضّل تمريره من الـ API)
 */
function sendMaintenanceToTelegram($maintenanceId, $vehicleId, $driverId, $type, $maintenanceDate, $kmReading, $kmDiff, $photoPath, $notes = null, $photoAbsolutePath = null) {
    if (!function_exists('isTelegramConfigured') || !isTelegramConfigured()) {
        return false;
    }
    require_once __DIR__ . '/simple_telegram.php';
    require_once __DIR__ . '/path_helper.php';
    if (!function_exists('formatDate')) {
        require_once __DIR__ . '/config.php';
    }

    $db = db();
    $vehicle = $db->queryOne("SELECT vehicle_number FROM vehicles WHERE id = ?", [$vehicleId]);
    $driver = $db->queryOne("SELECT full_name, username FROM users WHERE id = ?", [$driverId]);

    $vehicleNumber = $vehicle['vehicle_number'] ?? 'غير محدد';
    $driverName = $driver['full_name'] ?? $driver['username'] ?? 'غير محدد';

    $formattedDate = function_exists('formatDate') ? formatDate($maintenanceDate) : $maintenanceDate;

    if ($type === 'oil_change') {
        $title = '🛢️ تغيير زيت جديد';
    } else {
        $title = '⛽ تفويل بنزين جديد';
    }

    $caption = $title . "\n\n";
    $caption .= "🚗 <b>السيارة:</b> " . htmlspecialchars($vehicleNumber) . "\n";
    $caption .= "👤 <b>السائق:</b> " . htmlspecialchars($driverName) . "\n";
    $caption .= "📅 <b>التاريخ:</b> " . htmlspecialchars($formattedDate) . "\n";
    $caption .= "📏 <b>الكيلومترات:</b> " . number_format($kmReading) . " كم\n";
    if ($kmDiff !== null) {
        $caption .= "📐 <b>الفرق عن المرة السابقة:</b> " . number_format($kmDiff) . " كم\n";
    }
    if ($notes) {
        $caption .= "📝 <b>ملاحظات:</b> " . htmlspecialchars($notes) . "\n";
    }

    // استخدام المسار المطلق إن وُجد (من الـ API بعد حفظ الصورة)
    $absolutePath = $photoAbsolutePath;
    if (!$absolutePath || !file_exists($absolutePath)) {
        $absolutePath = getMaintenancePhotoAbsolutePath($photoPath);
    }
    if ($absolutePath && file_exists($absolutePath)) {
        $sent = sendTelegramPhoto($absolutePath, $caption, null, false);
        if ($sent) {
            return true;
        }
    }
    return sendTelegramMessage($caption);
}

/**
 * جلب سجلات الصيانات مع الفلترة
 */
function getVehicleMaintenanceRecords($filters = [], $limit = 100, $offset = 0) {
    ensureVehicleMaintenanceTable();
    $db = db();

    $where = ['1=1'];
    $params = [];

    if (!empty($filters['vehicle_id'])) {
        $where[] = 'vm.vehicle_id = ?';
        $params[] = $filters['vehicle_id'];
    }
    if (!empty($filters['driver_id'])) {
        $where[] = 'vm.driver_id = ?';
        $params[] = $filters['driver_id'];
    }
    if (!empty($filters['type'])) {
        $where[] = 'vm.type = ?';
        $params[] = $filters['type'];
    }
    if (!empty($filters['date_from'])) {
        $where[] = 'vm.maintenance_date >= ?';
        $params[] = $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $where[] = 'vm.maintenance_date <= ?';
        $params[] = $filters['date_to'];
    }

    $whereSql = implode(' AND ', $where);
    $params[] = $limit;
    $params[] = $offset;

    return $db->query(
        "SELECT vm.*, v.vehicle_number, v.model, u.full_name as driver_name, u.username as driver_username
         FROM vehicle_maintenance vm
         LEFT JOIN vehicles v ON vm.vehicle_id = v.id
         LEFT JOIN users u ON vm.driver_id = u.id
         WHERE {$whereSql}
         ORDER BY vm.maintenance_date DESC, vm.id DESC
         LIMIT ? OFFSET ?",
        $params
    );
}

/**
 * عدد سجلات الصيانات
 */
function countVehicleMaintenanceRecords($filters = []) {
    ensureVehicleMaintenanceTable();
    $db = db();

    $where = ['1=1'];
    $params = [];

    if (!empty($filters['vehicle_id'])) {
        $where[] = 'vehicle_id = ?';
        $params[] = $filters['vehicle_id'];
    }
    if (!empty($filters['driver_id'])) {
        $where[] = 'driver_id = ?';
        $params[] = $filters['driver_id'];
    }
    if (!empty($filters['type'])) {
        $where[] = 'type = ?';
        $params[] = $filters['type'];
    }
    if (!empty($filters['date_from'])) {
        $where[] = 'maintenance_date >= ?';
        $params[] = $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $where[] = 'maintenance_date <= ?';
        $params[] = $filters['date_to'];
    }

    $whereSql = implode(' AND ', $where);
    $row = $db->queryOne("SELECT COUNT(*) as total FROM vehicle_maintenance WHERE {$whereSql}", $params);
    return (int) ($row['total'] ?? 0);
}

/** نطاق الكيلومترات لتنبيه "تحتاج إلى تغيير الزيت في أقرب وقت" */
define('OIL_CHANGE_ALERT_KM_MIN', 2400);
define('OIL_CHANGE_ALERT_KM_MAX', 3000);

/**
 * ربط ذكي: حساب الفرق بين آخر تغيير زيت وآخر تفويل بنزين لسيارة
 * والتحقق إن كان ينبغي إظهار تنبيه (الفرق بين 2400 و 3000 كم).
 *
 * @param int $vehicleId
 * @return array ['need_alert' => bool, 'km_since_oil' => int|null, 'last_oil_km' => int|null, 'last_fuel_km' => int|null, 'vehicle_number' => string]
 */
function getVehicleOilChangeAlert($vehicleId) {
    ensureVehicleMaintenanceTable();
    $vehicleId = (int) $vehicleId;
    $result = [
        'need_alert' => false,
        'km_since_oil' => null,
        'last_oil_km' => null,
        'last_fuel_km' => null,
        'vehicle_number' => '',
    ];
    if ($vehicleId <= 0) {
        return $result;
    }
    $db = db();
    $vehicle = $db->queryOne("SELECT vehicle_number FROM vehicles WHERE id = ? AND status = 'active'", [$vehicleId]);
    if (!$vehicle) {
        return $result;
    }
    $result['vehicle_number'] = $vehicle['vehicle_number'] ?? '';

    $lastOil = $db->queryOne(
        "SELECT km_reading FROM vehicle_maintenance WHERE vehicle_id = ? AND type = 'oil_change' ORDER BY maintenance_date DESC, id DESC LIMIT 1",
        [$vehicleId]
    );
    $lastFuel = $db->queryOne(
        "SELECT km_reading FROM vehicle_maintenance WHERE vehicle_id = ? AND type = 'fuel_refill' ORDER BY maintenance_date DESC, id DESC LIMIT 1",
        [$vehicleId]
    );
    if (!$lastOil || !$lastFuel || !isset($lastOil['km_reading'], $lastFuel['km_reading'])) {
        return $result;
    }
    $lastOilKm = (int) $lastOil['km_reading'];
    $lastFuelKm = (int) $lastFuel['km_reading'];
    $kmSinceOil = $lastFuelKm - $lastOilKm;
    $result['last_oil_km'] = $lastOilKm;
    $result['last_fuel_km'] = $lastFuelKm;
    $result['km_since_oil'] = $kmSinceOil;
    $minKm = defined('OIL_CHANGE_ALERT_KM_MIN') ? (int) OIL_CHANGE_ALERT_KM_MIN : 2400;
    $maxKm = defined('OIL_CHANGE_ALERT_KM_MAX') ? (int) OIL_CHANGE_ALERT_KM_MAX : 3000;
    $result['need_alert'] = ($kmSinceOil >= $minKm && $kmSinceOil <= $maxKm);
    return $result;
}

/**
 * جلب كل السيارات التي تحتاج تنبيه تغيير زيت (الفرق بين 2400 و 3000 كم).
 *
 * @return array قائمة [ ['vehicle_id'=>int, 'vehicle_number'=>string, 'km_since_oil'=>int, 'last_oil_km'=>int, 'last_fuel_km'=>int], ... ]
 */
function getVehiclesNeedingOilChangeAlert() {
    ensureVehicleMaintenanceTable();
    $db = db();
    $minKm = defined('OIL_CHANGE_ALERT_KM_MIN') ? (int) OIL_CHANGE_ALERT_KM_MIN : 2400;
    $maxKm = defined('OIL_CHANGE_ALERT_KM_MAX') ? (int) OIL_CHANGE_ALERT_KM_MAX : 3000;
    $rows = $db->query(
        "SELECT v.id AS vehicle_id, v.vehicle_number,
         (SELECT vm.km_reading FROM vehicle_maintenance vm WHERE vm.vehicle_id = v.id AND vm.type = 'oil_change' ORDER BY vm.maintenance_date DESC, vm.id DESC LIMIT 1) AS last_oil_km,
         (SELECT vm.km_reading FROM vehicle_maintenance vm WHERE vm.vehicle_id = v.id AND vm.type = 'fuel_refill' ORDER BY vm.maintenance_date DESC, vm.id DESC LIMIT 1) AS last_fuel_km
         FROM vehicles v
         WHERE v.status = 'active'"
    );
    $list = [];
    foreach ($rows as $r) {
        $lastOil = isset($r['last_oil_km']) ? (int) $r['last_oil_km'] : null;
        $lastFuel = isset($r['last_fuel_km']) ? (int) $r['last_fuel_km'] : null;
        if ($lastOil === null || $lastFuel === null) {
            continue;
        }
        $kmSinceOil = $lastFuel - $lastOil;
        if ($kmSinceOil >= $minKm && $kmSinceOil <= $maxKm) {
            $list[] = [
                'vehicle_id' => (int) $r['vehicle_id'],
                'vehicle_number' => $r['vehicle_number'] ?? '',
                'km_since_oil' => $kmSinceOil,
                'last_oil_km' => $lastOil,
                'last_fuel_km' => $lastFuel,
            ];
        }
    }
    return $list;
}

/**
 * تنظيف صور صيانات السيارة الأقدم من X يوم
 * @param int $daysOld عدد الأيام (افتراضي 90 = 3 أشهر)
 */
function cleanupOldVehicleMaintenancePhotos($daysOld = 90) {
    $stats = [
        'deleted_files' => 0,
        'deleted_folders' => 0,
        'errors' => 0,
        'total_size_freed' => 0,
    ];

    $uploadsRoot = realpath(__DIR__ . '/../uploads');
    if ($uploadsRoot === false) {
        $uploadsRoot = __DIR__ . '/../uploads';
        if (!is_dir($uploadsRoot)) {
            return $stats;
        }
    }

    $maintenanceDir = $uploadsRoot . DIRECTORY_SEPARATOR . 'vehicle_maintenance';
    if (!is_dir($maintenanceDir)) {
        return $stats;
    }

    $cutoffTime = time() - ($daysOld * 24 * 60 * 60);
    $cutoffDate = date('Y-m-d', $cutoffTime);

    try {
        $monthFolders = glob($maintenanceDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
        foreach ($monthFolders as $monthFolder) {
            $folderName = basename($monthFolder);
            if (!preg_match('/^\d{4}-\d{2}$/', $folderName)) {
                continue;
            }
            $lastDayOfMonth = date('Y-m-t', strtotime($folderName . '-01'));

            if ($lastDayOfMonth < $cutoffDate) {
                $files = glob($monthFolder . DIRECTORY_SEPARATOR . '*');
                foreach ($files as $file) {
                    if (is_file($file)) {
                        $fileSize = filesize($file);
                        if (@unlink($file)) {
                            $stats['deleted_files']++;
                            $stats['total_size_freed'] += $fileSize;
                        } else {
                            $stats['errors']++;
                        }
                    }
                }
                if (@rmdir($monthFolder)) {
                    $stats['deleted_folders']++;
                }
            } else {
                $files = glob($monthFolder . DIRECTORY_SEPARATOR . '*.jpg');
                foreach ($files as $file) {
                    $fileTime = filemtime($file);
                    if ($fileTime !== false && $fileTime < $cutoffTime) {
                        $fileSize = filesize($file);
                        if (@unlink($file)) {
                            $stats['deleted_files']++;
                            $stats['total_size_freed'] += $fileSize;
                        } else {
                            $stats['errors']++;
                        }
                    }
                }
                $remainingFiles = glob($monthFolder . DIRECTORY_SEPARATOR . '*');
                if (empty($remainingFiles) && @rmdir($monthFolder)) {
                    $stats['deleted_folders']++;
                }
            }
        }

        $db = db();
        if ($db) {
            $records = $db->query(
                "SELECT id, photo_path FROM vehicle_maintenance WHERE maintenance_date < ? AND photo_path IS NOT NULL AND photo_path != ''",
                [$cutoffDate]
            );
            foreach ($records as $record) {
                $absPath = getMaintenancePhotoAbsolutePath($record['photo_path']);
                if ($absPath === null || !file_exists($absPath)) {
                    $db->execute("UPDATE vehicle_maintenance SET photo_path = '' WHERE id = ?", [$record['id']]);
                }
            }
        }
    } catch (Exception $e) {
        error_log('cleanupOldVehicleMaintenancePhotos: ' . $e->getMessage());
        $stats['errors']++;
    }

    return $stats;
}
