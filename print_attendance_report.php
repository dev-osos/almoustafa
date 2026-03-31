<?php
/**
 * طباعة تقرير سجلات الحضور والانصراف لمستخدم خلال الشهر المحدد
 */

define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/attendance.php';
require_once __DIR__ . '/includes/path_helper.php';

requireRole(['accountant', 'manager', 'developer']);

// منع الكاش عند التبديل بين الصفحات لضمان عدم رجوع أي كاش قديم
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Expires: 0');
}

$userId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
$month = isset($_GET['month']) ? trim((string) $_GET['month']) : date('Y-m');

if ($userId <= 0) {
    die('معرف المستخدم غير صحيح');
}

if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

$db = db();

if (!function_exists('addFridayCreditToPrintRecords')) {
    /**
     * إضافة يوم الجمعة إلى سجل الطباعة عند عدم وجود حضور/انصراف مكتمل،
     * مع احتساب 10 ساعات لذلك اليوم.
     *
     * @return array{records: array<int, array<string, mixed>>, extra_hours: float}
     */
    function addFridayCreditToPrintRecords(array $records, string $monthKey): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $monthKey)) {
            return ['records' => $records, 'extra_hours' => 0.0];
        }

        $normalizedRecords = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }

            $recordDate = trim((string)($record['date'] ?? ''));
            if ($recordDate === '') {
                continue;
            }

            if (!isset($record['check_in_time']) && isset($record['check_in'])) {
                $record['check_in_time'] = $record['check_in'];
            }
            if (!isset($record['check_out_time']) && isset($record['check_out'])) {
                $record['check_out_time'] = $record['check_out'];
            }

            $record['work_hours'] = isset($record['work_hours']) ? (float)$record['work_hours'] : 0.0;
            $normalizedRecords[] = $record;
        }

        $groupedByDate = [];
        foreach ($normalizedRecords as $index => $record) {
            $groupedByDate[(string)$record['date']][] = $index;
        }

        $extraHours = 0.0;
        $monthStart = DateTime::createFromFormat('Y-m-d', $monthKey . '-01');
        if (!$monthStart) {
            return ['records' => $normalizedRecords, 'extra_hours' => 0.0];
        }

        $monthEnd = clone $monthStart;
        $monthEnd->modify('last day of this month');

        for ($cursor = clone $monthStart; $cursor <= $monthEnd; $cursor->modify('+1 day')) {
            if ((int)$cursor->format('N') !== 5) {
                continue;
            }

            $dateStr = $cursor->format('Y-m-d');
            if (empty($groupedByDate[$dateStr])) {
                $normalizedRecords[] = [
                    'id' => 'friday-credit-' . $dateStr,
                    'date' => $dateStr,
                    'check_in_time' => null,
                    'check_out_time' => null,
                    'delay_minutes' => 0,
                    'delay_reason' => '',
                    'work_hours' => 10.0,
                    '_friday_auto_credit' => true,
                ];
                $extraHours += 10.0;
                continue;
            }

            $hasCompleteRecord = false;
            $maxHoursForDate = 0.0;
            foreach ($groupedByDate[$dateStr] as $recordIndex) {
                $checkInValue = trim((string)($normalizedRecords[$recordIndex]['check_in_time'] ?? ''));
                $checkOutValue = trim((string)($normalizedRecords[$recordIndex]['check_out_time'] ?? ''));
                $maxHoursForDate = max($maxHoursForDate, (float)($normalizedRecords[$recordIndex]['work_hours'] ?? 0));

                if ($checkInValue !== '' && strpos($checkInValue, '0000-00-00') !== 0 && $checkOutValue !== '' && strpos($checkOutValue, '0000-00-00') !== 0) {
                    $hasCompleteRecord = true;
                    break;
                }
            }

            if (!$hasCompleteRecord) {
                $firstIndex = $groupedByDate[$dateStr][0];
                $normalizedRecords[$firstIndex]['work_hours'] = max(10.0, $maxHoursForDate);
                $normalizedRecords[$firstIndex]['_friday_auto_credit'] = true;
                $extraHours += max(0.0, 10.0 - $maxHoursForDate);
            }
        }

        usort($normalizedRecords, function ($a, $b) {
            $dateComparison = strcmp((string)($a['date'] ?? ''), (string)($b['date'] ?? ''));
            if ($dateComparison !== 0) {
                return $dateComparison;
            }

            $timeA = (string)($a['check_in_time'] ?? '');
            $timeB = (string)($b['check_in_time'] ?? '');
            return strcmp($timeA, $timeB);
        });

        return [
            'records' => $normalizedRecords,
            'extra_hours' => round($extraHours, 2),
        ];
    }
}

$user = $db->queryOne(
    "SELECT id, username, full_name, role FROM users WHERE id = ? AND role != 'manager' AND status = 'active'",
    [$userId]
);

if (!$user) {
    die('المستخدم غير موجود أو لا يخضع لنظام الحضور والانصراف');
}

$tableCheck = $db->queryOne("SHOW TABLES LIKE 'attendance_records'");
if (empty($tableCheck)) {
    $records = [];
} else {
    $records = $db->query(
        "SELECT * FROM attendance_records 
         WHERE user_id = ? AND DATE_FORMAT(date, '%Y-%m') = ?
         ORDER BY date ASC, check_in_time ASC",
        [$userId, $month]
    );
}

$augmentedRecords = addFridayCreditToPrintRecords($records ?: [], $month);
$records = $augmentedRecords['records'] ?? [];
$fridayExtraHours = (float)($augmentedRecords['extra_hours'] ?? 0.0);

$stats = getAttendanceStatistics($userId, $month);
$stats['total_hours'] = round((float)($stats['total_hours'] ?? 0) + $fridayExtraHours, 2);
$delaySummary = calculateMonthlyDelaySummary($userId, $month);

$userName = $user['full_name'] ?? $user['username'];
$monthLabel = date('F Y', strtotime($month . '-01'));
$arabicMonths = [
    1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل',
    5 => 'مايو', 6 => 'يونيو', 7 => 'يوليو', 8 => 'أغسطس',
    9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر'
];
$monthNum = (int) date('n', strtotime($month . '-01'));
$monthYear = date('Y', strtotime($month . '-01'));
$monthLabelAr = ($arabicMonths[$monthNum] ?? $monthLabel) . ' ' . $monthYear;
$companyName = defined('COMPANY_NAME') ? COMPANY_NAME : 'النظام';

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>سجل حضور <?php echo htmlspecialchars($userName); ?> - <?php echo $monthLabelAr; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        @page { size: A4; margin: 15mm; }
        @media print {
            .no-print { display: none !important; }
            body { background: #fff; padding: 0; }
        }
        body {
            font-family: 'Tajawal', 'Arial', sans-serif;
            background: #f5f5f5;
            padding: 20px;
            font-size: 14px;
            line-height: 1.5;
        }
        .report-container {
            max-width: 210mm;
            margin: 0 auto;
            background: #fff;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .report-header {
            text-align: center;
            border-bottom: 2px solid #0d6efd;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        .report-header .company {
            font-size: 18px;
            font-weight: 700;
            color: #0d6efd;
            margin-bottom: 5px;
        }
        .report-header h1 {
            font-size: 20px;
            font-weight: 700;
            color: #333;
            margin: 10px 0 5px;
        }
        .report-header .subtitle {
            font-size: 14px;
            color: #666;
        }
        .info-block {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 20px;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .info-block span {
            font-weight: 600;
            color: #333;
        }
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
            gap: 6px;
            margin-bottom: 12px;
        }
        .summary-card {
            padding: 6px 8px;
            background: #f8f9fa;
            border-radius: 6px;
            text-align: center;
            border: 1px solid #dee2e6;
        }
        .summary-card .value {
            font-size: 13px;
            font-weight: 700;
            color: #0d6efd;
        }
        .summary-card .label {
            font-size: 10px;
            color: #666;
            margin-top: 2px;
        }
        table.report-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
            font-size: 11px;
        }
        table.report-table th,
        table.report-table td {
            border: 1px solid #dee2e6;
            padding: 4px 6px;
            text-align: right;
        }
        table.report-table th {
            background: #0d6efd;
            color: #fff;
            font-weight: 600;
            font-size: 11px;
        }
        table.report-table tr:nth-child(even) {
            background: #f8f9fa;
        }
        table.report-table .badge {
            display: inline-block;
            padding: 1px 5px;
            border-radius: 3px;
            font-size: 10px;
        }
        .badge-success { background: #198754; color: #fff; }
        .badge-warning { background: #ffc107; color: #000; }
        .footer-note {
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #dee2e6;
            font-size: 12px;
            color: #666;
            text-align: center;
        }
        .btn-print {
            display: inline-block;
            margin: 0 auto 20px;
            padding: 10px 24px;
            background: #0d6efd;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
        }
        .btn-print:hover { background: #0b5ed7; color: #fff; }
        .print-actions { text-align: center; }
    </style>
</head>
<body>
    <div class="print-actions no-print">
        <button type="button" class="btn-print" onclick="window.print();">
            طباعة التقرير
        </button>
    </div>
    <div class="report-container">
        <div class="report-header">
            <div class="company"><?php echo htmlspecialchars($companyName); ?></div>
            <h1>سجل الحضور والانصراف الشهري</h1>
            <div class="subtitle"><?php echo htmlspecialchars($userName); ?> &mdash; <?php echo $monthLabelAr; ?></div>
        </div>
        <div class="info-block">
            <span>المستخدم:</span> <?php echo htmlspecialchars($userName); ?>
            &nbsp;|&nbsp;
            <span>الشهر:</span> <?php echo $monthLabel; ?>
            &nbsp;|&nbsp;
            <span>الدور:</span> <?php echo htmlspecialchars($user['role']); ?>
        </div>
        <div class="summary-cards">
            <div class="summary-card">
                <div class="value"><?php echo (int) $stats['present_days']; ?></div>
                <div class="label">أيام الحضور</div>
            </div>
            <div class="summary-card">
                <div class="value"><?php echo formatHours($stats['total_hours']); ?></div>
                <div class="label">إجمالي ساعات العمل</div>
            </div>
            <div class="summary-card">
                <div class="value"><?php echo number_format($delaySummary['average_minutes'] ?? 0, 1); ?></div>
                <div class="label">متوسط التأخير (دقيقة)</div>
            </div>
            <div class="summary-card">
                <div class="value"><?php echo (int) ($delaySummary['delay_days'] ?? 0); ?></div>
                <div class="label">مرات التأخير</div>
            </div>
        </div>
        <table class="report-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>التاريخ</th>
                    <th>تسجيل الحضور</th>
                    <th>تسجيل الانصراف</th>
                    <th>التأخير</th>
                    <th>سبب التأخير</th>
                    <th>ساعات العمل</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($records)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; color: #666;">لا توجد سجلات لهذا الشهر</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($records as $i => $record): ?>
                        <tr>
                            <td><?php echo $i + 1; ?></td>
                            <td><?php echo formatDate($record['date']); ?></td>
                            <td><?php echo !empty($record['check_in_time']) ? formatDateTime($record['check_in_time']) : '-'; ?></td>
                            <td><?php echo !empty($record['check_out_time']) ? formatDateTime($record['check_out_time']) : '-'; ?></td>
                            <td>
                                <?php if (!empty($record['_friday_auto_credit'])): ?>
                                    <span class="badge" style="background:#0d6efd;color:#fff;">جمعة</span>
                                <?php elseif (($record['delay_minutes'] ?? 0) > 0): ?>
                                    <span class="badge badge-warning"><?php echo (int) $record['delay_minutes']; ?> دقيقة</span>
                                <?php else: ?>
                                    <span class="badge badge-success">في الوقت</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo !empty($record['delay_reason']) && ($record['delay_minutes'] ?? 0) > 0 ? htmlspecialchars($record['delay_reason']) : (!empty($record['_friday_auto_credit']) ? 'جمعة محتسبة تلقائياً' : '-'); ?></td>
                            <td><?php echo isset($record['work_hours']) && $record['work_hours'] > 0 ? formatHours($record['work_hours']) : '-'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <div class="footer-note">
            تم إنشاء التقرير في <?php echo date('Y-m-d H:i'); ?> — تقرير الحضور والانصراف (شهري)
        </div>
    </div>
    <script>
        // طباعة تلقائية اختيارية (يمكن تعطيلها)
        // window.onload = function() { window.print(); };
    </script>
</body>
</html>
