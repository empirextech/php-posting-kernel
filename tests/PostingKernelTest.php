<?php

declare(strict_types=1);

namespace EmpireX\Posting\Tests;

use EmpireX\Posting\Allocation\MemorySeriesStore;
use EmpireX\Posting\Document\Line;
use EmpireX\Posting\Idempotency\Conflict;
use EmpireX\Posting\Idempotency\MemoryStore;
use EmpireX\Posting\Kernel\Command;
use EmpireX\Posting\Kernel\PostingKernel;
use EmpireX\Posting\Money\Minor;
use EmpireX\Posting\Outbox\MemoryOutbox;
use PHPUnit\Framework\TestCase;

final class PostingKernelTest extends TestCase
{
    public function testMixedVatAndClassicTotals(): void
    {
        $kernel = $this->kernel();
        $posted = $kernel->post($this->invoice('key-a', [
            new Line('SKU-1', 'Item 1', 10, Minor::fromDecimal('100.00', 'RON'), 1900),
            new Line('SKU-2', 'Item 2', 5, Minor::fromDecimal('200.00', 'RON'), 1900),
            new Line('SKU-3', 'Reduced', 1, Minor::fromDecimal('50.00', 'RON'), 900),
        ]))->document;

        self::assertSame('FAC-2026-000001', $posted->number->format());
        self::assertSame('2050.00', $posted->totals->net->toDecimal());
        self::assertSame('384.50', $posted->totals->vat->toDecimal());
        self::assertSame('2434.50', $posted->totals->gross->toDecimal());
    }

    public function testSeriesIncrementsAndNeverCollides(): void
    {
        $kernel = $this->kernel();
        $seen = [];
        for ($i = 0; $i < 20; $i++) {
            $number = $kernel->post($this->invoice('k-' . $i, [
                new Line('SKU', 'Work', 1, Minor::fromDecimal('10.00', 'RON'), 1900),
            ]))->document->number->format();
            $seen[$number] = true;
        }

        self::assertCount(20, $seen);
        self::assertArrayHasKey('FAC-2026-000020', $seen);
    }

    public function testIdempotentReplayDoesNotBurnANumberOrDuplicateOutbox(): void
    {
        $outbox = new MemoryOutbox();
        $kernel = $this->kernel($outbox);
        $lines = [new Line('SKU', 'Work', 1, Minor::fromDecimal('100.00', 'RON'), 1900)];
        $first = $kernel->post($this->invoice('same', $lines));
        $second = $kernel->post($this->invoice('same', $lines));

        self::assertTrue($first->posted());
        self::assertFalse($second->posted());
        self::assertSame('FAC-2026-000001', $second->document->number->format());
        self::assertCount(1, $outbox->all());

        $third = $kernel->post($this->invoice('other', $lines));
        self::assertSame('FAC-2026-000002', $third->document->number->format());
    }

    public function testIdempotencyConflictWhenPayloadChanges(): void
    {
        $kernel = $this->kernel();
        $kernel->post($this->invoice('same', [
            new Line('SKU', 'Work', 1, Minor::fromDecimal('100.00', 'RON'), 1900),
        ]));

        $this->expectException(Conflict::class);
        $kernel->post($this->invoice('same', [
            new Line('SKU', 'Work', 2, Minor::fromDecimal('100.00', 'RON'), 1900),
        ]));
    }

    public function testStornoNegatesVatAndUsesItsOwnSeries(): void
    {
        $outbox = new MemoryOutbox();
        $kernel = $this->kernel($outbox);
        $invoice = $kernel->post($this->invoice('inv', [
            new Line('SKU-1', 'Item 1', 10, Minor::fromDecimal('100.00', 'RON'), 1900),
            new Line('SKU-2', 'Item 2', 5, Minor::fromDecimal('200.00', 'RON'), 1900),
        ]))->document;

        $storno = $kernel->storno($invoice->number->format(), 'storno-1', new \DateTimeImmutable('2026-10-14'));

        self::assertSame('STC-2026-000001', $storno->document->number->format());
        self::assertSame('storno', $storno->document->kind);
        self::assertSame('-2000.00', $storno->document->totals->net->toDecimal());
        self::assertSame('-380.00', $storno->document->totals->vat->toDecimal());
        self::assertSame('-2380.00', $storno->document->totals->gross->toDecimal());
        self::assertSame(['document.posted', 'document.storno'], array_map(
            static fn ($event) => $event->type,
            $outbox->all(),
        ));
    }

    public function testStornoOfMissingNumberFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->kernel()->storno('FAC-2026-000099', 'x', new \DateTimeImmutable('2026-10-13'));
    }

    public function testCannotStornoAStorno(): void
    {
        $kernel = $this->kernel();
        $invoice = $kernel->post($this->invoice('inv', [
            new Line('SKU', 'Work', 1, Minor::fromDecimal('100.00', 'RON'), 1900),
        ]))->document;
        $storno = $kernel->storno($invoice->number->format(), 'storno-1', new \DateTimeImmutable('2026-10-14'));

        $this->expectException(\InvalidArgumentException::class);
        $kernel->storno($storno->document->number->format(), 'storno-2', new \DateTimeImmutable('2026-10-15'));
    }

    public function testSecondStornoOfSameSourceFailsClosed(): void
    {
        $kernel = $this->kernel();
        $invoice = $kernel->post($this->invoice('inv', [
            new Line('SKU', 'Work', 1, Minor::fromDecimal('100.00', 'RON'), 1900),
        ]))->document;
        $first = $kernel->storno($invoice->number->format(), 'storno-1', new \DateTimeImmutable('2026-10-14'));
        $replay = $kernel->storno($invoice->number->format(), 'storno-1', new \DateTimeImmutable('2026-10-14'));

        self::assertFalse($replay->posted());
        self::assertSame($first->document->number->format(), $replay->document->number->format());

        $this->expectException(\InvalidArgumentException::class);
        $kernel->storno($invoice->number->format(), 'storno-other', new \DateTimeImmutable('2026-10-15'));
    }

    /**
     * @param list<Line> $lines
     */
    private function invoice(string $key, array $lines): Command
    {
        return new Command(
            idempotencyKey: $key,
            series: 'FAC',
            year: 2026,
            sellerTaxId: 'RO12345678',
            buyerTaxId: 'RO87654321',
            issuedAt: new \DateTimeImmutable('2026-10-13'),
            lines: $lines,
        );
    }

    private function kernel(?MemoryOutbox $outbox = null): PostingKernel
    {
        return new PostingKernel(
            new MemorySeriesStore(),
            new MemoryStore(),
            $outbox ?? new MemoryOutbox(),
        );
    }
}
