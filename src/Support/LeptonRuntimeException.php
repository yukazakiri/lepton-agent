<?php

declare(strict_types=1);

namespace Yukazakiri\Lepton\Support;

use RuntimeException;

/**
 * Raised when a gateway call succeeds at the process level but the response
 * cannot be trusted, for example a transfer with no settlement hash.
 */
final class LeptonRuntimeException extends RuntimeException {}
