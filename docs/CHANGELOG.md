# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/nowo-tech/VerifactuBundle/compare/v1.0.5...HEAD
[1.0.3]: https://github.com/nowo-tech/VerifactuBundle/compare/v1.0.2...v1.0.3
[1.0.2]: https://github.com/nowo-tech/VerifactuBundle/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/nowo-tech/VerifactuBundle/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/nowo-tech/VerifactuBundle/releases/tag/v1.0.0
