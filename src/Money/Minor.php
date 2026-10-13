<?php

declare(strict_types=1);

namespace EmpireX\Posting\Money;

/**
 * Signed integer minor units. Storno needs negatives. Floats never enter.
 */
final readonly class Minor
{
    public function __construct(
        public int $amount,
        public string $currency,
    ) {
        if (strlen($this->currency) !== 3) {
            throw new \InvalidArgumentException('Currency must be ISO 4217.');
        }
    }

    public static function fromDecimal(string $decimal, string $currency): self
    {
        if (!preg_match('/^-?\d+(\.\d{1,2})?$/', $decimal)) {
            throw new \InvalidArgumentException('Invalid decimal.');
        }
        $sign = str_starts_with($decimal, '-') ? -1 : 1;
        $unsigned = ltrim($decimal, '-');
        $parts = explode('.', $unsigned, 2);
        $minor = isset($parts[1]) ? str_pad($parts[1], 2, '0') : '00';

        return new self($sign * (((int) $parts[0] * 100) + (int) $minor), strtoupper($currency));
    }

    public static function zero(string $currency): self
    {
        return new self(0, strtoupper($currency));
    }

    public function add(self $other): self
    {
        $this->assertSame($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function negate(): self
    {
        return new self(-$this->amount, $this->currency);
    }

    public function multiply(int $quantity): self
    {
        return new self($this->amount * $quantity, $this->currency);
    }

    /**
     * VAT in basis points (1900 = 19%). Half-up on the absolute value, then sign restored.
     */
    public function vat(int $basisPoints): self
    {
        if ($basisPoints < 0 || $basisPoints > 10_000) {
            throw new \InvalidArgumentException('VAT basis points out of range.');
        }
        $sign = $this->amount < 0 ? -1 : 1;
        $raw = abs($this->amount) * $basisPoints;
        $vat = intdiv($raw, 10_000);
        if (($raw % 10_000) >= 5_000) {
            $vat++;
        }

        return new self($sign * $vat, $this->currency);
    }

    public function toDecimal(): string
    {
        $sign = $this->amount < 0 ? '-' : '';
        $abs = abs($this->amount);

        return sprintf('%s%d.%02d', $sign, intdiv($abs, 100), $abs % 100);
    }

    private function assertSame(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new \InvalidArgumentException('Currency mismatch.');
        }
    }
}
