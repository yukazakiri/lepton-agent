# Lepton Agent SDK for Laravel

**Community-maintained, Laravel-first PHP SDK for Circle wallets and the Arc network.**

Lepton gives Laravel applications reusable PHP methods instead of duplicating
Circle CLI commands, REST requests, authentication, and notification-signature
verification in every project. Use injected contracts, a facade, Artisan commands,
structured results, and offline fakes behind one application-facing API.

Circle's [official SDK catalog](https://developers.circle.com/sdks) lists Node.js
and Python server SDKs for Wallets, not PHP/Laravel. This SDK addresses that
integration gap; it is not an official Circle product or a claim to be the first
community PHP integration.

> **Release status:** SDK hardening release candidate for PHP 8.5 and Laravel 13.
> REST contract, cryptographic, and failure-path tests run offline. Live Circle
> wallet creation, transfers, and webhook delivery still need separately
> authorized testnet validation. Do not treat a passing suite as certification
> for production funds. See [production readiness](#production-readiness).

## Documentation

Full Astro Starlight documentation lives in [`docs/`](docs/README.md): offline
quickstart, driver setup, wallet lifecycle, reliable payments, verified
notifications, PHP/Artisan reference, and production-readiness gates.

```bash
cd docs
npm ci
npm run dev
```

Requires Node.js 22.22+. Default local path is `/lepton-agent/`. Static hosting
and manual GitHub Pages deployment instructions are in the
[documentation guide](docs/src/content/docs/project/documentation.md).

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Drivers and capabilities](#drivers-and-capabilities)
- [Configuration](#configuration)
- [PHP SDK usage](#php-sdk-usage)
- [Authentication](#authentication)
- [Verified notifications](#verified-notifications)
- [Artisan commands](#artisan-commands)
- [Testing](#testing)
- [Upgrade notes](#upgrade-notes)
- [Production readiness](#production-readiness)
- [Further reading](#further-reading)

## Requirements

- **PHP 8.5+**, 64-bit, with `curl` and `openssl`. RSA-OAEP SHA-256 uses PHP 8.5's
  explicit digest API; PHP 8.4 is no longer supported.
- **Laravel 13**. This is Laravel-first, not a framework-independent PHP client.
- Composer 2.
- For `LEPTON_DRIVER=circle`: Node.js and the
  [Circle CLI](https://developers.circle.com/agent-stack/circle-cli), installed with
  `npm install -g @circle-fin/cli`.
- For `developer` / `circle-rest`: a Circle Developer API key, an operator-registered
  Entity Secret, and an explicitly selected wallet set. No Circle CLI or Node.js
  is needed for this driver.
- Arc reads use HTTPS JSON-RPC; `uv` and `arc-canteen` are not required.

## Installation

Install a version whose requirements and release notes match your application:

```bash
composer require yukazakiri/lepton-agent
php artisan vendor:publish --tag=lepton-config
```

Laravel auto-discovers the provider and `Lepton` facade. Preview versions require
an explicit prerelease constraint. Do not change the entire application's minimum
stability merely to evaluate this SDK.

## Drivers and capabilities

| Driver | Transport / authentication | Scope |
| --- | --- | --- |
| `fake` | In-memory ledger; no credentials | Offline tests and rehearsals; never real settlement |
| `circle` | Circle CLI and its email-OTP session | Existing Agent Stack integration and CLI wrappers |
| `developer` / `circle-rest` | HTTPS REST, API key, registered Entity Secret | Developer-Controlled Wallets on `ARC-TESTNET` or `ARC` |

Unknown driver names fail closed instead of selecting a live signer.

| Operation | Developer REST driver |
| --- | --- |
| Wallet listing, creation, explicit wallet-set creation | Supported, network/custody/set validation and bounded pagination |
| USDC balances | Canonical Arc token or native view, exact six-decimal base units, no double-counting |
| Transfer and fee estimate | Separate methods; transfer requires persisted UUID v4 idempotency key |
| Transfer observation and transaction listing | Read-only; `CONFIRMED` remains pending, missing/unavailable results remain unknown |
| Message and EIP-712 signing, ABI execution | Explicit methods; signing and mutations blocked under dry-run |
| Faucet funding | Testnet request only; Circle account eligibility/rate limits apply, not guaranteed funding |
| Notification verification | Dedicated ECDSA signing-key lookup and raw-body verification |
| Gateway, CCTP, x402, contract registry | Not implemented by REST driver; throws `UnsupportedOperation`, never invented zero balances |

`CircleRestGateway::capabilities()` lists its supported operations. CLI coverage
does not imply REST parity. SDK support is intentionally bounded; applications
remain responsible for authorization, durable payment/inbox records, and settlement.

## Configuration

```env
LEPTON_DRIVER=fake
LEPTON_CHAIN=ARC-TESTNET
LEPTON_CHAIN_ID=5042002
LEPTON_TREASURY_ADDRESS=
LEPTON_RPC_URL=https://rpc.testnet.arc.io
LEPTON_DRY_RUN=true

# CLI driver only
LEPTON_CIRCLE_BIN=circle
LEPTON_ALLOW_RAW_CIRCLE=false

# Developer-Controlled Wallets only
LEPTON_CIRCLE_API_KEY=
LEPTON_ENTITY_SECRET=
LEPTON_WALLET_SET_ID=
LEPTON_CIRCLE_API_URL=https://api.circle.com/v1/w3s

# Consumer-controlled notification opt-in
LEPTON_WEBHOOKS_ENABLED=false
LEPTON_WEBHOOK_SUBSCRIPTIONS=
LEPTON_WEBHOOK_MAX_AGE_SECONDS=86400
```

The default driver is `fake` in `APP_ENV=testing`; otherwise it remains `circle`.
`TEST_API_KEY:ID:SECRET` and `LIVE_API_KEY:ID:SECRET` target different environments.
The REST driver refuses key/network mismatches. Its `authStatus()` verifies API
read access, not the correctness of the registered signing secret or custody of
an arbitrary address.

Keep API keys and Entity Secrets in protected configuration or a secrets manager.
Never commit or log them, send them as CLI flags, or store recovery files in the
repository. The operator must generate/register the Entity Secret and secure its
recovery file; SDK installation never registers, rotates, or resets it.

Mainnet uses `LEPTON_CHAIN=ARC` and chain ID `5042`. A mainnet rollout and each
irreversible operation require your application's explicit authority. Existing
Agent Stack wallets do not become Developer-Controlled Wallets by changing a key.

## PHP SDK usage

### Contracts and facade

| Contract | Purpose |
| --- | --- |
| `WalletGateway` | `transfer`, `balance`, `transactions`, `limits`; compatible with older four-method implementations |
| `WalletManagementGateway` | `wallets`, `createWallet`, `fundWallet` |
| `TransferObserver` | `resolve` accepted provider transaction IDs without resending |
| `TransferEstimator` | `estimateTransfer`, never a transfer result |
| `ArcNetworkGateway` | RPC, network identity, explorer links, native observations |
| `AuthGateway` | Provider authentication state; OTP only on CLI driver |
| `WebhookVerifier` | Verify Circle Wallets notifications independently of encryption keys |
| `JsonTransport` | Injectable HTTPS JSON transport for deterministic tests |
| `CircleGateway`, `X402Gateway` | Extended integration surface; consult driver capability matrix |

```php
use Yukazakiri\Lepton\Contracts\WalletGateway;
use Yukazakiri\Lepton\Facades\Lepton;
use Yukazakiri\Lepton\Support\Amounts;

// These addresses must come from reviewed application records.
$units = Amounts::fromDecimalString('1.25'); // 1_250000
$balance = app(WalletGateway::class)->balance($treasury);
$estimate = Lepton::estimateTransfer($treasury, $recipient, $units);

// Persist this UUID v4 with the approved intent BEFORE any network mutation.
$result = Lepton::transfer($treasury, $recipient, $units, [
    'chain' => 'ARC-TESTNET',
    'idempotencyKey' => $persistedSubmissionUuid,
]);

$result->handle;      // provider ID, not an on-chain hash
$result->txHash;      // nullable until the provider publishes one
$result->isFake;      // fake results are simulated only

$resolution = Lepton::resolve($result->handle, [
    'chain' => 'ARC-TESTNET', 'address' => $treasury,
]);
```

`Lepton::transferDecimal()` also accepts exact decimal strings. Integer conversion
rejects overflow and malformed precision; never multiply floats to construct units.
Circle requires EVM addresses with `0x` plus exactly **40 hex** characters. Syntax
validation is not proof the recipient controls the address.

REST mutations require an explicit persisted UUID v4. An absent key does not
generate a fresh identity for you. Timeouts/malformed results are ambiguous; do
not create another transfer. Observe the existing provider ID and reconcile the
original intent. SDK HTTP transport follows no redirects and automatically retries
no mutations. Ciphertext is freshly encrypted for each request that requires it.

### Wallet lifecycle

```php
use Yukazakiri\Lepton\Contracts\WalletManagementGateway;

$wallets = app(WalletManagementGateway::class);
$listed = $wallets->wallets(['chain' => 'ARC-TESTNET']);
$created = $wallets->createWallet([
    'chain' => 'ARC-TESTNET',
    'walletSetId' => $reviewedWalletSetId,
    'accountType' => 'EOA', // or SCA after reviewing sponsorship requirements
    'idempotencyKey' => $persistedCreationUuid,
]);
```

REST wallet-set creation is explicit through `Lepton::createWalletSet($name,
$options)`. No “pick the first set” or automatic custody migration occurs. Testnet
`fundWallet()` returns `status=requested`, not proof of funds received.

### Arc chain reads and exact balances

```php
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Support\Amounts;

$arc = app(ArcNetworkGateway::class);
$block = $arc->blockNumber();
$wei = $arc->rpc('eth_getBalance', [$treasury, 'latest']);
$decimal = Amounts::fromHexQuantity($wei, 18);
```

USDC is the **gas token** on Arc. Native view uses **18 decimals**; canonical
ERC-20 view at `0x3600000000000000000000000000000000000000` uses six. They expose
one balance: never add the two views. SDK balance results floor native sub-micro
residuals to six decimals and retain raw observations. Mismatched observations fail
closed. Never use `hexdec()` for quantities above `PHP_INT_MAX`.

RPC endpoints are explicit HTTPS endpoints. No automatic CLI/network fallback
occurs after failure. If a configured provider responds
`method 'x' not allowed by the proxy`, that provider does not expose the method; it is not evidence that a
payment failed. Testnet chain ID is `5042002`; mainnet is `5042`.

## Authentication

For `LEPTON_DRIVER=circle`, mainnet and testnet authenticate **independently**:

```bash
php artisan lepton:login --status
php artisan lepton:login operator@example.com
php artisan lepton:login --request=<request-id> --otp=<human-provided-code>
```

CLI owns the session. Never initiate OTP login inside a worker or settlement
path. REST does not implement email OTP; use configured API credentials and the
operator's registered Entity Secret instead. No credentials are embedded by SDK.

## Verified notifications

```php
use Yukazakiri\Lepton\Contracts\WebhookVerifier;

$event = app(WebhookVerifier::class)->verify(
    rawPayload: $request->getContent(),
    signatureBase64: $request->header('X-Circle-Signature', ''),
    keyId: $request->header('X-Circle-Key-Id', ''),
    allowedSubscriptions: config('lepton.webhooks.subscriptions'),
);
```

The verifier retrieves `GET /v2/notifications/publicKey/{keyId}`, checks ID,
`ECDSA_SHA_256` and P-256 key type, and verifies **original raw bytes**. It validates
the envelope, UTC timestamp, configured acceptance window, and subscription.
Missing/invalid signatures or unavailable keys fail closed. Entity Secret RSA
encryption keys are never used as notification keys.

Consumers must persist a unique `notificationId` with `payloadDigest` before
acknowledging delivery, then queue observation outside Circle's response deadline.
Handle duplicate/out-of-order events without regressing state or resubmitting
payments. Keep bounded polling for missed events. Configure the age window for
your redelivery policy; stale valid events require recovery, not relaxed signature
checks. The SDK does not install a public route or choose a financial database.

**A verified notification or hash is a claim, not proof of business settlement.**
Check successful receipt, matching network/token/sender/recipient/exact amount,
and finality. `CONFIRMED` stays pending; absent/unreachable lookups stay unknown.
Provider failure can still incur gas. The SDK never marks an invoice paid.

## Artisan commands

```bash
php artisan lepton:status --address=0x...
php artisan lepton:wallets --chain=ARC-TESTNET --json
php artisan lepton:wallet:create --testnet --wallet-set=<uuid> --account-type=EOA --idempotency-key=<persisted-uuid-v4>
php artisan lepton:wallet:fund 0x... --chain=ARC-TESTNET
php artisan lepton:transfer 0x... --from=0x... --amount=1.25 --estimate
php artisan lepton:transfer 0x... --from=0x... --amount=1.25 --idempotency-key=<persisted-uuid-v4>
```

`lepton:wallet` dispatches list/create/fund. `lepton:circle` and
`Lepton::runCircle()` require `LEPTON_ALLOW_RAW_CIRCLE=true` and apply only to the
CLI driver. Fake outputs must be labeled simulated; faucet requests are not
settlement. Do not copy placeholder addresses into a live transfer.

## Testing

The `fake` driver needs **no network, no credentials, and no cost**:

```php
config(['lepton.default' => 'fake']);
$fake = app(Yukazakiri\Lepton\Gateways\FakeLeptonGateway::class);
$fake->seedBalance('0xTreasury', 5_000000);
$result = Lepton::transfer('0xTreasury', '0xRecipient', 1_000000);
expect($result->isFake)->toBeTrue();
```

Bind a fresh fake per test. Gateways use scoped bindings for Octane isolation.
`JsonTransport` can be replaced with deterministic responses; default tests make
no live requests. REST request, pagination, identity, state, money, ciphertext,
ECDSA verification, and failure paths are covered by tests. Offline pass does not
prove provider availability or permission for live operations.

```bash
composer install
vendor/bin/pest --compact
```

## Upgrade notes

This release requires PHP 8.5. Review consumer CI, container, Composer lock, custom
gateways, and application adapters before upgrading. `WalletGateway` retains its
original four required methods; async observation and wallet management are
optional capability interfaces. Built-in drivers expose those capabilities.

REST callers must now supply persisted UUID v4 mutation keys, explicit wallet sets,
supported network, and exact reviewed addresses. Old invalid requests now fail.
Raw CLI passthrough, Gateway and x402 operations never fall back from REST to CLI.
Keep existing provider IDs and immutable financial records unchanged during upgrades.

## Production readiness

Implemented hardening is not certification. Before production use:

- [x] Public facade transfer, optional capabilities, strict REST paths and feeLevel
  fields, bounded pagination, wallet/network scoping, explicit unsupported errors.
- [x] PHP 8.5 RSA-OAEP SHA-256, fresh ciphertext, redacted HTTP errors, exact USDC
  conversion, dry-run signing checks, verified notification key lookup and ECDSA.
- [x] Offline SDK regression tests and CI. Mock-only tests do not satisfy live gates.
- [ ] Separately authorized Circle testnet wallet creation, signing, transfers,
  faucet eligibility, webhook delivery, and matched on-chain settlement.
- [ ] Consumer durable inbox/outbox, replay recovery, rate limits, observed
  settlement, secret recovery, backup restoration, and monitoring validated.
- [ ] Independent security review and explicit mainnet/custody rollout decision.

Idempotency is not an application outbox. Signature verification is not a durable
inbox. A fee estimate/level does not enforce a payer fee ceiling. The driver
refuses unsupported ceiling options; consumers requiring bounded fees must keep
execution gated until a reviewed transport actually enforces them. Failed, absent,
or unreadable evidence must not be called fabricated.

## Further reading

- [Circle official SDK catalog](https://developers.circle.com/sdks)
- [Circle Developer-Controlled Wallets](https://developers.circle.com/wallets/dev-controlled)
- [REST transfer schema](https://developers.circle.com/api-reference/wallets/developer-controlled-wallets/create-developer-transaction-transfer)
- [REST transaction lookup](https://developers.circle.com/api-reference/wallets/developer-controlled-wallets/get-transaction)
- [Webhook verification](https://developers.circle.com/wallets/webhook-notifications)
- [Entity Secret management](https://developers.circle.com/wallets/dev-controlled/entity-secret-management)
- [Circle CLI](https://developers.circle.com/agent-stack/circle-cli)
- [Agent authentication](https://developers.circle.com/agent-stack/agent-wallets/wallet-operations/authenticate)
- [Agent transfers](https://developers.circle.com/agent-stack/agent-wallets/wallet-operations/transfer)
- [Arc documentation](https://docs.arc.io)
- [Lepton Agents Hackathon](https://arc-node.thecanteenapp.com/)

## License

[MIT](LICENSE).
