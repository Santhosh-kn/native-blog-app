<?php

beforeEach(function () {
    $this->pluginPath = dirname(__DIR__);
    $this->manifestPath = $this->pluginPath . '/nativephp.json';
    $this->manifest = json_decode(
        file_get_contents($this->manifestPath),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
});

describe('Android-only plugin manifest', function () {
    it('declares Android as its only platform', function () {
        expect($this->manifest['platforms'])->toBe(['android']);
    });

    it('has the expected plugin identity', function () {
        expect($this->manifest)
            ->toHaveKeys(['name', 'namespace', 'bridge_functions']);

        expect($this->manifest['name'])->toBe('bbs/plugin-native-nfc');
        expect($this->manifest['namespace'])->toBe('NativeNfc');
    });

    it('declares all six Android bridge functions', function () {
        $expected = [
            'NativeNfc.IsAvailable',
            'NativeNfc.StartScan',
            'NativeNfc.StartWrite',
            'NativeNfc.GetStatus',
            'NativeNfc.StopSession',
            'NativeNfc.ConsumeResult',
        ];

        $actual = array_column(
            $this->manifest['bridge_functions'],
            'name'
        );

        expect($actual)->toBe($expected);

        foreach ($this->manifest['bridge_functions'] as $function) {
            expect($function)->toHaveKey('android');
            expect($function['android'])->toStartWith(
                'com.bbs.plugins.native_nfc.NativeNfcFunctions.'
            );
            expect($function)->not->toHaveKey('ios');
        }
    });

    it('declares NFC permission', function () {
        expect($this->manifest['android']['permissions'])
            ->toContain('android.permission.NFC');
    });

    it('declares NFC hardware as optional', function () {
        expect($this->manifest['android']['features'])->toContain([
            'name' => 'android.hardware.nfc',
            'required' => false,
        ]);
    });

    it('uses Android minimum API 33', function () {
        expect($this->manifest['android']['min_version'])->toBe(33);
    });

    it('has valid JSON', function () {
        expect($this->manifest)->toBeArray();
    });
});

describe('Native Android implementation', function () {
    it('has all required Kotlin implementation files', function () {
        $files = [
            'NativeNfcFunctions.kt',
            'NativeNfcContract.kt',
            'NativeNfcRequest.kt',
            'NativeNfcAvailability.kt',
            'NativeNfcTagHandler.kt',
            'NativeNfcCoordinator.kt',
            'NativeNfcStore.kt',
            'NativeNfcResult.kt',
            'NativeNfcStorage.kt',
            'NativeNfcEventDispatcher.kt',
        ];

        foreach ($files as $file) {
            expect(file_exists(
                $this->pluginPath . '/resources/android/' . $file
            ))->toBeTrue("Missing Android implementation file: {$file}");
        }
    });

    it('declares the correct Kotlin package and bridge object', function () {
        $content = file_get_contents(
            $this->pluginPath . '/resources/android/NativeNfcFunctions.kt'
        );

        expect($content)
            ->toContain('package com.bbs.plugins.native_nfc')
            ->toContain('object NativeNfcFunctions')
            ->toContain('BridgeFunction');
    });
});

describe('PHP implementation', function () {
    it('has the service provider and facade', function () {
        expect(file_exists(
            $this->pluginPath . '/src/NativeNfcServiceProvider.php'
        ))->toBeTrue();

        expect(file_exists(
            $this->pluginPath . '/src/Facades/NativeNfc.php'
        ))->toBeTrue();
    });

    it('has the public API and supporting classes', function () {
        $files = [
            'src/NativeNfc.php',
            'src/NativeNfcResult.php',
            'src/Contracts/NativeBridge.php',
            'src/Exceptions/NativeNfcException.php',
            'src/Support/NativePhpBridge.php',
            'src/Support/NativeNfcErrorCode.php',
            'src/Support/NativeNfcRequestValidator.php',
        ];

        foreach ($files as $file) {
            expect(file_exists($this->pluginPath . '/' . $file))
                ->toBeTrue("Missing PHP implementation file: {$file}");
        }
    });
});

describe('Composer configuration', function () {
    it('has valid plugin metadata and autoload configuration', function () {
        $composer = json_decode(
            file_get_contents($this->pluginPath . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        expect($composer['name'])->toBe('bbs/plugin-native-nfc');
        expect($composer['type'])->toBe('nativephp-plugin');
        expect($composer['autoload']['psr-4']['Bbs\\NativeNfc\\'])
            ->toBe('src/');
        expect($composer['extra']['nativephp']['manifest'])
            ->toBe('nativephp.json');
    });
});