---
title: Production readiness
description: Release-candidate limits and consumer gates before testnet validation or mainnet rollout.
sidebar:
  order: 1
---

This SDK is a hardening release candidate, not certified infrastructure for
production funds. Offline regression coverage is evidence of local behavior,
not live provider or financial assurance.

## Implemented offline boundaries

- Exact USDC conversions, strict REST request fields, bounded pagination, wallet
  and network identity checks, explicit unsupported capabilities.
- PHP 8.5 RSA-OAEP SHA-256, fresh Entity Secret ciphertext, redacted HTTP errors,
  REST dry-run signing/mutation blocks.
- Dedicated notification key lookup and P-256 ECDSA raw-body verification.
- Async provider identity separated from hash; unknown distinct from failure.

## Gates still required

1. Resolve literal redacted REST base URL/testnet identifiers and review network
   configuration. Run offline suite before any live operation.
2. Obtain separate authorization for controlled testnet wallet creation, signing,
   transfer, faucet request, and actual webhook delivery. Match on-chain receipts
   against submitted intent. Use no production funds.
3. Validate consumer durable outbox/inbox, concurrency control, duplicate and
   out-of-order delivery, ambiguous request recovery, bounded observation, and
   monotonic settlement decisions.
4. Exercise secret recovery, backups/restoration, access controls, monitoring,
   rate limits, and operational escalation. Never log recovery material.
5. Complete independent security review and explicit mainnet/custody rollout
   decision. Authorize each irreversible operation through application policy.

Idempotency is not an outbox. Signature verification is not an inbox. Fee level
is not an enforceable fee ceiling. Provider failure can still cost gas. Missing
or unreadable evidence means unknown, not an accusation of fabricated payment.

## Operational monitoring

Track aged pending/unknown submissions, inbox backlog, conflicting notification
digests, provider error rates/request IDs, receipt mismatch, RPC chain mismatch,
and observation lag. Separate simulated outcomes from real reports. Keep
credential-free audit metadata and reviewed retention policies.

Stop new submissions when authorization, custody identity, network identity, or
required fee policy cannot be established. Preserve existing observation and
reconciliation records; do not resolve incidents by resubmitting payments.
