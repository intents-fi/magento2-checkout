<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Model\Webhook;

/** The HTTP answer to one delivery. */
class Result
{
    /** @param array<string, mixed> $body */
    public function __construct(
        public readonly int $status,
        public readonly array $body,
        /** For the log: what happened, without any secret or payload. */
        public readonly string $summary
    ) {
    }
}
