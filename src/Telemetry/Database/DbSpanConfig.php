<?php

declare(strict_types=1);

namespace Magput\Logotel\Telemetry\Database;

final class DbSpanConfig
{
    public const DEFAULT_STATEMENT_MAX_BYTES = 2048;

    public function __construct(
        public readonly string $system = 'mysql',
        public readonly string $name = '',
        public readonly bool $includeStatement = true,
        public readonly int $statementMaxBytes = self::DEFAULT_STATEMENT_MAX_BYTES,
    ) {}

    /**
     * @param array $config
     * @return self
     */
    public static function fromArray(array $config): self
    {
        $driver = strtolower((string)($config['driver'] ?? 'mysql'));
        $name = $config['name'] ?? $config['database'] ?? '';

        return new self(
            system: self::systemFromDriver($driver),
            name: is_string($name) ? $name : '',
        );
    }

    /**
     * @param string $driver
     * @return string
     */
    public static function systemFromDriver(string $driver): string
    {
        return match (strtolower($driver)) {
            'pgsql', 'postgres', 'postgresql' => 'postgresql',
            'sqlsrv', 'mssql' => 'mssql',
            'sqlite' => 'sqlite',
            default => 'mysql',
        };
    }
}
