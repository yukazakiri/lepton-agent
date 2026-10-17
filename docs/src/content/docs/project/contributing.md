---
title: Contributing
description: Develop SDK and documentation offline, report issues safely, and keep examples faithful to contracts.
sidebar:
  order: 2
---

Lepton is community-maintained and MIT-licensed, not an official Circle product.
Use [GitHub](https://github.com/yukazakiri/lepton-agent) for source, issues, and
pull requests.

## SDK development

From repository root with PHP 8.5+, required extensions, and Composer 2:

```bash
composer validate --strict
composer install
vendor/bin/pest --compact
```

Default tests run offline. Do not introduce live credentials, faucet calls,
mutations, or notification subscriptions into tests or CI. Consumer Laravel
examples belong in a booted application; SDK checkout itself has no `artisan`.

Preserve optional capability interfaces for custom four-method wallet gateways.
New transport features need request-contract and failure-path tests, explicit
driver support, accurate state semantics, and updated docs. Test estimates
separately from submissions. Never replace unknown results with invented zero
balances or terminal failure.

## Documentation changes

See [documentation development](../documentation/) for exact commands. Check
examples against contracts, implementations, and tests—not only README or
provider marketing. Mark integration fragments and application-owned records.
Include configuration assumptions, failure handling, simulation labels, and
settlement limits. Do not describe untested live behavior as verified.

## Reporting issues

Include package/PHP/Laravel/CLI versions, selected driver/network, minimal
credential-free reproduction, expected result, actual result, and redacted
request ID where applicable. Do not include secrets, recovery files, customer
records, or public exploit details for unresolved security problems. Use a
maintainer's private security channel when available; request a safe reporting
channel without disclosing exploit details if none is published.

## Upstream references

- [Circle SDK catalog](https://developers.circle.com/sdks)
- [Developer-Controlled Wallets](https://developers.circle.com/wallets/dev-controlled)
- [Entity Secret management](https://developers.circle.com/wallets/dev-controlled/entity-secret-management)
- [Circle notifications](https://developers.circle.com/wallets/webhook-notifications)
- [Circle CLI](https://developers.circle.com/agent-stack/circle-cli)
- [Arc documentation](https://docs.arc.io)

Provider references describe upstream products. They do not expand implemented
SDK capabilities or remove local release gates.
