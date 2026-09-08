<?php

declare(strict_types=1);

$packageRoot = dirname(__DIR__);
$autoloadCandidates = [
    $packageRoot.'/vendor/autoload.php',
    dirname(__DIR__, 4).'/vendor/autoload.php',
];

$autoloadLoaded = false;

foreach ($autoloadCandidates as $autoloadPath) {
    if (is_file($autoloadPath)) {
        require_once $autoloadPath;
        $autoloadLoaded = true;

        break;
    }
}

if (! $autoloadLoaded) {
    throw new RuntimeException('Composer autoloader could not be found.');
}

spl_autoload_register(
    static function (string $class) use ($packageRoot): void {
        $prefix = 'Bbs\\NativeDocumentPicker\\';

        if (! str_starts_with($class, $prefix)) {
            return;
        }

        $relativeClass = substr($class, strlen($prefix));
        $sourcePath = $packageRoot.
            '/src/'.
            str_replace('\\', '/', $relativeClass).
            '.php';

        if (is_file($sourcePath)) {
            require_once $sourcePath;
        }
    },
);
