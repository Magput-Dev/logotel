<?php

declare(strict_types=1);

namespace Magput\Debug\Transports;

use Magput\Debug\Contracts\EntryInterface;
use Magput\Debug\Contracts\TransportInterface;
use Magput\Debug\DataKeeper\NameDataKeeper;
use Redis;

final class RedisQueueTransport implements TransportInterface
{
    private Redis $redis;
    private string $queueKey;

    public function __construct(Redis $redis, string $queueServiceName = 'entries')
    {
        $this->redis = $redis;
        $this->queueKey = NameDataKeeper::LOGGER_PREFIX . ':' . $queueServiceName . ':' . NameDataKeeper::LOGGER_LOG_SUFFIX;
    }

    public function send(EntryInterface $entry): void
    {
        $payload = [
            'manifestId' => $entry->manifestId(),
            'panel'      => $entry->panel(),
            'level'      => $entry->level(),
            'payload'    => $entry->payload(),
            'ts'         => $entry->timestamp()->format(DATE_ATOM),
        ];

        $this->redis->lPush($this->queueKey, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }
}
