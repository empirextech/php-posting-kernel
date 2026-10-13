<?php

declare(strict_types=1);

namespace EmpireX\Posting\Document;

use EmpireX\Posting\Money\Minor;

final readonly class Totals
{
    public function __construct(
        public Minor $net,
        public Minor $vat,
        public Minor $gross,
    ) {
    }

    /**
     * @param list<Line> $lines
     */
    public static function of(array $lines, string $currency): self
    {
        if ($lines === []) {
            throw new \InvalidArgumentException('Totals need at least one line.');
        }
        $net = Minor::zero($currency);
        $vat = Minor::zero($currency);
        foreach ($lines as $line) {
            $net = $net->add($line->net());
            $vat = $vat->add($line->vat());
        }

        return new self($net, $vat, $net->add($vat));
    }
}
