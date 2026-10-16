<?php

declare(strict_types=1);

namespace Yukazakiri\Lepton\Contracts;

use Yukazakiri\Lepton\DTOs\VerifiedNotification;

interface WebhookVerifier
{
    /** @param list<string> $allowedSubscriptions */
    public function verify(string $rawPayload, string $signatureBase64, string $keyId, array $allowedSubscriptions): VerifiedNotification;
}
