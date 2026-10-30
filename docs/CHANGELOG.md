# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.3.0] - 2026-10-30](#130---2026-10-30)
- [[1.2.0] - 2026-10-13](#120---2026-10-13)
- [[1.1.0] - 2026-10-13](#110---2026-10-13)
- [[1.0.12] - 2026-10-13](#1012---2026-10-13)
- [[1.0.11] - 2026-10-09](#1011---2026-10-09)
- [[1.0.10] - 2026-09-27](#1010---2026-09-27)
- [[1.0.9] - 2026-09-25](#109---2026-09-25)
- [[1.0.8] - 2026-09-03](#108---2026-09-03)
- [[1.0.7] - 2026-08-24](#107---2026-08-24)
- [[1.0.6] - 2026-08-19](#106---2026-08-19)
- [[1.0.5] - 2026-08-18](#105---2026-08-18)
- [[1.0.4] - 2026-08-04](#104---2026-08-04)
- [[1.0.3] - 2026-08-03](#103---2026-08-03)
- [[1.0.2] - 2026-07-31](#102---2026-07-31)
- [[1.0.1] - 2026-07-31](#101---2026-07-31)
- [[1.0.0] - 2026-07-18](#100---2026-07-18)

## [Unreleased]

## [1.3.0] - 2026-10-30

doctrine.table_prefix (TablePrefixListener), SECURITY.md threat model, SensitiveParameter on certificate passwords, PHP 8.5 curl_close deprecation See [UPGRADING](UPGRADING.md#to-130).

### Added

- **`doctrine.table_prefix` (REQ-ORM-002).** New option `nowo_verifactu.doctrine.table_prefix` (default `''`, letters / digits / underscores only). A Doctrine `loadClassMetadata` listener (`Nowo\VerifactuBundle\Doctrine\TablePrefixListener`) prepends it to the `verifactu_hash_chain` table, its unique constraint and any many-to-many join table of bundle entities (e.g. `app_` yields `app_verifactu_hash_chain`). Host and third-party entities are never touched; the default keeps the historical name.

### Security

- Certificate passwords are `#[\SensitiveParameter]` in `CertificateLoader::load()` and `CurlSoapTransport::send()` (redacted from stack traces).

### Fixed

- `CurlSoapTransport` no longer calls `curl_close()` (no effect since PHP 8.0, deprecated in PHP 8.5).

### Deprecated

- `nowo_verifactu.hash_chain.table_prefix` was never applied; setting it now triggers a deprecation pointing to `doctrine.table_prefix`. It is still accepted and still has no effect.

### Changed

- Developer tooling: Rector targets PHP 8.2, the declared `php` floor (REQ-CS-003, REQ-SF-001).
### Added

- Developer tooling: `make check-no-raw-html-form` fails on raw `<form>` / `<input>` / `<textarea>` / `<select>` in bundle views and demo templates, and runs in `release-check` (REQ-TWIG-005).

### Changed

- Demo: the invoice generator renders through a Symfony Form Type (`App\Form\InvoiceType`; flat field names unchanged, CSRF token id `demo_invoice`) with `form_start` / `form_row` / child loop / `form_end`; the controller validates with `handleRequest` + `isValid()`; the demo now requires `symfony/form` and `symfony/security-csrf` (REQ-TWIG-005).
- Developer tooling: `make release-check` runs the full REQ-MAKE-002 chain in order (`ensure-up` first, then `check-no-cursor-coauthor`, `check-open-prs`, `composer-sync`, `cs-fix`, `cs-check`, `rector-dry`, `phpstan`, `igor`, `test-coverage-100`, demo release-check); `check-open-prs` also fails on conflicted pull requests (REQ-REL-003).
- Dependencies refreshed (Composer and pnpm/npm lock files of the bundle and its demos, campaign 2026-10-19).
- `.php-cs-fixer.dist.php`: `new ParallelConfig(2)` (REQ-CS-001).

### Documentation

- PHPDoc descriptions on every public method in `src/` (REQ-CS-001).
- `docs/SECURITY.md` restructured as a security analysis: scope, attack surface, threat model (threat → mitigation), secrets and cryptography, logging policy, dependency policy, exposure (REQ-SEC-001).
- `docs/CONFIGURATION.md` documents `doctrine.table_prefix` with the resulting table names.
- Documentation: wave-1 REQ compliance — README documentation list order (canonical labels, PSR under Additional documentation), Symfony badge lists 7.4 explicitly and GitHub stars image uses the package name, canonical issue templates, demo README links DEMO-FRANKENPHP, per-variable comments in the demo `.env.example`, CONTRIBUTING Cursor Agent settings, TOCs for long docs, Spec Kit inventory refresh (55/55, LockableHashChainRepositoryInterface).

## [1.2.0] - 2026-10-13

### Fixed

- `TaxBreakdown::$taxType` is now emitted as `<Impuesto>` (first child of `DetalleDesglose`, as required by the AEAT `DetalleType` sequence) when it is not empty. Previously it was ignored, so IGIC (`03`), IPSI (`02`) and other (`05`) lines were sent without their tax type and AEAT assumed IVA. Generated XML with `Impuesto` is XSD-validated against the bundled `SuministroInformacion.xsd` in tests.

### Changed

- Requirements: all `symfony/*` constraints are now `^6.4 || ^7.0 || ^8.0` (was `^6.0 || ...`) so the lowest allowed version is the one tested in CI. README badge and INSTALLATION aligned (PHP requirement in INSTALLATION corrected to 8.2+).

## [1.1.0] - 2026-10-13

### Security

- `nowo:verifactu:submit-sandbox` without `--submit` (or with `--dry-run`) no longer stores the generated record as the new hash chain head, so smoke tests cannot rewrite the real persistent chain. `BillingRecordProcessor::process()` gains a `persistChainState` argument (default `true`).
- AEAT SOAP responses are only reported as successful when the HTTP status is 2xx and `EstadoRegistro` is `Correcto`. A missing `EstadoRegistro` (e.g. HTML error pages) or a non-2xx status is now a failure; namespace-prefixed AEAT responses (`<tikR:EstadoRegistro>`) are parsed and `DescripcionErrorRegistro` is reported.
- The chain head read-then-write is now serialized per issuer NIF: new `LockableHashChainRepositoryInterface`; the Doctrine repository runs it in a transaction with `SELECT … FOR UPDATE`, preventing forked chains under concurrent requests.

### Fixed

- Apps without `doctrine/doctrine-bundle` no longer fail at container build: the extension checks `hash_chain.storage` first and probes `ServiceEntityRepository` instead of autoloading the Doctrine repository. `storage: doctrine` without Doctrine now throws a clear `LogicException`.
- Anulación fingerprints now use `IDEmisorFacturaAnulada` / `NumSerieFacturaAnulada` / `FechaExpedicionFacturaAnulada`, as required by the AEAT hash specification (verified with the official test vector).
- Hash amounts are normalized as strings (insignificant zeros only) instead of through `float`, so large amounts such as `999999999999.99` are no longer altered.
- `Desglose` emits one `DetalleDesglose` per `TaxBreakdown` entry instead of a single line with the first rate and the invoice totals.
- QR URLs use `ValidarQRNoVerifactu` in `no_verifactu` mode (`QrUrlBuilder` takes the configured mode; `buildUrl()` accepts an optional per-call `$mode`).

### Changed

- Documented that `XadesBillingRecordSigner` produces plain enveloped XML-DSig, not full XAdES, and that `hash_chain.table_prefix` and `aeat_spec_version` are reserved options with no effect.

## [1.0.12] - 2026-10-13

### Changed

- Development: `composer.json` pins `config.platform.php` to 8.2.0 so the committed lock stays installable on the minimum PHP; CI overrides the platform per matrix cell.

### Dependencies

- `doctrine/orm` 3.7.4 → 3.7.5 (development lock; #12).

## [1.0.11] - 2026-10-09

### Dependencies

- Dev tooling (Dependabot + lockfile refresh): `igor-php/igor-php` `^0.10.0`, `doctrine/orm` 3.7.3, `phpstan/phpstan` 2.3.1, `phpstan/phpstan-phpunit` 2.1.1, `phpstan/phpstan-symfony` 2.1.0, `nowo-tech/phpstan-frankenphp` 1.2.3, `rector/rector` 2.7.0. The bundle lockfile stays resolved for the PHP 8.2 floor (Symfony 7.4).
- Demo (`demo/symfony8`): Symfony 8.1.8, `doctrine/orm` 3.7.4, `doctrine/dbal` 4.5.0, `twig/twig` 3.30.0; regenerated `config/reference.php`.

## [1.0.10] - 2026-09-27

### Added

- **REQ-CS-008:** `igor-php/igor-php` (require-dev only), root `igor.json`, Composer/`Makefile` `igor` target, and `release-check` wiring for FrankenPHP worker-state audit.

### Changed

- **Worker safety (Igor):** justified `// @igor-ignore` annotations and/or `ResetInterface` / request-scoped fixes so `make igor` passes on package `src/`.

[1.0.10]: https://github.com/nowo-tech/VerifactuBundle/releases/tag/v1.0.10

## [1.0.9] - 2026-09-25

### Fixed

- **`hash_chain.repository`** now also aliases `HashChainRepositoryInterface` (previously only `nowo_verifactu.hash_chain_repository`, so `BillingRecordProcessor` kept using the in-memory storage).
- **FrankenPHP worker mode (no kernel reset between requests):** see `docs/FRANKENPHP-WORKER-AUDIT.md`.
  - `InMemoryHashChainRepository` is request-scoped: new `clear()` method, tagged `kernel.reset`, and called at the start of every main request by the new `EventSubscriber\WorkerStateResetSubscriber` (priority 4096). Workers no longer build per-worker hash chains. When `kernel.debug` is false it logs a warning on every store (the chain is not persistent).
  - `DoctrineHashChainRepository` reads the last state fresh from the database (`Query::HINT_REFRESH`), detaches the entity after each read/write, deletes with a DQL `DELETE`, and resets a closed EntityManager when a flush/query fails before rethrowing.
  - `XsdValidator` restores the previous `libxml_use_internal_errors()` value in `finally`.

[1.0.9]: https://github.com/nowo-tech/VerifactuBundle/releases/tag/v1.0.9

## [1.0.8] - 2026-09-03

### Fixed

- **FrankenPHP / PHPStan:** replace mutable static `$translations` with immutable class constant in unit helper.
- **CI:** drop obsolete PHP 8.1 from the test matrix (composer requires PHP ≥8.2).
- **Demo:** install `pdo_mysql` in FrankenPHP image (demo was HTTP 500: could not find driver).

### Changed

- **Deps (dev):** refresh Composer lockfiles (`xmlseclibs` and demo lock alignment).

[1.0.8]: https://github.com/nowo-tech/VerifactuBundle/releases/tag/v1.0.8

## [1.0.7] - 2026-08-24

### Changed

- Raise minimum PHP to **8.2** and sync README badge (REQ-SF-001).
- **Demos:** MySQL env policy in FrankenPHP stack (REQ-DEMO-011).
- **Makefile:** detect Docker Compose V2 (REQ-MAKE-010).
- **QA:** add `phpstan-frankenphp` extension (REQ-CS-005).
- **README:** FrankenPHP-friendly worker-mode banner (REQ-DOCS-017).
- **Docs:** PHP-FIG PSR evaluation (REQ-CS-007).
- **Style:** PHP CS Fixer alignment.

### Notes

- **No API or configuration changes** for integrators unless noted above.

[1.0.7]: https://github.com/nowo-tech/VerifactuBundle/releases/tag/v1.0.7

## [1.0.6] - 2026-08-19

### Security

- **CI:** run `composer audit --locked` after dependency install (REQ-SEC / P3).

### Fixed

- **CI:** skip `GetFiltersAndFunctionsToAsTwigAttributeRector` in `rector.php` (conflicts with project Rector config).

## [1.0.5] - 2026-08-18

### Changed

- **Demos:** pin `nowo-tech/hot-reload-bundle` to `^1.4` with FrankenPHP Mercure/`hot_reload` (`dev`/`test` only).

[1.0.5]: https://github.com/nowo-tech/VerifactuBundle/releases/tag/v1.0.5

## [1.0.4] - 2026-08-04

### Fixed

- XAdES signing: pass the `DOMDocument` to `XMLSecurityDSig::addReference()` (library PHPDoc / runtime expect the document, which signs `documentElement`).
- PHPStan: drop baseline file; keep `ignoreErrors: []` in `phpstan.neon.dist`.

### Changed

- Demo `.env.example` tweak.

[1.0.4]: https://github.com/nowo-tech/VerifactuBundle/releases/tag/v1.0.4

## [1.0.3] - 2026-08-03

### Changed

- CI: `actions/stale` v11 (Dependabot).
- Rector: skip `ParamAndEnvAttributeRector` so `#[Autowire('%param%')]` stays compatible with Symfony `^6.0`.
- PHP-CS-Fixer: exclude auto-generated `config/reference.php` dumps (Symfony regenerates without `declare(strict_types=1)`).
- Refresh Symfony `reference.php` fixtures (demo + test kernels) and demo `composer.lock`.
- Dev lock refresh on the reference matrix (`composer-sync`).

### Upgrade

```bash
composer require nowo-tech/verifactu-bundle:^1.0.3
```

No public API or config schema changes.

## [1.0.2] - 2026-07-31

### Fixed

- **REQ-GIT-001:** stripped Cursor co-author trailer from `v1.0.0` history (CI `git-hygiene`).
- CI matrix install uses `composer update --with-all-dependencies` so PHP 8.1 / Symfony 8 jobs are not blocked by lock pins (`endroid/qr-code`, `doctrine/doctrine-bundle`).
- `require-dev`: `doctrine/doctrine-bundle` `^2.18 || ^3.2`; `symfony/var-exporter` `^6.4 || ^7.0 || ^8.0` (PHP 8.1 compatible).

### Upgrade

```bash
composer require nowo-tech/verifactu-bundle:^1.0.2
```

No public API changes.

## [1.0.1] - 2026-07-31

### Added

- **REQ-GIT-001:** `.githooks/commit-msg`, `make setup-hooks` / `check-no-cursor-coauthor` / `check-open-prs`, CI `git-hygiene` job, and [`docs/GITHUB_CI.md`](GITHUB_CI.md).
- **REQ-TEST-003 / REQ-TEST-006:** PHP line coverage gate at **100%** (`.scripts/coverage-check-100.php`); CI and `make release-check` enforce it.
- **REQ-RUNTIME-001:** Documented AEAT SOAP timeout hierarchy (operation &lt; PHP &lt; Caddy write); demo Caddy/PHP ini aligned (30s / 45s / 60s).
- Root [`CODE_OF_CONDUCT.md`](../CODE_OF_CONDUCT.md); README badges (stars, coverage 100%).
- Extra unit/integration tests (Doctrine hash-chain fixtures, SOAP transport, processor, sandbox command) for the coverage floor.

### Fixed

- Leftover **SEPA** naming in `.github/FUNDING.yml`, `.github/SECURITY.md`, and Copilot instructions (now Veri*Factu / AEAT).
- Soft `-include` for optional monorepo `update-deps` helpers so standalone GitHub checkouts do not break Make (REQ-MAKE-009).
- Demo Symfony 8: `doctrine/doctrine-bundle` for Symfony 8 (`^2.18 || ^3.2`); image installs `pdo_sqlite` + `gd`; adds `symfony/property-info` / `symfony/validator`; Doctrine ORM config without removed `enable_lazy_ghost_objects`; PHPUnit `KERNEL_CLASS` + test env; default `FRANKENPHP_MODE=classic` for reliable demo/dev; demo `release-check` = `update-bundle` + tests + HTTP verify.

### Changed

- Demo Symfony 8: FrankenPHP production Caddyfile uses worker mode with explicit timeouts; `release-check` on demos runs `update-bundle` + tests.
- Maintainer docs (CONFIGURATION, DEMO-FRANKENPHP, SECURITY SEC-002/004, RELEASE, SPEC-DRIVEN-DEVELOPMENT) aligned with the above.

### Upgrade

```bash
composer require nowo-tech/verifactu-bundle:^1.0.1
```

No public API or config schema breaks. If you host under FrankenPHP and raise `aeat.timeout`, raise PHP `max_execution_time` and Caddy write timeouts in the same step — see [UPGRADING.md](UPGRADING.md).

## [1.0.0] - 2026-07-18

### Added

- **Initial stable release** of `nowo-tech/verifactu-bundle` for Spanish Veri*Factu compliance (RD 1007/2023).
- **Hash chain (SHA-256)**: AEAT-compliant fingerprint calculation and encadenamiento via `HashChainGenerator` and `HashChainRepositoryInterface` (in-memory and Doctrine backends).
- **Billing record XML**: Registro de facturación generation (Alta / Anulación) with official AEAT XSD schemas shipped in the bundle.
- **QR codes**: ISO/IEC 18004 QR with AEAT verification URL and mandatory legend; Twig helpers `verifactu_qr_data_uri`, `verifactu_qr_url`, `verifactu_legend`.
- **Business rules validation**: NIF/CIF/NIE, dates, amounts, invoice types (`AeatBusinessRulesValidator`, `SpanishTaxIdValidator`).
- **Symfony events**: Before/after billing record generation and AEAT submission hooks.
- **Console commands**: `nowo:verifactu:validate-record`, `nowo:verifactu:verify-hash`, `nowo:verifactu:submit-sandbox`.
- **AEAT SOAP client**: mTLS submission to sandbox/production with client certificate (`SoapAeatSubmissionClient`).
- **XAdES signing**: No-Veri*Factu modalidad with PKCS#12/PEM certificates (`XadesBillingRecordSigner`).
- **Nowo integration**: `InvoiceDraft` + `InvoiceToBillingRecordMapper` for ERP adoption.
- **Modes**: Configuration-driven Veri*Factu and No-Veri*Factu behaviour (`nowo_verifactu.mode`).
- **i18n**: Validation messages in 7 locales (`en`, `es`, `it`, `fr`, `pt`, `de`, `nl`) under domain `NowoVerifactuBundle`.
- **Demo**: Symfony 8 + FrankenPHP demo under `demo/symfony8`.
- **Tooling**: PHPUnit (~98% coverage), PHPStan, PHP-CS-Fixer, Rector, GitHub Actions CI, and `make release-check`.

### Documentation

- README, INSTALLATION, CONFIGURATION, USAGE, SANDBOX, INTEGRATION-NOWO, SECURITY, CONTRIBUTING, SPEC-DRIVEN-DEVELOPMENT, SPEC-KIT, RELEASE, UPGRADING.

For first-time install steps, see [UPGRADING.md](UPGRADING.md) and [INSTALLATION.md](INSTALLATION.md).

[Unreleased]: https://github.com/nowo-tech/VerifactuBundle/compare/v1.3.0...HEAD
[1.3.0]: https://github.com/nowo-tech/VerifactuBundle/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/nowo-tech/VerifactuBundle/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/nowo-tech/VerifactuBundle/compare/v1.0.12...v1.1.0
[1.0.12]: https://github.com/nowo-tech/VerifactuBundle/compare/v1.0.11...v1.0.12
[1.0.11]: https://github.com/nowo-tech/VerifactuBundle/compare/v1.0.10...v1.0.11
[1.0.3]: https://github.com/nowo-tech/VerifactuBundle/compare/v1.0.2...v1.0.3
[1.0.2]: https://github.com/nowo-tech/VerifactuBundle/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/nowo-tech/VerifactuBundle/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/nowo-tech/VerifactuBundle/releases/tag/v1.0.0
