<?php
require_once __DIR__ . '/config.php';

// ضبط توقيت فلسطين (يتعامل تلقائيًا مع التوقيت الصيفي والشتوي)
date_default_timezone_set('Asia/Hebron');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    // مزامنة توقيت قاعدة البيانات مع نفس توقيت فلسطين الحالي (يشمل الصيفي تلقائيًا)
    $offset = (new DateTime('now', new DateTimeZone('Asia/Hebron')))->format('P');
    $pdo->exec("SET time_zone = '{$offset}'");
} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    die(json_encode(['error' => 'تعذر الاتصال بقاعدة البيانات. تأكد من صحة بيانات api/config.php — ' . $e->getMessage()]));
}
