---
title: Signing and contracts
description: Use explicit REST signing and ABI execution methods without mistaking signatures or estimates for settlement.
---

Signing can authorize valuable actions even when it does not broadcast. Apply
application authorization, domain restrictions, replay protection, and audit
policy to every signing request. Never expose arbitrary signing endpoints to
untrusted users. REST dry-run blocks message/typed signing and write execution.

Examples are server-side fragments with reviewed application inputs and
[configured REST driver](../developer-wallets/).

## Message signing

```php
use Yukazakiri\Lepton\Facades\Lepton;

$signed = Lepton::circle()->signMessage($reviewedWallet, $approvedMessage, [
    'chain' => $approvedChain,
]);
```

Message must be nonempty. `encodedByHex=true` requires `0x` plus complete hex
bytes. Response is provider data array, not `TransferResult`. This endpoint does
not use the transaction UUID idempotency option; do not assume all signing calls
share transfer recovery semantics.

## EIP-712 signing

```php
$signed = Lepton::circle()->signTypedData(
    $reviewedWallet,
    $approvedTypedDataJson,
    ['chain' => $approvedChain],
);
```

JSON must contain `types`, `domain`, `primaryType`, and `message`. If domain
includes `chainId`, REST validates it against selected Arc network. This is
structural checking, not approval of verifying contract, spender, nonce,
expiry, or semantic safety. Review those in application policy.

## Estimate and execute ABI call

```php
$estimate = Lepton::circle()->execute(
    $reviewedWallet,
    $reviewedContract,
    $approvedFunctionSignature,
    $approvedAbiParameters,
    ['chain' => $approvedChain, 'estimate' => true],
);

// Separate authorized write, after persisted UUID v4 and reviewed fee policy.
$execution = Lepton::circle()->execute(
    $reviewedWallet,
    $reviewedContract,
    $approvedFunctionSignature,
    $approvedAbiParameters,
    [
        'chain' => $approvedChain,
        'idempotencyKey' => $persistedExecutionUuid,
        'feeLevel' => 'MEDIUM',
    ],
);
```

Signature uses ABI function form such as `transfer(address,uint256)`. Estimate
uses explicit estimate endpoint and does not broadcast. Optional `amount` is an
exact decimal string for native value; current validation uses six-decimal amount
parser. Fee level is not a hard fee cap. Execution returns provider data array;
store returned identity and observe it with the same reconciliation discipline
as [payments](../payments/).

REST does not implement `contractQuery()` or contract registry. Use
`ArcNetworkGateway::rpc('eth_call', ...)` for reviewed read calls. Gateway deposits,
CCTP, and x402 remain unsupported by REST; never silently switch to CLI signing.
