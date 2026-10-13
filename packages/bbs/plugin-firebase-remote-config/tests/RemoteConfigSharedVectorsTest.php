<?php

declare(strict_types=1);

namespace Bbs\FirebaseRemoteConfig\Tests;

use Bbs\FirebaseRemoteConfig\Support\RemoteConfigContract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RemoteConfigSharedVectorsTest extends TestCase
{
    #[DataProvider('sharedVectors')]
    public function test_matches_the_shared_android_request_contract(string $operation, array $parameters, ?string $error): void
    {
        self::assertSame($error, RemoteConfigContract::validate('FirebaseRemoteConfig.'.$operation, $parameters));
    }

    public static function sharedVectors(): array
    {
        $vectors = json_decode(file_get_contents(__DIR__.'/fixtures/remote-config-contract-vectors.json'), true, flags: JSON_THROW_ON_ERROR);
        $cases = [];
        foreach ($vectors as $index => $vector) {
            $cases[$index.' '.$vector['name']] = [$vector['operation'], $vector['parameters'], $vector['expectedCode']];
        }

        return $cases;
    }
}
