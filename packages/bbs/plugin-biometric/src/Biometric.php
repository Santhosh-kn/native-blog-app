<?php

namespace Bbs\Biometric;

class Biometric
{
    public function isAvailable(): ?object
    {
        if (function_exists('nativephp_call')) {
            $result = nativephp_call('Biometric.IsAvailable', '{}');
            if ($result) {
                $decoded = json_decode($result);
                return $decoded->data ?? null;
            }
        }
        return null;
    }

    public function authenticate(string $title = 'Authenticate', string $subtitle = 'Confirm your identity', ?string $id = null): void
    {
        if (function_exists('nativephp_call')) {
            nativephp_call('Biometric.Authenticate', json_encode([
                'title' => $title,
                'subtitle' => $subtitle,
                'id' => $id,
            ]));
        }
    }
}