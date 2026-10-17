---
title: Installation
description: Requirements, Composer installation, Laravel discovery, and safe initial configuration.
---

## Requirements

- 64-bit **PHP 8.5+**, with `curl` and `openssl`. REST Entity Secret encryption
  uses PHP 8.5's explicit RSA-OAEP SHA-256 digest API.
- **Laravel 13** and Composer 2. This is not a framework-independent client.
- Circle CLI and Node.js only when using the `circle` driver.
- Node.js 22.22+ only for building this documentation site, not for REST SDK use.

## Add package

```bash
composer require yukazakiri/lepton-agent
php artisan vendor:publish --tag=lepton-config
```

Select a published version compatible with your application. A preview release
needs an explicit available prerelease constraint; do not lower your entire
application's Composer minimum stability to evaluate it.

Laravel discovers `LeptonServiceProvider` and the `Lepton` alias automatically.
Examples import `Yukazakiri\Lepton\Facades\Lepton` explicitly for clarity.
Configuration publishes to your application's `config/lepton.php`.

## Start safely

Set these values in your Laravel application's local `.env`, not in this SDK:

```dotenv
LEPTON_DRIVER=fake
LEPTON_DRY_RUN=true
```

Then clear cached application configuration during local development:

```bash
php artisan config:clear
```

`APP_ENV=testing` defaults to `fake`. Other environments default to **`circle`**,
not fake. Always choose a driver explicitly. The fake simulates operations even
when dry-run is enabled; dry-run is not a universal authorization boundary.

Follow [offline quickstart](../quickstart/) before supplying credentials. For
live integration, choose [REST or CLI](../drivers/) deliberately.

## Upgrade existing applications

Check PHP/container/CI versions, lockfiles, custom gateways, and adapters.
`WalletGateway` still requires only its original four methods. Observation,
estimation, and wallet management live in separate capability contracts.

REST wallet creation and transfers now reject absent persisted UUID v4 mutation
keys, invalid addresses, unsupported networks, and ambiguous wallet contexts.
Keep existing provider IDs and immutable financial records unchanged. Changing
API credentials does not migrate Agent Stack wallets to developer custody.
