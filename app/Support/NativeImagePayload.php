<?php

namespace App\Support;

use RuntimeException;
use Symfony\Component\Mime\MimeTypes;

final class NativeImagePayload
{
    private const MAX_IMAGE_BYTES = 12 * 1024 * 1024;

    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
    ];

    /**
     * @return array{mime_type: string, extension: string, contents: string}
     */
    public static function read(string $path): array
    {
        $resolvedPath = realpath($path);

        if (
            $resolvedPath === false ||
            ! is_file($resolvedPath) ||
            ! is_readable($resolvedPath)
        ) {
            throw new RuntimeException('Image file is unavailable.');
        }

        $bytes = filesize($resolvedPath);

        if (
            ! is_int($bytes) ||
            $bytes < 1 ||
            $bytes > self::MAX_IMAGE_BYTES
        ) {
            throw new RuntimeException('Image file size is invalid.');
        }

        $mimeType = MimeTypes::getDefault()
            ->guessMimeType($resolvedPath);

        if (
            ! is_string($mimeType) ||
            ! array_key_exists($mimeType, self::MIME_EXTENSIONS)
        ) {
            throw new RuntimeException('Image file type is unsupported.');
        }

        $contents = file_get_contents($resolvedPath);

        if (! is_string($contents) || $contents === '') {
            throw new RuntimeException('Image file could not be read.');
        }

        return [
            'mime_type' => $mimeType,
            'extension' => self::MIME_EXTENSIONS[$mimeType],
            'contents' => $contents,
        ];
    }

    /**
     * @return array{mime_type: string, base64: string}
     */
    public static function fromPath(string $path): array
    {
        $image = self::read($path);

        return [
            'mime_type' => $image['mime_type'],
            'base64' => base64_encode($image['contents']),
        ];
    }
}
