<?php

declare(strict_types=1);

namespace App\Support;

use Bbs\NativeDocumentPicker\Support\NativeDocumentPickerResult;
use Bbs\NativeMediaOptimizer\Support\NativeMediaOptimizerRequestValidator as Validator;
use Bbs\NativeMediaOptimizer\Support\NativeMediaOptimizerResult;

/** Trusted PHP references only. Public responses never contain these paths. */
final class NativeMediaOptimizerFileGuard
{
    public const MAX_PREVIEW_BYTES = 12 * 1024 * 1024;
    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'video/mp4' => 'mp4'];

    public function selection(NativeDocumentPickerResult $result, string $id, string $kind): ?array
    {
        $mimes = $kind === 'image' ? NativeMediaOptimizerDemoState::IMAGE_MIMES : NativeMediaOptimizerDemoState::VIDEO_MIMES;
        $maximum = $kind === 'image' ? Validator::MAX_IMAGE_INPUT_BYTES : Validator::MAX_INPUT_BYTES;
        if ($result->id !== $id || $result->status !== 'succeeded' || ! $result->success || $result->cancelled ||
            $result->errorCode !== null || ! in_array($result->mimeType, $mimes, true) || ! is_int($result->size) ||
            $result->size < 1 || $result->size > $maximum || ! is_string($result->path)) {
            return null;
        }
        $path = $this->privateFile($result->path, 'native-document-picker', $id);
        if ($path === null || filesize($path) !== $result->size) { return null; }
        return ['id' => $id, 'mime_type' => $result->mimeType, 'size' => $result->size];
    }

    public function output(NativeMediaOptimizerResult $result): ?array
    {
        $output = $result->output;
        if (! $result->success || ! $result->outputAvailable || $output === null || $output['id'] !== $result->id) {
            return null;
        }
        $extension = self::EXTENSIONS[$output['mime_type']] ?? null;
        if ($extension === null) { return null; }
        $path = $this->privateFile($output['path'], 'native-media-optimizer', $output['id'], $extension);
        if ($path === null || filesize($path) !== $output['size']) { return null; }
        if (str_starts_with($output['mime_type'], 'image/')) {
            $image = @getimagesize($path);
            if (! is_array($image) || $image[0] !== $output['width'] || $image[1] !== $output['height'] || ($image['mime'] ?? null) !== $output['mime_type']) {
                return null;
            }
        } else {
            $handle = @fopen($path, 'rb');
            if ($handle === false) { return null; }
            try { $header = fread($handle, 12); } finally { fclose($handle); }
            if (! is_string($header) || strlen($header) !== 12 || substr($header, 4, 4) !== 'ftyp') { return null; }
        }
        $stat = @stat($path);
        if (! is_array($stat)) { return null; }
        return [...$output, 'path' => $path, 'file_stat' => $stat];
    }

    /** Bounded ASCII transport for NativePHP's local string-based HTTP response. */
    public function preview(array $verified): ?string
    {
        if ($verified['size'] > self::MAX_PREVIEW_BYTES) { return null; }
        $handle = @fopen($verified['path'], 'rb');
        if ($handle === false) { return null; }
        try {
            $stat = fstat($handle);
            if (! is_array($stat) || $stat['size'] !== $verified['size'] ||
                $stat['dev'] !== $verified['file_stat']['dev'] || $stat['ino'] !== $verified['file_stat']['ino']) { return null; }
            $bytes = stream_get_contents($handle, self::MAX_PREVIEW_BYTES + 1);
            clearstatcache(true, $verified['path']);
            if (! is_string($bytes) || strlen($bytes) !== $verified['size'] || is_link($verified['path']) ||
                $this->privateFile($verified['path'], 'native-media-optimizer', $verified['id'], self::EXTENSIONS[$verified['mime_type']]) === null ||
                filesize($verified['path']) !== $verified['size']) { return null; }
            $after = @stat($verified['path']);
            if (! is_array($after) || $after['dev'] !== $stat['dev'] || $after['ino'] !== $stat['ino'] || $after['size'] !== $stat['size']) { return null; }
            return base64_encode($bytes);
        } finally { fclose($handle); }
    }

    private function privateFile(string $path, string $directory, string $id, ?string $extension = null): ?string
    {
        if (! Validator::isRequestId($id) || strlen($path) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $path) === 1 || is_link($path)) {
            return null;
        }
        $root = app()->storagePath('app/'.$directory);
        if (is_link($root)) { return null; }
        $parent = realpath(app()->storagePath('app'));
        $canonicalRoot = realpath($root);
        $file = realpath($path);
        if (! is_string($parent) || ! is_string($canonicalRoot) || ! is_string($file) || ! is_file($file) || ! is_readable($file)) { return null; }
        $expectedRoot = $this->normalize($parent.'/'.$directory);
        // Windows may expand the configured temporary directory's 8.3 alias.
        // Both spellings must still identify the exact owned file in the fixed root.
        $requested = $this->normalize($path);
        $allowedPaths = [$this->normalize($file), $this->normalize($root.'/'.basename($file))];
        if ($this->normalize($canonicalRoot) !== $expectedRoot || $this->normalize(dirname($file)) !== $expectedRoot ||
            ! in_array($requested, $allowedPaths, true)) { return null; }
        $name = basename($file);
        $validName = $extension !== null ? $name === $id.'.'.$extension : preg_match('/\A'.preg_quote($id, '/').'(?:\.[a-z0-9]{1,16})?\z/D', $name) === 1;
        if (! $validName) { return null; }
        clearstatcache(true, $file);
        return $file;
    }

    private function normalize(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        return DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
    }
}
