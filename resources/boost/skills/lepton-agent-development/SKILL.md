---
name: lepton-agent-development
description: Build Laravel 13 applications with Lepton Agent SDK on PHP 8.5 — Circle CLI or Developer-Controlled Wallet REST, exact USDC, optional gateway capabilities, verified notifications, and offline fakes.
---

# Lepton Agent SDK Development

Use when integrating Circle wallets or Arc in Laravel applications. Community SDK,
not official Circle support or a certification of financial production readiness.

## Contracts

| Contract | Methods |
| --- | --- |
| WalletGateway | transfer, balance, transactions, limits |
| WalletManagementGateway | wallets, createWallet, fundWallet |
| TransferObserver | resolve |
| TransferEstimator | estimateTransfer |
| ArcNetworkGateway | rpc, blockNumber, chainId, chainCode, rpcUrl, treasuryAddress, explorerUrl, addressExplorerUrl |
| AuthGateway | authStatus, beginLogin, completeLogin, isAuthenticatedFor |
| WebhookVerifier | verify |
| CircleGateway | extended wallet/signing/execution methods; check driver |
| X402Gateway | CLI services/gateway methods; REST explicitly unsupported |

Inject these contracts instead of writing provider calls in applications.

```php
use Yukazakiri\Lepton\Contracts\WalletGateway;
use Yukazakiri\Lepton\Contracts\WalletManagementGateway;
use Yukazakiri\Lepton\Support\Amounts;

$balance = app(WalletGateway::class)->balance($reviewedTreasury);
$wallets = app(WalletManagementGateway::class)->wallets(['chain' => 'ARC-TESTNET']);
$result = app(WalletGateway::class)->transfer($reviewedTreasury, $reviewedRecipient,
    Amounts::fromDecimalString('1.25'), ['chain' => 'ARC-TESTNET', 'idempotencyKey' => $persistedUuidV4]);
```

## Boundaries

- Six-decimal integer USDC; never floats. Native Arc 18-decimal USDC is the same
  balance as ERC-20; never double-count. Use Amounts::fromHexQuantity, not hexdec.
- REST mutations require persisted UUID v4 and explicit wallet set. Do not pick
  first wallet/set or invent destination. Address must be exactly 40 hex digits.
- REST API keys must match network; no custody or signing-authority inference.
- Gateway scopes are request-local/Octane scoped; no gateway state in singletons.
- estimateTransfer is informational, not a transfer and not an enforced fee cap.
- LEPTON_DRY_RUN blocks REST signing and mutations. No automatic write retries.
- Transfers may be asynchronous. Store nullable txHash and separate handle;
  resolve existing handle, never resend on missing response.
- COMPLETE/hash is provider observation; receipt/movement/finality validation is
  consumer responsibility. Missing chain data is unknown, never fabricated.
- Verified notifications require dedicated ECDSA signing key via key ID, raw body,
  known subscription, timestamp window. Never use entity RSA key for a webhook.
- Durable unique notificationId/digest inbox, replay handling, queue observation
  and recovery polling remain application responsibilities. Webhooks never sign.
- Signing requires explicit approval; operator registers/stores Entity Secret and
  recovery file. Never log or commit those secrets.

## Drivers

circle: CLI Agent Stack session, mainnet/testnet independent and human OTP only.
developer/circle-rest: HTTPS Developer-Controlled Wallet SDK on ARC or ARC-TESTNET;
PHP 8.5 native RSA-OAEP SHA-256, no Node/CLI. Not an agent wallet custody migration.
fake: no network, no credentials and no cost; simulated only.
CLI coverage is not REST parity. UnsupportedOperation never means zero balance.

## Commands and tests

lepton:wallets, lepton:wallet:create, lepton:wallet:fund, lepton:wallet,
lepton:login, lepton:status, lepton:transfer, lepton:circle. REST create/transfer
need --idempotency-key; create accepts --wallet-set and --account-type. Preflight
uses --estimate. Use existing test suite, fake gateway, and injected JsonTransport
for offline tests. SDK tests passing do not prove live API compatibility.
