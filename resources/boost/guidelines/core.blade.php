## Lepton Agent for Laravel

Wraps [Circle Agent Stack](https://developers.circle.com/agent-stack) wallets and the
[Arc](https://docs.arc.io) network behind injected contracts, so application code never
hardcodes a `circle` or `arc-canteen` command.

### Contracts

Resolve these from the container. Never shell out yourself.

- `WalletGateway` — `transfer()`, `balance()`, `transactions()`, `limits()`
- `ArcNetworkGateway` — `rpc()`, `blockNumber()`, `chainId()`, `rpcUrl()`, `treasuryAddress()`, `explorerUrl()`, `addressExplorerUrl()`
- `AuthGateway` — `authStatus()`, `beginLogin()`, `completeLogin()`, `isAuthenticatedFor()`
- `X402Gateway` — `searchServices()`, `inspectService()`, `payService()`, `gatewayBalance()`

The `Lepton` facade exposes `transfer`, `transferDecimal`, `balance`, `status`,
`authStatus`, `isAuthenticated`, and accessors for each gateway.

### Non-negotiable conventions

**Amounts are integer base units, never floats.** USDC is 6 decimals, so `45.00 USDC`
is `45_000000`. Convert with `Amounts`, never `(int) (45.00 * 1000000)`.

@verbatim
<code-snippet name="Converting amounts without floats" lang="php">
use Yukazakiri\Lepton\Support\Amounts;

// "45.00" -> 45_000000
$units = Amounts::fromDecimalString('45.00');

// 45_000000 -> "45.00"
$display = Amounts::toDecimalString($units);
</code-snippet>
@endverbatim

**Never `hexdec()` a chain quantity.** Arc native USDC is 18 decimals, so 20 USDC is
2e19 wei, which exceeds `PHP_INT_MAX`. `hexdec()` returns a float there and an `(int)`
cast wraps to a negative number.

@verbatim
<code-snippet name="Reading a hex quantity safely" lang="php">
use Yukazakiri\Lepton\Support\Amounts;

$arc = app(Yukazakiri\Lepton\Contracts\ArcNetworkGateway::class);
$wei = $arc->rpc('eth_getBalance', [$address, 'latest']);

// String arithmetic. Returns "5.01", exact.
$usdc = Amounts::fromHexQuantity($wei, 18);
</code-snippet>
@endverbatim

**A returned transaction hash is a claim, not proof.** Verify it on-chain before
recording a payment as settled.

@verbatim
<code-snippet name="Proving settlement" lang="php">
$tx = app(Yukazakiri\Lepton\Contracts\ArcNetworkGateway::class)
    ->rpc('eth_getTransactionByHash', [$hash]);

$settled = is_array($tx) && $tx !== [];
</code-snippet>
@endverbatim

**Prefer a Circle agent wallet address.** Circle only signs for wallets it custodies.
An `arc-canteen` local wallet holds a key Circle cannot use, so transfers against it
fail with "no agent session is active" even when the session is valid.

**Mainnet and testnet authenticate independently.** A valid mainnet session does not
authorise an ARC-TESTNET transfer. Check the specific chain before moving money.

**`rpc()` is allowlisted.** The Arc proxy rejects some methods by design. A response of
`method 'x' not allowed by the proxy` means the method is not exposed, not that the
call is broken.

### Transferring

@verbatim
<code-snippet name="Transfer USDC" lang="php">
use Yukazakiri\Lepton\Contracts\WalletGateway;
use Yukazakiri\Lepton\Support\Amounts;

$result = app(WalletGateway::class)->transfer(
    fromAddress: '0xTreasury',
    toAddress: '0xRecipient',
    amountBaseUnits: Amounts::fromDecimalString('45.00'),
    options: ['chain' => 'ARC-TESTNET'],
);

$result->txHash;      // a claim, verify before calling it settled
$result->explorerUrl;
$result->isFake;      // true only under the fake driver
</code-snippet>
@endverbatim

Supported options: `chain`, `rpcUrl`, `token`, `idempotencyKey`, `estimate`.
`estimate` validates the fee without broadcasting. `LEPTON_DRY_RUN=true` applies it
globally.

**Before writing a transfer, validate the destination.** Circle requires `0x` followed
by exactly 40 hex characters; EIP-55 checksum casing is not required. A rejected
address is recoverable, one the recipient does not control is not. Never synthesise a
recipient from a hash or an id.

### Auth

Sessions last seven days and are owned by the Circle CLI. The package stores no
credentials.

@verbatim
<code-snippet name="Checking and driving authentication" lang="php">
$auth = app(Yukazakiri\Lepton\Contracts\AuthGateway::class);

$auth->isAuthenticatedFor('ARC-TESTNET');  // guard before transferring

$requestId = $auth->beginLogin('you@example.com');
// The OTP still comes from a human inbox.
$auth->completeLogin($requestId, 'B1X-123456');
</code-snippet>
@endverbatim

Never call `beginLogin()` or `completeLogin()` from a queue worker or a settlement
path. The OTP needs a human, so login belongs in an operator-run command or an
opt-in web flow.

### Drivers and testing

`LEPTON_DRIVER=circle` is real. `LEPTON_DRIVER=fake` is an in-memory ledger needing no
network, credentials or cost, and defaults under `APP_ENV=testing`.

@verbatim
<code-snippet name="Testing without a chain" lang="php">
config(['lepton.default' => 'fake']);

$wallets = app(Yukazakiri\Lepton\Contracts\WalletGateway::class);
$wallets->seedBalance('0xTreasury', 45_000000);

$result = $wallets->transfer('0xTreasury', '0xRecipient', 45_000000);
$result->isFake;  // true — never present this as settled

// Exercise authenticated paths.
app(Yukazakiri\Lepton\Gateways\FakeLeptonGateway::class)->fakeAuthenticated();
</code-snippet>
@endverbatim

Bindings use `scoped()`, so they are safe under Laravel Octane and do not leak request
state between requests. Fakes are stateful — register an explicit one per test rather
than relying on a shared instance.

### Commands

This package registers `lepton:login` (and `--status`), `lepton:status` and
`lepton:transfer`.

Applications built on this package often add their own diagnostics — for example a
doctor command that checks binaries, session state and the treasury address. Check the
application's `artisan list` before assuming such a command exists.

### CLI coverage

Wrapped: `wallet transfer`, `wallet balance`, `wallet limit`, `wallet status`,
`wallet login`, `transaction list`, `services *`, `gateway balance`, JSON-RPC reads.
Not wrapped: `wallet create`, `fund`, `sign`, `swap`, `execute`, `import`, `bridge`,
`contract`, `earn`, `blockchain config`. Run those through the Circle CLI directly
rather than assuming the package covers them.
