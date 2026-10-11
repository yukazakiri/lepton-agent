<?php

declare(strict_types=1);

namespace Yukazakiri\Lepton\DTOs;

/**
 * What a rail knows about a transfer it previously accepted.
 *
 * Circle agent wallets settle **asynchronously**. A submission answers with a
 * Circle transaction id, and the on-chain hash only exists once the transaction
 * reaches a terminal state -- which can be blocks or minutes later. Collapsing
 * those two facts into one "hash" is what makes an app believe an in-flight
 * payment has settled, or report a settled one as unfindable.
 */
final readonly class TransferResolution
{
    /**
     * @param  'pending'|'completed'|'failed'|'unknown'  $state
     * @param  array<string,mixed>  $raw
     */
    private function __construct(
        public string $state,
        public ?string $txHash = null,
        public ?string $handle = null,
        public ?string $reason = null,
        public array $raw = [],
    ) {}

    /** @param array<string,mixed> $raw */
    public static function pending(?string $handle = null, array $raw = []): self
    {
        return new self('pending', handle: $handle, reason: 'The rail has not reached a terminal state for this transfer.', raw: $raw);
    }

    /** @param array<string,mixed> $raw */
    public static function completed(string $txHash, ?string $handle = null, array $raw = []): self
    {
        return new self('completed', txHash: $txHash, handle: $handle, raw: $raw);
    }

    /** @param array<string,mixed> $raw */
    public static function failed(string $reason, ?string $handle = null, array $raw = []): self
    {
        return new self('failed', handle: $handle, reason: $reason, raw: $raw);
    }

    /**
     * The rail could not be asked.
     *
     * Deliberately distinct from `failed`: an unreachable provider says nothing
     * about the transfer, and reading `unknown` as `failed` invents a conclusion
     * from a network error.
     *
     * @param  array<string,mixed>  $raw
     */
    public static function unknown(string $reason, ?string $handle = null, array $raw = []): self
    {
        return new self('unknown', handle: $handle, reason: $reason, raw: $raw);
    }

    public function hasHash(): bool
    {
        return $this->state === 'completed' && is_string($this->txHash) && $this->txHash !== '';
    }
}
