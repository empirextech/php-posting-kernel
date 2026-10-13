<?php

declare(strict_types=1);

namespace EmpireX\Posting\Kernel;

final readonly class Decision
{
    public function __construct(
        public string $status,
        public PostedDocument $document,
    ) {
        if (!in_array($this->status, ['posted', 'replayed'], true)) {
            throw new \InvalidArgumentException('Unknown posting status.');
        }
    }

    public function posted(): bool
    {
        return $this->status === 'posted';
    }
}
