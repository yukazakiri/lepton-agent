---
name: lepton-agent-settlement
description: Verify Circle and Arc payment evidence without confusing provider acceptance, hashes, notifications, or network outages with proven settlement. Use for reconciliation, recovery and audit.
---

# Lepton Agent Settlement

## Evidence, not claims

A stored transaction hash is a claim, not proof. TransferResult may contain only
provider handle plus accepted state. TransferObserver::resolve observes without
resending; CONFIRMED is pending, COMPLETE plus hash is provider observation only.

Business settlement requires successful receipt in the intended network, matching
token emitter/interface, sender, recipient, exact amount, and finality. Transaction
visibility alone does not prove execution success. Circle SCA/relayer envelopes
need movement-log checks; do not equate tx.from with the user's wallet blindly.

## Uncertainty and retries

Null, absent, timed-out, malformed, reverted or mismatched results are not proof
of fabrication. Keep unsettled/unresolved and investigate the same submission.
Never mark fabricated because RPC could not return evidence. Never resend with
a new identity because the accepted handle or hash is delayed. Persist original
UUID v4 before signing and keep write-ahead records/outbox in the application.
Provider failed state may still incur gas; do not claim zero fee unconditionally.

## Notifications

WebhookVerifier checks original raw bytes against ECDSA_SHA_256 notification key
retrieved by X-Circle-Key-Id. Entity Secret RSA public key is unrelated. Reject
missing/invalid signatures, wrong subscriptions, unacceptable timestamps or
unavailable keys. Persist unique notificationId and payload digest before ACK;
duplicates/out-of-order events only wake observation, never create a payment.
Application must retain durable inbox, bounded queued recovery and polling.

## Exact money and network

Use integer six-decimal USDC and string arithmetic for 18-decimal Arc quantities.
Native and ERC-20 USDC views are the same funds; do not sum them. Never hexdec a
quantity or multiply a float. Recipient must come from reviewed records, exactly
40 hex characters after 0x; syntax is not proof of custody. Match driver, chain,
API key environment, wallet set and token identity before any operation.

## Testing

LEPTON_DRIVER=fake needs no network, no credentials and no cost; simulated only.
Test success, reverts, mismatches, delayed/missing references, outage, duplicate
delivery, changed payload identity, and out-of-order events. Assert uncertain
results never settle or re-submit. A hash fixture alone is not a full settlement
test. Explicit testnet validation and security review still gate production.
