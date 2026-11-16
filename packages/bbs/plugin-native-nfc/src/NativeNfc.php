<?php

declare(strict_types=1);

namespace Bbs\NativeNfc;

use Bbs\NativeNfc\Contracts\NativeBridge;
use Bbs\NativeNfc\Exceptions\NativeNfcException;
use Bbs\NativeNfc\Support\NativeNfcErrorCode;
use Bbs\NativeNfc\Support\NativeNfcRequestValidator;
use Illuminate\Support\Str;

final class NativeNfc
{
    public function __construct(
        private readonly NativeBridge $bridge
    ) {}

    public function isAvailable(): object
    {
        $data = $this->call('NativeNfc.IsAvailable');

        if (
            !isset($data->supported, $data->enabled, $data->availability) ||
            !is_bool($data->supported) ||
            !is_bool($data->enabled) ||
            !in_array($data->availability, ['available', 'unavailable', 'disabled'], true)
        ) {
            $this->fail(NativeNfcErrorCode::INVALID_NATIVE_RESPONSE);
        }

        return $data;
    }

    public function startScan(?string $id = null): NativeNfcResult
    {
        $id ??= (string) Str::uuid();

        $parameters = NativeNfcRequestValidator::validateIdRequest([
            'id' => $id,
        ]);

        return NativeNfcResult::fromNative(
            $this->call('NativeNfc.StartScan', $parameters)
        );
    }

    public function startWrite(string $text, ?string $id = null): NativeNfcResult
    {
        $id ??= (string) Str::uuid();

        $parameters = NativeNfcRequestValidator::validateWriteRequest([
            'id' => $id,
            'text' => $text,
        ]);

        return NativeNfcResult::fromNative(
            $this->call('NativeNfc.StartWrite', $parameters)
        );
    }

    public function getStatus(string $id): NativeNfcResult
    {
        $parameters = NativeNfcRequestValidator::validateIdRequest([
            'id' => $id,
        ]);

        return NativeNfcResult::fromNative(
            $this->call('NativeNfc.GetStatus', $parameters)
        );
    }

    public function stopSession(string $id): NativeNfcResult
    {
        $parameters = NativeNfcRequestValidator::validateIdRequest([
            'id' => $id,
        ]);

        return NativeNfcResult::fromNative(
            $this->call('NativeNfc.StopSession', $parameters)
        );
    }

    public function consumeResult(string $id): string
    {
        $parameters = NativeNfcRequestValidator::validateIdRequest([
            'id' => $id,
        ]);

        $data = $this->call('NativeNfc.ConsumeResult', $parameters);

        if (
            ($data->status ?? null) !== 'read' ||
            ($data->consumed ?? null) !== true ||
            !isset($data->text) ||
            !is_string($data->text)
        ) {
            $this->fail(NativeNfcErrorCode::INVALID_NATIVE_RESPONSE);
        }

        return $data->text;
    }

    private function call(
        string $method,
        array $parameters = []
    ): object {
        $data = $this->bridge->call($method, $parameters);

        if ($data === null) {
            $this->fail(NativeNfcErrorCode::BRIDGE_UNAVAILABLE);
        }

        return $data;
    }

    private function fail(string $code): never
    {
        throw new NativeNfcException(
            $code,
            NativeNfcErrorCode::message($code)
        );
    }
}
