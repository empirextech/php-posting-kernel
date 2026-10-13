<?php

declare(strict_types=1);

namespace EmpireX\Posting\Kernel;

use EmpireX\Posting\Document\Line;

final readonly class Command
{
    /**
     * @param list<Line> $lines
     */
    public function __construct(
        public string $idempotencyKey,
        public string $series,
        public int $year,
        public string $sellerTaxId,
        public string $buyerTaxId,
        public \DateTimeImmutable $issuedAt,
        public array $lines,
        public string $currency = 'RON',
        public string $kind = 'invoice',
        public ?string $sourceNumber = null,
    ) {
        if ($this->idempotencyKey === '') {
            throw new \InvalidArgumentException('Idempotency key is required.');
        }
        if ($this->lines === []) {
            throw new \InvalidArgumentException('Posting needs at least one line.');
        }
        if (!in_array($this->kind, ['invoice', 'storno'], true)) {
            throw new \InvalidArgumentException('Kind must be invoice or storno.');
        }
    }

    public function payloadHash(): string
    {
        $body = [
            'kind' => $this->kind,
            'series' => $this->series,
            'year' => $this->year,
            'seller' => $this->sellerTaxId,
            'buyer' => $this->buyerTaxId,
            'ccy' => $this->currency,
            'source' => $this->sourceNumber,
            'lines' => array_map(static fn (Line $line) => $line->fingerprint(), $this->lines),
        ];

        return hash('sha256', json_encode($body, JSON_THROW_ON_ERROR));
    }
}
