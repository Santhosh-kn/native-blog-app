<?php

declare(strict_types=1);

namespace App\Support;

use Bbs\NativeMediaOptimizer\Support\NativeMediaOptimizerRequestValidator as Validator;
use Illuminate\Session\Store;

/** Session ownership is also bound to the authenticated Laravel user. */
final class NativeMediaOptimizerDemoState
{
    public const KEY = 'native_media_optimizer_demo_v1';
    public const OPERATIONS = ['InspectMedia', 'OptimizeImage', 'OptimizeVideo', 'GenerateThumbnail'];
    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];
    public const VIDEO_MIMES = ['video/mp4', 'video/webm', 'video/quicktime'];

    public function read(Store $session, string $owner): array
    {
        $raw = $session->get(self::KEY);
        $data = ['owner' => $owner, 'picks' => [], 'jobs' => [], 'selection' => null];
        if (! is_array($raw) || ($raw['owner'] ?? null) !== $owner) {
            return $data;
        }
        foreach (['picks' => 10, 'jobs' => 50] as $key => $limit) {
            $entries = is_array($raw[$key] ?? null) ? array_slice($raw[$key], -$limit, null, true) : [];
            foreach ($entries as $id => $entry) {
                if (! Validator::isRequestId($id) || ! is_array($entry) || ! is_bool($entry['terminal'] ?? null)) {
                    continue;
                }
                if ($key === 'picks' && in_array($entry['kind'] ?? null, ['image', 'video'], true)) {
                    $data[$key][$id] = ['kind' => $entry['kind'], 'terminal' => $entry['terminal']];
                } elseif ($key === 'jobs' && in_array($entry['operation'] ?? null, self::OPERATIONS, true) && Validator::isRequestId($entry['source_document_id'] ?? null)) {
                    // Older sessions may own a completed output without this flag.
                    // Preserve access until a native status query proves it unavailable.
                    $outputAvailable = is_bool($entry['output_available'] ?? null)
                        ? $entry['output_available']
                        : $entry['operation'] !== 'InspectMedia';
                    $data[$key][$id] = ['operation' => $entry['operation'], 'source_document_id' => $entry['source_document_id'],
                        'terminal' => $entry['terminal'], 'output_available' => $outputAvailable];
                }
            }
        }
        $selection = $raw['selection'] ?? null;
        if (is_array($selection) && Validator::isRequestId($selection['id'] ?? null) &&
            in_array($selection['mime_type'] ?? null, [...self::IMAGE_MIMES, ...self::VIDEO_MIMES], true) &&
            is_int($selection['size'] ?? null) && $selection['size'] >= 1 && $selection['size'] <= Validator::MAX_INPUT_BYTES &&
            (! in_array($selection['mime_type'], self::IMAGE_MIMES, true) || $selection['size'] <= Validator::MAX_IMAGE_INPUT_BYTES)) {
            $data['selection'] = ['id' => $selection['id'], 'mime_type' => $selection['mime_type'], 'size' => $selection['size']];
        }
        return $data;
    }

    public function rememberPick(Store $session, string $owner, string $id, string $kind): void
    {
        $data = $this->read($session, $owner);
        $data['picks'] = array_slice($data['picks'], -9, null, true);
        $data['picks'][$id] = ['kind' => $kind, 'terminal' => false];
        $session->put(self::KEY, $data);
    }

    public function finishPick(Store $session, string $owner, string $id, ?array $selection = null): void
    {
        $data = $this->read($session, $owner);
        if (! isset($data['picks'][$id])) {
            return;
        }
        $data['picks'][$id]['terminal'] = true;
        if ($selection !== null) {
            $data['selection'] = $selection;
        }
        $session->put(self::KEY, $data);
    }

    /** Retain active jobs and every owned output until deletion or reconciliation. */
    public function rememberJob(Store $session, string $owner, string $id, string $operation, string $source): bool
    {
        $data = $this->read($session, $owner);
        if (count($data['jobs']) >= 50) {
            $remove = null;
            foreach ($data['jobs'] as $previousId => $job) {
                if ($job['terminal'] && ! $job['output_available']) { $remove = $previousId; break; }
            }
            if ($remove === null) { return false; }
            unset($data['jobs'][$remove]);
        }
        $data['jobs'][$id] = ['operation' => $operation, 'source_document_id' => $source, 'terminal' => false, 'output_available' => false];
        $session->put(self::KEY, $data);
        return true;
    }

    public function finishJob(Store $session, string $owner, string $id, bool $outputAvailable): void
    {
        $data = $this->read($session, $owner);
        if (isset($data['jobs'][$id])) {
            $data['jobs'][$id]['terminal'] = true;
            $data['jobs'][$id]['output_available'] = $outputAvailable;
            $session->put(self::KEY, $data);
        }
    }
}
