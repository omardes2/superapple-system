<?php
// أوتولودر بسيط لمكتبة lbuchs/WebAuthn بدون الحاجة لـ Composer
spl_autoload_register(function ($class) {
    $prefix = 'lbuchs\\WebAuthn\\';
    if (strpos($class, $prefix) !== 0) return;
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) require $file;
});
