<?php
/**
 * تحديث وصف معاملات التحصيل القديمة:
 * استبدال "محفظة مستخدم" باسم المستخدم الفعلي (صاحب المحفظة)
 * يُنفذ مرة واحدة فقط
 */

header('Content-Type: text/plain; charset=utf-8');

$conn = new mysqli('localhost', 'u486977009_almostafax', 'HWShtbi63p#5', 'u486977009_almostafa_v2');
if ($conn->connect_error) {
    die('فشل الاتصال: ' . $conn->connect_error);
}
$conn->set_charset('utf8mb4');

$tables = ['accountant_transactions', 'financial_transactions'];

foreach ($tables as $table) {
    $check = $conn->query("SHOW TABLES LIKE '$table'");
    if ($check->num_rows === 0) {
        echo "$table: الجدول غير موجود\n";
        continue;
    }

    $stmt = $conn->prepare("SELECT id, description, reference_number FROM $table WHERE description LIKE ?");
    $search = '%محفظة مستخدم%';
    $stmt->bind_param('s', $search);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $updated = 0;
    foreach ($rows as $row) {
        $parts = explode('-', $row['reference_number'] ?? '');
        $requestId = (int)$parts[0];
        if ($requestId <= 0) continue;

        $stmt2 = $conn->prepare("SELECT user_id FROM user_wallet_local_collection_requests WHERE id = ?");
        $stmt2->bind_param('i', $requestId);
        $stmt2->execute();
        $req = $stmt2->get_result()->fetch_assoc();
        $stmt2->close();
        if (empty($req)) continue;

        $uid = (int)$req['user_id'];
        $stmt3 = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
        $stmt3->bind_param('i', $uid);
        $stmt3->execute();
        $user = $stmt3->get_result()->fetch_assoc();
        $stmt3->close();
        if (empty($user)) continue;

        $newDesc = str_replace('محفظة مستخدم', 'محفظة ' . $user['full_name'], $row['description']);
        $stmt4 = $conn->prepare("UPDATE $table SET description = ? WHERE id = ?");
        $stmt4->bind_param('si', $newDesc, $row['id']);
        $stmt4->execute();
        $stmt4->close();
        $updated++;
    }

    echo "$table: تم تحديث $updated معاملة\n";
}

$conn->close();
echo "تم الانتهاء.\n";
