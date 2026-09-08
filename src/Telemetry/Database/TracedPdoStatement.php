<?php

declare(strict_types=1);

namespace Magput\Logotel\Telemetry\Database;

use PDOStatement;

final class TracedPdoStatement extends PDOStatement
{
    protected function __construct(
        private readonly DbSpanConfig $config,
    ) {}

    public function execute(?array $params = null): bool
    {
        $sql = $this->queryString;

        return DbSpan::trace($sql, $this->config, fn (): bool => parent::execute($params));
    }
}
