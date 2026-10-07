<?php

declare(strict_types=1);

namespace Bbs\NativeMediaOptimizer\Support;

use JsonException;

final class NativeMediaOptimizerRequestValidator
{
    public const MAX_REQUEST_BYTES = 8192;
    public const MAX_INPUT_BYTES = 536_870_912;
    public const MAX_IMAGE_INPUT_BYTES = 104_857_600;
    public const MAX_IMAGE_SOURCE_PIXELS = 100_000_000;
    public const MAX_IMAGE_EDGE = 4096;
    public const MAX_IMAGE_OUTPUT_PIXELS = 16_777_216;
    public const MAX_THUMBNAIL_EDGE = 2048;
    public const MAX_VIDEO_EDGE = 1920;
    public const MAX_VIDEO_OUTPUT_PIXELS = 2_073_600;
    public const MAX_DURATION_MS = 3_600_000;
    public const MIN_VIDEO_BITRATE = 128_000;
    public const MAX_VIDEO_BITRATE = 20_000_000;
    public const MIN_AUDIO_BITRATE = 32_000;
    public const MAX_AUDIO_BITRATE = 320_000;

    private const ALLOWED_KEYS = [
        'IsAvailable' => [],
        'InspectMedia' => ['id', 'source_document_id'],
        'OptimizeImage' => ['id', 'source_document_id', 'max_width', 'max_height', 'format', 'quality'],
        'OptimizeVideo' => ['id', 'source_document_id', 'max_width', 'max_height', 'video_bitrate', 'audio_bitrate', 'start_ms', 'end_ms', 'remove_audio'],
        'GenerateThumbnail' => ['id', 'source_document_id', 'max_width', 'max_height', 'format', 'quality', 'timestamp_ms'],
        'GetStatus' => ['id'],
        'Cancel' => ['id'],
        'GetResult' => ['id'],
        'DeleteOutput' => ['id'],
    ];

    /**
     * Validate complete bridge options. The service generates id before calling.
     * Missing processing settings have documented defaults; explicit nulls do not.
     * Native code must repeat validation and inspect the actual media contents.
     *
     * @param array<string, mixed> $options
     */
    public static function validate(string $operation, array $options): ?string
    {
        if (! array_key_exists($operation, self::ALLOWED_KEYS)) {
            return NativeMediaOptimizerErrorCode::INVALID_OPTIONS;
        }

        try {
            $json = json_encode($options === [] ? (object) [] : $options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES, 16);
        } catch (JsonException) {
            return NativeMediaOptimizerErrorCode::INVALID_OPTIONS;
        }

        if (strlen($json) > self::MAX_REQUEST_BYTES) {
            return NativeMediaOptimizerErrorCode::REQUEST_TOO_LARGE;
        }

        foreach (array_keys($options) as $key) {
            if (! is_string($key) || ! in_array($key, self::ALLOWED_KEYS[$operation], true)) {
                return NativeMediaOptimizerErrorCode::INVALID_OPTIONS;
            }
        }

        if ($operation === 'IsAvailable') {
            return null;
        }

        if (! self::isRequestId($options['id'] ?? null)) {
            return NativeMediaOptimizerErrorCode::INVALID_REQUEST_ID;
        }

        if (! in_array($operation, ['InspectMedia', 'OptimizeImage', 'OptimizeVideo', 'GenerateThumbnail'], true)) {
            return null;
        }

        if (! self::isRequestId($options['source_document_id'] ?? null)) {
            return NativeMediaOptimizerErrorCode::INVALID_SOURCE_ID;
        }

        return match ($operation) {
            'OptimizeImage', 'GenerateThumbnail' => self::validateImage($options, $operation === 'GenerateThumbnail'),
            'OptimizeVideo' => self::validateVideo($options),
            default => null,
        };
    }

    public static function isRequestId(mixed $value): bool
    {
        return is_string($value) && strlen($value) === 36 && preg_match(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
            $value,
        ) === 1;
    }

    /** @param array<string, mixed> $options */
    private static function validateImage(array $options, bool $thumbnail): ?string
    {
        $defaultEdge = $thumbnail ? 512 : 1920;
        $maximumEdge = $thumbnail ? self::MAX_THUMBNAIL_EDGE : self::MAX_IMAGE_EDGE;
        $width = self::option($options, 'max_width', $defaultEdge);
        $height = self::option($options, 'max_height', $defaultEdge);

        if (! self::integerInRange($width, 1, $maximumEdge) || ! self::integerInRange($height, 1, $maximumEdge) || $width * $height > self::MAX_IMAGE_OUTPUT_PIXELS) {
            return NativeMediaOptimizerErrorCode::INVALID_DIMENSIONS;
        }

        $format = self::option($options, 'format', 'jpeg');
        if (! is_string($format) || ! in_array($format, ['jpeg', 'png', 'webp'], true)) {
            return NativeMediaOptimizerErrorCode::INVALID_FORMAT;
        }

        if ($format === 'png') {
            if (array_key_exists('quality', $options)) {
                return NativeMediaOptimizerErrorCode::INVALID_QUALITY;
            }
        } elseif (! self::integerInRange(self::option($options, 'quality', 80), 1, 100)) {
            return NativeMediaOptimizerErrorCode::INVALID_QUALITY;
        }

        if ($thumbnail && ! self::integerInRange(self::option($options, 'timestamp_ms', 0), 0, self::MAX_DURATION_MS - 1)) {
            return NativeMediaOptimizerErrorCode::INVALID_TIME_RANGE;
        }

        return null;
    }

    /** @param array<string, mixed> $options */
    private static function validateVideo(array $options): ?string
    {
        $width = self::option($options, 'max_width', 1920);
        $height = self::option($options, 'max_height', 1080);
        if (! self::integerInRange($width, 16, self::MAX_VIDEO_EDGE) || ! self::integerInRange($height, 16, self::MAX_VIDEO_EDGE) || $width % 2 !== 0 || $height % 2 !== 0 || $width * $height > self::MAX_VIDEO_OUTPUT_PIXELS) {
            return NativeMediaOptimizerErrorCode::INVALID_DIMENSIONS;
        }

        if (! self::integerInRange(self::option($options, 'video_bitrate', 2_500_000), self::MIN_VIDEO_BITRATE, self::MAX_VIDEO_BITRATE) || ! self::integerInRange(self::option($options, 'audio_bitrate', 128_000), self::MIN_AUDIO_BITRATE, self::MAX_AUDIO_BITRATE)) {
            return NativeMediaOptimizerErrorCode::INVALID_BITRATE;
        }

        if (! is_bool(self::option($options, 'remove_audio', false))) {
            return NativeMediaOptimizerErrorCode::INVALID_OPTIONS;
        }

        $start = self::option($options, 'start_ms', 0);
        if (! self::integerInRange($start, 0, self::MAX_DURATION_MS - 1)) {
            return NativeMediaOptimizerErrorCode::INVALID_TIME_RANGE;
        }

        if (array_key_exists('end_ms', $options)) {
            $end = $options['end_ms'];
            if (! self::integerInRange($end, 1, self::MAX_DURATION_MS) || $end <= $start) {
                return NativeMediaOptimizerErrorCode::INVALID_TIME_RANGE;
            }
        }

        return null;
    }

    private static function integerInRange(mixed $value, int $minimum, int $maximum): bool
    {
        return is_int($value) && $value >= $minimum && $value <= $maximum;
    }

    /** @param array<string, mixed> $options */
    private static function option(array $options, string $key, mixed $default): mixed
    {
        return array_key_exists($key, $options) ? $options[$key] : $default;
    }
}
