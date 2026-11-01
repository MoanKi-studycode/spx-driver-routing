<?php

declare(strict_types=1);

// Nạp cấu hình ứng dụng
require_once __DIR__ . '/../config/app.php';

// Nạp Composer Autoloader nếu có
$composerAutoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
} else {
    // Autoload dự phòng theo PSR-4 cho namespace App\
    spl_autoload_register(static function (string $class): void {
        $prefix = 'App\\';
        $baseDir = __DIR__ . '/';
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }
        $relativeClass = substr($class, $len);
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    });
}

// Khởi tạo session an toàn qua class Core\Session
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    \App\Core\Session::start();
}
