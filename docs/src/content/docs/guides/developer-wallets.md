---
title: Developer REST setup
description: Configure Developer-Controlled Wallets, understand Entity Secret ownership, and recognize checkout limitations.
---

## Prerequisites and current limitation

Obtain a Circle Developer API key for the intended environment. An operator must
generate and register an Entity Secret following
[Circle's Entity Secret guide](https://developers.circle.com/wallets/dev-controlled/entity-secret-management),
and secure the recovery file outside the repository. Installation does not
register, rotate, recover, or reset secrets.

:::caution[Literal redactions in this checkout]
The shipped REST default base URL and testnet chain constant are literally
`[REDACTED]`, not working provider values. The default base URL fails constructor
validation. Override `LEPTON_CIRCLE_API_URL` with a reviewed HTTPS Circle Wallets
base URL ending in `/v1/w3s`.

REST chain validation currently accepts only `ARC` and the literal `[REDACTED]`.
Setting `LEPTON_CHAIN=ARC-TESTNET` will fail. Do not treat the redaction as a
public provider chain identifier or send live testnet requests with it. A
maintainer must restore and validate the testnet identifier consistently in
source before a real testnet walkthrough is runnable. Do not switch to mainnet
to bypass this limitation.
:::

## Configuration checklist

Use your application's secrets manager or protected `.env`. These are variable
names, **not credentials to paste**:

| Variable | Required value |
| --- | --- |
| `LEPTON_DRIVER` | `developer` (`circle-rest` is alias) |
| `LEPTON_CIRCLE_API_URL` | Reviewed HTTPS API URL ending `/v1/w3s`; Circle API reference uses `https://api.circle.com/v1/w3s` |
| `LEPTON_CIRCLE_API_KEY` | Environment-specific key; `TEST_API_KEY:ID:SECRET` or `LIVE_API_KEY:ID:SECRET` format |
| `LEPTON_ENTITY_SECRET` | Registered 32-byte secret represented by 64 hexadecimal characters |
| `LEPTON_WALLET_SET_ID` | Explicit reviewed developer wallet-set UUID |
| `LEPTON_CHAIN` | Reviewed supported chain; see testnet limitation above |
| `LEPTON_CHAIN_ID` | Matching chain ID: testnet `5042002`, mainnet `5042` |
| `LEPTON_RPC_URL` | Explicit HTTPS endpoint for that network |
| `LEPTON_DRY_RUN` | `true` during read/estimate rehearsal |

The REST constructor requires HTTPS, no credentials/query/fragment in base URL,
and a `/v1/w3s` suffix. Only configure trusted provider endpoints: API requests
carry credentials. Key/network mismatches fail closed.

## Read access is not signing readiness

```php
use Yukazakiri\Lepton\Facades\Lepton;

$status = Lepton::authStatus();
$wallets = Lepton::listWallets(['chain' => config('lepton.arc.chain')]);
```

`authStatus()` verifies API read access only. It does not prove Entity Secret
registration, signing capability, account funding, or control of an arbitrary
address. REST does not support `beginLogin()` or `completeLogin()`.

SDK retrieves Circle's RSA public key and freshly encrypts Entity Secret for
each request requiring ciphertext. Entity encryption keys are distinct from
ECDSA notification verification keys.

Never commit or log credentials, put secrets in CLI flags, expose Entity Secret
to browsers, or keep recovery files in repository. Keep signing authority in
trusted server-side code. Before enabling writes, complete
[production readiness gates](../../project/production-readiness/).
