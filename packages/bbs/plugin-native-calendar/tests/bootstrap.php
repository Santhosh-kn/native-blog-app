<?php

declare(strict_types=1);

$autoload = dirname(__DIR__, 4).'/vendor/autoload.php';

if (! is_file($autoload)) {
    throw new RuntimeException('Application Composer autoload file was not found.');
}

require_once $autoload;

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Bbs\\NativeCalendar\\Tests\\' => __DIR__.'/',
        'Bbs\\NativeCalendar\\' => dirname(__DIR__).'/src/',
    ];

    foreach ($prefixes as $prefix => $directory) {
        if (! str_starts_with($class, $prefix)) {
            continue;
        }

        $path = $directory.str_replace(
            '\\',
            '/',
            substr($class, strlen($prefix)),
        ).'.php';

        if (is_file($path)) {
            require_once $path;
        }

        return;
    }
});
