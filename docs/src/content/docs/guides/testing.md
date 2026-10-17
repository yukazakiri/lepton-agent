---
title: Testing
description: Exercise application behavior with the fake, replace observation and HTTP boundaries, and understand offline test limits.
---

## Laravel feature test

Set fake driver **before** first resolving facade or contracts. This test assumes
your application's Pest suite boots Laravel:

```php
use Yukazakiri\Lepton\Facades\Lepton;
use Yukazakiri\Lepton\Gateways\FakeLeptonGateway;

it('moves simulated USDC between approved addresses', function () {
    config(['lepton.default' => 'fake']);
    $fake = app(FakeLeptonGateway::class);
    $from = '0x1111111111111111111111111111111111111111';
    $to = '0x2222222222222222222222222222222222222222';
    $fake->seedBalance($from, 5_000_000);

    $result = Lepton::transferDecimal($from, $to, '1.25');

    expect($result->isFake)->toBeTrue()
        ->and(Lepton::balance($from)->amountBaseUnits)->toBe(3_750_000)
        ->and(Lepton::balance($to)->amountBaseUnits)->toBe(1_250_000);
});
```

Create a fresh application/fake per test. SDK bindings are scoped for Octane
isolation; do not promote fake or signing gateway into a process-wide singleton.
Changing configuration after resolving services does not reconstruct them.

Fake offers `seedBalance()`, `fakeAuthenticated()`, `stubRpc()`, and `stubRun()`.
Its ledger, transaction list, immediate resolution, and permissive validation
are not a provider simulator. Never use it to prove idempotency, wallet custody,
network validation, cryptography, delayed settlement, or durable storage.

## Test unknown and pending observations

Replace `TransferObserver` in consumer tests to exercise state handling:

```php
use Yukazakiri\Lepton\Contracts\TransferObserver;
use Yukazakiri\Lepton\DTOs\TransferResolution;

app()->instance(TransferObserver::class, new class implements TransferObserver {
    public function resolve(string $handle, array $options = []): TransferResolution
    {
        return TransferResolution::unknown('Test provider unavailable.', $handle);
    }
});
```

Inject this contract into your application observer. This binding does **not**
replace facade `Lepton::resolve()`, which delegates through manager's wallet
gateway; test that path by replacing `WalletGateway` before manager resolution.

Assert unknown does not trigger a new transfer. Also test double-click approval,
concurrent jobs, crash after submission, lost provider ID, duplicate/conflicting
notifications, stale events, receipt mismatch, and out-of-order observations.

## Deterministic HTTP tests

`JsonTransport::request()` accepts method, URL, headers, optional JSON body and
returns `HttpResponse`. Bind a recording stub before resolving REST/verifier to
assert requests and serve fixtures without network. Transport has no automatic
mutation retries or redirects. See repository tests for REST pagination,
identity/network scoping, exact amounts, RSA encryption, and ECDSA verification.

## Run SDK suite

From repository root (not `docs/`):

```bash
composer install
vendor/bin/pest --compact
```

Offline pass does not validate live credentials, account eligibility, provider
availability, mainnet safety, webhook delivery, or actual finality. Real-network
validation requires separate authorization and controlled testnet funds.
