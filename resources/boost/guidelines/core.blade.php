## Lepton Agent SDK for Laravel

Community-maintained Laravel-first SDK for Circle wallets and Arc. PHP 8.5,
Laravel 13. Inject contracts; do not embed provider HTTP requests or CLI commands
in application code. Not an official Circle SDK or production certification.

### Contracts and capabilities

- `WalletGateway`: transfer, balance, transactions, limits (original four methods).
- `WalletManagementGateway`: wallets, createWallet, fundWallet.
- `TransferObserver`: resolve accepted provider IDs without resending.
- `TransferEstimator`: estimateTransfer (never a transfer outcome).
- `ArcNetworkGateway`: rpc, blockNumber, chainId, chainCode, treasuryAddress,
  rpcUrl, explorerUrl, addressExplorerUrl.
- `AuthGateway`: authStatus, beginLogin, completeLogin, isAuthenticatedFor.
- `WebhookVerifier`: verify raw Circle notification using signing key ID.
- `CircleGateway`, `X402Gateway`: extended driver APIs; REST throws explicit
  unsupported errors for Gateway/CCTP/x402 rather than inventing balances.
- `Amounts`: exact decimal/integer and fromHexQuantity conversions.

Use `Lepton::transfer`, transferDecimal, resolve, estimateTransfer, listWallets,
createWallet, createWalletSet, fundWallet, webhooks, auth, arc and services.

### Money and identity

USDC uses integer six-decimal base units. Arc native USDC uses 18 decimals and
exposes the same funds as ERC-20 USDC; never add both views. Never `hexdec()` a
chain quantity or use floats; `Amounts::fromHexQuantity()` uses exact strings.
Destination requires `0x` followed by exactly 40 hex digits, from reviewed records.
Never synthesize recipients. Syntax does not prove ownership.

REST mutations require a persisted UUID v4 `idempotencyKey` before I/O; reuse
original identity for recovery, never create a new payment after timeout.
Explicit wallet set and network required; no first-row custody selection.
Signing requires fresh RSA-OAEP SHA-256 Entity Secret ciphertext; operator owns
registration/recovery. Do not register or rotate secrets automatically.

### Settlement and notifications

A transaction ID or hash is a claim, not proof. Transfer may return nullable
txHash plus handle/state. `TransferObserver::resolve()` never submits.
CONFIRMED remains pending. Provider COMPLETE plus hash is provider observation,
not proof of matching business settlement. Verify receipt success, intended
network/token/sender/recipient/amount and finality. Null RPC result is unresolved,
not evidence of fabrication. Retried signing risks double payment.

Webhook verification uses X-Circle-Key-Id at /v2/notifications/publicKey/{keyId},
ECDSA_SHA_256, original raw bytes, subscription and timestamp. Never use entity
RSA encryption key for notifications. Persist unique notificationId/digest before
ACK and queue observation only. Never authorize or mark paid from a webhook.
Keep polling recovery and duplicate/out-of-order guards in consuming application.

### Drivers and testing

`LEPTON_DRIVER=circle` uses Agent Stack CLI. Mainnet and testnet authenticate
independently; OTP needs a human, never a queue/settlement path.
`developer`/`circle-rest` uses Developer-Controlled Wallet REST on Arc; API-read
access verification is not proof of signing-secret registration. Existing agent
wallets do not migrate custody by changing credentials.
`LEPTON_DRIVER=fake` needs no network, no credentials and no cost; receipts are
simulated only. Scoped bindings are Octane-safe; bind fresh fakes per test.
Unknown drivers fail closed, and HTTP failure never switches networks.

Use estimateTransfer for preflight. LEPTON_DRY_RUN blocks REST mutations and
signing. Fee estimate is not an enforced fee ceiling.
Provider `not allowed by the proxy` means method unavailable, not payment failure.

### Artisan

Registered: lepton:login (--status), lepton:status, lepton:transfer (--estimate,
--idempotency-key), lepton:wallets, lepton:wallet:create (--wallet-set,
--account-type, --idempotency-key), lepton:wallet:fund, lepton:wallet, lepton:circle.
Applications may supply their own doctor/reconciliation commands; inspect list.
