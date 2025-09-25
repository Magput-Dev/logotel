<?php

declare(strict_types=1);

namespace Magput\Debug\Core;

use DateTimeImmutable;
use Magput\Debug\Contracts\ManifestInterface;

final class Manifest implements ManifestInterface
{
    private string $id;
    private DateTimeImmutable $startedAt;
    private array $context;

    private function __construct(string $id, DateTimeImmutable $startedAt, array $context = [])
    {
        $this->id = $id;
        $this->startedAt = $startedAt;
        $this->context = $context;
    }

    public static function create(array $context = []): self
    {
        $id = bin2hex(random_bytes(16));
        return new static($id, new DateTimeImmutable(), $context);
    }

    public function id(): string { return $this->id; }
    public function startedAt(): DateTimeImmutable { return $this->startedAt; }
    public function context(): array { return $this->context; }
}
