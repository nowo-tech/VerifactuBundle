# Upgrade Guide

This guide helps you install and upgrade versions of the **Verifactu Bundle** (`nowo-tech/verifactu-bundle`).

For a full list of changes per version, see [CHANGELOG.md](CHANGELOG.md).

## Table of contents

- [From 1.0.7 to 1.0.8](#from-107-to-108)

- [From 1.0.6 to 1.0.7](#from-106-to-107)
- [Upgrading to 1.0.5](#upgrading-to-105)
- [Upgrading to 1.0.3](#upgrading-to-103)
- [Upgrading to 1.0.2](#upgrading-to-102)
- [Upgrading to 1.0.1](#upgrading-to-101)
- [Upgrading to 1.0.0](#upgrading-to-100)
  - [First stable release](#first-stable-release)
  - [Requirements](#requirements)
  - [Installation](#installation)
  - [Minimal configuration](#minimal-configuration)
  - [Breaking changes](#breaking-changes)
  - [Migration steps](#migration-steps)
- [General upgrade notes](#general-upgrade-notes)
- [Getting help](#getting-help)

## Upgrading to 1.0.6

No application upgrade steps.

```bash
composer update nowo-tech/verifactu-bundle
```

## Upgrading to 1.0.5

**Demos only.** Pin Hot Reload Bundle to `^1.4` (FrankenPHP Mercure/`hot_reload`, `dev`/`test`). No public API or config schema changes.

```bash
composer update nowo-tech/verifactu-bundle
```

## Upgrading to 1.0.3

**Maintainer / CI hygiene.** No public API or config schema changes.

```bash
composer require nowo-tech/verifactu-bundle:^1.0.3
```

Integrators: no application changes.

## Upgrading to 1.0.2

**CI / maintainer tooling.** No public API or config schema changes.

```bash
composer require nowo-tech/verifactu-bundle:^1.0.2
```

Integrators: no application changes.

## Upgrading to 1.0.1

**Compliance and hardening release.** No breaking public API or config schema changes.

```bash
composer require nowo-tech/verifactu-bundle:^1.0.1
```

### Integrators

- No application code changes required for typical DI consumers.
- Default `nowo_verifactu.aeat.timeout` remains **30** seconds (`CURLOPT_TIMEOUT` / `CURLOPT_CONNECTTIMEOUT`).
- Under FrankenPHP, keep **AEAT SOAP timeout &lt; PHP `max_execution_time` &lt; Caddy `servers.timeouts.write`**. Demo defaults: **30 / 45 / 60**. See [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md#timeouts-aeat-soap--frankenphp) and [CONFIGURATION.md](CONFIGURATION.md#aeat-soap-timeout-req-runtime-001).

### Bundle contributors

- Run `make setup-hooks` once per clone (REQ-GIT-001).
- `make release-check` now requires **100%** PHP line coverage and open-PR / git-hygiene gates.

## Upgrading to 1.0.0

### First stable release

**1.0.0** is the first stable release of the Verifactu Bundle. There is no previous public Packagist version to migrate from.

### Requirements

| Component | Constraint |
| --------- | ---------- |
| PHP | `>=8.1 <8.6` |
| Symfony | `^6.0 \|\| ^7.0 \|\| ^8.0` |
| Extensions | `curl`, `dom`, `libxml`, `openssl` |

Optional for persistent hash chains: `doctrine/orm` and `doctrine/doctrine-bundle`.

### Installation

```bash
composer require nowo-tech/verifactu-bundle:^1.0
```

Register the bundle in `config/bundles.php`:

```php
<?php

return [
    // ...
    Nowo\VerifactuBundle\NowoVerifactuBundle::class => ['all' => true],
];
```

### Minimal configuration

```yaml
# config/packages/nowo_verifactu.yaml
nowo_verifactu:
    mode: verifactu
    issuer:
        nif: '89890001K'
        name: 'My Company SL'
    software:
        id: '01'
        name: 'MyApp'
        version: '1.0.0'
        manufacturer_nif: 'B12345678'
        manufacturer_name: 'Nowo.tech'
        solo_verifactu: true
    installation:
        number: '001'
    hash_chain:
        storage: memory   # use doctrine in production
    aeat:
        environment: sandbox
        validate_xsd: true
```

For production AEAT submission and Doctrine storage, see [CONFIGURATION.md](CONFIGURATION.md) and [SANDBOX.md](SANDBOX.md).

### Breaking changes

None — this is the initial public API.

### Migration steps

1. Install the package with Composer as above.
2. Add configuration under `nowo_verifactu`.
3. Process records via `BillingRecordProcessor` (or map from `InvoiceDraft` with `InvoiceToBillingRecordMapper`).
4. Clear cache: `php bin/console cache:clear`.

See [USAGE.md](USAGE.md) for API examples and [COMMANDS.md](COMMANDS.md) for CLI.

## General upgrade notes

1. Always test in a development environment first.
2. Review [CHANGELOG.md](CHANGELOG.md) for the target version.
3. Prefer Doctrine hash-chain storage before production AEAT submissions so encadenamiento survives restarts.
4. Keep AEAT certificates outside the web root; use environment variables for paths and passwords.

## Getting help

1. Check [CHANGELOG.md](CHANGELOG.md) and [USAGE.md](USAGE.md).
2. Review [SECURITY.md](SECURITY.md) for certificate and submission guidance.
3. Open an issue on [GitHub](https://github.com/nowo-tech/VerifactuBundle/issues).

## From 1.0.7 to 1.0.8

No application upgrade steps.

```bash
composer update nowo-tech/verifactu-bundle
```

