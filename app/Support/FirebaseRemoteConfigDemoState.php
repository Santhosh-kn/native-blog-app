<?php

declare(strict_types=1);

namespace App\Support;

use Bbs\FirebaseRemoteConfig\Support\RemoteConfigContract as Contract;
use Bbs\FirebaseRemoteConfig\Support\RemoteConfigResponse as Response;
use Illuminate\Session\Store;

/** Ownership is persisted before entering native code, even if an acknowledgement is lost. */
final class FirebaseRemoteConfigDemoState
{
    public const KEY = 'firebase_remote_config_demo_v1';
    public const LIMIT = 32;

    public function read(Store $session, string $owner): array
    {
        $raw = $session->get(self::KEY);
        $data = ['owner' => $owner, 'operations' => []];
        if (! is_array($raw) || ($raw['owner'] ?? null) !== $owner) {
            return $data;
        }
        $entries = is_array($raw['operations'] ?? null) ? array_slice($raw['operations'], -self::LIMIT, null, true) : [];
        foreach ($entries as $id => $entry) {
            if (Contract::isRequestId($id) && is_array($entry) &&
                in_array($entry['operation'] ?? null, Response::OPERATIONS, true) &&
                is_string($entry['fingerprint'] ?? null) && preg_match('/\A[a-f0-9]{64}\z/D', $entry['fingerprint']) &&
                is_bool($entry['terminal'] ?? null)) {
                $data['operations'][$id] = ['operation' => $entry['operation'], 'fingerprint' => $entry['fingerprint'],
                    'terminal' => $entry['terminal']];
            }
        }

        return $data;
    }

    public function remember(Store $session, string $owner, string $id, string $operation, string $fingerprint): bool
    {
        $data = $this->read($session, $owner);
        if (isset($data['operations'][$id])) {
            // A rejected native request is not in durable history. An explicit
            // same-ID retry can now be accepted, so protect ownership until its reply.
            $data['operations'][$id]['terminal'] = false;
            $session->put(self::KEY, $data);
            return true;
        }
        if (count($data['operations']) >= self::LIMIT) {
            $remove = null;
            foreach ($data['operations'] as $previous => $entry) {
                if ($entry['terminal']) { $remove = $previous; break; }
            }
            if ($remove === null) { return false; }
            unset($data['operations'][$remove]);
        }
        $data['operations'][$id] = ['operation' => $operation, 'fingerprint' => $fingerprint, 'terminal' => false];
        $session->put(self::KEY, $data);

        return true;
    }

    public function finish(Store $session, string $owner, string $id): void
    {
        $data = $this->read($session, $owner);
        if (isset($data['operations'][$id])) {
            $data['operations'][$id]['terminal'] = true;
            $session->put(self::KEY, $data);
        }
    }
}
