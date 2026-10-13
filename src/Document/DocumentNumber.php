<?php

declare(strict_types=1);

namespace EmpireX\Posting\Document;

final readonly class DocumentNumber
{
    public function __construct(
        public string $series,
        public int $year,
        public int $sequence,
    ) {
        if (!preg_match('/^[A-Z]{3}$/', $this->series)) {
            throw new \InvalidArgumentException('Series must be three A-Z letters.');
        }
        if ($this->year < 2000 || $this->sequence < 1) {
            throw new \InvalidArgumentException('Year/sequence out of range.');
        }
    }

    public function format(): string
    {
        return sprintf('%s-%d-%06d', $this->series, $this->year, $this->sequence);
    }
}
