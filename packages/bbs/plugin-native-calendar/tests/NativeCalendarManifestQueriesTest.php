<?php

declare(strict_types=1);

namespace Bbs\NativeCalendar\Tests;

use Bbs\NativeCalendar\Support\NativeCalendarManifestQueries;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NativeCalendarManifestQueriesTest extends TestCase
{
    private const ANDROID_NS = 'http://schemas.android.com/apk/res/android';

    private function manifest(string $queries = ''): string
    {
        return '<manifest xmlns:android="'.self::ANDROID_NS.'" package="com.example.fixture">'
            .'<uses-sdk android:minSdkVersion="33"/>'
            .$queries
            .'<application android:label="Synthetic Calendar Fixture"/>'
            .'</manifest>';
    }

    private function inspect(string $xml): DOMXPath
    {
        $document = new DOMDocument();
        self::assertTrue($document->loadXML($xml, LIBXML_NONET));

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('android', self::ANDROID_NS);

        return $xpath;
    }

    public function test_adds_exactly_the_three_calendar_intent_signatures(): void
    {
        $configured = NativeCalendarManifestQueries::apply($this->manifest());
        $xpath = $this->inspect($configured);

        self::assertSame(1, $xpath->query('/manifest/queries')->length);
        self::assertSame(3, $xpath->query('/manifest/queries/intent')->length);

        $actual = [];

        foreach ($xpath->query('/manifest/queries/intent') as $intent) {
            self::assertSame(1, $xpath->query('action', $intent)->length);
            self::assertSame(1, $xpath->query('data', $intent)->length);
            self::assertSame(2, $xpath->query('*', $intent)->length);

            $actual[] = implode('|', [
                $xpath->evaluate('string(action/@android:name)', $intent),
                $xpath->evaluate('string(data/@android:scheme)', $intent),
                $xpath->evaluate('string(data/@android:host)', $intent),
                $xpath->evaluate('string(data/@android:mimeType)', $intent),
            ]);
        }

        self::assertSame([
            'android.intent.action.INSERT|||vnd.android.cursor.dir/event',
            'android.intent.action.VIEW|content|com.android.calendar|vnd.android.cursor.item/event',
            'android.intent.action.VIEW|content|com.android.calendar|time/epoch',
        ], $actual);

        self::assertSame(0, $xpath->query('/manifest/uses-permission')->length);
        self::assertSame(0, $xpath->query('/manifest/queries/package')->length);
        self::assertSame(0, $xpath->query('/manifest/queries/provider')->length);
        self::assertSame(0, $xpath->query('/manifest/queries/intent/data/@android:path')->length);
        self::assertSame(0, $xpath->query('/manifest/queries/intent/data/@android:pathPrefix')->length);
        self::assertSame(0, $xpath->query('/manifest/queries/intent/data/@android:pathPattern')->length);
        self::assertSame(
            '33',
            $xpath->evaluate('string(/manifest/uses-sdk/@android:minSdkVersion)')
        );
        self::assertSame(
            'Synthetic Calendar Fixture',
            $xpath->evaluate('string(/manifest/application/@android:label)')
        );
        self::assertSame(
            'queries',
            $xpath->evaluate('name(/manifest/application/preceding-sibling::*[1])')
        );
    }

    public function test_second_application_preserves_the_exact_bytes(): void
    {
        $once = NativeCalendarManifestQueries::apply($this->manifest());

        self::assertSame($once, NativeCalendarManifestQueries::apply($once));
    }

    public function test_preserves_contacts_queries_and_other_existing_entries(): void
    {
        $existing = '<queries>'
            .'<!-- Synthetic existing entries -->'
            .'<package android:name="com.example.synthetic"/>'
            .'<provider android:authorities="com.example.synthetic.provider"/>'
            .'<intent>'
            .'<action android:name="android.intent.action.PICK"/>'
            .'<data android:mimeType="vnd.android.cursor.dir/contact"/>'
            .'</intent>'
            .'</queries>';

        $configured = NativeCalendarManifestQueries::apply($this->manifest($existing));
        $xpath = $this->inspect($configured);

        self::assertSame(1, $xpath->query('/manifest/queries')->length);
        self::assertSame(4, $xpath->query('/manifest/queries/intent')->length);
        self::assertSame(
            'com.example.synthetic',
            $xpath->evaluate('string(/manifest/queries/package/@android:name)')
        );
        self::assertSame(
            'com.example.synthetic.provider',
            $xpath->evaluate('string(/manifest/queries/provider/@android:authorities)')
        );
        self::assertSame(
            'vnd.android.cursor.dir/contact',
            $xpath->evaluate('string(/manifest/queries/intent[1]/data/@android:mimeType)')
        );
        self::assertStringContainsString('Synthetic existing entries', $configured);
        self::assertSame($configured, NativeCalendarManifestQueries::apply($configured));
    }

    public function test_adds_only_missing_signatures(): void
    {
        $existing = '<queries><intent>'
            .'<action android:name="android.intent.action.INSERT"/>'
            .'<data android:mimeType="vnd.android.cursor.dir/event"/>'
            .'</intent></queries>';

        $configured = NativeCalendarManifestQueries::apply($this->manifest($existing));
        $xpath = $this->inspect($configured);

        self::assertSame(3, $xpath->query('/manifest/queries/intent')->length);
        self::assertSame(
            1,
            $xpath->query(
                '/manifest/queries/intent[action/@android:name="android.intent.action.INSERT"]'
            )->length
        );
    }

    public function test_a_different_authority_does_not_satisfy_event_viewing(): void
    {
        $existing = '<queries><intent>'
            .'<action android:name="android.intent.action.VIEW"/>'
            .'<data android:scheme="content" android:host="com.example.synthetic"'
            .' android:mimeType="vnd.android.cursor.item/event"/>'
            .'</intent></queries>';

        $configured = NativeCalendarManifestQueries::apply($this->manifest($existing));
        $xpath = $this->inspect($configured);

        self::assertSame(4, $xpath->query('/manifest/queries/intent')->length);
        self::assertSame(
            1,
            $xpath->query(
                '/manifest/queries/intent/data['
                .'@android:host="com.android.calendar" and '
                .'@android:mimeType="vnd.android.cursor.item/event"]'
            )->length
        );
        self::assertSame(
            'com.example.synthetic',
            $xpath->evaluate('string(/manifest/queries/intent[1]/data/@android:host)')
        );
    }

    public function test_adds_the_android_namespace_when_missing(): void
    {
        $configured = NativeCalendarManifestQueries::apply(
            '<manifest><application/></manifest>'
        );
        $xpath = $this->inspect($configured);

        self::assertSame(
            self::ANDROID_NS,
            $xpath->document->documentElement->lookupNamespaceURI('android')
        );
        self::assertSame(3, $xpath->query('/manifest/queries/intent')->length);
        self::assertSame($configured, NativeCalendarManifestQueries::apply($configured));
    }

    public function test_matching_signatures_with_an_alternate_prefix_are_unchanged(): void
    {
        $configured = NativeCalendarManifestQueries::apply($this->manifest());
        $alternate = str_replace(
            ['xmlns:android=', 'android:'],
            ['xmlns:m=', 'm:'],
            $configured
        );

        self::assertSame($alternate, NativeCalendarManifestQueries::apply($alternate));
    }

    #[DataProvider('invalidManifests')]
    public function test_rejects_invalid_manifests_with_a_controlled_message(string $xml): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Calendar manifest query configuration is invalid.');

        NativeCalendarManifestQueries::apply($xml);
    }

    public static function invalidManifests(): iterable
    {
        yield 'empty' => [''];
        yield 'malformed' => ['<manifest><application>'];
        yield 'wrong root' => ['<application/>'];
        yield 'missing application' => ['<manifest/>'];
        yield 'multiple applications' => ['<manifest><application/><application/></manifest>'];
        yield 'nested application' => ['<manifest><wrapper><application/></wrapper></manifest>'];
        yield 'multiple query containers' => ['<manifest><queries/><queries/><application/></manifest>'];
        yield 'nested query container' => ['<manifest><application><queries/></application></manifest>'];
        yield 'root namespace' => ['<manifest xmlns="urn:synthetic"><application/></manifest>'];
        yield 'wrong Android namespace' => [
            '<manifest xmlns:android="urn:synthetic"><application/></manifest>',
        ];
        yield 'internal doctype' => [
            '<!DOCTYPE manifest [<!ENTITY marker "synthetic">]>'
            .'<manifest><application/></manifest>',
        ];
        yield 'external doctype' => [
            '<!DOCTYPE manifest SYSTEM "https://example.invalid/synthetic.dtd">'
            .'<manifest><application/></manifest>',
        ];
        yield 'entity declaration' => [
            '<!ENTITY marker "synthetic"><manifest><application/></manifest>',
        ];
        yield 'invalid UTF-8' => [
            '<manifest><application label="'.chr(255).'"/></manifest>',
        ];
        yield 'oversized XML' => [
            '<manifest><application/></manifest>'.str_repeat(' ', 2_097_153),
        ];
    }

    public function test_parser_diagnostics_are_not_printed(): void
    {
        $caught = null;

        ob_start();

        try {
            try {
                NativeCalendarManifestQueries::apply(
                    '<manifest><application label="SyntheticPrivateMarker">'
                );
            } catch (RuntimeException $exception) {
                $caught = $exception;
            }
        } finally {
            $output = ob_get_clean();
        }

        self::assertInstanceOf(RuntimeException::class, $caught);
        self::assertSame('Calendar manifest query configuration is invalid.', $caught->getMessage());
        self::assertSame('', $output);
    }
}
