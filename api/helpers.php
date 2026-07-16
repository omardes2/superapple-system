<?php
/**
 * دوال مشتركة بين api/index.php و api/cron.php
 */

function sendWhatsAppCloud($pdo, $phone, $bodyText) {
    if (empty($phone)) return ['success' => false, 'error' => 'لا يوجد رقم هاتف'];
    if (!function_exists('curl_init')) return ['success' => false, 'error' => 'امتداد curl غير مفعّل على هذه الاستضافة'];
    $s = $pdo->query("SELECT whatsapp_phone_id, whatsapp_token, whatsapp_template FROM settings WHERE id = 1")->fetch();
    if (empty($s['whatsapp_phone_id']) || empty($s['whatsapp_token'])) {
        return ['success' => false, 'error' => 'واتساب غير مفعّل بعد (Phone Number ID أو Access Token فاضي)'];
    }

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
    if ($ch === false) return ['success' => false, 'error' => 'curl غير متاح على هذه الاستضافة'];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $s['whatsapp_token']],
        CURLOPT_TIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $success = ($httpCode >= 200 && $httpCode < 300);
    $responseText = $curlErr ?: $raw;

    try {
        $pdo->prepare("INSERT INTO whatsapp_log (phone, message, success, response) VALUES (?, ?, ?, ?)")
            ->execute([$phone, $bodyText, $success ? 1 : 0, $responseText]);
    } catch (\Throwable $e) { /* جدول السجل قد لا يكون موجودًا بعد على استضافات لم تحدّث قاعدة البيانات */ }

    if ($success) {
        try {
            $waMessageId = null;
            $decoded = json_decode($raw, true);
            if (isset($decoded['messages'][0]['id'])) $waMessageId = $decoded['messages'][0]['id'];
            $pdo->prepare("INSERT INTO whatsapp_messages (phone, direction, message, wa_message_id) VALUES (?, 'out', ?, ?)")
                ->execute([preg_replace('/\D/', '', $phone), $bodyText, $waMessageId]);
        } catch (\Throwable $e) { /* جدول صندوق الرسائل قد لا يكون موجودًا بعد */ }
    }

    if (!$success) {
        $decoded = json_decode($raw, true);
        $friendly = $decoded['error']['message'] ?? ($curlErr ?: 'خطأ غير معروف');
        return ['success' => false, 'error' => $friendly, 'httpCode' => $httpCode];
    }
    return ['success' => true];
}

// يسجّل إشعارًا داخل النظام + يبعت رسالة واتساب حقيقية بنفس الوقت
function pushNotification($pdo, $userId, $message, $type) {
    $pdo->prepare("INSERT INTO notifications (user_id, message, type) VALUES (?, ?, ?)")->execute([$userId, $message, $type]);
    $stmt = $pdo->prepare("SELECT phone FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $u = $stmt->fetch();
    if ($u && $u['phone']) sendWhatsAppCloud($pdo, $u['phone'], $message);
}
