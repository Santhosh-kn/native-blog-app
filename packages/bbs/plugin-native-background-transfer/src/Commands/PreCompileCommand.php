<?php

declare(strict_types=1);

namespace Bbs\NativeBackgroundTransfer\Commands;

use Native\Mobile\Plugins\Commands\NativePluginHookCommand;

final class PreCompileCommand extends NativePluginHookCommand
{
    protected $signature =
        'nativephp:native-background-transfer:pre-compile';

    protected $description =
        'Redact NativePHP bridge payload logs before Android compilation';

    private const SENSITIVE_LOG_REPLACEMENTS = [
        'Parameters JSON: %s", parametersJSON' =>
            'Parameters JSON: [REDACTED]"',
        'Result JSON: %s", resultStr' =>
            'Result JSON: [REDACTED]"',
    ];

    public function handle(): int
    {
        if (! $this->isAndroid()) {
            return self::SUCCESS;
        }

        $bridgePath =
            rtrim($this->buildPath(), '\\/').
            '/app/src/main/cpp/bridge_jni.cpp';

        if (! is_file($bridgePath)) {
            $this->error(
                'NativePHP Android bridge source was not found.',
            );

            return self::FAILURE;
        }

        $bridgeSource = file_get_contents($bridgePath);

        if ($bridgeSource === false) {
            $this->error(
                'NativePHP Android bridge source could not be read.',
            );

            return self::FAILURE;
        }

        $updated = false;

        foreach (
            self::SENSITIVE_LOG_REPLACEMENTS
            as $unsafeLog => $safeLog
        ) {
            $unsafeCount =
                substr_count($bridgeSource, $unsafeLog);

            $safeCount =
                substr_count($bridgeSource, $safeLog);

            if ($unsafeCount === 1 && $safeCount === 0) {
                $bridgeSource = str_replace(
                    $unsafeLog,
                    $safeLog,
                    $bridgeSource,
                );

                $updated = true;

                continue;
            }

            if ($unsafeCount === 0 && $safeCount === 1) {
                continue;
            }

            $this->error(
                'NativePHP bridge logging template was unexpected.',
            );

            return self::FAILURE;
        }

        if (
            $updated &&
            file_put_contents(
                $bridgePath,
                $bridgeSource,
            ) === false
        ) {
            $this->error(
                'NativePHP Android bridge source could not be updated.',
            );

            return self::FAILURE;
        }

        $this->info(
            $updated
                ? 'Redacted NativePHP Android bridge payload logs.'
                : 'NativePHP Android bridge payload logs are already redacted.',
        );

        return self::SUCCESS;
    }
}