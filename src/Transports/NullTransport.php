<?php

declare(strict_types=1);

namespace Magput\Debug\Transports;

use Magput\Debug\Contracts\EntryInterface;

final class NullTransport
{
    public function send(EntryInterface $entry): void
    {
        // ничего не делаем, используется для тестов или отключения экспорта
    }
}
