# Upgrade Guide

## Table of contents

- [Unreleased](#unreleased)
- [To 1.5.0](#to-150)
- [To 1.4.1](#to-141)
- [To 1.4.0](#to-140)
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

From **1.5.0** — minor. With Doctrine storage, add two columns (below). With `providers.issuer`, check the issuer
enforcement.

```bash
composer update nowo-tech/verifactu-bundle
```

- **Database: two nullable `environment` columns.** With `hash_chain.storage: doctrine` and/or
  `record_store.storage: doctrine`, the chain head (`verifactu_hash_chain`) and the record log
  (`verifactu_billing_record`) get `environment` (string, length 10, nullable: `sandbox` or `production`). Existing
  rows keep null, which means the configured `nowo_verifactu.aeat.environment`: chains started before 1.6 continue as
  they are. Generate the migration with `php bin/console doctrine:migrations:diff` and run
  `php bin/console doctrine:migrations:migrate`, or apply the SQL below (add your `doctrine.table_prefix` to the table
  names; skip a table you do not use):

  ```sql
  -- MySQL / MariaDB
  ALTER TABLE verifactu_hash_chain ADD environment VARCHAR(10) DEFAULT NULL;
  ALTER TABLE verifactu_billing_record ADD environment VARCHAR(10) DEFAULT NULL;

  -- PostgreSQL
  ALTER TABLE verifactu_hash_chain ADD environment VARCHAR(10) DEFAULT NULL;
  ALTER TABLE verifactu_billing_record ADD environment VARCHAR(10) DEFAULT NULL;

  -- SQLite
  ALTER TABLE verifactu_hash_chain ADD COLUMN environment VARCHAR(10) DEFAULT NULL;
  ALTER TABLE verifactu_billing_record ADD COLUMN environment VARCHAR(10) DEFAULT NULL;
  ```

- **One AEAT environment per chain.** A record is never chained onto a head of the other environment
  (`ChainEnvironmentMismatchException`). Before your first production record after testing in the sandbox with the
  same issuer, run `php bin/console nowo:verifactu:reset-chain --issuer=<NIF>` (add `--force` in non-interactive
  runs); the next record starts a new chain. `verify-chain` checks the environment of the current head unless you pass
  `--environment`.
- **Issuer enforcement (security).** With `providers.issuer` set, a record whose `issuerNif` differs from the provider's
  NIF is now refused (`validation.issuer_nif.provider_mismatch`) and never chained, signed nor submitted. Why: the
  certificate is selected by the record's NIF, so a caller-supplied NIF could otherwise make the bundle sign and submit
  with another issuer's certificate. Recommended for multi-tenant hosts: make your `IssuerProviderInterface` return
  the issuer of the current tenant (records may then leave `issuerNif` empty). Opt out only if your provider returns a
  single issuer while you send records for several NIFs:

  ```yaml
  nowo_verifactu:
      providers:
          issuer: App\Verifactu\IssuerProvider
          issuer_enforced: false
  ```

  Your `CertificateProviderInterface` then stays the only check: return a certificate only for NIFs the current user
  or tenant may issue for.
- **Environment provider values.** `AeatEnvironmentProviderInterface::getEnvironment()` returns
  `Model\AeatEnvironment`; map stored values with `AeatEnvironment::fromValue()`, which throws
  `InvalidAeatEnvironmentException` for anything but `sandbox` / `production` (never a silent fallback).
- **Stricter AEAT rules** (validations v1.2.2): IDType 07 only with country ES (and ES only with 03 or 07),
  ClaveRegimen only on IVA / IPSI / IGIC lines (IPSI: 01, 08, 11, 18, 19, 20), no E2 / E3 under ClaveRegimen 01.

- **Same XML for the same input.** The new `BillingRecord` / `InvoiceDraft` / `TaxBreakdown` arguments are optional and
  trailing; a record built as in 1.5 produces the same RegistroAlta.
- **Complete your records.** The AEAT rejects F1, F3 and R1 – R4 invoices without recipients and R1 – R5 without
  TipoRectificativa. Pass `recipients` and `rectificationType` (and `rectifiedInvoices` / `rectificationAmount`), then
  set `nowo_verifactu.validation.strict: true` so incomplete records are refused before they are chained. Pass a
  ClaveRegimen per tax line or set `nowo_verifactu.xml.default_regime_key` (the AEAT rejects IVA lines without one from
  2027-01-01).
- **New validation messages** for the new fields only; existing records validate as before unless `strict` is on.
- **Runtime environment and certificate.** Nothing changes with the static configuration. To read them from your
  database, implement `AeatEnvironmentProviderInterface` / `CertificateProviderInterface` and set
  `providers.aeat_environment` / `providers.certificate`. If you construct `SoapAeatSubmissionClient`,
  `XadesBillingRecordSigner`, `VerifactuTwigExtension`, `AeatBusinessRulesValidator`, `BillingRecordXmlGenerator` or
  `SubmitToAeatSandboxCommand` yourself, the new arguments are optional and last.
- **After `ChainTransactionLostException`** run no further statement of that unit of work on the connection: it
  autocommits on the reconnected session. Start a new transaction and redo the whole unit.

## To 1.5.0

From **1.4.1**:

```bash
composer update nowo-tech/verifactu-bundle
```

From **1.4.1** — minor, no configuration change required:

```bash
composer update nowo-tech/verifactu-bundle
```

- **Amounts are written in a canonical form.** `<CuotaTotal>`, `<ImporteTotal>`, the QR `importe` and the record
  returned by `process()` now carry two decimals (`12.3` → `12.30`, `0` → `0.00`, `-0012.5` → `-12.50`), and the
  fingerprint hashes the same string. The values are equal; only the text changes. For a record whose amounts had
  trailing zeros (or no decimals) the new Huella differs from what 1.4.1 computed for the same input. Chaining is not
  affected (the next record only needs the stored hash), and `HashChainGenerator::verifyHash()` /
  `nowo:verifactu:verify-hash` accept both forms, so records stored by 1.1 – 1.4.1 still verify (1.0.x used a float normaliser: a few of its hashes, for `-0.00` or
  amounts above 10^10, do not). If you compared hashes
  with hard-coded values in your own tests, recompute them.
- **No flush of your EntityManager.** With `hash_chain.storage: doctrine`, `process()` used to flush the manager of the
  chain entity (usually your default one), writing your pending entities as a side effect. It now writes the chain head
  with DBAL and leaves your unit of work alone. If your code relied on that flush, call `$entityManager->flush()`
  yourself. The repository no longer resets a closed EntityManager; it does not need an open one.
- **Transactions.** The chain lock now joins your transaction when one is open on the same connection (the head, and
  the record store, commit or roll back with your data). Without your transaction it commits on its own, as before.
  With DBAL 4 the nested transaction is a savepoint. With DBAL 3 without savepoints, an exception inside the lock marks
  your transaction rollback-only (your `commit()` then throws): roll back and retry.
- **Chain lock.** The Doctrine repository now creates the issuer row before the first record and reads the head once
  under the lock. Under contention the database may report a deadlock or lock wait timeout instead of forking the
  chain. Without a transaction of yours, retry `process()` (DBAL `RetryableException`). **Inside your transaction,
  roll back and retry the whole unit of work** (transaction, your writes, `process()`), never `process()` alone. On
  MySQL / MariaDB a deadlock rolls back your whole transaction on the server: the bundle then closes the connection
  (so DBAL never keeps a nesting level for a transaction that no longer exists, which in FrankenPHP worker mode would
  let later requests write without the lock) and throws `Nowo\VerifactuBundle\Exception\ChainTransactionLostException`
  wrapping the database error; do not call `rollBack()` after it. On PostgreSQL, a `lock_timeout` (SQLSTATE 55P03)
  arrives as a plain `DriverException`, not a `RetryableException`: treat it as retryable if you set one.
- **Custom lockable repositories.** If you implement `LockableHashChainRepositoryInterface`, pass the head you read
  under the lock to `$operation($head)`. Calling `$operation()` without it still runs (the processor then calls
  `getLastState()`, a plain read), but on MySQL / MariaDB (REPEATABLE READ) inside your transaction that read can be
  stale and **fork the chain**.
- **Record store on an existing chain.** When you enable `record_store` for an issuer that already has records, the
  first stored record continues from the old head; `verify-chain` accepts it and prints the hash it continues from
  (pass `--anchor=<old head>` to require it). Records before that are not in the store.
- **Generation time.** Pass an empty `generatedAt` to let the processor stamp it inside the chain lock (recommended);
  a value you pass is kept.
- **New optional table** `verifactu_billing_record` when you enable `record_store.storage: doctrine`: generate a
  migration (`doctrine:migrations:diff`) or update the schema. Nothing changes while `record_store.storage` is `none`.
- **Providers.** Without configuration the issuer and software identity still come from `nowo_verifactu.issuer`,
  `software` and `installation`. If you construct `BillingRecordProcessor` or `SoapAeatSubmissionClient` yourself, the
  new arguments (`$recordStore`, `$issuerProvider`, `$softwareIdentityProvider`; `$issuerProvider`) are optional and
  last.
- **`psr/clock`** (PSR-20 interfaces) is a new dependency.

## To 1.4.1

From **1.4.0** — fix, no configuration change:

```bash
composer update nowo-tech/verifactu-bundle
```

- Listeners on `VerifactuEvents::BEFORE_AEAT_SUBMISSION` / `AFTER_AEAT_SUBMISSION` now run when
  `process(..., submitToAeat: true)` submits a record (they never ran before). They receive
  `BeforeAeatSubmissionEvent` / `AfterAeatSubmissionEvent`. If you registered a listener on those names expecting a
  different event class, update its type hint.
- The events cannot veto the submission and carry a copy of the record; what is sent to the AEAT is unchanged.
- An exception thrown by one of your `AFTER_BILLING_RECORD_GENERATED`, `BEFORE_AEAT_SUBMISSION` or
  `AFTER_AEAT_SUBMISSION` listeners does **not** propagate from `process()` any more (for `AFTER_BILLING_RECORD_GENERATED`
  it did since 1.0.0, after the hash chain had already advanced): the record is still submitted (when requested) and
  returned, and the failure is reported in `$result['event_errors']` (list of `['event' => …, 'exception' => Throwable]`)
  and logged. Read that key if a listener does work you rely on.
- **To veto a record, use `BEFORE_BILLING_RECORD_GENERATION`**: it runs before validation and chaining, and an exception
  thrown there still propagates from `process()` with nothing chained. Throwing from the post-chaining events no longer
  stops anything.
- `AFTER_BILLING_RECORD_GENERATED` listeners now receive a **copy** of the record. If a listener wrote to
  `$event->getRecord()->xml`, `signedXml` or `hash` (which corrupted what was sent to the AEAT), that write no longer
  has any effect. Reading the record is unchanged.
- If you construct `BillingRecordProcessor` yourself, nothing changes: the new `?LoggerInterface $logger` argument is
  optional and last.

## To 1.4.0

From **1.3.0**:

```bash
composer update nowo-tech/verifactu-bundle
```

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

