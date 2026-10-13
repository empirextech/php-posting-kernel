<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use EmpireX\Posting\Allocation\MemorySeriesStore;
use EmpireX\Posting\Document\Line;
use EmpireX\Posting\Idempotency\Conflict;
use EmpireX\Posting\Idempotency\MemoryStore;
use EmpireX\Posting\Kernel\Command;
use EmpireX\Posting\Kernel\PostingKernel;
use EmpireX\Posting\Money\Minor;
use EmpireX\Posting\Outbox\MemoryOutbox;

$failed = 0;
$passed = 0;

function expect(string $name, bool $ok, string $detail = ''): void
{
    global $failed, $passed;
    if ($ok) {
        $passed++;
        fwrite(STDOUT, "OK   {$name}\n");
        return;
    }
    $failed++;
    fwrite(STDOUT, "FAIL {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n");
}

function invoice(string $key, array $lines): Command
{
    return new Command(
        idempotencyKey: $key,
        series: 'FAC',
        year: 2026,
        sellerTaxId: 'RO12345678',
        buyerTaxId: 'RO87654321',
        issuedAt: new DateTimeImmutable('2026-10-13'),
        lines: $lines,
    );
}

function kernel(?MemoryOutbox $outbox = null): PostingKernel
{
    return new PostingKernel(
        new MemorySeriesStore(),
        new MemoryStore(),
        $outbox ?? new MemoryOutbox(),
    );
}

$kernel = kernel();
$posted = $kernel->post(invoice('key-a', [
    new Line('SKU-1', 'Item 1', 10, Minor::fromDecimal('100.00', 'RON'), 1900),
    new Line('SKU-2', 'Item 2', 5, Minor::fromDecimal('200.00', 'RON'), 1900),
    new Line('SKU-3', 'Reduced', 1, Minor::fromDecimal('50.00', 'RON'), 900),
]))->document;
expect('mixed VAT number', $posted->number->format() === 'FAC-2026-000001', $posted->number->format());
expect('mixed VAT net', $posted->totals->net->toDecimal() === '2050.00', $posted->totals->net->toDecimal());
expect('mixed VAT vat', $posted->totals->vat->toDecimal() === '384.50', $posted->totals->vat->toDecimal());
expect('mixed VAT gross', $posted->totals->gross->toDecimal() === '2434.50', $posted->totals->gross->toDecimal());

$kernel = kernel();
$seen = [];
for ($i = 0; $i < 20; $i++) {
    $seen[$kernel->post(invoice('k-' . $i, [
        new Line('SKU', 'Work', 1, Minor::fromDecimal('10.00', 'RON'), 1900),
    ]))->document->number->format()] = true;
}
expect('series unique count', count($seen) === 20, (string) count($seen));
expect('series last number', isset($seen['FAC-2026-000020']));

$outbox = new MemoryOutbox();
$kernel = kernel($outbox);
$lines = [new Line('SKU', 'Work', 1, Minor::fromDecimal('100.00', 'RON'), 1900)];
$first = $kernel->post(invoice('same', $lines));
$second = $kernel->post(invoice('same', $lines));
expect('first posts', $first->posted());
expect('second replays', $second->posted() === false && $second->status === 'replayed');
expect('replay keeps number', $second->document->number->format() === 'FAC-2026-000001');
expect('replay does not duplicate outbox', count($outbox->all()) === 1, (string) count($outbox->all()));
$third = $kernel->post(invoice('other', $lines));
expect('next key burns next number', $third->document->number->format() === 'FAC-2026-000002');

$kernel = kernel();
$kernel->post(invoice('same', [
    new Line('SKU', 'Work', 1, Minor::fromDecimal('100.00', 'RON'), 1900),
]));
$conflicted = false;
try {
    $kernel->post(invoice('same', [
        new Line('SKU', 'Work', 2, Minor::fromDecimal('100.00', 'RON'), 1900),
    ]));
} catch (Conflict) {
    $conflicted = true;
}
expect('idempotency conflict on payload change', $conflicted);

$outbox = new MemoryOutbox();
$kernel = kernel($outbox);
$invoice = $kernel->post(invoice('inv', [
    new Line('SKU-1', 'Item 1', 10, Minor::fromDecimal('100.00', 'RON'), 1900),
    new Line('SKU-2', 'Item 2', 5, Minor::fromDecimal('200.00', 'RON'), 1900),
]))->document;
$storno = $kernel->storno($invoice->number->format(), 'storno-1', new DateTimeImmutable('2026-10-14'));
expect('storno series', $storno->document->number->format() === 'STC-2026-000001');
expect('storno kind', $storno->document->kind === 'storno');
expect('storno net', $storno->document->totals->net->toDecimal() === '-2000.00', $storno->document->totals->net->toDecimal());
expect('storno vat', $storno->document->totals->vat->toDecimal() === '-380.00', $storno->document->totals->vat->toDecimal());
expect('storno gross', $storno->document->totals->gross->toDecimal() === '-2380.00', $storno->document->totals->gross->toDecimal());
$types = array_map(static fn ($event) => $event->type, $outbox->all());
expect('outbox posted then storno', $types === ['document.posted', 'document.storno'], json_encode($types) ?: '');

$missing = false;
try {
    kernel()->storno('FAC-2026-000099', 'x', new DateTimeImmutable('2026-10-13'));
} catch (InvalidArgumentException) {
    $missing = true;
}
expect('storno missing fails closed', $missing);

$kernel = kernel();
$invoice = $kernel->post(invoice('inv', [
    new Line('SKU', 'Work', 1, Minor::fromDecimal('100.00', 'RON'), 1900),
]))->document;
$storno = $kernel->storno($invoice->number->format(), 'storno-1', new DateTimeImmutable('2026-10-14'));
$blocked = false;
try {
    $kernel->storno($storno->document->number->format(), 'storno-2', new DateTimeImmutable('2026-10-15'));
} catch (InvalidArgumentException) {
    $blocked = true;
}
expect('cannot storno a storno', $blocked);

$kernel = kernel();
$invoice = $kernel->post(invoice('inv', [
    new Line('SKU', 'Work', 1, Minor::fromDecimal('100.00', 'RON'), 1900),
]))->document;
$first = $kernel->storno($invoice->number->format(), 'storno-1', new DateTimeImmutable('2026-10-14'));
$replay = $kernel->storno($invoice->number->format(), 'storno-1', new DateTimeImmutable('2026-10-14'));
expect('storno replay keeps number', $replay->posted() === false && $replay->document->number->format() === $first->document->number->format());
$dup = false;
try {
    $kernel->storno($invoice->number->format(), 'storno-other', new DateTimeImmutable('2026-10-15'));
} catch (InvalidArgumentException) {
    $dup = true;
}
expect('second storno of same source fails closed', $dup);

fwrite(STDOUT, "\n{$passed} passed, {$failed} failed\n");
exit($failed === 0 ? 0 : 1);
