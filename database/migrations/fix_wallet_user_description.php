<?php
/**
 * تحديث وصف معاملات التحصيل القديمة:
 * استبدال "محفظة مستخدم" باسم المستخدم الفعلي (صاحب المحفظة)
 * يُنفذ مرة واحدة فقط
 */

define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

$db = db();

// جلب كل المعاملات التي تحتوي على "محفظة مستخدم" في الوصف
$tables = ['accountant_transactions', 'financial_transactions'];

foreach ($tables as $table) {
    $tableExists = $db->queryOne("SHOW TABLES LIKE ?", [$table]);
    if (empty($tableExists)) continue;

    $rows = $db->query(
        "SELECT id, description, reference_number FROM $table WHERE description LIKE ?",
        ['%محفظة مستخدم%']
    ) ?: [];

    $updated = 0;
    foreach ($rows as $row) {
        // reference_number = requestId-YYYYMMDD
        $requestId = (int)explode('-', $row['reference_number'] ?? '')[0];
        if ($requestId <= 0) continue;

        // جلب user_id من طلب التحصيل
        $req = $db->queryOne(
            "SELECT user_id FROM user_wallet_local_collection_requests WHERE id = ?",
            [$requestId]
        );
        if (empty($req)) continue;

        // جلب اسم المستخدم
        $user = $db->queryOne("SELECT full_name FROM users WHERE id = ?", [(int)$req['user_id']]);
        if (empty($user)) continue;

        $newDesc = str_replace('محفظة مستخدم', 'محفظة ' . $user['full_name'], $row['description']);
        $db->execute("UPDATE $table SET description = ? WHERE id = ?", [$newDesc, $row['id']]);
        $updated++;
    }

    echo "$table: تم تحديث $updated معاملة\n";
}

echo "تم الانتهاء.\n";
