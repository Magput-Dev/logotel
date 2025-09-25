<?php

declare(strict_types=1);

namespace Magput\Debug\Storage;

use Magput\Debug\Contracts\EntryInterface;
use Magput\Debug\Contracts\StorageInterface;
use Magput\Debug\Contracts\ManifestInterface;
use MongoDB\Client;
use MongoDB\Collection;

final class MongoStorage implements StorageInterface
{
    private Collection $manifests;
    private Collection $entries;

    public function __construct(
        Client $client,
        string $db = 'magput_debug',
        string $manifestColl = 'manifests',
        string $entriesColl = 'entries'
    ) {
        $database = $client->selectDatabase($db);
        $this->manifests = $database->selectCollection($manifestColl);
        $this->entries = $database->selectCollection($entriesColl);
    }

    public function storeManifest(ManifestInterface $m): void
    {
        $this->manifests->insertOne([
            '_id'        => $m->id(),
            'startedAt'  => $m->startedAt(),
            'context'    => $m->context(),
        ]);
    }

    public function storeEntries(array $entries): void
    {
        if (!$entries) return;
        $docs = array_map(function(EntryInterface $e) {
            return [
                'manifestId' => $e->manifestId(),
                'panel'      => $e->panel(),
                'level'      => $e->level(),
                'payload'    => $e->payload(),
                'ts'         => $e->timestamp(),
            ];
        }, $entries);
        $this->entries->insertMany($docs);
    }
}
