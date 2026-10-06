<?php

declare(strict_types=1);

namespace Bbs\NativeContacts\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

final class NativeContactsManifestQueries
{
    private const ANDROID_NS = 'http://schemas.android.com/apk/res/android';

    private const XMLNS_NS = 'http://www.w3.org/2000/xmlns/';

    private const MAX_XML_BYTES = 2_097_152;

    private const SIGNATURES = [
        [
            'action' => 'android.intent.action.PICK',
            'data' => ['mimeType' => 'vnd.android.cursor.dir/contact'],
        ],
        [
            'action' => 'android.intent.action.PICK',
            'data' => ['mimeType' => 'vnd.android.cursor.dir/phone_v2'],
        ],
        [
            'action' => 'android.intent.action.PICK',
            'data' => ['mimeType' => 'vnd.android.cursor.dir/email_v2'],
        ],
        [
            'action' => 'android.intent.action.INSERT',
            'data' => ['mimeType' => 'vnd.android.cursor.dir/contact'],
        ],
        [
            'action' => 'android.intent.action.VIEW',
            'data' => [
                'scheme' => 'content',
                'host' => 'com.android.contacts',
                'mimeType' => 'vnd.android.cursor.item/contact',
            ],
        ],
    ];

    public static function apply(string $xml): string
    {
        $document = self::parse($xml);
        $root = $document->documentElement;

        if (
            ! $root instanceof DOMElement ||
            $root->tagName !== 'manifest' ||
            ! in_array($root->namespaceURI, [null, ''], true)
        ) {
            self::invalid();
        }

        $androidNamespace = $root->lookupNamespaceURI('android');

        if ($androidNamespace !== null && $androidNamespace !== self::ANDROID_NS) {
            self::invalid();
        }

        $xpath = new DOMXPath($document);
        $applications = $xpath->query('/manifest/application');
        $queryNodes = $xpath->query('/manifest/queries');

        if (
            $applications === false ||
            $applications->length !== 1 ||
            $queryNodes === false ||
            $queryNodes->length > 1 ||
            $document->getElementsByTagName('queries')->length !== $queryNodes->length
        ) {
            self::invalid();
        }

        $queries = $queryNodes->item(0);
        $changed = false;

        if (! $queries instanceof DOMElement) {
            $queries = $document->createElement('queries');
            $application = $applications->item(0);
            $root->insertBefore($queries, $application);
            $root->insertBefore(
                $document->createTextNode("\n    "),
                $application
            );
            $changed = true;
        }

        foreach (self::SIGNATURES as $signature) {
            if (self::hasSignature($queries, $signature)) {
                continue;
            }

            if ($root->lookupNamespaceURI('android') === null) {
                $root->setAttributeNS(
                    self::XMLNS_NS,
                    'xmlns:android',
                    self::ANDROID_NS
                );
            }

            $intent = $document->createElement('intent');
            $action = $document->createElement('action');
            $action->setAttributeNS(
                self::ANDROID_NS,
                'android:name',
                $signature['action']
            );

            $data = $document->createElement('data');

            foreach ($signature['data'] as $name => $value) {
                $data->setAttributeNS(
                    self::ANDROID_NS,
                    'android:'.$name,
                    $value
                );
            }

            $intent->appendChild($document->createTextNode("\n            "));
            $intent->appendChild($action);
            $intent->appendChild($document->createTextNode("\n            "));
            $intent->appendChild($data);
            $intent->appendChild($document->createTextNode("\n        "));

            $queries->appendChild($document->createTextNode("\n        "));
            $queries->appendChild($intent);
            $changed = true;
        }

        if (! $changed) {
            return $xml;
        }

        $queries->appendChild($document->createTextNode("\n    "));
        $document->encoding = 'UTF-8';
        $result = $document->saveXML();

        if (! is_string($result) || strlen($result) > self::MAX_XML_BYTES) {
            self::invalid();
        }

        return $result;
    }

    private static function parse(string $xml): DOMDocument
    {
        if (
            $xml === '' ||
            strlen($xml) > self::MAX_XML_BYTES ||
            preg_match('/<!\s*(DOCTYPE|ENTITY)\b/i', $xml) === 1
        ) {
            self::invalid();
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $document->preserveWhiteSpace = true;
        $document->formatOutput = false;

        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET);
        } catch (\Throwable) {
            self::invalid();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $loaded || $document->doctype !== null) {
            self::invalid();
        }

        return $document;
    }

    private static function hasSignature(
        DOMElement $queries,
        array $signature
    ): bool {
        $expectedData = $signature['data'];
        ksort($expectedData);

        foreach ($queries->childNodes as $intent) {
            if (
                ! $intent instanceof DOMElement ||
                $intent->tagName !== 'intent' ||
                ! in_array($intent->namespaceURI, [null, ''], true)
            ) {
                continue;
            }

            $elements = [];

            foreach ($intent->childNodes as $child) {
                if ($child instanceof DOMElement) {
                    $elements[] = $child;
                }
            }

            if (count($elements) !== 2) {
                continue;
            }

            $action = null;
            $data = null;

            foreach ($elements as $element) {
                if ($element->tagName === 'action') {
                    $action = $element;
                } elseif ($element->tagName === 'data') {
                    $data = $element;
                }
            }

            if (
                $action instanceof DOMElement &&
                $data instanceof DOMElement &&
                in_array($action->namespaceURI, [null, ''], true) &&
                in_array($data->namespaceURI, [null, ''], true) &&
                self::androidAttributes($action) === [
                    'name' => $signature['action'],
                ] &&
                self::androidAttributes($data) === $expectedData
            ) {
                return true;
            }
        }

        return false;
    }

    private static function androidAttributes(DOMElement $element): ?array
    {
        $attributes = [];

        foreach ($element->attributes as $attribute) {
            if ($attribute->namespaceURI === self::XMLNS_NS) {
                continue;
            }

            if ($attribute->namespaceURI !== self::ANDROID_NS) {
                return null;
            }

            $attributes[$attribute->localName] = $attribute->value;
        }

        ksort($attributes);

        return $attributes;
    }

    private static function invalid(): never
    {
        // Never include manifest contents or parser diagnostics in the error.
        throw new RuntimeException(
            'Contacts manifest query configuration is invalid.'
        );
    }
}