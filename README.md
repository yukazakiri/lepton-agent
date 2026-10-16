# Lepton Agent for Laravel

A Laravel bridge for **Lepton Agents** — Circle's Agent Stack plus the Arc network. Wrap
agent wallets, USDC transfers, on-chain reads, and x402 payments behind injected
contracts, so your application code never hardcodes a `circle` or `arc-canteen` command.

Built for the [Lepton Agents Hackathon](https://arc-node.thecanteenapp.com/).

> **Release status:** The `developer` / `circle-rest` driver is experimental.
> [`v2.12.0-rc.2`](https://github.com/yukazakiri/lepton-agent/releases/tag/v2.12.0-rc.2)
> updates documentation and documentation tests only. Runtime code is unchanged
> from `v2.12.0-rc.1` and `v2.11.0`; the limitations below remain unresolved.
> Do not use the REST driver for production funds. Passing mocked tests does not
> establish live API compatibility, webhook security, or settlement correctness.

---

## Table of contents

- [Requirements](#requirements--prerequisites)
- [Production readiness](#production-readiness)
- [Installation](#installation)
- [Authentication](#authentication)
- [Wallets](#wallets)
- [Transfers](#transfers)
- [Reading the chain](#reading-the-chain)
- [Spending limits](#spending-limits)
- [x402 paid services](#x402-paid-services)
- [Circle CLI operations](#circle-cli-operations)
- [CLI coverage](#cli-coverage)
- [Configuration](#configuration)
- [Commands](#commands)
- [Testing](#testing)
- [Arc gotchas](#arc-gotchas)
- [Troubleshooting](#troubleshooting)
- [Further reading](#further-reading)

---

## Requirements & Prerequisites

- Composer declares PHP `^8.3` and Laravel components `^13.0`. Check the complete
  dependency platform requirements for the version you install; Laravel 12 is not
  included in the current constraint.
- The experimental REST encryption path currently requires PHP 8.5's OpenSSL
  digest argument despite that broader PHP declaration. Older runtimes are not
  supported for that path until the compatibility gate below is resolved.
- PHP extensions: `openssl`, `mbstring`, `curl`.
- [Node.js](https://nodejs.org/en/download) **20.18.2+**, for the `circle` CLI driver.
- [Circle CLI](https://developers.circle.com/agent-stack/circle-cli):
  `npm install -g @circle-fin/cli`
- [Astral uv](https://docs.astral.sh/uv/), only when installing `arc-canteen`:
  `curl -LsSf https://astral.sh/uv/install.sh | sh`
- [`arc-canteen`](https://arc-node.thecanteenapp.com/) (optional, for testnet RPC and a funded testnet wallet):
  `uv tool install arc-canteen && arc-canteen login`

> **Credential ownership depends on the driver.** Circle CLI owns Agent Stack
> sessions. The REST driver receives an API key and Entity Secret from application
> configuration; keep them in a secrets manager or a protected, untracked `.env`.
> Never commit or log those values or recovery files. Developer-Controlled Wallets
> credentials do not grant control over existing Agent Stack wallets.

---

## Production readiness

**Status: hardening planned, not completed.** The REST driver and the consuming
application need separate validation. Registering an implementation for an
interface does not mean every operation on that interface is supported.

### Known blockers and required fixes

| Area | Current limitation | Required change before production |
| --- | --- | --- |
| API contracts | Several read requests use incorrect `/developer/` paths, and transfer/contract fee fields use an SDK-style nested object. | Validate every supported request and response against Circle's official OpenAPI schemas, including signing and fee-estimate payloads. |
| USDC accounting | Balance selection accepts unrelated six-decimal tokens and can mix native and ERC-20 scales. | Validate token identity and chain; normalize with exact arithmetic to six-decimal USDC units without double-counting. |
| Wallet scoping | Address caches are not network-scoped; failed wallet lookup can fall back to account-wide transaction history. | Bind wallet ID, address, wallet set, account type, and chain; paginate and fail closed on missing or ambiguous context. |
| Authentication | Nonempty credential strings are reported as authenticated for both networks. | Distinguish configured credentials from API-verified access and signing readiness; reject network/key mismatches. |
| Cryptography | Entity Secret encryption uses a PHP 8.5 OpenSSL API although Composer declares PHP `^8.3`. | Choose and test a vetted RSA-OAEP SHA-256 implementation for supported runtimes, or raise requirements explicitly. Never downgrade to SHA-1. |
| Transfers and recovery | A missing caller key generates a new idempotency key; `CONFIRMED` is mapped to completed. | Persist one submission identity before sending; recover ambiguous outcomes without blind resubmission; distinguish inclusion from finality. |
| HTTP and dry-run | Transport error handling differs; dry-run checks are incomplete for signing and `estimate` handling. | Enforce HTTP/JSON response validation, timeouts, secret redaction, and non-broadcast behavior across all supported methods. |
| Notifications | A signature utility alone is not authenticated webhook ingestion. | Retrieve the correct notification key, verify raw bytes, persist/deduplicate events, and queue reconciliation. |
| Unsupported capabilities | Some REST methods throw while others return empty data or a placeholder zero balance. | Publish a capability matrix and explicit unsupported errors; never invent balances or silently fall back to CLI. |
| Consumer compatibility | Adding required `WalletGateway` methods affects custom implementations. | Provide a reviewed capability-interface or major-version migration, with consumer integration tests and updated Boost skills. |

The required REST path corrections include the following. This is a remediation
reference, **not a claim that the current driver already uses these paths**:

| Operation | Documented API path |
| --- | --- |
| List wallets | `GET /v1/w3s/wallets` |
| Read wallet balances | `GET /v1/w3s/wallets/{id}/balances` |
| List transactions | `GET /v1/w3s/transactions` |
| Read a transaction | `GET /v1/w3s/transactions/{id}` |
| Create wallets | `POST /v1/w3s/developer/wallets` |
| Create a transfer | `POST /v1/w3s/developer/transactions/transfer` |

The transfer REST schema uses top-level `feeLevel` or documented custom gas
parameters, not the SDK's nested `fee.config` object. Select tokens explicitly
using the API's `tokenId` or `tokenAddress`/`blockchain` rules. An estimate informs
fee selection; it does not itself enforce an approved fee ceiling.

### Webhook and settlement boundary

Circle Wallets notification keys are separate from Entity Secret encryption keys.
Use `X-Circle-Key-Id` with `GET /v2/notifications/publicKey/{keyId}`, validate the
returned key ID and algorithm, and verify `X-Circle-Signature` over the raw body
using the documented `ECDSA_SHA_256` algorithm. Do not use `/config/entity/publicKey`
to authenticate notifications.

Required consumer behavior:

- Reject missing or invalid signatures before business processing; key-fetch
  failures must not bypass authentication.
- Validate subscription, environment, payload schema, event type, and event age
  under a policy that accounts for documented redelivery behavior.
- Persist a unique `notificationId` before acknowledging delivery, then process
  asynchronously within Circle's response-time requirements. Duplicate or
  out-of-order events must not regress payment state or create another transfer.
- Keep bounded polling/reconciliation for missed notifications. A webhook wakes
  observation of an existing payment; it does not authorize a payment.
- Verify successful on-chain receipt, intended chain, token, sender, recipient,
  exact amount, and finality before marking business settlement. Transaction IDs,
  `CONFIRMED`, and stored hashes are not sufficient evidence by themselves.
- Keep timeouts, malformed responses, and absent RPC results unresolved. Do not
  infer fabrication or create a new payment attempt from an unavailable lookup.
  A reverted transfer may still consume gas.

### Release gates

All items below remain acceptance criteria, not completed work:

- [ ] API contract tests cover real schema fixtures, errors, pagination, token
  selection, signing, estimation, and network/account boundaries.
- [ ] CI covers the declared PHP/Laravel combinations and independently checks
  fresh RSA-OAEP SHA-256 ciphertexts, secret redaction, and dry-run behavior.
- [ ] Fault tests cover provider timeouts after acceptance, 429/5xx responses,
  worker crashes, duplicate/out-of-order webhooks, and recovery without a second
  payment. Recovery retains the original persisted idempotency key.
- [ ] Separately authorized Circle testnet integration verifies wallet creation,
  funding, transfer observation, real webhook delivery, and matching settlement.
  Mock-only tests do not satisfy this gate.
- [ ] Consuming applications lock and test the hardened version, preserve pending
  payment/provider identities, and verify upgrade and backup restoration.
- [ ] Security review, credential/recovery procedures, alerts, reconciliation
  monitoring, and an explicit mainnet rollout decision are complete.

Harden a narrow scope first: wallet lifecycle, exact USDC balances, fee estimation,
transfers, observation, and authenticated notifications. Bridge, Gateway, and x402
support can remain explicitly unavailable; they are not prerequisites for safe
wallet transfers. Never migrate custody, register Entity Secrets, or move mainnet
funds as a side effect of installing or upgrading this package.

Official references:
[transfer schema](https://developers.circle.com/api-reference/wallets/developer-controlled-wallets/create-developer-transaction-transfer),
[transaction lookup](https://developers.circle.com/api-reference/wallets/developer-controlled-wallets/get-transaction),
[wallet balances](https://developers.circle.com/api-reference/wallets/developer-controlled-wallets/list-wallet-balance),
[webhook verification](https://developers.circle.com/wallets/webhook-notifications),
[Entity Secret management](https://developers.circle.com/wallets/dev-controlled/entity-secret-management).

---

## Installation

```bash
composer require yukazakiri/lepton-agent
```

The service provider and the `Lepton` facade are auto-discovered. Publish the config
if you want to change defaults in a file:

```bash
php artisan vendor:publish --tag=lepton-config
```

---

## Authentication

This section describes the `circle` CLI driver. REST credentials are separate;
the experimental REST `authStatus()` is currently a configuration check, not
proof of valid credentials or signing authority.

Agent wallets authenticate with an **email one-time password**. Sessions last **7 days**,
and **mainnet and testnet authenticate independently** — a valid mainnet session does
*not* authorise a testnet transfer.

First-time login registers the email with Circle and **provisions an agent wallet on
every supported chain** automatically. You do not need to create wallets yourself.

### Interactive

```bash
circle wallet login you@example.com
# add --testnet for the testnet session
```

Paste the emailed code when prompted. Accept the Terms of Use on first run.

### Non-interactive — recommended for agents and scripts

The OTP is the only step that needs a human. Everything around it is scriptable, so
this package wraps the split flow:

```bash
# Step 1: send the OTP and get a request ID (expires in 10 minutes, one-shot)
php artisan lepton:login you@example.com

# Step 2: supply the code from the email, e.g. B1X-123456
php artisan lepton:login --request=<request-id> --otp=B1X-123456
```

Check the current session at any time:

```bash
php artisan lepton:login --status
```

```
  Circle agent session (agent)
  ────────────────────────────────────────────────────────────
  ✓  mainnet   you@example.com    expires in 26d 6h 52m
  ✓  testnet   you@example.com    expires in 27d 2h 54m
```

### From PHP

```php
use Yukazakiri\Lepton\Contracts\AuthGateway;

$auth = app(AuthGateway::class);

$status = $auth->authStatus();
$status['testnet']['authenticated'];  // bool
$status['mainnet']['expires_in'];     // "26d 6h 52m"

// Guard an operation that needs a session.
if (! $auth->isAuthenticatedFor('ARC-TESTNET')) {
    throw new RuntimeException('Log in before transferring on testnet.');
}

// Drive the flow programmatically. The OTP still comes from a human inbox.
$requestId = $auth->beginLogin('you@example.com');
// ... later, after the code arrives ...
$auth->completeLogin($requestId, 'B1X-123456');
```

> **Never call auth from a queue worker or the settlement path.** The OTP requires a
> human, so login belongs in an operator-run command or an opt-in web flow.

---

## Wallets

Circle distinguishes two wallet types, and **the difference decides whether transfers
work**:

| Type | Key custody | Circle can sign for it? | Use |
| --- | --- | --- | --- |
| **Agent** | Circle MPC, email OTP | **Yes** | Servers, agents, anything that sends USDC |
| **Local** | Your private key | No | Development, `cast`, direct signing |

This is the single most common failure: pointing your treasury at a local wallet and
wondering why `circle wallet transfer` reports *no agent session is active*.

List the agent wallets Circle knows about for a chain:

```bash
circle wallet list --type agent --chain ARC-TESTNET
```

From PHP, use whichever address your organisation is configured with:

```php
$address = config('lepton.arc.treasury');
```

### Funding a testnet wallet

```bash
circle wallet fund --address 0xYourAgentWallet --chain ARC-TESTNET
```

On **Arc, USDC is the gas token**, so a wallet needs USDC to send anything — including
a zero-value transfer.

---

## Transfers

Transfer inputs are **integer base units**. USDC uses six-decimal units at the
package boundary, so `450.00 USDC` is `450_000000`. Legacy balance normalization
still needs the [hardening work above](#production-readiness); do not assume every
existing balance path is exact.

```php
use Yukazakiri\Lepton\Contracts\WalletGateway;
use Yukazakiri\Lepton\Support\Amounts;

$result = app(WalletGateway::class)->transfer(
    fromAddress: $treasury,
    toAddress: $recipient,
    amountBaseUnits: Amounts::fromDecimalString('450.00'), // 450_000000
    options: ['chain' => 'ARC-TESTNET'],
);

$result->txHash;       // nullable: no hash may exist when the rail first accepts
$result->handle;       // provider transaction ID, not an on-chain hash
$result->explorerUrl;  // https://testnet.arcscan.app/tx/0x…
$result->isFake;       // false under the circle driver
```

Or via the facade, which accepts a decimal string:

```php
use Yukazakiri\Lepton\Facades\Lepton;

Lepton::transferDecimal('0xTreasury', '0xRecipient', '450.00');
```

### Transfer options

| Option | Effect |
| --- | --- |
| `chain` | Chain code, defaults to `lepton.arc.chain` |
| `rpcUrl` | Explicit RPC endpoint, overriding the configured one |
| `token` | ERC-20 contract or mint address. Omit for native USDC |
| `idempotencyKey` | Reuse a key to make a retry safe |
| `estimate` | Fee estimate only; **does not broadcast** |

```php
// Estimate the fee without moving money.
$result = $wallets->transfer($from, $to, $units, ['estimate' => true]);

// Or set LEPTON_DRY_RUN=true globally.
```

Transfers may be asynchronous. Persist the provider handle and observe it with
`WalletGateway::resolve()` without resending. A hash, when available, is a claim,
not proof of settlement. A transaction lookup only establishes visibility:

```php
$onChain = app(ArcNetworkGateway::class)->rpc('eth_getTransactionByHash', [$result->txHash]);
$visible = is_array($onChain) && $onChain !== [];
```

Business settlement additionally needs successful receipt, exact movement matching,
and finality checks. A null lookup alone does not prove a failed or fabricated payment.

### Balances

```php
$balance = $wallets->balance('0xTreasury');
$balance->amountBaseUnits;  // 19_970_000 == 19.97 USDC
```

---

## Reading the chain

Recent package versions support direct HTTP JSON-RPC, with a legacy `arc-canteen`
fallback. A configured `LEPTON_RPC_URL` selects the HTTP endpoint; otherwise the
current resolver first attempts `arc-canteen rpc-url` and then a public Arc endpoint.
Chain reads do not require a Circle wallet session. Verify the selected chain and
provider rather than assuming an HTTP failure should switch networks.

```php
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;

$arc = app(ArcNetworkGateway::class);

$arc->chainCode();      // "ARC-TESTNET"
$arc->chainId();        // 5042002
$arc->blockNumber();    // "0x3d667a7"
$arc->rpcUrl();         // authenticated RPC URL
$arc->explorerUrl($hash);
$arc->addressExplorerUrl($address);

// Native Arc USDC balance, 18 decimals.
$wei = $arc->rpc('eth_getBalance', [$address, 'latest']);
$usdc = Amounts::fromHexQuantity($wei, 18);  // "5.01"
```

> **`rpc()` is allowlisted.** The Arc proxy rejects some methods by design — a response
> of `method 'trace_block' not allowed by the proxy` means the method is not exposed,
> not that the call is broken. Read-mostly methods plus `eth_sendRawTransaction` work.

> **Use `Amounts::fromHexQuantity()`, not `hexdec()`.** Arc native USDC is 18 decimals,
> so 20 USDC is 2e19 wei, which exceeds `PHP_INT_MAX`. `hexdec()` returns a float
> there and a cast silently corrupts the value.

---

## Spending limits

Agent wallets carry a spending policy, which is a **second, independent enforcement
layer** on top of your own application rules.

```php
$wallets->limits('0xAgentWallet');  // defaults to mainnet
```

```bash
circle wallet limit set --address 0xAgentWallet --chain ARC --per-tx 1000 --daily 5000
circle wallet limit budget --address 0xAgentWallet --chain ARC --output json
```

> **Mainnet only.** Circle does not expose spending limits on testnet, so
> `limits()` returns declared-only values there. Enforce caps in your own code on testnet.

---

## x402 paid services

Pay for third-party APIs per request. The recipient receives USDC, you receive the
response.

```php
use Yukazakiri\Lepton\Contracts\X402Gateway;

$services = app(X402Gateway::class);

$services->searchServices('exchange rate');
$services->inspectService('https://api.example.com/rates');

$response = $services->payService('https://api.example.com/rates', '0xPayer', [
    'maxAmount' => '0.01',  // hard ceiling; refuses to overpay
]);
```

Nanopayment balances sit in Circle Gateway:

```php
$services->gatewayBalance('0xPayer');
```

These examples target the CLI driver. The experimental REST driver does not
provide equivalent x402 payment or Gateway balance support; do not interpret a
placeholder zero from an unsupported path as a real balance.

---

## Circle CLI operations

Directly interact with Circle CLI primitives using `CircleGateway`:

```php
use Yukazakiri\Lepton\Contracts\CircleGateway;

$circle = app(CircleGateway::class);

// Wallets & lifecycle
$wallets = $circle->wallets(['chain' => 'ARC-TESTNET', 'type' => 'agent']);
$newWallet = $circle->createWallet(['testnet' => true]);
$funded = $circle->fundWallet('0xRecipient', ['chain' => 'ARC-TESTNET']);

// Signing & execution
$signedMsg = $circle->signMessage('0xTreasury', 'hello');
$signedTyped = $circle->signTypedData('0xTreasury', '{"types":{}}');
$tx = $circle->execute('0xTreasury', '0xContract', 'transfer(address,uint256)', ['0xTo', '100']);

// Gateway nanopayments
$circle->gatewayDeposit('0xTreasury', '5.0', ['method' => 'eco']);
$circle->gatewayWithdraw('0xTreasury', '5.0');

// CCTP Bridge
$fee = $circle->bridgeFee('ARC-TESTNET', 'BASE');
$bridge = $circle->bridgeTransfer('0xTreasury', 'BASE', '10.0');
$status = $circle->bridgeStatus($bridge['burnTxHash']);

// Contracts & blockchains
$contracts = $circle->contractAddress('USDC');
$query = $circle->contractQuery('0xContract', 'balanceOf(address)', ['0xTreasury']);
$blockchains = $circle->blockchains();

// Arbitrary execution (guarded by LEPTON_ALLOW_RAW_CIRCLE)
$raw = $circle->run(['blockchain', 'list']);
```

---

## CLI coverage

This table describes CLI-driver wrappers, not REST-driver parity. Unsupported
operations are not made available by selecting another driver; check the
[production-readiness limits](#production-readiness) before integrating them.

| CLI verb | Package |
| --- | --- |
| `wallet transfer` | `WalletGateway::transfer()` |
| `wallet balance` | `WalletGateway::balance()` |
| `wallet limit` | `WalletGateway::limits()` |
| `wallet status` | `AuthGateway::authStatus()` |
| `wallet login` | `AuthGateway::beginLogin()` / `completeLogin()` |
| `wallet list` | `CircleGateway::wallets()` / `Lepton::listWallets()` |
| `wallet create` | `CircleGateway::createWallet()` / `Lepton::createWallet()` |
| `wallet fund` | `CircleGateway::fundWallet()` / `Lepton::fundWallet()` |
| `wallet sign message` / `typed-data` | `CircleGateway::signMessage()` / `signTypedData()` |
| `wallet execute` | `CircleGateway::execute()` |
| `gateway deposit` / `withdraw` | `CircleGateway::gatewayDeposit()` / `gatewayWithdraw()` |
| `bridge transfer` / `status` / `get-fee` | `CircleGateway::bridgeTransfer()` / `bridgeStatus()` / `bridgeFee()` |
| `contract address` / `query` | `CircleGateway::contractAddress()` / `contractQuery()` |
| `blockchain list` | `CircleGateway::blockchains()` |
| `transaction list` | `WalletGateway::transactions()` |
| `services search` / `inspect` / `pay` | `X402Gateway` |
| `gateway balance` | `X402Gateway::gatewayBalance()` |
| JSON-RPC reads | `ArcNetworkGateway::rpc()` |
| raw commands | `CircleGateway::run()` / `Lepton::runCircle()` / `lepton:circle` |
| `wallet import`, `swap`, `earn` | *not wrapped — use the CLI directly* |

---

## Configuration

```env
# circle = real Circle CLI (agent stack), developer / circle-rest = pure HTTP REST API, fake = in-memory ledger
LEPTON_DRIVER=circle
LEPTON_CIRCLE_BIN=circle
LEPTON_ARC_BIN=arc-canteen
LEPTON_CHAIN=ARC-TESTNET
LEPTON_CHAIN_ID=5042002

# For LEPTON_DRIVER=developer or circle-rest (Circle Developer-Controlled Wallets API):
LEPTON_CIRCLE_API_KEY=
LEPTON_ENTITY_SECRET=
LEPTON_WALLET_SET_ID=
LEPTON_CIRCLE_API_URL=https://api.circle.com/v1/w3s

# Treasury must belong to the selected wallet provider, account, and network.
LEPTON_TREASURY_ADDRESS=0x7d41e0AA33A4850E20ECa993dF6F0403d6527C6E

# Optional explicit RPC override; otherwise resolved via `arc-canteen rpc-url`
LEPTON_RPC_URL=

# Validate transfers without broadcasting (adds --estimate)
LEPTON_DRY_RUN=false

# Allow raw circle commands via lepton:circle or Lepton::runCircle()
LEPTON_ALLOW_RAW_CIRCLE=false
```

`LEPTON_DRIVER` defaults to `fake` when `APP_ENV=testing`.

---

## Commands

```bash
php artisan lepton:login --status                          # session state per network
php artisan lepton:login you@example.com                    # send OTP, get request ID
php artisan lepton:login --request=<id> --otp=B1X-123456    # complete login

php artisan lepton:status --address=0xTreasury             # chain, block, balance, limits
php artisan lepton:transfer 0xRecipient --amount=1.00 --from=0xTreasury
php artisan lepton:transfer 0xRecipient --amount=1.00 --from=0xTreasury --estimate

php artisan lepton:wallets                                 # list agent wallets (--chain, --type, --json)
php artisan lepton:wallet:create --testnet                 # create agent wallet on testnet
php artisan lepton:wallet:fund 0xTreasury                  # fund wallet with tokens (faucet on testnet)

php artisan lepton:circle blockchain list                  # run Circle CLI command (requires LEPTON_ALLOW_RAW_CIRCLE=true)
```

---

## Testing

The `fake` driver needs no network, no credentials, and no cost.

```php
config(['lepton.default' => 'fake']);

// Move money with no chain access.
$wallets = app(WalletGateway::class);
$wallets->seedBalance('0xTreasury', 1_000_000_00);

// Simulate a settled transfer.
$result = $wallets->transfer('0xTreasury', '0xRecipient', 450_00);
$result->isFake;  // true

// Exercise authenticated paths.
app(FakeLeptonGateway::class)->fakeAuthenticated();
```

Bindings use `scoped()` to isolate gateway state under Laravel Octane. Bind a fresh
fake per test. The default suite also includes an Arc HTTP test that contacts a
public endpoint; a fully offline run must exclude or stub that test. The REST
tests currently mock provider responses and do not prove real API compatibility.

---

## Arc gotchas

- **Chain IDs:** testnet `5042002` (`0x4cef52`), mainnet `5042`.
- **USDC is the gas token.** A wallet needs USDC to send anything.
- **One USDC balance, two interfaces.** Native Arc USDC is **18 decimals**; the
  ERC-20 view is **6 decimals**. Both represent the same funds. Never add them
  together or select unrelated tokens merely because their decimals match.
  Current balance normalization still needs hardening before financial use.
- **Explorer:** `https://testnet.arcscan.app/tx/<hash>` for testnet, `https://arcscan.app/tx/<hash>` for mainnet.
- **Sessions are per-network.** Log in to testnet separately from mainnet.

---

## Troubleshooting

**`No local wallet matches 0x… and no agent session is active`**
The address is not a Circle agent wallet, or you are not logged in for that network.
Check with `php artisan lepton:login --status`, then `circle wallet list --type agent --chain ARC-TESTNET`.

**Transfers fail with an authentication error on testnet**
You are logged in to mainnet only. Run `php artisan lepton:login you@example.com --testnet` flow.

**`method '<x>' not allowed by the proxy`**
The Arc RPC proxy is allowlisted. That method is not exposed by design.

**Balance reads as 0 but you have funds**
You are probably pointing at an `arc-canteen` local wallet rather than your Circle agent
wallet, or querying a chain you are not authenticated for.

**A returned tx hash is not on chain**
Retain an unresolved outcome and investigate the existing provider transaction;
do not submit again blindly. A stored hash is a claim, not proof. A visible
transaction can still revert, mismatch the intended payment, or lack finality.

---

## Further reading

- Circle CLI: <https://developers.circle.com/agent-stack/circle-cli>
- CLI command reference: <https://developers.circle.com/agent-stack/circle-cli/command-reference>
- Authenticate an agent wallet: <https://developers.circle.com/agent-stack/agent-wallets/wallet-operations/authenticate>
- Transfer USDC: <https://developers.circle.com/agent-stack/agent-wallets/wallet-operations/transfer>
- Fund a wallet: <https://developers.circle.com/agent-stack/agent-wallets/wallet-operations/fund>
- Custom spending policies: <https://developers.circle.com/agent-stack/agent-wallets/wallet-operations/custom-policies>
- x402 payments: <https://developers.circle.com/agent-stack/agent-wallets/wallet-operations/pay-for-service>
- Arc docs: <https://docs.arc.io>
- Lepton Agents hackathon: <https://arc-node.thecanteenapp.com/>

---

## License

MIT.
