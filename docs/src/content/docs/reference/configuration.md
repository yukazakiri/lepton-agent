---
title: Configuration
description: Environment variable mappings, defaults, security boundaries, and misleading legacy comments.
sidebar:
  order: 2
---

Published `config/lepton.php` is source of truth. Resolve configuration before
SDK services; restart long-lived workers after changing it. Use `config()` in
application code rather than reading environment variables directly.

| Environment variable | Config key (`lepton.` prefix) | Shipped default |
| --- | --- | --- |
| `LEPTON_DRIVER` | `default` | `fake` when `APP_ENV=testing`; otherwise `circle` |
| `LEPTON_CIRCLE_API_KEY` | `developer.api_key` | Fallback `CIRCLE_API_KEY` |
| `LEPTON_ENTITY_SECRET` | `developer.entity_secret` | Fallback `CIRCLE_ENTITY_SECRET` |
| `LEPTON_WALLET_SET_ID` | `developer.wallet_set_id` | Fallback `CIRCLE_WALLET_SET_ID` |
| `LEPTON_CIRCLE_API_URL` | `developer.base_url` | Fallback `CIRCLE_API_URL`, then literal `[REDACTED]` |
| `LEPTON_WEBHOOKS_ENABLED` | `webhooks.enabled` | `false` (consumer must enforce) |
| `LEPTON_WEBHOOK_SUBSCRIPTIONS` | `webhooks.subscriptions` | Empty comma-separated list |
| `LEPTON_WEBHOOK_MAX_AGE_SECONDS` | `webhooks.max_age_seconds` | `86400` |
| `LEPTON_CIRCLE_BIN` | `circle.bin` | `circle` |
| `LEPTON_CLI_TIMEOUT` | `circle.timeout`, `arc.timeout` | `60` seconds |
| `LEPTON_CHAIN` | `arc.chain` | Literal `[REDACTED]` |
| `LEPTON_CHAIN_ID` | `arc.chain_id` | `5042002` |
| `LEPTON_TREASURY_ADDRESS` | `arc.treasury` | Unset |
| `LEPTON_RPC_URL` | `arc.rpc_url` | Unset; HTTP Arc gateway selects public URL by supported chain |
| `LEPTON_DRY_RUN` | `dry_run` | `false` |
| `LEPTON_ALLOW_RAW_CIRCLE` | `allow_raw_commands` | `false` |
| `LEPTON_EXPLORER_TESTNET` | `explorer.testnet` | `https://testnet.arcscan.app/tx/` |
| `LEPTON_EXPLORER_MAINNET` | `explorer.mainnet` | `https://arcscan.app/tx/` |

Additional config-only settings: `developer.timeout=30`,
`webhooks.api_origin=https://api.circle.com`, `usdc_decimals=6`.
`LEPTON_ARC_BIN` maps to legacy `arc.bin`; current provider's Arc HTTP gateway
does not use it. Some shipped comments still describe binary fallback; current
HTTP read path does not perform that fallback.

:::caution[Do not deploy redacted defaults]
See [REST setup](../../guides/developer-wallets/) for literal testnet identifier
and base URL limitations. Testnet ID `5042002` and mainnet ID `5042` must match
reviewed chain configuration. Do not choose mainnet to get around testnet failure.
:::

Keep API origins trusted and secrets outside repository. `dry_run` is not a
spending policy or universal CLI/fake safeguard. `webhooks.enabled` does not
register or protect a route automatically. Read [driver behavior](../../getting-started/drivers/)
and [notification guide](../../guides/webhooks/) before enabling features.
