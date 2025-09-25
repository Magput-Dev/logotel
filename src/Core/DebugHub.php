<?php

declare(strict_types=1);

namespace Magput\Debug\Core;

use Magput\Debug\Contracts\DebugHubInterface;
use Magput\Debug\Contracts\EntryInterface;
use Magput\Debug\Contracts\ManifestInterface;
use Magput\Debug\Contracts\StorageInterface;
use Magput\Debug\Contracts\TransportInterface;

final class DebugHub implements DebugHubInterface
{
    private StorageInterface $storage;
    /** @var TransportInterface[] */
    private array $transports = [];

    private ?ManifestInterface $manifest = null;
    /** @var EntryInterface[] */
    private array $buffer = [];

    public function __construct(StorageInterface $storage, array $transports = [])
    {
        $this->storage = $storage;
        $this->transports = $transports;
    }

    public function start(array $context = []): ManifestInterface
    {
        $this->manifest = Manifest::create($context);
        $this->storage->storeManifest($this->manifest);
        return $this->manifest;
    }

    public function add(string $panel, array $payload, ?string $level = null): void
    {
        if (!$this->manifest) {
            $this->start();
        }
        $e = Entry::from($this->manifest->id(), $panel, $payload, $level);
        $this->buffer[] = $e;
        foreach ($this->transports as $t) {
            $t->send($e);
        }
    }

    public function flush(): void
    {
        if ($this->buffer) {
            $this->storage->storeEntries($this->buffer);
            $this->buffer = [];
        }
    }

    public function __destruct()
    {
        $this->flush();
    }
}
