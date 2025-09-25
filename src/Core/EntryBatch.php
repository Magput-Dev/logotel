<?php

declare(strict_types=1);

namespace Magput\Debug\Core;

use Magput\Debug\Contracts\EntryInterface;

final class EntryBatch
{
    /** @var EntryInterface[] */
    private array $entries = [];

    public function __construct(array $entries = [])
    {
        $this->entries = $entries;
    }

    public function add(EntryInterface $entry): void
    {
        $this->entries[] = $entry;
    }

    /**
     * @return EntryInterface[]
     */
    public function all(): array
    {
        return $this->entries;
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function clear(): void
    {
        $this->entries = [];
    }
}
