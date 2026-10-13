<?php

declare(strict_types=1);

namespace EmpireX\Posting\Allocation;

interface SeriesStore
{
    /**
     * Allocate the next sequence for series+year. Must be unique under concurrency.
     * Implementations should hold a row lock or use a unique index + retry.
     */
    public function next(string $series, int $year): int;
}
