<?php

declare(strict_types=1);

namespace Magput\Debug\Contracts;

interface StorageInterface
{
    public function storeManifest(ManifestInterface $m): void;

    /**
     * @param EntryInterface[] $entries
     */
    public function storeEntries(array $entries): void;
}
