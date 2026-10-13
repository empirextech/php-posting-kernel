<?php

declare(strict_types=1);

namespace EmpireX\Posting\Allocation;

/**
 * In-process stand-in for SELECT … FOR UPDATE + unique(series, year, seq).
 */
final class MemorySeriesStore implements SeriesStore
{
    /** @var array<string, int> */
    private array $cursor = [];

    /** @var array<string, true> */
    private array $issued = [];

    public function next(string $series, int $year): int
    {
        $key = $series . ':' . $year;
        $seq = ($this->cursor[$key] ?? 0) + 1;
        $unique = $key . ':' . $seq;
        if (isset($this->issued[$unique])) {
            throw new \RuntimeException('Series collision on ' . $unique);
        }
        $this->issued[$unique] = true;
        $this->cursor[$key] = $seq;

        return $seq;
    }
}
