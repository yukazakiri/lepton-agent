---
title: PHP API
description: Contracts, facade accessors, result fields, and driver-specific boundaries.
sidebar:
  order: 1
---

All namespaces below are relative to `Yukazakiri\Lepton`. Import contracts from
`Contracts`, results from `DTOs`, facade from `Facades\Lepton`.

## Contracts and facade

| Contract | Public methods | Facade entry |
| --- | --- | --- |
| `WalletGateway` | `transfer(string $fromAddress, string $toAddress, int $amountBaseUnits, array $options = []): TransferResult`; `balance(string $address, array $options = []): BalanceResult`; `transactions(string $address, array $filters = []): array`; `limits(string $address, array $options = []): array` | `transfer()`, `transferDecimal()`, `balance()`; use `wallets()` for history/limits |
| `TransferObserver` | `resolve(string $handle, array $options = []): TransferResolution` | `resolve()` |
| `TransferEstimator` | `estimateTransfer(string $fromAddress, string $toAddress, int $amountBaseUnits, array $options = []): array` | `estimateTransfer()` |
| `WalletManagementGateway` | `wallets(array $options = []): array`; `createWallet(array $options = []): array`; `fundWallet(string $address, array $options = []): array` | `listWallets()`, `createWallet()`, `fundWallet()` |
| `AuthGateway` | `authStatus()`, `beginLogin(string $email): string`, `completeLogin(string $requestId, string $otp): array`, `isAuthenticatedFor(string $chainCode): bool` | `authStatus()`, `isAuthenticated()`, `auth()` |
| `ArcNetworkGateway` | `rpcUrl()`, `chainCode()`, `chainId()`, `blockNumber()`, `treasuryAddress()`, `explorerUrl($txHash)`, `addressExplorerUrl($address)`, `rpc($method, $params = [])` | `arc()` |
| `WebhookVerifier` | `verify(string $rawPayload, string $signatureBase64, string $keyId, array $allowedSubscriptions): VerifiedNotification` | `webhooks()` |
| `JsonTransport` | `request(string $method, string $url, array $headers = [], ?array $body = null): HttpResponse` | Injectable transport, no facade method |

`Lepton::wallets()` returns **WalletGateway**, not a list. `Lepton::listWallets()`
returns wallet arrays. `Lepton::status($address)` aggregates chain, chain ID,
RPC URL, block, balance units, limits, and authentication; it can make multiple
provider calls. It is not a settlement check.

Optional capability interfaces keep custom four-method wallet implementations
compatible. Manager checks observation/estimation support and throws
`UnsupportedOperation` if missing. Do not infer every bound interface supports
every driver operation.

## Extended Circle surface

`Lepton::circle()` exposes `CircleGateway`:

| Methods | REST behavior |
| --- | --- |
| `wallets`, `createWallet`, `fundWallet` | See wallet guide; funding testnet-only |
| `signMessage`, `signTypedData`, `execute` | Explicit signing/ABI methods |
| `blockchains` | Declared Arc network list, not provider discovery |
| `run` | Unsupported; no CLI fallback |
| `gatewayDeposit`, `gatewayWithdraw` | Unsupported |
| `bridgeTransfer`, `bridgeStatus`, `bridgeFee` | Unsupported |
| `contractAddress`, `contractQuery` | Unsupported; contract reads use Arc RPC |

`Lepton::createWalletSet(string $name, array $options = [])` checks concrete REST
driver; not part of portable wallet-management interface. `runCircle(array $args,
bool $json = true, array $options = [])` is guarded raw CLI access, not REST API.

`Lepton::services()` exposes `X402Gateway`: `searchServices($query)`,
`inspectService($url, $options)`, `payService($url, $fromAddress, $options)`, and
`gatewayBalance($address, $options)`. REST throws `UnsupportedOperation` for all
four. CLI wraps provider commands; fake returns stubbed/simulated responses.

## REST options

| Operation | Supported application options |
| --- | --- |
| Transfer | `chain`, persisted UUID v4 `idempotencyKey`, `feeLevel`, `token` (canonical USDC address or `native`) |
| Estimate transfer | `chain`, `token`; no mutation key needed |
| Resolve | `chain`, optional `address` and `walletId` context checks |
| List wallets | `chain`, optional explicit `walletSetId` |
| Create wallet | `chain`, `walletSetId`, `accountType`, `count`, persisted UUID v4 `idempotencyKey`; optional `testnet` must agree with chain |
| Create wallet set | Persisted UUID v4 `idempotencyKey`; name required, maximum 255 bytes |
| Transactions | `chain`, `state`, `txType`, `limit` |
| Execute | `chain`, `estimate`, exact string `amount`; writes require `idempotencyKey`, support `feeLevel` |

Do not carry arbitrary CLI options into REST and assume effect. For example,
`rpcUrl` does not redirect REST transaction transport, and REST `limits()` does
not enforce application budgets. REST pagination fetches bounded pages; history
`limit` slices collected records, not a server-side cost guarantee.

## Result objects

Results are readonly value objects; they are not Eloquent models or durable state.

| DTO | Fields |
| --- | --- |
| `TransferResult` | Nullable `txHash`, `handle`, `state`, `explorerUrl`; `fromAddress`, `toAddress`, integer `amountBaseUnits`, `chain`, `isFake`, `raw` |
| `TransferResolution` | `state` (`pending`, `completed`, `failed`, `unknown`), nullable `txHash`, `handle`, `reason`; `raw`; `hasHash()` |
| `BalanceResult` | `address`, integer `amountBaseUnits`, `chain`, `isFake`, `raw` |
| `TransactionRecord` | `id`, nullable `txHash`, `state`, nullable `operation`, `raw` |
| `VerifiedNotification` | `notificationId`, `subscriptionId`, `notificationType`, `notification`, `timestamp` (`DateTimeImmutable`), `payloadDigest` |
| `HttpResponse` | Integer `status`, `data`, `headers`; case-insensitive `header($name)` |

`TransactionRecord::state` preserves provider state; it is not normalized like
`TransferResolution`. A hash is not a provider ID; neither alone proves settlement.
Raw fields can contain sensitive business data. Restrict retention and logging.

## Errors

| Exception | Meaning |
| --- | --- |
| `InvalidArgumentException` | Invalid local shape/amount/address/UUID; fix request, do not blindly retry |
| `OverflowException` | Exact amount exceeds supported integer range |
| `Support\UnsupportedOperation` | Selected driver does not support operation/policy |
| `Support\ApiException` | Provider HTTP error; exposes `status` and nullable `requestId` with redacted message |
| `Support\LeptonRuntimeException` | Transport, configuration, signing, or ambiguous response problem |

`resolve()` converts many lookup failures to `unknown`; it does not always throw.
Local handle/network validation may still throw before lookup. Inspect result
state explicitly. No exception during mutation proves provider did not accept it.
