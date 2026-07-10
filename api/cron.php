<?php
/**
 * مهمة مجدولة (Cron Job) — تنبيه تلقائي للموظفين المتأخرين عن الدوام
 * تشتغل كل بضع دقائق (مقترح: كل 5 دقائق) عن طريق Cron Jobs بلوحة الاستضافة.
 *
 * الرابط اللي تحطه بالـ Cron Job (غيّر المفتاح السري أولًا بالأسفل):
 * https://yourdomain.com/api/cron.php?key=YOUR_SECRET_KEY
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

// 🔒 غيّر هاد المفتاح لأي نص سري تختاره، وحطه بنفس الرابط بإعداد الـ Cron Job
define('CRON_SECRET', 'change-this-secret-key-123');

header('Content-Type: text/plain; charset=utf-8');

if (($_GET['key'] ?? '') !== CRON_SECRET) {
    http_response_code(403);
    die('ممنوع: مفتاح غير صحيح');
}

$today = date('Y-m-d');
$now = time();
$s = $pdo->query("SELECT grace_minutes FROM settings WHERE id = 1")->fetch();
$reminderMinutes = 15; // بعد كم دقيقة من بداية الدوام يُبعث التنبيه لو ما سجّل حضور

$employees = $pdo->query("SELECT id, name, phone, work_start AS workStart FROM users WHERE role = 'employee'")->fetchAll();

$sentCount = 0;
$checkedCount = 0;

foreach ($employees as $emp) {
    $checkedCount++;
    $workStart = $emp['workStart'] ?: '08:00:00';
    $deadline = strtotime($today . ' ' . $workStart) + ($reminderMinutes * 60);

    if ($now < $deadline) continue; // لسا ما وصلت مهلة الـ 15 دقيقة

    // هل سجّل حضوره اليوم أصلًا؟
    $stmt = $pdo->prepare("SELECT id FROM attendance WHERE user_id = ? AND date = ?");
    $stmt->execute([$emp['id'], $today]);
    if ($stmt->fetch()) continue; // سجّل خلص، ما في داعي تنبيه

    // هل تم إرسال تنبيه تأخير له اليوم مسبقًا؟ (لمنع التكرار كل ما اشتغل الـ cron)
    $stmt = $pdo->prepare("SELECT id FROM notifications WHERE user_id = ? AND type = 'late_reminder' AND DATE(created_at) = ?");
    $stmt->execute([$emp['id'], $today]);
    if ($stmt->fetch()) continue; // اتبعت خلص اليوم

    pushNotification($pdo, $emp['id'], "⏰ تنبيه: دوامك بدأ الساعة {$workStart} ولسا ما سجّلت حضورك. يرجى تسجيل حضورك فورًا.", 'late_reminder');
    $sentCount++;
}

echo "تم فحص {$checkedCount} موظف، وإرسال {$sentCount} تنبيه تأخير في " . date('Y-m-d H:i:s');
