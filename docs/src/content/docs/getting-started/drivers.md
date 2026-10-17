---
title: Choose a driver
description: Compare fake, Circle CLI, and Developer REST capabilities without assuming transport parity.
---

| Driver | Authentication | Use case |
| --- | --- | --- |
| `fake` | None | Offline application tests and demos; never real settlement |
| `circle` | Circle CLI email-OTP session | Existing Agent Stack wallets and CLI integrations |
| `developer` or `circle-rest` | Developer API key and registered Entity Secret | Developer-Controlled Wallets via HTTPS; no Node.js or CLI required |

Unknown names fail closed. No REST-to-CLI fallback occurs. Changing driver does
not move wallets, keys, funds, or custody.

## Capability boundaries

| Operation | Fake | CLI | Developer REST |
| --- | --- | --- | --- |
| List/create wallets, balances, transfer, estimate | Simulated | CLI wrapper | Supported with network/custody validation |
| Observe accepted transfers | Immediate synthetic result | CLI lookup | Read-only provider lookup |
| Create wallet set | Unsupported by manager | Unsupported by manager | Explicit creation |
| Testnet funding | Simulated 20 USDC | CLI faucet | Faucet request; eligibility applies |
| Message/EIP-712 signing, ABI execution | Synthetic | CLI wrapper | Explicit supported methods |
| Gateway, CCTP, x402 | Stub responses | CLI wrappers | `UnsupportedOperation` |
| Contract registry and contractQuery | Stubs | CLI wrappers | Unsupported; use Arc JSON-RPC for reads |
| Notification verification | Separate verifier, not faked automatically | Separate verifier | Separate verifier |

CLI wrappers depend on installed CLI version, account eligibility, and provider
support; presence of a method is not proof every chain supports it. The fake
is deliberately permissive and does not reproduce all REST validation.

REST `CircleRestGateway::capabilities()` lists supported operations. It implements
broad interfaces for interoperability but explicitly throws on unsupported methods.
Its `limits()` reports `supported=false`, `enforced=false`, and `declared_only=true`;
it does not enforce your spending policy.

## Dry-run is driver-specific

REST `LEPTON_DRY_RUN=true` blocks transfer, wallet/set creation, faucet requests,
message signing, typed signing, and non-estimate contract execution. Use
`estimateTransfer()` or `execute(..., ['estimate' => true])` explicitly.
`transfer(..., ['estimate' => true])` is not the REST estimate API.

CLI transfer behavior uses estimation under dry-run. Do not assume every other
CLI or raw operation is blocked. Fake continues simulated ledger operations.
Application authorization must guard **all** spending and signing paths.
