<?php

declare(strict_types=1);

namespace EasySQL\Tests\Support;

use Clearsoft\EasySQL\Connectors\ConnectorInterface;

/**
 * In-memory ConnectorInterface implementation used by the unit tests.
 *
 * Records the executed statements and connection lifecycle so the services
 * can be asserted without a real database.
 */
final class FakeConnector implements ConnectorInterface
{
    /** @var array{engine: string, tables: list<array<string, mixed>>} */
    private array $rawSchema;

    /** @var array{columns: list<string>, rows: list<array<string, mixed>>, row_count: int, duration_ms: int} */
    private array $executeResult;

    private ?\Throwable $introspectError = null;

    private ?\Throwable $executeError = null;

    private bool $connected = false;

    private int $connectCalls = 0;

    /** @var list<string> */
    private array $executedSql = [];

    /**
     * @param array{engine: string, tables: list<array<string, mixed>>} $rawSchema
     * @param array{columns?: list<string>, rows?: list<array<string, mixed>>, row_count?: int, duration_ms?: int} $executeResult
     */
    public function __construct(
        array $rawSchema = ['engine' => 'mysql', 'tables' => []],
        array $executeResult = []
    ) {
        $this->rawSchema = $rawSchema;
        $this->executeResult = $executeResult + [
            'columns' => [],
            'rows' => [],
            'row_count' => 0,
            'duration_ms' => 1,
        ];
    }

    public function connect(): void
    {
        $this->connected = true;
        ++$this->connectCalls;
    }

    public function close(): void
    {
        $this->connected = false;
    }

    public function introspect(): array
    {
        if (null !== $this->introspectError) {
            throw $this->introspectError;
        }

        return $this->rawSchema;
    }

    public function execute(string $sql, array $params = []): array
    {
        $this->executedSql[] = $sql;

        if (null !== $this->executeError) {
            throw $this->executeError;
        }

        return $this->executeResult;
    }

    public function withIntrospectError(\Throwable $error): self
    {
        $this->introspectError = $error;

        return $this;
    }

    public function withExecuteError(\Throwable $error): self
    {
        $this->executeError = $error;

        return $this;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function connectCalls(): int
    {
        return $this->connectCalls;
    }

    /** @return list<string> */
    public function executedSql(): array
    {
        return $this->executedSql;
    }
}
