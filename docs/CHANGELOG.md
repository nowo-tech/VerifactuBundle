# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
