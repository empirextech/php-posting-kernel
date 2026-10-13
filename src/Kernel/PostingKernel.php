<?php

declare(strict_types=1);

namespace EmpireX\Posting\Kernel;

use EmpireX\Posting\Allocation\SeriesStore;
use EmpireX\Posting\Document\DocumentNumber;
use EmpireX\Posting\Document\Line;
use EmpireX\Posting\Document\Totals;
use EmpireX\Posting\Idempotency\Conflict;
use EmpireX\Posting\Idempotency\Record;
use EmpireX\Posting\Idempotency\Store as IdempotencyStore;
use EmpireX\Posting\Outbox\Event;
use EmpireX\Posting\Outbox\Outbox;

final class PostingKernel
{
    public function __construct(
        private readonly SeriesStore $series,
        private readonly IdempotencyStore $idempotency,
        private readonly Outbox $outbox,
        private readonly Ledger $ledger = new Ledger(),
    ) {
    }

    public function post(Command $command): Decision
    {
        $hash = $command->payloadHash();
        $existing = $this->idempotency->find($command->idempotencyKey);
        if ($existing !== null) {
            if ($existing->payloadHash !== $hash) {
                throw new Conflict('Idempotency key reused with a different payload.');
            }
            $replay = $this->ledger->get($existing->documentNumber);
            if ($replay === null) {
                throw new \RuntimeException('Idempotent hit without a ledger row.');
            }

            return new Decision('replayed', $replay);
        }

        $seq = $this->series->next($command->series, $command->year);
        $number = new DocumentNumber($command->series, $command->year, $seq);
        $document = new PostedDocument(
            number: $number,
            kind: $command->kind,
            sellerTaxId: $command->sellerTaxId,
            buyerTaxId: $command->buyerTaxId,
            issuedAt: $command->issuedAt,
            lines: $command->lines,
            totals: Totals::of($command->lines, $command->currency),
            payloadHash: $hash,
            sourceNumber: $command->sourceNumber,
        );

        $this->ledger->put($document);
        $this->idempotency->remember(new Record($command->idempotencyKey, $hash, $number->format()));
        $this->outbox->append(new Event(
            id: $number->format() . ':' . $hash,
            type: $command->kind === 'storno' ? 'document.storno' : 'document.posted',
            payload: [
                'number' => $number->format(),
                'gross' => $document->totals->gross->toDecimal(),
                'hash' => $hash,
            ],
            occurredAt: $command->issuedAt,
        ));

        return new Decision('posted', $document);
    }

    public function storno(string $sourceNumber, string $idempotencyKey, \DateTimeImmutable $issuedAt): Decision
    {
        $source = $this->ledger->get($sourceNumber);
        if ($source === null) {
            throw new \InvalidArgumentException('Cannot storno a number that was never posted.');
        }
        if ($source->kind === 'storno') {
            throw new \InvalidArgumentException('Cannot storno a storno.');
        }
        if ($this->idempotency->find($idempotencyKey) === null) {
            foreach ($this->ledger->all() as $existing) {
                if ($existing->kind === 'storno' && $existing->sourceNumber === $sourceNumber) {
                    throw new \InvalidArgumentException('Source already has a storno.');
                }
            }
        }

        $reversals = array_map(static fn (Line $line) => $line->reverse(), $source->lines);

        return $this->post(new Command(
            idempotencyKey: $idempotencyKey,
            series: 'STC',
            year: (int) $issuedAt->format('Y'),
            sellerTaxId: $source->sellerTaxId,
            buyerTaxId: $source->buyerTaxId,
            issuedAt: $issuedAt,
            lines: $reversals,
            currency: $source->totals->gross->currency,
            kind: 'storno',
            sourceNumber: $sourceNumber,
        ));
    }

    public function ledger(): Ledger
    {
        return $this->ledger;
    }
}
