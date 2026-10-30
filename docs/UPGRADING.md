# Upgrade Guide

## Table of contents

- [Unreleased](#unreleased)
- [To 1.3.0](#to-130)
- [To 1.2.0](#to-120)
- [To 1.1.0](#to-110)
- [To 1.0.12](#to-1012)
- [To 1.0.11](#to-1011)
- [To 1.0.10](#to-1010)
- [From 1.0.8 to 1.0.9](#from-108-to-109)
- [Upgrading to 1.0.6](#upgrading-to-106)
- [Upgrading to 1.0.5](#upgrading-to-105)
- [Upgrading to 1.0.3](#upgrading-to-103)
- [Upgrading to 1.0.2](#upgrading-to-102)
- [Upgrading to 1.0.1](#upgrading-to-101)
  - [Integrators](#integrators)
  - [Bundle contributors](#bundle-contributors)
- [Upgrading to 1.0.0](#upgrading-to-100)
  - [First stable release](#first-stable-release)
  - [Requirements](#requirements)
  - [Installation](#installation)
  - [Minimal configuration](#minimal-configuration)
  - [Breaking changes](#breaking-changes)
  - [Migration steps](#migration-steps)
- [General upgrade notes](#general-upgrade-notes)
- [Getting help](#getting-help)
- [From 1.0.7 to 1.0.8](#from-107-to-108)

## Unreleased

Behaviour changes (security hardening):

- **Production with memory storage now fails at container build.** `aeat.environment: production` together with `aeat.certificate_path` (or `aeat.submission_client`) requires `hash_chain.storage: doctrine` or a persistent `hash_chain.repository`. Before, every request silently started a new hash chain.
- **`nowo:verifactu:submit-sandbox --submit` refuses non-sandbox environments.** Add `--allow-production`, pass `--nif` and `--numserie` explicitly and confirm the prompt (scripts running non-interactively are refused).
- **CIF check digit enforced.** Issuer CIFs with a wrong control character (e.g. the placeholder `B12345678`) are now reported as `validation.issuer_nif.invalid`.
- **Stricter dates and amounts.** Impossible dates (`31-02-2026`) and amounts like `1e3`, `121.005` or with more than 12 integer digits are rejected. Format amounts with `number_format($value, 2, '.', '')`.
- **PEM certificates** must contain both the private key and the matching certificate; `CertificateLoader::load()` no longer returns a `password` key.
- `twig/twig` `^3.19` is required.

## To 1.3.0

From **1.2.0**:

```bash
composer update nowo-tech/verifactu-bundle
```

No change is required: with the default configuration the table keeps its name (`verifactu_hash_chain`).

- **New `doctrine.table_prefix`.** To namespace the bundle table, set it and migrate the existing table:

  ```yaml
  nowo_verifactu:
      doctrine:
          table_prefix: app_   # verifactu_hash_chain -> app_verifactu_hash_chain
  ```

  Then rename the table (and its unique index `uniq_verifactu_hash_chain_issuer` -> `app_uniq_verifactu_hash_chain_issuer`) with a Doctrine migration (`bin/console doctrine:migrations:diff` generates it) or `ALTER TABLE verifactu_hash_chain RENAME TO app_verifactu_hash_chain`. Do not let `doctrine:schema:update` drop and recreate it: the table holds the head of the legal hash chain.
- **`hash_chain.table_prefix` is deprecated.** It never had any effect; remove it from `config/packages/nowo_verifactu.yaml` to silence the deprecation.

## To 1.2.0

From **1.1.0** — TaxBreakdown tax type emitted as DetalleDesglose/Impuesto; Symfony >=6.4.

```bash
composer update nowo-tech/verifactu-bundle
```

- **Symfony requirement raised to `^6.4 || ^7.0 || ^8.0`.** Symfony 6.0–6.3 were allowed but never tested in CI; upgrade to Symfony 6.4 LTS or later before updating the bundle.
- **`<Impuesto>` is now emitted** for each `TaxBreakdown` whose `taxType` is not empty. `taxType` must be an AEAT `ImpuestoType` code: `01` (IVA), `02` (IPSI), `03` (IGIC) or `05` (Otros). Any other value (e.g. `'IVA'`) now makes the XML fail XSD validation. Pass `''` to omit the element (AEAT then assumes IVA).

## To 1.1.0

From **1.0.12** — AEAT compliance and hardening: spec-correct Anulación hash, one DetalleDesglose per tax rate, strict SOAP success detection, chain lock, QR mode.

```bash
composer update nowo-tech/verifactu-bundle
```

- **Anulación hash input changed** to the spec-correct `IDEmisorFacturaAnulada` / `NumSerieFacturaAnulada` / `FechaExpedicionFacturaAnulada` fields (validated with the official AEAT vectors). Anulación hashes stored before this release will not pass `verify-hash-chain`.
- AEAT responses are only successful with HTTP 2xx and `EstadoRegistro=Correcto` (namespaced responses are now parsed). Before, a missing status counted as success.
- `verifactu:submit-sandbox` without `--submit` (or with `--dry-run`) no longer advances the persistent hash chain.
- Invoices with several VAT rates now emit one `DetalleDesglose` per `TaxBreakdown`.
- QR URLs in `no_verifactu` mode point to `ValidarQRNoVerifactu`.
- `storage: doctrine` without DoctrineBundle now fails at container build with a clear `LogicException`; the memory storage no longer requires Doctrine.
- The signer produces plain enveloped XML-DSig, **not XAdES** (now documented); No-Veri*Factu signing requirements are not yet met.

## To 1.0.12

From **1.0.11** — dependency refresh and repository maintenance.

```bash
composer update nowo-tech/verifactu-bundle
```

- No breaking changes. No application upgrade steps.

## To 1.0.11

From **1.0.10** — dependency updates only.

```bash
composer update nowo-tech/verifactu-bundle
```

No breaking changes. **No application upgrade steps.**

## To 1.0.10

From **1.0.9** — REQ-CS-008 Igor FrankenPHP worker audit (igor-php require-dev, igor.json, make igor).

```bash
composer update nowo-tech/verifactu-bundle
php bin/console cache:clear
```

- No application upgrade steps for require-dev Igor tooling (REQ-CS-008). Consumers do not pull `igor-php/igor-php` transitively.

This guide helps you install and upgrade versions of the **Verifactu Bundle** (`nowo-tech/verifactu-bundle`).

For a full list of changes per version, see [CHANGELOG.md](CHANGELOG.md).

## From 1.0.8 to 1.0.9

FrankenPHP worker mode hardening (kernel not reset between requests). Full audit: [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md).

- **`hash_chain.repository` is now honoured by `BillingRecordProcessor`:** the configured service id is aliased to `HashChainRepositoryInterface`. If you set this option and relied (unknowingly) on the in-memory storage, records now chain through your service.
- **In-memory storage (`hash_chain.storage: memory`, default) is request-scoped.** It is cleared at the start of every main request and on `kernel.reset`. Classic PHP-FPM behaviour is unchanged; in FrankenPHP worker mode the chain no longer survives between requests (it used to fork per worker). With `kernel.debug: false` every store logs a warning. For a legally valid chain use `hash_chain.storage: doctrine` or a persistent `hash_chain.repository`.
- **Doctrine storage** always reads the last hash from the database and detaches the `BillingRecordHashChain` entity after use: do not rely on it staying managed in your EntityManager.

No public signature removed; `InMemoryHashChainRepository` gained optional constructor arguments (`?LoggerInterface $logger = null, bool $warnNotPersistent = false`) and a `clear()` method.

```bash
composer update nowo-tech/verifactu-bundle
```

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

