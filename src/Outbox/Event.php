<?php

declare(strict_types=1);

namespace EmpireX\Posting\Outbox;

final readonly class Event
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $id,
        public string $type,
        public array $payload,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
