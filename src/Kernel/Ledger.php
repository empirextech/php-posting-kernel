<?php

declare(strict_types=1);

namespace EmpireX\Posting\Kernel;

final class Ledger
{
    /** @var array<string, PostedDocument> */
    private array $byNumber = [];

    public function put(PostedDocument $document): void
    {
        $this->byNumber[$document->number->format()] = $document;
    }

    public function get(string $number): ?PostedDocument
    {
        return $this->byNumber[$number] ?? null;
    }

    /** @return list<PostedDocument> */
    public function all(): array
    {
        return array_values($this->byNumber);
    }
}
