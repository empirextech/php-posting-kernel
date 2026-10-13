<?php

declare(strict_types=1);

namespace EmpireX\Posting\Idempotency;

interface Store
{
    public function find(string $key): ?Record;

    public function remember(Record $record): void;
}
