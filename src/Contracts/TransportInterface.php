<?php

declare(strict_types=1);

namespace Magput\Debug\Contracts;

interface TransportInterface
{
    /**
     * Отправка в систему наблюдаемости (OTLP/OTel, ...).
     */
    public function send(EntryInterface $entry): void;
}
