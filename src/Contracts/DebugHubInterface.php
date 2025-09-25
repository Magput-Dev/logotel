<?php

declare(strict_types=1);

namespace Magput\Debug\Contracts;

interface DebugHubInterface
{
    public function start(array $context = []): ManifestInterface;
    public function add(string $panel, array $payload, ?string $level = null): void;
    public function flush(): void;
}
