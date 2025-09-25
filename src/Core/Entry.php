<?php

declare(strict_types=1);

namespace Magput\Debug\Core;

use DateTimeImmutable;
use Magput\Debug\Contracts\EntryInterface;

final class Entry implements EntryInterface
{
    private string $manifestId;
    private string $panel;
    private ?string $level;
    private array $payload;
    private DateTimeImmutable $timestamp;

    private function __construct(
        string $manifestId,
        string $panel,
        array $payload,
        ?string $level = null
    ) {
        $this->manifestId = $manifestId;
        $this->panel = $panel;
        $this->payload = $payload;
        $this->level = $level;
        $this->timestamp = new DateTimeImmutable();
    }

    public static function from(string $manifestId, string $panel, array $payload, ?string $level = null): self
    {
        return new self($manifestId, $panel, $payload, $level);
    }

    public function manifestId(): string { return $this->manifestId; }
    public function panel(): string { return $this->panel; }
    public function level(): ?string { return $this->level; }
    public function payload(): array { return $this->payload; }
    public function timestamp(): DateTimeImmutable { return $this->timestamp; }
}
