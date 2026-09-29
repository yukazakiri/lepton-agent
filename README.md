# Lepton Agent for Laravel

A Laravel bridge for **Lepton Agents** — Circle's Agent Stack plus the Arc network. Wrap
agent wallets, USDC transfers, on-chain reads, and x402 payments behind injected
contracts, so your application code never hardcodes a `circle` or `arc-canteen` command.

Built for the [Lepton Agents Hackathon](https://arc-node.thecanteenapp.com/).

---

## Table of contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Authentication](#authentication)
- [Wallets](#wallets)
- [Transfers](#transfers)
- [Reading the chain](#reading-the-chain)
- [Spending limits](#spending-limits)
- [x402 paid services](#x402-paid-services)
- [CLI coverage](#cli-coverage)
- [Configuration](#configuration)
- [Commands](#commands)
- [Testing](#testing)
- [Arc gotchas](#arc-gotchas)
- [Troubleshooting](#troubleshooting)
- [Further reading](#further-reading)

---

## Requirements

- PHP 8.3+, Laravel 12 or 13
- **Node.js 20.18.2+**
- [Circle CLI](https://developers.circle.com/agent-stack/circle-cli):
  `npm install -g @circle-fin/cli`
- `arc-canteen` (optional, for testnet RPC and a funded testnet wallet):
  `uv tool install arc-canteen && arc-canteen login`

> **The package does not store credentials.** Circle CLI owns the agent session;
> this package only reads and initiates it. Nothing sensitive enters your `.env`.

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

All amounts are **integer base units**. USDC is 6 decimals on the Circle side, so
`450.00 USDC` is `450_000000`. Floats are never used.

```php
use Yukazakiri\Lepton\Contracts\WalletGateway;
use Yukazakiri\Lepton\Support\Amounts;

$result = app(WalletGateway::class)->transfer(
    from: '0xTreasury',
    to: '0xRecipient',
    amountBaseUnits: Amounts::fromDecimalString('450.00'), // 450_000000
    options: ['chain' => 'ARC-TESTNET'],
);

$result->txHash;       // 0x…
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

The transfer confirms on-chain before the command returns, so **no polling loop is
needed** — but the returned hash is still a claim. Verify it before treating a payment
as settled:

```php
$onChain = app(ArcNetworkGateway::class)->rpc('eth_getTransactionByHash', [$result->txHash]);
$settled = is_array($onChain) && $onChain !== [];
```

### Balances

```php
$balance = $wallets->balance('0xTreasury');
$balance->amountBaseUnits;  // 19_970_000 == 19.97 USDC
```

---

## Reading the chain

The Arc gateway wraps `arc-canteen rpc`, which gives you JSON-RPC against Arc testnet
without needing a Circle session.

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

---

## CLI coverage

This package wraps a focused subset. Anything not listed runs through the Circle CLI
directly.

| CLI verb | Package |
| --- | --- |
| `wallet transfer` | `WalletGateway::transfer()` |
| `wallet balance` | `WalletGateway::balance()` |
| `wallet limit` | `WalletGateway::limits()` |
| `wallet status` | `AuthGateway::authStatus()` |
| `wallet login` | `AuthGateway::beginLogin()` / `completeLogin()` |
| `transaction list` | `WalletGateway::transactions()` |
| `services search` / `inspect` / `pay` | `X402Gateway` |
| `gateway balance` | `X402Gateway::gatewayBalance()` |
| JSON-RPC reads | `ArcNetworkGateway::rpc()` |
| `wallet create`, `fund`, `import`, `sign`, `swap`, `execute` | *not wrapped — use the CLI* |
| `bridge`, `contract`, `earn`, `blockchain config` | *not wrapped — use the CLI* |

---

## Configuration

```env
# circle = real Circle CLI + arc-canteen, fake = in-memory ledger
LEPTON_DRIVER=circle
LEPTON_CIRCLE_BIN=circle
LEPTON_ARC_BIN=arc-canteen
LEPTON_CHAIN=ARC-TESTNET
LEPTON_CHAIN_ID=5042002

# The address Circle can actually sign for. Prefer a Circle *agent* wallet.
LEPTON_TREASURY_ADDRESS=0x7d41e0AA33A4850E20ECa993dF6F0403d6527C6E

# Optional explicit RPC override; otherwise resolved via `arc-canteen rpc-url`
LEPTON_RPC_URL=

# Validate transfers without broadcasting (adds --estimate)
LEPTON_DRY_RUN=false
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

Bindings use `scoped()`, so they are safe under Laravel Octane and never leak request
state between requests.

---

## Arc gotchas

- **Chain IDs:** testnet `5042002` (`0x4cef52`), mainnet `5042`.
- **USDC is the gas token.** A wallet needs USDC to send anything.
- **Two USDC precisions.** Native Arc USDC is **18 decimals**; the Circle ERC-20 is
  **6 decimals**. `circle wallet balance` reports both, and the package filters to the
  configured scale rather than summing mismatched units.
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
Verify it before trusting it:

```php
$ok = is_array($arc->rpc('eth_getTransactionByHash', [$hash])) && $arc->rpc('eth_getTransactionByHash', [$hash]) !== [];
```

A stored hash is a claim, not proof.

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
