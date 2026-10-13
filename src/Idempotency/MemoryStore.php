<?php

declare(strict_types=1);

namespace EmpireX\Posting\Idempotency;

final class MemoryStore implements Store
{
    /** @var array<string, Record> */
    private array $rows = [];

    public function find(string $key): ?Record
    {
        return $this->rows[$key] ?? null;
    }

    public function remember(Record $record): void
    {
        $this->rows[$record->key] = $record;
    }
}
