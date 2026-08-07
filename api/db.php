<?php
// بيانات الاتصال: نقرأها من api/config.php لو موجود (السلوك المعتاد محليًا وعلى الاستضافة)،
// وإلا نرجع لمتغيرات بيئة (Environment Variables) كخط دفاع ثاني — بدون أي سر مكتوب بالكود أو بـ Git.
// هذا يحمي الموقع لو انحذف config.php بالغلط بعملية نشر مستقبلية (نفس السبب اللي صار هلق).
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}
if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST') ?: '');
if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME') ?: '');
if (!defined('DB_USER')) define('DB_USER', getenv('DB_USER') ?: '');
if (!defined('DB_PASS')) define('DB_PASS', getenv('DB_PASS') ?: '');

// ضبط توقيت فلسطين (يتعامل تلقائيًا مع التوقيت الصيفي والشتوي)
date_default_timezone_set('Asia/Hebron');

if (DB_NAME === '') {
    // لا config.php ولا متغيرات بيئة — رسالة تشخيصية واضحة بدل الانهيار الصامت الغامض
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    die(json_encode(['error' => 'إعدادات قاعدة البيانات غير موجودة. أنشئ ملف api/config.php على السيرفر (انسخه من api/config.example.php وعبّي بياناته)، أو اضبط متغيرات بيئة DB_HOST/DB_NAME/DB_USER/DB_PASS.']));
}

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
