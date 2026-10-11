<?php

declare(strict_types=1);

namespace Yukazakiri\Lepton\DTOs;

final readonly class TransferResult
{
    /**
     * @param  string|null  $txHash  The on-chain reference, or null when the rail has accepted the
     *                              transfer but has not published one yet. An agent-wallet
     *                              submission lands in that state legitimately.
     * @param  string|null  $handle  The rail's own identifier for the transfer. This is what an
     *                              asynchronous rail answers with, and it is **not** a hash -- feeding
     *                              it anywhere a hash is expected reads as "not found", which is an
     *                              accusation against a payment that is merely in flight.
     * @param  array<string,mixed>  $raw
     */
    public function __construct(
        public ?string $txHash,
        public string $fromAddress,
        public string $toAddress,
        public int $amountBaseUnits,
        public string $chain,
        public bool $isFake = false,
        public ?string $explorerUrl = null,
        public array $raw = [],
        public ?string $handle = null,
        public ?string $state = null,
    ) {}
}
