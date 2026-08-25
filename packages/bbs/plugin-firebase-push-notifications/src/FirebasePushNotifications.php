<?php

namespace Bbs\FirebasePushNotifications;

class FirebasePushNotifications
{
    public function checkPermission(): ?object
    {
        return $this->call(
            'FirebasePushNotifications.CheckPermission'
        );
    }

    public function requestPermission(): ?object
    {
        return $this->call(
            'FirebasePushNotifications.RequestPermission'
        );
    }

    public function getToken(?string $id = null): ?object
    {
        $parameters = [];

        if ($id !== null) {
            $parameters['id'] = $id;
        }

        return $this->call(
            'FirebasePushNotifications.GetToken',
            $parameters
        );
    }

    public function getStoredToken(): ?object
    {
        $reference = $this->call(
            'FirebasePushNotifications.GetStoredToken'
        );

        if ($reference === null) {
            return null;
        }

        if (! ($reference->available ?? false)) {
            return (object) [
                'available' => false,
                'token' => null,
            ];
        }

        $path = $reference->path ?? null;

        if (
            ! is_string($path) ||
            trim($path) === '' ||
            ! is_file($path) ||
            ! is_readable($path)
        ) {
            return (object) [
                'available' => false,
                'token' => null,
            ];
        }

        $token = file_get_contents($path);

        if (
            ! is_string($token) ||
            trim($token) === ''
        ) {
            return (object) [
                'available' => false,
                'token' => null,
            ];
        }

        return (object) [
            'available' => true,
            'token' => trim($token),
        ];
    }

    private function call(
        string $method,
        array $parameters = []
    ): ?object {
        if (! function_exists('nativephp_call')) {
            return null;
        }

        $result = nativephp_call(
            $method,
            json_encode($parameters, JSON_THROW_ON_ERROR)
        );

        if (! $result) {
            return null;
        }

        $decoded = json_decode($result);

        return is_object($decoded) ? $decoded : null;
    }
}
