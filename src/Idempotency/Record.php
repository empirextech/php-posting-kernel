<?php

declare(strict_types=1);

namespace EmpireX\Posting\Idempotency;

final readonly class Record
{
    public function __construct(
        public string $key,
        public string $payloadHash,
        public string $documentNumber,
    ) {
    }
}
