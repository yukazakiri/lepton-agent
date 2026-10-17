---
title: Artisan commands
description: Command options, aliases, driver differences, and known command limitations.
sidebar:
  order: 3
---

Run commands in your Laravel application, not SDK checkout. Use
`php artisan help <command>` to inspect installed version. Examples use
placeholders; replace only with reviewed application records. No example is
permission to move mainnet funds.

| Command | Inputs and options |
| --- | --- |
| `lepton:status` | `--address` (defaults treasury), `--chain` (currently declared but not consumed) |
| `lepton:login` | Optional email, `--request`, `--otp`, `--testnet`, `--status` |
| `lepton:wallets` | `--chain`, `--type=agent` (`agent`, `local`, `all`), `--json` |
| `lepton:wallet:create` | `--type=agent` (CLI), `--account-type=EOA` (REST), `--wallet-set`, `--testnet`, `--mainnet`, `--idempotency-key`, `--json` |
| `lepton:wallet:fund` | Optional address (defaults treasury), `--chain`, `--amount`, `--token=usdc`, `--method`, `--json` |
| `lepton:transfer` | Required recipient, `--amount` decimal string, `--from` (defaults treasury), `--chain`, `--idempotency-key`, `--estimate` |
| `lepton:wallet` | `list`, `create`, `fund` dispatcher; optional address for fund |
| `lepton:circle` | Raw CLI argument list; disabled unless opted in |

## Read and estimate

```bash
php artisan lepton:login --status
php artisan lepton:wallets --json
php artisan lepton:status --address=<reviewed-wallet-address>
php artisan lepton:transfer <reviewed-recipient> --from=<reviewed-wallet-address> --amount=1.25 --estimate
```

Configure correct network before `lepton:status`: its `--chain` option currently
does not override manager configuration. `lepton:login --testnet` affects status
warning, not login transport network selection. See [CLI authentication](../../guides/cli-authentication/).

## Explicit mutations

```bash
php artisan lepton:wallet:create --testnet --wallet-set=<reviewed-set-uuid> --account-type=EOA --idempotency-key=<persisted-creation-uuid-v4> --json
php artisan lepton:wallet:fund <reviewed-wallet-address> --json
php artisan lepton:transfer <reviewed-recipient> --from=<reviewed-wallet-address> --amount=1.25 --idempotency-key=<persisted-submission-uuid-v4>
```

REST testnet commands require source limitation described in
[REST setup](../../guides/developer-wallets/) to be resolved first. REST dry-run
blocks writes; only disable after explicit authorization. Use explicit network
creation flags; current inference checks for `TESTNET` substring, which literal
redacted default does not contain. Never combine `--testnet` and `--mainnet`.

REST funding ignores CLI-style amount/method selections and only requests
canonical USDC faucet funds on validated testnet wallet. `--type` is CLI wallet
filter, not REST custody selection. JSON output for wallet commands retains
provider arrays; transfer prints provider ID/hash separately, not machine JSON.

## Aliases

- List: `lepton:wallet:list`, `lepton:wallet-list`.
- Create: `lepton:wallet-create`.
- Fund: `lepton:wallet-fund`, `lepton:fund`.
- Dispatcher recognizes `wallets` for list and `new` for create.

Fake outputs are simulated; its ledger does not persist across CLI processes.
Faucet requests and successful command exits never prove settlement.
