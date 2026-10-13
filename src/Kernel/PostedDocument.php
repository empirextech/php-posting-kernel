<?php

declare(strict_types=1);

namespace EmpireX\Posting\Kernel;

use EmpireX\Posting\Document\DocumentNumber;
use EmpireX\Posting\Document\Line;
use EmpireX\Posting\Document\Totals;

final readonly class PostedDocument
{
    /**
     * @param list<Line> $lines
     */
    public function __construct(
        public DocumentNumber $number,
        public string $kind,
        public string $sellerTaxId,
        public string $buyerTaxId,
        public \DateTimeImmutable $issuedAt,
        public array $lines,
        public Totals $totals,
        public string $payloadHash,
        public ?string $sourceNumber = null,
    ) {
    }
}
