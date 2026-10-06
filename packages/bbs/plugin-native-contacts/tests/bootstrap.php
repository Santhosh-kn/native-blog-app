<?php

declare(strict_types=1);

$rootAutoload = dirname(__DIR__, 4).'/vendor/autoload.php';

if (! is_file($rootAutoload)) {
    throw new RuntimeException('The application Composer autoloader is missing.');
}

require_once $rootAutoload;

spl_autoload_register(static function (string $class): void {
    $prefix = 'Bbs\\NativeContacts\\';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = dirname(__DIR__).'/src/'.str_replace('\\', '/', $relative).'.php';

    if (is_file($path)) {
        require_once $path;
    }
});
