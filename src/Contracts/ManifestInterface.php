<?php

declare(strict_types=1);

namespace Magput\Debug\Contracts;

use DateTimeImmutable;

interface ManifestInterface
{
    /**
     * UUID/ULID
     *
     * @return string
     */
    public function id(): string;

    public function startedAt(): DateTimeImmutable;
    public function context(): array;
}
