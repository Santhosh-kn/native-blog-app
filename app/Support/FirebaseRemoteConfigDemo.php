<?php

declare(strict_types=1);

namespace App\Support;

use Bbs\FirebaseRemoteConfig\FirebaseRemoteConfig;
use Bbs\FirebaseRemoteConfig\Support\RemoteConfigErrorCode as Error;

/** Firebase owns its disk cache. PHP keeps no second copy of effective values. */
final class FirebaseRemoteConfigDemo
{
    public const DEFAULTS = [
        'welcome_message' => ['type' => 'string', 'value' => 'Welcome to Native Blog!'],
        'show_media_optimizer' => ['type' => 'boolean', 'value' => true],
        'media_image_quality' => ['type' => 'integer', 'value' => 80],
    ];

    public function __construct(private readonly FirebaseRemoteConfig $native) {}

    public function snapshot(): array
    {
        // This starts only the SDK's cached initialization. It never fetches or activates.
        $info = $this->native->getInfo();
        $values = [];
        foreach (self::DEFAULTS as $key => $default) {
            $entry = [...$default, 'source' => 'local', 'nativeSource' => null, 'fallbackReason' => null];
            if ($info->success && $info->ready && $info->defaultsReady) {
                // Independent reads preserve other valid settings if one remote value is invalid.
                $reply = $this->native->getValue($key, $default['type']);
                if ($reply->success) {
                    $entry['nativeSource'] = $reply->source;
                    if ($key !== 'media_image_quality' || ($reply->value >= 1 && $reply->value <= 100)) {
                        $entry['value'] = $reply->value;
                        $entry['source'] = $reply->source;
                    } else {
                        $entry['fallbackReason'] = 'Image quality must be between 1 and 100.';
                    }
                } else {
                    $entry['fallbackReason'] = self::message($reply->errorCode);
                }
            } else {
                $entry['fallbackReason'] = $info->success
                    ? 'Local defaults are in use while native settings become ready.'
                    : self::message($info->errorCode);
            }
            $values[$key] = $entry;
        }

        return ['values' => $values, 'defaults' => self::DEFAULTS, 'info' => (array) $info];
    }

    public function imageQuality(): int
    {
        return $this->snapshot()['values']['media_image_quality']['value'];
    }

    public static function message(?string $code): string
    {
        return match ($code) {
            Error::NATIVE_UNAVAILABLE => 'Native Remote Config is unavailable. Local defaults remain usable.',
            Error::NOT_INITIALIZED => 'Native settings are still initializing. Local defaults remain usable.',
            Error::DEFAULTS_NOT_READY => 'Register local defaults before fetching or activating.',
            Error::BUSY => 'Another native settings task is still running or settling. Refresh its status.',
            Error::THROTTLED => 'Firebase has throttled fetching. Wait until the retry time before fetching again.',
            Error::OPERATION_TIMEOUT => 'The operation timed out. Its native task may still be settling; refresh values and status.',
            Error::PROCESS_INTERRUPTED => 'The app stopped before the operation finished. Its outcome is unconfirmed.',
            Error::RESULT_NOT_FOUND => 'No retained result was found. Read current values before deciding to start another operation.',
            Error::FETCH_FAILED => 'Fetching failed. Existing activated values and local defaults remain usable.',
            Error::ACTIVATION_FAILED => 'Activation failed. Read the current effective values.',
            Error::CONFIGURE_FAILED => 'The fetch settings could not be applied.',
            Error::DEFAULTS_FAILED => 'Native default registration failed. PHP local defaults remain usable.',
            Error::PERSIST_FAILED => 'The operation record could not be confirmed. Refresh this same request; do not assume it failed to start.',
            Error::INVALID_VALUE => 'This native value is invalid. Its validated local default is in use.',
            Error::INVALID_NATIVE_RESPONSE => 'The native response could not be confirmed. Refresh status and current values.',
            Error::LIMIT_EXCEEDED => 'The bounded settings limit was reached.',
            Error::REQUEST_ID_CONFLICT => 'This request ID already belongs to different settings.',
            default => 'The settings request could not be completed. Check its inputs and refresh status.',
        };
    }
}
