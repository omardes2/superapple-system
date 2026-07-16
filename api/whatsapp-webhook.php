<?php
/**
 * Webhook استقبال رسائل واتساب الواردة (ردود العملاء/الموظفين)
 * هذا الملف عام (بدون تسجيل دخول) لأن ميتا هي اللي بتناديه مباشرة.
 *
 * الرابط اللي بتحطه بلوحة Meta (Webhook Callback URL):
 * https://yourdomain.com/api/whatsapp-webhook.php
 */

require_once __DIR__ . '/db.php';

// ===== خطوة التحقق (يستدعيها ميتا مرة وحدة وقت الإعداد) =====
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $s = $pdo->query("SELECT whatsapp_verify_token FROM settings WHERE id = 1")->fetch();
    $verifyToken = $s['whatsapp_verify_token'] ?? '';

    if (($_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '') === 'subscribe'
        && ($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '') === $verifyToken
        && $verifyToken !== '') {
        header('Content-Type: text/plain');
        echo $_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '';
        exit;
    }
    http_response_code(403);
    die('Verification failed');
}

// ===== استقبال الرسائل الفعلية =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');

    // تحقق التوقيع الأمني (اختياري لكن موصى به لو ضبطت App Secret)
    $s = $pdo->query("SELECT whatsapp_app_secret FROM settings WHERE id = 1")->fetch();
    if (!empty($s['whatsapp_app_secret'])) {
        $sigHeader = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
        $expected = 'sha256=' . hash_hmac('sha256', $raw, $s['whatsapp_app_secret']);
        if (!$sigHeader || !hash_equals($expected, $sigHeader)) {
            http_response_code(403);
            die('Invalid signature');
        }
    }

    $data = json_decode($raw, true);
    $entries = $data['entry'] ?? [];
    foreach ($entries as $entry) {
        foreach (($entry['changes'] ?? []) as $change) {
            $value = $change['value'] ?? [];
            foreach (($value['messages'] ?? []) as $msg) {
                $from = $msg['from'] ?? null;
                $text = $msg['text']['body'] ?? ($msg['button']['text'] ?? '[رسالة غير نصية]');
                $waId = $msg['id'] ?? null;
                if ($from) {
                    $pdo->prepare("INSERT INTO whatsapp_messages (phone, direction, message, wa_message_id) VALUES (?, 'in', ?, ?)")
                        ->execute([$from, $text, $waId]);
                }
            }
        }
    }

    http_response_code(200);
    echo 'OK';
    exit;
}

http_response_code(405);
