# PHP Posting Kernel

The transactional boundary around an invoice ledger.

`php-invoice-ledger` does arithmetic. This library is what happens when a document must **exist exactly once**: allocate `FAC-2026-000041` without collisions, honour an Idempotency-Key, emit one outbox event, and reverse the VAT with a storno instead of deleting history.

Built by **SC EMPIRE GLOBAL SOLUTIONS SRL** (EMPIREX Tech). Original kernel from the invoicing domain. Not a dump of EMPIREBILL, not ANAF certificates, not a client company file.

## Why it exists

SaaS invoicing dies on four bugs:

1. Two requests mint the same series number.
2. A retried POST creates a second invoice.
3. Credit notes are a `DELETE` or a float tweak.
4. Downstream (e-invoice, email, ledger export) is called in the HTTP request.

The kernel keeps those rules in PHP 8.3 objects so Laravel, Symfony, or a CLI worker can share them.

## Flow

```
Command + Idempotency-Key
        │
   seen before? ── hash match → replay, no new number, no extra outbox
        │ hash mismatch → Conflict
        │
  SeriesStore.next()     unique(series, year, seq)
        │
  integer VAT totals     mixed rates allowed
        │
  Ledger.put + Outbox.append(document.posted)
```

Storno walks the posted lines, negates unit prices, allocates `STC-…`, and appends `document.storno`. You cannot storno a storno.

## Usage

```php
$kernel = new EmpireX\Posting\Kernel\PostingKernel(
    new EmpireX\Posting\Allocation\MemorySeriesStore(),
    new EmpireX\Posting\Idempotency\MemoryStore(),
    new EmpireX\Posting\Outbox\MemoryOutbox(),
);

$posted = $kernel->post(new EmpireX\Posting\Kernel\Command(
    idempotencyKey: 'req-41',
    series: 'FAC',
    year: 2026,
    sellerTaxId: 'RO12345678',
    buyerTaxId: 'RO87654321',
    issuedAt: new DateTimeImmutable('2026-10-13'),
    lines: [
        new EmpireX\Posting\Document\Line('SKU-1', 'Item 1', 10, EmpireX\Posting\Money\Minor::fromDecimal('100.00', 'RON'), 1900),
        new EmpireX\Posting\Document\Line('SKU-2', 'Item 2', 5, EmpireX\Posting\Money\Minor::fromDecimal('200.00', 'RON'), 1900),
    ],
));

$posted->document->number->format();          // FAC-2026-000001
$kernel->post($sameCommand)->status;          // replayed
$kernel->storno('FAC-2026-000001', 'stn-1', new DateTimeImmutable('2026-10-14'));
```

Swap `Memory*` for a PDO store that uses `SELECT … FOR UPDATE` and a unique index. The kernel does not care.

## Tests

```bash
composer install
composer test
```

No vendor required for the bundled runner:

```bash
php tests/run.php
```

Covered: mixed 9% + 19% VAT, 20 unique series numbers, idempotent replay, payload conflict, storno negatives, fail-closed missing source, no storno-of-storno, one storno per source.

## Security

Tax IDs are data. Do not commit live CUI lists, SPV certificates, or Stripe keys. Idempotency keys are request-scoped, not secrets.

## Related

- [`php-invoice-ledger`](https://github.com/empirextech/php-invoice-ledger) — integer VAT arithmetic
- [empirex.tech/engineering](https://empirex.tech/engineering) — public practice

## License

MIT — EMPIREX Tech / SC EMPIRE GLOBAL SOLUTIONS SRL
