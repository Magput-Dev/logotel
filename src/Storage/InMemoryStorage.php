<?php

declare(strict_types=1);

namespace Magput\Debug\Storage;

use Magput\Debug\Contracts\ManifestInterface;
use Magput\Debug\Contracts\EntryInterface;

final class InMemoryStorage
{
    /** @var ManifestInterface[] */
    private array $manifests = [];
    /** @var EntryInterface[] */
    private array $entries = [];

    public function storeManifest(ManifestInterface $m): void
    {
        $this->manifests[$m->id()] = $m;
    }

    public function storeEntries(array $entries): void
    {
        foreach ($entries as $e) {
            $this->entries[] = $e;
        }
    }

    public function manifests(): array
    {
        return $this->manifests;
    }

    public function entries(): array
    {
        return $this->entries;
    }
}
