<?php

namespace Bbs\FirebaseGoogleAuth\Commands;

use Native\Mobile\Plugins\Commands\NativePluginHookCommand;

/**
 * Copy Firebase configuration files into the generated native project.
 *
 * NativePHP recreates the nativephp directory during installation, so Firebase
 * configuration must be copied from the permanent plugin resources directory
 * during every native build.
 */
class CopyAssetsCommand extends NativePluginHookCommand
{
    protected $signature = 'nativephp:firebase-google-auth:copy-assets';

    protected $description = 'Copy Firebase configuration files for Google authentication';

    public function handle(): int
    {
        if ($this->isAndroid()) {
            return $this->copyAndroidFirebaseConfig();
        }

        if ($this->isIos()) {
            $this->info(
                'FirebaseGoogleAuth: iOS Firebase asset copying is not implemented yet.'
            );
        }

        return self::SUCCESS;
    }

    /**
     * Copy google-services.json to the Android app module root.
     */
    protected function copyAndroidFirebaseConfig(): int
    {
        $source = $this->pluginPath().'/resources/google-services.json';
        $destination = $this->buildPath().'/app/google-services.json';

        if (! file_exists($source)) {
            $this->error(
                'FirebaseGoogleAuth: resources/google-services.json was not found.'
            );

            return self::FAILURE;
        }

        $copied = $this->copyFile($source, $destination);

        if (! $copied) {
            $this->error(
                'FirebaseGoogleAuth: unable to copy google-services.json.'
            );

            return self::FAILURE;
        }

        $this->info(
            'FirebaseGoogleAuth: google-services.json copied successfully.'
        );

        return self::SUCCESS;
    }
}