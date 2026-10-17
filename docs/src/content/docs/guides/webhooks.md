---
title: Verified notifications
description: Verify raw Circle notification bytes, persist a durable inbox, and queue observation safely.
---

Use notifications to wake a transfer observer, not authorize payouts or mark
invoices paid. SDK installs **no public route, controller, queue job, or inbox**.

## Configure opt-in

```dotenv
LEPTON_WEBHOOKS_ENABLED=true
LEPTON_WEBHOOK_SUBSCRIPTIONS=<reviewed-subscription-uuid>
LEPTON_WEBHOOK_MAX_AGE_SECONDS=86400
```

Subscriptions are comma-separated exact UUIDs; avoid spaces. The verifier rejects
an empty allowlist. `enabled` is an application-consumed opt-in setting; the
verifier itself does not enforce it or register a route. Your controller must
check it. Configure API credentials for dedicated notification-key lookup even
if using another wallet driver.

## Verify original bytes

Inside your application-owned Laravel controller, before decoding or modifying
body:

```php
use Illuminate\Http\Request;
use Yukazakiri\Lepton\Contracts\WebhookVerifier;

// Controller method fragment; persistence/response handling belongs to app.
public function __invoke(Request $request, WebhookVerifier $verifier)
{
    abort_unless(config('lepton.webhooks.enabled'), 404);

    $event = $verifier->verify(
        rawPayload: $request->getContent(),
        signatureBase64: $request->header('X-Circle-Signature', ''),
        keyId: $request->header('X-Circle-Key-Id', ''),
        allowedSubscriptions: config('lepton.webhooks.subscriptions'),
    );

    // Persist verified event to durable inbox before acknowledging delivery.
    // Return a success response only after durable acceptance.
}
```

This fragment intentionally omits application persistence and responses; do not
deploy an empty successful handler. Configure a POST route and narrowly scoped
CSRF exemption if needed. Keep signature verification mandatory; do not disable
CSRF globally or expose an unsigned alternate path.

Verification retrieves `GET /v2/notifications/publicKey/{keyId}` from configured
HTTPS API origin, validates matching key ID, `ECDSA_SHA_256`, and P-256 key type,
and verifies signature over **original raw body**. Never re-encode JSON first.
Entity Secret RSA encryption key cannot verify notifications.

Verifier also checks version-2 envelope, subscription, UUID identities, UTC
ISO-8601 timestamp, body size (maximum 1 MiB), configured maximum age, and future
time skew (maximum 300 seconds). Invalid/unavailable verification fails closed.
Keep server clocks synchronized. Stale signed events require replay recovery,
not relaxed signature verification.

## Durable inbox contract

1. Persist a unique `notificationId`, `payloadDigest`, subscription, type, timestamp,
   and minimum necessary verified payload in a DB transaction.
2. A repeated ID with the same digest is a duplicate; do not create another job or
   regress payment state. A repeated ID with a different digest is a conflict;
   retain evidence and alert rather than overwriting it.
3. Commit before acknowledging delivery. A DB outage must not produce a success
   response. Classify invalid signatures separately from temporary key-lookup or
   storage outages; fail closed and use provider redelivery policy.
4. Dispatch through a durable outbox or inbox sweeper. Dispatch-after-commit alone
   leaves a crash window between commit and queue publish.
5. Worker maps notification to an existing approved submission, then calls
   `resolve()` with stored identity/network context. Handle unknown notification
   types without initiating spending. Keep bounded polling for missed delivery.

Circle's response deadline makes lengthy receipt lookup inside HTTP handler
unsafe. Queue observation outside that deadline. Authentication is not replay
storage, and a verified payload still is not financial settlement evidence.
