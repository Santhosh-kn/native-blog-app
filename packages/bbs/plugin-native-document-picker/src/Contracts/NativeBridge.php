<?php

declare(strict_types=1);

namespace Bbs\NativeDocumentPicker\Contracts;

interface NativeBridge
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public function call(string $method, array $parameters = []): ?object;
}
