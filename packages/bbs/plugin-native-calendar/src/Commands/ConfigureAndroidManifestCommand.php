<?php

declare(strict_types=1);

namespace Bbs\NativeCalendar\Commands;

use Bbs\NativeCalendar\Support\NativeCalendarManifestQueries;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

final class ConfigureAndroidManifestCommand extends Command
{
    protected $signature = 'nativephp:native-calendar:configure-android
        {--platform=}
        {--build-path=}
        {--plugin-path=}
        {--app-id=}
        {--config=}
        {--plugins=}';

    protected $description = 'Configure targeted Android calendar intent queries';

    public function handle(): int
    {
        if ($this->option('platform') === 'ios') {
            return self::SUCCESS;
        }

        try {
            if ($this->option('platform') !== 'android') {
                throw new RuntimeException('Unsupported hook platform.');
            }

            $suppliedPath = $this->option('build-path');

            if (! is_string($suppliedPath) || $suppliedPath === '') {
                throw new RuntimeException('Missing Android build path.');
            }

            $buildPath = realpath($suppliedPath);

            if (! is_string($buildPath) || ! is_dir($buildPath)) {
                throw new RuntimeException('Invalid Android build path.');
            }

            $expectedPath = rtrim($buildPath, '/\\')
                .DIRECTORY_SEPARATOR.'app'
                .DIRECTORY_SEPARATOR.'src'
                .DIRECTORY_SEPARATOR.'main'
                .DIRECTORY_SEPARATOR.'AndroidManifest.xml';

            $manifestPath = realpath($expectedPath);

            if (
                ! is_string($manifestPath) ||
                ! is_file($manifestPath) ||
                $this->normalizedPath($manifestPath)
                    !== $this->normalizedPath($expectedPath)
            ) {
                throw new RuntimeException('Invalid Android manifest path.');
            }

            // Bounded read; never print manifest contents or hook context.
            $original = @file_get_contents(
                $manifestPath,
                false,
                null,
                0,
                2_097_153
            );

            if (! is_string($original)) {
                throw new RuntimeException('Android manifest could not be read.');
            }

            $updated = NativeCalendarManifestQueries::apply($original);

            if ($updated === $original) {
                $this->info('Calendar intent queries are already configured.');

                return self::SUCCESS;
            }

            $files = new Filesystem();

            // Laravel replaces the existing file atomically.
            @$files->replace($manifestPath, $updated);

            $verified = @file_get_contents(
                $manifestPath,
                false,
                null,
                0,
                2_097_153
            );

            if ($verified !== $updated) {
                throw new RuntimeException('Android manifest verification failed.');
            }

            $this->info('Calendar intent queries configured.');

            return self::SUCCESS;
        } catch (\Throwable) {
            // The framework logs hook output. Keep it free of file contents,
            // input paths, config values, and underlying exception messages.
            $this->error('Calendar manifest hook failed. Check the Android build path and manifest validity.');

            return self::FAILURE;
        }
    }

    private function normalizedPath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);

        return PHP_OS_FAMILY === 'Windows'
            ? strtolower($normalized)
            : $normalized;
    }
}
