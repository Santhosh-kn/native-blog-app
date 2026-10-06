<?php

declare(strict_types=1);

namespace Bbs\NativeContacts\Tests;

use Bbs\NativeContacts\Support\NativeContactsManifestQueries;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NativeContactsManifestQueriesTest extends TestCase
{
    private const ANDROID_NS = 'http://schemas.android.com/apk/res/android';

    private function manifest(string $queries = ''): string
    {
        return '<manifest xmlns:android="'.self::ANDROID_NS.'">'
            .'<uses-permission android:name="android.permission.INTERNET"/>'
            .$queries
            .'<application android:label="Existing &amp; App">'
            .'<activity android:name=".MainActivity" android:exported="true"/>'
            .'<meta-data android:name="existing.feature" android:value="kept"/>'
            .'</application></manifest>';
    }

    private function xpath(string $xml): DOMXPath
    {
        $document = new DOMDocument();
        self::assertTrue($document->loadXML($xml, LIBXML_NONET));
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('android', self::ANDROID_NS);

        return $xpath;
    }

    public function test_adds_exactly_five_targeted_intent_queries(): void
    {
        $output = NativeContactsManifestQueries::apply($this->manifest());
        $xpath = $this->xpath($output);

        self::assertSame(1, $xpath->query('/manifest/queries')->length);
        self::assertSame(5, $xpath->query('/manifest/queries/intent')->length);

        foreach ([
            'vnd.android.cursor.dir/contact',
            'vnd.android.cursor.dir/phone_v2',
            'vnd.android.cursor.dir/email_v2',
        ] as $mime) {
            self::assertSame(
                1,
                $xpath->query(
                    '/manifest/queries/intent'
                    .'[action/@android:name="android.intent.action.PICK"]'
                    .'[data/@android:mimeType="'.$mime.'"]'
                )->length
            );
        }

        self::assertSame(
            1,
            $xpath->query(
                '/manifest/queries/intent'
                .'[action/@android:name="android.intent.action.INSERT"]'
                .'[data/@android:mimeType="vnd.android.cursor.dir/contact"]'
            )->length
        );

        self::assertSame(
            1,
            $xpath->query(
                '/manifest/queries/intent'
                .'[action/@android:name="android.intent.action.VIEW"]'
                .'[data/@android:scheme="content"]'
                .'[data/@android:host="com.android.contacts"]'
                .'[data/@android:mimeType="vnd.android.cursor.item/contact"]'
            )->length
        );

        self::assertSame(1, $xpath->query('/manifest/uses-permission')->length);
        self::assertStringNotContainsString('QUERY_ALL_PACKAGES', $output);
        self::assertStringNotContainsString('android.permission.READ_CONTACTS', $output);
        self::assertStringNotContainsString('android.permission.WRITE_CONTACTS', $output);
    }

    public function test_preserves_existing_queries_and_application_entries(): void
    {
        $queries = '<queries>'
            .'<package android:name="com.example.existing"/>'
            .'<intent><action android:name="android.intent.action.SEND"/>'
            .'<data android:mimeType="image/jpeg"/></intent>'
            .'</queries>';

        $output = NativeContactsManifestQueries::apply($this->manifest($queries));
        $xpath = $this->xpath($output);

        self::assertSame(6, $xpath->query('/manifest/queries/intent')->length);
        self::assertSame(
            'com.example.existing',
            $xpath->evaluate('string(/manifest/queries/package/@android:name)')
        );
        self::assertSame(
            1,
            $xpath->query(
                '/manifest/queries/intent'
                .'[action/@android:name="android.intent.action.SEND"]'
                .'[data/@android:mimeType="image/jpeg"]'
            )->length
        );
        self::assertSame(
            'Existing & App',
            $xpath->evaluate('string(/manifest/application/@android:label)')
        );
        self::assertSame(
            'true',
            $xpath->evaluate(
                'string(/manifest/application/activity'
                .'[@android:name=".MainActivity"]/@android:exported)'
            )
        );
        self::assertSame(
            'kept',
            $xpath->evaluate(
                'string(/manifest/application/meta-data'
                .'[@android:name="existing.feature"]/@android:value)'
            )
        );
    }

    public function test_repeated_application_is_byte_identical(): void
    {
        $once = NativeContactsManifestQueries::apply($this->manifest());

        self::assertSame($once, NativeContactsManifestQueries::apply($once));
    }

    public function test_keeps_an_existing_matching_intent_without_duplication(): void
    {
        $queries = '<queries><intent>'
            .'<action android:name="android.intent.action.PICK"/>'
            .'<data android:mimeType="vnd.android.cursor.dir/contact"/>'
            .'</intent></queries>';

        $output = NativeContactsManifestQueries::apply($this->manifest($queries));

        self::assertSame(
            5,
            $this->xpath($output)->query('/manifest/queries/intent')->length
        );
    }

    public function test_restricted_intent_does_not_replace_required_signature(): void
    {
        $queries = '<queries><intent>'
            .'<action android:name="android.intent.action.PICK"/>'
            .'<data android:scheme="content" '
            .'android:mimeType="vnd.android.cursor.dir/contact"/>'
            .'</intent></queries>';

        $output = NativeContactsManifestQueries::apply($this->manifest($queries));
        $xpath = $this->xpath($output);

        self::assertSame(6, $xpath->query('/manifest/queries/intent')->length);
        self::assertSame(
            1,
            $xpath->query(
                '/manifest/queries/intent'
                .'[action/@android:name="android.intent.action.PICK"]'
                .'[data/@android:mimeType="vnd.android.cursor.dir/contact"]'
                .'[not(data/@android:scheme)]'
            )->length
        );
    }

    public function test_handles_a_self_closing_queries_element(): void
    {
        $output = NativeContactsManifestQueries::apply(
            $this->manifest('<queries/>')
        );
        $xpath = $this->xpath($output);

        self::assertSame(1, $xpath->query('/manifest/queries')->length);
        self::assertSame(5, $xpath->query('/manifest/queries/intent')->length);
    }

    #[DataProvider('invalidManifests')]
    public function test_rejects_invalid_xml_with_a_controlled_message(
        string $xml
    ): void {
        try {
            NativeContactsManifestQueries::apply($xml);
            self::fail('Expected invalid manifest rejection.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Contacts manifest query configuration is invalid.',
                $exception->getMessage()
            );
        }
    }

    public static function invalidManifests(): array
    {
        return [
            'empty' => [''],
            'malformed' => ['<manifest><application></manifest>'],
            'wrong root' => ['<other><application/></other>'],
            'missing application' => ['<manifest/>'],
            'duplicate application' => [
                '<manifest><application/><application/></manifest>',
            ],
            'duplicate queries' => [
                '<manifest><queries/><queries/><application/></manifest>',
            ],
            'nested queries' => [
                '<manifest><application><queries/></application></manifest>',
            ],
            'doctype' => [
                '<!DOCTYPE manifest [<!ENTITY private "private-marker">]>'
                .'<manifest><application/></manifest>',
            ],
            'oversized' => [str_repeat(' ', 2_097_153)],
        ];
    }
}