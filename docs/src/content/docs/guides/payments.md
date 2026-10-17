---
title: Reliable payments
description: Design approved payouts with durable idempotency, separate observation, and explicit settlement checks.
---

## Scenario: approved merchant payout

A customer clicks “pay” twice; a worker times out after submitting; a notification
arrives before the worker stores its response. None of these should create a
second payout. SDK exposes transport primitives, not a durable payment engine.

### 1. Persist intent before submission

In an application DB transaction, authorize the payer and recipient, validate
network/token/exact amount, and store an immutable payment intent. Generate a
UUID v4 **once**, persist it with the operation, then commit before network I/O.
Use a unique business-intent key and durable outbox/claim mechanism so concurrent
workers cannot independently submit the same approved payout.

Store at least:

| Record | Fields |
| --- | --- |
| Intent | Business ID, authorization, sender wallet/set, recipient, chain, token, integer amount |
| Submission | Persisted UUID v4, request fingerprint, attempt state, provider ID when known |
| Observation | Provider state, nullable hash, evidence timestamp, error/request ID |
| Settlement | Receipt checks, finality evidence, reconciliation decision |

These are application-owned records, not SDK models or migrations. Do not hold
a DB transaction open while waiting for provider network calls.

### 2. Estimate separately

```php
use Yukazakiri\Lepton\Facades\Lepton;
use Yukazakiri\Lepton\Support\Amounts;

$units = Amounts::fromDecimalString($approvedDecimalAmount);
$estimate = Lepton::estimateTransfer($treasury, $recipient, $units, [
    'chain' => $approvedChain,
]);
```

Variables above and below refer to reviewed application records. REST examples
require [reviewed driver setup](../developer-wallets/). Estimate does not submit,
reserve funds, or enforce a fee ceiling. `feeLevel` is `LOW`, `MEDIUM` (default),
or `HIGH`. Unsupported fee-ceiling/gas override options throw; keep execution
gated if business policy requires a hard fee cap.

### 3. Submit approved operation

Only after operator-authorized write enablement (`LEPTON_DRY_RUN=false` for REST):

```php
$result = Lepton::transfer($treasury, $recipient, $units, [
    'chain' => $approvedChain,
    'idempotencyKey' => $persistedSubmissionUuid,
    'feeLevel' => 'MEDIUM',
]);

// Persist these on the existing submission record, not a new payment.
$providerId = $result->handle;
$hash = $result->txHash;
$isSimulated = $result->isFake;
```

REST requires persisted UUID v4 and positive integer USDC units. EVM recipients
must be nonzero `0x` plus exactly 40 hex characters. Syntax is not recipient
ownership verification. Default token is canonical Arc USDC; `token=native` is
also supported. Arbitrary token identifiers are rejected.

REST returns `state=accepted`, provider UUID, and `txHash=null`. A successful
request is **not** a settled payout. `transferDecimal()` accepts exact decimal
strings and converts at configured six-decimal boundary; never pass floats.

### 4. Observe without sending again

```php
$resolution = Lepton::resolve($providerId, [
    'chain' => $approvedChain,
    'address' => $treasury,
    'walletId' => $approvedWalletId,
]);
```

| Resolution | Meaning | Application response |
| --- | --- | --- |
| `pending` | Provider has not supplied terminal success with valid hash | Schedule bounded observation |
| `completed` | REST `COMPLETE` with valid 32-byte hash | Verify receipt and settlement policy |
| `failed` | Provider reports `FAILED`, `DENIED`, or `CANCELLED` | Reconcile; fees may have been charged |
| `unknown` | Missing/unavailable/mismatched/unrecognized evidence | Preserve intent; investigate, do not resend blindly |

REST `CONFIRMED` remains pending. Even `COMPLETE` without valid hash stays pending.
`hasHash()` means completed resolution with nonempty hash, not receipt validation.
Bound polling with backoff, age/attempt limits, and operator escalation. A
notification should wake the same observer, never initiate another transfer.

### 5. Verify business settlement

Read successful receipt and transaction on intended chain. Match sender,
recipient, canonical token and exact amount; apply reviewed finality policy.
Persist evidence and make terminal business transitions monotonic. SDK never
marks an invoice paid. Verified notifications and explorer links are hints, not
authorization or settlement proof.

## Ambiguous submission recovery

SDK HTTP transport follows no redirects and automatically retries no mutations.
Timeout or malformed response can occur **after** provider accepts request.
An exception is not proof no funds moved.

If provider ID is known, observe that ID. If response was lost before ID storage,
mark submission ambiguous and recover through original persisted key, provider
records, and reviewed Circle recovery procedure. Do not invent a provider ID,
new intent, or fresh idempotency key. Preserve identical operation semantics;
provider idempotency alone does not replace application outbox or concurrency
control. Do not blindly replay requests until provider retry semantics and
retention window have been verified.

Catch `ApiException` for HTTP status and redacted request ID; catch validation
errors separately. Log correlation metadata, never credentials, ciphertext,
full raw payloads, or signing inputs by default.
