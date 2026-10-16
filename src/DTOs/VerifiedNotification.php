<?php

declare(strict_types=1);

namespace Yukazakiri\Lepton\DTOs;

use DateTimeImmutable;

/** A verified notification is a wake-up hint, not authorization or settlement. */
final readonly class VerifiedNotification
{
    /** @param array<string, mixed> $notification */
    public function __construct(
        public string $notificationId,
        public string $subscriptionId,
        public string $notificationType,
        public array $notification,
        public DateTimeImmutable $timestamp,
        public string $payloadDigest,
    ) {}
}
