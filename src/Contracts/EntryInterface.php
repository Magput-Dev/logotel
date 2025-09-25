<?php

declare(strict_types=1);

namespace Magput\Debug\Contracts;

use DateTimeImmutable;

interface EntryInterface
{
    /**
     * Связь с манифестом
     *
     * @return string
     */
    public function manifestId(): string;

    public function panel(): string;           // 'log','db','http','timeline',...
    public function level(): ?string;          // 'info','error','debug'...
    public function payload(): array;          // произвольные поля
    public function timestamp(): DateTimeImmutable;
}
