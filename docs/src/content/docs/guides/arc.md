---
title: Arc reads and amounts
description: Read Arc JSON-RPC and represent USDC exactly without double-counting native and ERC-20 balances.
---

## Scenario: treasury dashboard

Read chain identity, block height, and normalized wallet balance without signing:

```php
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\WalletGateway;
use Yukazakiri\Lepton\Support\Amounts;

$arc = app(ArcNetworkGateway::class);
$blockHex = $arc->blockNumber();
$blockDecimal = Amounts::fromHexQuantity($blockHex, 0);
$balance = app(WalletGateway::class)->balance($reviewedTreasury, [
    'chain' => config('lepton.arc.chain'),
]);
$display = Amounts::toDecimalString($balance->amountBaseUnits);
```

Arc reads use HTTPS JSON-RPC and verify returned chain ID against configured
network before reads. No Node.js, Circle CLI, `uv`, or separate Arc binary is
required. Configure matching `LEPTON_CHAIN`, `LEPTON_CHAIN_ID`, and
`LEPTON_RPC_URL`; see [checkout limitations](../developer-wallets/).
Testnet chain ID is `5042002`; mainnet is `5042`. No automatic CLI or alternate
network fallback occurs on failure.

## Native view and ERC-20 view are one balance

USDC is Arc's gas token. Native view uses **18 decimals**; canonical ERC-20 view
at `0x3600000000000000000000000000000000000000` uses **six decimals**.
Never add these views together.

```php
$nativeHex = $arc->rpc('eth_getBalance', [$reviewedTreasury, 'latest']);
$nativeDecimal = Amounts::fromHexQuantity($nativeHex, 18);
```

Do not cast large quantities through `hexdec()` or floats: even 20 native USDC
exceeds 64-bit integer capacity in 18-decimal units. `fromHexQuantity()` uses
string arithmetic and returns an exact decimal **string**.

REST balance normalization floors sub-micro native residuals to six decimals,
retains raw observations, and rejects disagreement between native and ERC-20
views. It does not sum duplicate representations.

## Amount boundaries

```php
Amounts::fromDecimalString('1.25'); // 1250000 (int)
Amounts::toDecimalString(1250000); // '1.25'
Amounts::toCliAmount(1000000); // '1'
```

Parsing rejects negative values, scientific notation, malformed precision,
and integer overflow. Transfers additionally require positive units. SDK USDC
boundary is six decimals; changing `usdc_decimals` is not support for arbitrary
tokens and REST rejects another scale.

## Receipts and provider restrictions

```php
$receipt = $arc->rpc('eth_getTransactionReceipt', [$storedTransactionHash]);
```

A null receipt is not proof of failure. Match network, transaction identity,
receipt success, token movement, amount, parties, and finality before settlement.
`method 'x' not allowed by the proxy` means configured provider does not expose
that method, not that payment failed. Use a reviewed provider supporting needed
reads; never silently switch networks. Explorer URLs aid operators but do not
validate receipt contents.
