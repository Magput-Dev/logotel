<?php

declare(strict_types=1);

namespace Magput\Logotel\Telemetry\Database;

use PDO;
use PDOStatement;

final class TracedPdo extends PDO
{
    private DbSpanConfig $spanConfig;

    public function __construct(string $dsn, ?string $username = null, ?string $password = null, ?array $options = null)
    {
        parent::__construct($dsn, $username, $password, $options ?? []);
        $this->spanConfig = new DbSpanConfig();
    }

    /**
     * @param DbSpanConfig $spanConfig
     * @return self
     */
    public function enableTracing(DbSpanConfig $spanConfig): self
    {
        $this->spanConfig = $spanConfig;
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [TracedPdoStatement::class, [$spanConfig]]);

        return $this;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return DbSpan::trace($query, $this->spanConfig, function () use ($query, $fetchMode, $fetchModeArgs) {
            if ($fetchMode === null && $fetchModeArgs === []) {
                return parent::query($query);
            }

            return parent::query($query, $fetchMode, ...$fetchModeArgs);
        });
    }

    public function exec(string $statement): int|false
    {
        return DbSpan::trace($statement, $this->spanConfig, fn () => parent::exec($statement));
    }

    public function beginTransaction(): bool
    {
        return DbSpan::trace('BEGIN', $this->spanConfig, fn () => parent::beginTransaction());
    }

    public function commit(): bool
    {
        return DbSpan::trace('COMMIT', $this->spanConfig, fn () => parent::commit());
    }

    public function rollBack(): bool
    {
        return DbSpan::trace('ROLLBACK', $this->spanConfig, fn () => parent::rollBack());
    }
}
