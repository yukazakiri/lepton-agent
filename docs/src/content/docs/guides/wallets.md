---
title: Wallet lifecycle
description: Explicit wallet-set selection, wallet creation, and testnet faucet observation.
---

For a merchant onboarding flow, store approved wallet-set, network, account type,
and wallet identity in application records. Never choose the first returned set
or infer custody from an address.

These REST examples assume [configuration limitations](../developer-wallets/)
have been resolved and reviewed. Variables come from durable application records;
examples are integration fragments, not standalone provisioning scripts.

## Select or explicitly create a wallet set

Use an existing reviewed set whenever appropriate. Creating one is an explicit
operator action, not an automatic fallback:

```php
use Yukazakiri\Lepton\Facades\Lepton;

$set = Lepton::createWalletSet('Merchant treasury', [
    'idempotencyKey' => $persistedWalletSetCreationUuid,
]);
// Persist and review returned wallet-set identity before using it.
```

Wallet-set creation is REST-only. Persist UUID v4 before request, and preserve
same operation identity during recovery. Mutations are blocked in REST dry-run.

## List and create wallets

```php
use Yukazakiri\Lepton\Contracts\WalletManagementGateway;

$wallets = app(WalletManagementGateway::class);
$chain = config('lepton.arc.chain');
$listed = $wallets->wallets([
    'chain' => $chain,
    'walletSetId' => $reviewedWalletSetId,
]);

$created = $wallets->createWallet([
    'chain' => $chain,
    'walletSetId' => $reviewedWalletSetId,
    'accountType' => 'EOA',
    'count' => 1,
    'idempotencyKey' => $persistedWalletCreationUuid,
]);
$wallet = $created['wallets'][0];
```

REST accepts `EOA` or `SCA` and integer `count` from 1 to 200. Review deployment
and sponsorship requirements before selecting SCA. Result is
`['status' => 'created', 'wallets' => [...], 'raw' => ...]`.

REST validates developer custody, account type, chain, wallet UUID, nonzero EVM
address, and selected wallet-set membership. Listing uses bounded pagination;
lookup fails if an address is absent or ambiguous. Balance/transfer lookup uses
configured wallet set, so configure the same reviewed set before subsequent calls.
Changing driver never migrates existing Agent Stack wallets.

## Request testnet funding

```php
$funding = $wallets->fundWallet($wallet['address'], ['chain' => $chain]);
// REST returns status=requested. Poll balance separately.
```

REST funding is testnet-only and restricted to a validated wallet. Circle
eligibility/rate limits apply. Request acceptance is not receipt of funds.
REST never provides mainnet funding via this method; CLI funding has a distinct
provider-managed workflow. Fake funding immediately credits a simulated ledger.

## Operator commands

```bash
php artisan lepton:wallets --json
php artisan lepton:wallet:create --testnet --wallet-set=<reviewed-set-uuid> --account-type=EOA --idempotency-key=<persisted-uuid-v4> --json
php artisan lepton:wallet:fund <reviewed-wallet-address> --json
```

These live commands remain gated by testnet source limitation. Use explicit
`--testnet` or `--mainnet` when creating wallets; do not rely on default inference.
Never run both flags together. Treat mainnet creation as a separately authorized
operation, not a workaround for testnet errors.
