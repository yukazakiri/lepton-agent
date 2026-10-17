---
title: Offline quickstart
description: Rehearse a USDC transfer in Laravel with the in-memory fake and no network or credentials.
---

## Run inside Laravel

[Install package](../installation/) and set `LEPTON_DRIVER=fake` before resolving
any SDK service. Open your application's Tinker shell:

```bash
php artisan tinker
```

Paste this PHP (omit `<?php` in Tinker):

```php
use Yukazakiri\Lepton\Facades\Lepton;
use Yukazakiri\Lepton\Gateways\FakeLeptonGateway;
use Yukazakiri\Lepton\Support\Amounts;

// Example addresses for simulation only. Never use them as live recipients.
$treasury = '0x1111111111111111111111111111111111111111';
$recipient = '0x2222222222222222222222222222222222222222';
$fake = app(FakeLeptonGateway::class);
$fake->seedBalance($treasury, Amounts::fromDecimalString('5.00'));

$estimate = Lepton::estimateTransfer($treasury, $recipient, 1_250_000);
$result = Lepton::transferDecimal($treasury, $recipient, '1.25');

$result->isFake; // true: no funds moved
Lepton::balance($treasury)->amountBaseUnits; // 3750000
Lepton::balance($recipient)->amountBaseUnits; // 1250000
Lepton::resolve($result->handle)->state; // 'completed' (simulated)
Amounts::toDecimalString(3_750_000); // '3.75'
```

The facade and fake class resolve to the same scoped instance. Fake balances
exist only in memory. A new CLI process or application scope does not retain
this ledger. Calling `lepton:wallet:fund` in one shell process will not seed
balances for a later `lepton:transfer` process.

## What result means

- `estimateTransfer()` produces an array, never a transfer result.
- `TransferResult::handle` identifies a provider submission; `txHash` is separate.
- Fake fills both with synthetic references. REST initially returns a provider
  UUID with `txHash=null`.
- Fake immediately resolves its own submissions as completed. This does **not**
  rehearse provider delays, idempotency, finality, signatures, or custody rules.

## Move example into application

Inject `WalletGateway` for balance/transfer/history/limits. Use `TransferObserver`
for observation and `WalletManagementGateway` for wallet creation. Facade offers
convenient access to the same configured driver; neither approach stores your
payment records.

Next: [choose a driver](../drivers/), then use [reliable payments](../../guides/payments/)
to separate approval, submission, observation, and settlement.
