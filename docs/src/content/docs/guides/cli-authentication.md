---
title: CLI authentication
description: Use Circle CLI sessions with the Laravel wrapper and keep interactive login out of workers.
---

Use `LEPTON_DRIVER=circle` for an existing Agent Stack integration. This is not
the Developer-Controlled Wallets API-key model.

```bash
npm install -g @circle-fin/cli
```

```dotenv
LEPTON_DRIVER=circle
LEPTON_CIRCLE_BIN=circle
LEPTON_ALLOW_RAW_CIRCLE=false
LEPTON_DRY_RUN=true
```

CLI installation is operator-managed. Configure reviewed network values before
use; this checkout's testnet defaults contain literal `[REDACTED]` strings.

## Human-driven login

```bash
php artisan lepton:login --status
php artisan lepton:login operator@example.com
```

After receiving Circle's email code, complete the returned request:

```bash
php artisan lepton:login --request=<request-id> --otp=<human-provided-code>
```

Mainnet and testnet sessions authenticate independently. Verify the desired
network with `authStatus()` / `isAuthenticatedFor($chain)` before use.
`lepton:login --testnet` selects which status section gets a warning; current
command does **not** pass a network argument to `beginLogin()` or `completeLogin()`.
Do not assume this flag alone establishes a testnet session. Follow installed
Circle CLI's documented per-environment login procedure and verify status.

Circle CLI owns session storage. SDK contracts describe seven-day sessions and
ten-minute login request IDs; account/provider policy can still invalidate them.
Never request OTP inside queue workers, web requests, or settlement paths.
Protect shell history and logs containing OTPs.

## Raw commands

`lepton:circle` and facade `runCircle()` are guarded by
`LEPTON_ALLOW_RAW_CIRCLE`; keep disabled unless deliberately needed. Raw commands
can bypass higher-level application workflows. REST never falls back to them.
Prefer typed methods and application authorization over user-supplied CLI arguments.
