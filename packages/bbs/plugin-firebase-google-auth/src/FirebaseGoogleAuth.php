<?php

namespace Bbs\FirebaseGoogleAuth;

class FirebaseGoogleAuth
{
    /**
     * Start native Google authentication.
     */
    public function signIn(array $options = []): mixed
    {
        return $this->call(
            'FirebaseGoogleAuth.SignIn',
            $options,
        );
    }

    /**
     * Sign out of native Firebase Authentication.
     */
    public function signOut(): mixed
    {
        return $this->call(
            'FirebaseGoogleAuth.SignOut',
        );
    }

    /**
     * Call a registered NativePHP bridge function.
     */
    private function call(
        string $method,
        array $parameters = []
    ): mixed {
        if (! function_exists('nativephp_call')) {
            return null;
        }

        $result = nativephp_call(
            $method,
            json_encode($parameters),
        );

        if (! $result) {
            return null;
        }

        $decoded = json_decode($result);

        return $decoded->data ?? null;
    }
}