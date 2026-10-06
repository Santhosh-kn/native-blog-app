<?php

declare(strict_types=1);

namespace Bbs\NativeCalendar\Tests;

use Bbs\NativeCalendar\Commands\ConfigureAndroidManifestCommand;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ConfigureAndroidManifestCommandTest extends TestCase
{
    private string $root;

    private string $manifestPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir()
            .DIRECTORY_SEPARATOR.'native-calendar-hook-'
            .bin2hex(random_bytes(12));

        $directory = $this->root
            .DIRECTORY_SEPARATOR.'app'
            .DIRECTORY_SEPARATOR.'src'
            .DIRECTORY_SEPARATOR.'main';

        self::assertTrue(mkdir($directory, 0777, true));

        $this->manifestPath = $directory
            .DIRECTORY_SEPARATOR.'AndroidManifest.xml';

        file_put_contents(
            $this->manifestPath,
            '<manifest xmlns:android="http://schemas.android.com/apk/res/android">'
            .'<application android:label="private-fixture-value"/>'
            .'</manifest>'
        );
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->root);

        parent::tearDown();
    }

    private function runHook(array $options = []): CommandTester
    {
        $command = new ConfigureAndroidManifestCommand();
        $application = new Application($this->root);
        $application->instance('env', 'testing');

        $command->setLaravel($application);

        $tester = new CommandTester($command);
        $tester->execute(
            array_merge([
                '--platform' => 'android',
                '--build-path' => $this->root,
                '--plugin-path' => 'private-fixture-value',
                '--app-id' => 'com.example.calendar.test',
                '--config' => '{"marker":"private-fixture-value"}',
                '--plugins' => '[]',
            ], $options),
            ['interactive' => false]
        );

        return $tester;
    }

    public function test_accepts_framework_options_and_configures_queries(): void
    {
        $tester = $this->runHook();

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringNotContainsString(
            'private-fixture-value',
            $tester->getDisplay()
        );

        $document = new DOMDocument();
        self::assertTrue($document->load($this->manifestPath, LIBXML_NONET));
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace(
            'android',
            'http://schemas.android.com/apk/res/android'
        );

        self::assertSame(3, $xpath->query('/manifest/queries/intent')->length);
        self::assertSame(
            'private-fixture-value',
            $xpath->evaluate('string(/manifest/application/@android:label)')
        );

        $once = file_get_contents($this->manifestPath);
        $again = $this->runHook();

        self::assertSame(0, $again->getStatusCode());
        self::assertSame($once, file_get_contents($this->manifestPath));
    }

    public function test_ios_does_not_modify_the_manifest(): void
    {
        $before = file_get_contents($this->manifestPath);
        $tester = $this->runHook(['--platform' => 'ios']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame($before, file_get_contents($this->manifestPath));
    }

    public function test_rejects_an_unsupported_platform(): void
    {
        $before = file_get_contents($this->manifestPath);
        $tester = $this->runHook(['--platform' => 'unsupported']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame($before, file_get_contents($this->manifestPath));
    }

    public function test_rejects_a_missing_build_directory_without_echoing_it(): void
    {
        $tester = $this->runHook([
            '--build-path' => $this->root.'/private-fixture-value',
        ]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringNotContainsString(
            'private-fixture-value',
            $tester->getDisplay()
        );
    }

    public function test_rejects_a_missing_manifest(): void
    {
        self::assertTrue(unlink($this->manifestPath));
        $tester = $this->runHook();

        self::assertSame(1, $tester->getStatusCode());
        self::assertFileDoesNotExist($this->manifestPath);
    }

    public function test_invalid_xml_is_unchanged_and_not_printed(): void
    {
        $invalid = '<manifest><application label="private-fixture-value">';
        file_put_contents($this->manifestPath, $invalid);

        $tester = $this->runHook();

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame($invalid, file_get_contents($this->manifestPath));
        self::assertStringNotContainsString(
            'private-fixture-value',
            $tester->getDisplay()
        );
    }

    public function test_rejects_an_empty_build_path_without_modifying_the_manifest(): void
    {
        $before = file_get_contents($this->manifestPath);
        $tester = $this->runHook(['--build-path' => '']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame($before, file_get_contents($this->manifestPath));
        self::assertStringNotContainsString(
            'private-fixture-value',
            $tester->getDisplay()
        );
    }

    public function test_oversized_xml_is_unchanged_and_not_printed(): void
    {
        $oversized = '<manifest><application label="private-fixture-value"/></manifest>'
            .str_repeat(' ', 2_097_153);

        self::assertSame(
            strlen($oversized),
            file_put_contents($this->manifestPath, $oversized)
        );

        $tester = $this->runHook();

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame($oversized, file_get_contents($this->manifestPath));
        self::assertStringNotContainsString(
            'private-fixture-value',
            $tester->getDisplay()
        );
    }
}
