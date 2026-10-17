---
title: Troubleshooting
description: Diagnose configuration, provider, amount, notification, and observation failures without creating duplicate transfers.
sidebar:
  order: 4
---

| Symptom | Check and response |
| --- | --- |
| `Circle base URL must be HTTPS and end with /v1/w3s.` | Shipped default is literal `[REDACTED]`; set reviewed API base URL. Do not use arbitrary proxy carrying credentials. |
| `REST SDK currently supports ARC and [REDACTED] only.` | Checkout testnet identifier is redacted. Restore/validate source consistently before live testnet use; do not bypass via mainnet. |
| Key environment does not match network | Confirm `TEST_API_KEY` versus `LIVE_API_KEY`, chain and chain ID; never silently switch environment. |
| Wallet missing or ambiguous | Check developer custody, configured wallet set, chain, exact address, and provider list. Do not choose first match. |
| Dry-run blocks operation | Use explicit estimate APIs. Disabling dry-run requires authorization, not exception swallowing. |
| Invalid persisted idempotency key | Persist UUID v4 before operation. Reuse original operation identity during reviewed recovery, never mint a key inside retry loop. |
| Estimate treated as transfer | Call `estimateTransfer()` separately; REST transfer rejects `estimate=true`. |
| Amount overflow/precision rejected | Use six-decimal strings and integer units. No floats/scientific notation. |
| Native and ERC-20 balances disagree | Preserve raw observations and investigate; do not sum or choose favorable value. |
| `CONFIRMED` remains pending | Deliberate: REST success requires `COMPLETE` plus valid hash, followed by application settlement checks. |
| Resolve returns unknown | Provider unavailable, missing record, mismatched context, or unknown state. Preserve intent and investigate; do not resend. |
| `method 'x' not allowed by the proxy` | RPC provider does not expose method. Use reviewed endpoint supporting needed read on same network. Not payment failure evidence. |
| Notification rejected | Check original bytes, headers, key lookup, allowlist, UTC clock, max age, and version-2 envelope. Never skip verification to accept delivery. |
| Fake balance disappears | New process/scope has fresh in-memory ledger; seed and exercise within same test or shell. |
| Config change has no effect | Configure before service resolution; clear/rebuild Laravel config as appropriate and restart long-lived workers. |
| REST Gateway/CCTP/x402 call throws | Unsupported intentionally; CLI parity is not promised. Do not fall back to another signer. |

For provider support, retain redacted HTTP status, request ID, SDK version,
network, and timestamp. Never attach API keys, Entity Secrets, recovery files,
OTP codes, full signing inputs, or private customer records to public issues.
