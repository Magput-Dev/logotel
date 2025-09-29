<?php

declare(strict_types=1);

namespace Magput\Debug\Transports;

use Magput\Debug\Contracts\EntryInterface;

final class NullTransport
{
    /**
     * Ничего не делаем, используется для тестов или отключения экспорта.
     */
    public function send(EntryInterface $entry): void
    {

    }
}
