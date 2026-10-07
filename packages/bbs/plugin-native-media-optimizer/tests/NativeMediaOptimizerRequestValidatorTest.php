<?php

declare(strict_types=1);

namespace Bbs\NativeMediaOptimizer\Tests;

use Bbs\NativeMediaOptimizer\Support\NativeMediaOptimizerErrorCode as ErrorCode;
use Bbs\NativeMediaOptimizer\Support\NativeMediaOptimizerRequestValidator as Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NativeMediaOptimizerRequestValidatorTest extends TestCase
{
    private const ID = '3f1d6c28-78e0-4f1b-a48e-f7350dcb601a';
    private const SOURCE = 'a72645ed-85bb-4f51-9d9e-8c71815646af';

    #[DataProvider('requestCases')]
    public function test_request_contract(string $operation, array $options, ?string $expected): void
    {
        self::assertSame($expected, Validator::validate($operation, $options));
    }

    public static function requestCases(): iterable
    {
        $base = ['id' => self::ID, 'source_document_id' => self::SOURCE];
        yield 'availability' => ['IsAvailable', [], null];
        yield 'availability rejects options' => ['IsAvailable', ['id' => self::ID], ErrorCode::INVALID_OPTIONS];
        yield 'unknown operation' => ['Unknown', [], ErrorCode::INVALID_OPTIONS];

        foreach (['GetStatus', 'Cancel', 'GetResult', 'DeleteOutput'] as $operation) {
            yield "$operation valid" => [$operation, ['id' => self::ID], null];
            yield "$operation missing id" => [$operation, [], ErrorCode::INVALID_REQUEST_ID];
            yield "$operation rejects paths" => [$operation, ['id' => self::ID, 'path' => '/private/file'], ErrorCode::INVALID_OPTIONS];
        }

        foreach (['InspectMedia', 'OptimizeImage', 'OptimizeVideo', 'GenerateThumbnail'] as $operation) {
            yield "$operation defaults" => [$operation, $base, null];
            yield "$operation missing source" => [$operation, ['id' => self::ID], ErrorCode::INVALID_SOURCE_ID];
            yield "$operation source path" => [$operation, array_replace($base, ['source_document_id' => '../file']), ErrorCode::INVALID_SOURCE_ID];
            yield "$operation rejects unknown keys" => [$operation, $base + ['destination_path' => '/private/file'], ErrorCode::INVALID_OPTIONS];
            yield "$operation malformed request id" => [$operation, array_replace($base, ['id' => 'not-a-uuid']), ErrorCode::INVALID_REQUEST_ID];
        }

        $invalidTypes = ['null' => null, 'string' => '32', 'float' => 32.0, 'boolean' => true, 'array' => []];
        foreach (['OptimizeImage', 'OptimizeVideo', 'GenerateThumbnail'] as $operation) {
            foreach (['max_width', 'max_height'] as $field) {
                foreach ($invalidTypes as $label => $value) {
                    yield "$operation $field rejects $label" => [$operation, $base + [$field => $value], ErrorCode::INVALID_DIMENSIONS];
                }
                yield "$operation $field rejects zero" => [$operation, $base + [$field => 0], ErrorCode::INVALID_DIMENSIONS];
            }
        }

        yield 'image maximum dimensions' => ['OptimizeImage', $base + ['max_width' => 4096, 'max_height' => 4096], null];
        yield 'image oversized edge' => ['OptimizeImage', $base + ['max_width' => 4097], ErrorCode::INVALID_DIMENSIONS];
        yield 'thumbnail oversized edge' => ['GenerateThumbnail', $base + ['max_height' => 2049], ErrorCode::INVALID_DIMENSIONS];
        yield 'video portrait' => ['OptimizeVideo', $base + ['max_width' => 1080, 'max_height' => 1920], null];
        yield 'video pixel limit' => ['OptimizeVideo', $base + ['max_width' => 1920, 'max_height' => 1920], ErrorCode::INVALID_DIMENSIONS];
        yield 'video odd dimension' => ['OptimizeVideo', $base + ['max_width' => 1279], ErrorCode::INVALID_DIMENSIONS];
        yield 'video too small' => ['OptimizeVideo', $base + ['max_width' => 14], ErrorCode::INVALID_DIMENSIONS];

        foreach (['OptimizeImage', 'GenerateThumbnail'] as $operation) {
            foreach (['jpeg', 'png', 'webp'] as $format) {
                yield "$operation $format" => [$operation, $base + ['format' => $format], null];
            }
            foreach (['JPEG', 'jpg', 'avif', null, 1, []] as $index => $format) {
                yield "$operation invalid format $index" => [$operation, $base + ['format' => $format], ErrorCode::INVALID_FORMAT];
            }
            foreach ([0, 101, null, '80', 80.0, true, []] as $index => $quality) {
                yield "$operation invalid quality $index" => [$operation, $base + ['quality' => $quality], ErrorCode::INVALID_QUALITY];
            }
            foreach ([1, 100] as $quality) {
                yield "$operation quality boundary $quality" => [$operation, $base + ['quality' => $quality], null];
            }
            yield "$operation PNG quality rejected" => [$operation, $base + ['format' => 'png', 'quality' => 80], ErrorCode::INVALID_QUALITY];
        }

        foreach (['video_bitrate', 'audio_bitrate'] as $field) {
            foreach ($invalidTypes as $label => $value) {
                yield "$field rejects $label" => ['OptimizeVideo', $base + [$field => $value], ErrorCode::INVALID_BITRATE];
            }
        }
        yield 'video bitrate too low' => ['OptimizeVideo', $base + ['video_bitrate' => 127999], ErrorCode::INVALID_BITRATE];
        yield 'video bitrate too high' => ['OptimizeVideo', $base + ['video_bitrate' => 20000001], ErrorCode::INVALID_BITRATE];
        yield 'audio bitrate too low' => ['OptimizeVideo', $base + ['audio_bitrate' => 31999], ErrorCode::INVALID_BITRATE];
        yield 'audio bitrate too high' => ['OptimizeVideo', $base + ['audio_bitrate' => 320001], ErrorCode::INVALID_BITRATE];
        yield 'bitrate lower boundaries' => ['OptimizeVideo', $base + ['video_bitrate' => 128000, 'audio_bitrate' => 32000], null];
        yield 'bitrate upper boundaries' => ['OptimizeVideo', $base + ['video_bitrate' => 20000000, 'audio_bitrate' => 320000], null];
        foreach ([true, false] as $index => $value) {
            yield "remove audio boolean $index" => ['OptimizeVideo', $base + ['remove_audio' => $value], null];
        }
        foreach ([null, 'true', 1, []] as $index => $value) {
            yield "remove audio invalid $index" => ['OptimizeVideo', $base + ['remove_audio' => $value], ErrorCode::INVALID_OPTIONS];
        }

        foreach (['start_ms', 'end_ms'] as $field) {
            foreach ($invalidTypes as $label => $value) {
                yield "$field rejects $label" => ['OptimizeVideo', $base + [$field => $value], ErrorCode::INVALID_TIME_RANGE];
            }
        }
        yield 'valid trim' => ['OptimizeVideo', $base + ['start_ms' => 1000, 'end_ms' => 5000], null];
        yield 'end only trim' => ['OptimizeVideo', $base + ['end_ms' => 1000], null];
        yield 'start only trim' => ['OptimizeVideo', $base + ['start_ms' => 1000], null];
        yield 'equal trim times' => ['OptimizeVideo', $base + ['start_ms' => 1000, 'end_ms' => 1000], ErrorCode::INVALID_TIME_RANGE];
        yield 'reverse trim' => ['OptimizeVideo', $base + ['start_ms' => 2000, 'end_ms' => 1000], ErrorCode::INVALID_TIME_RANGE];
        yield 'negative start' => ['OptimizeVideo', $base + ['start_ms' => -1], ErrorCode::INVALID_TIME_RANGE];
        yield 'zero end' => ['OptimizeVideo', $base + ['end_ms' => 0], ErrorCode::INVALID_TIME_RANGE];
        yield 'start at duration limit' => ['OptimizeVideo', $base + ['start_ms' => 3600000], ErrorCode::INVALID_TIME_RANGE];
        yield 'end beyond duration limit' => ['OptimizeVideo', $base + ['end_ms' => 3600001], ErrorCode::INVALID_TIME_RANGE];
        yield 'full duration boundary' => ['OptimizeVideo', $base + ['end_ms' => 3600000], null];
        foreach ([-1, 3600000, null, '100', 100.0, true, []] as $index => $value) {
            yield "thumbnail invalid time $index" => ['GenerateThumbnail', $base + ['timestamp_ms' => $value], ErrorCode::INVALID_TIME_RANGE];
        }
        yield 'thumbnail time upper boundary' => ['GenerateThumbnail', $base + ['timestamp_ms' => 3599999], null];
        yield 'oversized request' => ['InspectMedia', $base + ['unexpected' => str_repeat('a', 8192)], ErrorCode::REQUEST_TOO_LARGE];
        yield 'invalid UTF8' => ['InspectMedia', ['id' => "\xff", 'source_document_id' => self::SOURCE], ErrorCode::INVALID_OPTIONS];
        yield 'unencodable float' => ['OptimizeImage', $base + ['quality' => NAN], ErrorCode::INVALID_OPTIONS];
        yield 'numeric key rejected' => ['InspectMedia', $base + [0 => 'value'], ErrorCode::INVALID_OPTIONS];
    }

    #[DataProvider('idCases')]
    public function test_request_id_contract(mixed $id, bool $expected): void
    {
        self::assertSame($expected, Validator::isRequestId($id));
    }

    public static function idCases(): iterable
    {
        yield 'valid' => [self::ID, true];
        yield 'valid source' => [self::SOURCE, true];
        foreach ([null, true, 1, [], '', strtoupper(self::ID), ' '.self::ID, self::ID.' ', str_replace('-4f1b-', '-7f1b-', self::ID), str_replace('-a48e-', '-748e-', self::ID), self::ID."\n", '../'.self::ID] as $index => $value) {
            yield "invalid $index" => [$value, false];
        }
    }

    public function test_error_messages_do_not_echo_unknown_values(): void
    {
        self::assertFalse(ErrorCode::isKnown(['private-data']));
        self::assertFalse(ErrorCode::isKnown('/private/file'));
        self::assertSame('Native media processing failed.', ErrorCode::message('/private/file'));
        self::assertTrue(ErrorCode::isKnown(ErrorCode::INVALID_DIMENSIONS));
        self::assertNotSame('', ErrorCode::message(ErrorCode::INVALID_DIMENSIONS));
    }
}
