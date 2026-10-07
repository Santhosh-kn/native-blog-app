<?php

declare(strict_types=1);

namespace Bbs\NativeMediaOptimizer\Tests;

use Bbs\NativeMediaOptimizer\Contracts\NativeBridge;
use Bbs\NativeMediaOptimizer\NativeMediaOptimizer;
use Bbs\NativeMediaOptimizer\NativeMediaOptimizerServiceProvider;
use Bbs\NativeMediaOptimizer\Support\NativePhpBridge;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

final class NativeMediaOptimizerServiceProviderTest extends TestCase
{
    public function test_provider_registers_independent_singletons(): void
    {
        $application = new Application(dirname(__DIR__, 4));
        $application->useStoragePath(dirname(__DIR__, 4).'/storage');
        (new NativeMediaOptimizerServiceProvider($application))->register();
        self::assertInstanceOf(NativePhpBridge::class, $application->make(NativeBridge::class));
        self::assertSame($application->make(NativeBridge::class), $application->make(NativeBridge::class));
        self::assertInstanceOf(NativeMediaOptimizer::class, $application->make(NativeMediaOptimizer::class));
        self::assertSame($application->make(NativeMediaOptimizer::class), $application->make(NativeMediaOptimizer::class));
    }
}
