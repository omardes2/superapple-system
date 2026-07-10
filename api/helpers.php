<?php
/**
 * دوال مشتركة بين api/index.php و api/cron.php
 */

function sendWhatsAppCloud($pdo, $phone, $bodyText) {
    if (empty($phone)) return;
    if (!function_exists('curl_init')) return; // امتداد curl غير مفعّل على هذه الاستضافة
    $s = $pdo->query("SELECT whatsapp_phone_id, whatsapp_token, whatsapp_template FROM settings WHERE id = 1")->fetch();
    if (empty($s['whatsapp_phone_id']) || empty($s['whatsapp_token'])) return; // غير مفعّل بعد

    $template = $s['whatsapp_template'] ?: 'hello_world';
    $isHelloWorld = ($template === 'hello_world');

    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => preg_replace('/\D/', '', $phone),
        'type' => 'template',
        'template' => [
            'name' => $template,
            'language' => ['code' => $isHelloWorld ? 'en_US' : 'ar'],
        ],
    ];
    if (!$isHelloWorld) {
        $payload['template']['components'] = [[
            'type' => 'body',
            'parameters' => [['type' => 'text', 'text' => $bodyText]],
        ]];
    }

    $ch = curl_init("https://graph.facebook.com/v20.0/{$s['whatsapp_phone_id']}/messages");
    if ($ch === false) return; // curl غير متاح على هذه الاستضافة
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $s['whatsapp_token']],
        CURLOPT_TIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    @curl_exec($ch);
    curl_close($ch);
}

// يسجّل إشعارًا داخل النظام + يبعت رسالة واتساب حقيقية بنفس الوقت
function pushNotification($pdo, $userId, $message, $type) {
    $pdo->prepare("INSERT INTO notifications (user_id, message, type) VALUES (?, ?, ?)")->execute([$userId, $message, $type]);
    $stmt = $pdo->prepare("SELECT phone FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $u = $stmt->fetch();
    if ($u && $u['phone']) sendWhatsAppCloud($pdo, $u['phone'], $message);
}
