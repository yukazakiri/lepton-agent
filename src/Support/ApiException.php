<?php

declare(strict_types=1);

namespace Yukazakiri\Lepton\Support;

final class ApiException extends LeptonRuntimeException
{
    public function __construct(public readonly int $status, public readonly ?string $requestId = null)
    {
        parent::__construct('Circle API request failed with HTTP '.$status.'.'.($requestId === null ? '' : ' Request ID: '.$requestId));
    }
}
