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

// تنظيف تلقائي: حذف رسائل دردشة الفريق الأقدم من 24 ساعة (يشتغل كل مرة بغض النظر عن يوم الجمعة)
try {
    $pdo->exec("DELETE FROM team_messages WHERE created_at < (NOW() - INTERVAL 24 HOUR)");
} catch (\Throwable $e) { /* الجدول قد لا يكون موجودًا بعد على استضافات لم تحدّث قاعدة البيانات */ }

$today = date('Y-m-d');

/* ============================================================
   الرسائل التحفيزية اليومية
   تُرسل لكل موظف مرّة واحدة باليوم، بعد مرور مدة محددة من تسجيل حضوره الفعلي.
   الاختيار ذكي: يفضّل الرسائل اللي الموظف ما استلمها من قبل إطلاقًا.
   ============================================================ */
$motivSent = 0;
try {
    $ms = $pdo->query("SELECT motivation_enabled, motivation_delay_minutes FROM settings WHERE id = 1")->fetch();
    if ($ms && (int) $ms['motivation_enabled']) {
        $delayMin = max(0, (int) $ms['motivation_delay_minutes']);

        // الموظفون اللي سجّلوا حضور اليوم، ومرّت المدة المطلوبة، وما استلموا رسالة اليوم
        $stmt = $pdo->prepare("
            SELECT a.user_id, u.name, u.phone
            FROM attendance a
            JOIN users u ON u.id = a.user_id
            WHERE a.date = ?
              AND a.check_in IS NOT NULL
              AND u.phone IS NOT NULL AND u.phone != ''
              AND ADDTIME(a.check_in, SEC_TO_TIME(? * 60)) <= CURTIME()
              AND NOT EXISTS (SELECT 1 FROM motivation_log ml WHERE ml.user_id = a.user_id AND ml.sent_date = ?)
        ");
        $stmt->execute([$today, $delayMin, $today]);
        $dueUsers = $stmt->fetchAll();

        foreach ($dueUsers as $du) {
            // اختر رسالة عشوائية من اللي ما استلمها هذا الموظف من قبل
            $pick = $pdo->prepare("
                SELECT id, message FROM motivation_messages
                WHERE is_active = 1
                  AND id NOT IN (SELECT COALESCE(message_id, 0) FROM motivation_log WHERE user_id = ?)
                ORDER BY RAND() LIMIT 1
            ");
            $pick->execute([$du['user_id']]);
            $msg = $pick->fetch();

            // لو استلم كل الرسائل الموجودة، نعيد من البداية (نختار أي رسالة مفعّلة)
            if (!$msg) {
                $msg = $pdo->query("SELECT id, message FROM motivation_messages WHERE is_active = 1 ORDER BY RAND() LIMIT 1")->fetch();
            }
            if (!$msg) break; // ما في ولا رسالة مفعّلة أصلًا

            $firstName = explode(' ', trim($du['name']))[0];
            $text = $firstName . '، ' . $msg['message'];
            $waResult = sendWhatsAppCloud($pdo, $du['phone'], $text);
            $ok = is_array($waResult) ? !empty($waResult['success']) : (bool) $waResult;

            // نسجّل الإرسال بكل الأحوال حتى لو فشل واتساب، عشان ما نكرر المحاولة كل 5 دقائق طول اليوم
            $pdo->prepare("INSERT IGNORE INTO motivation_log (user_id, message_id, sent_date, success) VALUES (?, ?, ?, ?)")
                ->execute([$du['user_id'], $msg['id'], $today, $ok ? 1 : 0]);
            if ($ok) $motivSent++;
        }
    }
} catch (\Throwable $e) {
    // جداول الرسائل التحفيزية قد لا تكون موجودة بعد على استضافة لم تُحدَّث — نتخطى بأمان
}


// الجمعة عطلة رسمية بالشركة — لا تنبيهات تأخير فيها
if (date('N') == 5) {
    die('اليوم جمعة (عطلة) — تم تخطي فحص التأخير، وتم إرسال الرسائل التحفيزية إن وُجدت.');
}

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

echo "تم فحص {$checkedCount} موظف، وإرسال {$sentCount} تنبيه تأخير و{$motivSent} رسالة تحفيزية في " . date('Y-m-d H:i:s');
