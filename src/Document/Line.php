<?php

declare(strict_types=1);

namespace EmpireX\Posting\Document;

use EmpireX\Posting\Money\Minor;

final readonly class Line
{
    public function __construct(
        public string $sku,
        public string $description,
        public int $quantity,
        public Minor $unitPrice,
        public int $vatBasisPoints,
    ) {
        if ($this->sku === '' || $this->description === '') {
            throw new \InvalidArgumentException('Line needs sku and description.');
        }
        if ($this->quantity === 0) {
            throw new \InvalidArgumentException('Quantity cannot be zero.');
        }
        if ($this->vatBasisPoints < 0 || $this->vatBasisPoints > 10_000) {
            throw new \InvalidArgumentException('VAT basis points out of range.');
        }
    }

    public function net(): Minor
    {
        return $this->unitPrice->multiply($this->quantity);
    }

    public function vat(): Minor
    {
        return $this->net()->vat($this->vatBasisPoints);
    }

    public function reverse(): self
    {
        return new self(
            $this->sku,
            $this->description,
            $this->quantity,
            $this->unitPrice->negate(),
            $this->vatBasisPoints,
        );
    }

    /** @return array<string, int|string> */
    public function fingerprint(): array
    {
        return [
            'sku' => $this->sku,
            'qty' => $this->quantity,
            'unit' => $this->unitPrice->amount,
            'vat' => $this->vatBasisPoints,
            'ccy' => $this->unitPrice->currency,
        ];
    }
}
