<?php

namespace App\Services\Cardcom;

/**
 * Normalized result of a Cardcom charge attempt. Recorded verbatim on the
 * charges row — success and failure alike.
 */
readonly class ChargeResult
{
    public function __construct(
        public bool $success,
        public ?string $transactionId,
        public string $responseCode,
        public ?string $message = null,
    ) {}
}
